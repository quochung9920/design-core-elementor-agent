<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Classifies source media references before planning. A relative bundle reference such
 * as assets/hero.webp has no trustworthy meaning once the layout is written to a new
 * WordPress URL, so Design Core must block instead of silently producing broken media.
 */
final class Design_Core_Elementor_Source_Asset_Contract {
    const VERSION = 1;

    public function audit( array $ir ) {
        $refs = array(); $unresolved = array(); $resolved = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            if ( ! is_array( $node ) || empty( $node['id'] ) ) { continue; }
            $node_id = (string) $node['id'];
            $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
            $content_url = trim( (string) ( $node['content']['image']['url'] ?? '' ) );
            $background_url = trim( (string) ( $node['style']['background_image']['url'] ?? '' ) );
            if ( 'img' === $tag || '' !== $content_url ) { $refs[] = array( 'node_id'=>$node_id, 'slot'=>'content_image', 'url'=>$content_url ); }
            if ( '' !== $background_url ) { $refs[] = array( 'node_id'=>$node_id, 'slot'=>'background_image', 'url'=>$background_url ); }
        }
        foreach ( $refs as $ref ) {
            $classification = $this->classify( (string) $ref['url'] );
            $item = array_merge( $ref, array( 'classification'=>$classification ) );
            if ( in_array( $classification, array( 'absolute-http', 'root-relative', 'protocol-relative' ), true ) ) { $resolved[] = $item; }
            else { $unresolved[] = $item; }
        }
        return array(
            'version' => self::VERSION,
            'status' => $unresolved ? 'blocked' : 'pass',
            'reference_count' => count( $refs ),
            'resolved_count' => count( $resolved ),
            'unresolved_count' => count( $unresolved ),
            'resolved' => $resolved,
            'unresolved' => $unresolved,
            'contract_hash' => hash( 'sha256', wp_json_encode( array( $refs, $unresolved ) ) ),
        );
    }

    public function classify( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) { return 'missing'; }
        if ( preg_match( '#^https?://#i', $url ) ) { return 'absolute-http'; }
        if ( 0 === strpos( $url, '//' ) ) { return 'protocol-relative'; }
        if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) { return 'root-relative'; }
        if ( preg_match( '#^(?:data|blob|file|javascript):#i', $url ) ) { return 'unsupported-scheme'; }
        return 'relative-bundle-unresolved';
    }

    public function blocking_message( array $audit ) {
        $parts = array();
        foreach ( array_slice( (array) ( $audit['unresolved'] ?? array() ), 0, 8 ) as $item ) {
            $parts[] = (string) ( $item['slot'] ?? 'asset' ) . '@' . (string) ( $item['node_id'] ?? '?' ) . '=' . ( (string) ( $item['url'] ?? '' ) ?: '<missing>' );
        }
        return 'Source assets are unresolved and cannot be safely compiled: ' . implode( ', ', $parts );
    }
}
