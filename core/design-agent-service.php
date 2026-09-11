<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local high-level entrypoint for Hermes/OpenCode/WP-CLI agents. */
class Design_Core_Elementor_Design_Agent_Service {
    const VERSION = 2;

    public function capabilities() {
        $catalog = new Design_Core_Elementor_Design_Intelligence_Catalog();
        $figma = class_exists( 'Design_Core_Elementor_Figma_Fidelity_Service' );
        return array(
            'version' => self::VERSION,
            'mode' => 'local-agent',
            'design_intelligence' => $catalog->status(),
            'page_shells' => count( Design_Core_Elementor_Page_Shell::definitions() ),
            'section_recipes' => count( Design_Core_Elementor_Section_Recipe_Library::definitions() ),
            'figma_fidelity' => array(
                'available' => $figma,
                'transport_configured' => class_exists( 'Design_Core_Elementor_Figma_Transport' ) ? ( new Design_Core_Elementor_Figma_Transport() )->configured() : false,
                'pipeline' => array( 'figma-read', 'normalize-v2', 'text-composition', 'design-ir-v4', 'native-elementor-compile', 'draft-save', 'figma-reference-compare' ),
                'writes' => 'draft-only',
                'visual_pass_required' => true,
            ),
            'visual_feedback' => class_exists( 'Design_Core_Elementor_Visual_Feedback_Engine' ),
            'visual_correction' => class_exists( 'Design_Core_Elementor_Visual_Correction_Service' ),
            'structural_diff' => class_exists( 'Design_Core_Elementor_Structural_Diff_Engine' ),
            'browser_analysis' => class_exists( 'Design_Core_Elementor_Browser_Analysis_Service' ) ? ( new Design_Core_Elementor_Browser_Analysis_Service() )->is_available() : false,
            'write_policy' => 'Mutations remain draft-only through governed Elementor persistence. Figma references are authoritative and visual mismatches never count as completion.',
        );
    }

    public function plan_design( $brief, array $options = array() ) {
        $parsed = ( new Design_Core_Elementor_Design_Brief() )->parse( $brief, $options );
        if ( is_wp_error( $parsed ) ) { return $parsed; }
        $recommendation = ( new Design_Core_Elementor_Design_Advisor() )->recommend( $parsed['brief'], array(
            'product_type' => $parsed['product_type'] ?? '',
            'mode' => $parsed['constraints']['mode'] ?? 'light',
            'variance' => $options['variance'] ?? null,
            'motion' => $options['motion'] ?? null,
            'density' => $options['density'] ?? null,
            'max_rules' => $options['max_rules'] ?? 24,
        ) );
        if ( is_wp_error( $recommendation ) ) { return $recommendation; }
        $strategy = ( new Design_Core_Elementor_Page_Strategy_Engine() )->plan( $parsed, $recommendation['profile'] );
        $coverage = $this->coverage( $strategy );
        return array(
            'status' => 'success', 'version' => self::VERSION, 'mutation' => false,
            'brief' => $parsed, 'recommendation' => $recommendation, 'site_strategy' => $strategy,
            'library_coverage' => $coverage,
            'next_step' => 'complete' === $coverage['status'] ? 'Create bindings, preview BuildPlan, build a disposable draft, render and run QA.' : 'Resolve library coverage before build.',
        );
    }

    public function figma_prepare( $figma_url, array $options = array() ) {
        return ( new Design_Core_Elementor_Figma_Fidelity_Service() )->prepare( $figma_url, $options );
    }

    public function figma_compile( $figma_url, array $options = array() ) {
        return ( new Design_Core_Elementor_Figma_Fidelity_Service() )->compile( $figma_url, $options );
    }

    public function figma_build( $figma_url, $page_id = 0, array $options = array() ) {
        return ( new Design_Core_Elementor_Figma_Fidelity_Service() )->build_draft( $figma_url, (int) $page_id, $options );
    }

    public function figma_verify( $figma_url, $candidate_target, array $options = array() ) {
        return ( new Design_Core_Elementor_Figma_Fidelity_Service() )->verify( $figma_url, $candidate_target, $options );
    }

    public function quality( $page_id, $reference = '', $candidate = '', array $options = array() ) {
        $page_id = (int) $page_id;
        $context = array( 'target_similarity' => (float) ( $options['target_similarity'] ?? .95 ), 'interactive' => ! empty( $options['interactive'] ), 'interaction' => (array) ( $options['interaction'] ?? array() ) );
        if ( $reference ) { $context['reference_target'] = $reference; }
        if ( $candidate ) { $context['candidate_target'] = $candidate; }
        $qa = ( new Design_Core_Elementor_Visual_QA() )->audit_page( $page_id, $context );
        $structural = array( 'status' => $reference ? 'unverified' : 'not-applicable' );
        if ( is_array( $options['reference_analysis'] ?? null ) && is_array( $options['candidate_analysis'] ?? null ) ) { $structural = ( new Design_Core_Elementor_Structural_Diff_Engine() )->compare( $options['reference_analysis'], $options['candidate_analysis'] ); }
        return array( 'page_id' => $page_id, 'qa' => $qa, 'structural' => $structural, 'publishable' => ! empty( $qa['quality_gate']['publishable'] ) && empty( $structural['requires_rebuild'] ) );
    }

    private function coverage( $strategy ) {
        $shells = Design_Core_Elementor_Page_Shell::definitions(); $recipes = Design_Core_Elementor_Section_Recipe_Library::definitions(); $missing_shells = $missing_recipes = array();
        foreach ( (array) ( $strategy['pages'] ?? array() ) as $page ) { $shell = sanitize_key( (string) ( $page['shell'] ?? '' ) ); if ( $shell && ! isset( $shells[ $shell ] ) ) { $missing_shells[] = $shell; } }
        foreach ( $shells as $shell ) { foreach ( (array) ( $shell['sections'] ?? array() ) as $section ) { $recipe = sanitize_key( (string) ( $section['recipe'] ?? '' ) ); if ( $recipe && ! isset( $recipes[ $recipe ] ) ) { $missing_recipes[] = $recipe; } } }
        return array( 'shell_count' => count( $shells ), 'recipe_count' => count( $recipes ), 'missing_shells' => array_values( array_unique( $missing_shells ) ), 'missing_recipes' => array_values( array_unique( $missing_recipes ) ), 'status' => $missing_shells || $missing_recipes ? 'incomplete' : 'complete' );
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI && is_readable( DESIGN_CORE_ELEMENTOR_PATH . 'core/design-agent-cli.php' ) ) { require_once DESIGN_CORE_ELEMENTOR_PATH . 'core/design-agent-cli.php'; }
