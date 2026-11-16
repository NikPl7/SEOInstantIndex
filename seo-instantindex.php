<?php
/**
 * Plugin Name: SEO InstantIndex
 * Description: Плагин impik.dev для мгновенной отправки URL в IndexNow (Bing, Yandex, Seznam, Naver) и Google Indexing API при публикации и обновлении контента. Инструменты → SEO InstantIndex.
 * Version: 1.2.1
 * Author: impik.dev
 * Author URI: https://impik.dev
 */

defined( 'ABSPATH' ) || exit;

define( 'HCIN_OPT',     'hopcott_indexnow_settings' );
define( 'HCIN_LOG_OPT', 'hopcott_indexnow_log' );
define( 'HCIN_LOG_MAX', 300 );

// ─────────────────────────────────────────────────────────────────────────────
// НАСТРОЙКИ
// ─────────────────────────────────────────────────────────────────────────────

function hcin_settings(): array {
    $saved = get_option( HCIN_OPT, [] );
    // дефолты без жёстко зашитого ключа — ключ создаётся через UI
    $defaults = [
        'indexnow_enabled' => true,
        'indexnow_key'     => '',
        'indexnow_key_url' => '',
        'google_enabled'   => true,
        'google_sa_json'   => '',
        'post_types'       => [ 'post', 'page' ],
    ];
    return array_merge( $defaults, $saved );
}

// ─────────────────────────────────────────────────────────────────────────────
// АВТО-ОТПРАВКА
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'transition_post_status', 'hcin_on_status_transition', 10, 3 );
function hcin_on_status_transition( string $new_status, string $old_status, WP_Post $post ): void {
    if ( $new_status !== 'publish' ) return;
    $s = hcin_settings();
    if ( ! in_array( $post->post_type, $s['post_types'], true ) ) return;
    if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) return;
    $url = get_permalink( $post->ID );
    if ( $url ) hcin_dispatch( [ $url ], 'auto' );
}

// ─────────────────────────────────────────────────────────────────────────────
// ДИСПЕТЧЕР
// ─────────────────────────────────────────────────────────────────────────────

function hcin_dispatch( array $urls, string $source = 'manual' ): array {
    $urls = array_values( array_unique( array_filter( array_map( 'trim', $urls ) ) ) );
    if ( empty( $urls ) ) return [ 'error' => 'Нет URL' ];

    $s       = hcin_settings();
    $results = [];

    if ( ! empty( $s['indexnow_enabled'] ) && ! empty( $s['indexnow_key'] ) ) {
        $results['indexnow'] = hcin_send_indexnow( $urls, $s );
    }
    if ( ! empty( $s['google_enabled'] ) && ! empty( $s['google_sa_json'] ) ) {
        $results['google'] = hcin_send_google( $urls, $s );
    }

    $ok = ! empty( array_filter( array_column( $results, 'ok' ) ) );
    hcin_log_add( [
        'time'    => current_time( 'mysql' ),
        'source'  => $source,
        'urls'    => $urls,
        'ok'      => $ok,
        'results' => $results,
    ] );
    return $results;
}

// ─────────────────────────────────────────────────────────────────────────────
// IndexNow
// ─────────────────────────────────────────────────────────────────────────────

