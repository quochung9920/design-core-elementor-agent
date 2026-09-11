<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Stable API-only contract intended for ChatGPT GPT Actions and other REST clients. */
final class Design_Core_Elementor_GPT_Actions_API {
    const VERSION = '1.0';
    const REST_NAMESPACE = 'design-core/v1';

    public static function operations() {
        $read = Design_Core_Elementor_Capabilities::READ;
        $preview = Design_Core_Elementor_Capabilities::PREVIEW;
        $build = Design_Core_Elementor_Capabilities::BUILD;
        $modify = Design_Core_Elementor_Capabilities::MODIFY;
        $publish = Design_Core_Elementor_Capabilities::PUBLISH;
        $rollback = Design_Core_Elementor_Capabilities::ROLLBACK;

        return array(
            self::op( 'getManifest', 'GET', '/manifest', $read, true, 'Read the owner API manifest and security state.' ),
            self::op( 'getSiteStatus', 'GET', '/site/status', $read, true, 'Read WordPress, Elementor, Elementor Pro and Design Core runtime status.' ),
            self::op( 'understandSite', 'POST', '/understand', $read, true, 'Read site map, design system, Elementor capabilities and a task plan in one mutation-free call.', self::schema_understand() ),
            self::op( 'getSiteMap', 'GET', '/site/map', $read, true, 'Read bounded site structure, pages, menus, templates, theme and registry counts.', null, array( self::query_int( 'limit', 1, 200, false ) ) ),
            self::op( 'getSiteDesignSystem', 'GET', '/site/design-system', $read, true, 'Read Design Core tokens, active Elementor Kit settings and runtime breakpoints.' ),
            self::op( 'searchSiteContent', 'GET', '/site/search', $read, true, 'Search WordPress content and Design Core reusable registries.', null, array( self::query_string( 'q', true ), self::query_string( 'types', false ), self::query_int( 'limit', 1, 50, false ) ) ),
            self::op( 'getElementorCapabilities', 'GET', '/elementor/capabilities', $read, true, 'Read live Elementor/Elementor Pro capabilities and registered widget coverage.' ),
            self::op( 'getElementorCatalog', 'GET', '/elementor/catalog', $read, true, 'Read the live Elementor widget catalog.', null, array( self::query_string( 'source', false ), self::query_string( 'q', false ), self::query_int( 'limit', 1, 200, false ) ) ),
            self::op( 'searchElementorWidgets', 'POST', '/elementor/widgets/search', $read, true, 'Rank live Elementor widgets against structured requirements.', self::schema_widget_search() ),
            self::op( 'getElementorWidgetSchema', 'GET', '/elementor/widgets/{widget}', $read, true, 'Read the runtime control schema for one Elementor widget.', null, array( self::path_string( 'widget' ), self::query_bool( 'detail' ) ) ),
            self::op( 'getMediaLibrary', 'GET', '/media', $read, true, 'Search the WordPress media library.', null, array( self::query_string( 'q', false ), self::query_string( 'mime', false ), self::query_int( 'limit', 1, 50, false ) ) ),
            self::op( 'planTask', 'POST', '/tasks/plan', $read, true, 'Create a mutation-free Design Core task plan grounded in the live site.', self::schema_plan_task() ),
            self::op( 'getDesignIntelligenceStatus', 'GET', '/design/status', $read, true, 'Read Design Intelligence catalog/runtime status.' ),
            self::op( 'recommendDesign', 'POST', '/design/recommend', $preview, true, 'Recommend a governed Design Core design profile from a brief.', self::schema_design_recommend() ),
            self::op( 'previewDesignSystem', 'POST', '/design/preview', $preview, true, 'Compile a design recommendation into a mutation-free Design Core preview ticket.', self::schema_design_preview() ),
            self::op( 'enrichDesignIR', 'POST', '/design/enrich-ir', $preview, true, 'Enrich Design IR with a governed Design Intelligence profile without mutating WordPress.', self::schema_enrich_ir() ),
            self::op( 'auditPageUX', 'GET', '/pages/{id}/ux-audit', $read, true, 'Run the Design Core UX quality auditor against one page.', null, array( self::path_int( 'id' ) ) ),
            self::op( 'previewFigma', 'POST', '/figma/preview', $preview, true, 'Convert configured Figma input into a mutation-free Design Core preview.', self::schema_figma_preview() ),
            self::op( 'previewBuild', 'POST', '/build/preview', $preview, true, 'Compile HTML/CSS or Design IR into a mutation-free BuildPlan preview.', self::schema_build_preview() ),
            self::op( 'createDraftPage', 'POST', '/pages', $build, false, 'Create one new draft WordPress page without writing Elementor storage.', self::schema_create_page(), array(), true ),
            self::op( 'getPageSnapshot', 'GET', '/pages/{id}', $read, true, 'Read a bounded Design Core/Elementor page snapshot.', null, array( self::path_int( 'id' ) ) ),
            self::op( 'applyPageBuild', 'POST', '/pages/{id}/apply', $modify, false, 'Execute the exact approved preview ticket against one Elementor page.', self::schema_apply_page(), array( self::path_int( 'id' ) ), true ),
            self::op( 'verifyPage', 'POST', '/pages/{id}/verify', $read, true, 'Read post state, Design Core snapshot, UX audit and history after a build.', self::schema_empty(), array( self::path_int( 'id' ) ) ),
            self::op( 'visualFeedback', 'POST', '/pages/{id}/visual-feedback', $preview, true, 'Compare a page candidate against a reference target without mutation.', self::schema_visual_feedback(), array( self::path_int( 'id' ) ) ),
            self::op( 'autoCorrectPage', 'POST', '/pages/{id}/auto-correct', $modify, false, 'Apply an approved bounded visual correction through Design Core.', self::schema_auto_correct(), array( self::path_int( 'id' ) ), true ),
            self::op( 'publishPage', 'POST', '/pages/{id}/publish', $publish, false, 'Publish one explicitly approved page.', self::schema_confirm_idempotent(), array( self::path_int( 'id' ) ), true ),
            self::op( 'getHistory', 'GET', '/history', $read, true, 'Read the Design Core change ledger.' ),
            self::op( 'rollbackHistory', 'POST', '/history/{entry}/rollback', $rollback, false, 'Rollback one rollback-eligible Design Core history entry.', self::schema_confirm_idempotent(), array( self::path_string( 'entry' ) ), true ),

            // Bounded WordPress owner operations. These never write private Elementor storage.
            self::op( 'listWordPressContent', 'GET', '/wordpress/content', $read, true, 'List WordPress posts/pages/CPT content.', null, array( self::query_string( 'post_type', false ), self::query_string( 'status', false ), self::query_string( 'search', false ), self::query_int( 'limit', 1, 100, false ) ) ),
            self::op( 'getWordPressContent', 'GET', '/wordpress/content/{id}', $read, true, 'Read one WordPress content item and whether Elementor manages it.', null, array( self::path_int( 'id' ) ) ),
            self::op( 'createWordPressContent', 'POST', '/wordpress/content', $build, false, 'Create a non-Elementor WordPress post/page/CPT item.', self::schema_wp_content_create(), array(), true ),
            self::op( 'updateWordPressContent', 'POST', '/wordpress/content/{id}', $modify, false, 'Update bounded WordPress content fields; post_content is blocked when Elementor manages the item.', self::schema_wp_content_update(), array( self::path_int( 'id' ) ), true ),
            self::op( 'trashWordPressContent', 'POST', '/wordpress/content/{id}/trash', $modify, false, 'Move a WordPress content item to Trash. Current front page needs an extra confirmation.', self::schema_wp_trash(), array( self::path_int( 'id' ) ), true ),
            self::op( 'restoreWordPressContent', 'POST', '/wordpress/content/{id}/restore', $modify, false, 'Restore a WordPress content item from Trash.', self::schema_confirm_idempotent(), array( self::path_int( 'id' ) ), true ),
            self::op( 'getWordPressSettings', 'GET', '/wordpress/settings', $read, true, 'Read bounded site identity/front-page/date-time settings.' ),
            self::op( 'updateWordPressSettings', 'POST', '/wordpress/settings', $publish, false, 'Update bounded WordPress site identity/front-page/date-time settings.', self::schema_wp_settings(), array(), true ),
            self::op( 'getWordPressMenus', 'GET', '/wordpress/menus', $read, true, 'Read WordPress navigation menus and items.' ),
            self::op( 'upsertWordPressMenuItem', 'POST', '/wordpress/menus/{id}/items', $modify, false, 'Create or update one navigation menu item.', self::schema_menu_item(), array( self::path_int( 'id' ) ), true ),
            self::op( 'trashWordPressMenuItem', 'POST', '/wordpress/menus/{id}/items/{item}', $modify, false, 'Move one navigation menu item to Trash.', self::schema_confirm_idempotent(), array( self::path_int( 'id' ), self::path_int( 'item' ) ), true ),
            self::op( 'importWordPressMedia', 'POST', '/wordpress/media/import', $build, false, 'Import one public remote media URL into the WordPress media library with SSRF and size guards.', self::schema_media_import(), array(), true ),
            self::op( 'updateWordPressMedia', 'POST', '/wordpress/media/{id}', $modify, false, 'Update attachment title, alt text, caption or description.', self::schema_media_update(), array( self::path_int( 'id' ) ), true ),
            self::op( 'trashWordPressMedia', 'POST', '/wordpress/media/{id}/trash', $modify, false, 'Move one attachment to Trash.', self::schema_confirm_idempotent(), array( self::path_int( 'id' ) ), true ),
        );
    }

