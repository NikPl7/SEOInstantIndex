<?php
/**
 * Plugin Name: SEO InstantIndex
 * Description: Отправляет URL в IndexNow и Google Indexing API. Настройки доступны в разделе «Инструменты».
 * Version: 1.3.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: impik.dev
 * Author URI: https://impik.dev
 */

defined( 'ABSPATH' ) || exit;

define( 'HCIN_VERSION', '1.3.0' );
define( 'HCIN_OPT', 'hopcott_indexnow_settings' );
define( 'HCIN_LOG_OPT', 'hopcott_indexnow_log' );
define( 'HCIN_LOG_MAX', 300 );

function hcin_settings(): array {
    return array_merge( [
        'indexnow_enabled' => true,
        'indexnow_key'     => '',
        'indexnow_key_url' => '',
        'google_enabled'   => true,
        'google_sa_json'   => '',
        'post_types'       => [ 'post', 'page' ],
        'google_post_types' => [],
    ], (array) get_option( HCIN_OPT, [] ) );
}

require __DIR__ . '/inc/services.php';
require __DIR__ . '/inc/queue.php';
require __DIR__ . '/inc/admin.php';
