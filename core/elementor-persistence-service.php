<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed persistence boundary for Elementor documents.
 *
 * A save is only considered successful after: raw save -> cache invalidation ->
 * reload -> render verification. The service also records a conflict-aware history
 * entry when the Change Ledger is available.
 */
class Design_Core_Elementor_Persistence_Service {
    const EVIDENCE_VERSION = 1;

    public function save_and_verify( $post_id, $elements, $settings, $raw_save, $reload, $render, array $context = array() ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) { return new WP_Error( 'design_core_persistence_invalid_post', 'A valid post ID is required.' ); }
        if ( ! is_callable( $raw_save ) || ! is_callable( $reload ) || ! is_callable( $render ) ) {
            return new WP_Error( 'design_core_persistence_invalid_callbacks', 'Persistence callbacks are invalid.' );
        }

        $before_data = (string) get_post_meta( $post_id, '_elementor_data', true );
        $before_owned_meta = $this->capture_owned_meta( $post_id );
        $before_post_content = (string) get_post_field( 'post_content', $post_id, 'raw' );
        $this->backup_corrupt_data( $post_id, $before_data );
        $started = microtime( true );

        try { $saved = call_user_func( $raw_save, $post_id, $elements, $settings ); }
        catch ( Throwable $exception ) { return new WP_Error( 'design_core_persistence_save_exception', $exception->getMessage() ); }
        if ( is_wp_error( $saved ) ) { return $saved; }
        if ( false === $saved || null === $saved ) { return new WP_Error( 'design_core_persistence_save_failed', 'Elementor rejected the document save.' ); }

        $cache = $this->invalidate_caches( $post_id );

        try { $reloaded = call_user_func( $reload, $post_id ); }
        catch ( Throwable $exception ) { return new WP_Error( 'design_core_persistence_reload_exception', $exception->getMessage() ); }
        if ( ! is_array( $reloaded ) || empty( $reloaded ) ) { return new WP_Error( 'design_core_persistence_reload_failed', 'Saved Elementor document could not be reloaded.' ); }

        try { $rendered = (string) call_user_func( $render, $post_id ); }
        catch ( Throwable $exception ) { return new WP_Error( 'design_core_persistence_render_exception', $exception->getMessage() ); }
        if ( '' === trim( $rendered ) ) { return new WP_Error( 'design_core_persistence_render_failed', 'Saved Elementor document rendered an empty result.' ); }

        $after_data = (string) get_post_meta( $post_id, '_elementor_data', true );
        if ( '' === $after_data ) { return new WP_Error( 'design_core_persistence_data_missing', 'Elementor data is empty after save.' ); }
        $decoded = json_decode( $after_data, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            return new WP_Error( 'design_core_persistence_data_invalid', 'Elementor data is not valid JSON after save.' );
        }

        $evidence = array(
            'schema_version' => self::EVIDENCE_VERSION,
            'status' => 'pass',
            'post_id' => $post_id,
            'adapter' => sanitize_key( (string) ( $context['adapter'] ?? '' ) ),
            'expected_root_elements' => is_array( $elements ) ? count( $elements ) : 0,
            'reloaded_root_elements' => count( $reloaded ),
            'rendered_bytes' => strlen( $rendered ),
            'before_hash' => Design_Core_Elementor_Change_Ledger::hash_value( $before_data ),
            'after_hash' => Design_Core_Elementor_Change_Ledger::hash_value( $after_data ),
            'cache' => $cache,
            'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
        );

