<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Custom_Widget_Executor {
    public function execute( $component ) {
        $registry = new Design_Core_Elementor_Widget_Registry();
        $type = sanitize_key( $component['type'] ?? 'custom-component' );
        foreach ( $registry->all() as $item ) {
            $purposes = $item['source']['purpose'] ?? $item['purpose'] ?? $item['capabilities'] ?? array();
            if ( in_array( $type, $purposes, true ) && ! empty( $item['slug'] ) ) {
                return array( 'status'=>'success', 'widget_type'=>$item['slug'], 'created'=>false, 'definition'=>$item );
            }
        }

        $filtered = apply_filters( 'design_core_elementor_generate_custom_widget', null, $component );
        if ( is_array( $filtered ) && ! empty( $filtered['widget_type'] ) ) { return array_merge( array( 'status'=>'success', 'created'=>true ), $filtered ); }

        if ( current_user_can( 'manage_options' ) ) {
            $slug = 'dc-' . sanitize_title( $type ?: 'custom-component' );
            $definition = array( 'content_schema' => $component['content_schema'] ?? array( 'heading','rich_text','link' ) );
            $result = ( new Design_Core_Elementor_Widget_Generator() )->generate( $slug, ucwords( str_replace( '-', ' ', $type ) ), array( $type ), $definition );
            if ( ! is_wp_error( $result ) && in_array( $result['status'] ?? '', array( 'generated', 'exists' ), true ) ) {
                return array( 'status'=>'success', 'widget_type'=>$result['slug'], 'created'=>'generated'===($result['status']??''), 'reload_required'=>false, 'definition'=>$result['definition']??array() );
            }
        }

        return array( 'status'=>'deferred', 'widget_type'=>'', 'created'=>false, 'reason'=>'No reusable custom widget could be created in the current runtime.' );
    }
}
