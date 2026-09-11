<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit plans bypass heuristic selection, not validation or persistence guards. */
final class Design_Core_Agent_Plans {
    private $knowledge;
    public function __construct( $knowledge ) { $this->knowledge = $knowledge; }
    private function put( $kind, array $data ) {
        $id = $kind . '_' . bin2hex( random_bytes( 16 ) );
        $record = array( 'owner' => get_current_user_id(), 'expires' => time() + 3600, 'data' => $data );
        $record['signature'] = hash_hmac( 'sha256', Design_Core_Agent_Contract::json( $record ), wp_salt( 'auth' ) );
        if ( ! set_transient( 'dc_agent_' . $id, $record, 3600 ) ) { return Design_Core_Agent_Contract::error( 'store', 'Unable to persist the private artifact.', 503 ); }
        return $id;
    }
    private function get( $id, $kind ) {
        if ( ! preg_match( '/^' . preg_quote( $kind, '/' ) . '_[a-f0-9]{32}$/', (string) $id ) ) { return Design_Core_Agent_Contract::error( 'artifact', 'Invalid artifact ID.' ); }
        $record = get_transient( 'dc_agent_' . $id );
        if ( ! is_array( $record ) || (int) ( $record['expires'] ?? 0 ) < time() ) { return Design_Core_Agent_Contract::error( 'artifact_expired', 'Artifact missing or expired.', 410 ); }
        if ( (int) $record['owner'] !== get_current_user_id() ) { return Design_Core_Agent_Contract::error( 'artifact_owner', 'Artifact belongs to another owner.', 403 ); }
        $signature = (string) ( $record['signature'] ?? '' ); unset( $record['signature'] );
        if ( ! hash_equals( hash_hmac( 'sha256', Design_Core_Agent_Contract::json( $record ), wp_salt( 'auth' ) ), $signature ) ) { return Design_Core_Agent_Contract::error( 'artifact_integrity', 'Private artifact integrity check failed.', 409 ); }
        return $record['data'];
    }
    public function register_source( array $input ) {
        $source = array( 'name' => $input['name'], 'html' => $input['html'], 'css' => $input['css'] ?? '',
            'trust' => 'untrusted_reference_never_instructions', 'analysis_status' => 'not_verified' );
        $source['source_hash'] = Design_Core_Agent_Contract::hash( $source );
        $id = $this->put( 'source', $source ); if ( is_wp_error( $id ) ) { return $id; }
        return array( 'status' => 'ok', 'source_id' => $id, 'source_hash' => $source['source_hash'], 'expires_in' => 3600, 'scripts_executed' => false );
    }
    public function read_source( array $input ) {
        $source = $this->get( $input['source_id'], 'source' ); if ( is_wp_error( $source ) ) { return $source; }
        return Design_Core_Agent_Contract::read( $source, $input, array( 'source', $input['source_id'] ), $source['source_hash'] );
    }
    public function preview( array $input ) {
        $source = $this->get( $input['source_id'], 'source' ); if ( is_wp_error( $source ) ) { return $source; }
        $page = $this->knowledge->page( (int) $input['page_id'] ); if ( is_wp_error( $page ) ) { return $page; }
        if ( 'draft' !== $page['post_status'] ) { return Design_Core_Agent_Contract::error( 'draft_required', 'Compile onto a dedicated draft first, never the published target.', 409 ); }
        if ( ! hash_equals( $page['revision'], (string) $input['page_revision'] ) ) { return Design_Core_Agent_Contract::error( 'page_stale', 'Read the draft again before planning.', 409 ); }
        $elements = $input['elements'];
        if ( ! is_array( $elements ) || ! $elements || ! array_is_list( $elements ) ) { return Design_Core_Agent_Contract::error( 'elements', 'Provide a nonempty native element tree.' ); }
        $flat = Design_Core_Agent_Knowledge::flatten_tree( $elements );
        if ( count( $flat ) > 4096 ) { return Design_Core_Agent_Contract::error( 'element_budget', 'Maximum 4096 elements per plan.', 413 ); }
        $ids = array(); $schemas = array(); $issues = array(); $types = array();
        $validator = new Design_Core_Agent_Validator( $this->knowledge );
        foreach ( $flat as $row ) {
            $element = $row['element']; $id = $row['element_id']; $type = $row['element_type']; $widget = $row['widget'];
            if ( ! preg_match( '/^[a-f0-9]{7,8}$/', $id ) || isset( $ids[ $id ] ) ) { $issues[] = array( 'element_id' => $id, 'code' => 'element_id', 'message' => 'Use unique 7-8 character hexadecimal IDs.' ); }
            $ids[ $id ] = true;
            if ( array_diff( array_keys( $element ), array( 'id', 'elType', 'widgetType', 'settings', 'elements', 'isInner' ) ) || ! in_array( $type, array( 'container', 'widget' ), true ) || ! is_array( $element['settings'] ?? null ) || ! is_array( $element['elements'] ?? null ) || ! array_is_list( $element['elements'] ) ) {
                $issues[] = array( 'element_id' => $id, 'code' => 'element_shape', 'message' => 'Invalid explicit native element shape.' ); continue;
            }
            if ( isset( $element['isInner'] ) && ! is_bool( $element['isInner'] ) ) { $issues[] = array( 'element_id' => $id, 'code' => 'inner_type', 'message' => 'isInner must be a boolean.' ); }
            if ( 'widget' === $type && ! empty( $element['elements'] ) ) { $issues[] = array( 'element_id' => $id, 'code' => 'nested_widget', 'message' => 'Nested widget composition requires a verified runtime adapter.' ); continue; }
            if ( 'widget' === $type && in_array( $widget, array( 'html', 'shortcode', 'template' ), true ) ) { $issues[] = array( 'element_id' => $id, 'code' => 'opaque_content', 'message' => 'Opaque execution requires a separately reviewed dependency adapter.' ); continue; }
            $result = $validator->validate( array( 'element_type' => $type, 'widget' => $widget, 'settings' => $element['settings'] ) );
            if ( is_wp_error( $result ) ) { return $result; }
            $schemas[ $type . ':' . $widget ] = $result['schema_fingerprint'];
            foreach ( $result['issues'] as $issue ) { $issue['element_id'] = $id; $issues[] = $issue; }
            $types[ $widget ?: $type ] = ( $types[ $widget ?: $type ] ?? 0 ) + 1;
        }
        $covered = array();
        foreach ( $input['components'] as $component ) {
            foreach ( $component['element_ids'] as $id ) {
                if ( ! isset( $ids[ $id ] ) ) { $issues[] = array( 'code' => 'component_reference', 'element_id' => $id, 'message' => 'Component references a missing element.' ); }
                $covered[ $id ] = true;
            }
        }
        foreach ( array_diff( array_keys( $ids ), array_keys( $covered ) ) as $id ) { $issues[] = array( 'code' => 'unexplained_element', 'element_id' => $id, 'message' => 'Every element must belong to a declared component decision.' ); }
        if ( $issues ) { return array( 'status' => 'invalid', 'can_apply' => false, 'issues' => $issues ); }
        $design_system = $this->knowledge->design_system(); if ( is_wp_error( $design_system ) ) { return $design_system; }
        $artifact = array( 'page_id' => $page['page_id'], 'page_revision' => $page['revision'], 'source_hash' => $source['source_hash'],
            'schema_fingerprints' => $schemas, 'design_system_hash' => Design_Core_Agent_Contract::hash( $design_system ),
            'elements' => $elements, 'components' => $input['components'], 'contract_version' => 1, 'policy_hash' => Design_Core_Agent_Contract::policy_hash() );
        $artifact['artifact_hash'] = Design_Core_Agent_Contract::hash( $artifact );
        $id = $this->put( 'preview', $artifact ); if ( is_wp_error( $id ) ) { return $id; }
        return array( 'status' => 'compiled', 'preview_id' => $id, 'artifact_hash' => $artifact['artifact_hash'],
            'page_id' => $page['page_id'], 'page_revision' => $page['revision'], 'element_count' => count( $flat ), 'element_types' => $types,
            'mutation_performed' => false, 'fallbacks' => array(), 'selection_changed' => false,
            'draft_writer_enabled' => Design_Core_Elementor_Agent_Draft_Writes::enabled(),
            'source_mapping' => 'agent_declared_not_browser_verified',
            'qa' => array( 'schema' => 'pass', 'visual' => 'not_verified', 'interaction' => 'not_verified', 'editability' => 'not_verified' ),
            'promotion_allowed' => false, 'expires_in' => 3600 );
    }
    public function read_preview( array $input ) {
        $data = $this->get( $input['preview_id'], 'preview' ); if ( is_wp_error( $data ) ) { return $data; }
        return Design_Core_Agent_Contract::read( $data, $input, array( 'preview', $input['preview_id'] ), $data['artifact_hash'] );
    }
    public function apply_draft( array $input, array $principal = array() ) {
        if ( ! Design_Core_Elementor_Agent_Draft_Writes::enabled() ) { return Design_Core_Agent_Contract::error( 'writes_disabled', 'Draft writes require explicit server-side enablement after runtime acceptance tests.', 423 ); }
        if ( true !== ( $input['confirm'] ?? false ) ) { return Design_Core_Agent_Contract::error( 'confirm', 'Explicit confirm=true is required.' ); }
        if ( ! current_user_can( 'manage_options' ) ) { return Design_Core_Agent_Contract::error( 'permission', 'Permission denied.', 403 ); }
        $env = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production';
        if ( ! in_array( $env, array( 'staging', 'development', 'local', 'test' ), true ) ) { return Design_Core_Agent_Contract::error( 'environment', 'This release only permits draft writes in non-production environments.', 403 ); }
        $artifact = $this->get( $input['preview_id'], 'preview' ); if ( is_wp_error( $artifact ) ) { return $artifact; }
        $hash = $artifact['artifact_hash']; unset( $artifact['artifact_hash'] );
        if ( ! hash_equals( $hash, (string) $input['artifact_hash'] ) || ! hash_equals( $hash, Design_Core_Agent_Contract::hash( $artifact ) ) ) { return Design_Core_Agent_Contract::error( 'artifact_mismatch', 'Preview artifact hash mismatch.', 409 ); }
        if ( ! hash_equals( $artifact['policy_hash'], Design_Core_Agent_Contract::policy_hash() ) ) { return Design_Core_Agent_Contract::error( 'policy_stale', 'Agent or persistence implementation changed. Compile a new preview.', 409 ); }
        $page_id = (int) $artifact['page_id'];
        $key = 'dc_agent_apply_' . hash( 'sha256', get_current_user_id() . ':' . $input['idempotency_key'] );
        $previous = get_option( $key, null );
        if ( is_array( $previous ) ) {
            if ( ( $previous['hash'] ?? '' ) !== $hash ) { return Design_Core_Agent_Contract::error( 'idempotency_conflict', 'Idempotency key already belongs to another artifact.', 409 ); }
            return $previous['result'] ?? Design_Core_Agent_Contract::error( 'write_in_progress', 'Write already started. Inspect the draft/history; do not retry with a new key.', 409 );
        }
        $lock = 'dc_agent_lock_' . $page_id;
        if ( ! add_option( $lock, array( 'started' => time(), 'owner' => get_current_user_id() ), '', false ) ) { return Design_Core_Agent_Contract::error( 'page_locked', 'Another agent write holds this page. Expired locks require operator review.', 409 ); }
        try {
            $page = $this->knowledge->page( $page_id ); if ( is_wp_error( $page ) ) { return $page; }
            if ( 'draft' !== $page['post_status'] || ! hash_equals( $page['revision'], $artifact['page_revision'] ) ) { return Design_Core_Agent_Contract::error( 'page_stale', 'Draft status or revision changed.', 409 ); }
            if ( function_exists( 'wp_check_post_lock' ) && wp_check_post_lock( $page_id ) ) { return Design_Core_Agent_Contract::error( 'editor_lock', 'A human editor is editing this draft.', 409 ); }
            $design_system = $this->knowledge->design_system(); if ( is_wp_error( $design_system ) ) { return $design_system; }
            if ( ! hash_equals( $artifact['design_system_hash'], Design_Core_Agent_Contract::hash( $design_system ) ) ) { return Design_Core_Agent_Contract::error( 'design_system_stale', 'Design system changed after preview.', 409 ); }
            foreach ( $artifact['schema_fingerprints'] as $name => $expected ) {
                list( $type, $widget ) = explode( ':', $name, 2 );
                $schema = $this->knowledge->schema( array( 'element_type' => $type, 'widget' => $widget ) );
                if ( is_wp_error( $schema ) ) { return $schema; }
                if ( ! hash_equals( $expected, $schema['fingerprint'] ) ) { return Design_Core_Agent_Contract::error( 'schema_stale', 'Runtime schema changed after preview.', 409 ); }
            }
            if ( ! add_option( $key, array( 'hash' => $hash, 'started' => time() ), '', false ) ) { return Design_Core_Agent_Contract::error( 'write_in_progress', 'Idempotent write already started.', 409 ); }
            $adapter = new Design_Core_Elementor_V3_Adapter();
            // Only the existing governed persistence boundary writes Elementor storage.
            $saved = $adapter->save_page( $page_id, $artifact['elements'], $page['document_settings'] );
            if ( is_wp_error( $saved ) ) {
                $result = array( 'status' => 'failed', 'write_may_have_occurred' => true, 'page_id' => $page_id, 'error_code' => $saved->get_error_code(), 'recovery' => 'Inspect the draft and ledger before any retry.' );
            } else {
                $after = $adapter->reload( $page_id );
                // isInner is documented as optional on the way in; Elementor's own save path
                // fills a default of false on reload. Strip only that exact default from both
                // sides before comparing, so an omitted isInner still counts as an exact match
                // while a real, non-default isInner (or any other real divergence) still fails.
                $matches = Design_Core_Agent_Contract::hash( self::strip_default_is_inner( $after ) ) === Design_Core_Agent_Contract::hash( self::strip_default_is_inner( $artifact['elements'] ) );
                $result = array( 'status' => $matches ? 'saved_to_draft' : 'verification_failed', 'page_id' => $page_id,
                    'artifact_hash' => $hash, 'exact_tree_match' => $matches, 'persistence' => $adapter->last_persistence_evidence(),
                    'visual_qa' => 'not_verified', 'interaction_qa' => 'not_verified', 'promotion_allowed' => false );
            }
            update_option( $key, array( 'hash' => $hash, 'completed' => time(), 'result' => $result ), false );
            return $result;
        } finally { delete_option( $lock ); }
    }
    /**
     * Updates one already-published target page with an artifact that was already applied
     * to, and QA-verified on, its own disposable draft. Deliberately separate from
     * apply_draft: it never touches the draft, and it refuses unless that specific applied
     * artifact carries a passing visual_qa/interaction_qa record -- which apply_draft always
     * marks not_verified until an independent browser QA run attaches real evidence. Until
     * that evidence path exists, this refuses every call by design; the gate is the point.
     */
    public function promote_draft( array $input, array $principal = array() ) {
        if ( ! Design_Core_Elementor_Agent_Draft_Writes::enabled() ) { return Design_Core_Agent_Contract::error( 'writes_disabled', 'Draft writes require explicit server-side enablement after runtime acceptance tests.', 423 ); }
        if ( true !== ( $input['confirm'] ?? false ) ) { return Design_Core_Agent_Contract::error( 'confirm', 'Explicit confirm=true is required.' ); }
        if ( ! current_user_can( 'manage_options' ) ) { return Design_Core_Agent_Contract::error( 'permission', 'Permission denied.', 403 ); }
        $env = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production';
        if ( ! in_array( $env, array( 'staging', 'development', 'local', 'test' ), true ) ) { return Design_Core_Agent_Contract::error( 'environment', 'This release only permits promotion in non-production environments.', 403 ); }
        $artifact = $this->get( $input['preview_id'], 'preview' ); if ( is_wp_error( $artifact ) ) { return $artifact; }
        $hash = $artifact['artifact_hash']; unset( $artifact['artifact_hash'] );
        if ( ! hash_equals( $hash, (string) $input['artifact_hash'] ) || ! hash_equals( $hash, Design_Core_Agent_Contract::hash( $artifact ) ) ) { return Design_Core_Agent_Contract::error( 'artifact_mismatch', 'Preview artifact hash mismatch.', 409 ); }
        if ( ! hash_equals( $artifact['policy_hash'], Design_Core_Agent_Contract::policy_hash() ) ) { return Design_Core_Agent_Contract::error( 'policy_stale', 'Agent or persistence implementation changed. Compile a new preview.', 409 ); }
        // The artifact must have already been written to, and QA-verified on, its own draft.
        // apply_draft's stored option (keyed by owner + its own idempotency_key) is the only
        // record of that; a promotion cannot claim verification apply_draft never recorded.
        $draft_record = get_option( 'dc_agent_apply_' . hash( 'sha256', get_current_user_id() . ':' . $input['draft_idempotency_key'] ), null );
        if ( ! is_array( $draft_record ) || ( $draft_record['hash'] ?? '' ) !== $hash ) { return Design_Core_Agent_Contract::error( 'draft_write_not_found', 'No recorded draft write matches this artifact and idempotency key.', 404 ); }
        $draft_result = $draft_record['result'] ?? array();
        if ( ( $draft_result['status'] ?? '' ) !== 'saved_to_draft' || empty( $draft_result['exact_tree_match'] ) ) { return Design_Core_Agent_Contract::error( 'draft_write_unverified', 'The referenced draft write did not complete with an exact tree match.', 409 ); }
        if ( 'pass' !== ( $draft_result['visual_qa'] ?? '' ) || 'pass' !== ( $draft_result['interaction_qa'] ?? '' ) ) { return Design_Core_Agent_Contract::error( 'qa_not_verified', 'Promotion requires a passing visual and interaction QA record for this exact artifact; none is attached.', 423 ); }
        $target_page_id = (int) $input['target_page_id'];
        $key = 'dc_agent_promote_' . hash( 'sha256', get_current_user_id() . ':' . $input['idempotency_key'] );
        $previous = get_option( $key, null );
        if ( is_array( $previous ) ) {
            if ( ( $previous['hash'] ?? '' ) !== $hash || ( $previous['target_page_id'] ?? 0 ) !== $target_page_id ) { return Design_Core_Agent_Contract::error( 'idempotency_conflict', 'Idempotency key already belongs to another artifact or target.', 409 ); }
            return $previous['result'] ?? Design_Core_Agent_Contract::error( 'write_in_progress', 'Promotion already started. Inspect the target/history; do not retry with a new key.', 409 );
        }
        // Reuses apply_draft's own lock name: a draft write and a promotion can never
        // interleave on the same page_id, whichever kind of write got there first.
        $lock = 'dc_agent_lock_' . $target_page_id;
        if ( ! add_option( $lock, array( 'started' => time(), 'owner' => get_current_user_id() ), '', false ) ) { return Design_Core_Agent_Contract::error( 'page_locked', 'Another agent write holds this page. Expired locks require operator review.', 409 ); }
        try {
            $target = $this->knowledge->page( $target_page_id ); if ( is_wp_error( $target ) ) { return $target; }
            if ( 'publish' !== $target['post_status'] ) { return Design_Core_Agent_Contract::error( 'target_not_published', 'Promotion targets an already-published page; use apply-draft for drafts.', 409 ); }
            if ( ! hash_equals( $target['revision'], (string) $input['target_page_revision'] ) ) { return Design_Core_Agent_Contract::error( 'target_page_stale', 'Target page changed since it was last read.', 409 ); }
            if ( function_exists( 'wp_check_post_lock' ) && wp_check_post_lock( $target_page_id ) ) { return Design_Core_Agent_Contract::error( 'editor_lock', 'A human editor is editing this target.', 409 ); }
            $design_system = $this->knowledge->design_system(); if ( is_wp_error( $design_system ) ) { return $design_system; }
            if ( ! hash_equals( $artifact['design_system_hash'], Design_Core_Agent_Contract::hash( $design_system ) ) ) { return Design_Core_Agent_Contract::error( 'design_system_stale', 'Design system changed since the draft was QA-verified.', 409 ); }
            foreach ( $artifact['schema_fingerprints'] as $name => $expected ) {
                list( $type, $widget ) = explode( ':', $name, 2 );
                $schema = $this->knowledge->schema( array( 'element_type' => $type, 'widget' => $widget ) );
                if ( is_wp_error( $schema ) ) { return $schema; }
                if ( ! hash_equals( $expected, $schema['fingerprint'] ) ) { return Design_Core_Agent_Contract::error( 'schema_stale', 'Runtime schema changed since the draft was QA-verified.', 409 ); }
            }
            if ( ! add_option( $key, array( 'hash' => $hash, 'target_page_id' => $target_page_id, 'started' => time() ), '', false ) ) { return Design_Core_Agent_Contract::error( 'write_in_progress', 'Idempotent promotion already started.', 409 ); }
            // Explicit pre-write snapshot, independent of and in addition to WordPress/Elementor's
            // own revision history, so a same-window rollback never depends on either.
            $backup_id = $this->put( 'backup', array( 'page_id' => $target_page_id, 'tree' => $target['tree'], 'document_settings' => $target['document_settings'], 'revision' => $target['revision'] ) );
            if ( is_wp_error( $backup_id ) ) { return $backup_id; }
            $adapter = new Design_Core_Elementor_V3_Adapter();
            $saved = $adapter->save_page( $target_page_id, $artifact['elements'], $target['document_settings'] );
            if ( is_wp_error( $saved ) ) {
                $result = array( 'status' => 'failed', 'write_may_have_occurred' => true, 'target_page_id' => $target_page_id, 'backup_id' => $backup_id, 'error_code' => $saved->get_error_code(), 'recovery' => 'Inspect the target and ledger before any retry.' );
            } else {
                $after = $adapter->reload( $target_page_id );
                $matches = Design_Core_Agent_Contract::hash( self::strip_default_is_inner( $after ) ) === Design_Core_Agent_Contract::hash( self::strip_default_is_inner( $artifact['elements'] ) );
                $rolled_back = false;
                if ( ! $matches ) {
                    // Safe only because this promotion still holds the page lock: nothing else
                    // governed by that lock could have written in between. Best-effort restore;
                    // never claims success the reload does not confirm.
                    $restore = $adapter->save_page( $target_page_id, $target['tree'], $target['document_settings'] );
                    $rolled_back = ! is_wp_error( $restore ) && Design_Core_Agent_Contract::hash( self::strip_default_is_inner( $adapter->reload( $target_page_id ) ) ) === Design_Core_Agent_Contract::hash( self::strip_default_is_inner( $target['tree'] ) );
                }
                $result = array( 'status' => $matches ? 'promoted' : 'verification_failed', 'target_page_id' => $target_page_id,
                    'artifact_hash' => $hash, 'exact_tree_match' => $matches, 'backup_id' => $backup_id, 'rolled_back' => $rolled_back,
                    'persistence' => $adapter->last_persistence_evidence(), 'source_draft_page_id' => (int) ( $draft_record['result']['page_id'] ?? 0 ),
                    'publish_status_changed' => false );
            }
            update_option( $key, array( 'hash' => $hash, 'target_page_id' => $target_page_id, 'completed' => time(), 'result' => $result ), false );
            return $result;
        } finally { delete_option( $lock ); }
    }
    public function audit( array $input ) {
        $page = $this->knowledge->page( (int) $input['page_id'] ); if ( is_wp_error( $page ) ) { return $page; }
        $rows = array(); $validator = new Design_Core_Agent_Validator( $this->knowledge );
        foreach ( Design_Core_Agent_Knowledge::flatten_tree( $page['tree'] ) as $element ) {
            $result = $validator->validate( array( 'element_type' => $element['element_type'], 'widget' => $element['widget'], 'settings' => $element['element']['settings'] ?? array() ) );
            if ( is_wp_error( $result ) ) { $rows[] = array( 'element_id' => $element['element_id'], 'code' => $result->get_error_code() ); continue; }
            foreach ( $result['issues'] as $issue ) { $issue['element_id'] = $element['element_id']; $rows[] = $issue; }
        }
        $result = Design_Core_Agent_Contract::page( $rows, $input, array( 'audit', $page['page_id'] ), $page['revision'] );
        if ( ! is_wp_error( $result ) ) {
            $result['structural_qa'] = $rows ? 'fail' : 'pass';
            $result['visual_qa'] = 'not_verified'; $result['interaction_qa'] = 'not_verified'; $result['editability_qa'] = 'not_verified';
            $result['promotion_allowed'] = false;
        }
        return $result;
    }
    /**
     * Recursively drops isInner when it is exactly false, mirroring the one default
     * Elementor's own persistence fills in on save for a value the agent protocol
     * documents as optional. Any other isInner value, or any other field, is left
     * untouched -- this only neutralizes that one known, harmless normalization.
     */
    private static function strip_default_is_inner( array $elements ) {
        $out = array();
        foreach ( $elements as $key => $element ) {
            if ( ! is_array( $element ) ) { $out[ $key ] = $element; continue; }
            if ( array_key_exists( 'isInner', $element ) && false === $element['isInner'] ) { unset( $element['isInner'] ); }
            if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) { $element['elements'] = self::strip_default_is_inner( $element['elements'] ); }
            $out[ $key ] = $element;
        }
        return $out;
    }
}
