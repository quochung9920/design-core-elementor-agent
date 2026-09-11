<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Common versioned, validated, recoverable option registry. */
abstract class Design_Core_Elementor_Versioned_Registry {
    const SCHEMA_VERSION = 2;
    const LOCK_TTL = 30;
    public const OPTION_KEY = '';
    protected $type = '';

    public function all() { return $this->load()['items']; }
    public function payload() { return $this->load(); }
    public function get( $id ) { foreach ( $this->all() as $item ) { if ( $item['id'] === $id ) { return $item; } } return null; }
    public function search( $query ) { $query = strtolower( trim( (string) $query ) ); return array_values( array_filter( $this->all(), static function ( $item ) use ( $query ) { return '' === $query || false !== strpos( strtolower( wp_json_encode( $item ) ), $query ); } ) ); }
    public function find_similar( $candidate ) {
        $fingerprint = is_array( $candidate['fingerprint'] ?? null ) ? $candidate['fingerprint'] : array();
        return array_values( array_filter( $this->all(), static function ( $item ) use ( $fingerprint ) { return ( $item['fingerprint']['semantic'] ?? '' ) === ( $fingerprint['semantic'] ?? '' ) && ( $item['fingerprint']['structure'] ?? '' ) === ( $fingerprint['structure'] ?? '' ); } ) );
    }
    public function upsert( $item ) {
        try {
            ( new Design_Core_Elementor_Registry_Item_Validator() )->validate( $item );
            if ( $this->type !== $item['type'] ) { throw new InvalidArgumentException( 'Registry item type does not match registry.' ); }
            $this->mutate_items( static function ( $items ) use ( $item ) {
                $found = false;
                foreach ( $items as $index => $existing ) {
                    if ( $existing['id'] === $item['id'] ) { $item['item_version'] = max( $existing['item_version'] + 1, $item['item_version'] ); $item['created_at'] = $existing['created_at']; $items[ $index ] = $item; $found = true; break; }
                }
                if ( ! $found ) { $items[] = $item; }
                return $items;
            } );
            return $this->get( $item['id'] );
        } catch ( Throwable $exception ) { return new WP_Error( $this->error_code( $exception->getMessage() ), $exception->getMessage() ); }
    }
    public function add( $item ) { return $this->upsert( $item ); }
    public function update( $id, $changes ) {
        try { $updated = null; $this->mutate_item( $id, static function ( $item ) use ( $changes, &$updated ) { $updated = array_replace_recursive( $item, is_array( $changes ) ? $changes : array() ); return $updated; } ); return $updated; }
        catch ( Throwable $exception ) { return new WP_Error( $this->error_code( $exception->getMessage() ), $exception->getMessage() ); }
    }
    public function delete( $id ) { try { $this->mutate_items( static function ( $items ) use ( $id ) { return array_values( array_filter( $items, static function ( $item ) use ( $id ) { return $item['id'] !== $id; } ) ); } ); return true; } catch ( Throwable $exception ) { return false; } }
    public function register_usage( $id, $location ) {
        try { $this->mutate_item( $id, static function ( $item ) use ( $location ) { $locations = $item['usage']['locations']; $locations[] = is_int( $location ) ? $location : sanitize_text_field( $location ); $item['usage'] = array( 'count' => count( array_unique( $locations, SORT_REGULAR ) ), 'locations' => array_values( array_unique( $locations, SORT_REGULAR ) ) ); $item['updated_at'] = gmdate( 'c' ); return $item; } ); return true; }
        catch ( Throwable $exception ) { return false; }
    }
    public function validate( $items ) { if ( ! is_array( $items ) ) { throw new InvalidArgumentException( 'Registry items must be an array.' ); } $ids = array(); foreach ( $items as $item ) { ( new Design_Core_Elementor_Registry_Item_Validator() )->validate( $item ); if ( isset( $ids[ $item['id'] ] ) ) { throw new InvalidArgumentException( 'Registry item IDs must be unique.' ); } $ids[ $item['id'] ] = true; if ( $item['type'] !== $this->type ) { throw new InvalidArgumentException( 'Registry item type mismatch.' ); } } return true; }
    public function replace_all( $items ) { $this->validate( $items ); $token = $this->acquire_lock(); try { return $this->write_items( $items, $token ); } finally { $this->release_lock( $token ); } }
    public function backup() { $backup = $this->load(); update_option( static::OPTION_KEY . '_backup', $backup, false ); return $backup; }
    public function rollback() { $backup = get_option( static::OPTION_KEY . '_backup', null ); if ( ! is_array( $backup ) ) { return false; } $this->replace_all( $backup['items'] ?? array() ); return true; }
    public function export() { return $this->load(); }
    public function import( $payload ) { if ( ! is_array( $payload ) || self::SCHEMA_VERSION !== ( $payload['schema_version'] ?? null ) ) { throw new InvalidArgumentException( 'Registry payload schema is invalid.' ); } return $this->replace_all( $payload['items'] ?? null ); }
    public function ensure_defaults() { $this->load(); }

