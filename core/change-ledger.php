<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Persistent post-change ledger with conflict-aware rollback.
 *
 * Large before/after payloads are stored as JSON blobs under uploads while the
 * bounded option keeps only metadata and references. Rollback never overwrites a
 * newer state: the current hash must still match the recorded after hash.
 */
class Design_Core_Elementor_Change_Ledger {
    const OPTION_KEY = 'design_core_elementor_change_ledger';
    const SCHEMA_VERSION = 1;
    const MAX_ENTRIES = 250;
    const INLINE_BYTES = 4096;

    /**
     * Bounded, test-only fault injection for rollback stage coverage. Never affects behavior
     * unless a test explicitly attaches a filter callback via add_filter() -- no environment
     * variable, request parameter, or REST/MCP argument can reach this, so it cannot be
     * activated remotely. See apply_rollback_stages() for the exact stage names used.
     */
    const TEST_FAIL_STAGE_FILTER = 'design_core_elementor_test_fail_history_rollback_stage';

    public function all() {
        $entries = get_option( self::OPTION_KEY, array() );
        return is_array( $entries ) ? array_values( $entries ) : array();
    }


    /** Metadata-only list for REST/Page Snapshot surfaces; payload bodies stay private to rollback. */
    public function summaries() {
        $out = array();
        foreach ( $this->all() as $entry ) {
            $out[] = array(
                'schema_version' => (int) ( $entry['schema_version'] ?? self::SCHEMA_VERSION ),
                'id' => (string) ( $entry['id'] ?? '' ),
                'timestamp' => (string) ( $entry['timestamp'] ?? '' ),
                'action' => (string) ( $entry['action'] ?? '' ),
                'object_type' => (string) ( $entry['object_type'] ?? '' ),
                'object_id' => $entry['object_id'] ?? '',
                'before_hash' => (string) ( $entry['before_hash'] ?? '' ),
                'after_hash' => (string) ( $entry['after_hash'] ?? '' ),
                'actor' => (int) ( $entry['actor'] ?? 0 ),
                'rolled_back_at' => (string) ( $entry['rolled_back_at'] ?? '' ),
                'rollback_of' => (string) ( $entry['rollback_of'] ?? '' ),
                'context' => self::transport_safe( (array) ( $entry['context'] ?? array() ) ),
                'rollback_available' => self::rollback_available_for( $entry ),
            );
        }
        return $out;
    }

    /**
     * Whether a history entry can still be rolled back: the underlying action supports it, it
     * hasn't been rolled back already, and its "before" snapshot was actually captured durably
     * (a large payload whose blob write failed -- e.g. an unwritable uploads directory -- degrades
     * to a hash-only record that proves what the prior state *was* but can't restore it). Callers
     * that promise revertibility (the rc21 remote write path) should surface this immediately
     * rather than let a caller discover it only on a later, separate history/snapshot call.
     */
    public static function rollback_available_for( array $entry ) {
        return 'elementor-save' === ( $entry['action'] ?? '' ) && empty( $entry['rolled_back_at'] ) && 'hash-only' !== ( $entry['before']['storage'] ?? '' );
    }

    /**
     * Merges additional key/value pairs into an already-recorded entry's context. Needed
     * because not every field a governed write touches is known at record()-call time -- the
     * Design Core page manifest, for example, is synchronized by Conversion_Service AFTER the
     * Elementor save (and its Change_Ledger entry) already completed, in the same overall
     * request. Silently no-ops if the entry no longer exists (already trimmed by MAX_ENTRIES,
     * or already rolled back and superseded) rather than erroring -- this is best-effort
     * enrichment of an entry that already recorded its primary (_elementor_data) state
     * correctly, never a requirement for that entry to be valid.
     */
    public function augment_context( $entry_id, array $additional_context ) {
        $entry_id = sanitize_key( (string) $entry_id );
        $entries = $this->all();
        $found = false;
        foreach ( $entries as &$candidate ) {
            if ( ( $candidate['id'] ?? '' ) === $entry_id ) {
                $candidate['context'] = array_merge( (array) ( $candidate['context'] ?? array() ), self::transport_safe( $additional_context ) );
                $found = true;
                break;
            }
        }
        unset( $candidate );
        if ( ! $found ) { return false; }
        return false !== update_option( self::OPTION_KEY, $entries, false );
    }

