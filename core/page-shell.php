<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Platform-neutral ordered page composition built from Section Recipes. */
class Design_Core_Elementor_Page_Shell {
    const SCHEMA_VERSION = 1;

    public function all() { return self::definitions(); }
    public function get( $shell_id ) { $all = self::definitions(); return $all[ sanitize_key( (string) $shell_id ) ] ?? null; }

    public function compile( $shell_id, array $bindings = array() ) {
        $shell_id = sanitize_key( (string) $shell_id );
        $shell = $this->get( $shell_id );
        if ( ! is_array( $shell ) ) { return new WP_Error( 'design_core_shell_not_found', 'Unknown page shell: ' . $shell_id ); }
        $library = new Design_Core_Elementor_Section_Recipe_Library();
        $nodes = array(); $roots = array(); $included = array(); $index = 0;
        foreach ( (array) ( $shell['sections'] ?? array() ) as $section ) {
            if ( ! is_array( $section ) ) { continue; }
            $key = sanitize_key( $section['key'] ?? $section['recipe'] ?? 'section-' . $index );
            $recipe = sanitize_key( $section['recipe'] ?? '' );
            $section_bindings = is_array( $bindings[ $key ] ?? null ) ? $bindings[ $key ] : array();
            if ( empty( $section['required'] ) && ! $section_bindings ) { $index++; continue; }
            $root_id = 'shell-' . $shell_id . '-' . $index . '-' . $key;
            $compiled = $library->compile( $recipe, $section_bindings, $root_id );
            if ( is_wp_error( $compiled ) ) { return $compiled; }
            $nodes = array_merge( $nodes, (array) ( $compiled['nodes'] ?? array() ) );
            $roots = array_merge( $roots, (array) ( $compiled['root_ids'] ?? array() ) );
            $included[] = array( 'position' => $index, 'key' => $key, 'recipe' => $recipe, 'root_id' => $root_id );
            $index++;
        }
        $ir = array(
            'schema_version' => Design_Core_Elementor_Design_IR::SCHEMA_VERSION,
            'type' => 'design-ir',
            'source_name' => 'page-shell:' . $shell_id,
            'nodes' => $nodes,
            'root_ids' => $roots,
            'analysis_quality' => array( 'browser_runtime' => 'shell', 'computed_styles' => 'shell', 'geometry' => 'shell', 'css_static' => 'shell', 'interaction' => 'shell' ),
            'tokens' => array(), 'breakpoints' => array(),
            'diagnostics' => array( 'page_shell' => array( 'schema_version' => self::SCHEMA_VERSION, 'id' => $shell_id, 'sections' => $included ) ),
        );
        try {
            ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
            $ir = ( new Design_Core_Elementor_Normalization_Pipeline() )->normalize( $ir );
            ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
        } catch ( Throwable $exception ) { return new WP_Error( 'design_core_shell_compile_failed', $exception->getMessage() ); }
        return $ir;
    }

    public static function definitions() {
        return array(
            'service-landing' => array( 'label' => 'Service landing', 'sections' => array(
                array( 'key' => 'hero', 'recipe' => 'hero-split', 'required' => true ), array( 'key' => 'intro', 'recipe' => 'intro', 'required' => true ), array( 'key' => 'benefits', 'recipe' => 'benefits-grid', 'required' => true ), array( 'key' => 'comparison', 'recipe' => 'comparison', 'required' => false ), array( 'key' => 'process', 'recipe' => 'process-timeline', 'required' => true ), array( 'key' => 'cta', 'recipe' => 'enquiry-cta', 'required' => true ),
            ) ),
            'location-landing' => array( 'label' => 'Location landing', 'sections' => array(
                array( 'key' => 'hero', 'recipe' => 'hero-split', 'required' => true ), array( 'key' => 'intro', 'recipe' => 'intro', 'required' => true ), array( 'key' => 'services', 'recipe' => 'location-service', 'required' => true ), array( 'key' => 'benefits', 'recipe' => 'benefits-grid', 'required' => false ), array( 'key' => 'process', 'recipe' => 'process-timeline', 'required' => false ), array( 'key' => 'cta', 'recipe' => 'enquiry-cta', 'required' => true ),
            ) ),
            'resource-article' => array( 'label' => 'Resource / article', 'sections' => array(
                array( 'key' => 'hero', 'recipe' => 'hero-split', 'required' => true ), array( 'key' => 'intro', 'recipe' => 'intro', 'required' => true ), array( 'key' => 'cta', 'recipe' => 'enquiry-cta', 'required' => false ),
            ) ),
            'import-guide' => array( 'label' => 'Import guide', 'sections' => array(
                array( 'key' => 'hero', 'recipe' => 'hero-split', 'required' => true ), array( 'key' => 'intro', 'recipe' => 'intro', 'required' => true ), array( 'key' => 'process', 'recipe' => 'process-timeline', 'required' => true ), array( 'key' => 'comparison', 'recipe' => 'comparison', 'required' => false ), array( 'key' => 'cta', 'recipe' => 'enquiry-cta', 'required' => true ),
            ) ),
            'contact-about' => array( 'label' => 'Contact / about', 'sections' => array(
                array( 'key' => 'hero', 'recipe' => 'hero-split', 'required' => true ), array( 'key' => 'intro', 'recipe' => 'intro', 'required' => true ), array( 'key' => 'benefits', 'recipe' => 'benefits-grid', 'required' => false ), array( 'key' => 'cta', 'recipe' => 'enquiry-cta', 'required' => true ),
            ) ),
        );
    }
}
