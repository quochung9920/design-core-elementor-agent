<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded, auto-expiring approval tickets backing the READ -> PREVIEW -> APPROVAL ->
 * EXECUTE -> VERIFY workflow. Backed by transients (never a growing option) since a
 * ticket is only useful for a short window and must never survive indefinitely.
 *
 * plan_hash locks the exact BuildPlan that was previewed; current_page_hash locks the
 * exact page state the preview was computed against. Both are re-checked at execute
 * time so an approved preview can never be replayed against a page that has since
 * changed, and execution never silently re-plans from scratch.
 */
class Design_Core_Elementor_Preview_Ticket_Store {
    const PREFIX = 'design_core_elementor_preview_';

    public static function create( $page_id, array $preview, $adapter_target = 'elementor-v3' ) {
        $page_id = (int) $page_id;
        $id = 'pv_' . bin2hex( random_bytes( 10 ) );
        $ttl = (int) apply_filters( 'design_core_elementor_preview_ttl_seconds', 900 );
        $ir = (array) ( $preview['normalized_ir'] ?? array() );
        $plan = (array) ( $preview['build_plan'] ?? array() );

        $ticket = array(
            'id' => $id,
            'page_id' => $page_id,
            'adapter_target' => sanitize_key( (string) ( $preview['adapter_target'] ?? $adapter_target ) ),
            'ir' => $ir,
            'plan' => $plan,
            'plan_hash' => Design_Core_Elementor_Change_Ledger::hash_value( $plan ),
            'current_page_hash' => $page_id > 0 ? Design_Core_Elementor_Change_Ledger::hash_value( (string) get_post_meta( $page_id, '_elementor_data', true ) ) : '',
            'created_at' => gmdate( 'c' ),
            'expires_at' => gmdate( 'c', time() + $ttl ),
        );
        set_transient( self::PREFIX . $id, $ticket, $ttl );
        return $ticket;
    }

    public static function get( $preview_id ) {
        $preview_id = sanitize_key( (string) $preview_id );
        $ticket = get_transient( self::PREFIX . $preview_id );
        return is_array( $ticket ) ? $ticket : null;
    }

    /**
     * Full approval-gate check for /pages/{id}/update. Returns the stored ticket
     * (including the locked ir/plan) on success, or a WP_Error identifying exactly
     * which guarantee failed.
     */
    public static function validate_for_execute( $preview_id, $page_id, $plan_hash ) {
        $ticket = self::get( $preview_id );
        if ( ! $ticket ) { return new WP_Error( 'design_core_preview_not_found', 'Preview was not found or has expired. Request a new preview.', array( 'status' => 404 ) ); }
        if ( (int) $ticket['page_id'] !== (int) $page_id ) { return new WP_Error( 'design_core_preview_page_mismatch', 'This preview was generated for a different page.', array( 'status' => 409 ) ); }
        if ( ! hash_equals( (string) $ticket['plan_hash'], (string) $plan_hash ) ) { return new WP_Error( 'design_core_plan_hash_mismatch', 'plan_hash does not match the approved preview.', array( 'status' => 409 ) ); }
        $current_hash = Design_Core_Elementor_Change_Ledger::hash_value( (string) get_post_meta( (int) $page_id, '_elementor_data', true ) );
        if ( ! hash_equals( (string) $ticket['current_page_hash'], $current_hash ) ) {
            return new WP_Error( 'design_core_page_conflict', 'The page changed since this preview was generated. Request a new preview before updating.', array( 'status' => 409 ) );
        }
        return $ticket;
    }

    public static function consume( $preview_id ) {
        delete_transient( self::PREFIX . sanitize_key( (string) $preview_id ) );
    }
}