    public function get( $entry_id ) {
        $entry_id = sanitize_key( (string) $entry_id );
        foreach ( $this->all() as $entry ) {
            if ( $entry_id === (string) ( $entry['id'] ?? '' ) ) { return $entry; }
        }
        return null;
    }

    public function record( $action, $object_type, $object_id, $before, $after, array $context = array() ) {
        $action = sanitize_key( (string) $action );
        $object_type = sanitize_key( (string) $object_type );
        if ( ! $action || ! $object_type ) { return new WP_Error( 'design_core_history_invalid', 'History action and object type are required.' ); }

        $entry_id = 'dc-' . substr( hash( 'sha256', $action . '|' . $object_type . '|' . (string) $object_id . '|' . microtime( true ) . '|' . wp_generate_uuid4() ), 0, 20 );
        $before_payload = $this->store_payload( $entry_id . '-before', $before );
        $after_payload = $this->store_payload( $entry_id . '-after', $after );
        if ( is_wp_error( $before_payload ) ) { return $before_payload; }
        if ( is_wp_error( $after_payload ) ) { return $after_payload; }

        $entry = array(
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $entry_id,
            'timestamp' => gmdate( 'c' ),
            'action' => $action,
            'object_type' => $object_type,
            'object_id' => is_numeric( $object_id ) ? (int) $object_id : sanitize_text_field( (string) $object_id ),
            'before_hash' => self::hash_value( $before ),
            'after_hash' => self::hash_value( $after ),
            'before' => $before_payload,
            'after' => $after_payload,
            'context' => self::transport_safe( $context ),
            'actor' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
            'rolled_back_at' => '',
            'rollback_of' => sanitize_key( (string) ( $context['rollback_of'] ?? '' ) ),
        );

        $entries = $this->all();
        array_unshift( $entries, $entry );
        if ( count( $entries ) > self::MAX_ENTRIES ) {
            $removed = array_splice( $entries, self::MAX_ENTRIES );
            foreach ( $removed as $old ) { $this->delete_payload_files( $old ); }
        }
        if ( false === update_option( self::OPTION_KEY, $entries, false ) ) {
            return new WP_Error( 'design_core_history_write_failed', 'Unable to persist Design Core history.' );
        }
        return $entry;
    }

