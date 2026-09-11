<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Strict Figma -> Elementor fidelity path.
 * Figma is authoritative: assets, reference render, versioned Design Memory,
 * exact node geometry, parent ownership, font proof and responsive runtime
 * safety are mandatory evidence.
 */
class Design_Core_Elementor_Figma_Fidelity_Service {
    const VERSION = 5;
    const DEFAULT_TARGET_SIMILARITY = 0.95;

    public function prepare( $figma_url, array $options = array() ) {
        if ( ! class_exists( 'Design_Core_Elementor_Figma_Transport' ) || ! class_exists( 'Design_Core_Elementor_Figma_Design_IR_Adapter' ) ) { return new WP_Error( 'design_core_figma_fidelity_unavailable', 'Figma fidelity services are unavailable.' ); }
        $transport = new Design_Core_Elementor_Figma_Transport();
        if ( ! $transport->configured() ) { return new WP_Error( 'design_core_figma_token_missing', 'Configure DESIGN_CORE_FIGMA_ACCESS_TOKEN before using the local Figma fidelity path.' ); }

        $source = $transport->read_url( $figma_url, array( 'resolve_image_fills' => true, 'resolve_raster_assets' => true, 'resolve_vector_assets' => true, 'export_reference' => true ) );
        if ( is_wp_error( $source ) ) { return $source; }
        $asset_diagnostics = (array) ( $source['asset_diagnostics'] ?? array() );
        $raster_candidates = (int) ( $asset_diagnostics['raster_candidates'] ?? 0 );
        $raster_exports = (int) ( $asset_diagnostics['raster_exports'] ?? count( (array) ( $source['rendered_image_assets'] ?? array() ) ) );
        if ( $raster_candidates > $raster_exports && empty( $options['allow_partial_raster_assets'] ) ) {
            return new WP_Error( 'design_core_figma_raster_export_incomplete', 'Strict Figma fidelity found transformed image atoms that could not be rendered by Figma. Refusing to guess their crop/transform in Elementor.' );
        }
        $vector_candidates = (int) ( $asset_diagnostics['vector_candidates'] ?? 0 );
        $vector_exports = (int) ( $asset_diagnostics['vector_exports'] ?? count( (array) ( $source['vector_assets'] ?? array() ) ) );
        if ( $vector_candidates > $vector_exports && empty( $options['allow_partial_vector_assets'] ) ) {
            return new WP_Error( 'design_core_figma_vector_export_incomplete', 'Strict Figma fidelity found vector/icon candidates that could not be exported. Refusing to silently omit or containerize authored assets.' );
        }

        $ir = ( new Design_Core_Elementor_Figma_Design_IR_Adapter() )->convert( $source, (string) ( $source['source']['node_id'] ?? '' ) );
        if ( is_wp_error( $ir ) ) { return $ir; }

        $source_fingerprint = class_exists( 'Design_Core_Elementor_Design_Memory_Store' ) ? Design_Core_Elementor_Design_Memory_Store::source_fingerprint( (array) ( $source['source'] ?? array() ) ) : '';
        $memory = array();
        if ( class_exists( 'Design_Core_Elementor_Design_Memory_Retriever' ) ) {
            $prepared_memory = ( new Design_Core_Elementor_Design_Memory_Retriever() )->prepare_ir( $ir, array(
                'source_fingerprint' => $source_fingerprint,
                'project_scope_key' => Design_Core_Elementor_Design_Memory_Store::project_scope_key(),
            ) );
            $ir = (array) ( $prepared_memory['design_ir'] ?? $ir );
            $memory = (array) ( $prepared_memory['memory'] ?? array() );
        }
        try { ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir ); }
        catch ( Throwable $exception ) { return new WP_Error( 'design_core_figma_fidelity_ir_invalid', $exception->getMessage() ); }

