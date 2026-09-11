<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Breakpoint_Registry {
    private $defaults = array(
        'mobile' => 767,
        'tablet' => 1024,
    );

    public function all() {
        $points = array();
        if ( class_exists( '\Elementor\Plugin' ) ) {
            try {
                $manager = \Elementor\Plugin::instance()->breakpoints;
                if ( $manager && method_exists( $manager, 'get_active_breakpoints' ) ) {
                    foreach ( $manager->get_active_breakpoints() as $name => $breakpoint ) {
                        $value = method_exists( $breakpoint, 'get_value' ) ? $breakpoint->get_value() : null;
                        if ( is_numeric( $value ) ) {
                            $points[ sanitize_key( (string) $name ) ] = (int) $value;
                        }
                    }
                }
            } catch ( Throwable $e ) {
                $points = array();
            }
        }
        if ( empty( $points ) ) {
            $points = $this->defaults;
        }
        asort( $points, SORT_NUMERIC );
        return apply_filters( 'design_core_elementor_breakpoints', $points );
    }

    public function classify_max_width( $width ) {
        $width = (int) $width;
        $points = $this->all();
        if ( isset( $points['mobile'] ) && $width <= $points['mobile'] ) {
            return 'mobile';
        }
        if ( isset( $points['mobile_extra'] ) && $width <= $points['mobile_extra'] ) {
            return 'mobile_extra';
        }
        if ( isset( $points['tablet'] ) && $width <= $points['tablet'] ) {
            return 'tablet';
        }
        if ( isset( $points['tablet_extra'] ) && $width <= $points['tablet_extra'] ) {
            return 'tablet_extra';
        }
        if ( isset( $points['laptop'] ) && $width <= $points['laptop'] ) {
            return 'laptop';
        }

        $best_name = 'desktop';
        $best_delta = PHP_INT_MAX;
        foreach ( $points as $name => $value ) {
            $delta = abs( $value - $width );
            if ( $delta < $best_delta ) {
                $best_name = $name;
                $best_delta = $delta;
            }
        }
        return $best_name;
    }

    public function viewport_matrix() {
        $matrix = array( 'desktop' => 1440 );
        foreach ( $this->all() as $name => $value ) {
            $matrix[ $name ] = max( 320, (int) $value );
        }
        $matrix['mobile-small'] = 390;
        return array_unique( $matrix );
    }
}
