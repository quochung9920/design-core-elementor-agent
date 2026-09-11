<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Transaction manager for Design Core conversions.
 *
 * Tracks and manages rollback for:
 * - Created posts (new pages created during conversion, deleted on rollback)
 * - Mutated posts (existing pages updated during remote apply, restored on rollback)
 * - Kit settings mutations
 *
 * CRITICAL SAFETY: Existing pages are NEVER deleted on rollback.
 * Only state snapshots are restored, and only when it is provably safe to do so.
 *
 * Shared registries (components/sections/widgets/tokens) are deliberately NOT tracked
 * or restored here. They are concurrently-written option state shared across requests;
 * rewinding one to its begin()-time snapshot on rollback would silently erase writes
 * made by other, unrelated transactions in between. Registry mutation safety is owned
 * by Design_Core_Elementor_Versioned_Registry's own locked read-modify-write contract
 * (acquire_lock()/mutate_items()/write_items()), not by this transaction.
 *
 * EXISTING-PAGE CONFLICT MODEL: an existing page can be edited by someone else (or by
 * another request) between the moment this transaction captures its BEFORE snapshot and
 * the moment it rolls back. "Current no longer equals BEFORE" is NOT proof that this
 * transaction's own mutation is the cause -- it could just as easily be a concurrent
 * edit. So rollback tracks three states per post:
 *   BEFORE         -- captured by track_existing_post_mutation(), first call wins.
 *   EXPECTED_AFTER -- captured by mark_existing_post_expected_state(), called once this
 *                     transaction's OWN mutation has actually landed; latest call wins.
 *   CURRENT        -- read live at rollback() time.
 * Decision table (never inferred, always compared by canonical state hash):
 *   CURRENT == BEFORE          -> no-op, already original.
 *   CURRENT == EXPECTED_AFTER  -> safe to restore BEFORE (this transaction owns the delta).
 *   otherwise                  -> conflict; leave CURRENT untouched, fail closed.
 *   EXPECTED_AFTER never set AND CURRENT != BEFORE -> fail closed (cannot prove ownership).
 */
class Design_Core_Elementor_Conversion_Transaction {
    /** Elementor-native meta keys Design Core's own save path writes; see elementor-v3-adapter.php raw_save_page(). */
    const OWNED_META_KEYS = array( '_elementor_data', '_elementor_edit_mode', '_elementor_version', '_elementor_template_type' );

    private $created_posts = array();
    private $created_attachments = array();
    private $mutated_posts = array();  // post_id => array( before, before_hash, expected_after, expected_after_hash )
    private $kit_snapshot = null;
    private $kit_expected = null;
    private $committed = false;

    public function begin() {
        $this->snapshot_kit();
        return $this;
    }

    /**
     * Track a newly created post during this transaction.
     * On rollback, this post will be deleted permanently.
     */
    public function track_post( $post_id ) {
        if ( $post_id ) { $this->created_posts[] = (int) $post_id; }
    }

