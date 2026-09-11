<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Single source of truth for Design Core ability authorization.
 *
 * The ability catalog (GPT_Actions_API::operations()) declares one Design Core
 * capability per operation. This service enforces exactly that capability at
 * runtime -- no second mapping, no owner-user match, no manage_options
 * requirement. The catalog text and the execution check therefore cannot drift
 * apart: both read the same operation definition.
 *
 * What stays owner-gated (unchanged): claiming ownership, enabling/disabling
 * the API, and minting dcapi_* credentials -- all in API_Access_Settings and
 * the credential registry, never on this path.
 *
 * What stays as explicit operator control: the global API enabled() switch.
 */
class Design_Core_Elementor_Permissions {
    /**
     * @return string|null The catalog capability for an operation ID, or null
     *   when the operation does not exist.
     */
    public static function capability_for_operation( $operation_id ) {
        if ( ! class_exists( 'Design_Core_Elementor_GPT_Actions_API' ) ) { return null; }
        foreach ( Design_Core_Elementor_GPT_Actions_API::operations() as $operation ) {
            if ( is_array( $operation ) && (string) ( $operation['operationId'] ?? '' ) === (string) $operation_id ) {
                $capability = sanitize_key( (string) ( $operation['capability'] ?? '' ) );
                return in_array( $capability, Design_Core_Elementor_Capabilities::all(), true ) ? $capability : null;
            }
        }
        return null;
    }

    /**
     * Authorize one catalog operation for the current connector user.
     *
     * @return true|WP_Error True when allowed, WP_Error (401/423/403/404) otherwise.
     */
    public static function check_operation( $operation_id ) {
        $capability = self::capability_for_operation( $operation_id );
        if ( null === $capability ) {
            return new WP_Error( 'design_core_ability_unknown', 'Unknown Design Core ability operation.', array( 'status' => 404 ) );
        }
        return self::check_ability_capability( $capability );
    }

    /**
     * Authorize one Design Core capability for the current connector user.
     *
     * @return true|WP_Error
     */
    public static function check_ability_capability( $capability ) {
        if ( ! function_exists( 'get_current_user_id' ) || get_current_user_id() <= 0 ) {
            return new WP_Error( 'design_core_ability_auth_required', 'An authenticated WordPress user is required.', array( 'status' => 401 ) );
        }
        if ( class_exists( 'Design_Core_Elementor_API_Access_Settings' ) && ! Design_Core_Elementor_API_Access_Settings::enabled() ) {
            return new WP_Error( 'design_core_api_disabled', 'The Design Core API and its abilities are disabled.', array( 'status' => 423 ) );
        }
        $capability = sanitize_key( (string) $capability );
        if ( ! in_array( $capability, Design_Core_Elementor_Capabilities::all(), true ) ) {
            return new WP_Error( 'design_core_ability_scope_forbidden', 'The connected user lacks the required Design Core capability.', array( 'status' => 403 ) );
        }
        if ( ! current_user_can( $capability ) ) {
            return new WP_Error( 'design_core_ability_scope_forbidden', 'The connected user lacks the required Design Core capability.', array( 'status' => 403 ) );
        }
        return true;
    }
}
