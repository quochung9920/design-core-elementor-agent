<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Detects Elementor payloads whose apparent native structure is CSS-driven elsewhere. */
class Design_Core_Elementor_Architecture_Auditor {
    public function audit( $elements ) {
        $flat = $this->flatten( is_array( $elements ) ? $elements : array() );
        $stylesheet_html = 0; $important = 0; $class_only = 0; $custom_css = 0; $native_style_settings = 0;
        foreach ( $flat as $element ) {
            $settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
            if ( 'html' === ( $element['widgetType'] ?? '' ) ) {
                $html = (string) ( $settings['html'] ?? '' );
                if ( preg_match( '/<style\b/i', $html ) ) { $stylesheet_html++; }
                $important += substr_count( strtolower( $html ), '!important' );
            }
            if ( ! empty( $settings['custom_css'] ) ) {
                $custom_css++;
                $important += substr_count( strtolower( (string) $settings['custom_css'] ), '!important' );
            }
            if ( 'container' === ( $element['elType'] ?? '' ) ) {
                $non_structural = array_diff( array_keys( $settings ), array( '_css_classes', 'css_classes', 'content_width', 'container_type' ) );
                if ( empty( $non_structural ) && ( ! empty( $settings['_css_classes'] ) || ! empty( $settings['css_classes'] ) ) ) { $class_only++; }
            }
            foreach ( array_keys( $settings ) as $key ) {
                if ( preg_match( '/(?:color|background|typography|font_|padding|margin|gap|width|height|border|radius|shadow|align|justify|direction|object-fit)/', $key ) ) { $native_style_settings++; }
            }
        }
        return array(
            'status' => $stylesheet_html ? 'fail' : ( $class_only ? 'warning' : 'pass' ),
            'stylesheet_html_widget_count' => $stylesheet_html,
            'important_declaration_count' => $important,
            'class_only_container_count' => $class_only,
            'custom_css_element_count' => $custom_css,
            'native_style_setting_count' => $native_style_settings,
        );
    }

    private function flatten( $elements ) {
        $flat = array();
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) { continue; }
            $flat[] = $element;
            if ( ! empty( $element['elements'] ) ) { $flat = array_merge( $flat, $this->flatten( $element['elements'] ) ); }
        }
        return $flat;
    }
}
