<?php
/** Minimal WordPress stubs for deterministic contract tests. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DESIGN_CORE_ELEMENTOR_VERSION', '1.0.0-rc1' );
define( 'DESIGN_CORE_ELEMENTOR_PATH', dirname( __DIR__ ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );
$dc_vendor_autoload = DESIGN_CORE_ELEMENTOR_PATH . 'vendor/autoload.php';
if ( is_readable( $dc_vendor_autoload ) ) { require_once $dc_vendor_autoload; }
$GLOBALS['dc_test_options'] = array();
$GLOBALS['dc_fail_option'] = '';
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['dc_test_options'] ) ? $GLOBALS['dc_test_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { if ( $GLOBALS['dc_fail_option'] === $key ) { $GLOBALS['dc_fail_option'] = ''; return false; } $GLOBALS['dc_test_options'][ $key ] = $value; return true; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) { if ( array_key_exists( $key, $GLOBALS['dc_test_options'] ) ) { return false; } $GLOBALS['dc_test_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['dc_test_options'][ $key ] ); return true; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return trim( strip_tags( preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $value ) ) ); }
function sanitize_html_class( $value ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value ); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ), '-' ); }
function esc_url_raw( $value ) { return preg_match( '#^https?://#i', (string) $value ) ? (string) $value : ''; }
function wp_kses_post( $value ) { return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_generate_uuid4() { static $i = 0; return '00000000-0000-4000-8000-' . str_pad( (string) ++$i, 12, '0', STR_PAD_LEFT ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_time( $type, $gmt = false ) { return '2026-08-31 12:00:00'; }
function wp_unslash( $value ) { return $value; }
function wp_slash( $value ) { return $value; }
function attachment_url_to_postid( $url ) { return 'https://example.test/uploads/why-icon.webp' === $url ? 321 : 0; }
function add_action() { return true; }
/** Real single-callback-per-tag hook support: no test currently calls add_filter(), so
 *  every existing apply_filters() caller keeps seeing its default value unchanged. This
 *  exists so a test can register a bounded, test-only fault-injection callback. */
$GLOBALS['dc_test_filters'] = array();
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['dc_test_filters'][ $tag ][] = $callback; return true; }
function apply_filters( $name, $value ) {
    if ( empty( $GLOBALS['dc_test_filters'][ $name ] ) ) { return $value; }
    $args = array_slice( func_get_args(), 1 ); // [value, ...extra_args], tag name is not passed to callbacks
    foreach ( $GLOBALS['dc_test_filters'][ $name ] as $callback ) { $args[0] = $value; $value = call_user_func_array( $callback, $args ); }
    return $value;
}
function sanitize_hex_color( $value ) { return is_string( $value ) && preg_match( '/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $value ) ? $value : null; }
function absint( $value ) { return abs( (int) $value ); }
function get_bloginfo( $field ) { return '6.8'; }
$GLOBALS['dc_test_transients'] = array();
function set_transient( $key, $value, $expiration = 0 ) { $GLOBALS['dc_test_transients'][ $key ] = array( 'value' => $value, 'expires' => $expiration > 0 ? time() + $expiration : 0 ); return true; }
function get_transient( $key ) { if ( ! isset( $GLOBALS['dc_test_transients'][ $key ] ) ) { return false; } $t = $GLOBALS['dc_test_transients'][ $key ]; if ( $t['expires'] && $t['expires'] < time() ) { unset( $GLOBALS['dc_test_transients'][ $key ] ); return false; } return $t['value']; }
function delete_transient( $key ) { unset( $GLOBALS['dc_test_transients'][ $key ] ); return true; }
/** Deletion instrumentation + optional sync with a test's own post/attachment store, so a
 *  test can assert "this ID was/was not ever sent to the destructive delete path" and see
 *  get_post()-equivalent reads reflect the deletion. No suite currently sets dc_test_posts
 *  or dc_test_attachments, so this is a pure no-op addition for every other caller -- the
 *  return value (always true) is unchanged from before. */
$GLOBALS['dc_test_deleted_post_ids'] = array();
$GLOBALS['dc_test_deleted_attachment_ids'] = array();
function wp_delete_post( $post_id, $force = false ) {
    $post_id = (int) $post_id;
    $GLOBALS['dc_test_deleted_post_ids'][] = $post_id;
    if ( isset( $GLOBALS['dc_test_posts'] ) && is_array( $GLOBALS['dc_test_posts'] ) ) { unset( $GLOBALS['dc_test_posts'][ $post_id ] ); }
    return true;
}
function wp_delete_attachment( $post_id, $force = false ) {
    $post_id = (int) $post_id;
    $GLOBALS['dc_test_deleted_attachment_ids'][] = $post_id;
    if ( isset( $GLOBALS['dc_test_attachments'] ) && is_array( $GLOBALS['dc_test_attachments'] ) ) { unset( $GLOBALS['dc_test_attachments'][ $post_id ] ); }
    return true;
}
class WP_Error { private $code; private $message; public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
$GLOBALS['dc_assertions'] = 0;
$GLOBALS['dc_failures'] = 0;
function dc_assert( $condition, $message ) { $GLOBALS['dc_assertions']++; if ( ! $condition ) { $GLOBALS['dc_failures']++; fwrite( STDERR, "FAIL: {$message}\n" ); } }
function dc_finish( $suite ) { echo $suite . ': ' . $GLOBALS['dc_assertions'] . " assertions, " . $GLOBALS['dc_failures'] . " failures\n"; exit( $GLOBALS['dc_failures'] ? 1 : 0 ); }
function dc_require( $files ) { foreach ( $files as $file ) { require_once DESIGN_CORE_ELEMENTOR_PATH . $file; } }
