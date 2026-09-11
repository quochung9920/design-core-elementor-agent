<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Owner lock for the direct Design Core REST API used by ChatGPT GPT Actions.
 *
 * The first administrator who explicitly claims ownership becomes the only WordPress
 * user allowed to mint dcapi_* credentials or change the owner API configuration.
 * This is intentionally independent from the legacy MCP/machine credential settings.
 */
final class Design_Core_Elementor_API_Access_Settings {
    const OPTION_KEY = 'design_core_elementor_owner_api_settings';
    const SCHEMA_VERSION = 1;

    public static function get() {
        $stored = get_option( self::OPTION_KEY, array() );
        $stored = is_array( $stored ) ? $stored : array();
        return array_merge( array(
            'schema_version' => self::SCHEMA_VERSION,
            'enabled' => false,
            'owner_user_id' => 0,
            'claimed_at' => '',
            'updated_at' => '',
        ), $stored );
    }

    public static function enabled() {
        $settings = self::get();
        return ! empty( $settings['enabled'] );
    }

    public static function owner_user_id() {
        $settings = self::get();
        return max( 0, (int) ( $settings['owner_user_id'] ?? 0 ) );
    }

    public static function owner_claimed() {
        return self::owner_user_id() > 0;
    }

    /** First claim is intentionally explicit and requires a real wp-admin user. */
    public static function claim_for_current_user() {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'design_core_api_owner_forbidden', 'Administrator capability is required to claim Design Core API ownership.', array( 'status' => 403 ) );
        }
        $current = (int) get_current_user_id();
        if ( $current <= 0 ) {
            return new WP_Error( 'design_core_api_owner_user_required', 'A real authenticated WordPress user is required to claim API ownership.', array( 'status' => 403 ) );
        }
        $settings = self::get();
        $existing = (int) ( $settings['owner_user_id'] ?? 0 );
        if ( $existing > 0 && $existing !== $current ) {
            return new WP_Error( 'design_core_api_owner_already_claimed', 'Design Core API ownership is already locked to another WordPress user.', array( 'status' => 409 ) );
        }
        $now = gmdate( 'c' );
        $settings['owner_user_id'] = $current;
        $settings['claimed_at'] = $settings['claimed_at'] ?: $now;
        $settings['updated_at'] = $now;
        if ( false === update_option( self::OPTION_KEY, $settings, false ) ) {
            $after = self::get();
            if ( (int) ( $after['owner_user_id'] ?? 0 ) !== $current ) {
                return new WP_Error( 'design_core_api_owner_write_failed', 'Unable to persist the Design Core API owner lock.', array( 'status' => 500 ) );
            }
        }
        return $settings;
    }

    public static function set_enabled_for_current_owner( $enabled ) {
        $guard = self::require_current_owner();
        if ( is_wp_error( $guard ) ) { return $guard; }
        $settings = self::get();
        $settings['enabled'] = (bool) $enabled;
        $settings['updated_at'] = gmdate( 'c' );
        if ( false === update_option( self::OPTION_KEY, $settings, false ) ) {
            $after = self::get();
            if ( (bool) ( $after['enabled'] ?? false ) !== (bool) $enabled ) {
                return new WP_Error( 'design_core_api_settings_write_failed', 'Unable to persist the Design Core API enabled state.', array( 'status' => 500 ) );
            }
        }
        return $settings;
    }

    public static function require_current_owner() {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'design_core_api_owner_forbidden', 'Administrator capability is required.', array( 'status' => 403 ) );
        }
        $owner = self::owner_user_id();
        $current = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $owner <= 0 ) {
            return new WP_Error( 'design_core_api_owner_unclaimed', 'Design Core API ownership has not been claimed yet.', array( 'status' => 409 ) );
        }
        if ( $current !== $owner ) {
            return new WP_Error( 'design_core_api_owner_required', 'Only the configured Design Core API owner may manage API access.', array( 'status' => 403 ) );
        }
        return true;
    }
}
