<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface Design_Core_Elementor_Adapter_Interface {
    public function target();
    public function supports( $capability );
    public function normalize( $ir );
    public function validate( $elements );
    public function save( $document_id, $elements, $context = array() );
    public function reload( $document_id );
    public function render( $document_id );
}
