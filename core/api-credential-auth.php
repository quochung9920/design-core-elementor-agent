<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Strict owner-only Bearer auth for the direct Design Core REST/GPT Actions API. */
class Design_Core_Elementor_API_Credential_Auth {
    const TOKEN_PATTERN = '/^dcapi_([a-f0-9]{16})_([A-Za-z0-9_-]{40,64})$/';

    /**
     * Unlike the legacy remote v2 auth, this API never falls back to a cookie session.
     * Every /design-core/v1 operation except the public OpenAPI document requires a dcapi_* key.
     */
    public static function resolve_from_request( WP_REST_Request $request ) {
        if ( ! Design_Core_Elementor_API_Access_Settings::enabled() ) {
            return new WP_Error( 'design_core_api_disabled', 'The owner-only Design Core API is disabled.', array( 'status' => 423 ) );
        }
        $owner_id = Design_Core_Elementor_API_Access_Settings::owner_user_id();
        if ( $owner_id <= 0 ) {
            return new WP_Error( 'design_core_api_owner_unclaimed', 'The Design Core API owner has not been configured.', array( 'status' => 503 ) );
        }

        $header = (string) $request->get_header( 'authorization' );
        if ( '' === trim( $header ) ) { $header = self::fallback_authorization_header(); }
        if ( '' === trim( $header ) ) {
            return new WP_Error( 'design_core_api_auth_required', 'Authorization: Bearer <dcapi token> is required.', array( 'status' => 401 ) );
        }
        if ( 0 !== stripos( trim( $header ), 'bearer ' ) ) {
            return new WP_Error( 'design_core_api_auth_scheme_invalid', 'The Design Core API requires Bearer authentication.', array( 'status' => 401 ) );
        }

        $token = trim( substr( trim( $header ), 7 ) );
        if ( ! preg_match( self::TOKEN_PATTERN, $token, $matches ) ) {
            return new WP_Error( 'design_core_api_credential_malformed', 'The bearer token is not a recognized Design Core API credential.', array( 'status' => 401 ) );
        }
        list( , $id, $secret ) = $matches;

        $registry = new Design_Core_Elementor_API_Credential_Registry();
        $credential = $registry->find( $id );
        if ( ! $credential ) {
            return new WP_Error( 'design_core_api_credential_unknown', 'Design Core API credential was not found.', array( 'status' => 401 ) );
        }
        if ( ! empty( $credential['revoked_at'] ) ) {
            return new WP_Error( 'design_core_api_credential_revoked', 'This Design Core API credential has been revoked.', array( 'status' => 401 ) );
        }
        $expires_at = (string) ( $credential['expires_at'] ?? '' );
        if ( $expires_at && strtotime( $expires_at ) !== false && strtotime( $expires_at ) <= time() ) {
            return new WP_Error( 'design_core_api_credential_expired', 'This Design Core API credential has expired. Rotate it in wp-admin.', array( 'status' => 401 ) );
        }
        if ( ! hash_equals( (string) ( $credential['token_hash'] ?? '' ), hash( 'sha256', $secret ) ) ) {
            return new WP_Error( 'design_core_api_credential_invalid', 'Design Core API credential token is invalid.', array( 'status' => 401 ) );
        }
        if ( (int) ( $credential['created_by'] ?? 0 ) !== $owner_id ) {
            return new WP_Error( 'design_core_api_owner_required', 'This API credential is not owned by the configured Design Core API owner.', array( 'status' => 403 ) );
        }

        $credential_env = strtolower( trim( (string) ( $credential['environment'] ?? '' ) ) );
        $site_env = strtolower( trim( (string) Design_Core_Elementor_Remote_Settings::environment() ) );
        if ( '' === $credential_env || $credential_env !== $site_env ) {
            return new WP_Error(
                'design_core_api_credential_environment_mismatch',
                'API credential environment does not match the target site environment.',
                array( 'status' => 403, 'credential_env' => $credential_env, 'site_env' => $site_env )
            );
        }

        if ( function_exists( 'user_can' ) && ! user_can( $owner_id, 'manage_options' ) ) {
            return new WP_Error( 'design_core_api_owner_capability_lost', 'The configured API owner is no longer a WordPress administrator.', array( 'status' => 403 ) );
        }

        $limited = self::enforce_rate_limit( $id );
        if ( is_wp_error( $limited ) ) { return $limited; }

        $registry->record_use( $id );
        if ( function_exists( 'wp_set_current_user' ) ) { wp_set_current_user( $owner_id ); }

        // Return type=credential so existing Design Core write guards/audit logic remain authoritative.
        return array(
            'type' => 'credential',
            'credential_kind' => 'owner_api',
            'id' => $id,
            'name' => (string) ( $credential['name'] ?? '' ),
            'scopes' => array_values( (array) ( $credential['scopes'] ?? array() ) ),
            'environment' => $credential_env,
            'owner_user_id' => $owner_id,
        );
    }

    public static function principal_has_scope( array $principal, $scope ) {
        return in_array( sanitize_key( (string) $scope ), (array) ( $principal['scopes'] ?? array() ), true );
    }

    private static function fallback_authorization_header() {
        if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) { return (string) $_SERVER['HTTP_AUTHORIZATION']; }
        if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) { return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']; }
        $headers = function_exists( 'getallheaders' ) ? getallheaders() : ( function_exists( 'apache_request_headers' ) ? apache_request_headers() : array() );
        if ( ! is_array( $headers ) ) { return ''; }
        foreach ( $headers as $name => $value ) {
            if ( 0 === strcasecmp( (string) $name, 'authorization' ) ) { return (string) $value; }
        }
        return '';
    }

    private static function enforce_rate_limit( $credential_id ) {
        $limit = (int) apply_filters( 'design_core_elementor_owner_api_rate_limit', 120 );
        $window = (int) apply_filters( 'design_core_elementor_owner_api_rate_window', 60 );
        if ( $limit <= 0 ) { return true; }
        $bucket = (int) floor( time() / max( 1, $window ) );
        $key = 'design_core_owner_api_rl_' . sanitize_key( (string) $credential_id ) . '_' . $bucket;
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            return new WP_Error( 'design_core_api_rate_limited', 'This Design Core API credential exceeded its request rate limit.', array( 'status' => 429 ) );
        }
        set_transient( $key, $count + 1, $window + 5 );
        return true;
    }
}
