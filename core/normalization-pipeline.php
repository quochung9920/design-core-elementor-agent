<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Normalization_Pipeline {
    public function normalize( $ir, $css = '' ) {
        $ir = ( new Design_Core_Elementor_Responsive_Normalizer() )->normalize( $ir );
        if ( class_exists( 'Design_Core_Elementor_Layout_Intelligence' ) ) { $ir = ( new Design_Core_Elementor_Layout_Intelligence() )->enrich( $ir ); }
        if ( class_exists( 'Design_Core_Elementor_Section_Intelligence' ) ) { $ir = ( new Design_Core_Elementor_Section_Intelligence() )->prepare( $ir ); }
        if ( '' !== trim( (string) $css ) && class_exists( 'Design_Core_Elementor_Responsive_Compiler' ) ) {
            list( $ir ) = ( new Design_Core_Elementor_Responsive_Compiler() )->compile( $ir, $css );
        }
        return $ir;
    }
}
