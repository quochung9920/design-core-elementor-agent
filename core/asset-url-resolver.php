<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Resolves relative asset URLs in imported HTML/CSS against the source page
 * URL they were uploaded alongside. Without this, relative references
 * (assets/logo.png) are unresolvable, get dropped from the IR, and then
 * trip the source-fidelity gate -- even though the files sit right next to
 * the uploaded HTML. Only asset carriers are rewritten (img/srcset/poster,
 * CSS url()); links are left untouched.
 */
class Design_Core_Elementor_Asset_URL_Resolver {
    public static function resolve( $html, $css, $base_url ) {
        $base = self::base_parts( $base_url );
        if ( ! $base ) { return array( (string) $html, (string) $css, 0 ); }
        $count = 0;
        $html = (string) $html;
        $css = (string) $css;
        $html = preg_replace_callback(
            '/(<img\b[^>]*\bsrc\s*=\s*["\'])([^"\']+)(["\'])/i',
            static function ( $m ) use ( $base, &$count ) {
                $abs = Design_Core_Elementor_Asset_URL_Resolver::absolutize( $m[2], $base );
                if ( null !== $abs ) { $count++; return $m[1] . $abs . $m[3]; }
                return $m[0];
            },
            $html
        );
        $html = preg_replace_callback(
            '/(<source\b[^>]*\bsrcset\s*=\s*["\'])([^"\']+)(["\'])/i',
            static function ( $m ) use ( $base, &$count ) {
                $parts = explode( ',', $m[2] );
                $changed = false;
                foreach ( $parts as &$part ) {
                    $part = trim( $part );
                    if ( '' === $part ) { continue; }
                    $space = strpos( $part, ' ' );
                    $url = false === $space ? $part : substr( $part, 0, $space );
                    $descriptor = false === $space ? '' : substr( $part, $space );
                    $abs = Design_Core_Elementor_Asset_URL_Resolver::absolutize( $url, $base );
                    if ( null !== $abs ) { $part = $abs . $descriptor; $changed = true; $count++; }
                }
                unset( $part );
                return $changed ? $m[1] . implode( ', ', $parts ) . $m[3] : $m[0];
            },
            $html
        );
        $html = preg_replace_callback(
            '/(<video\b[^>]*\bposter\s*=\s*["\'])([^"\']+)(["\'])/i',
            static function ( $m ) use ( $base, &$count ) {
                $abs = Design_Core_Elementor_Asset_URL_Resolver::absolutize( $m[2], $base );
                if ( null !== $abs ) { $count++; return $m[1] . $abs . $m[3]; }
                return $m[0];
            },
            $html
        );
        $rewriter = static function ( $m ) use ( $base, &$count ) {
            $abs = Design_Core_Elementor_Asset_URL_Resolver::absolutize( $m[2], $base );
            if ( null !== $abs ) { $count++; return 'url(' . $m[1] . $abs . $m[1]; }
            return $m[0];
        };
        $html = preg_replace_callback( '/style\s*=\s*"[^"]*url\(\s*([\'"]?)([^"\'\)]+)\1\s*\)/', $rewriter, $html );
        $css = preg_replace_callback( '/url\(\s*([\'"]?)([^"\'\)]+)\1\s*\)/i', $rewriter, $css );
        return array( $html, $css, $count );
    }

    public static function absolutize( $url, $base ) {
        $url = trim( (string) $url );
        if ( '' === $url || '#' === $url[0] ) { return null; }
        $lower = strtolower( $url );
        if ( 0 === strpos( $lower, 'data:' ) || 0 === strpos( $lower, 'blob:' ) ) { return null; }
        if ( preg_match( '#^https?://#i', $url ) ) { return null; }
        if ( 0 === strpos( $url, '//' ) ) { return null; }
        if ( ! is_array( $base ) ) { $base = self::base_parts( $base ); if ( ! $base ) { return null; } }
        if ( '/' === $url[0] ) { return $base['origin'] . $url; }
        $merged = $base['dir'] . '/' . $url;
        $segments = array();
        foreach ( explode( '/', $merged ) as $segment ) {
            if ( '' === $segment || '.' === $segment ) { continue; }
            if ( '..' === $segment ) { array_pop( $segments ); continue; }
            $segments[] = $segment;
        }
        return $base['origin'] . '/' . implode( '/', $segments );
    }

    private static function base_parts( $base_url ) {
        if ( ! is_string( $base_url ) || '' === trim( $base_url ) ) { return null; }
        $parts = parse_url( trim( $base_url ) );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) { return null; }
        $origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
        if ( ! empty( $parts['port'] ) ) { $origin .= ':' . (int) $parts['port']; }
        $path = (string) ( $parts['path'] ?? '/' );
        if ( '/' !== substr( $path, -1 ) ) { $path = dirname( $path ); }
        return array( 'origin' => $origin, 'dir' => rtrim( $path, '/' ) );
    }
}
