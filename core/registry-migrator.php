<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Registry_Migrator {
    const BACKUP_OPTION = 'design_core_elementor_registry_backup';

    public function migrate() {
        $backup = array(
            'created_at' => gmdate( 'c' ),
            'components' => get_option( Design_Core_Elementor_Component_Registry::OPTION_KEY, array() ),
            'sections' => class_exists( 'Design_Core_Elementor_Section_Registry' ) ? get_option( Design_Core_Elementor_Section_Registry::OPTION_KEY, array() ) : array(),
            'widgets' => get_option( Design_Core_Elementor_Widget_Registry::OPTION_KEY, array() ),
            'tokens' => get_option( Design_Core_Elementor_Design_Token_Service::OPTION_KEY, array() ),
        );
        update_option( self::BACKUP_OPTION, $backup, false );
        try {
            ( new Design_Core_Elementor_Component_Registry() )->ensure_defaults();
            if ( class_exists( 'Design_Core_Elementor_Section_Registry' ) ) { ( new Design_Core_Elementor_Section_Registry() )->ensure_defaults(); }
            ( new Design_Core_Elementor_Widget_Registry() )->ensure_defaults();
            ( new Design_Core_Elementor_Token_Registry() )->ensure_defaults();
            return array(
                'status' => 'migrated', 'backup_created' => true,
                'component_schema' => Design_Core_Elementor_Component_Registry::SCHEMA_VERSION,
                'section_schema' => class_exists( 'Design_Core_Elementor_Section_Registry' ) ? Design_Core_Elementor_Section_Registry::SCHEMA_VERSION : null,
                'section_fingerprint' => class_exists( 'Design_Core_Elementor_Section_Fingerprint_Service' ) ? Design_Core_Elementor_Section_Fingerprint_Service::VERSION : null,
                'widget_schema' => Design_Core_Elementor_Widget_Registry::SCHEMA_VERSION,
                'token_schema' => Design_Core_Elementor_Design_Token_Service::SCHEMA_VERSION,
            );
        } catch ( Throwable $e ) {
            $this->rollback();
            return new WP_Error( 'design_core_registry_migration_failed', $e->getMessage() );
        }
    }

    public function rollback() {
        $backup = get_option( self::BACKUP_OPTION, array() );
        if ( ! is_array( $backup ) || empty( $backup ) ) { return false; }
        update_option( Design_Core_Elementor_Component_Registry::OPTION_KEY, $this->coerce_payload( $backup['components'] ?? array(), Design_Core_Elementor_Component_Registry::SCHEMA_VERSION ), false );
        if ( class_exists( 'Design_Core_Elementor_Section_Registry' ) ) {
            update_option( Design_Core_Elementor_Section_Registry::OPTION_KEY, $this->coerce_payload( $backup['sections'] ?? array(), Design_Core_Elementor_Section_Registry::SCHEMA_VERSION ), false );
        }
        update_option( Design_Core_Elementor_Widget_Registry::OPTION_KEY, $this->coerce_payload( $backup['widgets'] ?? array(), Design_Core_Elementor_Widget_Registry::SCHEMA_VERSION ), false );
        update_option( Design_Core_Elementor_Design_Token_Service::OPTION_KEY, $backup['tokens'] ?? array(), false );
        return true;
    }

    /** A backup captured before a registry was ever durably initialized can be a bare array(); never restore a shape mutate_items() would reject. */
    private function coerce_payload( $payload, $schema_version ) {
        return ( is_array( $payload ) && isset( $payload['schema_version'], $payload['items'] ) && is_array( $payload['items'] ) )
            ? $payload
            : array( 'schema_version' => $schema_version, 'items' => array() );
    }
}
