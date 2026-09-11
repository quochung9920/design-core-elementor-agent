<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Idempotently owns Elementor Pro Theme Builder documents. */
class Design_Core_Elementor_Theme_Part_Provisioner {
    const OWNER_META = '_design_core_theme_part_owner';

    public function upsert( $type, $owner, $title, array $elements, array $conditions = array() ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'design_core_theme_part_forbidden', 'Administrator capability is required to provision Theme Parts.' ); }
        $type = sanitize_key( (string) $type ); $owner = sanitize_key( (string) $owner );
        if ( ! in_array( $type, array( 'header', 'footer' ), true ) || '' === $owner ) { return new WP_Error( 'design_core_invalid_theme_part', 'Theme Part type or owner is invalid.' ); }
        $adapter = new Design_Core_Elementor_V3_Adapter();
        if ( ! $adapter->validate( $elements ) ) { return new WP_Error( 'design_core_invalid_theme_part_elements', 'Theme Part element payload is invalid.' ); }
        if ( ! class_exists( '\\Elementor\\Plugin' ) || ! class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module' ) ) { return new WP_Error( 'design_core_theme_builder_unavailable', 'Elementor Pro Theme Builder is unavailable.' ); }

        $ids = get_posts( array( 'post_type' => 'elementor_library', 'post_status' => 'any', 'numberposts' => 3, 'fields' => 'ids', 'meta_key' => self::OWNER_META, 'meta_value' => $owner ) );
        if ( count( $ids ) > 1 ) { return new WP_Error( 'design_core_duplicate_theme_part', 'Multiple Theme Parts claim the same Design Core owner.' ); }
        $created = false;
        if ( empty( $ids ) ) {
            $document = \Elementor\Plugin::$instance->documents->create( $type, array( 'post_title' => sanitize_text_field( $title ), 'post_status' => 'publish' ), array( self::OWNER_META => $owner ) );
            if ( is_wp_error( $document ) ) { return $document; }
            $post_id = (int) $document->get_main_id(); $created = true;
        } else {
            $post_id = (int) $ids[0]; $document = \Elementor\Plugin::$instance->documents->get( $post_id );
            if ( ! $document || $type !== (string) get_post_meta( $post_id, '_elementor_template_type', true ) ) { return new WP_Error( 'design_core_theme_part_type_mismatch', 'Existing Theme Part owner has an incompatible document type.' ); }
            wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $title ), 'post_status' => 'publish' ) );
        }

        update_post_meta( $post_id, self::OWNER_META, $owner );
        $saved = ( new Design_Core_Elementor_Persistence_Service() )->save_and_verify(
            $post_id, array_values( $elements ), array(),
            static function ( $document_id, $document_elements, $document_settings ) use ( $document ) {
                $result = $document->save( array( 'elements' => array_values( $document_elements ), 'settings' => (array) $document_settings ) );
                if ( is_wp_error( $result ) || false === $result || null === $result ) { return is_wp_error( $result ) ? $result : new WP_Error( 'design_core_theme_part_save_failed', 'Elementor rejected the Theme Part save.' ); }
                return true;
            },
            array( $adapter, 'reload' ), array( $adapter, 'render' ),
            array( 'adapter' => 'elementor-v3', 'source' => 'theme-part-provisioner', 'theme_part_type' => $type, 'owner' => $owner )
        );
        if ( is_wp_error( $saved ) ) { if ( $created ) { wp_delete_post( $post_id, true ); } return $saved; }

        $manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
        $condition_rows = $conditions ?: array( array( 'type' => 'include', 'name' => 'general', 'sub_name' => '', 'sub_id' => '' ) ); $expected_conditions = array();
        foreach ( $condition_rows as $condition ) { unset( $condition['_id'] ); $expected_conditions[] = rtrim( implode( '/', $condition ), '/' ); }
        $conditions_saved = $manager->save_conditions( $post_id, $condition_rows ); $persisted_conditions = $document->get_meta( '_elementor_conditions' );
        if ( false === $conditions_saved && array_values( (array) $persisted_conditions ) !== $expected_conditions ) { return new WP_Error( 'design_core_theme_part_conditions_failed', 'Elementor Pro rejected the Theme Part display conditions.' ); }
        $reloaded = $adapter->reload( $post_id );
        if ( ! $adapter->validate( $reloaded ) ) { return new WP_Error( 'design_core_theme_part_reload_failed', 'Theme Part failed persistence read-back.' ); }
        return array( 'id' => $post_id, 'type' => $type, 'owner' => $owner, 'created' => $created, 'elements' => $reloaded, 'persistence' => $saved );
    }
}