        if ( empty( $context['skip_history'] ) && class_exists( 'Design_Core_Elementor_Change_Ledger' ) && $evidence['before_hash'] !== $evidence['after_hash'] ) {
            $history = ( new Design_Core_Elementor_Change_Ledger() )->record(
                'elementor-save',
                'post',
                $post_id,
                $before_data,
                $after_data,
                array_merge( $context, array(
                    'persistence_evidence' => $evidence,
                    // Elementor's own save always writes _elementor_data alongside these -- carried
                    // here so Change_Ledger::rollback() can restore the exact prior exists/absent
                    // state for all four, not just the raw document blob.
                    'owned_meta_before' => $before_owned_meta,
                    'owned_meta_after' => $this->capture_owned_meta( $post_id ),
                    // Elementor's own Db::save_plain_text() (core/base/document.php -> save_elements())
                    // unconditionally overwrites post_content with a plain-text render of the widget
                    // tree on every save, independently of anything Design Core does. Unlike the meta
                    // keys above, post_content CAN also be changed independently of _elementor_data
                    // (Classic Editor, REST, other plugins), so rollback must conflict-check it against
                    // its own recorded after-state, not just blindly restore it.
                    'post_content_before' => $before_post_content,
                    'post_content_after' => (string) get_post_field( 'post_content', $post_id, 'raw' ),
                ) )
            );
            if ( ! is_wp_error( $history ) ) { $evidence['history_entry_id'] = $history['id'] ?? ''; }
        }

        if ( class_exists( 'Design_Core_Elementor_Runtime_Evidence' ) ) {
            ( new Design_Core_Elementor_Runtime_Evidence() )->record( 'elementor-persistence-service', 'pass', $evidence, 'persistence-service' );
        }
        return $evidence;
    }

    /** Public because History and repair tools need the exact same invalidation path. */
    public function invalidate_caches( $post_id ) {
        $post_id = (int) $post_id;
        $result = array( 'element_cache' => false, 'post_css' => false, 'files_manager' => false );
        if ( $post_id <= 0 ) { return $result; }

        if ( function_exists( 'delete_post_meta' ) ) {
            delete_post_meta( $post_id, '_elementor_element_cache' );
            $result['element_cache'] = true;
        }

        $css_class = '\\Elementor\\Core\\Files\\CSS\\Post';
        if ( class_exists( $css_class ) && method_exists( $css_class, 'create' ) ) {
            try {
                $css = $css_class::create( $post_id );
                if ( is_object( $css ) && method_exists( $css, 'delete' ) ) { $css->delete(); $result['post_css'] = true; }
            } catch ( Throwable $exception ) { $result['post_css_error'] = $exception->getMessage(); }
        }

        if ( class_exists( '\\Elementor\\Plugin' ) ) {
            try {
                $plugin = \Elementor\Plugin::instance();
                if ( isset( $plugin->files_manager ) && is_object( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
                    $plugin->files_manager->clear_cache();
                    $result['files_manager'] = true;
                }
            } catch ( Throwable $exception ) { $result['files_manager_error'] = $exception->getMessage(); }
        }
        return $result;
    }

    /**
     * exists/value snapshot of the Elementor-native meta keys a save writes alongside
     * _elementor_data, so a later Change_Ledger rollback can restore "absent" exactly rather
     * than leaving them present-but-stale after reverting the document itself.
     */
    private function capture_owned_meta( $post_id ) {
        $state = array();
        foreach ( array( '_elementor_edit_mode', '_elementor_version', '_elementor_template_type' ) as $meta_key ) {
            $exists = metadata_exists( 'post', (int) $post_id, $meta_key );
            $state[ $meta_key ] = array( 'exists' => $exists, 'value' => $exists ? get_post_meta( (int) $post_id, $meta_key, true ) : null );
        }
        return $state;
    }

    private function backup_corrupt_data( $post_id, $data ) {
        if ( '' === trim( (string) $data ) ) { return; }
        json_decode( (string) $data, true );
        if ( JSON_ERROR_NONE === json_last_error() ) { return; }
        $backup = array(
            'captured_at' => gmdate( 'c' ),
            'json_error' => json_last_error_msg(),
            'data' => (string) $data,
        );
        update_post_meta( (int) $post_id, '_design_core_corrupt_elementor_data_backup', $backup );
    }
}