        $root = $this->root_node( $ir );
        $geometry = (array) ( $root['figma']['geometry'] ?? array() );
        $source_width = (int) round( (float) ( $geometry['width'] ?? $source['reference_image']['width'] ?? 0 ) );
        if ( $source_width < 320 || $source_width > 3840 ) { $source_width = 1440; }
        $figma_node_id = (string) ( $source['source']['node_id'] ?? $root['figma']['id'] ?? '' );
        $selector = $figma_node_id ? '.dc-figma-node-' . $this->class_id( $figma_node_id ) : '';
        $reference = is_array( $source['reference_image'] ?? null ) ? $source['reference_image'] : array();
        if ( empty( $reference['url'] ) ) { return new WP_Error( 'design_core_figma_reference_missing', 'Strict Figma fidelity requires a Figma-rendered reference export.' ); }

        return array(
            'status' => 'prepared', 'version' => self::VERSION, 'figma_url' => esc_url_raw( (string) $figma_url ),
            'source' => Design_Core_Elementor_Change_Ledger::transport_safe( (array) ( $source['source'] ?? array() ) ),
            'source_fingerprint' => $source_fingerprint, 'reference_image' => Design_Core_Elementor_Change_Ledger::transport_safe( $reference ),
            'source_viewport_width' => $source_width, 'candidate_selector' => $selector, 'design_ir' => $ir, 'design_memory' => $memory,
            'diagnostics' => array(
                'transport_version' => (int) ( $source['transport_version'] ?? 0 ), 'adapter_version' => (int) ( $ir['diagnostics']['figma_adapter_version'] ?? 0 ),
                'normalization_version' => (int) ( $ir['diagnostics']['normalization']['version'] ?? 0 ), 'node_count' => count( (array) ( $ir['nodes'] ?? array() ) ),
                'source_version' => sanitize_text_field( (string) ( $source['source']['version'] ?? '' ) ),
                'source_structural_hash' => sanitize_text_field( (string) ( $source['source']['structural_hash'] ?? '' ) ),
                'resolved_image_fills' => count( (array) ( $source['image_fills'] ?? array() ) ),
                'resolved_rendered_image_assets' => count( (array) ( $source['rendered_image_assets'] ?? array() ) ),
                'resolved_vector_assets' => count( (array) ( $source['vector_assets'] ?? array() ) ),
                'asset_diagnostics' => Design_Core_Elementor_Change_Ledger::transport_safe( $asset_diagnostics ),
                'reference_exported' => true, 'memory_lessons' => count( (array) ( $memory['lessons'] ?? array() ) ), 'memory_strategies' => array_values( (array) ( $memory['strategies'] ?? array() ) ),
                'memory_skipped_incompatible' => (int) ( $memory['skipped_incompatible'] ?? 0 ),
                'policy' => 'figma-is-authoritative-strict-raster-vector-assets-versioned-memory-geometry-structure-font-responsive-runtime-and-rendered-verification',
            ),
        );
    }

    public function compile( $figma_url, array $options = array() ) {
        $prepared = $this->prepare( $figma_url, $options ); if ( is_wp_error( $prepared ) ) { return $prepared; }
        try { $mapper = new Design_Core_Elementor_Mapping_Engine(); $elements = $mapper->map_ir( $prepared['design_ir'] ); $mapping = $mapper->mapping_report(); }
        catch ( Throwable $exception ) { return new WP_Error( 'design_core_figma_mapping_failed', $exception->getMessage() ); }
        if ( ! $elements ) { return new WP_Error( 'design_core_figma_mapping_empty', 'Figma compiler produced no Elementor elements.' ); }
        $gate = class_exists( 'Design_Core_Elementor_Strict_Structure_Gate' ) ? Design_Core_Elementor_Strict_Structure_Gate::audit_elements( $elements ) : array( 'status' => 'unavailable' );
        if ( 'fail' === ( $gate['status'] ?? '' ) ) { return new WP_Error( 'design_core_figma_structure_failed', 'Compiled Figma tree failed the strict Elementor structure gate.' ); }
        $prepared['status'] = 'compiled'; $prepared['elements'] = $elements; $prepared['element_count'] = $this->count_elements( $elements );
        $prepared['mapping_report'] = $mapping; $prepared['strict_structure_gate'] = $gate;
        $prepared['artifact_hash'] = hash( 'sha256', wp_json_encode( array( 'source' => $prepared['source'], 'ir' => $prepared['design_ir'], 'elements' => $elements ) ) );
        return $prepared;
    }

    public function build_draft( $figma_url, $page_id = 0, array $options = array() ) {
        $compiled = $this->compile( $figma_url, $options ); if ( is_wp_error( $compiled ) ) { return $compiled; }
        $created = false; $page_id = (int) $page_id;
        if ( $page_id < 1 ) {
            $page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => sanitize_text_field( (string) ( $options['title'] ?? 'Figma Import' ) ), 'post_content' => '' ) );
            if ( is_wp_error( $page_id ) || ! $page_id ) { return new WP_Error( 'design_core_figma_draft_create_failed', 'Unable to create the Figma draft page.' ); }
            $created = true;
        }
        $post = get_post( $page_id );
        if ( ! $post || 'page' !== $post->post_type || 'draft' !== $post->post_status ) { if ( $created ) { wp_delete_post( $page_id, true ); } return new WP_Error( 'design_core_figma_draft_required', 'Figma fidelity builds may write only to a WordPress page in draft status.' ); }
        $adapter = new Design_Core_Elementor_V3_Adapter();
        $settings = get_post_meta( $page_id, '_elementor_page_settings', true );
        $saved = $adapter->save_page( $page_id, $compiled['elements'], is_array( $settings ) ? $settings : array() );
        if ( is_wp_error( $saved ) ) { if ( $created ) { wp_delete_post( $page_id, true ); } return $saved; }
        $reloaded = $adapter->reload( $page_id );
        if ( ! is_array( $reloaded ) || hash( 'sha256', wp_json_encode( $this->strip_default_is_inner( $reloaded ) ) ) !== hash( 'sha256', wp_json_encode( $this->strip_default_is_inner( $compiled['elements'] ) ) ) ) { return new WP_Error( 'design_core_figma_persistence_mismatch', 'Elementor reload does not exactly match the compiled Figma tree.' ); }
        if ( class_exists( 'Design_Core_Elementor_Figma_Font_Service' ) ) { ( new Design_Core_Elementor_Figma_Font_Service() )->store_manifest( $page_id, $compiled['design_ir'] ); }
        update_post_meta( $page_id, '_design_core_figma_source', array(
            'url' => esc_url_raw( (string) $figma_url ), 'node_id' => sanitize_text_field( (string) ( $compiled['source']['node_id'] ?? '' ) ),
            'source_fingerprint' => sanitize_key( (string) ( $compiled['source_fingerprint'] ?? '' ) ), 'artifact_hash' => (string) $compiled['artifact_hash'],
            'reference_image' => $compiled['reference_image'], 'source_viewport_width' => (int) $compiled['source_viewport_width'],
            'candidate_selector' => (string) $compiled['candidate_selector'], 'created_at' => gmdate( 'c' ),
        ) );
        $result = array(
            'status' => 'saved_to_draft', 'version' => self::VERSION, 'page_id' => $page_id, 'created_page' => $created, 'candidate_target' => get_permalink( $page_id ),
            'artifact_hash' => (string) $compiled['artifact_hash'], 'exact_tree_match' => true, 'mapping_report' => $compiled['mapping_report'],
            'reference_image' => $compiled['reference_image'], 'candidate_selector' => $compiled['candidate_selector'], 'source_viewport_width' => $compiled['source_viewport_width'],
            'design_memory' => (array) ( $compiled['design_memory'] ?? array() ), 'visual' => array( 'status' => 'unverified' ), 'publishable' => false,
        );
        if ( ! empty( $options['verify'] ) ) {
            $verify_target = trim( (string) ( $options['candidate_target'] ?? '' ) ); if ( ! $verify_target ) { $verify_target = (string) get_permalink( $page_id ); }
            $verified = $this->verify( $figma_url, $verify_target, array_merge( $options, array( 'page_id' => $page_id, 'prepared' => $compiled ) ) );
            if ( ! is_wp_error( $verified ) ) { $result['visual'] = $verified['visual']; $result['quality_gate'] = $verified['quality_gate']; $result['learning'] = $verified['learning'] ?? array(); $result['publishable'] = ! empty( $verified['quality_gate']['publishable'] ); }
            else { $result['visual'] = array( 'status' => 'unavailable', 'error' => $verified->get_error_message() ); }
        }
        return $result;
    }

    public function verify( $figma_url, $candidate_target, array $options = array() ) {
        $prepared = is_array( $options['prepared'] ?? null ) ? $options['prepared'] : $this->prepare( $figma_url, $options ); if ( is_wp_error( $prepared ) ) { return $prepared; }
        $reference = (array) ( $prepared['reference_image'] ?? array() ); if ( empty( $reference['url'] ) ) { return new WP_Error( 'design_core_figma_reference_missing', 'Figma rendered reference export is required for fidelity verification.' ); }
        $candidate_target = $this->validate_candidate_target( $candidate_target ); if ( is_wp_error( $candidate_target ) ) { return $candidate_target; }
        $target = max( 0.50, min( 0.999, (float) ( $options['target_similarity'] ?? self::DEFAULT_TARGET_SIMILARITY ) ) );
        $width = (int) ( $prepared['source_viewport_width'] ?? 1440 ); $selector = (string) ( $prepared['candidate_selector'] ?? '' );
        $allowed_hosts = array_values( array_unique( array_merge( array( 'fonts.googleapis.com', 'fonts.gstatic.com' ), (array) ( $options['candidate_allowed_hosts'] ?? array() ) ) ) );
        $comparison = ( new Design_Core_Elementor_Screenshot_Service() )->compare_targets( (string) $reference['url'], (string) $candidate_target, (string) ( $options['workdir'] ?? '' ), array( 'viewports' => array( 'figma-source' => $width ), 'reference_direct_image' => true, 'candidate_selector' => $selector, 'candidate_allowed_hosts' => $allowed_hosts ) );
        if ( is_wp_error( $comparison ) ) { return $comparison; }

        $geometry_report = array( 'status' => 'unavailable', 'differences' => array() );
        $structure_report = array( 'status' => 'unavailable', 'issues' => array() );
        $font_report = array( 'status' => 'unavailable', 'issues' => array() );
        $responsive_report = array( 'status' => 'unavailable', 'mode' => 'inferred-runtime', 'issues' => array() );
        $analysis_error = '';
        $candidate_analysis = array();
        $responsive_widths = array_values( array_filter( array( 1024, 768, 390 ), static fn( $v ) => $v < $width ) );
        $analysis_widths = array_values( array_unique( array_merge( array( $width ), $responsive_widths ) ) );
        if ( ! class_exists( 'Design_Core_Elementor_Browser_Analysis_Service' ) ) { $analysis_error = 'Rendered DOM analysis is unavailable.'; }
        else {
            $browser = new Design_Core_Elementor_Browser_Analysis_Service();
            if ( ! $browser->is_available() ) { $analysis_error = 'Rendered DOM analysis is unavailable.'; }
            else {
                $candidate_analysis = $browser->analyze_target( (string) $candidate_target, $analysis_widths, $allowed_hosts );
                if ( is_wp_error( $candidate_analysis ) ) { $analysis_error = $candidate_analysis->get_error_message(); $candidate_analysis = array(); }
                else {
                    if ( class_exists( 'Design_Core_Elementor_Figma_Geometry_Verifier' ) ) { $geometry_report = ( new Design_Core_Elementor_Figma_Geometry_Verifier() )->report( (array) $prepared['design_ir'], $candidate_analysis, $width ); }
                    if ( class_exists( 'Design_Core_Elementor_Figma_Structure_Verifier' ) ) { $structure_report = ( new Design_Core_Elementor_Figma_Structure_Verifier() )->report( (array) $prepared['design_ir'], $candidate_analysis, $width ); }
                    if ( class_exists( 'Design_Core_Elementor_Figma_Font_Verifier' ) ) { $font_report = ( new Design_Core_Elementor_Figma_Font_Verifier() )->report( (array) $prepared['design_ir'], $candidate_analysis, $width ); }
                    if ( $responsive_widths && class_exists( 'Design_Core_Elementor_Figma_Responsive_Verifier' ) ) { $responsive_report = ( new Design_Core_Elementor_Figma_Responsive_Verifier() )->report( (array) $prepared['design_ir'], $candidate_analysis, $responsive_widths ); }
                    elseif ( ! $responsive_widths ) { $responsive_report = array( 'status' => 'pass', 'mode' => 'source-is-smallest-governed-viewport', 'reference_fidelity' => 'source-only', 'issues' => array(), 'expected' => 0, 'verified' => 0, 'match_ratio' => 1 ); }
                }
            }
        }

        $geometry = (array) ( $geometry_report['differences'] ?? array() );
        $engine = new Design_Core_Elementor_Visual_Feedback_Engine();
        $visual = $engine->evaluate( $comparison, $geometry, $target );
        $geometry_verified = ! $analysis_error && 'unavailable' !== (string) ( $geometry_report['status'] ?? 'unavailable' ) && (int) ( $geometry_report['expected'] ?? 0 ) > 0 && (float) ( $geometry_report['match_ratio'] ?? 0 ) >= 0.98;
        $structure_verified = ! $analysis_error && 'pass' === (string) ( $structure_report['status'] ?? '' );
        $font_status = (string) ( $font_report['status'] ?? 'unavailable' );
        $font_verified = in_array( $font_status, array( 'pass', 'not-applicable' ), true );

        $strict_issues = array();
        foreach ( (array) ( $structure_report['issues'] ?? array() ) as $issue ) { if ( is_array( $issue ) ) { $strict_issues[] = $issue; } }
        foreach ( (array) ( $font_report['issues'] ?? array() ) as $issue ) { if ( is_array( $issue ) ) { $strict_issues[] = $issue; } }
        if ( $strict_issues ) {
            $visual['issues'] = array_merge( (array) ( $visual['issues'] ?? array() ), $strict_issues );
            $visual['correction_plan'] = $engine->build_correction_plan( $visual['issues'] );
        }
        $visual['figma_geometry_verified'] = $geometry_verified;
        $visual['figma_geometry_report'] = $geometry_report;
        $visual['figma_structure_verified'] = $structure_verified;
        $visual['figma_structure_report'] = $structure_report;
        $visual['figma_font_verified'] = $font_verified;
        $visual['figma_font_report'] = $font_report;
        $visual['figma_geometry_analysis_error'] = $analysis_error;
        if ( $analysis_error ) { $visual['status'] = 'unavailable'; }
        elseif ( ! $geometry_verified || ! $structure_verified || ! $font_verified || $strict_issues ) { $visual['status'] = 'needs-correction'; }

        $architecture = array( 'status' => 'unverified' ); $ux = array( 'status' => 'unverified' );
        $page_id = (int) ( $options['page_id'] ?? 0 );
        if ( $page_id > 0 ) {
            $qa = ( new Design_Core_Elementor_Visual_QA() )->audit_page( $page_id );
            $architecture = array( 'status' => in_array( $qa['status'] ?? '', array( 'pass', 'warning' ), true ) ? 'pass' : 'fail', 'score' => (float) ( $qa['score'] ?? 0 ) );
            $ux = (array) ( $qa['ux_quality'] ?? array( 'status' => 'unverified' ) );
        }
        $responsive = array(
            'status' => 'pass' === (string) ( $responsive_report['status'] ?? '' ) ? 'pass' : ( 'fail' === (string) ( $responsive_report['status'] ?? '' ) ? 'fail' : 'unverified' ),
            'score' => isset( $responsive_report['match_ratio'] ) ? round( (float) $responsive_report['match_ratio'] * 100, 2 ) : null,
            'verification_mode' => (string) ( $responsive_report['mode'] ?? 'unavailable' ),
            'reference_fidelity' => (string) ( $responsive_report['reference_fidelity'] ?? 'not-claimed' ),
            'report' => $responsive_report,
        );
        $gate = ( new Design_Core_Elementor_Visual_Quality_Gate() )->evaluate( array( 'architecture' => $architecture, 'responsive' => $responsive, 'visual' => $visual, 'interaction' => array( 'status' => ! empty( $options['interactive'] ) ? 'unverified' : 'not-applicable' ), 'ux' => $ux ), array( 'reference_exists' => true, 'interactive' => ! empty( $options['interactive'] ) ) );

        $learning = array();
        if ( class_exists( 'Design_Core_Elementor_Correction_Learning_Engine' ) ) {
            $learning_observation = $visual;
            if ( empty( $gate['publishable'] ) ) {
                $learning_observation['status'] = 'needs-correction';
                $learning_observation['issues'] = array_merge( (array) ( $learning_observation['issues'] ?? array() ), (array) ( $responsive_report['issues'] ?? array() ) );
            }
            $learning = ( new Design_Core_Elementor_Correction_Learning_Engine() )->observe_verification( $learning_observation, array( 'source_kind' => 'figma', 'source_fingerprint' => sanitize_key( (string) ( $prepared['source_fingerprint'] ?? '' ) ), 'project_scope_key' => Design_Core_Elementor_Design_Memory_Store::project_scope_key(), 'page_id' => $page_id, 'design_ir' => (array) ( $prepared['design_ir'] ?? array() ) ) );
        }
        if ( $page_id > 0 ) { update_post_meta( $page_id, '_design_core_figma_visual_verification', array( 'visual' => $visual, 'responsive' => $responsive, 'quality_gate' => $gate, 'learning' => $learning, 'reference' => $reference, 'verified_at' => gmdate( 'c' ) ) ); }
        return array( 'status' => 'pass' === ( $visual['status'] ?? '' ) && ! empty( $gate['publishable'] ) ? 'verified' : 'needs-correction', 'version' => self::VERSION, 'page_id' => $page_id, 'reference_image' => $reference, 'candidate_target' => (string) $candidate_target, 'candidate_selector' => $selector, 'source_viewport_width' => $width, 'source_fingerprint' => sanitize_key( (string) ( $prepared['source_fingerprint'] ?? '' ) ), 'visual' => $visual, 'responsive' => $responsive, 'quality_gate' => $gate, 'learning' => $learning );
    }

    private function root_node( array $ir ) { $root_id = (string) ( $ir['root_ids'][0] ?? '' ); foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) { if ( (string) ( $node['id'] ?? '' ) === $root_id ) { return $node; } } return array(); }
    private function class_id( $figma_id ) { return sanitize_html_class( trim( strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $figma_id ) ), '-' ) ); }
    private function validate_candidate_target( $target ) {
        $target = trim( (string) $target ); if ( '' === $target ) { return new WP_Error( 'design_core_figma_candidate_missing', 'Candidate target is required.' ); }
        if ( preg_match( '#^https?://#i', $target ) ) {
            $t = function_exists( 'wp_parse_url' ) ? wp_parse_url( $target ) : parse_url( $target ); $h = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url( '/' ) ) : parse_url( home_url( '/' ) );
            if ( is_array( $t ) && is_array( $h ) ) { $ts = strtolower( (string) ( $t['scheme'] ?? '' ) ); $hs = strtolower( (string) ( $h['scheme'] ?? '' ) ); $th = strtolower( (string) ( $t['host'] ?? '' ) ); $hh = strtolower( (string) ( $h['host'] ?? '' ) ); $tp = (int) ( $t['port'] ?? ( 'https' === $ts ? 443 : 80 ) ); $hp = (int) ( $h['port'] ?? ( 'https' === $hs ? 443 : 80 ) ); if ( $ts === $hs && $th && $th === $hh && $tp === $hp ) { return $target; } }
            if ( class_exists( 'Design_Core_Elementor_Security_Policy' ) ) { return ( new Design_Core_Elementor_Security_Policy() )->validate_remote_url( $target ); }
            return new WP_Error( 'design_core_figma_candidate_forbidden', 'Remote candidate target is not the current WordPress origin.' );
        }
        $path = 0 === strpos( $target, 'file://' ) ? substr( $target, 7 ) : $target; $real = realpath( $path ); return $real && is_readable( $real ) ? $real : new WP_Error( 'design_core_figma_candidate_invalid', 'Candidate file is not readable.' );
    }
    private function count_elements( array $elements ) { $count = 0; foreach ( $elements as $element ) { if ( ! is_array( $element ) ) { continue; } $count++; if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) { $count += $this->count_elements( $element['elements'] ); } } return $count; }
    private function strip_default_is_inner( array $elements ) { $out = array(); foreach ( $elements as $key => $element ) { if ( ! is_array( $element ) ) { $out[ $key ] = $element; continue; } if ( array_key_exists( 'isInner', $element ) && false === $element['isInner'] ) { unset( $element['isInner'] ); } if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) { $element['elements'] = $this->strip_default_is_inner( $element['elements'] ); } $out[ $key ] = $element; } return $out; }
}