    protected function mutate_item( $id, $callback ) {
        $found = false;
        $this->mutate_items( function ( $items ) use ( $id, $callback, &$found ) { foreach ( $items as $index => $item ) { if ( $item['id'] === $id ) { $items[ $index ] = call_user_func( $callback, $item ); $found = true; break; } } if ( ! $found ) { throw new RuntimeException( 'Registry item not found.' ); } return $items; } );
        return true;
    }
    protected function mutate_items( $callback ) {
        $this->load();
        $token = $this->acquire_lock();
        try {
            $payload = get_option( static::OPTION_KEY, array( 'schema_version' => self::SCHEMA_VERSION, 'items' => array() ) );
            if ( ! is_array( $payload ) || self::SCHEMA_VERSION !== ( $payload['schema_version'] ?? null ) || ! is_array( $payload['items'] ?? null ) ) { throw new RuntimeException( 'Registry changed during mutation.' ); }
            $items = call_user_func( $callback, $payload['items'] );
            return $this->write_items( $items, $token );
        } finally { $this->release_lock( $token ); }
    }
    protected function load() {
        $stored = get_option( static::OPTION_KEY, array() );
        if ( isset( $stored['schema_version'], $stored['items'] ) ) { $this->validate( $stored['items'] ); return $stored; }
        $items = $this->migrate_legacy( is_array( $stored ) ? $stored : array() );
        if ( empty( $items ) ) {
            // Persist the schema-shaped default so a missing/malformed option is durably initialized once, not just returned in-memory.
            $default = array( 'schema_version' => self::SCHEMA_VERSION, 'items' => array() );
            update_option( static::OPTION_KEY, $default, false );
            return $default;
        }
        return $this->replace_all( $items );
    }
    protected function migrate_legacy( $items ) { return array(); }
    private function write_items( $items, $token ) { $this->validate( $items ); $this->assert_import_access(); $lock = get_option( static::OPTION_KEY . '_lock', null ); if ( ! is_array( $lock ) || ! hash_equals( (string) ( $lock['token'] ?? '' ), (string) $token ) || (int) ( $lock['expires_at'] ?? 0 ) < time() ) { throw new RuntimeException( 'design_core_registry_lock_lost' ); } $payload = array( 'schema_version' => self::SCHEMA_VERSION, 'items' => array_values( $items ) ); update_option( static::OPTION_KEY, $payload, false ); if ( get_option( static::OPTION_KEY, null ) !== $payload ) { throw new RuntimeException( 'Registry persistence verification failed.' ); } return $payload; }
    private function acquire_lock() {
        $this->assert_import_access();
        $key = static::OPTION_KEY . '_lock'; $existing = get_option( $key, null );
        if ( is_array( $existing ) && (int) ( $existing['expires_at'] ?? 0 ) < time() ) { delete_option( $key ); }
        $token = wp_generate_uuid4();
        if ( ! add_option( $key, array( 'token' => $token, 'expires_at' => time() + self::LOCK_TTL ), '', false ) ) { throw new RuntimeException( 'design_core_registry_locked' ); }
        return $token;
    }
    private function release_lock( $token ) { $key = static::OPTION_KEY . '_lock'; $lock = get_option( $key, null ); if ( is_array( $lock ) && hash_equals( (string) ( $lock['token'] ?? '' ), (string) $token ) ) { delete_option( $key ); } }
    private function assert_import_access() { $owner = $GLOBALS['design_core_elementor_registry_import_token'] ?? ''; $lock = get_option( 'design_core_elementor_registry_import_lock', null ); if ( ! is_array( $lock ) ) { if ( $owner ) { throw new RuntimeException( 'design_core_registry_import_lock_lost' ); } return; } if ( (int) ( $lock['expires_at'] ?? 0 ) < time() ) { delete_option( 'design_core_elementor_registry_import_lock' ); if ( $owner ) { throw new RuntimeException( 'design_core_registry_import_lock_lost' ); } return; } if ( $owner ) { if ( ! hash_equals( (string) ( $lock['token'] ?? '' ), (string) $owner ) ) { throw new RuntimeException( 'design_core_registry_import_lock_lost' ); } return; } throw new RuntimeException( 'design_core_registry_import_locked' ); }
    private function error_code( $message ) { if ( 'design_core_registry_import_locked' === $message ) { return 'design_core_registry_import_locked'; } if ( in_array( $message, array( 'design_core_registry_locked', 'design_core_registry_lock_lost', 'design_core_registry_import_lock_lost' ), true ) ) { return 'design_core_registry_locked'; } return 'design_core_registry_write_failed'; }
}
