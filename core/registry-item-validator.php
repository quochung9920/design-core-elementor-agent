<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Registry_Item_Validator {
    const SCHEMA_VERSION = 2;

    public function validate( $item ) {
        if ( ! is_array( $item ) ) { throw new InvalidArgumentException( 'RegistryItem must be an array.' ); }
        foreach ( array( 'id', 'type', 'schema_version', 'item_version', 'created_at', 'updated_at', 'source', 'usage', 'fingerprint' ) as $field ) {
            if ( ! array_key_exists( $field, $item ) ) { throw new InvalidArgumentException( 'RegistryItem field is missing: ' . $field ); }
        }
        if ( ! is_string( $item['id'] ) || '' === trim( $item['id'] ) ) { throw new InvalidArgumentException( 'RegistryItem ID is invalid.' ); }
        if ( ! is_string( $item['type'] ) || ! in_array( $item['type'], array( 'component', 'widget', 'section' ), true ) ) { throw new InvalidArgumentException( 'RegistryItem type is invalid.' ); }
        if ( self::SCHEMA_VERSION !== $item['schema_version'] || ! is_int( $item['item_version'] ) || $item['item_version'] < 1 ) { throw new InvalidArgumentException( 'RegistryItem version is invalid.' ); }
        if ( ! is_array( $item['source'] ) || ! is_array( $item['usage'] ) || ! is_array( $item['fingerprint'] ) ) { throw new InvalidArgumentException( 'RegistryItem source, usage, and fingerprint must be arrays.' ); }
        if ( ! array_key_exists( 'count', $item['usage'] ) || ! is_numeric( $item['usage']['count'] ) || ! isset( $item['usage']['locations'] ) || ! is_array( $item['usage']['locations'] ) ) { throw new InvalidArgumentException( 'RegistryItem usage is invalid.' ); }
        foreach ( $item['usage']['locations'] as $location ) {
            if ( 'section' === $item['type'] && is_array( $location ) ) {
                if ( ! isset( $location['page_id'] ) || ! is_numeric( $location['page_id'] ) ) { throw new InvalidArgumentException( 'Section RegistryItem usage location requires page_id.' ); }
                if ( isset( $location['position'] ) && ! is_numeric( $location['position'] ) ) { throw new InvalidArgumentException( 'Section RegistryItem usage position is invalid.' ); }
                if ( isset( $location['root_id'] ) && ! is_string( $location['root_id'] ) ) { throw new InvalidArgumentException( 'Section RegistryItem usage root_id is invalid.' ); }
                continue;
            }
            if ( ! is_string( $location ) && ! is_int( $location ) ) { throw new InvalidArgumentException( 'RegistryItem usage location is invalid.' ); }
        }
        if ( 'section' === $item['type'] ) { $this->validate_section_fingerprint( $item['fingerprint'] ); }
        else { $this->validate_component_fingerprint( $item['fingerprint'] ); }
        foreach ( array( 'created_at', 'updated_at' ) as $date_key ) {
            if ( ! is_string( $item[ $date_key ] ) || false === strtotime( $item[ $date_key ] ) ) { throw new InvalidArgumentException( 'RegistryItem timestamp is invalid.' ); }
        }
        return true;
    }

    private function validate_component_fingerprint( array $fingerprint ) {
        if ( Design_Core_Elementor_Fingerprint_Service::VERSION !== ( $fingerprint['version'] ?? null ) ) { throw new InvalidArgumentException( 'RegistryItem fingerprint v2 is required.' ); }
        foreach ( array( 'semantic', 'structure', 'content_schema', 'layout', 'interaction' ) as $field ) {
            if ( ! array_key_exists( $field, $fingerprint ) ) { throw new InvalidArgumentException( 'RegistryItem fingerprint field is missing: ' . $field ); }
        }
        foreach ( array( 'semantic', 'structure', 'layout', 'interaction' ) as $field ) {
            if ( ! is_string( $fingerprint[ $field ] ) ) { throw new InvalidArgumentException( 'RegistryItem fingerprint field is invalid: ' . $field ); }
        }
        if ( ! is_array( $fingerprint['content_schema'] ) ) { throw new InvalidArgumentException( 'RegistryItem fingerprint content_schema is invalid.' ); }
    }

    private function validate_section_fingerprint( array $fingerprint ) {
        if ( ! class_exists( 'Design_Core_Elementor_Section_Fingerprint_Service' ) || Design_Core_Elementor_Section_Fingerprint_Service::VERSION !== ( $fingerprint['version'] ?? null ) ) { throw new InvalidArgumentException( 'Section fingerprint v3 is required.' ); }
        foreach ( array( 'semantic', 'topology', 'slots', 'layout_topology', 'responsive_topology', 'media_topology', 'interaction_topology', 'family_hash', 'variant_hash' ) as $field ) {
            if ( ! array_key_exists( $field, $fingerprint ) ) { throw new InvalidArgumentException( 'Section fingerprint field is missing: ' . $field ); }
        }
        foreach ( array( 'semantic', 'topology', 'layout_topology', 'family_hash', 'variant_hash' ) as $field ) {
            if ( ! is_string( $fingerprint[ $field ] ) || '' === trim( $fingerprint[ $field ] ) ) { throw new InvalidArgumentException( 'Section fingerprint field is invalid: ' . $field ); }
        }
        foreach ( array( 'slots', 'responsive_topology', 'media_topology', 'interaction_topology' ) as $field ) {
            if ( ! is_array( $fingerprint[ $field ] ) ) { throw new InvalidArgumentException( 'Section fingerprint collection is invalid: ' . $field ); }
        }
        if ( isset( $fingerprint['detail_hash'] ) && ( ! is_string( $fingerprint['detail_hash'] ) || '' === trim( $fingerprint['detail_hash'] ) ) ) { throw new InvalidArgumentException( 'Section fingerprint detail_hash is invalid.' ); }
    }
}