    public static function manifest() {
        $ops = self::operations();
        return array(
            'name' => 'Design Core Owner API for ChatGPT',
            'api_version' => self::VERSION,
            'rest_namespace' => self::REST_NAMESPACE,
            'transport' => 'HTTPS REST only',
            'mcp_required' => false,
            'operation_count' => count( $ops ),
            'authentication' => array(
                'type' => 'bearer',
                'token_prefix' => Design_Core_Elementor_API_Credential_Registry::TOKEN_PREFIX,
                'owner_locked' => true,
                'cookie_fallback' => false,
            ),
            'security' => array(
                'owner_user_id' => Design_Core_Elementor_API_Access_Settings::owner_user_id(),
                'enabled' => Design_Core_Elementor_API_Access_Settings::enabled(),
                'environment' => Design_Core_Elementor_Remote_Settings::environment(),
                'remote_writes_enabled' => Design_Core_Elementor_Remote_Settings::writes_enabled(),
                'scoped_credentials' => true,
                'credential_expiry' => true,
                'rate_limited' => true,
                'preview_plan_hash_gate' => true,
                'idempotency_supported' => true,
                'conflict_detection' => true,
                'rollback' => true,
                'no_direct_elementor_storage_mutation' => true,
            ),
            'operations' => array_map( static function ( $op ) {
                return array(
                    'operationId' => $op['operationId'],
                    'method' => $op['method'],
                    'path' => $op['path'],
                    'capability' => $op['capability'],
                    'read_only' => $op['read_only'],
                    'consequential' => $op['consequential'],
                    'summary' => $op['summary'],
                );
            }, $ops ),
        );
    }

