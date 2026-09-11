<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Runtime facade for Design Memory. It never mutates a design by itself: it
 * returns proven rules/lessons to planners and learns only from verified runs.
 */
class Design_Core_Elementor_Design_Memory_Service {
    const VERSION = 1;
    private static $booted = false;
    private $store;

    public function __construct( Design_Core_Elementor_Design_Memory_Store $store = null ) {
        $this->store = $store ?: new Design_Core_Elementor_Design_Memory_Store();
    }

    public static function boot() {
        if ( self::$booted ) { return; }
        self::$booted = true;
        $service = new self();
        add_filter( 'design_core_elementor_design_memory_recommendations', array( $service, 'filter_recommendations' ), 10, 2 );
        add_action( 'design_core_elementor_record_verified_correction', array( $service, 'record_verified_correction' ), 10, 1 );
        add_action( 'design_core_elementor_record_design_incident', array( $service, 'record_incident' ), 10, 1 );
        add_action( 'added_post_meta', array( $service, 'observe_figma_verification_meta' ), 10, 4 );
        add_action( 'updated_post_meta', array( $service, 'observe_figma_verification_meta' ), 10, 4 );
    }

    public function recommend( array $context, $limit = 8 ) {
        $signature_engine = new Design_Core_Elementor_Failure_Signature_Engine();
        $signature = $signature_engine->signature( $context );
        $rules = ( new Design_Core_Elementor_Fidelity_Rule_Registry() )->match( $signature );
        $lessons = ( new Design_Core_Elementor_Lesson_Retriever( $this->store ) )->retrieve( $context, $limit );
        return array(
            'version' => self::VERSION,
            'signature' => $signature,
            'rules' => array_slice( $rules, 0, max( 1, (int) $limit ) ),
            'lessons' => $lessons,
            'policy' => 'advisory-until-runtime-verified',
        );
    }

    public function filter_recommendations( $existing, $context ) {
        $recommendations = $this->recommend( is_array( $context ) ? $context : array() );
        if ( is_array( $existing ) && $existing ) {
            $recommendations['external'] = $existing;
        }
        return $recommendations;
    }

    public function record_verified_correction( $run ) {
        if ( ! is_array( $run ) ) { return; }
        ( new Design_Core_Elementor_Correction_Learning_Engine( $this->store ) )->learn( $run );
    }

    public function record_incident( $incident ) {
        if ( ! is_array( $incident ) ) { return; }
        $context = is_array( $incident['context'] ?? null ) ? $incident['context'] : $incident;
        if ( empty( $incident['signature'] ) ) { $incident['signature'] = ( new Design_Core_Elementor_Failure_Signature_Engine() )->signature( $context ); }
        $this->store->record_incident( $incident );
    }

    /**
     * Figma fidelity verification already persists a post-meta evidence record.
     * Observe it automatically so successful/failed real renders become history.
     * No reusable lesson is created here because no correction provenance exists.
     */
    public function observe_figma_verification_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( '_design_core_figma_visual_verification' !== (string) $meta_key || ! is_array( $meta_value ) ) { return; }
        $source = get_post_meta( (int) $object_id, '_design_core_figma_source', true );
        $source = is_array( $source ) ? $source : array();
        $visual = is_array( $meta_value['visual'] ?? null ) ? $meta_value['visual'] : array();
        $gate = is_array( $meta_value['quality_gate'] ?? null ) ? $meta_value['quality_gate'] : array();
        $this->store->record_incident( array(
            'signature' => 'figma.render.verification',
            'status' => ! empty( $gate['publishable'] ) ? 'verified-render' : 'render-mismatch',
            'source_type' => 'figma',
            'page_id' => (int) $object_id,
            'source_node_id' => sanitize_text_field( (string) ( $source['node_id'] ?? '' ) ),
            'visual_score' => (float) ( $visual['similarity'] ?? $visual['score'] ?? 0 ),
            'quality_gate' => $gate,
            'context' => array(
                'source_type' => 'figma',
                'page_id' => (int) $object_id,
                'source_node_id' => sanitize_text_field( (string) ( $source['node_id'] ?? '' ) ),
                'candidate_selector' => sanitize_text_field( (string) ( $source['candidate_selector'] ?? '' ) ),
            ),
        ) );
    }

    public function snapshot() {
        $lessons = $this->store->lessons();
        $incidents = $this->store->incidents();
        $verified = count( array_filter( $lessons, static fn( $lesson ) => ! empty( $lesson['verified'] ) ) );
        return array(
            'version' => self::VERSION,
            'lessons' => count( $lessons ),
            'verified_lessons' => $verified,
            'incidents' => count( $incidents ),
            'built_in_rules' => count( ( new Design_Core_Elementor_Fidelity_Rule_Registry() )->all() ),
            'policy' => array(
                'learn_only_from_verified_corrections' => true,
                'failed_runs_become_incidents_only' => true,
                'memory_never_mutates_design_without_planner' => true,
            ),
        );
    }
}
