<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** @deprecated Compatibility facade; use Design_Core_Elementor_Conversion_Service. */
class Design_Core_Elementor_HTML_Converter {
    private $service;
    public function __construct( $service = null ) { $this->service = $service ?: new Design_Core_Elementor_Conversion_Service(); }
    public function convert_to_elementor( $html, $css = '', $page_title = 'Imported Page', $base_url = '' ) { return $this->service->convert( $html, $css, $page_title, $base_url ); }
}
