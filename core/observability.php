<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Observability {
    const OPTION_KEY = 'design_core_elementor_conversion_logs';
    private $id;
    private $events = array();

    public function __construct( $id = '' ) {
        $this->id = $id ? sanitize_key( $id ) : 'dc-' . wp_generate_uuid4();
    }

    public function id() { return $this->id; }

    public function event( $stage, $status, $context = array() ) {
        $this->events[] = array(
            'time' => gmdate( 'c' ),
            'stage' => sanitize_key( $stage ),
            'status' => sanitize_key( $status ),
            'context' => $this->sanitize_context( $context ),
        );
        return $this;
    }

    public function persist() {
        $logs = get_option( self::OPTION_KEY, array() );
        $logs[ $this->id ] = array( 'id' => $this->id, 'events' => $this->events, 'updated_at' => gmdate( 'c' ) );
        if ( count( $logs ) > 50 ) { $logs = array_slice( $logs, -50, null, true ); }
        update_option( self::OPTION_KEY, $logs, false );
        return $logs[ $this->id ];
    }

    public static function recent() {
        return array_reverse( get_option( self::OPTION_KEY, array() ), true );
    }

    private function sanitize_context( $value ) {
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $key => $item ) { $out[ sanitize_key( (string) $key ) ] = $this->sanitize_context( $item ); }
            return $out;
        }
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) { return $value; }
        return sanitize_text_field( (string) $value );
    }
}
