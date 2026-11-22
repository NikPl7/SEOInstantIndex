<?php
/**
 * Plugin Name: SEO InstantIndex
 * Description: Плагин для отправки URL в IndexNow (Bing, Yandex, Seznam, Naver) и Google Indexing API при публикации и обновлении контента. Инструменты → SEO InstantIndex.
 * Version: 1.3.5
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: impik.dev
 * Author URI: https://impik.dev
 * Update URI: https://updates.impik.dev/seo-instantindex/
 */

defined( 'ABSPATH' ) || exit;

define( 'HCIN_VERSION', '1.3.5' );
define( 'HCIN_OPT', 'hopcott_indexnow_settings' );
define( 'HCIN_LOG_OPT', 'hopcott_indexnow_log' );
define( 'HCIN_LOG_MAX', 300 );

require_once __DIR__ . '/inc/vendor/impik-updater/Client.php';
\Impik\SeoInstantIndex\Updater\Client::register( __FILE__, 'seo-instantindex', HCIN_VERSION );

add_filter( 'impik_guard/protected', static function ( $protected, $basename ): bool {
    return $protected || $basename === plugin_basename( __FILE__ );
}, 10, 2 );

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
