<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Owner API -> WordPress Abilities transport adapter.
 *
 * The 42 operation names, schemas and capabilities come from the Owner API
 * contract. Only the controller-method mapping lives here. REST Bearer auth is
 * NOT reused by an in-process ability: the connector authenticates a WordPress
 * user, and this bridge independently requires the configured API owner.
 *
 * Do not add business logic, raw Elementor writes or connector credentials here.
 */
class Design_Core_Elementor_MCP_Ability_Bridge {
    const VERSION = 2;
    const MAX_INPUT_BYTES = 2097152;

    /** Exact public callbacks on the existing Owner REST controller. */
    const OPERATION_METHODS = array(
        'getManifest' => 'get_manifest',
        'getSiteStatus' => 'get_site_status',
        'understandSite' => 'post_understand',
        'getSiteMap' => 'get_site_map',
        'getSiteDesignSystem' => 'get_site_design_system',
        'searchSiteContent' => 'get_search_content',
        'getElementorCapabilities' => 'get_elementor_capabilities',
        'getElementorCatalog' => 'get_elementor_catalog',
        'searchElementorWidgets' => 'post_widget_search',
        'getElementorWidgetSchema' => 'get_widget_schema',
        'getMediaLibrary' => 'get_media_library',
        'planTask' => 'post_plan_task',
        'getDesignIntelligenceStatus' => 'get_design_status',
        'recommendDesign' => 'post_design_recommend',
        'previewDesignSystem' => 'post_design_preview',
        'enrichDesignIR' => 'post_design_enrich_ir',
        'auditPageUX' => 'get_ux_audit',
        'previewFigma' => 'post_figma_preview',
        'previewBuild' => 'post_build_preview',
        'createDraftPage' => 'post_create_page',
        'getPageSnapshot' => 'get_page_snapshot',
        'applyPageBuild' => 'post_apply_page',
        'verifyPage' => 'post_verify_page',
        'visualFeedback' => 'post_visual_feedback',
        'autoCorrectPage' => 'post_auto_correct',
        'publishPage' => 'post_publish_page',
        'getHistory' => 'get_history',
        'rollbackHistory' => 'post_rollback',
        'listWordPressContent' => 'get_wp_content_index',
        'getWordPressContent' => 'get_wp_content',
        'createWordPressContent' => 'post_wp_content_create',
        'updateWordPressContent' => 'post_wp_content_update',
        'trashWordPressContent' => 'post_wp_content_trash',
        'restoreWordPressContent' => 'post_wp_content_restore',
        'getWordPressSettings' => 'get_wp_settings',
        'updateWordPressSettings' => 'post_wp_settings',
        'getWordPressMenus' => 'get_wp_menus',
        'upsertWordPressMenuItem' => 'post_wp_menu_item',
        'trashWordPressMenuItem' => 'post_wp_menu_item_trash',
        'importWordPressMedia' => 'post_wp_media_import',
        'updateWordPressMedia' => 'post_wp_media_update',
        'trashWordPressMedia' => 'post_wp_media_trash',
    );

    /** REST field => ability-facing field. Preserve the original page aliases. */
    const FIELD_ALIASES = array(
        'getPageSnapshot' => array( 'id' => 'page_id' ),
        'auditPageUX' => array( 'id' => 'page_id' ),
        'applyPageBuild' => array( 'id' => 'page_id' ),
        'verifyPage' => array( 'id' => 'page_id' ),
        'visualFeedback' => array( 'id' => 'page_id' ),
        'autoCorrectPage' => array( 'id' => 'page_id' ),
        'publishPage' => array( 'id' => 'page_id' ),
        'getWordPressContent' => array( 'id' => 'content_id' ),
        'updateWordPressContent' => array( 'id' => 'content_id' ),
        'trashWordPressContent' => array( 'id' => 'content_id' ),
        'restoreWordPressContent' => array( 'id' => 'content_id' ),
        'upsertWordPressMenuItem' => array( 'id' => 'menu_id' ),
        'trashWordPressMenuItem' => array( 'id' => 'menu_id', 'item' => 'item_id' ),
        'updateWordPressMedia' => array( 'id' => 'media_id' ),
        'trashWordPressMedia' => array( 'id' => 'media_id' ),
    );

    private static $controller = null;
    private static $operations_by_id = null;