    public static function openapi( $server_url = '' ) {
        if ( '' === $server_url ) {
            if ( defined( 'DESIGN_CORE_API_PUBLIC_URL' ) && '' !== trim( (string) DESIGN_CORE_API_PUBLIC_URL ) ) {
                $server_url = trim( (string) DESIGN_CORE_API_PUBLIC_URL );
            } else {
                $server_url = function_exists( 'rest_url' ) ? rest_url( self::REST_NAMESPACE ) : '/wp-json/' . self::REST_NAMESPACE;
            }
        }
        $paths = array();
        foreach ( self::operations() as $op ) {
            $entry = array(
                'operationId' => $op['operationId'],
                'summary' => $op['summary'],
                'description' => $op['summary'] . ' Required scope: ' . $op['capability'] . '.',
                'security' => array( array( 'ownerApiKey' => array() ) ),
                'responses' => self::responses(),
                'x-design-core-capability' => $op['capability'],
                'x-design-core-read-only' => $op['read_only'],
                'x-openai-isConsequential' => $op['consequential'],
            );
            if ( $op['parameters'] ) { $entry['parameters'] = $op['parameters']; }
            if ( $op['body_schema'] ) {
                $entry['requestBody'] = array(
                    'required' => true,
                    'content' => array( 'application/json' => array( 'schema' => $op['body_schema'] ) ),
                );
            }
            $path = $op['path'];
            $method = strtolower( $op['method'] );
            if ( ! isset( $paths[ $path ] ) ) { $paths[ $path ] = array(); }
            $paths[ $path ][ $method ] = $entry;
        }

        return array(
            'openapi' => '3.1.0',
            'info' => array(
                'title' => 'Design Core Owner API',
                'version' => self::VERSION,
                'description' => 'Owner-locked REST API for ChatGPT GPT Actions. It provides Design Core/Elementor workflows plus bounded WordPress content, media, menu and site-setting management. MCP is not required.',
            ),
            'servers' => array( array( 'url' => untrailingslashit( (string) $server_url ) ) ),
            'components' => array(
                'securitySchemes' => array(
                    'ownerApiKey' => array(
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'dcapi',
                        'description' => 'Owner-only Design Core API credential created in WordPress admin.',
                    ),
                ),
            ),
            'paths' => $paths,
        );
    }

