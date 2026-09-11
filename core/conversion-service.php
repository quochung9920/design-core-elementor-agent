<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Canonical application service for Design Core conversions. */
class Design_Core_Elementor_Conversion_Service {
    const MAX_HTML_BYTES = 1048576;
    const MAX_CSS_BYTES = 1048576;
    private $analysis; private $normalizer; private $planner; private $executor;

    public function __construct( $analysis = null, $normalizer = null, $planner = null, $executor = null ) {
        $this->analysis = $analysis ?: new Design_Core_Elementor_Analysis_Engine();
        $this->normalizer = $normalizer ?: new Design_Core_Elementor_Normalization_Pipeline();
        $this->planner = $planner ?: new Design_Core_Elementor_Build_Planner();
        $this->executor = $executor ?: new Design_Core_Elementor_Build_Plan_Executor();
    }

    public function analyze( $html, $css = '', $source_name = 'source' ) {
        if ( strlen( (string) $html ) > self::MAX_HTML_BYTES || strlen( (string) $css ) > self::MAX_CSS_BYTES ) { return array( 'error' => 'source-too-large', 'design_ir' => array() ); }
        return $this->analysis->analyze_html( $html, $css, $source_name );
    }

    public function plan( $ir, $adapter_target = 'elementor-v3' ) { return $this->planner->plan( $this->normalizer->normalize( $ir ), $adapter_target ); }

    public function convert( $html, $css = '', $page_title = 'Imported Page', $base_url = '' ) {
        $observer = new Design_Core_Elementor_Observability();
        $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
        $observer->event( 'preflight', 'started', array( 'title' => sanitize_text_field( $page_title ) ) );
        if ( '' !== trim( (string) $base_url ) && class_exists( 'Design_Core_Elementor_Asset_URL_Resolver' ) ) {
            list( $html, $css, $resolved ) = Design_Core_Elementor_Asset_URL_Resolver::resolve( $html, $css, $base_url );
            $observer->event( 'preflight', 'assets-resolved', array( 'resolved' => (int) $resolved ) );
        }
        $analysis = $this->analyze( $html, $css );
        if ( ! empty( $analysis['error'] ) ) { return $this->failure( $observer, $transaction, 'analysis', $analysis['message'] ?? $analysis['error'] ); }
        $observer->event( 'analysis', 'passed', array( 'nodes' => count( $analysis['design_ir']['nodes'] ?? array() ) ) );

        $capabilities = ( new Design_Core_Elementor_Capability_Scanner() )->scan();
        $settings = class_exists( 'Design_Core_Elementor_Settings' ) ? Design_Core_Elementor_Settings::get_conversion_rules() : array();
        $v4_adapter = new Design_Core_Elementor_V4_Adapter();
        $requested_mode = 'v4' === ( $capabilities['elementor']['editor_mode'] ?? 'v3' ) && ! empty( $capabilities['capabilities']['atomic_build_composition'] ) && $v4_adapter->supports( 'save' ) && $v4_adapter->supports( 'reload' ) && $v4_adapter->supports( 'render' ) ? 'v4' : 'v3';

        try {
            ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $analysis['design_ir'] );
            $observer->event( 'ir-validation', 'passed', array() );
            // Section identity is calculated from the source presentation before any component master may alter descendants.
            $ir = $this->normalizer->normalize( $analysis['design_ir'], $css );
            $observer->event( 'normalization', 'passed', array() );
            // Component blueprints are applied only to non-section descendants when Variant_Engine proves exact reuse.
            $ir = $this->apply_registry_reuse( $ir );
            $observer->event( 'reuse-search', 'passed', array() );
            if ( ! empty( $settings['import_assets'] ) ) { $ir = $this->import_assets( $ir, $transaction ); }
            $plan = $this->planner->plan( $ir, 'v4' === $requested_mode ? 'elementor-v4' : 'elementor-v3' );
            $observer->event( 'decision', 'passed', array( 'strategies' => array_column( $plan['items'], 'strategy' ) ) );
            $observer->event( 'planning', 'passed', array( 'items' => count( $plan['items'] ) ) );
        } catch ( Throwable $exception ) { return $this->failure( $observer, $transaction, 'contracts', $exception->getMessage() ); }

