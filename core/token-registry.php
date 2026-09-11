<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Token_Registry {
    private $service;
    public function __construct() { $this->service = new Design_Core_Elementor_Design_Token_Service(); }
    public function ensure_defaults() { $this->service->all(); }
    public function all() { return $this->service->all(); }
    public function add( $token ) {
        $tokens = $this->service->all();
        $type = sanitize_key( $token['type'] ?? '' );
        $key = sanitize_key( $token['key'] ?? '' );
        $value = $token['value'] ?? null;
        if ( 'color' === $type ) { $tokens['colors'][ $key ] = $value; }
        elseif ( 'spacing' === $type && is_numeric( $value ) ) { $tokens['spacing'][ $key ] = $value; }
        elseif ( 'typography' === $type ) { $tokens['typography'][ $key ] = $value; }
        return $this->service->save( $tokens );
    }
}