    public function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) { return; }
        foreach ( self::operations() as $operation_id => $operation ) {
            if ( ! isset( self::OPERATION_METHODS[ $operation_id ] ) ) { continue; }
            wp_register_ability( 'design-core/' . self::ability_slug( $operation_id ), array(
                'label' => $operation['summary'],
                'description' => self::description_for( $operation ),
                'category' => 'design-core',
                'execute_callback' => function ( $input = array() ) use ( $operation_id ) {
                    if ( null === $input ) { $input = array(); }
                    if ( ! is_array( $input ) ) {
                        return new WP_Error( 'design_core_ability_invalid_input', 'Ability input must be an object.', array( 'status' => 400 ) );
                    }
                    return self::execute( $operation_id, $input );
                },
                'permission_callback' => function () use ( $operation ) {
                    return self::permission( $operation['capability'] );
                },
                'input_schema' => self::input_schema_for( $operation_id, $operation ),
                'meta' => array(
                    'show_in_rest' => false,
                    'mcp' => array( 'public' => true, 'type' => 'tool' ),
                    'design_core_operation_id' => $operation_id,
                    'design_core_capability' => $operation['capability'],
                    'annotations' => self::annotations_for( $operation_id, $operation ),
                ),
            ) );
        }
    }

    /**
     * Ability gate: the SAME catalog capability, enforced by the centralized
     * Permissions service. Owner management (claim/enable/mint) stays locked in
     * API_Access_Settings and never gates ability execution here.
     */
    public static function permission( $capability ) {
        if ( ! class_exists( 'Design_Core_Elementor_Permissions' ) ) {
            return new WP_Error( 'design_core_ability_unavailable', 'Design Core permission service is unavailable.', array( 'status' => 503 ) );
        }
        return Design_Core_Elementor_Permissions::check_ability_capability( $capability );
    }

    public static function ability_names() {
        $names = array();
        foreach ( array_keys( self::OPERATION_METHODS ) as $operation_id ) {
            $names[ $operation_id ] = 'design-core/' . self::ability_slug( $operation_id );
        }
        return $names;
    }

    public static function ability_slug( $operation_id ) {
        $slug = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', (string) $operation_id );
        $slug = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1-$2', $slug );
        return str_replace( 'word-press', 'wordpress', strtolower( $slug ) );
    }

    private static function description_for( array $operation ) {
        $suffix = $operation['read_only']
            ? ' Does not modify site content; previews may store temporary tickets.'
            : ' Changes site state; requires explicit user approval and confirm=true.';
        return $operation['summary'] . $suffix . ' Requires an authenticated WordPress user with capability: ' . $operation['capability'] . '.';
    }

    public static function annotations_for( $operation_id, array $operation ) {
        $read_only = (bool) $operation['read_only'];
        // Conservatively flag every mutation. Optional idempotency keys do not
        // make an operation unconditionally idempotent.
        return array(
            'readonly' => $read_only,
            'destructive' => ! $read_only,
            'idempotent' => $read_only,
            'readOnlyHint' => $read_only,
            'destructiveHint' => ! $read_only,
            'idempotentHint' => $read_only,
            'openWorldHint' => in_array( $operation_id, array( 'previewFigma', 'visualFeedback', 'autoCorrectPage', 'importWordPressMedia' ), true ),
        );
    }

    /** Combine path/query and body fields without accepting client auth context. */
    public static function input_schema_for( $operation_id, array $operation ) {
        $aliases = self::FIELD_ALIASES[ $operation_id ] ?? array();
        $properties = array();
        $required = array();
        foreach ( (array) $operation['parameters'] as $param ) {
            $name = $aliases[ $param['name'] ] ?? $param['name'];
            $properties[ $name ] = $param['schema'];
            if ( ! empty( $param['required'] ) ) { $required[] = $name; }
        }
        if ( is_array( $operation['body_schema'] ) ) {
            foreach ( (array) ( $operation['body_schema']['properties'] ?? array() ) as $key => $schema ) {
                $properties[ $aliases[ $key ] ?? $key ] = $schema;
            }
            foreach ( (array) ( $operation['body_schema']['required'] ?? array() ) as $key ) {
                $required[] = $aliases[ $key ] ?? $key;
            }
        }
        $schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
        $required = array_values( array_unique( $required ) );
        if ( $required ) { $schema['required'] = $required; }
        return $schema;
    }

    private static function unalias_input( $operation_id, array $input ) {
        $ability_to_rest = array_flip( self::FIELD_ALIASES[ $operation_id ] ?? array() );
        $out = array();
        foreach ( $input as $key => $value ) { $out[ $ability_to_rest[ $key ] ?? $key ] = $value; }
        return $out;
    }

    private static function operations() {
        if ( null === self::$operations_by_id ) {
            self::$operations_by_id = array();
            foreach ( Design_Core_Elementor_GPT_Actions_API::operations() as $operation ) {
                self::$operations_by_id[ $operation['operationId'] ] = $operation;
            }
        }
        return self::$operations_by_id;
    }

    private static function controller() {
        if ( null === self::$controller ) { self::$controller = new Design_Core_Elementor_GPT_Actions_Rest_Controller(); }
        return self::$controller;
    }

    /** Read-only diagnostics: code parity is NOT proof of connector grants. */
    public static function contract_report() {
        $api_ids = array_keys( self::operations() );
        $bridge_ids = array_keys( self::OPERATION_METHODS );
        $missing = array_values( array_diff( $api_ids, $bridge_ids ) );
        $stale = array_values( array_diff( $bridge_ids, $api_ids ) );
        $invalid = array();
        foreach ( self::OPERATION_METHODS as $id => $method ) {
            if ( ! is_callable( array( self::controller(), $method ) ) ) { $invalid[] = $id; }
        }
        return array(
            'version' => self::VERSION,
            'owner_operation_count' => count( $api_ids ),
            'mapped_operation_count' => count( $bridge_ids ),
            'missing_operations' => $missing,
            'stale_operations' => $stale,
            'invalid_callbacks' => $invalid,
            'parity' => ! $missing && ! $stale && ! $invalid,
            'authentication' => 'connector-wordpress-user-with-design-core-capability',
            'bearer_token_used' => false,
            'connector_grants_verified' => false,
            'discovery_note' => 'Registration does not grant NHI/OAuth access; verify discovery through the connected client.',
        );
    }

    /**
     * The public execute entry point repeats permission/schema checks so direct
     * calls cannot bypass WP_Ability::execute(). Controller write guards remain
     * authoritative. This does not invoke the REST Bearer permission callback.
     */
    public static function execute( $operation_id, array $input ) {
        $operations = self::operations();
        if ( ! isset( $operations[ $operation_id ], self::OPERATION_METHODS[ $operation_id ] ) ) {
            return new WP_Error( 'design_core_ability_unknown', 'Unknown Design Core ability operation.', array( 'status' => 404 ) );
        }
        $operation = $operations[ $operation_id ];
        $permission = self::permission( $operation['capability'] );
        if ( is_wp_error( $permission ) ) { return $permission; }

        $encoded = wp_json_encode( $input );
        if ( false === $encoded ) {
            return new WP_Error( 'design_core_ability_invalid_input', 'Ability input is not valid JSON.', array( 'status' => 400 ) );
        }
        if ( strlen( $encoded ) > self::MAX_INPUT_BYTES ) {
            return new WP_Error( 'design_core_ability_payload_too_large', 'Ability input exceeds 2 MB.', array( 'status' => 413 ) );
        }
        if ( ! function_exists( 'rest_validate_value_from_schema' ) ) {
            return new WP_Error( 'design_core_ability_validator_unavailable', 'WordPress schema validation is unavailable.', array( 'status' => 503 ) );
        }
        $valid = rest_validate_value_from_schema( $input, self::input_schema_for( $operation_id, $operation ), 'input' );
        if ( is_wp_error( $valid ) ) { return $valid; }
        if ( ! $operation['read_only'] && true !== ( $input['confirm'] ?? false ) ) {
            return new WP_Error( 'design_core_confirmation_required', 'Mutation requires confirm=true.', array( 'status' => 400 ) );
        }

        $rest_input = self::unalias_input( $operation_id, $input );
        $route = (string) $operation['path'];
        $path = array();
        $query = array();
        $body = array();
        foreach ( (array) $operation['parameters'] as $param ) {
            $name = $param['name'];
            if ( ! array_key_exists( $name, $rest_input ) ) { continue; }
            if ( 'path' === $param['in'] ) {
                $path[ $name ] = $rest_input[ $name ];
                $route = str_replace( '{' . $name . '}', rawurlencode( (string) $rest_input[ $name ] ), $route );
            } elseif ( 'query' === $param['in'] ) {
                $query[ $name ] = $rest_input[ $name ];
            }
        }
        foreach ( (array) ( $operation['body_schema']['properties'] ?? array() ) as $name => $schema ) {
            if ( array_key_exists( $name, $rest_input ) ) { $body[ $name ] = $rest_input[ $name ]; }
        }
        if ( preg_match( '/\{[a-zA-Z_]+\}/', $route ) ) {
            return new WP_Error( 'design_core_ability_path_required', 'A required route parameter is missing.', array( 'status' => 400 ) );
        }
        $request = new WP_REST_Request( $operation['method'], '/' . Design_Core_Elementor_GPT_Actions_API::REST_NAMESPACE . $route );
        $request->set_url_params( $path );
        $request->set_query_params( $query );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ? $body : (object) array() ) );
        if ( ! empty( $body['idempotency_key'] ) ) { $request->set_header( 'idempotency-key', $body['idempotency_key'] ); }

        // Never accept scopes, owner ID, environment or principal from the model.
        // Generic WordPress publish checks inspect these server-derived scopes.
        $scopes = array_values( array_filter( Design_Core_Elementor_Capabilities::all(), 'current_user_can' ) );
        $request->set_param( '_design_core_principal', array(
            'type' => 'user',
            'id' => get_current_user_id(),
            'owner_user_id' => get_current_user_id(),
            'scopes' => $scopes,
            'environment' => Design_Core_Elementor_Remote_Settings::environment(),
        ) );

        $method = self::OPERATION_METHODS[ $operation_id ];
        if ( ! is_callable( array( self::controller(), $method ) ) ) {
            return new WP_Error( 'design_core_ability_callback_unavailable', 'The mapped Owner API callback is unavailable.', array( 'status' => 503 ) );
        }
        $result = self::controller()->{$method}( $request );
        if ( is_wp_error( $result ) ) { return $result; }
        if ( $result instanceof WP_REST_Response ) { $result = $result->get_data(); }
        if ( in_array( $operation_id, array( 'getManifest', 'getSiteStatus' ), true ) && is_array( $result ) ) {
            $result['mcp_bridge'] = self::contract_report();
        }
        return $result;
    }
}
