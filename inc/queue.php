<?php
defined( 'ABSPATH' ) || exit;

const HCIN_WORKER = 'hcin_process_queue';
const HCIN_QUEUE_SCHEMA = '1';

function hcin_queue_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'hcin_queue';
}

function hcin_install_queue(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = hcin_queue_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        source varchar(16) NOT NULL,
        provider varchar(16) NOT NULL,
        payload longtext NOT NULL,
        ready_at bigint(20) unsigned NOT NULL,
        attempts tinyint unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY ready (ready_at,id)
    ) ENGINE=InnoDB $charset;" );
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
        update_option( 'hcin_queue_schema', HCIN_QUEUE_SCHEMA, false );
    }
}

add_filter( 'cron_schedules', static function ( array $schedules ): array {
    $schedules['hcin_minute'] = [ 'interval' => MINUTE_IN_SECONDS, 'display' => 'SEO InstantIndex' ];
    return $schedules;
} );

function hcin_ensure_worker(): void {
    if ( get_option( 'hcin_queue_schema' ) !== HCIN_QUEUE_SCHEMA ) {
        hcin_install_queue();
    }
    if ( get_option( 'hcin_queue_schema' ) === HCIN_QUEUE_SCHEMA && ! wp_next_scheduled( HCIN_WORKER ) ) {
        wp_schedule_event( time(), 'hcin_minute', HCIN_WORKER );
    }
}

add_action( 'init', 'hcin_ensure_worker' );
add_action( HCIN_WORKER, 'hcin_process_queue' );
register_deactivation_hook( dirname( __DIR__ ) . '/seo-instantindex.php', static function (): void {
    wp_clear_scheduled_hook( HCIN_WORKER );
} );

function hcin_queue_job( string $provider, string $source, array $payload ): bool {
    global $wpdb;
    $json = wp_json_encode( $payload );
    return $json !== false && $wpdb->insert( hcin_queue_table(), [
        'source' => $source, 'provider' => $provider, 'payload' => $json, 'ready_at' => time(),
    ], [ '%s', '%s', '%s', '%d' ] ) === 1;
}

function hcin_queue_submissions( array $indexnow, array $google, string $source ): bool {
    $success = true;
    foreach ( array_chunk( $indexnow, 100 ) as $chunk ) {
        $success = hcin_queue_job( 'indexnow', $source, $chunk ) && $success;
    }
    foreach ( array_unique( $google ) as $url ) {
        $success = hcin_queue_job( 'google', $source, [ $url ] ) && $success;
    }
    return $success;
}

function hcin_post_url( WP_Post $post ): string {
    if ( $post->post_status !== 'publish' || $post->post_password !== '' || ! is_post_type_viewable( $post->post_type ) ) {
        return '';
    }
    return hcin_valid_urls( [ (string) get_permalink( $post ) ] )[0] ?? '';
}