    /**
     * Conflict-aware, all-or-compensate rollback for completed Elementor document saves.
     *
     * This is NOT database-transaction atomicity -- there is no single underlying transaction
     * to roll back, only a sequence of independent WordPress API writes (postmeta, a post
     * field). "All-or-compensate" means: every conflict check happens before the first
     * mutation (see capture_rollback_state() + the checks just below it); if every restore
     * stage succeeds and is verified, the ledger is durably marked rolled-back; if ANY stage
     * fails partway through -- including the final ledger-mark write itself -- this attempts to
     * write every tracked field straight back to the exact pre-rollback CURRENT snapshot
     * ("compensation") rather than leaving a blend of the old and new states, and never reports
     * success or marks the entry rolled back unless that compensation is itself verified.
     */
    public function rollback( $entry_id ) {
        $entry = $this->get( $entry_id );
        if ( ! is_array( $entry ) ) { return new WP_Error( 'design_core_history_not_found', 'History entry was not found.', array( 'status' => 404 ) ); }
        if ( 'elementor-save' !== ( $entry['action'] ?? '' ) || 'post' !== ( $entry['object_type'] ?? '' ) ) {
            return new WP_Error( 'design_core_history_unsupported', 'This history entry does not support automatic rollback.', array( 'status' => 400 ) );
        }
        if ( ! empty( $entry['rolled_back_at'] ) ) { return new WP_Error( 'design_core_history_already_rolled_back', 'This history entry has already been rolled back.', array( 'status' => 409 ) ); }

        $post_id = (int) ( $entry['object_id'] ?? 0 );
        if ( $post_id <= 0 ) { return new WP_Error( 'design_core_history_invalid_post', 'History entry has no valid post ID.', array( 'status' => 400 ) ); }

        $context = is_array( $entry['context'] ?? null ) ? $entry['context'] : array();

        // Capture the exact CURRENT/B snapshot before touching anything. This drives every
        // conflict check below and, if any restore stage later fails, is exactly what
        // compensation restores back to.
        $current_state = $this->capture_rollback_state( $post_id, $context );

        // Every conflict check happens before the first mutation. A field is only ever
        // conflict-checked (and later restored) if THIS entry actually tracked it -- an older
        // entry recorded before post_content/page-manifest tracking existed simply can't have
        // that kind of conflict detected, matching its prior (narrower) rollback scope rather
        // than failing outright.
        if ( ! hash_equals( (string) ( $entry['after_hash'] ?? '' ), self::hash_value( $current_state['elementor_data'] ) ) ) {
            return new WP_Error( 'design_core_history_conflict', 'Rollback refused because the Elementor document changed after this history entry.', array( 'status' => 409 ) );
        }
        if ( $current_state['has_post_content']
            && ! hash_equals( self::hash_value( (string) $context['post_content_after'] ), self::hash_value( $current_state['post_content'] ) ) ) {
            return new WP_Error( 'design_core_history_conflict', 'Rollback refused because the page content changed after this history entry.', array( 'status' => 409 ) );
        }
        if ( $current_state['has_page_manifest']
            && ! hash_equals( self::hash_value( $context['page_manifest_after'] ?? null ), self::hash_value( $current_state['page_manifest'] ) ) ) {
            return new WP_Error( 'design_core_history_conflict', 'Rollback refused because the page manifest changed after this history entry.', array( 'status' => 409 ) );
        }

        $before_payload = $this->load_payload( $entry['before'] ?? array() );
        if ( is_wp_error( $before_payload ) ) { return $before_payload; }
        $before_elementor_data = is_scalar( $before_payload ) || null === $before_payload ? (string) $before_payload : wp_json_encode( $before_payload );

        $target_state = array(
            'elementor_data' => $before_elementor_data,
            'owned_meta' => is_array( $context['owned_meta_before'] ?? null ) ? $context['owned_meta_before'] : array(),
            'has_post_content' => $current_state['has_post_content'],
            'post_content' => $current_state['has_post_content'] ? (string) $context['post_content_before'] : null,
            'has_page_manifest' => $current_state['has_page_manifest'],
            'page_manifest' => $current_state['has_page_manifest']
                ? (array) ( $context['page_manifest_before'] ?? array( 'exists' => false, 'value' => null ) )
                : null,
        );

        // Restore A in verified stages. Any stage failing -- real or, in a test, deliberately
        // injected via TEST_FAIL_STAGE_FILTER -- compensates back to the exact B captured above
        // rather than leaving the page in a half-restored blend of A and B.
        $restore = $this->apply_rollback_stages( $post_id, $target_state, 'restore' );
        if ( ! $restore['ok'] ) {
            return $this->compensate_and_build_error( $post_id, $entry_id, $current_state, $restore['failed_stage'] );
        }

        $this->best_effort_invalidate_caches( $post_id );

        // Only mark the ledger entry rolled_back_at once every restore stage above is verified
        // AND this final durable write is itself verified. A page correctly restored to A but
        // whose ledger durably still says "not rolled back" is exactly the inconsistent state
        // that would make a later retry misfire as a false conflict (the live document would no
        // longer match this entry's recorded after_hash) -- so this failing also compensates
        // back to B rather than leaving that mismatch behind.
        $ledger_marked = false;
        if ( ! apply_filters( self::TEST_FAIL_STAGE_FILTER, false, 'before-ledger-mark', $post_id ) ) {
            $entries = $this->all();
            $found = false;
            foreach ( $entries as &$candidate ) {
                if ( ( $candidate['id'] ?? '' ) === ( $entry['id'] ?? '' ) ) {
                    $candidate['rolled_back_at'] = gmdate( 'c' );
                    $found = true;
                    break;
                }
            }
            unset( $candidate );
            // A false return here is never the "value unchanged" false-negative that applies
            // elsewhere in WordPress -- rolled_back_at always changes from '' to a fresh
            // timestamp, so update_option() returning false can only mean a genuine write
            // failure. Re-read it back rather than trusting the return value alone.
            $persisted = $found && false !== update_option( self::OPTION_KEY, $entries, false );
            $reloaded = $persisted ? $this->get( $entry_id ) : null;
            $ledger_marked = $persisted && is_array( $reloaded ) && ! empty( $reloaded['rolled_back_at'] );
        }
        if ( ! $ledger_marked ) {
            return $this->compensate_and_build_error( $post_id, $entry_id, $current_state, 'ledger-mark' );
        }

        $rollback_entry = $this->record(
            'history-rollback',
            'post',
            $post_id,
            $current_state['elementor_data'],
            $target_state['elementor_data'],
            array( 'rollback_of' => $entry['id'], 'conflict_checked' => true )
        );
        return array( 'status' => 'rolled-back', 'post_id' => $post_id, 'entry_id' => $entry['id'], 'rollback_entry' => is_wp_error( $rollback_entry ) ? null : $rollback_entry );
    }

