<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Canonical platform-neutral design direction returned by Design Intelligence. */
class Design_Core_Elementor_Design_System_Profile {
    const SCHEMA_VERSION = 1;

    public static function from_match( array $match, $brief, array $options = array(), array $source = array() ) {
        $profile = (array) ( $match['profile'] ?? array() );
        $defaults = (array) ( $profile['defaults'] ?? array() );
        $variance = self::dial( $options['variance'] ?? ( $defaults['variance'] ?? 5 ) );
        $motion = self::dial( $options['motion'] ?? ( $defaults['motion'] ?? 4 ) );
        $density = self::dial( $options['density'] ?? ( $defaults['density'] ?? 5 ) );
        $mode_candidate = strtolower( (string) ( $options['mode'] ?? 'light' ) );
        $mode = in_array( $mode_candidate, array( 'light', 'dark' ), true ) ? $mode_candidate : 'light';

        $colors = self::sanitize_colors( (array) ( $profile['colors'] ?? array() ) );
        if ( 'dark' === $mode ) { $colors = self::derive_dark_colors( $colors ); }
        $spacing = self::spacing_for_density( $density );
        $typography = array(
            'heading' => array(
                'font_family' => sanitize_text_field( (string) ( $profile['heading_font'] ?? 'Inter' ) ),
                'font_weight' => '700',
                'line_height' => '1.15',
            ),
            'body' => array(
                'font_family' => sanitize_text_field( (string) ( $profile['body_font'] ?? 'Inter' ) ),
                'font_weight' => '400',
                'line_height' => '1.5',
            ),
        );

        $rules = array_values( array_filter( array_map( static function ( $rule ) {
            if ( ! is_array( $rule ) ) { return null; }
            return array(
                'id' => sanitize_key( (string) ( $rule['id'] ?? '' ) ),
                'category' => sanitize_text_field( (string) ( $rule['category'] ?? '' ) ),
                'severity' => sanitize_key( (string) ( $rule['severity'] ?? '' ) ),
                'check' => sanitize_key( (string) ( $rule['check'] ?? '' ) ),
                'guidance' => sanitize_text_field( (string) ( $rule['guidance'] ?? '' ) ),
            );
        }, (array) ( $options['ux_rules'] ?? array() ) ) ) );

        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'type' => 'design-system-profile',
            'generated_at' => gmdate( 'c' ),
            'brief' => sanitize_textarea_field( (string) $brief ),
            'product' => array(
                'id' => sanitize_key( (string) ( $profile['id'] ?? '' ) ),
                'category' => sanitize_text_field( (string) ( $profile['category'] ?? '' ) ),
                'match_score' => (float) ( $match['score'] ?? 0 ),
                'confidence' => sanitize_key( (string) ( $match['confidence'] ?? 'low' ) ),
                'evidence' => array_values( array_map( 'sanitize_text_field', (array) ( $match['evidence'] ?? array() ) ) ),
            ),
            'direction' => array(
                'styles' => array_values( array_map( 'sanitize_text_field', (array) ( $profile['styles'] ?? array() ) ) ),
                'color_mood' => sanitize_text_field( (string) ( $profile['color_mood'] ?? '' ) ),
                'typography_mood' => sanitize_text_field( (string) ( $profile['typography_mood'] ?? '' ) ),
                'effects' => array_values( array_map( 'sanitize_text_field', (array) ( $profile['effects'] ?? array() ) ) ),
                'variance' => $variance,
                'motion' => $motion,
                'density' => $density,
                'mode' => $mode,
            ),
            'page_strategy' => array(
                'pattern' => sanitize_text_field( (string) ( $profile['pattern'] ?? '' ) ),
                'pattern_id' => sanitize_key( (string) ( $profile['pattern_id'] ?? '' ) ),
                'recommended_shell' => sanitize_key( (string) ( $profile['recommended_shell'] ?? '' ) ),
                'shell_fit' => sanitize_key( (string) ( $profile['shell_fit'] ?? '' ) ),
                'sections' => array_values( array_map( 'sanitize_key', (array) ( $profile['sections'] ?? array() ) ) ),
                'considerations' => array_values( array_map( 'sanitize_text_field', (array) ( $profile['considerations'] ?? array() ) ) ),
            ),
            'tokens' => array(
                'colors' => $colors,
                'typography' => $typography,
                'spacing' => $spacing,
                'radius' => self::radius_for_variance( $variance ),
            ),
            'ux' => array(
                'rules' => $rules,
                'anti_patterns' => array_values( array_map( 'sanitize_text_field', (array) ( $profile['anti_patterns'] ?? array() ) ) ),
            ),
            'provenance' => array(
                'provider' => 'ui-ux-pro-max-normalized',
                'source' => Design_Core_Elementor_Change_Ledger::transport_safe( $source ),
                'runtime_dependency' => 'none',
            ),
        );
    }

    public static function validate( array $profile ) {
        if ( self::SCHEMA_VERSION !== (int) ( $profile['schema_version'] ?? 0 ) ) { throw new InvalidArgumentException( 'Unsupported Design System Profile schema.' ); }
        if ( 'design-system-profile' !== ( $profile['type'] ?? '' ) ) { throw new InvalidArgumentException( 'Invalid Design System Profile type.' ); }
        if ( empty( $profile['product']['id'] ) ) { throw new InvalidArgumentException( 'Design System Profile product id is required.' ); }
        if ( empty( $profile['tokens']['colors'] ) || empty( $profile['tokens']['typography'] ) ) { throw new InvalidArgumentException( 'Design System Profile tokens are incomplete.' ); }
        return true;
    }

    private static function dial( $value ) { return max( 1, min( 10, (int) $value ) ); }

    private static function sanitize_colors( array $colors ) {
        $out = array();
        foreach ( $colors as $key => $value ) {
            $color = sanitize_hex_color( (string) $value );
            if ( $color ) { $out[ sanitize_key( (string) $key ) ] = strtoupper( $color ); }
        }
        return $out;
    }

    private static function derive_dark_colors( array $colors ) {
        $colors['background'] = '#0F172A';
        $colors['foreground'] = '#F8FAFC';
        $colors['card'] = '#111827';
        $colors['card_foreground'] = '#F8FAFC';
        $colors['muted'] = '#1E293B';
        $colors['muted_foreground'] = '#CBD5E1';
        $colors['border'] = '#334155';
        if ( empty( $colors['ring'] ) ) { $colors['ring'] = $colors['primary'] ?? '#60A5FA'; }
        return $colors;
    }

    private static function spacing_for_density( $density ) {
        if ( $density <= 3 ) { return array( 'xs'=>4, 'sm'=>8, 'md'=>24, 'lg'=>32, 'xl'=>48, '2xl'=>64, '3xl'=>96 ); }
        if ( $density >= 8 ) { return array( 'xs'=>2, 'sm'=>4, 'md'=>8, 'lg'=>12, 'xl'=>16, '2xl'=>24, '3xl'=>32 ); }
        return array( 'xs'=>4, 'sm'=>8, 'md'=>16, 'lg'=>24, 'xl'=>32, '2xl'=>48, '3xl'=>64 );
    }

    private static function radius_for_variance( $variance ) {
        if ( $variance >= 8 ) { return array( 'sm'=>2, 'md'=>6, 'lg'=>12 ); }
        if ( $variance <= 3 ) { return array( 'sm'=>4, 'md'=>8, 'lg'=>16 ); }
        return array( 'sm'=>4, 'md'=>10, 'lg'=>20 );
    }
}
