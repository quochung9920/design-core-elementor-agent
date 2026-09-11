<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Persistent, bounded, versioned storage for verified design lessons and
 * observed incidents. Nothing is learned merely because an agent proposed it.
 */
class Design_Core_Elementor_Design_Memory_Store {
    const VERSION = 1;
    const LESSONS_OPTION = 'design_core_elementor_design_memory_v1';
    const INCIDENTS_OPTION = 'design_core_elementor_design_incidents_v1';
    const MAX_LESSONS = 500;
    const MAX_INCIDENTS = 750;

    public function lessons() {
        return $this->read( self::LESSONS_OPTION, 'lessons' );
    }

    public function incidents() {
        return $this->read( self::INCIDENTS_OPTION, 'incidents' );
    }

    public function upsert_lesson( array $lesson ) {
        if ( empty( $lesson['verified'] ) || empty( $lesson['signature'] ) ) {
            return new WP_Error( 'design_core_memory_unverified_lesson', 'Only verified lessons with a stable signature may enter Design Memory.' );
        }
        $lesson = $this->sanitize_record( $lesson );
        $lesson['signature'] = $this->signature( $lesson['signature'] );
        $lesson['scope'] = $this->scope( $lesson['scope'] ?? 'global' );
        $lesson['source_type'] = sanitize_key( (string) ( $lesson['source_type'] ?? 'generic' ) );
        $lesson['updated_at'] = gmdate( 'c' );
        $lesson['created_at'] = (string) ( $lesson['created_at'] ?? $lesson['updated_at'] );
        $lesson['hits'] = max( 0, (int) ( $lesson['hits'] ?? 0 ) );
        $lesson['confidence'] = max( 0, min( 1, (float) ( $lesson['confidence'] ?? 0 ) ) );
        $lesson['id'] = (string) ( $lesson['id'] ?? $this->lesson_id( $lesson ) );

        $records = $this->lessons();
        $merged = false;
        foreach ( $records as $index => $existing ) {
            if ( (string) ( $existing['id'] ?? '' ) !== $lesson['id'] ) { continue; }
            $lesson['created_at'] = (string) ( $existing['created_at'] ?? $lesson['created_at'] );
            $lesson['hits'] = max( (int) ( $existing['hits'] ?? 0 ), $lesson['hits'] );
            $lesson['verified_runs'] = max( (int) ( $existing['verified_runs'] ?? 1 ), (int) ( $lesson['verified_runs'] ?? 1 ) );
            $records[ $index ] = array_replace_recursive( $existing, $lesson );
            $merged = true;
            break;
        }
        if ( ! $merged ) { $records[] = $lesson; }
        $this->write( self::LESSONS_OPTION, 'lessons', $this->cap( $records, self::MAX_LESSONS ) );
        return $lesson;
    }

    public function record_incident( array $incident ) {
        $incident = $this->sanitize_record( $incident );
        $incident['signature'] = $this->signature( $incident['signature'] ?? 'unknown' );
        $incident['status'] = sanitize_key( (string) ( $incident['status'] ?? 'observed' ) );
        $incident['created_at'] = (string) ( $incident['created_at'] ?? gmdate( 'c' ) );
        $incident['id'] = (string) ( $incident['id'] ?? 'incident_' . substr( hash( 'sha256', wp_json_encode( array( $incident['signature'], $incident['created_at'], $incident['page_id'] ?? 0, $incident['source_node_id'] ?? '' ) ) ), 0, 20 ) );
        $records = $this->incidents();
        $records[] = $incident;
        $this->write( self::INCIDENTS_OPTION, 'incidents', $this->cap( $records, self::MAX_INCIDENTS ) );
        return $incident;
    }

    public function increment_hit( $lesson_id ) {
        $lesson_id = sanitize_key( (string) $lesson_id );
        if ( '' === $lesson_id ) { return false; }
        $records = $this->lessons();
        $changed = false;
        foreach ( $records as $index => $lesson ) {
            if ( (string) ( $lesson['id'] ?? '' ) !== $lesson_id ) { continue; }
            $records[ $index ]['hits'] = (int) ( $lesson['hits'] ?? 0 ) + 1;
            $records[ $index ]['last_used_at'] = gmdate( 'c' );
            $changed = true;
            break;
        }
        if ( $changed ) { $this->write( self::LESSONS_OPTION, 'lessons', $records ); }
        return $changed;
    }

    public function clear( $kind = 'all' ) {
        $kind = sanitize_key( (string) $kind );
        if ( in_array( $kind, array( 'all', 'lessons' ), true ) ) { delete_option( self::LESSONS_OPTION ); }
        if ( in_array( $kind, array( 'all', 'incidents' ), true ) ) { delete_option( self::INCIDENTS_OPTION ); }
        return true;
    }

    private function read( $option, $key ) {
        $stored = get_option( $option, array() );
        if ( ! is_array( $stored ) || (int) ( $stored['version'] ?? 0 ) !== self::VERSION ) { return array(); }
        return array_values( array_filter( (array) ( $stored[ $key ] ?? array() ), 'is_array' ) );
    }

    private function write( $option, $key, array $records ) {
        update_option( $option, array( 'version' => self::VERSION, $key => array_values( $records ), 'updated_at' => gmdate( 'c' ) ), false );
    }

    private function cap( array $records, $limit ) {
        usort( $records, static function ( $a, $b ) {
            return strcmp( (string) ( $b['updated_at'] ?? $b['created_at'] ?? '' ), (string) ( $a['updated_at'] ?? $a['created_at'] ?? '' ) );
        } );
        return array_slice( $records, 0, max( 1, (int) $limit ) );
    }

    private function lesson_id( array $lesson ) {
        return 'lesson_' . substr( hash( 'sha256', implode( '|', array( (string) $lesson['signature'], (string) $lesson['scope'], (string) $lesson['source_type'] ) ) ), 0, 20 );
    }

    private function signature( $value ) {
        $parts = array_filter( array_map( 'sanitize_key', preg_split( '/[.\\/]+/', strtolower( trim( (string) $value ) ) ) ) );
        return $parts ? implode( '.', $parts ) : 'unknown';
    }

    private function scope( $value ) {
        $value = sanitize_key( (string) $value );
        return in_array( $value, array( 'global', 'project', 'source' ), true ) ? $value : 'global';
    }

    private function sanitize_record( array $record ) {
        if ( class_exists( 'Design_Core_Elementor_Change_Ledger' ) && method_exists( 'Design_Core_Elementor_Change_Ledger', 'transport_safe' ) ) {
            $safe = Design_Core_Elementor_Change_Ledger::transport_safe( $record );
            return is_array( $safe ) ? $safe : array();
        }
        return $this->bounded( $record );
    }

    private function bounded( $value, $depth = 0 ) {
        if ( $depth > 8 ) { return '[max-depth]'; }
        if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { return $value; }
        if ( is_string( $value ) ) { return mb_substr( $value, 0, 4000 ); }
        if ( ! is_array( $value ) ) { return null; }
        $out = array(); $count = 0;
        foreach ( $value as $key => $item ) {
            if ( ++$count > 250 ) { break; }
            $out[ is_string( $key ) ? sanitize_key( $key ) : $key ] = $this->bounded( $item, $depth + 1 );
        }
        return $out;
    }
}
