<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Security_Policy {
    public function validate_remote_url( $url ) {
        $url = esc_url_raw( (string) $url );
        if ( ! $url || ! wp_http_validate_url( $url ) ) { return new WP_Error( 'design_core_invalid_url', 'Remote asset URL is invalid.' ); }
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) { return new WP_Error( 'design_core_invalid_scheme', 'Only HTTP and HTTPS assets are allowed.' ); }
        $host = strtolower( (string) $parts['host'] );
        if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) || '.local' === substr( $host, -6 ) ) { return new WP_Error( 'design_core_private_host', 'Private network hosts are not allowed.' ); }
        $ips = $this->resolve_ips( $host );
        if ( empty( $ips ) ) { return new WP_Error( 'design_core_unresolved_host', 'Remote host could not be resolved.' ); }
        foreach ( $ips as $ip ) { if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { return new WP_Error( 'design_core_private_ip', 'Private or reserved network addresses are not allowed.' ); } }
        return $url;
    }

    public function preflight_remote_asset( $url ) {
        $validated = $this->validate_remote_url( $url );
        if ( is_wp_error( $validated ) ) { return $validated; }
        $response = wp_safe_remote_head( $validated, array( 'timeout' => 10, 'redirection' => 3, 'reject_unsafe_urls' => true ) );
        if ( is_wp_error( $response ) ) { return $response; }
        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 400 ) { return new WP_Error( 'design_core_asset_unavailable', 'Remote asset did not return a successful response.' ); }
        $length = (int) wp_remote_retrieve_header( $response, 'content-length' );
        $max = (int) apply_filters( 'design_core_elementor_max_asset_bytes', 20 * 1024 * 1024 );
        if ( $length > 0 && $max > 0 && $length > $max ) { return new WP_Error( 'design_core_asset_too_large', 'Remote asset exceeds the configured size limit.' ); }
        $mime = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
        if ( false !== strpos( $mime, ';' ) ) { $mime = trim( strtok( $mime, ';' ) ); }
        $allowed = (array) apply_filters( 'design_core_elementor_allowed_remote_image_mimes', array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ) );
        if ( $mime && ! in_array( $mime, $allowed, true ) ) { return new WP_Error( 'design_core_asset_mime_rejected', 'Remote asset MIME type is not allowed.' ); }
        return array( 'url' => $validated, 'content_length' => $length, 'mime' => $mime );
    }

    public function validate_source_size( $html, $css = '' ) {
        $html_limit = (int) apply_filters( 'design_core_elementor_max_html_bytes', 2 * 1024 * 1024 );
        $css_limit = (int) apply_filters( 'design_core_elementor_max_css_bytes', 1024 * 1024 );
        if ( strlen( (string) $html ) > $html_limit ) { return new WP_Error( 'design_core_html_too_large', 'HTML input exceeds the configured safety limit.' ); }
        if ( strlen( (string) $css ) > $css_limit ) { return new WP_Error( 'design_core_css_too_large', 'CSS input exceeds the configured safety limit.' ); }
        return true;
    }

    private function resolve_ips( $host ) {
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) { return array( $host ); }
        $records = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_A | DNS_AAAA ) : array();
        $ips = array();
        foreach ( is_array( $records ) ? $records : array() as $record ) { if ( ! empty( $record['ip'] ) ) { $ips[] = $record['ip']; } if ( ! empty( $record['ipv6'] ) ) { $ips[] = $record['ipv6']; } }
        if ( empty( $ips ) ) { $fallback = @gethostbyname( $host ); if ( $fallback && $fallback !== $host ) { $ips[] = $fallback; } }
        return array_values( array_unique( $ips ) );
    }
}
