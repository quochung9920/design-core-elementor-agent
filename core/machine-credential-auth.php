<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Verifies `Authorization: Bearer dcmcp_<id>_<secret>` against the Machine Credential
 * Registry. Never impersonates a WP_User/cookie session -- callers get back a small
 * "principal" array and REST v2 checks scopes against it directly.
 */
class Design_Core_Elementor_Machine_Credential_Auth {
    const TOKEN_PATTERN = '/^dcmcp_([a-f0-9]{16})_([A-Za-z0-9_-]{40,64})$/';

    /**
     * @return array|WP_Error|null Principal array when a valid Bearer token was presented,
     *   WP_Error when a Bearer token was presented but is invalid/revoked/rate-limited
     *   (fail closed -- never silently falls back to cookie auth in that case), or null
     *   when no Authorization header was sent at all (caller should fall back to the
     *   normal WordPress session/capability check).
     */
    public static function resolve_from_request( WP_REST_Request $request ) {
        $header = (string) $request->get_header( 'authorization' );
        if ( '' === trim( $header ) ) { $header = self::fallback_authorization_header(); }
        if ( '' === trim( $header ) ) { return null; }
        if ( 0 !== stripos( trim( $header ), 'bearer ' ) ) { return null; }
        $token = trim( substr( trim( $header ), 7 ) );
        if ( ! preg_match( self::TOKEN_PATTERN, $token, $matches ) ) {
            return new WP_Error( 'design_core_credential_malformed', 'The bearer token is not a recognized Design Core machine credential.', array( 'status' => 401 ) );
        }
        list( , $id, $secret ) = $matches;

        $registry = new Design_Core_Elementor_Machine_Credential_Registry();
        $credential = $registry->find( $id );
        if ( ! $credential ) { return new WP_Error( 'design_core_credential_unknown', 'Machine credential was not found.', array( 'status' => 401 ) ); }
        if ( ! empty( $credential['revoked_at'] ) ) { return new WP_Error( 'design_core_credential_revoked', 'This machine credential has been revoked.', array( 'status' => 401 ) ); }
        if ( ! hash_equals( (string) $credential['token_hash'], hash( 'sha256', $secret ) ) ) {
            return new WP_Error( 'design_core_credential_invalid', 'Machine credential token is invalid.', array( 'status' => 401 ) );
        }

        $limited = self::enforce_rate_limit( $id );
        if ( is_wp_error( $limited ) ) { return $limited; }

        $registry->record_use( $id );
        self::act_as_credential_owner( $credential );
        return array(
            'type' => 'credential',
            'id' => $id,
            'name' => (string) ( $credential['name'] ?? '' ),
            'scopes' => (array) ( $credential['scopes'] ?? array() ),
            'environment' => (string) ( $credential['environment'] ?? '' ),
        );
    }

    /**
     * WordPress's own header parsing (WP_REST_Request) only reads $_SERVER['HTTP_*'] --
     * Apache running as mod_php commonly never populates HTTP_AUTHORIZATION there at all
     * (a well-known gap; WP core's own Application Passwords feature has the same
     * documented caveat and requires a server-config workaround for it). getallheaders()/
     * apache_request_headers() read Apache's request record directly and do see it, so
     * fall back to those before giving up -- this makes Bearer auth work out of the box on
     * a stock Apache+mod_php WordPress install without requiring an .htaccess change.
     */
    private static function fallback_authorization_header() {
        if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) { return (string) $_SERVER['HTTP_AUTHORIZATION']; }
        if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) { return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']; }
        $headers = function_exists( 'getallheaders' ) ? getallheaders() : ( function_exists( 'apache_request_headers' ) ? apache_request_headers() : array() );
        if ( ! is_array( $headers ) ) { return ''; }
        foreach ( $headers as $name => $value ) { if ( 0 === strcasecmp( (string) $name, 'authorization' ) ) { return (string) $value; } }
        return '';
    }

    /**
     * Elementor's own Document API checks current_user_can( 'edit_post', $post_id ) internally
     * (Document::is_editable_by_current_user() and friends) -- with no WordPress cookie session
     * behind a Bearer-only request, that has no acting user at all and Elementor legitimately
     * refuses the save ("Access denied"), regardless of the credential's Design Core scopes.
     *
     * This does NOT change how design-core-elementor/v2 makes its OWN authorization decisions --
     * REST v2's permission callbacks gate on the credential's scopes (Machine_Credential_Auth::
     * principal_has_scope()), never on current_user_can(). This only gives WordPress/Elementor's
     * *own* internal capability checks a real acting user for the duration of the request, the
     * same way WordPress core Application Passwords and WooCommerce REST API keys both work:
     * a credential is tied to the WP user who created it, and a valid token acts as that user.
     * A credential created with no user context (e.g. via WP-CLI) has no owner to act as, and
     * Elementor-side writes will correctly keep failing for it until it's recreated by a real user.
     */
    private static function act_as_credential_owner( array $credential ) {
        $owner_id = (int) ( $credential['created_by'] ?? 0 );
        if ( $owner_id > 0 && function_exists( 'wp_set_current_user' ) ) { wp_set_current_user( $owner_id ); }
    }

    public static function principal_has_scope( array $principal, $scope ) {
        if ( 'credential' !== ( $principal['type'] ?? '' ) ) { return true; }
        return in_array( sanitize_key( (string) $scope ), (array) ( $principal['scopes'] ?? array() ), true );
    }

    /** Bounded fixed-window counter in a transient -- never an unbounded option. */
    private static function enforce_rate_limit( $credential_id ) {
        $limit = (int) apply_filters( 'design_core_elementor_machine_credential_rate_limit', 120 );
        $window = (int) apply_filters( 'design_core_elementor_machine_credential_rate_window', 60 );
        if ( $limit <= 0 ) { return true; }
        $bucket = (int) floor( time() / max( 1, $window ) );
        $key = 'design_core_elementor_rl_' . $credential_id . '_' . $bucket;
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            return new WP_Error( 'design_core_credential_rate_limited', 'This machine credential has exceeded its request rate limit.', array( 'status' => 429 ) );
        }
        set_transient( $key, $count + 1, $window + 5 );
        return true;
    }
}
