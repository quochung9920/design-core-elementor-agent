<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One authoritative read model for a Design Core / Elementor page. */
class Design_Core_Elementor_Page_Snapshot {
    const SCHEMA_VERSION = 1;

    public function snapshot( $page_id ) {
        $page_id = (int) $page_id;
        $post = function_exists( 'get_post' ) ? get_post( $page_id ) : null;
        if ( ! $post ) { return new WP_Error( 'design_core_snapshot_page_missing', 'Page was not found.' ); }
        $manifest = get_post_meta( $page_id, '_design_core_page_manifest', true ); if ( ! is_array( $manifest ) ) { $manifest = array(); }
        $visual = get_post_meta( $page_id, '_design_core_visual_feedback', true ); if ( ! is_array( $visual ) ) { $visual = array(); }
        $structural_qa = class_exists( 'Design_Core_Elementor_Visual_QA' ) ? ( new Design_Core_Elementor_Visual_QA() )->audit_page( $page_id ) : array(); if ( ! is_array( $structural_qa ) ) { $structural_qa = array(); }
        $adapter = $this->runtime_adapter( $page_id ); $elements = $adapter ? $adapter->reload( $page_id ) : array();
        $tree = $this->summarize_elements( is_array( $elements ) ? $elements : array() );
        $tokens = class_exists( 'Design_Core_Elementor_Design_Token_Service' ) ? ( new Design_Core_Elementor_Design_Token_Service() )->all() : array();
        $warnings = array();
        if ( ! $manifest ) { $warnings[] = 'Page has no Design Core Page Manifest.'; }
        if ( ! $elements ) { $warnings[] = 'Elementor document tree is empty or could not be reloaded.'; }
        if ( ! $visual ) { $warnings[] = 'No persisted Visual Feedback v2 result is available for this page.'; }
        if ( 'fail' === ( $structural_qa['status'] ?? '' ) ) { $warnings[] = 'Live structural Visual QA reports a failure.'; }
        $sections = array();
        if ( class_exists( 'Design_Core_Elementor_Section_Explainability' ) && $manifest ) { $sections = ( new Design_Core_Elementor_Section_Explainability() )->explain_manifest( $page_id ); if ( is_wp_error( $sections ) ) { $sections = array(); } }
        return array(
            'schema_version' => self::SCHEMA_VERSION, 'generated_at' => gmdate( 'c' ),
            'page' => array( 'id' => $page_id, 'title' => sanitize_text_field( (string) $post->post_title ), 'status' => sanitize_key( (string) $post->post_status ), 'type' => sanitize_key( (string) $post->post_type ), 'modified_gmt' => (string) $post->post_modified_gmt ),
            'design_core' => array( 'manifest' => $manifest, 'sections' => $sections, 'manifest_section_count' => count( (array) ( $manifest['sections'] ?? array() ) ) ),
            'elementor' => array( 'editor_mode' => $this->editor_mode( $page_id ), 'root_elements' => count( is_array( $elements ) ? $elements : array() ), 'total_elements' => $tree['total_elements'], 'containers' => $tree['containers'], 'widgets' => $tree['widgets'], 'widget_types' => $tree['widget_types'], 'widget_profiles' => $this->widget_profiles( array_keys( $tree['widget_types'] ) ), 'responsive_override_count' => $tree['responsive_override_count'], 'element_tree_hash' => Design_Core_Elementor_Change_Ledger::hash_value( $elements ), 'content_outline' => $tree['content_outline'] ),
            'tokens' => $tokens, 'visual_qa' => $structural_qa, 'visual_feedback' => $visual, 'history' => $this->page_history( $page_id ), 'warnings' => $warnings,
        );
    }

