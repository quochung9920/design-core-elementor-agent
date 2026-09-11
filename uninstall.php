<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'design_core_elementor_components' );
delete_option( 'design_core_elementor_sections' );
delete_option( 'design_core_elementor_widgets' );
delete_option( 'design_core_elementor_tokens' );
