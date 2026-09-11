<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Persistent provenance for one end-to-end design/build run.
 *
 * Design Runs record public, auditable decisions and tool activity. They never
 * attempt to store a model's hidden chain-of-thought. Automatic operation events
 * keep hashes plus bounded evidence metadata; explicit rationale events are
 * expected to contain concise, user-visible reasoning summaries only.
 */
final class Design_Core_Elementor_Design_Run_Trace {
    const VERSION = 1;
    const POST_TYPE = 'design_core_run';
    const EVENT_META = '_dc_run_event';
    const MAX_EVENTS = 5000;
    const MAX_EVENT_BYTES = 32768;
    const ACTIVE_TTL = 43200;

    const STATUSES = array(
        'pending', 'running', 'pass', 'warning', 'fail', 'blocked',
        'not_verified', 'skipped', 'cancelled', 'completed', 'stale',
    );

    const PHASES = array(
        'source', 'source_inventory', 'component_graph', 'semantic_planning',
        'widget_selection', 'control_coverage', 'control_mapping', 'validation',
        'compile', 'preview', 'draft_write', 'read_back', 'browser_render',
        'visual_qa', 'interaction_qa', 'editability_qa', 'correction',
        'final_verification', 'promotion', 'general',
    );

    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) { return; }
        self::$booted = true;
        add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
        add_action( 'updated_option', array( __CLASS__, 'capture_change_ledger_update' ), 10, 3 );
        add_action( 'added_option', array( __CLASS__, 'capture_change_ledger_add' ), 10, 2 );
    }

    public static function capture_change_ledger_add( $option, $value ) {
        self::capture_change_ledger_update( $option, array(), $value );
    }

    /** Link any Change Ledger mutation occurring during an active Design Run. */
    public static function capture_change_ledger_update( $option, $old_value, $value ) {
        if ( 'design_core_elementor_change_ledger' !== (string) $option ) { return; }
        $run_id = self::active_run_id();
        if ( ! $run_id || ! is_array( $value ) ) { return; }
        $old_ids = array();
        foreach ( (array) $old_value as $entry ) { if ( is_array( $entry ) && ! empty( $entry['id'] ) ) { $old_ids[] = (string) $entry['id']; } }
        $new_entry = null;
        foreach ( $value as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['id'] ) ) { continue; }
            if ( ! in_array( (string) $entry['id'], $old_ids, true ) ) { $new_entry = $entry; break; }
        }
        if ( ! $new_entry ) { return; }
        self::append_event( $run_id, array(
            'event_type' => 'mutation',
            'phase' => 'history-rollback' === ( $new_entry['action'] ?? '' ) ? 'correction' : 'draft_write',
            'status' => 'pass',
            'channel' => 'wordpress',
            'operation' => 'change-ledger:' . sanitize_key( (string) ( $new_entry['action'] ?? 'mutation' ) ),
            'summary' => 'WordPress/Elementor mutation was recorded in Design Core History.',
            'metadata' => array(
                'history_entry_id' => (string) ( $new_entry['id'] ?? '' ),
                'action' => (string) ( $new_entry['action'] ?? '' ),
                'object_type' => (string) ( $new_entry['object_type'] ?? '' ),
                'object_id' => $new_entry['object_id'] ?? '',
                'before_hash' => (string) ( $new_entry['before_hash'] ?? '' ),
                'after_hash' => (string) ( $new_entry['after_hash'] ?? '' ),
                'rollback_of' => (string) ( $new_entry['rollback_of'] ?? '' ),
            ),
        ) );
    }

    public static function register_post_type() {
        if ( function_exists( 'post_type_exists' ) && post_type_exists( self::POST_TYPE ) ) { return; }
        register_post_type( self::POST_TYPE, array(
            'labels' => array( 'name' => 'Design Runs', 'singular_name' => 'Design Run' ),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => array( 'title', 'author' ),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ) );
    }

    public static function start( array $input ) {
        self::ensure_post_type();
        $task = trim( sanitize_text_field( (string) ( $input['task'] ?? '' ) ) );
        if ( '' === $task ) { return self::error( 'run_task', 'Design Run task is required.' ); }

        $run_id = self::new_id( 'run' );
        $owner = (int) get_current_user_id();
        $post_id = wp_insert_post( array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'post_title' => $task,
            'post_author' => $owner,
        ), true );
        if ( is_wp_error( $post_id ) ) { return $post_id; }

        $started_at = gmdate( 'c' );
        $client = sanitize_key( (string) ( $input['client'] ?? 'chatgpt-web' ) );
        if ( ! in_array( $client, array( 'chatgpt-web', 'developer-ai', 'wordpress-admin', 'api-client', 'other' ), true ) ) { $client = 'other'; }

        $meta = array(
            '_dc_run_id' => $run_id,
            '_dc_run_status' => 'running',
            '_dc_run_owner' => $owner,
            '_dc_run_client' => $client,
            '_dc_run_page_id' => isset( $input['page_id'] ) ? (int) $input['page_id'] : 0,
            '_dc_run_page_title' => sanitize_text_field( (string) ( $input['page_title'] ?? '' ) ),
            '_dc_run_source_id' => sanitize_text_field( (string) ( $input['source_id'] ?? '' ) ),
            '_dc_run_source_name' => sanitize_text_field( (string) ( $input['source_name'] ?? '' ) ),
            '_dc_run_source_hash' => self::hash_string( (string) ( $input['source_hash'] ?? '' ) ),
            '_dc_run_started_at' => $started_at,
            '_dc_run_finished_at' => '',
            '_dc_run_current_phase' => 'source',
            '_dc_run_latest_artifact_hash' => '',
            '_dc_run_event_count' => 0,
            '_dc_run_last_event_at' => $started_at,
            '_dc_run_trace_version' => self::VERSION,
        );
        foreach ( $meta as $key => $value ) { update_post_meta( $post_id, $key, $value ); }

        self::activate( $run_id );
        self::append_event( $run_id, array(
            'event_type' => 'run_started',
            'phase' => 'source',
            'status' => 'pass',
            'actor' => $client,
            'channel' => 'design-core',
            'operation' => 'agent-run-start',
            'summary' => 'Design Run started.',
            'metadata' => array(
                'page_id' => (int) $meta['_dc_run_page_id'],
                'source_id' => (string) $meta['_dc_run_source_id'],
                'source_hash' => (string) $meta['_dc_run_source_hash'],
            ),
        ) );

        return self::get( $run_id );
    }

    public static function activate( $run_id ) {
        $record = self::record( $run_id );
        if ( is_wp_error( $record ) ) { return $record; }
        if ( ! self::can_access( $record ) ) { return self::error( 'run_access', 'Design Run is not accessible.', 403 ); }
        set_transient( self::active_key(), (string) $run_id, self::ACTIVE_TTL );
        return array( 'status' => 'ok', 'run_id' => (string) $run_id, 'active' => true );
    }

    public static function active_run_id() {
        $run_id = (string) get_transient( self::active_key() );
        if ( ! self::valid_id( $run_id, 'run' ) ) { return ''; }
        $record = self::record( $run_id );
        if ( is_wp_error( $record ) || ! self::can_access( $record ) ) {
            delete_transient( self::active_key() );
            return '';
        }
        return $run_id;
    }

    public static function finish( $run_id, array $input = array() ) {
        $record = self::record( $run_id );
        if ( is_wp_error( $record ) ) { return $record; }
        if ( ! self::can_access( $record ) ) { return self::error( 'run_access', 'Design Run is not accessible.', 403 ); }

        $status = sanitize_key( (string) ( $input['status'] ?? 'completed' ) );
        if ( ! in_array( $status, array( 'completed', 'failed', 'blocked', 'cancelled' ), true ) ) { $status = 'completed'; }
        $event_status = 'failed' === $status ? 'fail' : $status;
        $artifact_hash = self::hash_string( (string) ( $input['artifact_hash'] ?? '' ) );

        self::append_event( $run_id, array(
            'event_type' => 'run_finished',
            'phase' => 'final_verification',
            'status' => $event_status,
            'actor' => self::client_for_record( $record ),
            'channel' => 'design-core',
            'operation' => 'agent-run-finish',
            'summary' => sanitize_text_field( (string) ( $input['summary'] ?? 'Design Run finished.' ) ),
            'artifact_hash' => $artifact_hash,
        ) );

        update_post_meta( $record['post_id'], '_dc_run_status', $status );
        update_post_meta( $record['post_id'], '_dc_run_finished_at', gmdate( 'c' ) );
        if ( self::active_run_id() === $run_id ) { delete_transient( self::active_key() ); }
        return self::get( $run_id );
    }

    public static function append_event( $run_id, array $event ) {
        $record = self::record( $run_id );
        if ( is_wp_error( $record ) ) { return $record; }
        if ( ! self::can_access( $record ) ) { return self::error( 'run_access', 'Design Run is not accessible.', 403 ); }

        $count = (int) get_post_meta( $record['post_id'], '_dc_run_event_count', true );
        if ( $count >= self::MAX_EVENTS ) { return self::error( 'run_event_limit', 'Design Run event limit reached.', 409 ); }

        $phase = sanitize_key( (string) ( $event['phase'] ?? 'general' ) );
        if ( ! in_array( $phase, self::PHASES, true ) ) { $phase = 'general'; }
        $status = self::normalize_status( $event['status'] ?? 'pass' );
        $artifact_hash = self::hash_string( (string) ( $event['artifact_hash'] ?? '' ) );

        $existing_events = get_post_meta( $record['post_id'], self::EVENT_META, false );
        $last_event = $existing_events ? end( $existing_events ) : array();
        $previous_event_hash = is_array( $last_event ) ? self::hash_string( (string) ( $last_event['event_hash'] ?? '' ) ) : '';

        $normalized = array(
            'event_id' => self::new_id( 'evt' ),
            'seq' => $count + 1,
            'run_id' => (string) $run_id,
            'previous_event_hash' => $previous_event_hash,
            'timestamp' => gmdate( 'c' ),
            'event_type' => sanitize_key( (string) ( $event['event_type'] ?? 'event' ) ),
            'phase' => $phase,
            'status' => $status,
            'actor' => sanitize_key( (string) ( $event['actor'] ?? self::client_for_record( $record ) ) ),
            'channel' => sanitize_key( (string) ( $event['channel'] ?? 'design-core' ) ),
            'operation' => sanitize_text_field( (string) ( $event['operation'] ?? '' ) ),
            'component_id' => sanitize_key( (string) ( $event['component_id'] ?? '' ) ),
            'component_type' => sanitize_key( (string) ( $event['component_type'] ?? '' ) ),
            'summary' => self::bounded_text( $event['summary'] ?? '', 2048 ),
            'rationale' => self::bounded_text( $event['rationale'] ?? '', 4096 ),
            'duration_ms' => max( 0, (int) ( $event['duration_ms'] ?? 0 ) ),
            'input_hash' => self::hash_string( (string) ( $event['input_hash'] ?? '' ) ),
            'output_hash' => self::hash_string( (string) ( $event['output_hash'] ?? '' ) ),
            'artifact_hash' => $artifact_hash,
            'decision' => self::redact( $event['decision'] ?? array() ),
            'metrics' => self::redact( $event['metrics'] ?? array() ),
            'evidence_refs' => self::string_list( $event['evidence_refs'] ?? array(), 100, 1024 ),
            'warnings' => self::string_list( $event['warnings'] ?? array(), 100, 1024 ),
            'errors' => self::string_list( $event['errors'] ?? array(), 100, 1024 ),
            'metadata' => self::redact( $event['metadata'] ?? array() ),
        );

        $normalized['event_hash'] = hash( 'sha256', self::json( $normalized ) );
        $json = self::json( $normalized );
        if ( strlen( $json ) > self::MAX_EVENT_BYTES ) { return self::error( 'run_event_budget', 'Design Run event exceeds the 32 KB evidence budget.', 413 ); }

        add_post_meta( $record['post_id'], self::EVENT_META, $normalized, false );
        update_post_meta( $record['post_id'], '_dc_run_event_count', $count + 1 );
        update_post_meta( $record['post_id'], '_dc_run_last_event_at', $normalized['timestamp'] );
        update_post_meta( $record['post_id'], '_dc_run_current_phase', $phase );
        if ( $artifact_hash ) { update_post_meta( $record['post_id'], '_dc_run_latest_artifact_hash', $artifact_hash ); }

        return array( 'status' => 'ok', 'run_id' => (string) $run_id, 'event' => $normalized );
    }

    /** Wrap an MCP/REST operation without changing its return value or error behavior. */
    public static function around( $channel, $operation, $input, callable $callback, $actor = '' ) {
        $run_id = self::active_run_id();
        if ( '' === $run_id ) { return $callback(); }

        $started = microtime( true );
        $redacted_input = self::redact( is_array( $input ) ? $input : array( 'value' => $input ) );
        try {
            $result = $callback();
            $duration = (int) round( ( microtime( true ) - $started ) * 1000 );
            $metadata = self::operation_metadata( $input, $result );
            self::append_event( $run_id, array(
                'event_type' => 'operation',
                'phase' => self::phase_for_operation( $operation ),
                'status' => self::status_from_result( $result ),
                'actor' => $actor ?: self::client_for_run_id( $run_id ),
                'channel' => $channel,
                'operation' => $operation,
                'component_id' => (string) ( $metadata['component_id'] ?? '' ),
                'component_type' => (string) ( $metadata['component_type'] ?? '' ),
                'summary' => self::operation_summary( $operation, $result ),
                'duration_ms' => $duration,
                'input_hash' => self::hash_value( $redacted_input ),
                'output_hash' => self::hash_value( self::redact( self::hashable_result( $result ) ) ),
                'artifact_hash' => (string) ( $metadata['artifact_hash'] ?? '' ),
                'decision' => (array) ( $metadata['decision'] ?? array() ),
                'metrics' => (array) ( $metadata['metrics'] ?? array() ),
                'metadata' => $metadata,
                'errors' => is_wp_error( $result ) ? array( (string) $result->get_error_code() ) : array(),
            ) );
            return $result;
        } catch ( Throwable $exception ) {
            self::append_event( $run_id, array(
                'event_type' => 'operation',
                'phase' => self::phase_for_operation( $operation ),
                'status' => 'fail',
                'actor' => $actor ?: self::client_for_run_id( $run_id ),
                'channel' => $channel,
                'operation' => $operation,
                'summary' => 'Operation raised an exception; inspect server diagnostics.',
                'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
                'input_hash' => self::hash_value( $redacted_input ),
                'errors' => array( get_class( $exception ) ),
            ) );
            throw $exception;
        }
    }

    public static function get( $run_id ) {
        $record = self::record( $run_id );
        if ( is_wp_error( $record ) ) { return $record; }
        if ( ! self::can_access( $record ) ) { return self::error( 'run_access', 'Design Run is not accessible.', 403 ); }
        $record['summary'] = self::summary_for_record( $record );
        return array( 'status' => 'ok', 'run' => $record );
    }

    public static function list_runs( array $input = array() ) {
        self::ensure_post_type();
        $limit = max( 1, min( 100, (int) ( $input['limit'] ?? 20 ) ) );
        $page = max( 1, (int) ( $input['page'] ?? 1 ) );
        $args = array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => $limit,
            'offset' => ( $page - 1 ) * $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => false,
        );
        if ( ! current_user_can( 'manage_options' ) ) { $args['author'] = (int) get_current_user_id(); }
        $posts = get_posts( $args );
        $runs = array();
        foreach ( (array) $posts as $post ) {
            $record = self::record_from_post( $post );
            if ( ! $record || ! self::can_access( $record ) ) { continue; }
            $record['summary'] = self::summary_for_record( $record );
            $runs[] = $record;
        }
        return array( 'status' => 'ok', 'page' => $page, 'limit' => $limit, 'count' => count( $runs ), 'runs' => $runs );
    }

    public static function events( $run_id, array $input = array() ) {
        $record = self::record( $run_id );
        if ( is_wp_error( $record ) ) { return $record; }
        if ( ! self::can_access( $record ) ) { return self::error( 'run_access', 'Design Run is not accessible.', 403 ); }

        $phase = sanitize_key( (string) ( $input['phase'] ?? '' ) );
        $component_id = sanitize_key( (string) ( $input['component_id'] ?? '' ) );
        $limit = max( 1, min( 250, (int) ( $input['limit'] ?? 100 ) ) );
        $offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
        $all = self::all_events_for_record( $record );
        $filtered = array_values( array_filter( $all, static function ( $event ) use ( $phase, $component_id ) {
            if ( $phase && $phase !== ( $event['phase'] ?? '' ) ) { return false; }
            if ( $component_id && $component_id !== ( $event['component_id'] ?? '' ) ) { return false; }
            return true;
        } ) );
        $slice = array_slice( $filtered, $offset, $limit );
        return array(
            'status' => 'ok', 'run_id' => (string) $run_id, 'total' => count( $filtered ),
            'offset' => $offset, 'limit' => $limit, 'has_more' => $offset + count( $slice ) < count( $filtered ),
            'events' => $slice,
        );
    }

    public static function components( $run_id ) {
        $record = self::record( $run_id );
        if ( is_wp_error( $record ) ) { return $record; }
        if ( ! self::can_access( $record ) ) { return self::error( 'run_access', 'Design Run is not accessible.', 403 ); }

        $groups = array();
        foreach ( self::all_events_for_record( $record ) as $event ) {
            $key = (string) ( $event['component_id'] ?? '' );
            if ( '' === $key ) { $key = (string) ( $event['component_type'] ?? '' ); }
            if ( '' === $key ) { continue; }
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array(
                    'component_id' => (string) ( $event['component_id'] ?? '' ),
                    'component_type' => (string) ( $event['component_type'] ?? '' ),
                    'event_count' => 0, 'last_status' => 'not_verified', 'last_phase' => '',
                    'selected_widget' => '', 'implementation_type' => '', 'last_at' => '',
                );
            }
            $groups[ $key ]['event_count']++;
            $groups[ $key ]['last_status'] = (string) ( $event['status'] ?? 'not_verified' );
            $groups[ $key ]['last_phase'] = (string) ( $event['phase'] ?? '' );
            $groups[ $key ]['last_at'] = (string) ( $event['timestamp'] ?? '' );
            $metadata = (array) ( $event['metadata'] ?? array() );
            $decision = (array) ( $event['decision'] ?? array() );
            $selected = (string) ( $metadata['selected_widget'] ?? $decision['selected_widget'] ?? $decision['selected'] ?? '' );
            if ( $selected ) { $groups[ $key ]['selected_widget'] = sanitize_key( $selected ); }
            $implementation = (string) ( $metadata['implementation_type'] ?? $decision['implementation_type'] ?? '' );
            if ( $implementation ) { $groups[ $key ]['implementation_type'] = sanitize_key( $implementation ); }
        }
        return array( 'status' => 'ok', 'run_id' => (string) $run_id, 'count' => count( $groups ), 'components' => array_values( $groups ) );
    }

    public static function artifacts( $run_id ) {
        $record = self::record( $run_id );
        if ( is_wp_error( $record ) ) { return $record; }
        if ( ! self::can_access( $record ) ) { return self::error( 'run_access', 'Design Run is not accessible.', 403 ); }

        $groups = array();
        foreach ( self::all_events_for_record( $record ) as $event ) {
            $hash = self::hash_string( (string) ( $event['artifact_hash'] ?? '' ) );
            if ( ! $hash ) { continue; }
            if ( ! isset( $groups[ $hash ] ) ) {
                $groups[ $hash ] = array( 'artifact_hash' => $hash, 'event_count' => 0, 'first_at' => '', 'last_at' => '', 'preview_id' => '', 'qa' => self::qa_defaults() );
            }
            $groups[ $hash ]['event_count']++;
            if ( ! $groups[ $hash ]['first_at'] ) { $groups[ $hash ]['first_at'] = (string) ( $event['timestamp'] ?? '' ); }
            $groups[ $hash ]['last_at'] = (string) ( $event['timestamp'] ?? '' );
            $metadata = (array) ( $event['metadata'] ?? array() );
            if ( ! empty( $metadata['preview_id'] ) ) { $groups[ $hash ]['preview_id'] = sanitize_text_field( (string) $metadata['preview_id'] ); }
            self::apply_qa_from_event( $groups[ $hash ]['qa'], $event, $hash );
        }
        return array( 'status' => 'ok', 'run_id' => (string) $run_id, 'latest_artifact_hash' => $record['latest_artifact_hash'], 'artifacts' => array_values( $groups ) );
    }

    public static function compare( $run_id_a, $run_id_b ) {
        $a = self::get( $run_id_a );
        $b = self::get( $run_id_b );
        if ( is_wp_error( $a ) ) { return $a; }
        if ( is_wp_error( $b ) ) { return $b; }
        $ca = self::components( $run_id_a );
        $cb = self::components( $run_id_b );
        if ( is_wp_error( $ca ) ) { return $ca; }
        if ( is_wp_error( $cb ) ) { return $cb; }

        $index_a = self::component_index( (array) $ca['components'] );
        $index_b = self::component_index( (array) $cb['components'] );
        $keys = array_values( array_unique( array_merge( array_keys( $index_a ), array_keys( $index_b ) ) ) );
        sort( $keys );
        $component_changes = array();
        foreach ( $keys as $key ) {
            $wa = (string) ( $index_a[ $key ]['selected_widget'] ?? '' );
            $wb = (string) ( $index_b[ $key ]['selected_widget'] ?? '' );
            $sa = (string) ( $index_a[ $key ]['last_status'] ?? 'missing' );
            $sb = (string) ( $index_b[ $key ]['last_status'] ?? 'missing' );
            if ( $wa !== $wb || $sa !== $sb ) {
                $component_changes[] = array( 'component' => $key, 'widget_a' => $wa, 'widget_b' => $wb, 'status_a' => $sa, 'status_b' => $sb );
            }
        }

        return array(
            'status' => 'ok',
            'run_a' => $a['run']['run_id'], 'run_b' => $b['run']['run_id'],
            'qa_a' => $a['run']['summary']['qa'], 'qa_b' => $b['run']['summary']['qa'],
            'promotion_ready_a' => (bool) $a['run']['summary']['promotion_ready'],
            'promotion_ready_b' => (bool) $b['run']['summary']['promotion_ready'],
            'component_changes' => $component_changes,
        );
    }

    public static function redact( $value, $depth = 0, $key = '' ) {
        if ( $depth > 12 ) { return '[depth-limit]'; }
        $key_lc = strtolower( (string) $key );
        if ( $key_lc && preg_match( '/(?:authorization|bearer|token|api[_-]?key|password|passwd|secret|cookie|set-cookie|private[_-]?key|ssh[_-]?key|credential)/i', $key_lc ) ) {
            return '[redacted]';
        }
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $k => $v ) { $out[ $k ] = self::redact( $v, $depth + 1, (string) $k ); }
            return $out;
        }
        if ( is_object( $value ) ) {
            if ( $value instanceof WP_Error ) { return array( 'wp_error' => (string) $value->get_error_code() ); }
            return self::redact( get_object_vars( $value ), $depth + 1, $key );
        }
        if ( is_string( $value ) ) {
            if ( strlen( $value ) > 4096 ) { return '[omitted sha256=' . hash( 'sha256', $value ) . ' bytes=' . strlen( $value ) . ']'; }
            return $value;
        }
        if ( is_scalar( $value ) || null === $value ) { return $value; }
        return '[unsupported]';
    }

    private static function summary_for_record( array $record ) {
        $events = self::all_events_for_record( $record );
        $latest_artifact = (string) $record['latest_artifact_hash'];
        $phases = array();
        foreach ( self::PHASES as $phase ) { $phases[ $phase ] = 'pending'; }
        $qa = self::qa_defaults();
        $qa_stale = array_fill_keys( array_keys( $qa ), false );

        foreach ( $events as $event ) {
            $phase = (string) ( $event['phase'] ?? 'general' );
            if ( isset( $phases[ $phase ] ) ) { $phases[ $phase ] = self::normalize_status( $event['status'] ?? 'not_verified' ); }

            $event_artifact = self::hash_string( (string) ( $event['artifact_hash'] ?? '' ) );
            $metadata = (array) ( $event['metadata'] ?? array() );
            foreach ( array( 'semantic_qa', 'structural_qa', 'visual_qa', 'interaction_qa', 'editability_qa' ) as $qa_key ) {
                if ( ! isset( $metadata[ $qa_key ] ) ) { continue; }
                $short = str_replace( '_qa', '', $qa_key );
                $value = self::normalize_qa_status( $metadata[ $qa_key ] );
                if ( $latest_artifact && $event_artifact && $event_artifact !== $latest_artifact ) {
                    if ( 'pass' === $value ) { $qa_stale[ $short ] = true; }
                    continue;
                }
                $qa[ $short ] = $value;
            }
            if ( 'compile' === $phase && 'pass' === ( $event['status'] ?? '' ) && 'pass' === self::normalize_qa_status( $metadata['schema_validation'] ?? '' ) ) {
                if ( ! $latest_artifact || ! $event_artifact || $event_artifact === $latest_artifact ) { $qa['structural'] = 'pass'; }
            }
            if ( in_array( $phase, array( 'visual_qa', 'interaction_qa', 'editability_qa' ), true ) ) {
                $short = str_replace( '_qa', '', $phase );
                $value = self::normalize_qa_status( $event['status'] ?? 'not_verified' );
                if ( $latest_artifact && $event_artifact && $event_artifact !== $latest_artifact ) {
                    if ( 'pass' === $value ) { $qa_stale[ $short ] = true; }
                } else { $qa[ $short ] = $value; }
            }
        }
        foreach ( $qa_stale as $key => $stale ) { if ( $stale && 'not_verified' === $qa[ $key ] ) { $qa[ $key ] = 'stale'; } }

        $source_ok = (bool) ( $record['source_hash'] || $record['source_id'] );
        $required = array(
            'source' => $source_ok,
            'artifact' => (bool) $latest_artifact,
            'semantic_qa' => 'pass' === $qa['semantic'],
            'structural_qa' => 'pass' === $qa['structural'],
            'visual_qa' => 'pass' === $qa['visual'],
            'interaction_qa' => 'pass' === $qa['interaction'],
        );
        $missing = array_keys( array_filter( $required, static fn( $ok ) => ! $ok ) );

        $integrity = self::integrity_for_events( $events );
        return array(
            'event_count' => count( $events ),
            'integrity' => $integrity,
            'current_phase' => (string) $record['current_phase'],
            'phases' => $phases,
            'qa' => $qa,
            'latest_artifact_hash' => $latest_artifact,
            'promotion_ready' => empty( $missing ),
            'promotion_missing' => $missing,
        );
    }

    private static function integrity_for_events( array $events ) {
        $previous = '';
        foreach ( $events as $index => $event ) {
            if ( ! is_array( $event ) ) { return array( 'status' => 'fail', 'checked_events' => $index, 'reason' => 'invalid-event' ); }
            $declared_previous = self::hash_string( (string) ( $event['previous_event_hash'] ?? '' ) );
            if ( $declared_previous !== $previous ) { return array( 'status' => 'fail', 'checked_events' => $index, 'reason' => 'chain-break' ); }
            $declared_hash = self::hash_string( (string) ( $event['event_hash'] ?? '' ) );
            $copy = $event;
            unset( $copy['event_hash'] );
            $expected = hash( 'sha256', self::json( $copy ) );
            if ( ! $declared_hash || ! hash_equals( $expected, $declared_hash ) ) { return array( 'status' => 'fail', 'checked_events' => $index, 'reason' => 'event-hash-mismatch' ); }
            $previous = $declared_hash;
        }
        return array( 'status' => 'pass', 'checked_events' => count( $events ), 'head_hash' => $previous );
    }

    private static function apply_qa_from_event( array &$qa, array $event, $artifact_hash ) {
        $event_hash = self::hash_string( (string) ( $event['artifact_hash'] ?? '' ) );
        if ( $event_hash && $event_hash !== $artifact_hash ) { return; }
        $metadata = (array) ( $event['metadata'] ?? array() );
        foreach ( array( 'semantic_qa', 'structural_qa', 'visual_qa', 'interaction_qa', 'editability_qa' ) as $qa_key ) {
            if ( isset( $metadata[ $qa_key ] ) ) { $qa[ str_replace( '_qa', '', $qa_key ) ] = self::normalize_qa_status( $metadata[ $qa_key ] ); }
        }
        $phase = (string) ( $event['phase'] ?? '' );
        if ( in_array( $phase, array( 'visual_qa', 'interaction_qa', 'editability_qa' ), true ) ) { $qa[ str_replace( '_qa', '', $phase ) ] = self::normalize_qa_status( $event['status'] ?? '' ); }
    }

    private static function operation_metadata( $input, $result ) {
        $input = is_array( $input ) ? $input : array();
        $out = array();
        foreach ( array( 'page_id', 'target_page_id', 'source_id', 'preview_id', 'artifact_hash', 'page_revision', 'target_page_revision', 'component_id', 'component_type' ) as $key ) {
            if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ) { $out[ $key ] = self::safe_scalar( $input[ $key ] ); }
        }
        if ( is_array( $result ) ) {
            foreach ( array( 'page_id', 'target_page_id', 'source_id', 'preview_id', 'artifact_hash', 'page_revision', 'revision', 'history_entry_id', 'semantic_qa', 'structural_qa', 'visual_qa', 'interaction_qa', 'editability_qa', 'schema_validation', 'decision_hash' ) as $key ) {
                if ( isset( $result[ $key ] ) && is_scalar( $result[ $key ] ) ) { $out[ $key ] = self::safe_scalar( $result[ $key ] ); }
            }
            if ( isset( $result['recommendation'] ) && is_array( $result['recommendation'] ) ) {
                $recommendation = $result['recommendation'];
                $selected = sanitize_key( (string) ( $recommendation['widget'] ?? '' ) );
                if ( $selected ) { $out['selected_widget'] = $selected; }
                $type = sanitize_key( (string) ( $recommendation['type'] ?? '' ) );
                if ( $type ) { $out['implementation_type'] = $type; }
                $out['decision'] = array(
                    'selected_widget' => $selected,
                    'implementation_type' => $type,
                    'score' => isset( $recommendation['score'] ) ? (float) $recommendation['score'] : null,
                    'reason' => sanitize_text_field( (string) ( $recommendation['reason'] ?? '' ) ),
                );
            }
            if ( isset( $result['metrics'] ) && is_array( $result['metrics'] ) ) { $out['metrics'] = self::redact( $result['metrics'] ); }
        }
        if ( empty( $out['artifact_hash'] ) && isset( $input['artifact_hash'] ) ) { $out['artifact_hash'] = self::hash_string( (string) $input['artifact_hash'] ); }
        if ( isset( $out['artifact_hash'] ) ) { $out['artifact_hash'] = self::hash_string( (string) $out['artifact_hash'] ); }
        return self::redact( $out );
    }

    private static function hashable_result( $result ) {
        if ( is_wp_error( $result ) ) { return array( 'wp_error' => (string) $result->get_error_code() ); }
        if ( is_array( $result ) ) {
            $copy = $result;
            foreach ( array( 'html', 'css', 'content', 'elements', 'tree', 'definitions', 'controls' ) as $key ) {
                if ( isset( $copy[ $key ] ) ) { $copy[ $key ] = '[omitted-from-trace-hash]'; }
            }
            return $copy;
        }
        return $result;
    }

    private static function phase_for_operation( $operation ) {
        $operation = strtolower( (string) $operation );
        $map = array(
            'register-source' => 'source', 'read-source' => 'source',
            'context' => 'source_inventory', 'catalog' => 'source_inventory', 'element-schema' => 'source_inventory', 'control-detail' => 'source_inventory', 'page-tree' => 'source_inventory', 'page-element' => 'source_inventory', 'library' => 'source_inventory', 'docs' => 'source_inventory',
            'component-ontology' => 'semantic_planning', 'component-plan' => 'widget_selection',
            'validate-settings' => 'validation', 'preview-plan' => 'compile', 'agent-semantic-preview' => 'compile',
            'read-preview' => 'preview', 'apply-draft' => 'draft_write', 'audit-page' => 'final_verification', 'agent-semantic-audit' => 'final_verification',
            'promote-draft' => 'promotion',
        );
        foreach ( $map as $needle => $phase ) { if ( false !== strpos( $operation, $needle ) ) { return $phase; } }
        return 'general';
    }

    private static function status_from_result( $result ) {
        if ( is_wp_error( $result ) ) { return 'fail'; }
        if ( is_array( $result ) && isset( $result['status'] ) ) {
            $status = strtolower( (string) $result['status'] );
            $map = array( 'ok' => 'pass', 'success' => 'pass', 'invalid' => 'fail', 'error' => 'fail', 'failed' => 'fail', 'not_verified' => 'not_verified' );
            return self::normalize_status( $map[ $status ] ?? $status );
        }
        return 'pass';
    }

    private static function operation_summary( $operation, $result ) {
        $status = self::status_from_result( $result );
        return self::bounded_text( sprintf( '%s %s.', (string) $operation, $status ), 512 );
    }

    private static function record( $run_id ) {
        if ( ! self::valid_id( $run_id, 'run' ) ) { return self::error( 'run_id', 'Invalid Design Run ID.' ); }
        self::ensure_post_type();
        $posts = get_posts( array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_key' => '_dc_run_id',
            'meta_value' => (string) $run_id,
            'suppress_filters' => false,
        ) );
        if ( empty( $posts ) ) { return self::error( 'run_not_found', 'Design Run was not found.', 404 ); }
        return self::record_from_post( $posts[0] );
    }

    private static function record_from_post( $post ) {
        if ( ! is_object( $post ) ) { return null; }
        $post_id = (int) $post->ID;
        return array(
            'post_id' => $post_id,
            'run_id' => (string) get_post_meta( $post_id, '_dc_run_id', true ),
            'task' => (string) $post->post_title,
            'owner_id' => (int) get_post_meta( $post_id, '_dc_run_owner', true ),
            'client' => (string) get_post_meta( $post_id, '_dc_run_client', true ),
            'status' => (string) get_post_meta( $post_id, '_dc_run_status', true ),
            'page_id' => (int) get_post_meta( $post_id, '_dc_run_page_id', true ),
            'page_title' => (string) get_post_meta( $post_id, '_dc_run_page_title', true ),
            'source_id' => (string) get_post_meta( $post_id, '_dc_run_source_id', true ),
            'source_name' => (string) get_post_meta( $post_id, '_dc_run_source_name', true ),
            'source_hash' => (string) get_post_meta( $post_id, '_dc_run_source_hash', true ),
            'started_at' => (string) get_post_meta( $post_id, '_dc_run_started_at', true ),
            'finished_at' => (string) get_post_meta( $post_id, '_dc_run_finished_at', true ),
            'current_phase' => (string) get_post_meta( $post_id, '_dc_run_current_phase', true ),
            'latest_artifact_hash' => (string) get_post_meta( $post_id, '_dc_run_latest_artifact_hash', true ),
            'event_count' => (int) get_post_meta( $post_id, '_dc_run_event_count', true ),
            'last_event_at' => (string) get_post_meta( $post_id, '_dc_run_last_event_at', true ),
            'trace_version' => (int) get_post_meta( $post_id, '_dc_run_trace_version', true ),
        );
    }

    private static function can_access( array $record ) {
        $user_id = (int) get_current_user_id();
        return current_user_can( 'manage_options' ) || (int) $record['owner_id'] === $user_id;
    }

    private static function all_events_for_record( array $record ) {
        $events = get_post_meta( $record['post_id'], self::EVENT_META, false );
        $events = array_values( array_filter( (array) $events, 'is_array' ) );
        usort( $events, static fn( $a, $b ) => (int) ( $a['seq'] ?? 0 ) <=> (int) ( $b['seq'] ?? 0 ) );
        return $events;
    }

    private static function component_index( array $components ) {
        $out = array();
        foreach ( $components as $component ) {
            $key = (string) ( $component['component_id'] ?? '' );
            if ( ! $key ) { $key = (string) ( $component['component_type'] ?? '' ); }
            if ( $key ) { $out[ $key ] = $component; }
        }
        return $out;
    }

    private static function client_for_record( array $record ) {
        $client = sanitize_key( (string) ( $record['client'] ?? 'chatgpt-web' ) );
        return $client ?: 'chatgpt-web';
    }

    private static function client_for_run_id( $run_id ) {
        $record = self::record( $run_id );
        return is_wp_error( $record ) ? 'mcp-client' : self::client_for_record( $record );
    }

    private static function active_key() { return 'dc_design_run_active_' . (int) get_current_user_id(); }

    private static function ensure_post_type() {
        if ( function_exists( 'post_type_exists' ) && ! post_type_exists( self::POST_TYPE ) ) { self::register_post_type(); }
    }

    private static function new_id( $prefix ) {
        try { $random = bin2hex( random_bytes( 12 ) ); }
        catch ( Throwable $e ) { $random = str_replace( '-', '', (string) wp_generate_uuid4() ); }
        return sanitize_key( $prefix . '_' . gmdate( 'YmdHis' ) . '_' . substr( $random, 0, 24 ) );
    }

    private static function valid_id( $value, $prefix ) { return 1 === preg_match( '/^' . preg_quote( $prefix, '/' ) . '_[a-z0-9_\-]{12,80}$/', (string) $value ); }

    private static function qa_defaults() { return array( 'semantic' => 'not_verified', 'structural' => 'not_verified', 'visual' => 'not_verified', 'interaction' => 'not_verified', 'editability' => 'not_verified' ); }

    private static function normalize_qa_status( $value ) {
        $value = strtolower( str_replace( '-', '_', trim( (string) $value ) ) );
        $map = array( 'ok' => 'pass', 'success' => 'pass', 'passed' => 'pass', 'failed' => 'fail', 'invalid' => 'fail', 'notverified' => 'not_verified', 'not_tested' => 'not_verified' );
        $value = $map[ $value ] ?? $value;
        return in_array( $value, array( 'pass', 'warning', 'fail', 'blocked', 'not_verified', 'skipped', 'stale' ), true ) ? $value : 'not_verified';
    }

    private static function normalize_status( $value ) {
        $value = strtolower( str_replace( '-', '_', trim( (string) $value ) ) );
        $map = array( 'ok' => 'pass', 'success' => 'pass', 'passed' => 'pass', 'failed' => 'fail', 'error' => 'fail' );
        $value = $map[ $value ] ?? $value;
        return in_array( $value, self::STATUSES, true ) ? $value : 'not_verified';
    }

    private static function bounded_text( $value, $max ) {
        $text = sanitize_text_field( (string) $value );
        if ( strlen( $text ) <= $max ) { return $text; }
        return substr( $text, 0, $max - 1 ) . '…';
    }

    private static function string_list( $values, $max_items, $max_length ) {
        $out = array();
        foreach ( array_slice( (array) $values, 0, $max_items ) as $value ) {
            if ( ! is_scalar( $value ) ) { continue; }
            $out[] = self::bounded_text( $value, $max_length );
        }
        return array_values( array_unique( $out ) );
    }

    private static function safe_scalar( $value ) {
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { return $value; }
        return self::bounded_text( (string) $value, 1024 );
    }

    private static function hash_string( $value ) {
        $value = strtolower( trim( (string) $value ) );
        return preg_match( '/^[a-f0-9]{16,128}$/', $value ) ? $value : '';
    }

    private static function hash_value( $value ) { return hash( 'sha256', self::json( $value ) ); }
    private static function json( $value ) { return function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }

    private static function error( $code, $message, $status = 400 ) {
        if ( class_exists( 'Design_Core_Agent_Contract' ) ) { return Design_Core_Agent_Contract::error( $code, $message, $status ); }
        return new WP_Error( $code, $message );
    }
}