    public function summarize_elements( array $elements ) {
        $summary = array( 'total_elements' => 0, 'containers' => 0, 'widgets' => 0, 'widget_types' => array(), 'responsive_override_count' => 0, 'content_outline' => array() );
        $walk = function ( array $items ) use ( &$walk, &$summary ) {
            foreach ( $items as $element ) {
                if ( ! is_array( $element ) ) { continue; }
                $summary['total_elements']++; $type = (string) ( $element['elType'] ?? '' );
                if ( in_array( $type, array( 'container', 'section', 'column' ), true ) ) { $summary['containers']++; }
                if ( 'widget' === $type ) {
                    $summary['widgets']++; $widget = sanitize_key( (string) ( $element['widgetType'] ?? 'unknown' ) ); $summary['widget_types'][ $widget ] = ( $summary['widget_types'][ $widget ] ?? 0 ) + 1;
                    $content = $this->element_content_excerpt( (array) ( $element['settings'] ?? array() ) ); if ( '' !== $content && count( $summary['content_outline'] ) < 100 ) { $summary['content_outline'][] = array( 'widget' => $widget, 'text' => $content ); }
                }
                foreach ( (array) ( $element['settings'] ?? array() ) as $key => $value ) { if ( preg_match( '/_(?:tablet|mobile|widescreen|laptop|tablet_extra|mobile_extra)$/', (string) $key ) ) { $summary['responsive_override_count']++; } }
                if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) { $walk( $element['elements'] ); }
            }
        };
        $walk( $elements ); ksort( $summary['widget_types'] ); return $summary;
    }

    private function widget_profiles( array $widget_types ) {
        if ( ! class_exists( 'Design_Core_Elementor_Widget_Intelligence' ) ) { return array(); }
        $service = new Design_Core_Elementor_Widget_Intelligence(); $profiles = array();
        foreach ( array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_key', $widget_types ) ) ) ), 0, 100 ) as $widget_type ) {
            $detail = $service->inspect( $widget_type ); if ( is_wp_error( $detail ) || ! is_array( $detail ) ) { continue; }
            $profiles[ $widget_type ] = array( 'title' => (string) ( $detail['title'] ?? $widget_type ), 'source' => (string) ( $detail['source'] ?? '' ), 'semantic' => (array) ( $detail['semantic'] ?? array() ), 'capabilities' => (array) ( $detail['capabilities'] ?? array() ), 'schema_fingerprint' => (string) ( $detail['schema_fingerprint'] ?? '' ) );
        }
        return $profiles;
    }

    private function element_content_excerpt( array $settings ) {
        foreach ( $settings as $key => $value ) {
            if ( ! is_scalar( $value ) || ! preg_match( '/(?:title|heading|text|editor|description|caption|content|label)/i', (string) $key ) ) { continue; }
            $text = trim( wp_strip_all_tags( (string) $value ) ); if ( '' === $text ) { continue; }
            return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 180 ) : substr( $text, 0, 180 );
        }
        return '';
    }

    private function runtime_adapter( $page_id ) {
        $atomic = (bool) get_post_meta( $page_id, '_design_core_elementor_atomic', true );
        if ( $atomic && class_exists( 'Design_Core_Elementor_V4_Adapter' ) ) { $v4 = new Design_Core_Elementor_V4_Adapter(); if ( $v4->supports( 'reload' ) ) { return $v4; } }
        return class_exists( 'Design_Core_Elementor_V3_Adapter' ) ? new Design_Core_Elementor_V3_Adapter() : null;
    }
    private function editor_mode( $page_id ) { if ( get_post_meta( $page_id, '_design_core_elementor_atomic_fallback', true ) ) { return 'v3-fallback'; } if ( get_post_meta( $page_id, '_design_core_elementor_atomic', true ) ) { return 'v4'; } return 'v3'; }
    private function page_history( $page_id ) { if ( ! class_exists( 'Design_Core_Elementor_Change_Ledger' ) ) { return array(); } return array_values( array_filter( ( new Design_Core_Elementor_Change_Ledger() )->summaries(), static function ( $entry ) use ( $page_id ) { return 'post' === ( $entry['object_type'] ?? '' ) && (int) ( $entry['object_id'] ?? 0 ) === (int) $page_id; } ) ); }
}