    /**
     * Track an existing post being mutated during this transaction.
     * Captures the BEFORE state. Safe to call multiple times; only the first call
     * captures a snapshot -- later calls are no-ops so BEFORE always reflects the
     * page as it was prior to any mutation this transaction makes.
     */
    public function track_existing_post_mutation( $post_id ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || ! get_post( $post_id ) ) { return $this; }
        if ( ! isset( $this->mutated_posts[ $post_id ] ) ) {
            $before = $this->capture_post_state( $post_id );
            $this->mutated_posts[ $post_id ] = array(
                'before' => $before,
                'before_hash' => $this->state_hash( $before ),
                'expected_after' => null,
                'expected_after_hash' => null,
            );
        }
        return $this;
    }

    /**
     * Record the state Design Core's OWN mutation just produced for a tracked post.
     * Call this immediately after this transaction's own write lands, before any later
     * stage that might fail. Unlike track_existing_post_mutation(), the LATEST call wins:
     * if this transaction makes more than one of its own owned-state mutations, each call
     * moves EXPECTED_AFTER forward. A post that was never track_existing_post_mutation()'d
     * has no BEFORE to protect, so this is a no-op for it.
     */
    public function mark_existing_post_expected_state( $post_id ) {
        $post_id = (int) $post_id;
        if ( ! isset( $this->mutated_posts[ $post_id ] ) ) { return $this; }
        $after = $this->capture_post_state( $post_id );
        $this->mutated_posts[ $post_id ]['expected_after'] = $after;
        $this->mutated_posts[ $post_id ]['expected_after_hash'] = $this->state_hash( $after );
        return $this;
    }

    public function track_attachment( $attachment_id ) { if ( $attachment_id ) { $this->created_attachments[] = (int) $attachment_id; } }
    public function track_kit_mutation() { $this->kit_expected = $this->current_kit_settings(); return $this; }
    public function commit() { $this->committed = true; return true; }

    /**
     * Rollback all tracked mutations.
     *
     * Order:
     * 1. Restore existing posts to their pre-mutation state (conflict-aware; see class docblock)
     * 2. Delete created posts permanently
     * 3. Delete created attachments permanently
     * 4. Restore kit settings if unchanged (conflict-aware)
     *
     * @return array {status: rolled-back|rollback-failed|not-rolled-back, errors: [...]}
     */
    public function rollback() {
        if ( $this->committed ) { return array( 'status' => 'not-rolled-back', 'errors' => array( 'transaction-already-committed' ) ); }
        $errors = array();

        // 1. Restore existing posts (NEVER delete; conflict-aware -- see restore_existing_post())
        foreach ( $this->mutated_posts as $post_id => $tracked ) {
            $restore_error = $this->restore_existing_post( $post_id, $tracked );
            if ( $restore_error ) { $errors[] = $restore_error; }
        }

        // 2. Delete created posts (safe to delete, we created them)
        foreach ( array_reverse( array_unique( $this->created_posts ) ) as $post_id ) {
            if ( false === wp_delete_post( $post_id, true ) ) { $errors[] = 'post-delete-failed:' . $post_id; }
        }

        // 3. Delete created attachments
        foreach ( array_reverse( array_unique( $this->created_attachments ) ) as $attachment_id ) {
            if ( false === wp_delete_attachment( $attachment_id, true ) ) { $errors[] = 'attachment-delete-failed:' . $attachment_id; }
        }

        // 4. Restore kit
        $kit_error = $this->restore_kit(); if ( $kit_error ) { $errors[] = $kit_error; }
        $this->created_posts = array(); $this->created_attachments = array();
        return array( 'status' => $errors ? 'rollback-failed' : 'rolled-back', 'errors' => $errors );
    }

    /**
     * Conflict-aware restore for one tracked existing post. Never infers ownership from
     * "current differs from before" alone -- only EXPECTED_AFTER proves this transaction
     * produced the current state. See class docblock for the full decision table.
     *
     * @param int $post_id
     * @param array $tracked {before, before_hash, expected_after, expected_after_hash}
     * @return string|null Stable error code if restore was refused/failed, null on success (incl. no-op).
     */
    private function restore_existing_post( $post_id, array $tracked ) {
        $post_id = (int) $post_id;
        if ( null === $tracked['before'] ) { return null; } // nothing was ever actually captured

        if ( ! get_post( $post_id ) ) { return 'post-rollback-missing:' . $post_id; }

        $current = $this->capture_post_state( $post_id );
        $current_hash = $this->state_hash( $current );

        if ( hash_equals( $tracked['before_hash'], $current_hash ) ) { return null; } // already original; no-op

        if ( null === $tracked['expected_after_hash'] ) {
            // This transaction never recorded what its own mutation should have produced,
            // yet the post no longer matches BEFORE. We cannot tell our own write apart
            // from a concurrent one here -- refuse to guess and fail closed.
            return 'post-rollback-expected-state-missing:' . $post_id;
        }

        if ( ! hash_equals( $tracked['expected_after_hash'], $current_hash ) ) {
            // Current is neither the original state nor the state this transaction itself
            // produced: something else changed the post afterward. Never overwrite it.
            return 'post-rollback-conflict:' . $post_id;
        }

        return $this->apply_post_state( $post_id, $tracked['before'], $tracked['before_hash'] );
    }

    /** Writes a captured state back onto a post and verifies the result by re-hashing. */
    private function apply_post_state( $post_id, array $target_state, $target_hash ) {
        try {
            $updated = wp_update_post( array_merge( array( 'ID' => $post_id ), $target_state['post'] ), true );
            if ( is_wp_error( $updated ) ) { return 'post-restore-update-failed:' . $post_id . ':' . $updated->get_error_message(); }

            foreach ( $target_state['meta'] as $meta_key => $meta_state ) {
                if ( $meta_state['exists'] ) { update_post_meta( $post_id, $meta_key, $meta_state['value'] ); }
                else { delete_post_meta( $post_id, $meta_key ); }
            }

            if ( ! hash_equals( $target_hash, $this->state_hash( $this->capture_post_state( $post_id ) ) ) ) { return 'post-restore-verify-failed:' . $post_id; }
            return null;
        } catch ( Throwable $e ) {
            return 'post-restore-exception:' . $post_id . ':' . $e->getMessage();
        }
    }

    /**
     * Canonical snapshot of the state this transaction owns for a post: the editable post
     * fields plus the Elementor-native meta keys Design Core's save path writes. Each meta
     * key records exists/value separately so "absent" and "present but empty" never collapse
     * into each other -- get_post_meta()'s single-value return can't distinguish them itself.
     *
     * @return array|null null if the post does not exist.
     */
    private function capture_post_state( $post_id ) {
        $post_id = (int) $post_id;
        $post = get_post( $post_id );
        if ( ! $post ) { return null; }

        $state = array(
            'post' => array(
                'post_title' => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_status' => $post->post_status,
                'post_name' => $post->post_name,
                'post_parent' => $post->post_parent,
                'menu_order' => $post->menu_order,
                'comment_status' => $post->comment_status,
                'ping_status' => $post->ping_status,
            ),
            'meta' => array(),
        );
        foreach ( self::OWNED_META_KEYS as $meta_key ) {
            $exists = metadata_exists( 'post', $post_id, $meta_key );
            $state['meta'][ $meta_key ] = array( 'exists' => $exists, 'value' => $exists ? get_post_meta( $post_id, $meta_key, true ) : null );
        }
        return $state;
    }

    /** Deterministic hash of a captured state (or of "no post" when $state is null). */
    private function state_hash( $state ) {
        return hash( 'sha256', wp_json_encode( self::canonicalize( $state ) ) );
    }

    /** Recursively key-sorts arrays so the hash never depends on incidental construction order. */
    private static function canonicalize( $value ) {
        if ( ! is_array( $value ) ) { return $value; }
        $normalized = array();
        foreach ( $value as $key => $item ) { $normalized[ $key ] = self::canonicalize( $item ); }
        ksort( $normalized );
        return $normalized;
    }

    private function snapshot_kit() {
        if ( ! class_exists( '\Elementor\Plugin' ) ) { return; }
        try {
            $manager = \Elementor\Plugin::instance()->kits_manager;
            $kit = $manager && method_exists( $manager, 'get_active_kit' ) ? $manager->get_active_kit() : null;
            if ( $kit ) {
                $this->kit_snapshot = array(
                    'custom_colors' => (array) $kit->get_settings( 'custom_colors' ),
                    'custom_typography' => (array) $kit->get_settings( 'custom_typography' ),
                );
            }
        } catch ( Throwable $e ) { $this->kit_snapshot = null; }
    }

    private function restore_kit() {
        if ( null === $this->kit_snapshot || null === $this->kit_expected || ! class_exists( '\Elementor\Plugin' ) ) { return ''; }
        try {
            $manager = \Elementor\Plugin::instance()->kits_manager;
            $kit = $manager && method_exists( $manager, 'get_active_kit' ) ? $manager->get_active_kit() : null;
            if ( ! $kit || ! method_exists( $kit, 'update_settings' ) ) { return 'kit-restore-unavailable'; }
            if ( $this->current_kit_settings() !== $this->kit_expected ) { return 'kit-rollback-conflict'; }
            $kit->update_settings( $this->kit_snapshot ); return '';
        } catch ( Throwable $e ) { return 'kit-restore-failed:' . $e->getMessage(); }
    }

    private function current_kit_settings() {
        if ( ! class_exists( '\Elementor\Plugin' ) ) { return null; }
        try { $manager = \Elementor\Plugin::instance()->kits_manager; $kit = $manager && method_exists( $manager, 'get_active_kit' ) ? $manager->get_active_kit() : null; return $kit ? array( 'custom_colors' => (array) $kit->get_settings( 'custom_colors' ), 'custom_typography' => (array) $kit->get_settings( 'custom_typography' ) ) : null; }
        catch ( Throwable $exception ) { return null; }
    }

    public function __destruct() {
        try {
            if ( ! $this->committed && ( $this->created_posts || $this->created_attachments || $this->mutated_posts ) ) { $this->rollback(); }
        } catch ( Throwable $e ) {
            // Never let a destructor-time rollback fatal script shutdown; a caller that needs
            // to know about this should call rollback() explicitly instead of relying on GC.
        }
    }
}
