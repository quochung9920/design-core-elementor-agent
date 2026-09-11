<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Registry_Exporter {
    const IMPORT_LOCK = 'design_core_elementor_registry_import_lock';
    const SCHEMA_VERSION = 4;

    public function export() {
        return array(
            'format' => 'design-core-registry', 'schema_version' => self::SCHEMA_VERSION, 'plugin_version' => DESIGN_CORE_ELEMENTOR_VERSION,
            'components' => ( new Design_Core_Elementor_Component_Registry() )->export(),
            'sections' => class_exists( 'Design_Core_Elementor_Section_Registry' ) ? ( new Design_Core_Elementor_Section_Registry() )->export() : array( 'schema_version' => 2, 'items' => array() ),
            'widgets' => ( new Design_Core_Elementor_Widget_Registry() )->export(),
            'tokens' => ( new Design_Core_Elementor_Design_Token_Service() )->all(), 'exported_at' => gmdate( 'c' ),
        );
    }

    public function import( $payload ) {
        $components = new Design_Core_Elementor_Component_Registry(); $sections = new Design_Core_Elementor_Section_Registry(); $widgets = new Design_Core_Elementor_Widget_Registry(); $tokens = new Design_Core_Elementor_Design_Token_Service();
        try {
            $schema = (int) ( $payload['schema_version'] ?? 0 );
            if ( ! is_array( $payload ) || 'design-core-registry' !== ( $payload['format'] ?? null ) || ! in_array( $schema, array( 3, 4 ), true ) ) { throw new InvalidArgumentException( 'Registry payload is invalid.' ); }
            if ( ! is_array( $payload['components'] ?? null ) || ! is_array( $payload['widgets'] ?? null ) || ! is_array( $payload['tokens'] ?? null ) ) { throw new InvalidArgumentException( 'Registry payload sections must be arrays.' ); }
            $section_payload = 4 === $schema ? ( $payload['sections'] ?? null ) : null;
            if ( 4 === $schema && ! is_array( $section_payload ) ) { throw new InvalidArgumentException( 'Section registry payload must be an array.' ); }
            if ( 2 !== ( $payload['components']['schema_version'] ?? null ) || 2 !== ( $payload['widgets']['schema_version'] ?? null ) ) { throw new InvalidArgumentException( 'Registry section schema is unsupported.' ); }
            if ( is_array( $section_payload ) && 2 !== ( $section_payload['schema_version'] ?? null ) ) { throw new InvalidArgumentException( 'Section registry schema is unsupported.' ); }
            $components->validate( $payload['components']['items'] ?? null );
            if ( is_array( $section_payload ) ) { $sections->validate( $section_payload['items'] ?? null ); }
            $widgets->validate( $payload['widgets']['items'] ?? null ); $tokens->normalize( $payload['tokens'] );
        } catch ( Throwable $exception ) { return array( 'status' => 'invalid-payload', 'reason' => $exception->getMessage() ); }

        $existing_lock = get_option( self::IMPORT_LOCK, null );
        if ( is_array( $existing_lock ) && (int) ( $existing_lock['expires_at'] ?? 0 ) < time() ) { delete_option( self::IMPORT_LOCK ); }
        $lock_token = wp_generate_uuid4();
        if ( ! add_option( self::IMPORT_LOCK, array( 'token' => $lock_token, 'expires_at' => time() + 60 ), '', false ) ) { return array( 'status' => 'busy', 'reason' => 'registry-import-locked' ); }
        $GLOBALS['design_core_elementor_registry_import_token'] = $lock_token;

        try {
            $snapshots = array( 'components' => $components->payload(), 'sections' => $sections->payload(), 'widgets' => $widgets->payload(), 'tokens' => $tokens->all() );
            try {
                $components->replace_all( $payload['components']['items'] );
                if ( is_array( $section_payload ) ) { $sections->replace_all( $section_payload['items'] ); }
                $widgets->replace_all( $payload['widgets']['items'] );
                $saved_tokens = $tokens->save( $payload['tokens'] );
                if ( $tokens->all() !== $saved_tokens ) { throw new RuntimeException( 'Token persistence verification failed.' ); }
                return array( 'status' => 'imported', 'count' => array(
                    'components' => count( $payload['components']['items'] ),
                    'sections' => is_array( $section_payload ) ? count( $section_payload['items'] ) : count( $snapshots['sections']['items'] ),
                    'widgets' => count( $payload['widgets']['items'] ), 'tokens' => count( $saved_tokens ),
                ), 'sections_preserved' => ! is_array( $section_payload ) );
            } catch ( Throwable $exception ) {
                try { $components->replace_all( $snapshots['components']['items'] ); $sections->replace_all( $snapshots['sections']['items'] ); $widgets->replace_all( $snapshots['widgets']['items'] ); $tokens->save( $snapshots['tokens'] ); }
                catch ( Throwable $rollback_exception ) { return array( 'status' => 'rollback-failed', 'reason' => $exception->getMessage(), 'rollback_reason' => $rollback_exception->getMessage() ); }
                return array( 'status' => 'rolled-back', 'reason' => $exception->getMessage() );
            }
        } catch ( Throwable $state_exception ) { return array( 'status' => 'invalid-state', 'reason' => $state_exception->getMessage() ); }
        finally {
            $lock = get_option( self::IMPORT_LOCK, null );
            if ( is_array( $lock ) && hash_equals( (string) ( $lock['token'] ?? '' ), $lock_token ) ) { delete_option( self::IMPORT_LOCK ); }
            if ( ( $GLOBALS['design_core_elementor_registry_import_token'] ?? '' ) === $lock_token ) { unset( $GLOBALS['design_core_elementor_registry_import_token'] ); }
        }
    }
}