    /**
     * Captures the full live state of everything a rollback might touch, in one shot, before
     * any mutation. This becomes both (a) the source for every pre-mutation conflict check, and
     * (b) -- reused directly as a stage target -- the exact snapshot compensation restores back
     * to if any restore stage later fails. Which optional fields (post_content, page manifest)
     * are even tracked depends on whether the ORIGINAL save recorded both a before and after
     * value for them in context; older entries recorded before that tracking existed simply
     * don't carry those keys, and rollback skips them entirely rather than failing.
     */
    private function capture_rollback_state( $post_id, array $context ) {
        $has_post_content = array_key_exists( 'post_content_before', $context ) && array_key_exists( 'post_content_after', $context );
        $has_page_manifest = array_key_exists( 'page_manifest_before', $context ) && array_key_exists( 'page_manifest_after', $context );

        $owned_meta = array();
        foreach ( array( '_elementor_edit_mode', '_elementor_version', '_elementor_template_type' ) as $meta_key ) {
            $exists = metadata_exists( 'post', $post_id, $meta_key );
            $owned_meta[ $meta_key ] = array( 'exists' => $exists, 'value' => $exists ? get_post_meta( $post_id, $meta_key, true ) : null );
        }

        $page_manifest_exists = metadata_exists( 'post', $post_id, '_design_core_page_manifest' );

        return array(
            'elementor_data' => (string) get_post_meta( $post_id, '_elementor_data', true ),
            'owned_meta' => $owned_meta,
            'has_post_content' => $has_post_content,
            'post_content' => $has_post_content ? (string) get_post_field( 'post_content', $post_id, 'raw' ) : null,
            'has_page_manifest' => $has_page_manifest,
            'page_manifest' => $has_page_manifest
                ? array( 'exists' => $page_manifest_exists, 'value' => $page_manifest_exists ? get_post_meta( $post_id, '_design_core_page_manifest', true ) : null )
                : null,
        );
    }

