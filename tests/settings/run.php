<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
dc_require( array( 'core/design-token-service.php', 'includes/class-settings.php' ) );

$source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'includes/class-settings.php' );
dc_assert( false !== strpos( $source, "current_user_can( 'manage_options' )" ), 'manage_options required' );
dc_assert( false !== strpos( $source, "check_admin_referer( 'design_core_settings'" ), 'nonce required' );
$rules = Design_Core_Elementor_Settings::sanitize_conversion_rules( array( 'auto_match_threshold' => 3, 'create_as_draft' => 1 ) );
dc_assert( 1.0 === $rules['auto_match_threshold'], 'reuse threshold clamped to 1' );
$rules = Design_Core_Elementor_Settings::sanitize_conversion_rules( array( 'auto_match_threshold' => -1 ) );
dc_assert( 0.0 === $rules['auto_match_threshold'], 'reuse threshold clamped to 0' );
$qa = Design_Core_Elementor_Settings::sanitize_qa_thresholds( array( 'min_score' => 999 ) );
dc_assert( 100 === $qa['min_score'], 'QA score clamped to 100' );
$tokens = Design_Core_Elementor_Settings::sanitize_design_tokens( array( 'colors' => array( 'primary' => '#173F35' ), 'spacing' => array( '8', 'bad', 16 ) ) );
dc_assert( array( 8.0, 16.0 ) === $tokens['spacing'], 'spacing accepts numeric values only' );
dc_assert( '#173F35' === $tokens['colors']['primary'], 'valid token persists' );

dc_finish( 'Settings' );
