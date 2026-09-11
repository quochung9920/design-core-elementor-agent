<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local Design Intelligence inspection/recommendation UI (REST removed). */
class Design_Core_Elementor_Design_Intelligence_Admin {
    const SLUG = 'design-core-design-intelligence';
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) { return; }
        self::$booted = true;
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 20 );
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'design-core-elementor',
            'Design Intelligence',
            'Design Intelligence',
            'manage_options',
            self::SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized', 'design-core-elementor' ) ); }
        $catalog = new Design_Core_Elementor_Design_Intelligence_Catalog();
        $status = $catalog->status();
        $result = null;
        $brief = isset( $_POST['design_core_design_brief'] ) ? sanitize_textarea_field( wp_unslash( $_POST['design_core_design_brief'] ) ) : '';
        $product_type = isset( $_POST['design_core_design_product_type'] ) ? sanitize_text_field( wp_unslash( $_POST['design_core_design_product_type'] ) ) : '';
        $mode = isset( $_POST['design_core_design_mode'] ) ? sanitize_key( wp_unslash( $_POST['design_core_design_mode'] ) ) : 'light';

        if ( isset( $_POST['design_core_design_recommend'] ) && check_admin_referer( 'design_core_design_recommend', 'design_core_design_nonce' ) ) {
            $result = ( new Design_Core_Elementor_Design_Advisor() )->recommend( $brief, array( 'product_type'=>$product_type, 'mode'=>$mode ) );
        }

        echo '<div class="wrap design-core-admin-shell design-core-design-intelligence">';
        echo '<h1>Design Intelligence</h1>';
        echo '<p>Local UI/UX guidance normalized from the MIT-licensed UI UX Pro Max knowledge model. WordPress does not execute Python or call the upstream repository at runtime.</p>';
        echo '<div class="design-core-widget-stats">';
        self::stat( 'Catalog', ! empty( $status['available'] ) ? 'Available' : 'Unavailable', 'local JSON' );
        self::stat( 'Product profiles', (string) ( $status['profiles'] ?? 0 ), 'starter/synced knowledge' );
        self::stat( 'UX rules', (string) ( $status['ux_rules'] ?? 0 ), 'quality guidance' );
        self::stat( 'Runtime dependency', 'PHP only', 'no Python / no network' );
        echo '</div>';

        echo '<div class="design-core-card"><h2>Recommend a Design System Profile</h2>';
        echo '<form method="post">';
        wp_nonce_field( 'design_core_design_recommend', 'design_core_design_nonce' );
        echo '<p><label><strong>Brief</strong><br><textarea name="design_core_design_brief" rows="5" class="large-text" placeholder="e.g. International engineering and construction manpower company, B2B, premium, trustworthy">' . esc_textarea( $brief ) . '</textarea></label></p>';
        echo '<p><label>Product type override <input class="regular-text" name="design_core_design_product_type" value="' . esc_attr( $product_type ) . '" placeholder="Optional, e.g. B2B Service"></label> ';
        echo '<label>Mode <select name="design_core_design_mode"><option value="light"' . selected( $mode, 'light', false ) . '>Light</option><option value="dark"' . selected( $mode, 'dark', false ) . '>Dark</option></select></label></p>';
        echo '<p><button class="button button-primary" name="design_core_design_recommend" value="1">Recommend</button></p></form></div>';

        if ( is_wp_error( $result ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
        } elseif ( is_array( $result ) ) {
            echo '<div class="design-core-card"><h2>Recommendation</h2><pre class="design-core-registry-json">' . esc_html( wp_json_encode( Design_Core_Elementor_Change_Ledger::transport_safe( $result ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></div>';
        }

        echo '<div class="design-core-card"><h2>Source & sync policy</h2><p>The bundled catalog is intentionally a starter subset. To import the complete upstream product/reasoning/color/typography/landing/UX datasets, run <code>tools/sync-uiux-promax.py</code> against a separate checkout of UI UX Pro Max, review the generated JSON, then commit it deliberately. Runtime fetching is intentionally not supported.</p></div>';
        echo '</div>';
    }

    private static function stat( $label, $value, $note ) {
        echo '<div class="design-core-widget-stat"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong><small>' . esc_html( $note ) . '</small></div>';
    }
}
