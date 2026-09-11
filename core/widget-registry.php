<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Widget_Registry extends Design_Core_Elementor_Versioned_Registry {
    const SCHEMA_VERSION = 2;
    public const OPTION_KEY = 'design_core_elementor_widgets';
    protected $type = 'widget';
    public function defaults() { return array(); }
    public function get( $id ) { $item = parent::get( $id ); if ( $item ) { return $item; } foreach ( $this->all() as $candidate ) { if ( ( $candidate['slug'] ?? '' ) === $id ) { return $candidate; } } return null; }
    protected function migrate_legacy( $items ) {
        $migrated = array();
        foreach ( $items as $legacy ) {
            if ( ! is_array( $legacy ) ) { continue; }
            $id = sanitize_key( $legacy['id'] ?? $legacy['slug'] ?? '' ); if ( ! $id ) { continue; }
            $schema = array_values( array_map( 'sanitize_key', $legacy['content_schema'] ?? array() ) );
            $migrated[] = array( 'id' => $id, 'slug' => $id, 'type' => 'widget', 'schema_version' => 2, 'item_version' => 1, 'created_at' => gmdate( 'c' ), 'updated_at' => gmdate( 'c' ), 'source' => array( 'kind' => 'legacy-migration', 'version' => $legacy['version'] ?? '' ), 'usage' => array( 'count' => 0, 'locations' => array() ), 'fingerprint' => array( 'version' => 2, 'semantic' => sanitize_key( $legacy['purpose'][0] ?? 'widget' ), 'structure' => 'runtime-widget', 'content_schema' => $schema, 'layout' => '', 'interaction' => '' ), 'definition' => is_array( $legacy['definition'] ?? null ) ? $legacy['definition'] : array(), 'label' => sanitize_text_field( $legacy['label'] ?? $id ) );
        }
        return $migrated;
    }
}
