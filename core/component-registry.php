<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Component_Registry extends Design_Core_Elementor_Versioned_Registry {
    const SCHEMA_VERSION = 2;
    public const OPTION_KEY = 'design_core_elementor_components';
    protected $type = 'component';

    public function defaults() { return array(); }
    public function exists( $id ) { return null !== $this->get( $id ); }

    public function validate( $items ) {
        parent::validate( $items );
        foreach ( $items as $item ) {
            if ( isset( $item['variants'] ) ) {
                if ( ! is_array( $item['variants'] ) ) { throw new InvalidArgumentException( 'Component variants must be an array.' ); }
                foreach ( $item['variants'] as $variant_id => $definition ) {
                    if ( '' === sanitize_key( (string) $variant_id ) || ! is_array( $definition ) ) { throw new InvalidArgumentException( 'Component variant definition is invalid.' ); }
                    if ( isset( $definition['presentation_hash'] ) && ( ! is_string( $definition['presentation_hash'] ) || '' === trim( $definition['presentation_hash'] ) ) ) { throw new InvalidArgumentException( 'Component variant presentation_hash is invalid.' ); }
                    if ( isset( $definition['blueprint'] ) && ! is_array( $definition['blueprint'] ) ) { throw new InvalidArgumentException( 'Component variant blueprint is invalid.' ); }
                    // Variant definitions predate the current fingerprint shape. Keep them readable and
                    // only require that a supplied fingerprint remains an array; new writes still use v2.
                    if ( isset( $definition['fingerprint'] ) && ! is_array( $definition['fingerprint'] ) ) { throw new InvalidArgumentException( 'Component variant fingerprint is invalid.' ); }
                }
            }
            if ( isset( $item['instances'] ) ) {
                if ( ! is_array( $item['instances'] ) ) { throw new InvalidArgumentException( 'Component instances must be an array.' ); }
                foreach ( $item['instances'] as $instance_id => $instance ) {
                    if ( ! is_array( $instance ) || '' === trim( (string) $instance_id ) ) { throw new InvalidArgumentException( 'Component instance is invalid.' ); }
                    if ( (string) ( $instance['master_id'] ?? '' ) !== (string) $item['id'] ) { throw new InvalidArgumentException( 'Component instance master_id mismatch.' ); }
                    foreach ( array( 'content_bindings', 'asset_bindings', 'allowed_overrides' ) as $field ) {
                        if ( isset( $instance[ $field ] ) && ! is_array( $instance[ $field ] ) ) { throw new InvalidArgumentException( 'Component instance ' . $field . ' is invalid.' ); }
                    }
                    if ( isset( $instance['location'] ) && ! is_int( $instance['location'] ) && ! is_string( $instance['location'] ) ) { throw new InvalidArgumentException( 'Component instance location is invalid.' ); }
                }
            }
        }
        return true;
    }

    public function register_instance( $master_id, $content_bindings, $location, $asset_bindings = array(), $allowed_overrides = array() ) {
        $content_bindings = is_array( $content_bindings ) ? $content_bindings : array();
        $asset_bindings = is_array( $asset_bindings ) ? $asset_bindings : array();
        $allowed_overrides = is_array( $allowed_overrides ) ? $allowed_overrides : array();
        $identity = wp_json_encode( array( 'content' => $content_bindings, 'assets' => $asset_bindings, 'overrides' => $allowed_overrides, 'location' => $location ) );
        $instance = array(
            'id' => 'instance-' . substr( md5( $master_id . '|' . $identity ), 0, 12 ),
            'master_id' => $master_id,
            'content_bindings' => $content_bindings,
            'asset_bindings' => $asset_bindings,
            'links' => array(), 'dynamic_fields' => array(),
            'allowed_overrides' => $allowed_overrides,
            'location' => $location,
        );
        try {
            $this->mutate_item( $master_id, static function ( $master ) use ( $instance, $location ) {
                if ( ! isset( $master['instances'] ) || ! is_array( $master['instances'] ) ) { $master['instances'] = array(); }
                $master['instances'][ $instance['id'] ] = $instance;
                if ( ! isset( $master['usage']['locations'] ) || ! is_array( $master['usage']['locations'] ) ) { $master['usage']['locations'] = array(); }
                $master['usage']['locations'][] = $location;
                $master['usage']['locations'] = array_values( array_unique( $master['usage']['locations'], SORT_REGULAR ) );
                $master['usage']['count'] = count( $master['usage']['locations'] );
                $master['updated_at'] = gmdate( 'c' ); $master['item_version']++;
                return $master;
            } );
            return $instance;
        } catch ( Throwable $exception ) {
            $code = 'design_core_registry_import_locked' === $exception->getMessage() ? 'design_core_registry_import_locked' : ( in_array( $exception->getMessage(), array( 'design_core_registry_locked', 'design_core_registry_lock_lost', 'design_core_registry_import_lock_lost' ), true ) ? 'design_core_registry_locked' : 'design_core_component_write_failed' );
            return new WP_Error( $code, $exception->getMessage() );
        }
    }

    public function add_variant( $id, $variant, $definition = array() ) {
        $variant = sanitize_key( $variant );
        if ( '' === $variant ) { return false; }
        try {
            $this->mutate_item( $id, static function ( $item ) use ( $variant, $definition ) {
                if ( ! isset( $item['variants'] ) || ! is_array( $item['variants'] ) ) { $item['variants'] = array(); }
                $item['variants'][ $variant ] = is_array( $definition ) ? $definition : array();
                $item['updated_at'] = gmdate( 'c' ); $item['item_version']++;
                return $item;
            } );
            return true;
        } catch ( Throwable $exception ) { return false; }
    }

    protected function migrate_legacy( $items ) {
        $migrated = array();
        foreach ( $items as $legacy ) {
            if ( ! is_array( $legacy ) ) { continue; }
            $id = sanitize_key( $legacy['id'] ?? '' ); if ( ! $id ) { continue; }
            $schema = array_values( array_map( 'sanitize_key', $legacy['editable'] ?? $legacy['content_schema'] ?? array() ) );
            $fingerprint = array( 'version' => 2, 'semantic' => sanitize_key( $legacy['fingerprint']['semantic'] ?? $legacy['purpose'][0] ?? 'component' ), 'structure' => sanitize_text_field( $legacy['fingerprint']['signature'] ?? '' ), 'content_schema' => $schema, 'layout' => sanitize_text_field( $legacy['fingerprint']['layout'] ?? '' ), 'interaction' => '' );
            $locations = array_values( array_map( 'intval', $legacy['usage'] ?? array() ) );
            $migrated[] = array(
                'id' => $id, 'type' => 'component', 'schema_version' => 2, 'item_version' => 1, 'created_at' => gmdate( 'c' ), 'updated_at' => gmdate( 'c' ),
                'source' => array( 'kind' => 'legacy-migration', 'version' => $legacy['version'] ?? '' ),
                'usage' => array( 'count' => count( $locations ), 'locations' => $locations ), 'fingerprint' => $fingerprint,
                'master' => array( 'structure' => $legacy['elementor_elements'] ?? array(), 'layout' => array(), 'shared_styles' => $legacy['shared_style'] ?? array(), 'responsive_rules' => array(), 'editable_schema' => $schema ),
                'instances' => array(), 'variants' => $legacy['variant_definitions'] ?? array(), 'name' => sanitize_text_field( $legacy['name'] ?? $id ),
            );
        }
        return $migrated;
    }
}