        $adapter = 'v4' === $requested_mode ? $v4_adapter : new Design_Core_Elementor_V3_Adapter();
        try { $execution = $this->executor->execute( $plan, array( 'ir' => $ir, 'adapter' => $adapter ) ); }
        catch ( Throwable $exception ) {
            return $this->failure( $observer, $transaction, 'execution', $exception->getMessage() );
        }
        if ( 'success' !== ( $execution['status'] ?? '' ) || empty( $execution['elements'] ) ) { return $this->failure( $observer, $transaction, 'execution', $execution['diagnostics']['reason'] ?? 'No executable elements were produced.', array( 'execution' => $execution ) ); }
        $observer->event( 'execution', 'passed', array( 'elements' => count( $execution['elements'] ) ) );
        $native_fidelity = Design_Core_Elementor_Native_Fidelity_Report::from_execution( $execution );
        $observer->event( 'native-fidelity', 'passed', array( 'native_rate' => $native_fidelity['native_rate'], 'native_items' => $native_fidelity['native_items'], 'fallback_items' => $native_fidelity['fallback_items'] ) );

        $global_adapter = 'v4' === $requested_mode ? new Design_Core_Elementor_V4_Global_Adapter() : new Design_Core_Elementor_V3_Global_Adapter();
        $global_sync = $global_adapter->sync( ( new Design_Core_Elementor_Design_Token_Service() )->all() );
        $transaction->track_kit_mutation();
        if ( is_wp_error( $global_sync ) ) { return $this->failure( $observer, $transaction, 'globals', $global_sync->get_error_message() ); }
        $observer->event( 'globals', 'passed', array( 'mode' => $global_sync['mode'] ?? '' ) );
        if ( method_exists( $global_adapter, 'apply_references' ) ) { $execution['elements'] = $global_adapter->apply_references( $execution['elements'], $global_sync ); }