function hcin_send_indexnow( array $urls, array $s ): array {
    $resp = wp_remote_post( 'https://api.indexnow.org/indexnow', [
        'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
        'body'    => wp_json_encode( [
            'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
            'key'         => $s['indexnow_key'],
            'keyLocation' => $s['indexnow_key_url'],
            'urlList'     => $urls,
        ] ),
        'timeout' => 15,
    ] );
    $code = is_wp_error( $resp ) ? 0 : wp_remote_retrieve_response_code( $resp );
    return [
        'ok'        => in_array( $code, [ 200, 202 ], true ),
        'http_code' => $code,
        'error'     => is_wp_error( $resp ) ? $resp->get_error_message() : '',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Google Indexing API
// ─────────────────────────────────────────────────────────────────────────────

function hcin_b64u( string $data ): string {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

function hcin_google_token( array $sa ): string {
    $cached = get_transient( 'hcin_google_token' );
    if ( $cached ) return $cached;

    $now     = time();
    $header  = hcin_b64u( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
    $payload = hcin_b64u( wp_json_encode( [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/indexing',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ] ) );
    $input = $header . '.' . $payload;
    $key   = openssl_pkey_get_private( $sa['private_key'] );
    if ( ! $key ) return '';
    openssl_sign( $input, $sig, $key, 'SHA256' );
    $jwt = $input . '.' . hcin_b64u( $sig );

    $resp  = wp_remote_post( 'https://oauth2.googleapis.com/token', [
        'body'    => [ 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt ],
        'timeout' => 15,
    ] );
    if ( is_wp_error( $resp ) ) return '';
    $data  = json_decode( wp_remote_retrieve_body( $resp ), true );
    $token = $data['access_token'] ?? '';
    if ( $token ) set_transient( 'hcin_google_token', $token, 55 * MINUTE_IN_SECONDS );
    return $token;
}

function hcin_send_google( array $urls, array $s ): array {
    $sa = json_decode( $s['google_sa_json'], true );
    if ( empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
        return [ 'ok' => false, 'http_code' => 0, 'error' => 'Неверный Service Account JSON' ];
    }
    $token = hcin_google_token( $sa );
    if ( ! $token ) return [ 'ok' => false, 'http_code' => 0, 'error' => 'Не удалось получить токен Google' ];

    $ok_count = 0;
    $errors   = [];
    foreach ( $urls as $url ) {
        $resp = wp_remote_post( 'https://indexing.googleapis.com/v3/urlNotifications:publish', [
            'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'url' => $url, 'type' => 'URL_UPDATED' ] ),
            'timeout' => 15,
        ] );
        $code = is_wp_error( $resp ) ? 0 : wp_remote_retrieve_response_code( $resp );
        if ( in_array( $code, [ 200, 202 ], true ) ) {
            $ok_count++;
        } else {
            $body_err = is_wp_error( $resp ) ? $resp->get_error_message() : wp_remote_retrieve_body( $resp );
            $errors[] = "[$code] " . mb_substr( $body_err, 0, 120 );
        }
        usleep( 100000 );
    }
    return [
        'ok'        => $ok_count === count( $urls ),
        'http_code' => $ok_count > 0 ? 200 : 0,
        'sent'      => $ok_count,
        'total'     => count( $urls ),
        'error'     => implode( '; ', $errors ),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// IndexNow: генерация и управление ключом
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Генерирует новый ключ, записывает файл в корень WordPress,
 * сохраняет ключ и URL в настройки.
 * Возвращает [ 'ok' => bool, 'key' => string, 'file_url' => string, 'error' => string ]
 */
function hcin_generate_key(): array {
    // Генерируем 32-символьный hex ключ
    $key  = bin2hex( random_bytes( 16 ) );
    $file = ABSPATH . $key . '.txt';
    $url  = home_url( '/' . $key . '.txt' );

    // Записываем файл
    $written = file_put_contents( $file, $key );
    if ( $written === false ) {
        return [ 'ok' => false, 'key' => $key, 'file_url' => $url, 'error' => 'Не удалось записать файл ' . $file ];
    }

    // Проверяем HTTP-доступность
    $check = wp_remote_get( $url, [ 'timeout' => 10, 'sslverify' => false ] );
    $code  = is_wp_error( $check ) ? 0 : wp_remote_retrieve_response_code( $check );
    $body  = is_wp_error( $check ) ? '' : trim( wp_remote_retrieve_body( $check ) );

    if ( $code !== 200 || $body !== $key ) {
        return [
            'ok'       => false,
            'key'      => $key,
            'file_url' => $url,
            'error'    => "Файл создан, но не доступен по URL (HTTP $code). Проверьте настройки сервера.",
        ];
    }

    // Удаляем старый файл ключа если был
    $s = hcin_settings();
    if ( ! empty( $s['indexnow_key'] ) && $s['indexnow_key'] !== $key ) {
        $old_file = ABSPATH . $s['indexnow_key'] . '.txt';
        if ( file_exists( $old_file ) ) @unlink( $old_file );
    }

    // Сохраняем в настройки
    $s['indexnow_key']     = $key;
    $s['indexnow_key_url'] = $url;
    update_option( HCIN_OPT, $s );

    return [ 'ok' => true, 'key' => $key, 'file_url' => $url, 'error' => '' ];
}

/** Проверяет, доступен ли текущий файл ключа по HTTP */
function hcin_check_key_file(): array {
    $s = hcin_settings();
    if ( empty( $s['indexnow_key'] ) ) return [ 'status' => 'missing', 'msg' => 'Ключ не настроен' ];

    $check = wp_remote_get( $s['indexnow_key_url'], [ 'timeout' => 10, 'sslverify' => false ] );
    $code  = is_wp_error( $check ) ? 0 : wp_remote_retrieve_response_code( $check );
    $body  = is_wp_error( $check ) ? '' : trim( wp_remote_retrieve_body( $check ) );

    if ( $code === 200 && $body === $s['indexnow_key'] ) {
        return [ 'status' => 'ok', 'msg' => "✅ Файл доступен (HTTP 200), ключ совпадает" ];
    }
    return [ 'status' => 'error', 'msg' => "❌ HTTP $code — файл недоступен или содержимое не совпадает" ];
}

// ─────────────────────────────────────────────────────────────────────────────
// ЛОГ
// ─────────────────────────────────────────────────────────────────────────────

function hcin_log_add( array $entry ): void {
    $log = get_option( HCIN_LOG_OPT, [] );
    array_unshift( $log, $entry );
    if ( count( $log ) > HCIN_LOG_MAX ) $log = array_slice( $log, 0, HCIN_LOG_MAX );
    update_option( HCIN_LOG_OPT, $log, false );
}

// ─────────────────────────────────────────────────────────────────────────────
// МЕНЮ
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_management_page( 'SEO InstantIndex', 'SEO InstantIndex', 'manage_options', 'hopcott-indexnow', 'hcin_admin_page' );
} );

// ─────────────────────────────────────────────────────────────────────────────
// ОБРАБОТКА ФОРМ
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_init', function () {
    if ( ! isset( $_POST['hcin_action'] ) || ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hcin_nonce' ) ) return;

    $action  = sanitize_text_field( wp_unslash( $_POST['hcin_action'] ) );
    $cur_tab = sanitize_key( wp_unslash( $_POST['current_tab'] ?? 'settings' ) );
    if ( ! in_array( $cur_tab, [ 'settings', 'submit', 'log' ], true ) ) $cur_tab = 'settings';

    if ( $action === 'generate_key' ) {
        $result = hcin_generate_key();
        set_transient( 'hcin_flash', [
            'type' => $result['ok'] ? 'success' : 'error',
            'msg'  => $result['ok']
                ? "✅ Ключ создан: {$result['key']} | Файл: {$result['file_url']}"
                : "❌ {$result['error']}",
        ], 30 );
        $cur_tab = 'settings';
    }

    if ( $action === 'check_key' ) {
        $r = hcin_check_key_file();
        set_transient( 'hcin_flash', [ 'type' => $r['status'] === 'ok' ? 'success' : 'error', 'msg' => $r['msg'] ], 30 );
        $cur_tab = 'settings';
    }

    if ( $action === 'save_settings' ) {
        $all_cpt = array_keys( hcin_all_post_types() );
        $chosen  = array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ?? [] ) ), $all_cpt );
        $s       = hcin_settings();
        // Service Account JSON: пустое поле = оставить сохранённый; JSON не выводится обратно в форму.
        $sa_new  = trim( (string) wp_unslash( $_POST['google_sa_json'] ?? '' ) );
        $sa_save = $s['google_sa_json'];
        $sa_err  = '';
        if ( ! empty( $_POST['google_sa_remove'] ) ) {
            $sa_save = '';
        } elseif ( $sa_new !== '' ) {
            $sa_chk = json_decode( $sa_new, true );
            if ( is_array( $sa_chk ) && ! empty( $sa_chk['client_email'] ) && ! empty( $sa_chk['private_key'] ) ) {
                $sa_save = $sa_new;
            } else {
                $sa_err = 'Неверный Service Account JSON — прежнее значение сохранено.';
            }
        }
        update_option( HCIN_OPT, array_merge( $s, [
            'indexnow_enabled' => ! empty( $_POST['indexnow_enabled'] ),
            'indexnow_key'     => isset( $_POST['indexnow_key'] ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', wp_unslash( $_POST['indexnow_key'] ) ) : $s['indexnow_key'],
            'indexnow_key_url' => isset( $_POST['indexnow_key_url'] ) ? esc_url_raw( wp_unslash( $_POST['indexnow_key_url'] ) ) : $s['indexnow_key_url'],
            'google_enabled'   => ! empty( $_POST['google_enabled'] ),
            'google_sa_json'   => $sa_save,
            'post_types'       => array_values( $chosen ),
        ] ) );
        delete_transient( 'hcin_google_token' );
        set_transient( 'hcin_flash', $sa_err
            ? [ 'type' => 'error', 'msg' => $sa_err ]
            : [ 'type' => 'success', 'msg' => 'Настройки сохранены.' ], 30 );
        $cur_tab = 'settings';
    }

    if ( $action === 'submit_custom' ) {
        $raw  = sanitize_textarea_field( wp_unslash( $_POST['hcin_urls'] ?? '' ) );
        $urls = array_filter( array_map( 'esc_url_raw', array_map( 'trim', explode( "\n", $raw ) ) ) );
        if ( $urls ) {
            $r = hcin_dispatch( array_values( $urls ), 'manual' );
            set_transient( 'hcin_flash', [ 'type' => 'success', 'msg' => hcin_result_text( $r ) ], 30 );
        }
        $cur_tab = 'submit';
    }

    if ( $action === 'submit_all' ) {
        $s    = hcin_settings();
        $ids  = get_posts( [ 'post_type' => $s['post_types'], 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ] );
        $all  = array_filter( array_map( 'get_permalink', $ids ) );
        $chunks = array_chunk( array_values( $all ), 100 );
        $ok = $fail = 0;
        foreach ( $chunks as $chunk ) {
            $r = hcin_dispatch( $chunk, 'bulk' );
            ! empty( array_filter( array_column( $r, 'ok' ) ) ) ? $ok++ : $fail++;
            usleep( 300000 );
        }
        set_transient( 'hcin_flash', [
            'type' => $fail ? 'warning' : 'success',
            'msg'  => sprintf( 'Bulk: %d URL, %d запросов ✅, %d ❌', count( $all ), $ok, $fail ),
        ], 30 );
        $cur_tab = 'submit';
    }

    if ( $action === 'clear_log' ) {
        delete_option( HCIN_LOG_OPT );
        set_transient( 'hcin_flash', [ 'type' => 'success', 'msg' => 'Лог очищен.' ], 30 );
        $cur_tab = 'log';
    }

    wp_safe_redirect( admin_url( 'tools.php?page=hopcott-indexnow&tab=' . $cur_tab ) );
    exit;
} );

function hcin_result_text( array $r ): string {
    $parts = [];
    if ( isset( $r['indexnow'] ) ) {
        $i = $r['indexnow'];
        $parts[] = 'IndexNow: ' . ( $i['ok'] ? "✅ HTTP {$i['http_code']}" : "❌ HTTP {$i['http_code']} {$i['error']}" );
    }
    if ( isset( $r['google'] ) ) {
        $g = $r['google'];
        $parts[] = 'Google: ' . ( $g['ok'] ? "✅ {$g['sent']}/{$g['total']}" : "❌ {$g['error']}" );
    }
    return implode( ' | ', $parts ) ?: 'Нет активных API';
}

function hcin_all_post_types(): array {
    $types  = get_post_types( [ 'public' => true ], 'objects' );
    $result = [];
    foreach ( $types as $slug => $obj ) {
        $result[ $slug ] = $obj->labels->singular_name ?? $slug;
    }
    return $result;
}

// ─────────────────────────────────────────────────────────────────────────────
// СТРАНИЦА АДМИНКИ
// ─────────────────────────────────────────────────────────────────────────────

function hcin_admin_page(): void {
    $s     = hcin_settings();
    $tab   = sanitize_key( wp_unslash( $_GET['tab'] ?? 'settings' ) );
    $flash = get_transient( 'hcin_flash' );
    if ( $flash ) delete_transient( 'hcin_flash' );
    $log   = get_option( HCIN_LOG_OPT, [] );
    $tabs  = [ 'settings' => 'Настройки', 'submit' => 'Отправка', 'log' => 'Лог (' . count( $log ) . ')' ];

    // определяем статус ключа для бейджа
    $key_ok = false;
    if ( ! empty( $s['indexnow_key'] ) ) {
        $key_file = ABSPATH . $s['indexnow_key'] . '.txt';
        $key_ok   = file_exists( $key_file ) && trim( file_get_contents( $key_file ) ) === $s['indexnow_key'];
    }
    ?>
    <div class="wrap">
        <h1>SEO InstantIndex <span style="font-size:14px;font-weight:400;color:#666">by impik.dev · v1.2.1</span></h1>

        <nav class="nav-tab-wrapper" style="margin-bottom:20px">
            <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?php echo esc_url( admin_url( 'tools.php?page=hopcott-indexnow&tab=' . $key ) ); ?>"
                   class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ( $flash ) : ?>
            <div class="notice notice-<?php echo esc_attr( $flash['type'] ); ?> is-dismissible"><p><?php echo esc_html( $flash['msg'] ); ?></p></div>
        <?php endif; ?>

        <?php if ( $tab === 'settings' ) : ?>

            <!-- ── IndexNow ───────────────────────────────────────────────── -->
            <div style="background:#fff;border:1px solid #ddd;padding:24px;border-radius:4px;margin-bottom:20px;max-width:860px">
                <h2 style="margin-top:0">🔵 IndexNow <span style="font-size:13px;font-weight:400;color:#666">(Bing, Yandex, Seznam, Naver)</span></h2>

                <form method="post" style="margin-bottom:20px">
                    <?php wp_nonce_field( 'hcin_nonce' ); ?>
                    <input type="hidden" name="hcin_action" value="save_settings">
                    <input type="hidden" name="current_tab" value="settings">
                    <!-- передаём остальные поля скрыто чтобы не сбросить -->
                    <?php foreach ( $s['post_types'] as $pt ) : ?>
                        <input type="hidden" name="post_types[]" value="<?php echo esc_attr( $pt ); ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="indexnow_key" value="<?php echo esc_attr( $s['indexnow_key'] ); ?>">
                    <input type="hidden" name="indexnow_key_url" value="<?php echo esc_attr( $s['indexnow_key_url'] ); ?>">
                    <?php if ( $s['google_enabled'] ) : ?><input type="hidden" name="google_enabled" value="1"><?php endif; ?>
                    <label><input type="checkbox" name="indexnow_enabled" value="1" <?php checked( $s['indexnow_enabled'] ); ?>>
                        <strong>Включить IndexNow</strong></label>
                    <button type="submit" class="button" style="margin-left:12px">Сохранить</button>
                </form>

                <!-- Ключ -->
                <table class="form-table" style="margin:0">
                    <tr>
                        <th style="width:160px;padding:8px 10px">API ключ</th>
                        <td style="padding:8px 10px">
                            <?php if ( $s['indexnow_key'] ) : ?>
                                <code style="font-size:13px;background:#f0f0f0;padding:4px 8px;border-radius:3px"><?php echo esc_html( $s['indexnow_key'] ); ?></code>
                                &nbsp;
                                <?php echo $key_ok ? '<span style="color:#2e7d32">✅ файл на сервере</span>' : '<span style="color:#c00">⚠️ файл не найден</span>'; ?>
                            <?php else : ?>
                                <span style="color:#c00">⚠️ Ключ не настроен</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:8px 10px">URL файла</th>
                        <td style="padding:8px 10px">
                            <?php if ( $s['indexnow_key_url'] ) : ?>
                                <a href="<?php echo esc_url( $s['indexnow_key_url'] ); ?>" target="_blank"><?php echo esc_html( $s['indexnow_key_url'] ); ?></a>
                            <?php else : ?>
                                <span style="color:#999">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="margin-top:16px;display:flex;gap:12px;align-items:center">
                    <!-- Сгенерировать ключ -->
                    <form method="post">
                        <?php wp_nonce_field( 'hcin_nonce' ); ?>
                        <input type="hidden" name="hcin_action" value="generate_key">
                        <input type="hidden" name="current_tab" value="settings">
                        <button type="submit" class="button button-primary"
                            onclick="return confirm('<?php echo $s['indexnow_key'] ? 'Сгенерировать новый ключ? Старый файл будет удалён.' : 'Создать ключ IndexNow?'; ?>')">
                            <?php echo $s['indexnow_key'] ? '🔄 Обновить ключ' : '✨ Создать ключ автоматически'; ?>
                        </button>
                    </form>

                    <!-- Проверить доступность -->
                    <?php if ( $s['indexnow_key'] ) : ?>
                    <form method="post">
                        <?php wp_nonce_field( 'hcin_nonce' ); ?>
                        <input type="hidden" name="hcin_action" value="check_key">
                        <input type="hidden" name="current_tab" value="settings">
                        <button type="submit" class="button">🔍 Проверить доступность</button>
                    </form>
                    <?php endif; ?>
                </div>
                <p style="color:#666;font-size:12px;margin-top:10px">
                    Нажмите «Создать ключ» — плагин автоматически сгенерирует ключ и создаст файл верификации в корне сайта.
                </p>
            </div>

            <!-- ── Google Indexing API ────────────────────────────────────── -->
            <div style="background:#fff;border:1px solid #ddd;padding:24px;border-radius:4px;margin-bottom:20px;max-width:860px">
                <h2 style="margin-top:0">🔴 Google Indexing API</h2>
                <form method="post">
                    <?php wp_nonce_field( 'hcin_nonce' ); ?>
                    <input type="hidden" name="hcin_action" value="save_settings">
                    <input type="hidden" name="current_tab" value="settings">
                    <input type="hidden" name="indexnow_key" value="<?php echo esc_attr( $s['indexnow_key'] ); ?>">
                    <input type="hidden" name="indexnow_key_url" value="<?php echo esc_attr( $s['indexnow_key_url'] ); ?>">
                    <?php if ( $s['indexnow_enabled'] ) : ?><input type="hidden" name="indexnow_enabled" value="1"><?php endif; ?>
                    <?php foreach ( $s['post_types'] as $pt ) : ?>
                        <input type="hidden" name="post_types[]" value="<?php echo esc_attr( $pt ); ?>">
                    <?php endforeach; ?>

                    <label><input type="checkbox" name="google_enabled" value="1" <?php checked( $s['google_enabled'] ); ?>>
                        <strong>Включить Google Indexing API</strong></label>

                    <p style="color:#666;font-size:13px;margin-top:12px">
                        <strong>Как получить JSON:</strong> Google Cloud Console → IAM → Сервисные аккаунты → создать аккаунт → Ключи → Добавить ключ (JSON).
                        Затем добавьте этот аккаунт как пользователя в <a href="https://search.google.com/search-console" target="_blank">Search Console</a> с правами «Владелец».
                    </p>

                    <?php if ( $s['google_sa_json'] ) :
                        $sa = json_decode( $s['google_sa_json'], true ); ?>
                        <p style="background:#e8f5e9;border:1px solid #a5d6a7;padding:10px 14px;border-radius:4px;font-size:13px">
                            ✅ Service Account подключён: <code><?php echo esc_html( $sa['client_email'] ?? '?' ); ?></code>
                        </p>
                    <?php endif; ?>

                    <textarea name="google_sa_json" rows="6" style="width:100%;font-family:monospace;font-size:12px;margin-top:8px"
                        placeholder='{"type":"service_account","project_id":"...","private_key":"-----BEGIN PRIVATE KEY-----\n...","client_email":"...@....iam.gserviceaccount.com",...}'><?php
                    ?></textarea>
                    <?php if ( $s['google_sa_json'] ) : ?>
                        <p style="color:#666;font-size:12px">Сохранённый ключ не показывается. Оставьте поле пустым, чтобы не менять его, или вставьте новый JSON.</p>
                        <label><input type="checkbox" name="google_sa_remove" value="1"> Удалить сохранённый Service Account JSON</label>
                    <?php endif; ?>
                    <p><button type="submit" class="button button-primary">Сохранить</button></p>
                </form>
            </div>

            <!-- ── Типы контента ──────────────────────────────────────────── -->
            <div style="background:#fff;border:1px solid #ddd;padding:24px;border-radius:4px;max-width:860px">
                <h2 style="margin-top:0">📋 Типы контента для авто-отправки</h2>
                <p style="color:#666;font-size:13px">URL отправляется автоматически при публикации или обновлении записи.</p>
                <form method="post">
                    <?php wp_nonce_field( 'hcin_nonce' ); ?>
                    <input type="hidden" name="hcin_action" value="save_settings">
                    <input type="hidden" name="current_tab" value="settings">
                    <input type="hidden" name="indexnow_key" value="<?php echo esc_attr( $s['indexnow_key'] ); ?>">
                    <input type="hidden" name="indexnow_key_url" value="<?php echo esc_attr( $s['indexnow_key_url'] ); ?>">
                    <?php if ( $s['indexnow_enabled'] ) : ?><input type="hidden" name="indexnow_enabled" value="1"><?php endif; ?>
                    <?php if ( $s['google_enabled'] ) : ?><input type="hidden" name="google_enabled" value="1"><?php endif; ?>

                    <div style="display:flex;flex-wrap:wrap;gap:12px 28px;margin-bottom:16px">
                        <?php foreach ( hcin_all_post_types() as $slug => $label ) : ?>
                            <label>
                                <input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $slug ); ?>"
                                    <?php checked( in_array( $slug, $s['post_types'], true ) ); ?>>
                                <strong><?php echo esc_html( $label ); ?></strong>
                                <span style="color:#999;font-size:12px">(<?php echo esc_html( $slug ); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="button button-primary">Сохранить</button>
                </form>
            </div>

        <?php elseif ( $tab === 'submit' ) : ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
                <div style="background:#fff;border:1px solid #ddd;padding:20px;border-radius:4px">
                    <h2 style="margin-top:0">Ручная отправка</h2>
                    <form method="post">
                        <?php wp_nonce_field( 'hcin_nonce' ); ?>
                        <input type="hidden" name="hcin_action" value="submit_custom">
                        <input type="hidden" name="current_tab" value="submit">
                        <p style="color:#666;font-size:13px;margin-top:0">По одному URL на строку</p>
                        <textarea name="hcin_urls" rows="10" style="width:100%;font-family:monospace;font-size:13px"
                            placeholder="https://example.com/article/&#10;https://example.com/about/"></textarea>
                        <p><button type="submit" class="button button-primary">Отправить</button></p>
                    </form>
                </div>

                <div style="background:#fff;border:1px solid #ddd;padding:20px;border-radius:4px">
                    <h2 style="margin-top:0">Массовая отправка</h2>
                    <p>Отправит все опубликованные URL выбранных типов контента (батчами по 100).</p>
                    <p>
                        <strong>Активные API:</strong>
                        <?php
                        $active = [];
                        if ( $s['indexnow_enabled'] && $s['indexnow_key'] ) $active[] = '✅ IndexNow';
                        if ( $s['google_enabled'] && $s['google_sa_json'] )  $active[] = '✅ Google';
                        echo $active ? implode( ', ', $active ) : '⚠️ Нет активных API — настройте на вкладке «Настройки»';
                        ?>
                    </p>
                    <p><strong>Типы:</strong> <?php echo esc_html( implode( ', ', $s['post_types'] ) ?: 'не выбраны' ); ?></p>
                    <form method="post" onsubmit="return confirm('Отправить все опубликованные URL?')">
                        <?php wp_nonce_field( 'hcin_nonce' ); ?>
                        <input type="hidden" name="hcin_action" value="submit_all">
                        <input type="hidden" name="current_tab" value="submit">
                        <button type="submit" class="button button-primary">Отправить всё</button>
                    </form>
                    <hr style="margin:20px 0">
                    <form method="post">
                        <?php wp_nonce_field( 'hcin_nonce' ); ?>
                        <input type="hidden" name="hcin_action" value="clear_log">
                        <input type="hidden" name="current_tab" value="log">
                        <button type="submit" class="button">🗑 Очистить лог</button>
                    </form>
                </div>
            </div>

        <?php elseif ( $tab === 'log' ) : ?>

            <?php if ( empty( $log ) ) : ?>
                <p>Лог пустой.</p>
            <?php else : ?>
                <table class="widefat striped" style="font-size:12px">
                    <thead>
                        <tr>
                            <th style="width:145px">Время</th>
                            <th style="width:50px">Тип</th>
                            <th style="width:110px">IndexNow</th>
                            <th style="width:110px">Google</th>
                            <th>URL</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $log as $entry ) :
                        $in = $entry['results']['indexnow'] ?? null;
                        $go = $entry['results']['google']   ?? null;
                        ?>
                        <tr>
                            <td><?php echo esc_html( $entry['time'] ); ?></td>
                            <td><span style="background:<?php echo $entry['source'] === 'auto' ? '#e3f2fd' : ( $entry['source'] === 'bulk' ? '#fff3e0' : '#f3e5f5' ); ?>;padding:2px 6px;border-radius:3px;font-size:11px"><?php echo esc_html( $entry['source'] ); ?></span></td>
                            <td><?php
                                if ( $in ) {
                                    echo $in['ok'] ? '<span style="color:#2e7d32">&#10003;&#10003;</span>' : '<span style="color:#c00">&#10007;</span>';
                                    echo ' HTTP ' . (int) $in['http_code'];
                                    if ( $in['error'] ) echo '<br><span style="color:#c00;font-size:11px">' . esc_html( mb_substr( $in['error'], 0, 50 ) ) . '</span>';
                                } else {
                                    echo '<span style="color:#999">-</span>';
                                }
                            ?></td>
                            <td><?php
                                if ( $go ) {
                                    echo $go['ok'] ? '<span style="color:#2e7d32">&#10003;&#10003;</span>' : '<span style="color:#c00">&#10007;</span>';
                                    echo isset( $go['sent'] ) ? ' ' . (int) $go['sent'] . '/' . (int) $go['total'] : ' HTTP ' . (int) $go['http_code'];
                                    if ( $go['error'] ) echo '<br><span style="color:#c00;font-size:11px">' . esc_html( mb_substr( $go['error'], 0, 50 ) ) . '</span>';
                                } else {
                                    echo '<span style="color:#999">-</span>';
                                }
                            ?></td>
                            <td style="word-break:break-all;font-size:11px">
                                <?php
                                $urls = (array) $entry['urls'];
                                if ( count( $urls ) <= 3 ) {
                                    echo esc_html( implode( "\n", $urls ) );
                                } else {
                                    echo esc_html( implode( "\n", array_slice( $urls, 0, 3 ) ) );
                                    echo ' <em style="color:#999">+' . ( count( $urls ) - 3 ) . ' ещё</em>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php endif; ?>
    </div>
    <?php
}
