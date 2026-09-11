<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Network/auth boundary for Figma.
 *
 * v3 exports geometry-detected vector composites as exact SVG assets, enriches
 * the source identity with Figma version/structural evidence and keeps a bounded
 * Figma-rendered reference for strict browser verification.
 */
class Design_Core_Elementor_Figma_Transport {
    const VERSION = 3;
    const API_BASE = 'https://api.figma.com/v1';
    const MAX_RESPONSE_BYTES = 12582912;
    const MAX_VECTOR_EXPORTS = 48;

    public function configured() { return '' !== $this->token(); }

    public function parse_url( $url ) {
        $url = trim( (string) $url );
        $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
        if ( ! is_array( $parts ) ) { return new WP_Error( 'design_core_figma_url_invalid', 'Invalid Figma URL.' ); }
        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        if ( ! in_array( $host, array( 'figma.com', 'www.figma.com' ), true ) ) { return new WP_Error( 'design_core_figma_url_host', 'Only figma.com URLs are supported.' ); }
        $segments = array_values( array_filter( explode( '/', trim( (string) ( $parts['path'] ?? '' ), '/' ) ) ) );
        if ( count( $segments ) < 2 || ! in_array( strtolower( $segments[0] ), array( 'design', 'file', 'proto', 'board' ), true ) ) { return new WP_Error( 'design_core_figma_url_path', 'Figma URL does not contain a supported file key.' ); }
        $file_key = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $segments[1] );
        if ( strlen( $file_key ) < 8 ) { return new WP_Error( 'design_core_figma_file_key_invalid', 'Figma file key is invalid.' ); }
        $query = array(); parse_str( (string) ( $parts['query'] ?? '' ), $query );
        $node_id = isset( $query['node-id'] ) ? str_replace( '-', ':', sanitize_text_field( (string) $query['node-id'] ) ) : '';
        return array( 'url' => $url, 'file_key' => $file_key, 'node_id' => $node_id, 'kind' => strtolower( (string) $segments[0] ) );
    }

    /**
     * Read a Figma URL with optional source-fidelity evidence.
     *
     * Options:
     * - resolve_image_fills: map IMAGE fills to temporary CDN URLs.
     * - resolve_vector_assets: export authored vector/icon atoms as SVG.
     * - export_reference: export the selected node as a Figma-rendered PNG.
     */
    public function read_url( $url, array $options = array() ) {
        $parsed = $this->parse_url( $url );
        if ( is_wp_error( $parsed ) ) { return $parsed; }
        $payload = $this->fetch_document( $parsed['file_key'], $parsed['node_id'] );
        if ( is_wp_error( $payload ) ) { return $payload; }

        $document = (array) ( $payload['document'] ?? $payload );
        $source = $parsed;
        $source['version'] = sanitize_text_field( (string) ( $payload['version'] ?? '' ) );
        $source['structural_hash'] = $this->structural_hash( $document );

        $image_fills = ! empty( $options['resolve_image_fills'] ) ? $this->fetch_image_fills( $parsed['file_key'] ) : array();
        if ( is_wp_error( $image_fills ) ) { $image_fills = array(); }

        $vector_assets = array();
        $vector_ids = array();
        if ( ! empty( $options['resolve_vector_assets'] ) ) {
            $vector_ids = $this->collect_vector_ids( $document );
            if ( $vector_ids ) {
                $vector_assets = $this->export_nodes( $parsed['file_key'], $vector_ids, 'svg', 1 );
                if ( is_wp_error( $vector_assets ) ) { $vector_assets = array(); }
            }
            if ( $vector_assets && isset( $payload['document'] ) && is_array( $payload['document'] ) ) {
                $payload['document'] = $this->collapse_exported_vector_nodes( $payload['document'], array_keys( $vector_assets ) );
            }
        }

        $reference = array();
        if ( ! empty( $options['export_reference'] ) ) {
            $root = (array) ( $payload['document'] ?? array() );
            $reference_id = $parsed['node_id'] ?: sanitize_text_field( (string) ( $root['id'] ?? '' ) );
            if ( $reference_id ) {
                $images = $this->export_nodes( $parsed['file_key'], array( $reference_id ), 'png', 1 );
                if ( ! is_wp_error( $images ) && ! empty( $images[ $reference_id ] ) ) {
                    $box = (array) ( $root['absoluteBoundingBox'] ?? array() );
                    $reference = array(
                        'node_id' => $reference_id,
                        'url' => esc_url_raw( (string) $images[ $reference_id ] ),
                        'format' => 'png',
                        'scale' => 1,
                        'width' => isset( $box['width'] ) && is_numeric( $box['width'] ) ? (int) round( (float) $box['width'] ) : 0,
                        'height' => isset( $box['height'] ) && is_numeric( $box['height'] ) ? (int) round( (float) $box['height'] ) : 0,
                    );
                }
            }
        }

        return array(
            'transport_version' => self::VERSION,
            'source' => $source,
            'figma' => $payload,
            'image_fills' => $image_fills,
            'vector_assets' => $vector_assets,
            'reference_image' => $reference,
            'asset_diagnostics' => array(
                'vector_candidates' => count( $vector_ids ),
                'vector_exports' => count( $vector_assets ),
                'composite_vector_resolution' => class_exists( 'Design_Core_Elementor_Figma_Vector_Asset_Resolver' ) ? 'enabled' : 'legacy-leaf-only',
            ),
        );
    }

    public function fetch_document( $file_key, $node_id = '' ) {
        $file_key = $this->file_key( $file_key ); if ( is_wp_error( $file_key ) ) { return $file_key; }
        $node_id = sanitize_text_field( (string) $node_id );
        $path = $node_id ? '/files/' . rawurlencode( $file_key ) . '/nodes?ids=' . rawurlencode( $node_id ) : '/files/' . rawurlencode( $file_key );
        $response = $this->request( $path );
        if ( is_wp_error( $response ) ) { return $response; }
        if ( $node_id ) {
            $entry = $response['nodes'][ $node_id ] ?? null;
            if ( ! is_array( $entry ) || ! is_array( $entry['document'] ?? null ) ) { return new WP_Error( 'design_core_figma_node_missing', 'Requested Figma node was not returned by the API.' ); }
            return array(
                'document' => $entry['document'],
                'components' => (array) ( $response['components'] ?? array() ),
                'componentSets' => (array) ( $response['componentSets'] ?? array() ),
                'styles' => (array) ( $response['styles'] ?? array() ),
                'name' => sanitize_text_field( (string) ( $response['name'] ?? '' ) ),
                'version' => sanitize_text_field( (string) ( $response['version'] ?? '' ) ),
            );
        }
        return $response;
    }

    /** Resolve Figma imageRef values to temporary Figma CDN URLs without downloading. */
    public function fetch_image_fills( $file_key ) {
        $file_key = $this->file_key( $file_key ); if ( is_wp_error( $file_key ) ) { return $file_key; }
        $response = $this->request( '/files/' . rawurlencode( $file_key ) . '/images' );
        if ( is_wp_error( $response ) ) { return $response; }
        $images = (array) ( $response['meta']['images'] ?? $response['images'] ?? array() );
        $safe = array();
        foreach ( $images as $ref => $url ) {
            if ( is_string( $ref ) && is_string( $url ) && preg_match( '#^https://#i', $url ) ) { $safe[ sanitize_text_field( $ref ) ] = esc_url_raw( $url ); }
        }
        return $safe;
    }

    /** Export selected nodes as rendered images; useful for reference evidence and vectors. */
    public function export_nodes( $file_key, array $node_ids, $format = 'png', $scale = 1 ) {
        $file_key = $this->file_key( $file_key ); if ( is_wp_error( $file_key ) ) { return $file_key; }
        $node_ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $node_ids ) ) ) );
        if ( ! $node_ids || count( $node_ids ) > 50 ) { return new WP_Error( 'design_core_figma_export_nodes_invalid', 'Export requires between 1 and 50 Figma node IDs.' ); }
        $format = in_array( strtolower( (string) $format ), array( 'png', 'jpg', 'svg', 'pdf' ), true ) ? strtolower( (string) $format ) : 'png';
        $scale = max( 0.01, min( 4, (float) $scale ) );
        $response = $this->request( '/images/' . rawurlencode( $file_key ) . '?ids=' . rawurlencode( implode( ',', $node_ids ) ) . '&format=' . rawurlencode( $format ) . '&scale=' . rawurlencode( (string) $scale ) );
        if ( is_wp_error( $response ) ) { return $response; }
        $images = array();
        foreach ( (array) ( $response['images'] ?? array() ) as $id => $url ) {
            if ( is_string( $url ) && preg_match( '#^https://#i', $url ) ) { $images[ sanitize_text_field( (string) $id ) ] = esc_url_raw( $url ); }
        }
        return $images;
    }

    private function collect_vector_ids( array $root ) {
        if ( class_exists( 'Design_Core_Elementor_Figma_Vector_Asset_Resolver' ) ) {
            return ( new Design_Core_Elementor_Figma_Vector_Asset_Resolver() )->collect( $root, self::MAX_VECTOR_EXPORTS );
        }
        $ids = array(); $queue = array( $root );
        while ( $queue && count( $ids ) < self::MAX_VECTOR_EXPORTS ) {
            $node = array_shift( $queue );
            if ( ! is_array( $node ) || false === ( $node['visible'] ?? true ) ) { continue; }
            $type = strtoupper( (string) ( $node['type'] ?? '' ) );
            $children = array_values( array_filter( (array) ( $node['children'] ?? array() ), 'is_array' ) );
            if ( in_array( $type, array( 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'STAR', 'POLYGON' ), true ) && ! $children && ! empty( $node['id'] ) ) { $ids[] = sanitize_text_field( (string) $node['id'] ); }
            foreach ( $children as $child ) { $queue[] = $child; }
        }
        return array_values( array_unique( $ids ) );
    }

    /**
     * Exported nodes are exact visual atoms. Preserve their Figma ID/geometry
     * but make them leaves so the adapter maps them to media, not layout trees.
     */
    private function collapse_exported_vector_nodes( array $root, array $exported_ids ) {
        $lookup = array_fill_keys( array_map( 'strval', $exported_ids ), true );
        $walk = function ( array $node ) use ( &$walk, $lookup ) {
            $id = (string) ( $node['id'] ?? '' );
            if ( $id && isset( $lookup[ $id ] ) ) {
                $node['_design_core_original_type'] = (string) ( $node['type'] ?? '' );
                $node['_design_core_exact_vector_asset'] = true;
                $node['type'] = 'VECTOR';
                $node['children'] = array();
                return $node;
            }
            if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
                foreach ( $node['children'] as $index => $child ) {
                    if ( is_array( $child ) ) { $node['children'][ $index ] = $walk( $child ); }
                }
            }
            return $node;
        };
        return $walk( $root );
    }

    /** Stable source-shape evidence used to invalidate stale source-scoped memory. */
    private function structural_hash( array $root ) {
        $walk = function ( array $node ) use ( &$walk ) {
            $box = (array) ( $node['absoluteBoundingBox'] ?? array() );
            $shape = array(
                'id' => (string) ( $node['id'] ?? '' ),
                'type' => strtoupper( (string) ( $node['type'] ?? '' ) ),
                'layoutMode' => strtoupper( (string) ( $node['layoutMode'] ?? '' ) ),
                'layoutSizingHorizontal' => strtoupper( (string) ( $node['layoutSizingHorizontal'] ?? '' ) ),
                'layoutSizingVertical' => strtoupper( (string) ( $node['layoutSizingVertical'] ?? '' ) ),
                'width' => isset( $box['width'] ) ? round( (float) $box['width'], 2 ) : null,
                'height' => isset( $box['height'] ) ? round( (float) $box['height'], 2 ) : null,
                'children' => array(),
            );
            foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
                if ( is_array( $child ) && false !== ( $child['visible'] ?? true ) ) { $shape['children'][] = $walk( $child ); }
            }
            return $shape;
        };
        return substr( hash( 'sha256', wp_json_encode( $walk( $root ) ) ), 0, 32 );
    }

    private function request( $path ) {
        if ( ! function_exists( 'wp_safe_remote_get' ) ) { return new WP_Error( 'design_core_figma_http_unavailable', 'WordPress HTTP API is unavailable.' ); }
        $token = $this->token();
        if ( '' === $token ) { return new WP_Error( 'design_core_figma_token_missing', 'Configure DESIGN_CORE_FIGMA_ACCESS_TOKEN or the Design Core Figma token filter.' ); }
        $url = self::API_BASE . $path;
        $response = wp_safe_remote_get( $url, array( 'timeout' => 25, 'redirection' => 2, 'headers' => array( 'X-Figma-Token' => $token, 'Accept' => 'application/json' ), 'user-agent' => 'Design-Core-Elementor/' . ( defined( 'DESIGN_CORE_ELEMENTOR_VERSION' ) ? DESIGN_CORE_ELEMENTOR_VERSION : 'dev' ) ) );
        if ( is_wp_error( $response ) ) { return $response; }
        $code = (int) wp_remote_retrieve_response_code( $response ); $body = (string) wp_remote_retrieve_body( $response );
        if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) { return new WP_Error( 'design_core_figma_response_too_large', 'Figma response exceeded the 12 MB Design Core limit.' ); }
        $decoded = json_decode( $body, true );
        if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'design_core_figma_http_error', is_array( $decoded ) && ! empty( $decoded['err'] ) ? sanitize_text_field( (string) $decoded['err'] ) : 'Figma API request failed with HTTP ' . $code . '.' ); }
        return is_array( $decoded ) ? $decoded : new WP_Error( 'design_core_figma_json_invalid', 'Figma API returned invalid JSON.' );
    }

    private function token() {
        $token = defined( 'DESIGN_CORE_FIGMA_ACCESS_TOKEN' ) ? (string) DESIGN_CORE_FIGMA_ACCESS_TOKEN : '';
        if ( function_exists( 'apply_filters' ) ) { $token = (string) apply_filters( 'design_core_elementor_figma_access_token', $token ); }
        return trim( $token );
    }

    private function file_key( $value ) {
        $value = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
        return strlen( $value ) >= 8 ? $value : new WP_Error( 'design_core_figma_file_key_invalid', 'Figma file key is invalid.' );
    }
}