add_action( 'wp_after_insert_post', 'hcin_on_save', 10, 2 );
function hcin_on_save( int $post_id, WP_Post $post ): void {
    $settings = hcin_settings();
    $enabled = hcin_connected( $settings );
    $indexnow = $enabled['indexnow'] && in_array( $post->post_type, $settings['post_types'], true );
    $google = $enabled['google'] && in_array( $post->post_type, $settings['google_post_types'], true );
    if ( ( ! $indexnow && ! $google ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }
    $url = hcin_post_url( $post );
    if ( $url && ! hcin_queue_submissions( $indexnow ? [ $url ] : [], $google ? [ $url ] : [], 'auto' ) ) {
        hcin_log_add( 'auto', [ $url ], 'queue', [ 'ok' => false, 'http_code' => 0, 'error' => 'Не удалось записать URL в очередь.' ] );
    }
}

function hcin_bulk_scan( object $job, array $payload, array $settings, array $enabled ): bool {
    global $wpdb;
    $indexnow = $enabled['indexnow'] ? $settings['post_types'] : [];
    $google = $enabled['google'] ? $settings['google_post_types'] : [];
    $types = array_values( array_unique( array_merge( $indexnow, $google ) ) );
    $ids = [];
    if ( $types ) {
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_status = 'publish'
             AND post_type IN ($placeholders) ORDER BY ID LIMIT 100",
            array_merge( [ $payload['after'], $payload['through'] ], $types )
        ) );
        if ( $wpdb->last_error ) {
            return false;
        }
    }

    $urls = $google_urls = [];
    foreach ( $ids as $id ) {
        $post = get_post( $id );
        $url = $post ? hcin_post_url( $post ) : '';
        if ( $url && in_array( $post->post_type, $indexnow, true ) ) {
            $urls[] = $url;
        }
        if ( $url && in_array( $post->post_type, $google, true ) ) {
            $google_urls[] = $url;
        }
    }

    $table = hcin_queue_table();
    if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
        return false;
    }
    $success = hcin_queue_submissions( $urls, $google_urls, 'bulk' );
    if ( count( $ids ) === 100 ) {
        $payload['after'] = (int) end( $ids );
        $success = $success && $wpdb->update( $table, [
            'payload' => wp_json_encode( $payload ), 'ready_at' => time() + 1, 'attempts' => 0,
        ], [ 'id' => $job->id ] ) !== false;
    } else {
        $success = $success && $wpdb->delete( $table, [ 'id' => $job->id ] ) === 1;
    }
    if ( $success && $wpdb->query( 'COMMIT' ) !== false ) {
        return true;
    }
    $wpdb->query( 'ROLLBACK' );
    return false;
}

function hcin_process_queue(): void {
    global $wpdb;
    $table = hcin_queue_table();
    $lock = 'hcin:' . sha1( DB_NAME . '/' . $table );
    if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) !== 1 ) {
        return;
    }
    try {
        $deadline = microtime( true ) + 8;
        $settings = hcin_settings();
        $enabled = hcin_connected( $settings );
        $jobs = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE ready_at <= %d ORDER BY ready_at, id LIMIT 20",
            time()
        ) );
        foreach ( $jobs as $job ) {
            if ( microtime( true ) >= $deadline ) {
                break;
            }
            $payload = json_decode( $job->payload, true );
            $urls = [];
            if ( ! is_array( $payload ) ) {
                $result = [ 'ok' => false, 'http_code' => 0, 'error' => 'Задача повреждена.' ];
            } elseif ( $job->provider === 'scan' ) {
                if ( hcin_bulk_scan( $job, $payload, $settings, $enabled ) ) {
                    continue;
                }
                $result = [ 'ok' => false, 'http_code' => 0, 'retry' => true, 'error' => 'Не удалось подготовить список URL.' ];
            } else {
                $urls = $payload;
                $result = [ 'ok' => false, 'http_code' => 0, 'error' => 'Сервис отключён или не настроен.' ];
                if ( ! empty( $enabled[$job->provider] ) ) {
                    $result = $job->provider === 'indexnow' ? hcin_send_indexnow( $urls, $settings ) : hcin_send_google( $urls[0], $settings );
                }
            }
            if ( ! empty( $result['retry'] ) && (int) $job->attempts < 2 ) {
                $delay = ( (int) $job->attempts + 1 ) * 5 * MINUTE_IN_SECONDS;
                $delay = max( $delay, min( DAY_IN_SECONDS, $result['retry_after'] ?? 0 ) );
                $wpdb->update( $table, [
                    'attempts' => (int) $job->attempts + 1,
                    'ready_at' => time() + $delay,
                ], [ 'id' => $job->id ] );
                continue;
            }
            if ( $wpdb->delete( $table, [ 'id' => $job->id ] ) === 1 ) {
                unset( $result['retry'], $result['retry_after'] );
                hcin_log_add( $job->source, $urls, $job->provider, $result );
            }
        }
    } finally {
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
    }
}

function hcin_log_add( string $source, array $urls, string $provider, array $result ): void {
    $log = (array) get_option( HCIN_LOG_OPT, [] );
    array_unshift( $log, [ 'time' => current_time( 'mysql' ), 'source' => $source, 'urls' => $urls, 'results' => [ $provider => $result ] ] );
    update_option( HCIN_LOG_OPT, array_slice( $log, 0, HCIN_LOG_MAX ), false );
}
