<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Settings {
    const OPTION_CONVERSION_RULES = 'design_core_conversion_rules';
    const OPTION_QA_THRESHOLDS = 'design_core_qa_thresholds';

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized', 'design-core-elementor' ) ); }
        if ( isset( $_POST['save_settings'] ) ) {
            check_admin_referer( 'design_core_settings', 'design_core_settings_nonce' );
            $this->save_settings();
        }
        $tokens = ( new Design_Core_Elementor_Design_Token_Service() )->all();
        $rules = self::get_conversion_rules(); $qa = self::get_qa_thresholds();
        ?>
        <div class="wrap design-core-admin-shell design-core-settings"><h1><?php esc_html_e( 'Design Core Settings', 'design-core-elementor' ); ?></h1>
        <form method="post"><?php wp_nonce_field( 'design_core_settings', 'design_core_settings_nonce' ); ?>
            <h2><?php esc_html_e( 'Design tokens', 'design-core-elementor' ); ?></h2>
            <p><label>Primary color <input name="tokens[colors][primary]" value="<?php echo esc_attr( $tokens['colors']['primary'] ?? '#173F35' ); ?>"></label></p>
            <p><label>Accent color <input name="tokens[colors][accent]" value="<?php echo esc_attr( $tokens['colors']['accent'] ?? '#F4A261' ); ?>"></label></p>
            <p><label>Primary font <input name="tokens[typography][primary]" value="<?php echo esc_attr( is_string( $tokens['typography']['primary'] ?? '' ) ? $tokens['typography']['primary'] : '' ); ?>"></label></p>
            <p><label>Spacing scale <input name="tokens_spacing" value="<?php echo esc_attr( implode( ', ', array_values( $tokens['spacing'] ?? array() ) ) ); ?>"></label></p>

            <h2><?php esc_html_e( 'Conversion', 'design-core-elementor' ); ?></h2>
            <p><label>Reuse threshold <input type="number" step="0.05" min="0" max="1" name="rules[auto_match_threshold]" value="<?php echo esc_attr( $rules['auto_match_threshold'] ?? 0.75 ); ?>"></label></p>
            <p><label>Minimum native rate % <input type="number" step="0.1" min="0" max="100" name="rules[min_native_rate]" value="<?php echo esc_attr( $rules['min_native_rate'] ?? 90 ); ?>"> (report-only quality gate for native Elementor strategies)</label></p>
            <p><label><input type="checkbox" name="rules[create_as_draft]" value="1" <?php checked( ! empty( $rules['create_as_draft'] ) ); ?>> Create pages as draft</label></p>
            <p><label><input type="checkbox" name="rules[import_assets]" value="1" <?php checked( ! empty( $rules['import_assets'] ) ); ?>> Import remote images into Media Library</label></p>
            <p><label><input type="checkbox" name="rules[browser_analysis]" value="1" <?php checked( ! empty( $rules['browser_analysis'] ) ); ?>> Run Playwright browser analysis when available</label></p>

            <h2><?php esc_html_e( 'QA', 'design-core-elementor' ); ?></h2>
            <p><label>Color limit <input type="number" min="1" name="qa[color_limit]" value="<?php echo esc_attr( $qa['color_limit'] ?? 8 ); ?>"></label></p>
            <p><label>Font limit <input type="number" min="1" name="qa[font_limit]" value="<?php echo esc_attr( $qa['font_limit'] ?? 3 ); ?>"></label></p>
            <p><label>Minimum score <input type="number" min="0" max="100" name="qa[min_score]" value="<?php echo esc_attr( $qa['min_score'] ?? 80 ); ?>"></label></p>
            <p><label>Max custom-CSS ratio <input type="number" step="0.01" min="0" max="1" name="qa[max_custom_css_ratio]" value="<?php echo esc_attr( $qa['max_custom_css_ratio'] ?? 0 ); ?>"> (0 = strict: any custom CSS fails the structural gate)</label></p>
            <p><button class="button button-primary" name="save_settings" value="1">Save Settings</button></p>
        </form></div>
        <?php
    }

    private function save_settings() {
        $tokens = isset( $_POST['tokens'] ) && is_array( $_POST['tokens'] ) ? wp_unslash( $_POST['tokens'] ) : array();
        $spacing = isset( $_POST['tokens_spacing'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['tokens_spacing'] ) ) ) : array();
        $tokens['spacing'] = $spacing;
        $tokens = self::sanitize_design_tokens( $tokens );
        ( new Design_Core_Elementor_Design_Token_Service() )->save( $tokens );

        $raw_rules = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array();
        $rules = self::sanitize_conversion_rules( $raw_rules );
        update_option( self::OPTION_CONVERSION_RULES, $rules, false );

        $raw_qa = isset( $_POST['qa'] ) && is_array( $_POST['qa'] ) ? wp_unslash( $_POST['qa'] ) : array();
        update_option( self::OPTION_QA_THRESHOLDS, self::sanitize_qa_thresholds( $raw_qa ), false );
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    public static function get_design_tokens() { return ( new Design_Core_Elementor_Design_Token_Service() )->all(); }
    public static function get_conversion_rules() { return get_option( self::OPTION_CONVERSION_RULES, array( 'auto_match_threshold'=>0.75, 'create_as_draft'=>true, 'import_assets'=>true, 'browser_analysis'=>false, 'min_native_rate'=>90.0 ) ); }
    public static function get_qa_thresholds() { return get_option( self::OPTION_QA_THRESHOLDS, array( 'color_limit'=>8, 'font_limit'=>3, 'min_score'=>80, 'max_custom_css_ratio'=>0.0 ) ); }

    public static function sanitize_conversion_rules( $raw ) {
        $raw = is_array( $raw ) ? $raw : array();
        return array( 'auto_match_threshold' => (float) max( 0, min( 1, (float) ( $raw['auto_match_threshold'] ?? 0.75 ) ) ), 'create_as_draft' => ! empty( $raw['create_as_draft'] ), 'import_assets' => ! empty( $raw['import_assets'] ), 'browser_analysis' => ! empty( $raw['browser_analysis'] ), 'min_native_rate' => (float) max( 0, min( 100, (float) ( $raw['min_native_rate'] ?? 90.0 ) ) ) );
    }

    public static function sanitize_qa_thresholds( $raw ) {
        $raw = is_array( $raw ) ? $raw : array();
        return array( 'color_limit' => max( 1, (int) ( $raw['color_limit'] ?? 8 ) ), 'font_limit' => max( 1, (int) ( $raw['font_limit'] ?? 3 ) ), 'min_score' => max( 0, min( 100, (int) ( $raw['min_score'] ?? 80 ) ) ), 'max_custom_css_ratio' => (float) max( 0, min( 1, (float) ( $raw['max_custom_css_ratio'] ?? 0.0 ) ) ) );
    }

    public static function sanitize_design_tokens( $raw ) {
        $raw = is_array( $raw ) ? $raw : array(); $result = array( 'colors' => array(), 'typography' => array(), 'spacing' => array(), 'radius' => array() );
        foreach ( $raw['colors'] ?? array() as $name => $value ) { if ( is_string( $value ) ) { $result['colors'][ sanitize_key( $name ) ] = sanitize_text_field( $value ); } }
        foreach ( $raw['typography'] ?? array() as $name => $value ) { if ( is_string( $value ) ) { $result['typography'][ sanitize_key( $name ) ] = sanitize_text_field( $value ); } }
        foreach ( $raw['spacing'] ?? array() as $value ) { if ( is_numeric( $value ) ) { $result['spacing'][] = (float) $value; } }
        foreach ( $raw['radius'] ?? array() as $name => $value ) { if ( is_numeric( $value ) ) { $result['radius'][ sanitize_key( $name ) ] = (float) $value; } }
        return $result;
    }
}
