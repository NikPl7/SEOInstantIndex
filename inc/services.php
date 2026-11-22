<?php
defined( 'ABSPATH' ) || exit;

function hcin_valid_urls( array $lines ): array {
    $host = wp_parse_url( home_url(), PHP_URL_HOST );
    $urls = [];
    foreach ( $lines as $line ) {
        $url = trim( $line );
        $parts = wp_parse_url( $url );
        if ( ! $parts || ! preg_match( '//u', $url ) || preg_match( '/[\x00-\x20\x7f]/', $url ) || strpos( $url, '\\' ) !== false
            || ! in_array( strtolower( $parts['scheme'] ?? '' ), [ 'https', 'http' ], true )
            || strcasecmp( $parts['host'] ?? '', (string) $host ) !== 0
            || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
            continue;
        }
        $urls[] = $url;
    }
    return array_values( array_unique( $urls ) );
}

function hcin_key_url( array $settings ): string {
    return $settings['indexnow_key_url'] ?: home_url( '/' . $settings['indexnow_key'] . '.txt' );
}

function hcin_connected( array $settings ): array {
    return [
        'indexnow' => $settings['indexnow_enabled'] && $settings['indexnow_key'] !== '',
        'google'   => $settings['google_enabled'] && $settings['google_sa_json'] !== '' && function_exists( 'openssl_sign' ),
    ];
}

function hcin_check_key( string $url, string $key ): bool {
    if ( ! hcin_valid_urls( [ $url ] ) || ! $key ) {
        return false;
    }
    $response = wp_safe_remote_get( $url, [ 'timeout' => 5, 'redirection' => 0, 'limit_response_size' => 1024 ] );
    return ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) === 200
        && trim( wp_remote_retrieve_body( $response ) ) === $key;
}

function hcin_generate_key(): array {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $key = bin2hex( random_bytes( 16 ) );
    $file = get_home_path() . $key . '.txt';
    $url = home_url( '/' . $key . '.txt' );

    if ( @file_put_contents( $file, $key ) !== strlen( $key ) ) {
        return [ false, 'Не удалось создать файл. Укажите ключ и адрес файла вручную.' ];
    }
    if ( ! hcin_check_key( $url, $key ) ) {
        wp_delete_file( $file );
        return [ false, 'Сайт не смог открыть новый файл ключа. Проверьте доступ к корню сайта или добавьте файл вручную.' ];
    }

    $settings = hcin_settings();
    $settings['indexnow_key'] = $key;
    $settings['indexnow_key_url'] = $url;
    if ( ! update_option( HCIN_OPT, $settings ) ) {
        wp_delete_file( $file );
        return [ false, 'Не удалось сохранить новый ключ.' ];
    }
    return [ true, 'Новый ключ сохранён. Файл доступен по указанному адресу.' ];
}

function hcin_http_result( $response, array $accepted ): array {
    $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
    $ok = in_array( $code, $accepted, true );
    $retry_after = $code === 429 || $code === 503 ? wp_remote_retrieve_header( $response, 'retry-after' ) : '';
    $retry_after = is_string( $retry_after ) ? trim( $retry_after ) : '';
    return [
        'ok' => $ok,
        'http_code' => $code,
        'retry' => ! $ok && ( $code === 0 || $code === 408 || $code === 429 || $code >= 500 ),
        'retry_after' => preg_match( '/^\d+$/D', $retry_after ) ? (int) $retry_after : max( 0, (int) strtotime( $retry_after ) - time() ),
        'error' => $ok ? '' : ( $code ? '' : 'Сервис не ответил.' ),
    ];
}

function hcin_send_indexnow( array $urls, array $settings ): array {
    return hcin_http_result( wp_remote_post( 'https://api.indexnow.org/indexnow', [
        'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
        'body'    => wp_json_encode( [
            'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
            'key'         => $settings['indexnow_key'],
            'keyLocation' => hcin_key_url( $settings ),
            'urlList'     => $urls,
        ] ),
        'timeout' => 5,
        'redirection' => 0,
    ] ), [ 200, 202 ] );
}

function hcin_service_account( string $json ): array {
    $account = json_decode( $json, true );
    if ( is_array( $account ) && is_string( $account['client_email'] ?? null )
        && filter_var( $account['client_email'], FILTER_VALIDATE_EMAIL )
        && is_string( $account['private_key'] ?? null ) && $account['private_key'] !== '' ) {
        return $account;
    }
    return [];
}

function hcin_b64u( string $data ): string {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

function hcin_google_token_key( string $json ): string {
    return 'hcin_google_' . hash( 'sha256', $json );
}

function hcin_google_token( string $json ): array {
    $cache_key = hcin_google_token_key( $json );
    $cached = get_transient( $cache_key );
    if ( is_string( $cached ) && $cached !== '' ) {
        return [ 'token' => $cached ];
    }

    $account = hcin_service_account( $json );
    $key = $account && function_exists( 'openssl_pkey_get_private' ) ? openssl_pkey_get_private( $account['private_key'] ) : false;
    $now = time();
    $input = hcin_b64u( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) ) . '.' . hcin_b64u( wp_json_encode( [
        'iss'   => $account['client_email'] ?? '',
        'scope' => 'https://www.googleapis.com/auth/indexing',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + HOUR_IN_SECONDS,
    ] ) );
    if ( ! $key || ! openssl_sign( $input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
        return [ 'ok' => false, 'http_code' => 0, 'retry' => false, 'error' => 'Проверьте ключ сервисного аккаунта.' ];
    }

    $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
        'body' => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $input . '.' . hcin_b64u( $signature ),
        ],
        'timeout' => 5,
        'redirection' => 0,
    ] );
    $result = hcin_http_result( $response, [ 200 ] );
    $data = $result['ok'] ? json_decode( wp_remote_retrieve_body( $response ), true ) : [];
    if ( empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
        $result['ok'] = false;
        $result['error'] = 'Google не выдал токен доступа.';
        return $result;
    }
    $ttl = max( 0, min( 55 * MINUTE_IN_SECONDS, (int) ( $data['expires_in'] ?? HOUR_IN_SECONDS ) - 60 ) );
    if ( $ttl ) {
        set_transient( $cache_key, $data['access_token'], $ttl );
    }
    return [ 'token' => $data['access_token'] ];
}

function hcin_send_google( string $url, array $settings ): array {
    $auth = hcin_google_token( $settings['google_sa_json'] );
    if ( ! isset( $auth['token'] ) ) {
        return $auth;
    }
    $result = hcin_http_result( wp_remote_post( 'https://indexing.googleapis.com/v3/urlNotifications:publish', [
        'headers' => [ 'Authorization' => 'Bearer ' . $auth['token'], 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [ 'url' => $url, 'type' => 'URL_UPDATED' ] ),
        'timeout' => 5,
        'redirection' => 0,
    ] ), [ 200 ] );
    if ( $result['http_code'] === 401 ) {
        delete_transient( hcin_google_token_key( $settings['google_sa_json'] ) );
        $result['retry'] = true;
    }
    return $result;
}
