<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Widget_Generator {
    public function generate( $slug, $title, $purpose = array(), $definition = array() ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'design_core_forbidden', 'Insufficient permission to generate widgets.' ); }
        $safe_slug = sanitize_key( $slug );
        if ( ! $safe_slug ) { return new WP_Error( 'design_core_invalid_slug', 'Invalid widget slug.' ); }
        $title = sanitize_text_field( $title );
        $registry = new Design_Core_Elementor_Widget_Registry();
        $existing = $registry->get( $safe_slug );
        if ( $existing ) { return array( 'slug'=>$safe_slug, 'status'=>'exists', 'definition'=>$existing, 'reload_required'=>false ); }

        $content_schema = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $definition['content_schema'] ?? array( 'heading', 'rich_text', 'link' ) ) ) ) ) );
        $now = gmdate( 'c' );
        $item = array(
            'id' => $safe_slug, 'slug' => $safe_slug, 'type' => 'widget', 'schema_version' => 2, 'item_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
            'label' => $title,
            'source' => array( 'kind' => 'design-core-runtime', 'purpose' => array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $purpose ) ) ) ) ),
            'usage' => array( 'count' => 0, 'locations' => array() ),
            'fingerprint' => array( 'version' => 2, 'semantic' => sanitize_key( $purpose[0] ?? 'widget' ), 'structure' => 'generic-runtime-widget', 'content_schema' => $content_schema, 'layout' => '', 'interaction' => 'runtime' ),
            'definition' => array(
                'render_mode' => 'generic-runtime',
                'content_schema' => $content_schema,
                'created_at' => gmdate( 'c' ),
            ),
        );
        $saved = $registry->upsert( $item );
        if ( is_wp_error( $saved ) ) { return $saved; }
        return array( 'slug'=>$safe_slug, 'status'=>'generated', 'definition'=>$saved, 'reload_required'=>false );
    }
}
