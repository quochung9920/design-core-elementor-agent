<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Sole owner of Elementor V3 JSON/controls; persistence is delegated to the governed service. */
class Design_Core_Elementor_V3_Adapter implements Design_Core_Elementor_Adapter_Interface {
    private $last_persistence_evidence = array();

    public function target() { return 'elementor-v3'; }
    public function get_mode() { return 'v3'; }
    public function supports( $capability ) { return in_array( $capability, array( 'normalize', 'save', 'reload', 'render', 'responsive' ), true ); }
    public function normalize( $ir ) {
        $mapper = class_exists( 'Design_Core_Elementor_Semantic_Native_Mapping_V2' )
            ? new Design_Core_Elementor_Semantic_Native_Mapping_V2()
            : new Design_Core_Elementor_Mapping_Engine();
        $elements = $mapper->map_ir( $ir );
        if ( class_exists( 'Design_Core_Elementor_Elementor_Setting_Governor' ) ) { $elements = ( new Design_Core_Elementor_Elementor_Setting_Governor() )->govern_document( $elements, $ir ); }
        // Final choke point: every setting that survived mapping and
        // post-governance must still satisfy the live runtime schema before
        // any executor sees the tree.
        if ( class_exists( 'Design_Core_Elementor_Binding_Governor' ) && class_exists( 'Design_Core_Elementor_Control_Schema_Registry' ) ) {
            $elements = $this->govern_tree( $elements, new Design_Core_Elementor_Control_Schema_Registry() );
        }
        return $elements;
    }

    private function govern_tree( array $elements, $registry ) {
        foreach ( $elements as &$element ) {
            if ( ! is_array( $element ) ) { continue; }
            $type = strtolower( (string) ( $element['elType'] ?? '' ) );
            if ( in_array( $type, array( 'widget', 'container', 'section', 'column' ), true ) ) {
                $notes = array();
                $element['settings'] = Design_Core_Elementor_Binding_Governor::govern_settings(
                    'widget' === $type ? 'widget' : 'container',
                    'widget' === $type ? (string) ( $element['widgetType'] ?? '' ) : '',
                    is_array( $element['settings'] ?? null ) ? $element['settings'] : array(),
                    $registry,
                    $notes
                );
            }
            if ( ! empty( $element['elements'] ) ) { $element['elements'] = $this->govern_tree( $element['elements'], $registry ); }
        }
        unset( $element );
        return array_values( $elements );
    }
    public function normalize_as_widget( $ir, $widget_type ) { return ( new Design_Core_Elementor_Mapping_Engine() )->map_ir_as_widget( $ir, $widget_type ); }
    public function normalize_elements( $elements ) { return array_values( is_array( $elements ) ? $elements : array() ); }

    public function validate( $elements ) {
        if ( ! is_array( $elements ) || empty( $elements ) ) { return false; }
        foreach ( $elements as $element ) { if ( ! $this->validate_element( $element ) ) { return false; } }
        return true;
    }

    private function validate_element( $element ) {
        if ( ! is_array( $element ) || empty( $element['id'] ) || ! is_string( $element['elType'] ?? null ) || ! is_array( $element['settings'] ?? null ) || ! is_array( $element['elements'] ?? null ) ) { return false; }
        if ( ! in_array( $element['elType'], array( 'container', 'section', 'column', 'widget' ), true ) ) { return false; }
        if ( 'widget' === $element['elType'] && empty( $element['widgetType'] ) ) { return false; }
        foreach ( $element['elements'] as $child ) { if ( ! $this->validate_element( $child ) ) { return false; } }
        return true;
    }

    public function save( $document_id, $elements, $context = array() ) { return $this->save_page( $document_id, $elements, $context ); }

    public function save_page( $post_id, $elements, $settings = array() ) {
        $elements = $this->normalize_elements( $elements );
        if ( ! $this->validate( $elements ) ) { return new WP_Error( 'design_core_invalid_elements', 'Elementor element payload is invalid.' ); }
        $service = new Design_Core_Elementor_Persistence_Service();
        $evidence = $service->save_and_verify( (int) $post_id, $elements, $settings, array( $this, 'raw_save_page' ), array( $this, 'reload' ), array( $this, 'render' ), array( 'adapter' => $this->target(), 'source' => 'adapter-save' ) );
        if ( is_wp_error( $evidence ) ) { return $evidence; }
        $this->last_persistence_evidence = $evidence;
        return true;
    }

    /** Low-level V3 document write; callers should normally use save_page(). */
    public function raw_save_page( $post_id, $elements, $settings = array() ) {
        if ( ! class_exists( '\\Elementor\\Plugin' ) || empty( \Elementor\Plugin::$instance->documents ) ) { return new WP_Error( 'design_core_elementor_runtime_unavailable', 'Elementor document runtime is unavailable.' ); }
        try {
            $document = \Elementor\Plugin::$instance->documents->get( $post_id );
            if ( ! $document ) { return new WP_Error( 'design_core_elementor_document_missing', 'Elementor document is unavailable.' ); }
            update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
            update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
            if ( defined( 'ELEMENTOR_VERSION' ) ) { update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION ); }
            $save_result = $document->save( array( 'elements' => $elements, 'settings' => $settings ) );
            if ( is_wp_error( $save_result ) || false === $save_result || null === $save_result ) { return is_wp_error( $save_result ) ? $save_result : new WP_Error( 'design_core_elementor_save_failed', 'Elementor rejected the document save.' ); }
        } catch ( Throwable $exception ) { return new WP_Error( 'design_core_elementor_save_failed', $exception->getMessage() ); }
        $stored = get_post_meta( $post_id, '_elementor_data', true );
        $decoded = json_decode( (string) $stored, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! $this->validate( $decoded ) ) { return new WP_Error( 'design_core_elementor_save_unverified', 'Elementor data failed JSON or payload verification after save.' ); }
        return true;
    }

    public function last_persistence_evidence() { return $this->last_persistence_evidence; }
    public function reload( $document_id ) { $stored = get_post_meta( $document_id, '_elementor_data', true ); $decoded = json_decode( (string) $stored, true ); return is_array( $decoded ) ? $decoded : array(); }
    public function render( $document_id ) {
        if ( class_exists( '\\Elementor\\Plugin' ) ) { try { return (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $document_id, true ); } catch ( Throwable $exception ) { return ''; } }
        return '';
    }
}