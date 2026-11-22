<?php
// Offline regression checks. WordPress and HTTP are stubbed.
define( 'ABSPATH', '/nonexistent/' );

$hooks = $opts = $transients = [];
function add_action( $name, $callback, ...$args ) { $GLOBALS['hooks'][$name] = $callback; }
function add_filter( ...$args ) {}
function register_deactivation_hook( ...$args ) {}
function get_option( $key, $default = false ) { return $GLOBALS['opts'][$key] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['opts'][$key] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][$key] = $value; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][$key] ); }
function current_user_can( $capability ) { return true; }
function get_current_user_id() { return 1; }
function check_admin_referer( $action ) { return true; }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function wp_parse_url( ...$args ) { return parse_url( ...$args ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function admin_url( $path ) { return home_url( '/wp-admin/' . $path ); }
class Redirected extends Exception {}
function wp_safe_redirect( $url ) { throw new Redirected( $url ); }
function get_post_types( ...$args ) {
    return [
        'post' => (object) [ 'labels' => (object) [ 'singular_name' => 'Запись' ] ],
        'vacancy' => (object) [ 'labels' => (object) [ 'singular_name' => 'Вакансия' ] ],
    ];
}
function is_post_type_viewable( $type ) { return true; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function wp_create_nonce( $action ) { return 'fixture'; }
function wp_unique_id( $prefix ) { static $id = 0; return $prefix . ++$id; }
function checked( $value ) { if ( $value ) echo 'checked'; }
function number_format_i18n( $value ) { return (string) $value; }

require dirname( __DIR__ ) . '/seo-instantindex.php';

function verify( $condition, $message ) {
    if ( ! $condition ) {
        throw new Exception( $message );
    }
}

function save( array $extra = [] ): void {
    $_POST = array_merge( [
        'hcin_action' => 'save_settings', 'post_types' => ['post'], 'google_enabled' => '1', 'indexnow_enabled' => '1',
    ], $extra );
    try {
        $GLOBALS['hooks']['admin_init']();
    } catch ( Redirected $redirect ) {
        return;
    }
    throw new Exception( 'Expected redirect' );
}

verify( hcin_settings()['post_types'] === ['post', 'page'], 'Unexpected IndexNow defaults' );
verify( hcin_settings()['google_post_types'] === [], 'Google auto-submission must require opt-in' );
$original = json_encode( [ 'client_email' => 'fixture@example.test', 'private_key' => 'FAKE_PRIVATE_KEY_OLD' ] );
$opts[HCIN_OPT] = [ 'google_sa_json' => $original, 'post_types' => ['post'], 'indexnow_enabled' => true, 'google_enabled' => true ];
save( [ 'google_post_types' => ['vacancy', 'invalid'] ] );
verify( $opts[HCIN_OPT]['google_sa_json'] === $original, 'Unrelated save lost credential' );
verify( $opts[HCIN_OPT]['google_post_types'] === ['vacancy'] && $opts[HCIN_OPT]['post_types'] === ['post'], 'Service type lists must be independent' );
$next = json_encode( [ 'client_email' => 'fixture-new@example.test', 'private_key' => "FAKE_PRIVATE_KEY_NEW\nline2" ] );
$transients[hcin_google_token_key( $original )] = 'fixture-cache';
save( [ 'google_sa_json' => addslashes( $next ) ] );
verify( $opts[HCIN_OPT]['google_sa_json'] === $next, 'Replacement JSON changed' );
verify( ! get_transient( hcin_google_token_key( $original ) ), 'Cached token not cleared' );
save( [ 'google_sa_json' => '' ] );
verify( $opts[HCIN_OPT]['google_sa_json'] === $next, 'Empty field erased credential' );
save( [ 'google_sa_json' => '{"client_email":["invalid"],"private_key":"invalid"}' ] );
verify( $opts[HCIN_OPT]['google_sa_json'] === $next, 'Invalid JSON erased credential' );
verify( get_transient( hcin_flash_key() )[0] === 'error', 'Invalid JSON not reported' );

$_GET = [];
ob_start();
hcin_admin_page();
$html = ob_get_clean();
verify( strpos( $html, 'FAKE_PRIVATE_KEY_NEW' ) === false, 'Private key leaked to HTML' );
verify( strpos( $html, 'impik.dev' ) !== false, 'Author not shown' );
verify( preg_match( '/<hr class="wp-header-end">\s*<div class="hcin_admin">/', $html ) === 1, 'WordPress notices need an anchor outside plugin styling' );
verify( strpos( $html, 'notice notice-error inline hcin_notice' ) !== false, 'Plugin feedback must stay below its tabs' );
verify( strpos( $html, 'Как подключить Google' ) !== false, 'Google setup help missing' );
verify( hcin_admin_design_css( [] ) === '', 'Default design must work without a theme' );
verify( hcin_admin_design_css( [ 'accent' => '#222222', 'radius' => 14, 'font_family' => 'Exo 2, system-ui' ] )
    === '--hcin-accent:#222222;--hcin-accent-ink:#fff;--hcin-radius:14px;--hcin-font:Exo 2, system-ui;--hcin-display:var(--hcin-font);', 'Theme overrides not applied' );
verify( hcin_admin_design_css( [ 'accent' => '#fff; }', 'radius' => 80, 'font_family' => 'url(https://example.test)' ] ) === '', 'Invalid design tokens must be ignored' );
save( [ 'google_sa_remove' => '1' ] );
verify( $opts[HCIN_OPT]['google_sa_json'] === '', 'Explicit removal failed' );

$encoded_url = home_url( '/path%20name/%D1%82/?ref=a%2Bb' );
verify( hcin_valid_urls( [ $encoded_url . "\r", $encoded_url ] ) === [$encoded_url], 'Encoded URL changed or duplicated' );
verify( hcin_valid_urls( [ 'https://other.test/path', home_url( '/path#fragment' ), home_url( '/a b' ) ] ) === [], 'Invalid URL accepted' );
echo "Passed: settings, Google credential, provider type lists and URL validation\n";
