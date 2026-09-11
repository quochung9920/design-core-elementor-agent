<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Canonical reusable section recipes replacing page-specific Elementor scripts. */
class Design_Core_Elementor_Section_Recipe_Library {
    const VERSION = 1;

    public function all() { return self::definitions(); }

    public function get( $family ) {
        $family = sanitize_key( (string) $family );
        $all = self::definitions();
        return $all[ $family ] ?? null;
    }

    public function compile( $family, array $bindings = array(), $root_id = '' ) {
        $recipe = $this->get( $family );
        if ( ! is_array( $recipe ) ) { return new WP_Error( 'design_core_recipe_not_found', 'Unknown section recipe: ' . sanitize_key( $family ) ); }
        foreach ( (array) ( $recipe['slots'] ?? array() ) as $slot_name => $slot ) {
            if ( ! is_array( $slot ) || empty( $slot['required'] ) || array_key_exists( 'default', $slot ) ) { continue; }
            if ( ! array_key_exists( $slot_name, $bindings ) || null === $bindings[ $slot_name ] || '' === $bindings[ $slot_name ] || array() === $bindings[ $slot_name ] ) {
                return new WP_Error( 'design_core_recipe_binding_missing', 'Required binding is missing for ' . sanitize_key( $family ) . '.' . sanitize_key( $slot_name ) . '.' );
            }
        }
        try { return ( new Design_Core_Elementor_Section_Recipe_Compiler() )->compile( $recipe, $bindings, $root_id ); }
        catch ( Throwable $exception ) { return new WP_Error( 'design_core_recipe_compile_failed', $exception->getMessage() ); }
    }

    public static function definitions() {
        return array(
            'hero-split' => array(
                'family' => 'hero', 'tag' => 'section', 'classes' => array( 'dc-section-hero', 'dc-layout-split' ),
                'layout' => array( 'display' => 'flex', 'direction' => 'row', 'gap' => array( 'value' => 40, 'unit' => 'px' ), 'max_width' => array( 'value' => 1280, 'unit' => 'px' ) ),
                'responsive' => array( 'mobile' => array( 'layout' => array( 'direction' => 'column', 'gap' => array( 'value' => 24, 'unit' => 'px' ) ) ) ),
                'slots' => array(
                    'eyebrow' => array( 'type' => 'text' ),
                    'heading' => array( 'type' => 'heading', 'tag' => 'h1', 'required' => true ),
                    'description' => array( 'type' => 'rich_text' ),
                    'primary_cta' => array( 'type' => 'link' ),
                    'secondary_cta' => array( 'type' => 'link' ),
                    'media' => array( 'type' => 'media' ),
                ),
            ),
            'intro' => array(
                'family' => 'intro', 'tag' => 'section', 'classes' => array( 'dc-section-intro' ),
                'layout' => array( 'display' => 'flex', 'direction' => 'column', 'gap' => array( 'value' => 20, 'unit' => 'px' ), 'max_width' => array( 'value' => 960, 'unit' => 'px' ) ),
                'slots' => array( 'eyebrow' => array( 'type' => 'text' ), 'heading' => array( 'type' => 'heading', 'required' => true ), 'description' => array( 'type' => 'rich_text' ) ),
            ),
            'benefits-grid' => array(
                'family' => 'benefits', 'tag' => 'section', 'classes' => array( 'dc-section-benefits' ),
                'layout' => array( 'display' => 'grid', 'columns' => 3, 'gap' => array( 'value' => 24, 'unit' => 'px' ), 'max_width' => array( 'value' => 1280, 'unit' => 'px' ) ),
                'responsive' => array( 'tablet' => array( 'layout' => array( 'columns' => 2 ) ), 'mobile' => array( 'layout' => array( 'columns' => 1 ) ) ),
                'slots' => array( 'heading' => array( 'type' => 'heading' ), 'description' => array( 'type' => 'rich_text' ), 'items' => array( 'type' => 'list', 'required' => true ) ),
            ),
            'comparison' => array(
                'family' => 'comparison', 'tag' => 'section', 'classes' => array( 'dc-section-comparison' ),
                'layout' => array( 'display' => 'flex', 'direction' => 'column', 'gap' => array( 'value' => 28, 'unit' => 'px' ), 'max_width' => array( 'value' => 1280, 'unit' => 'px' ) ),
                'slots' => array( 'heading' => array( 'type' => 'heading', 'required' => true ), 'description' => array( 'type' => 'rich_text' ), 'rows' => array( 'type' => 'list', 'required' => true ), 'primary_cta' => array( 'type' => 'link' ) ),
            ),
            'calculator' => array(
                'family' => 'pricing-calculator', 'tag' => 'section', 'classes' => array( 'dc-section-calculator' ),
                'layout' => array( 'display' => 'flex', 'direction' => 'column', 'gap' => array( 'value' => 24, 'unit' => 'px' ), 'max_width' => array( 'value' => 1120, 'unit' => 'px' ) ),
                'interaction' => array( 'behavior' => array( 'pattern' => 'calculator', 'confidence' => 1.0 ) ),
                'slots' => array( 'heading' => array( 'type' => 'heading', 'required' => true ), 'description' => array( 'type' => 'rich_text' ), 'fields' => array( 'type' => 'form', 'required' => true ), 'primary_cta' => array( 'type' => 'link' ) ),
            ),
            'process-timeline' => array(
                'family' => 'process', 'tag' => 'section', 'classes' => array( 'dc-section-process' ),
                'layout' => array( 'display' => 'flex', 'direction' => 'column', 'gap' => array( 'value' => 24, 'unit' => 'px' ), 'max_width' => array( 'value' => 1180, 'unit' => 'px' ) ),
                'slots' => array( 'heading' => array( 'type' => 'heading', 'required' => true ), 'description' => array( 'type' => 'rich_text' ), 'steps' => array( 'type' => 'list', 'required' => true ) ),
            ),
            'enquiry-cta' => array(
                'family' => 'cta', 'tag' => 'section', 'classes' => array( 'dc-section-cta' ),
                'layout' => array( 'display' => 'flex', 'direction' => 'row', 'gap' => array( 'value' => 32, 'unit' => 'px' ), 'max_width' => array( 'value' => 1280, 'unit' => 'px' ) ),
                'responsive' => array( 'mobile' => array( 'layout' => array( 'direction' => 'column' ) ) ),
                'slots' => array( 'heading' => array( 'type' => 'heading', 'required' => true ), 'description' => array( 'type' => 'rich_text' ), 'primary_cta' => array( 'type' => 'link', 'required' => true ), 'media' => array( 'type' => 'media' ) ),
            ),
            'location-service' => array(
                'family' => 'location-services', 'tag' => 'section', 'classes' => array( 'dc-section-location-services' ),
                'layout' => array( 'display' => 'grid', 'columns' => 2, 'gap' => array( 'value' => 28, 'unit' => 'px' ), 'max_width' => array( 'value' => 1280, 'unit' => 'px' ) ),
                'responsive' => array( 'mobile' => array( 'layout' => array( 'columns' => 1 ) ) ),
                'slots' => array( 'heading' => array( 'type' => 'heading', 'required' => true ), 'description' => array( 'type' => 'rich_text' ), 'services' => array( 'type' => 'list', 'required' => true ), 'primary_cta' => array( 'type' => 'link' ) ),
            ),
        );
    }
}
