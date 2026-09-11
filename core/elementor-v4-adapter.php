<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Elementor V4 uses only public build-composition plus governed transforms. */
class Design_Core_Elementor_V4_Adapter extends Design_Core_Elementor_V3_Adapter {
    public function target() { return 'elementor-v4'; }
    public function get_mode() { return 'v4'; }
    public function supports( $capability ) {
        if ( 'save' === $capability ) { return function_exists( 'wp_get_ability' ) && (bool) wp_get_ability( 'elementor/build-composition' ) && has_filter( 'design_core_elementor_v4_composition_transform' ); }
        if ( 'reload' === $capability ) { return has_filter( 'design_core_elementor_v4_composition_reload' ); }
        if ( 'render' === $capability ) { return has_filter( 'design_core_elementor_v4_composition_render' ); }
        return false;
    }
    public function reload( $document_id ) { $result = apply_filters( 'design_core_elementor_v4_composition_reload', null, (int) $document_id ); return is_array( $result ) ? $result : array(); }
    public function render( $document_id ) { $result = apply_filters( 'design_core_elementor_v4_composition_render', null, (int) $document_id ); return is_string( $result ) ? $result : ''; }

    public function save_page( $post_id, $elements, $settings = array() ) {
        if ( ! $this->supports( 'reload' ) || ! $this->supports( 'render' ) ) { return $this->v3_fallback( $post_id, $elements, $settings, 'governed-atomic-verification-unavailable' ); }
        if ( ! function_exists( 'wp_get_ability' ) ) { return $this->v3_fallback( $post_id, $elements, $settings, 'public-abilities-api-unavailable' ); }
        $ability = wp_get_ability( 'elementor/build-composition' );
        if ( ! $ability || ! method_exists( $ability, 'execute' ) ) { return $this->v3_fallback( $post_id, $elements, $settings, 'build-composition-unavailable' ); }
        $composition = apply_filters( 'design_core_elementor_v4_composition_transform', null, $elements, $settings, $ability );
        if ( ! $this->valid_composition( $composition ) ) { return $this->v3_fallback( $post_id, $elements, $settings, 'governed-composition-transform-unavailable' ); }
        try {
            $result = $ability->execute( array( 'post_id' => (int) $post_id, 'xml_structure' => $composition['xml_structure'], 'element_config' => $composition['element_config'], 'style' => $composition['style'], 'classes' => $composition['classes'], 'parent_id' => 'document', 'mode' => 'replace_children', 'dry_run' => false ) );
        } catch ( Throwable $exception ) { return $this->v3_fallback( $post_id, $elements, $settings, 'build-composition-exception' ); }
        if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['success'] ) ) { return $this->v3_fallback( $post_id, $elements, $settings, 'build-composition-failed' ); }
        ( new Design_Core_Elementor_Persistence_Service() )->invalidate_caches( $post_id );
        $snapshot = $this->reload( $post_id ); $rendered = $this->render( $post_id );
        if ( empty( $snapshot ) || '' === trim( (string) $rendered ) ) { return $this->v3_fallback( $post_id, $elements, $settings, 'atomic-save-verification-failed' ); }
        update_post_meta( $post_id, '_design_core_elementor_atomic', 1 ); delete_post_meta( $post_id, '_design_core_elementor_atomic_fallback' );
        if ( class_exists( 'Design_Core_Elementor_Change_Ledger' ) ) { ( new Design_Core_Elementor_Change_Ledger() )->record( 'elementor-atomic-save', 'post', $post_id, array(), $snapshot, array( 'adapter' => 'elementor-v4', 'rendered_bytes' => strlen( (string) $rendered ), 'rollback' => 'not-available-without-public-atomic-restore-contract' ) ); }
        if ( class_exists( 'Design_Core_Elementor_Runtime_Evidence' ) ) { ( new Design_Core_Elementor_Runtime_Evidence() )->record( 'elementor-persistence-service', 'pass', array( 'post_id' => (int) $post_id, 'adapter' => 'elementor-v4', 'reloaded_root_elements' => count( $snapshot ), 'rendered_bytes' => strlen( (string) $rendered ) ), 'persistence-service' ); }
        return true;
    }

    private function valid_composition( $composition ) {
        if ( ! is_array( $composition ) || ! is_string( $composition['xml_structure'] ?? null ) || '' === trim( $composition['xml_structure'] ) ) { return false; }
        foreach ( array( 'element_config', 'style', 'classes' ) as $field ) { if ( ! isset( $composition[ $field ] ) || ! is_array( $composition[ $field ] ) ) { return false; } }
        return true;
    }
    private function v3_fallback( $post_id, $elements, $settings, $reason ) { delete_post_meta( $post_id, '_design_core_elementor_atomic' ); update_post_meta( $post_id, '_design_core_elementor_atomic_fallback', sanitize_key( $reason ) ); return parent::save_page( $post_id, $elements, $settings ); }
}