        $post_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => sanitize_text_field( $page_title ), 'post_content' => '', 'post_status' => ! empty( $settings['create_as_draft'] ) ? 'draft' : 'publish' ) );
        if ( is_wp_error( $post_id ) || ! $post_id ) { return $this->failure( $observer, $transaction, 'save', 'Page creation failed.' ); }
        $transaction->track_post( $post_id );
        $saved = $adapter->save( $post_id, $execution['elements'], array() );
        if ( is_wp_error( $saved ) ) { return $this->failure( $observer, $transaction, 'save', $saved->get_error_message() ); }
        $atomic_fallback_reason = 'v4' === $requested_mode ? (string) get_post_meta( $post_id, '_design_core_elementor_atomic_fallback', true ) : '';
        $actual_mode = $atomic_fallback_reason ? 'v3' : $requested_mode;
        $observer->event( 'save', 'passed', array( 'page_id' => $post_id, 'editor_mode' => $actual_mode ) );

        $runtime_adapter = $atomic_fallback_reason ? new Design_Core_Elementor_V3_Adapter() : $adapter;
        $reloaded = $runtime_adapter->reload( $post_id );
        if ( empty( $reloaded ) ) { return $this->failure( $observer, $transaction, 'reload', 'Elementor reload evidence failed.' ); }
        $observer->event( 'reload', 'passed', array( 'page_id' => $post_id, 'elements' => count( $reloaded ) ) );
        $rendered = $runtime_adapter->render( $post_id );
        if ( '' === trim( $rendered ) ) { return $this->failure( $observer, $transaction, 'render', 'Elementor render evidence failed.' ); }
        $observer->event( 'render', 'passed', array( 'page_id' => $post_id, 'bytes' => strlen( $rendered ) ) );
        $post_write_gate = class_exists( 'Design_Core_Elementor_Strict_Structure_Gate' ) ? Design_Core_Elementor_Strict_Structure_Gate::audit_elements( is_array( $reloaded ) ? $reloaded : array() ) : array( 'status' => 'unavailable' );
        $observer->event( 'post-write-verification', (string) ( $post_write_gate['status'] ?? 'unavailable' ), array( 'page_id' => $post_id, 'material' => count( (array) ( $post_write_gate['material'] ?? array() ) ) ) );
        if ( 'fail' === ( $post_write_gate['status'] ?? '' ) ) { return $this->failure( $observer, $transaction, 'post-write-verification', 'Persisted tree failed strict structural verification.', array( 'strict_gate' => $post_write_gate ) ); }

        $qa = ( new Design_Core_Elementor_Visual_QA() )->audit_page( $post_id );
        if ( 'fail' === ( $qa['status'] ?? '' ) ) { return $this->failure( $observer, $transaction, 'structural-qa', 'Structural QA failed.' ); }
        $observer->event( 'structural-qa', 'passed', array( 'page_id' => $post_id ) );

        try { $reuse = $this->synchronize_reuse( $ir, $post_id ); }
        catch ( Throwable $exception ) {
            return $this->failure( $observer, $transaction, 'registry', $exception->getMessage() );
        }
        $observer->event( 'registry-sync', 'passed', array( 'page_id' => $post_id, 'registered' => count( $reuse ) ) );

        $sections = array(); $page_manifest = array();
        if ( class_exists( 'Design_Core_Elementor_Section_Intelligence' ) ) {
            try {
                $sections = ( new Design_Core_Elementor_Section_Intelligence() )->synchronize( $ir, (int) $post_id );
                $page_manifest = Design_Core_Elementor_Page_Manifest::from_sync( $sections, (int) $post_id, $page_title );
                Design_Core_Elementor_Page_Manifest::validate( $page_manifest );
                update_post_meta( (int) $post_id, '_design_core_page_manifest', $page_manifest );
                if ( get_post_meta( (int) $post_id, '_design_core_page_manifest', true ) !== $page_manifest ) { throw new RuntimeException( 'Page Manifest persistence failed.' ); }
                $observer->event( 'section-registry-sync', 'passed', array( 'page_id' => $post_id, 'registered' => count( $sections ) ) );
            } catch ( Throwable $exception ) {
                return $this->failure( $observer, $transaction, 'section-registry', $exception->getMessage() );
            }
        }

        foreach ( $execution['diagnostics']['item_results'] ?? array() as $item_result ) {
            if ( ! empty( $item_result['fallback_used'] ) ) {
                $observer->event( 'planning', 'fallback', array(
                    'node_id' => $item_result['strategy'] ?? '',
                    'requested_strategy' => $item_result['diagnostics']['requested_strategy'] ?? '',
                    'actual_strategy' => $item_result['diagnostics']['actual_strategy'] ?? '',
                    'reason' => $item_result['diagnostics']['reason'] ?? '',
                    'error_code' => $item_result['diagnostics']['error_code'] ?? '',
                ) );
            }
        }
        if ( class_exists( 'Design_Core_Elementor_Runtime_Evidence' ) ) { ( new Design_Core_Elementor_Runtime_Evidence() )->record( 'elementor-save-reload-render', 'pass', array( 'post_id' => $post_id, 'elements' => count( $reloaded ) ), 'conversion-service' ); }
        $transaction->commit();
        $observer->event( 'commit', 'passed', array( 'page_id' => $post_id ) )->persist();

        return array(
            'page_id' => (int) $post_id, 'status' => 'success', 'elements' => count( $execution['elements'] ), 'analysis' => $analysis,
            'design_ir' => $ir, 'build_plan' => $plan, 'execution' => $execution, 'reuse' => $reuse, 'sections' => $sections, 'page_manifest' => $page_manifest,
            'global_sync' => $global_sync, 'qa' => $qa, 'requested_editor_mode' => $requested_mode, 'editor_mode' => $actual_mode,
            'native_fidelity' => $native_fidelity,
            'atomic_fallback_reason' => $atomic_fallback_reason, 'conversion_id' => $observer->id(),
        );
    }

    private function apply_registry_reuse( $ir ) {
        $registry = new Design_Core_Elementor_Component_Registry();
        $variant_engine = new Design_Core_Elementor_Variant_Engine();
        $index_by_id = array(); foreach ( $ir['nodes'] as $index => $node ) { $index_by_id[ $node['id'] ] = $index; }
        foreach ( $ir['nodes'] as $index => $node ) {
            if ( ! empty( $node['section']['reusable'] ) ) { continue; }
            if ( empty( $node['component']['reusable'] ) || empty( $node['semantic']['component_type'] ) ) { continue; }
            $fingerprint = (array) ( $node['component']['fingerprint'] ?? array() );
            $candidate = array(
                'fingerprint' => $fingerprint, 'type' => sanitize_key( $node['semantic']['component_type'] ),
                'content_schema' => $node['component']['content_schema'] ?? array(), 'signature' => $fingerprint['structure'] ?? '',
                'layout' => $node['layout'] ?? array(), 'style' => $node['style'] ?? array(), 'spacing' => $node['spacing'] ?? array(), 'responsive' => $node['responsive'] ?? array(),
            );
            $matches = $registry->find_similar( $candidate );
            if ( ! $matches ) { continue; }
            $master = $matches[0];
            $classification = $variant_engine->classify( $candidate, array( 'score' => 1.0, 'item' => $master, 'reasons' => array( 'semantic-structure-match' ) ) );
            if ( 'reuse' !== ( $classification['action'] ?? '' ) ) { continue; }
            $matched_master = is_array( $classification['item'] ?? null ) ? $classification['item'] : $master;
            $blueprint = $matched_master['master']['structure']['blueprint'] ?? null;
            $candidate_ir = $ir;
            if ( is_array( $blueprint ) && $this->apply_blueprint( $candidate_ir, $index, $index_by_id, $blueprint, $matched_master['id'] ) ) { $ir = $candidate_ir; }
        }
        return $ir;
    }

    private function apply_blueprint( &$ir, $index, $index_by_id, $blueprint, $master_id ) {
        $node = $ir['nodes'][ $index ];
        if ( sanitize_key( $node['source']['tag'] ?? '' ) !== sanitize_key( $blueprint['source']['tag'] ?? '' ) ) { return false; }
        $blueprint_children = $blueprint['children'] ?? array();
        if ( ! is_array( $blueprint_children ) || count( $blueprint_children ) !== count( $node['children'] ) ) { return false; }
        foreach ( $node['children'] as $offset => $child_id ) {
            if ( ! isset( $index_by_id[ $child_id ] ) || ! $this->apply_blueprint( $ir, $index_by_id[ $child_id ], $index_by_id, $blueprint_children[ $offset ], $master_id ) ) { return false; }
        }
        $instance_style = (array) ( $node['style'] ?? array() );
        foreach ( array( 'semantic', 'layout', 'style', 'spacing', 'responsive', 'interaction' ) as $field ) {
            if ( isset( $blueprint[ $field ] ) && is_array( $blueprint[ $field ] ) ) { $ir['nodes'][ $index ][ $field ] = $blueprint[ $field ]; }
        }
        $this->restore_instance_style_assets( $instance_style, $ir['nodes'][ $index ]['style'] );
        if ( isset( $blueprint['source']['classes'] ) && is_array( $blueprint['source']['classes'] ) ) {
            $ir['nodes'][ $index ]['source']['classes'] = array_values( array_unique( array_merge( $blueprint['source']['classes'], (array) ( $node['source']['classes'] ?? array() ) ) ) );
        }
        $ir['nodes'][ $index ]['component']['master_id'] = $master_id;
        return true;
    }

    private function restore_instance_style_assets( array $source_style, array &$target_style ) {
        if ( array_key_exists( 'background_image', $source_style ) ) { $target_style['background_image'] = $source_style['background_image']; }
        $source_fallback = is_array( $source_style['css_fallback'] ?? null ) ? $source_style['css_fallback'] : array();
        if ( $source_fallback ) {
            if ( ! isset( $target_style['css_fallback'] ) || ! is_array( $target_style['css_fallback'] ) ) { $target_style['css_fallback'] = array(); }
            foreach ( array( 'background-image', 'background_image' ) as $key ) {
                if ( array_key_exists( $key, $source_fallback ) ) { $target_style['css_fallback'][ $key ] = $source_fallback[ $key ]; }
            }
        }
    }

    private function capture_blueprint( $node, $nodes_by_id ) {
        $children = array();
        foreach ( $node['children'] as $child_id ) { if ( isset( $nodes_by_id[ $child_id ] ) ) { $children[] = $this->capture_blueprint( $nodes_by_id[ $child_id ], $nodes_by_id ); } }
        return array(
            'source' => array( 'tag' => $node['source']['tag'], 'classes' => $node['source']['classes'] ),
            'semantic' => $node['semantic'], 'layout' => $node['layout'], 'style' => $node['style'], 'spacing' => $node['spacing'],
            'responsive' => $node['responsive'], 'interaction' => $node['interaction'], 'children' => $children,
        );
    }

    private function import_assets( $ir, $transaction ) {
        $importer = new Design_Core_Elementor_Asset_Importer( $transaction ); $imported = array();
        foreach ( $ir['nodes'] as $index => $node ) {
            foreach ( $this->remote_asset_slots( $node ) as $slot => $url ) {
                if ( ! isset( $imported[ $url ] ) ) {
                    $imported[ $url ] = $importer->import_image_url( $url );
                    if ( ! empty( $imported[ $url ]['error'] ) ) { throw new RuntimeException( 'Asset import failed: ' . sanitize_key( $imported[ $url ]['error'] ) ); }
                }
                $asset = $imported[ $url ];
                if ( empty( $asset['id'] ) ) { continue; }
                if ( 'content_image' === $slot ) { $ir['nodes'][ $index ]['content']['image'] = array_replace( $node['content']['image'], array( 'id' => (int) $asset['id'], 'url' => $asset['url'] ) ); }
                if ( 'background_image' === $slot ) { $ir['nodes'][ $index ]['style']['background_image'] = array_replace( (array) ( $node['style']['background_image'] ?? array() ), array( 'id' => (int) $asset['id'], 'url' => $asset['url'] ) ); }
            }
        }
        return $ir;
    }

    private function remote_asset_slots( array $node ) {
        $slots = array( 'content_image' => $node['content']['image']['url'] ?? '', 'background_image' => $node['style']['background_image']['url'] ?? '' );
        return array_filter( $slots, static function ( $url ) { return is_string( $url ) && preg_match( '#^https?://#i', $url ); } );
    }

    private function synchronize_reuse( $ir, $location ) {
        $registry = new Design_Core_Elementor_Component_Registry();
        $reuse_engine = new Design_Core_Elementor_Reuse_Engine();
        $variant_engine = new Design_Core_Elementor_Variant_Engine();
        $registered = array(); $nodes_by_id = array();
        foreach ( $ir['nodes'] as $candidate_node ) { $nodes_by_id[ $candidate_node['id'] ] = $candidate_node; }

        foreach ( $ir['nodes'] as $node ) {
            if ( ! empty( $node['section']['reusable'] ) ) { continue; }
            if ( empty( $node['component']['reusable'] ) || empty( $node['semantic']['component_type'] ) ) { continue; }
            $fingerprint = (array) $node['component']['fingerprint'];
            $candidate = array(
                'fingerprint' => $fingerprint, 'type' => sanitize_key( $node['semantic']['component_type'] ),
                'content_schema' => $node['component']['content_schema'] ?? array(), 'signature' => $fingerprint['structure'] ?? '',
                'layout' => $node['layout'] ?? array(), 'style' => $node['style'] ?? array(), 'spacing' => $node['spacing'] ?? array(), 'responsive' => $node['responsive'] ?? array(),
            );
            $matches = $registry->find_similar( $candidate );
            $family = $matches ? array( 'score' => 1.0, 'item' => $matches[0], 'reasons' => array( 'semantic-structure-match' ) ) : $reuse_engine->find_best_match( $candidate, $registry->all() );
            $classification = $variant_engine->classify( $candidate, $family );
            $action = $classification['action'] ?? 'new';
            $variant_id = sanitize_key( $classification['variant'] ?? '' );

            if ( 'reuse' === $action && ! empty( $family['item']['id'] ) ) {
                $master = is_array( $classification['item'] ?? null ) ? $classification['item'] : $family['item'];
                if ( empty( $master['master']['structure']['blueprint'] ) ) {
                    $master['master']['structure']['blueprint'] = $this->capture_blueprint( $node, $nodes_by_id );
                    $master = $registry->upsert( $master );
                    if ( is_wp_error( $master ) ) { throw new RuntimeException( $master->get_error_message() ); }
                }
            } elseif ( 'variant' === $action && ! empty( $family['item']['id'] ) ) {
                $master_id = $family['item']['id'];
                $variant_id = $variant_id ?: 'variant';
                $definition = array(
                    'fingerprint' => $fingerprint,
                    'presentation_hash' => (string) ( $classification['presentation_hash'] ?? $variant_engine->presentation_hash( $candidate ) ),
                    'blueprint' => $this->capture_blueprint( $node, $nodes_by_id ),
                );
                if ( ! $registry->add_variant( $master_id, $variant_id, $definition ) ) { throw new RuntimeException( 'Variant registration failed.' ); }
                $master = $registry->get( $master_id );
                if ( ! $master ) { throw new RuntimeException( 'Variant registration lost its component master.' ); }
            } else {
                $now = gmdate( 'c' );
                $master = array(
                    'id' => 'component-' . substr( ( new Design_Core_Elementor_Fingerprint_Service() )->lookup_hash( $fingerprint ), 0, 12 ),
                    'type' => 'component', 'schema_version' => 2, 'item_version' => 1, 'created_at' => $now, 'updated_at' => $now,
                    'source' => array( 'kind' => 'design-ir', 'node_id' => $node['id'] ), 'usage' => array( 'count' => 0, 'locations' => array() ),
                    'fingerprint' => $fingerprint,
                    'master' => array(
                        'structure' => array( 'root_tag' => $node['source']['tag'], 'blueprint' => $this->capture_blueprint( $node, $nodes_by_id ) ),
                        'layout' => $node['layout'], 'shared_styles' => $node['style'], 'spacing' => $node['spacing'],
                        'responsive_rules' => $node['responsive'], 'editable_schema' => $node['component']['content_schema'],
                    ),
                    'instances' => array(), 'variants' => array(),
                );
                $master = $registry->upsert( $master );
                if ( is_wp_error( $master ) ) { throw new RuntimeException( $master->get_error_message() ); }
                $variant_id = '';
            }

            $bindings = array( 'text' => $node['content']['text'], 'rich_text' => $node['content']['rich_text'], 'link' => $node['content']['link'] );
            $asset_bindings = array( 'image' => $node['content']['image'], 'background_image' => $node['style']['background_image'] ?? array() );
            $allowed_overrides = array( 'node_id' => (string) $node['id'] );
            if ( $variant_id ) { $allowed_overrides['variant_id'] = $variant_id; }
            $instance = $registry->register_instance( $master['id'], $bindings, (int) $location, $asset_bindings, $allowed_overrides );
            if ( is_wp_error( $instance ) ) { throw new RuntimeException( $instance->get_error_message() ); }
            $registered[] = array( 'master_id' => $master['id'], 'instance_id' => $instance['id'], 'action' => $action, 'variant_id' => $variant_id, 'node_id' => (string) $node['id'] );
        }
        return $registered;
    }

    private function failure( $observer, $transaction, $stage, $message, $extra = array() ) {
        $rollback = $transaction->rollback();
        $observer->event( $stage, 'failed', array( 'error' => $message, 'rollback' => $rollback ) )->persist();
        return array_merge( array( 'page_id' => 0, 'status' => 'failed', 'error' => $message, 'rollback' => $rollback, 'conversion_id' => $observer->id() ), $extra );
    }

    /**
     * Executes an already-approved, hash-locked BuildPlan against an EXISTING page,
     * for the rc21 remote-update workflow. Reuses the same executor/adapter/persistence/
     * registry-sync primitives as convert() -- it never re-plans and never re-applies
     * registry reuse or asset import, because both of those happen to the IR *before*
     * convert() plans it. Re-running them here, after the plan was already hashed and
     * approved, could silently change what gets built without changing plan_hash. Preview
     * never applied them either (Build_Plan_Preview::preview_ir() does not call
     * apply_registry_reuse()/import_assets()), so this keeps execution consistent with
     * exactly what was shown and approved; it costs some reuse fidelity that convert()
     * would otherwise apply, tracked as a known follow-up rather than a silent gap.
     */
    public function execute_approved_plan( $post_id, array $ir, array $plan, array $options = array() ) {
        $post_id = (int) $post_id;
        $observer = new Design_Core_Elementor_Observability();
        $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
        if ( $post_id <= 0 || ! get_post( $post_id ) ) { return $this->failure( $observer, $transaction, 'preflight', 'Target page does not exist.' ); }
        $observer->event( 'preflight', 'started', array( 'page_id' => $post_id ) );

        // Captured before any mutation so a later Change_Ledger::augment_context() call (once
        // this same write's new manifest is known, further below) can carry an exact before/
        // after pair -- the page manifest is synchronized by THIS method, after the Elementor
        // save (and its own Change_Ledger entry) already completed, so it isn't visible to
        // Persistence_Service's own context capture the way _elementor_data/post_content are.
        $page_manifest_exists_before = metadata_exists( 'post', $post_id, '_design_core_page_manifest' );
        $page_manifest_before = array(
            'exists' => $page_manifest_exists_before,
            'value' => $page_manifest_exists_before ? get_post_meta( $post_id, '_design_core_page_manifest', true ) : null,
        );

        try {
            ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
            ( new Design_Core_Elementor_Build_Plan_Validator() )->validate( $plan, $ir );
            $observer->event( 'contract-validation', 'passed', array() );
        } catch ( Throwable $exception ) { return $this->failure( $observer, $transaction, 'contracts', $exception->getMessage() ); }

        $capabilities = ( new Design_Core_Elementor_Capability_Scanner() )->scan();
        $v4_adapter = new Design_Core_Elementor_V4_Adapter();
        $use_v4 = 'elementor-v4' === sanitize_key( (string) ( $options['adapter_target'] ?? 'elementor-v3' ) )
            && ! empty( $capabilities['capabilities']['atomic_build_composition'] )
            && $v4_adapter->supports( 'save' ) && $v4_adapter->supports( 'reload' ) && $v4_adapter->supports( 'render' );
        $adapter = $use_v4 ? $v4_adapter : new Design_Core_Elementor_V3_Adapter();

        try { $execution = $this->executor->execute( $plan, array( 'ir' => $ir, 'adapter' => $adapter ) ); }
        catch ( Throwable $exception ) {
            return $this->failure( $observer, $transaction, 'execution', $exception->getMessage() );
        }
        if ( 'success' !== ( $execution['status'] ?? '' ) || empty( $execution['elements'] ) ) { return $this->failure( $observer, $transaction, 'execution', $execution['diagnostics']['reason'] ?? 'No executable elements were produced.', array( 'execution' => $execution ) ); }
        $observer->event( 'execution', 'passed', array( 'elements' => count( $execution['elements'] ) ) );
        $native_fidelity = Design_Core_Elementor_Native_Fidelity_Report::from_execution( $execution );
        $observer->event( 'native-fidelity', 'passed', array( 'native_rate' => $native_fidelity['native_rate'], 'native_items' => $native_fidelity['native_items'], 'fallback_items' => $native_fidelity['fallback_items'] ) );

        $global_adapter = $use_v4 ? new Design_Core_Elementor_V4_Global_Adapter() : new Design_Core_Elementor_V3_Global_Adapter();
        $global_sync = $global_adapter->sync( ( new Design_Core_Elementor_Design_Token_Service() )->all() );
        $transaction->track_kit_mutation();
        if ( is_wp_error( $global_sync ) ) { return $this->failure( $observer, $transaction, 'globals', $global_sync->get_error_message() ); }
        $observer->event( 'globals', 'passed', array( 'mode' => $global_sync['mode'] ?? '' ) );
        if ( method_exists( $global_adapter, 'apply_references' ) ) { $execution['elements'] = $global_adapter->apply_references( $execution['elements'], $global_sync ); }

        // CRITICAL: Snapshot existing post BEFORE any mutation. On failure, post will be restored, never deleted.
        $transaction->track_existing_post_mutation( $post_id );
        $saved = $adapter->save( $post_id, $execution['elements'], array() );
        if ( is_wp_error( $saved ) ) { return $this->failure( $observer, $transaction, 'save', $saved->get_error_message() ); }
        // Record what OUR OWN write just produced so a later-stage failure can prove
        // ownership before restoring BEFORE -- see Conversion_Transaction's class docblock.
        $transaction->mark_existing_post_expected_state( $post_id );
        // Test-only, bounded fault injection for stage-coverage tests: no callback is ever
        // registered outside a test bootstrap, so this is a no-op in every real deployment
        // and is not reachable through any remote API surface.
        $injected_failure = apply_filters( 'design_core_elementor_test_fail_after_stage', false, 'after-save', $post_id );
        if ( is_wp_error( $injected_failure ) ) { return $this->failure( $observer, $transaction, 'test-injected-failure', $injected_failure->get_error_message() ); }
        $atomic_fallback_reason = $use_v4 ? (string) get_post_meta( $post_id, '_design_core_elementor_atomic_fallback', true ) : '';
        $actual_mode = $atomic_fallback_reason ? 'v3' : ( $use_v4 ? 'v4' : 'v3' );
        $observer->event( 'save', 'passed', array( 'page_id' => $post_id, 'editor_mode' => $actual_mode ) );
        $persistence_evidence = method_exists( $adapter, 'last_persistence_evidence' ) ? (array) $adapter->last_persistence_evidence() : array();

        $runtime_adapter = $atomic_fallback_reason ? new Design_Core_Elementor_V3_Adapter() : $adapter;
        $reloaded = $runtime_adapter->reload( $post_id );
        if ( empty( $reloaded ) ) { return $this->failure( $observer, $transaction, 'reload', 'Elementor reload evidence failed.' ); }
        $observer->event( 'reload', 'passed', array( 'page_id' => $post_id, 'elements' => count( $reloaded ) ) );
        $rendered = $runtime_adapter->render( $post_id );
        if ( '' === trim( $rendered ) ) { return $this->failure( $observer, $transaction, 'render', 'Elementor render evidence failed.' ); }
        $observer->event( 'render', 'passed', array( 'page_id' => $post_id, 'bytes' => strlen( $rendered ) ) );
        $post_write_gate = class_exists( 'Design_Core_Elementor_Strict_Structure_Gate' ) ? Design_Core_Elementor_Strict_Structure_Gate::audit_elements( is_array( $reloaded ) ? $reloaded : array() ) : array( 'status' => 'unavailable' );
        $observer->event( 'post-write-verification', (string) ( $post_write_gate['status'] ?? 'unavailable' ), array( 'page_id' => $post_id, 'material' => count( (array) ( $post_write_gate['material'] ?? array() ) ) ) );
        if ( 'fail' === ( $post_write_gate['status'] ?? '' ) ) { return $this->failure( $observer, $transaction, 'post-write-verification', 'Persisted tree failed strict structural verification.', array( 'strict_gate' => $post_write_gate ) ); }

        $qa = ( new Design_Core_Elementor_Visual_QA() )->audit_page( $post_id );
        if ( 'fail' === ( $qa['status'] ?? '' ) ) { return $this->failure( $observer, $transaction, 'structural-qa', 'Structural QA failed.' ); }
        $observer->event( 'structural-qa', 'passed', array( 'page_id' => $post_id ) );

        try { $reuse = $this->synchronize_reuse( $ir, $post_id ); }
        catch ( Throwable $exception ) {
            return $this->failure( $observer, $transaction, 'registry', $exception->getMessage() );
        }
        $observer->event( 'registry-sync', 'passed', array( 'page_id' => $post_id, 'registered' => count( $reuse ) ) );

        $sections = array(); $page_manifest = array();
        if ( class_exists( 'Design_Core_Elementor_Section_Intelligence' ) ) {
            try {
                $sections = ( new Design_Core_Elementor_Section_Intelligence() )->synchronize( $ir, (int) $post_id );
                $page_manifest = Design_Core_Elementor_Page_Manifest::from_sync( $sections, (int) $post_id, get_the_title( $post_id ) );
                Design_Core_Elementor_Page_Manifest::validate( $page_manifest );
                update_post_meta( (int) $post_id, '_design_core_page_manifest', $page_manifest );
                if ( get_post_meta( (int) $post_id, '_design_core_page_manifest', true ) !== $page_manifest ) { throw new RuntimeException( 'Page Manifest persistence failed.' ); }
                // Best-effort enrichment of the Elementor-save history entry this same request
                // already created (see Persistence_Service::save_and_verify()) so its governed
                // rollback can restore the page manifest too, not just _elementor_data/post_content
                // -- see the capture at the top of this method for why it can't be known any
                // earlier. A missing history_entry_id (e.g. the save didn't change the hash, so
                // no entry was recorded) or a failed augment is not itself an error: an older/
                // entry-less manifest simply stays outside this particular rollback's tracked fields.
                $history_entry_id = (string) ( $persistence_evidence['history_entry_id'] ?? '' );
                if ( '' !== $history_entry_id && class_exists( 'Design_Core_Elementor_Change_Ledger' ) ) {
                    ( new Design_Core_Elementor_Change_Ledger() )->augment_context( $history_entry_id, array(
                        'page_manifest_before' => $page_manifest_before,
                        'page_manifest_after' => array( 'exists' => true, 'value' => $page_manifest ),
                    ) );
                }
                $observer->event( 'section-registry-sync', 'passed', array( 'page_id' => $post_id, 'registered' => count( $sections ) ) );
            } catch ( Throwable $exception ) {
                return $this->failure( $observer, $transaction, 'section-registry', $exception->getMessage() );
            }
        }

        if ( class_exists( 'Design_Core_Elementor_Runtime_Evidence' ) ) { ( new Design_Core_Elementor_Runtime_Evidence() )->record( 'elementor-save-reload-render', 'pass', array( 'post_id' => $post_id, 'elements' => count( $reloaded ) ), 'remote-update' ); }
        $transaction->commit();
        $observer->event( 'commit', 'passed', array( 'page_id' => $post_id ) )->persist();

        return array(
            'page_id' => (int) $post_id, 'status' => 'success', 'elements' => count( $execution['elements'] ), 'execution' => $execution,
            'reuse' => $reuse, 'sections' => $sections, 'page_manifest' => $page_manifest, 'global_sync' => $global_sync, 'qa' => $qa,
            'native_fidelity' => $native_fidelity,
            'editor_mode' => $actual_mode, 'atomic_fallback_reason' => $atomic_fallback_reason, 'conversion_id' => $observer->id(),
            'history_entry_id' => (string) ( $persistence_evidence['history_entry_id'] ?? '' ),
            'before_hash' => (string) ( $persistence_evidence['before_hash'] ?? '' ),
            'after_hash' => (string) ( $persistence_evidence['after_hash'] ?? '' ),
        );
    }
}