    /**
     * Writes every tracked field in $target_state, verifying each stage immediately via a
     * write-then-read-back comparison -- never trusting a bare update_post_meta()/
     * wp_update_post() return value alone, since WordPress returns false for a genuine no-op
     * write, not only for a real failure. Used both for restoring the approved BEFORE state (A,
     * $mode='restore') and, on any failure, for compensating back to the captured pre-rollback
     * CURRENT state (B, $mode='compensation') -- identical mechanics either way, only the
     * target and the injected-failure stage-name prefix differ. Stops at the first failing
     * stage (real or test-injected) rather than continuing to mutate further fields once one is
     * already unverified, and reports exactly which stage that was.
     */
    private function apply_rollback_stages( $post_id, array $target_state, $mode ) {
        $prefix = 'compensation' === $mode ? 'compensation-' : '';

        if ( '' === $prefix && apply_filters( self::TEST_FAIL_STAGE_FILTER, false, 'before-restore', $post_id ) ) {
            return array( 'ok' => false, 'failed_stage' => 'before-restore' );
        }

        // STAGE 1: _elementor_data
        $target_data = (string) $target_state['elementor_data'];
        if ( '' === $target_data ) { delete_post_meta( $post_id, '_elementor_data' ); }
        else { update_post_meta( $post_id, '_elementor_data', wp_slash( $target_data ) ); }
        $now_data = (string) get_post_meta( $post_id, '_elementor_data', true );
        $stage_ok = hash_equals( self::hash_value( $target_data ), self::hash_value( $now_data ) );
        if ( $stage_ok && apply_filters( self::TEST_FAIL_STAGE_FILTER, false, "{$prefix}after-elementor-data", $post_id ) ) { $stage_ok = false; }
        if ( ! $stage_ok ) { return array( 'ok' => false, 'failed_stage' => 'elementor-data' ); }

        // STAGE 2: owned Elementor companion metadata (_elementor_edit_mode/_version/_template_type)
        $stage_ok = true;
        foreach ( (array) $target_state['owned_meta'] as $meta_key => $meta_state ) {
            if ( ! is_array( $meta_state ) || ! array_key_exists( 'exists', $meta_state ) ) { continue; }
            if ( ! empty( $meta_state['exists'] ) ) { update_post_meta( $post_id, $meta_key, $meta_state['value'] ); }
            else { delete_post_meta( $post_id, $meta_key ); }
            $now_exists = metadata_exists( 'post', $post_id, $meta_key );
            $now_value = $now_exists ? get_post_meta( $post_id, $meta_key, true ) : null;
            if ( $now_exists !== ! empty( $meta_state['exists'] ) || ( $now_exists && $now_value !== $meta_state['value'] ) ) { $stage_ok = false; }
        }
        if ( $stage_ok && apply_filters( self::TEST_FAIL_STAGE_FILTER, false, "{$prefix}after-owned-meta", $post_id ) ) { $stage_ok = false; }
        if ( ! $stage_ok ) { return array( 'ok' => false, 'failed_stage' => 'owned-meta' ); }

        // STAGE 3: post_content (only for entries that tracked it)
        if ( $target_state['has_post_content'] ) {
            $target_content = (string) $target_state['post_content'];
            wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $target_content ) ) );
            $now_content = (string) get_post_field( 'post_content', $post_id, 'raw' );
            $stage_ok = hash_equals( self::hash_value( $target_content ), self::hash_value( $now_content ) );
            if ( $stage_ok && apply_filters( self::TEST_FAIL_STAGE_FILTER, false, "{$prefix}after-post-content", $post_id ) ) { $stage_ok = false; }
            if ( ! $stage_ok ) { return array( 'ok' => false, 'failed_stage' => 'post-content' ); }
        }

        // STAGE 4: Design Core page manifest (only for entries that tracked it)
        if ( $target_state['has_page_manifest'] ) {
            $manifest_state = (array) $target_state['page_manifest'];
            if ( ! empty( $manifest_state['exists'] ) ) { update_post_meta( $post_id, '_design_core_page_manifest', $manifest_state['value'] ); }
            else { delete_post_meta( $post_id, '_design_core_page_manifest' ); }
            $now_exists = metadata_exists( 'post', $post_id, '_design_core_page_manifest' );
            $now_value = $now_exists ? get_post_meta( $post_id, '_design_core_page_manifest', true ) : null;
            $stage_ok = hash_equals(
                self::hash_value( array( 'exists' => ! empty( $manifest_state['exists'] ), 'value' => $manifest_state['value'] ?? null ) ),
                self::hash_value( array( 'exists' => $now_exists, 'value' => $now_value ) )
            );
            if ( $stage_ok && apply_filters( self::TEST_FAIL_STAGE_FILTER, false, "{$prefix}after-page-manifest", $post_id ) ) { $stage_ok = false; }
            if ( ! $stage_ok ) { return array( 'ok' => false, 'failed_stage' => 'page-manifest' ); }
        }

        return array( 'ok' => true, 'failed_stage' => null );
    }

    /**
     * Called whenever any restore-to-A stage (including the final ledger-mark) fails: attempts
     * to compensate the page back to the exact pre-rollback CURRENT/B snapshot, verifies it
     * landed exactly, records a secret-free diagnostic, and returns the appropriate distinct
     * WP_Error -- "compensated" if B was fully restored, or the more serious
     * "compensation_failed" if it could not be. Neither path ever marks rolled_back_at or
     * returns anything resembling success.
     */
    private function compensate_and_build_error( $post_id, $entry_id, array $current_state, $failed_restore_stage ) {
        $compensation = $this->apply_rollback_stages( $post_id, $current_state, 'compensation' );
        $this->best_effort_invalidate_caches( $post_id );
        if ( $compensation['ok'] ) {
            $this->record_rollback_diagnostic( $post_id, $entry_id, 'compensated', $failed_restore_stage, null );
            return new WP_Error(
                'design_core_history_restore_failed_compensated',
                sprintf(
                    'Rollback failed at stage "%s"; the page was successfully compensated back to its exact pre-rollback state. This history entry remains not rolled back and may be retried once the underlying failure is corrected.',
                    $failed_restore_stage
                )
            );
        }
        $this->record_rollback_diagnostic( $post_id, $entry_id, 'compensation-failed', $failed_restore_stage, $compensation['failed_stage'] );
        return new WP_Error(
            'design_core_history_compensation_failed',
            sprintf(
                'Rollback failed at stage "%s" AND compensation back to the pre-rollback state failed at stage "%s". The page may be in a partially-restored state and requires manual investigation. This history entry was NOT marked rolled back.',
                $failed_restore_stage,
                (string) $compensation['failed_stage']
            )
        );
    }

    /** Cache invalidation is always best-effort: never let it mask the real rollback/compensation result, in either the success or failure path. */
    private function best_effort_invalidate_caches( $post_id ) {
        try {
            if ( class_exists( 'Design_Core_Elementor_Persistence_Service' ) ) {
                ( new Design_Core_Elementor_Persistence_Service() )->invalidate_caches( $post_id );
            } else {
                delete_post_meta( $post_id, '_elementor_element_cache' );
            }
        } catch ( Throwable $exception ) { /* best-effort only -- never mask the real result. */ }
    }

    /**
     * Secret-free diagnostic record for a failed (or failed-and-compensated) rollback -- never
     * the machine credential, MCP token, raw Elementor JSON, or full post content, only enough
     * to identify which stage failed and where. Best-effort: a missing/broken evidence sink
     * never blocks the rollback result itself from being returned correctly.
     */
    private function record_rollback_diagnostic( $post_id, $entry_id, $outcome, $failed_restore_stage, $failed_compensation_stage ) {
        if ( ! class_exists( 'Design_Core_Elementor_Runtime_Evidence' ) ) { return; }
        try {
            ( new Design_Core_Elementor_Runtime_Evidence() )->record(
                'history-rollback-failure',
                'compensation-failed' === $outcome ? 'fail' : 'pass',
                array(
                    'post_id' => (int) $post_id,
                    'entry_id' => (string) $entry_id,
                    'outcome' => $outcome,
                    'failed_restore_stage' => (string) $failed_restore_stage,
                    'failed_compensation_stage' => null === $failed_compensation_stage ? null : (string) $failed_compensation_stage,
                ),
                'history-rollback'
            );
        } catch ( Throwable $exception ) { /* diagnostics are best-effort -- never mask the real rollback error. */ }
    }

    public static function hash_value( $value ) {
        if ( is_string( $value ) ) { $serialized = $value; }
        else { $serialized = (string) wp_json_encode( self::transport_safe( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }
        return hash( 'sha256', $serialized );
    }

    public static function transport_safe( $value, $depth = 0 ) {
        if ( $depth > 10 ) { return '[max-depth]'; }
        if ( null === $value || is_scalar( $value ) ) { return $value; }
        if ( is_resource( $value ) ) { return '[resource]'; }
        if ( $value instanceof Closure ) { return '[closure]'; }
        if ( is_object( $value ) ) { return '[object ' . get_class( $value ) . ']'; }
        if ( ! is_array( $value ) ) { return '[' . gettype( $value ) . ']'; }
        $safe = array(); $count = 0;
        foreach ( $value as $key => $item ) {
            if ( $count++ >= 1000 ) { $safe['__truncated__'] = true; break; }
            $safe[ $key ] = self::transport_safe( $item, $depth + 1 );
        }
        return $safe;
    }

    private function store_payload( $name, $value ) {
        $safe = self::transport_safe( $value );
        $json = wp_json_encode( array( 'value' => $safe ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $json ) { return new WP_Error( 'design_core_history_encode_failed', 'Unable to encode history payload.' ); }
        if ( strlen( $json ) <= self::INLINE_BYTES ) { return array( 'storage' => 'inline', 'value' => $safe ); }
        if ( ! function_exists( 'wp_upload_dir' ) ) { return array( 'storage' => 'hash-only', 'bytes' => strlen( $json ) ); }
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) { return array( 'storage' => 'hash-only', 'bytes' => strlen( $json ) ); }
        $dir = trailingslashit( $upload['basedir'] ) . 'design-core-history';
        if ( ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) { return array( 'storage' => 'hash-only', 'bytes' => strlen( $json ) ); }
        $relative = 'design-core-history/' . sanitize_file_name( $name ) . '.json';
        $file = trailingslashit( $upload['basedir'] ) . $relative;
        $written = @file_put_contents( $file, $json, LOCK_EX );
        if ( false === $written ) { return array( 'storage' => 'hash-only', 'bytes' => strlen( $json ) ); }
        return array( 'storage' => 'blob', 'file' => $relative, 'bytes' => (int) $written );
    }

    private function load_payload( $descriptor ) {
        if ( ! is_array( $descriptor ) ) { return new WP_Error( 'design_core_history_payload_invalid', 'History payload descriptor is invalid.' ); }
        if ( 'inline' === ( $descriptor['storage'] ?? '' ) ) { return $descriptor['value'] ?? null; }
        $file = $this->payload_file_path( $descriptor );
        if ( 'blob' === ( $descriptor['storage'] ?? '' ) && $file && is_readable( $file ) ) {
            $decoded = json_decode( (string) file_get_contents( $file ), true );
            if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) && array_key_exists( 'value', $decoded ) ) { return $decoded['value']; }
        }
        return new WP_Error( 'design_core_history_payload_unavailable', 'Rollback payload is unavailable.' );
    }

    private function payload_file_path( array $descriptor ) {
        if ( 'blob' !== ( $descriptor['storage'] ?? '' ) || empty( $descriptor['file'] ) || ! function_exists( 'wp_upload_dir' ) ) { return ''; }
        $relative = ltrim( str_replace( array( '..', '\\' ), array( '', '/' ), (string) $descriptor['file'] ), '/' );
        if ( 0 !== strpos( $relative, 'design-core-history/' ) ) { return ''; }
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) { return ''; }
        return trailingslashit( $upload['basedir'] ) . $relative;
    }

    private function delete_payload_files( array $entry ) {
        foreach ( array( 'before', 'after' ) as $side ) {
            $descriptor = is_array( $entry[ $side ] ?? null ) ? $entry[ $side ] : array();
            $file = $this->payload_file_path( $descriptor );
            if ( 'blob' === ( $descriptor['storage'] ?? '' ) && $file && is_file( $file ) ) { @unlink( $file ); }
        }
    }
}