    private static function op( $operation_id, $method, $path, $capability, $read_only, $summary, $body_schema = null, array $parameters = array(), $consequential = false ) {
        return array(
            'operationId' => $operation_id,
            'method' => strtoupper( (string) $method ),
            'path' => (string) $path,
            'capability' => sanitize_key( (string) $capability ),
            'read_only' => (bool) $read_only,
            'consequential' => (bool) $consequential,
            'summary' => (string) $summary,
            'body_schema' => $body_schema,
            'parameters' => $parameters,
        );
    }

    private static function responses() {
        return array(
            '200' => array( 'description' => 'Successful Design Core response.' ),
            '400' => array( 'description' => 'Invalid request or missing explicit confirmation.' ),
            '401' => array( 'description' => 'Missing, invalid, expired or revoked owner API credential.' ),
            '403' => array( 'description' => 'Owner mismatch or insufficient Design Core scope.' ),
            '409' => array( 'description' => 'Conflict, stale preview, protected Elementor content, or unavailable capability.' ),
            '423' => array( 'description' => 'Owner API or remote writes are disabled.' ),
            '429' => array( 'description' => 'Rate limit exceeded.' ),
        );
    }

    private static function object_schema( array $properties = array(), array $required = array() ) {
        return array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false );
    }
    private static function str( $description = '', $max = null ) { $x = array( 'type' => 'string' ); if ( $description ) { $x['description'] = $description; } if ( $max ) { $x['maxLength'] = $max; } return $x; }
    private static function integer( $minimum = 0 ) { return array( 'type' => 'integer', 'minimum' => $minimum ); }
    private static function boolean() { return array( 'type' => 'boolean' ); }
    private static function string_array() { return array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ); }
    private static function any_object() { return array( 'type' => 'object', 'additionalProperties' => true ); }
    private static function query_string( $name, $required ) { return array( 'name'=>$name, 'in'=>'query', 'required'=>(bool)$required, 'schema'=>array( 'type'=>'string' ) ); }
    private static function query_int( $name, $min, $max, $required ) { return array( 'name'=>$name, 'in'=>'query', 'required'=>(bool)$required, 'schema'=>array( 'type'=>'integer', 'minimum'=>$min, 'maximum'=>$max ) ); }
    private static function query_bool( $name ) { return array( 'name'=>$name, 'in'=>'query', 'required'=>false, 'schema'=>array( 'type'=>'boolean' ) ); }
    private static function path_int( $name ) { return array( 'name'=>$name, 'in'=>'path', 'required'=>true, 'schema'=>array( 'type'=>'integer', 'minimum'=>1 ) ); }
    private static function path_string( $name ) { return array( 'name'=>$name, 'in'=>'path', 'required'=>true, 'schema'=>array( 'type'=>'string', 'minLength'=>1 ) ); }

    private static function schema_empty() { return self::object_schema(); }
    private static function schema_understand() { return self::object_schema( array( 'brief'=>self::str( 'What the user wants to do.', 16384 ), 'page_id'=>self::integer( 0 ), 'site_map_limit'=>array( 'type'=>'integer', 'minimum'=>1, 'maximum'=>200 ) ), array( 'brief' ) ); }
    private static function schema_plan_task() { return self::object_schema( array( 'brief'=>self::str( '', 16384 ), 'page_id'=>self::integer( 0 ) ), array( 'brief' ) ); }
    private static function schema_widget_search() { return self::object_schema( array( 'query'=>self::str( '', 1000 ), 'intent'=>self::str(), 'capabilities'=>self::string_array(), 'controls'=>self::string_array(), 'keywords'=>self::string_array(), 'limit'=>array( 'type'=>'integer', 'minimum'=>1, 'maximum'=>50 ) ) ); }
    private static function schema_design_recommend() { return self::object_schema( array( 'brief'=>self::str( '', 16384 ), 'product_type'=>self::str(), 'mode'=>self::str(), 'variance'=>self::integer(), 'motion'=>self::integer(), 'density'=>self::integer(), 'max_rules'=>array( 'type'=>'integer', 'minimum'=>1, 'maximum'=>100 ) ), array( 'brief' ) ); }
    private static function schema_design_preview() { return self::object_schema( array( 'brief'=>self::str( '', 16384 ), 'page_id'=>self::integer( 0 ), 'product_type'=>self::str(), 'mode'=>self::str(), 'bindings'=>self::any_object(), 'adapter_target'=>self::str() ), array( 'brief' ) ); }
    private static function schema_enrich_ir() { return self::object_schema( array( 'design_ir'=>self::any_object(), 'brief'=>self::str( '', 16384 ), 'profile'=>self::any_object() ), array( 'design_ir' ) ); }
    private static function schema_figma_preview() { return self::object_schema( array( 'page_id'=>self::integer( 0 ), 'figma_url'=>self::str(), 'node_id'=>self::str(), 'adapter_target'=>self::str(), 'instructions'=>self::str( '', 4000 ) ), array( 'figma_url' ) ); }
    private static function schema_build_preview() { return self::object_schema( array( 'page_id'=>self::integer( 0 ), 'design_ir'=>self::any_object(), 'html'=>self::str(), 'css'=>self::str(), 'adapter_target'=>self::str() ) ); }
    private static function schema_create_page() { return self::object_schema( array( 'title'=>self::str( '', 200 ), 'slug'=>self::str( '', 200 ), 'parent_id'=>self::integer( 1 ), 'confirm'=>array( 'type'=>'boolean', 'enum'=>array( true ) ), 'idempotency_key'=>self::str( 'Reuse the same key if retrying the exact same action.', 200 ) ), array( 'title', 'confirm' ) ); }
    private static function schema_apply_page() { return self::object_schema( array( 'preview_id'=>self::str(), 'plan_hash'=>self::str(), 'confirm'=>array( 'type'=>'boolean', 'enum'=>array( true ) ), 'idempotency_key'=>self::str( '', 200 ) ), array( 'preview_id', 'plan_hash', 'confirm' ) ); }
    private static function schema_visual_feedback() { return self::object_schema( array( 'reference_target'=>self::str(), 'reference_url'=>self::str(), 'candidate_target'=>self::str(), 'target_similarity'=>array( 'type'=>'number', 'minimum'=>0, 'maximum'=>1 ) ) ); }
    private static function schema_auto_correct() { return self::object_schema( array( 'feedback'=>self::any_object(), 'corrections'=>array( 'type'=>'array', 'items'=>self::any_object() ), 'confirm'=>array( 'type'=>'boolean', 'enum'=>array( true ) ), 'idempotency_key'=>self::str( '', 200 ) ), array( 'confirm' ) ); }
    private static function schema_confirm_idempotent() { return self::object_schema( array( 'confirm'=>array( 'type'=>'boolean', 'enum'=>array( true ) ), 'idempotency_key'=>self::str( '', 200 ) ), array( 'confirm' ) ); }
    private static function schema_wp_content_create() { return self::object_schema( array( 'post_type'=>self::str(), 'title'=>self::str( '', 300 ), 'slug'=>self::str(), 'status'=>self::str(), 'content'=>self::str(), 'excerpt'=>self::str(), 'parent_id'=>self::integer( 0 ), 'menu_order'=>self::integer(), 'elementor'=>self::boolean(), 'confirm'=>array( 'type'=>'boolean', 'enum'=>array( true ) ), 'idempotency_key'=>self::str( '', 200 ) ), array( 'title', 'confirm' ) ); }
    private static function schema_wp_content_update() { return self::object_schema( array( 'title'=>self::str(), 'slug'=>self::str(), 'status'=>self::str(), 'content'=>self::str(), 'excerpt'=>self::str(), 'parent_id'=>self::integer(), 'menu_order'=>self::integer(), 'expected_modified_gmt'=>self::str(), 'confirm'=>array( 'type'=>'boolean', 'enum'=>array( true ) ), 'idempotency_key'=>self::str( '', 200 ) ), array( 'confirm' ) ); }
    private static function schema_wp_trash() { return self::object_schema( array( 'expected_modified_gmt'=>self::str(), 'confirm_front_page'=>self::boolean(), 'confirm'=>array( 'type'=>'boolean', 'enum'=>array( true ) ), 'idempotency_key'=>self::str( '', 200 ) ), array( 'confirm' ) ); }
    private static function schema_wp_settings() { return self::object_schema( array( 'blogname'=>self::str(), 'blogdescription'=>self::str(), 'show_on_front'=>array( 'type'=>'string', 'enum'=>array( 'posts', 'page' ) ), 'page_on_front'=>self::integer(), 'page_for_posts'=>self::integer(), 'timezone_string'=>self::str(), 'date_format'=>self::str(), 'time_format'=>self::str(), 'confirm'=>array( 'type'=>'boolean', 'enum'=>array( true ) ), 'idempotency_key'=>self::str( '', 200 ) ), array( 'confirm' ) ); }
    private static function schema_menu_item() { return self::object_schema( array( 'item_id'=>self::integer(), 'title'=>self::str(), 'url'=>self::str(), 'type'=>self::str(), 'object'=>self::str(), 'object_id'=>self::integer(), 'parent_id'=>self::integer(), 'position'=>self::integer(), 'confirm'=>array( 'type'=>'boolean', 'enum'=>array( true ) ), 'idempotency_key'=>self::str( '', 200 ) ), array( 'confirm' ) ); }
    private static function schema_media_import() { return self::object_schema( array( 'url'=>self::str(), 'title'=>self::str(), 'alt'=>self::str(), 'parent_id'=>self::integer(), 'confirm'=>array( 'type'=>'boolean', 'enum'=>array( true ) ), 'idempotency_key'=>self::str( '', 200 ) ), array( 'url', 'confirm' ) ); }
    private static function schema_media_update() { return self::object_schema( array( 'title'=>self::str(), 'alt'=>self::str(), 'caption'=>self::str(), 'description'=>self::str(), 'confirm'=>array( 'type'=>'boolean', 'enum'=>array( true ) ), 'idempotency_key'=>self::str( '', 200 ) ), array( 'confirm' ) ); }
}
