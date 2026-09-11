<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Lossless, bounded reads. Cursors are bound to the owner, query and revision. */
final class Design_Core_Agent_Contract {
    const VERSION = 1;
    const PAGE_BYTES = 24000;
    const TTL = 900;

    public static function error( $code, $message, $status = 400, array $extra = array() ) {
        return new WP_Error( 'design_core_agent_' . $code, $message, array_merge( array( 'status' => $status ), $extra ) );
    }

    public static function hash( $value ) {
        return hash( 'sha256', self::json( self::canonical( $value ) ) );
    }

    public static function policy_hash() {
        $files = array();
        foreach ( array( 'contract', 'knowledge', 'validator', 'plans', 'protocol' ) as $name ) {
            $files[ $name ] = hash_file( 'sha256', __DIR__ . '/' . $name . '.php' );
        }
        foreach ( array( 'elementor-v3-adapter', 'elementor-persistence-service' ) as $name ) {
            $path = dirname( __DIR__ ) . '/' . $name . '.php';
            $files[ $name ] = is_readable( $path ) ? hash_file( 'sha256', $path ) : 'unavailable';
        }
        return self::hash( $files );
    }

    public static function json( $value ) {
        return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
    }

    private static function canonical( $value ) {
        if ( ! is_array( $value ) ) { return $value; }
        if ( ! array_is_list( $value ) ) { ksort( $value, SORT_STRING ); }
        foreach ( $value as &$item ) { $item = self::canonical( $item ); }
        return $value;
    }

    /** Never expose runtime objects, executable callbacks or credential values. */
    public static function safe( $value, &$omitted = array(), $path = '', $depth = 0 ) {
        if ( $depth > 64 ) { $omitted[] = $path; return array( 'unavailable' => 'depth_limit' ); }
        if ( is_object( $value ) || is_resource( $value ) ) {
            $omitted[] = $path;
            return array( 'unavailable' => 'non_json_runtime_value' );
        }
        if ( ! is_array( $value ) ) { return $value; }
        $out = array();
        foreach ( $value as $key => $item ) {
            $pointer = $path . '/' . self::escape( (string) $key );
            if ( self::sensitive( (string) $key ) ) {
                $out[ $key ] = array( 'unavailable' => 'redacted' );
                $omitted[] = $pointer;
            } else { $out[ $key ] = self::safe( $item, $omitted, $pointer, $depth + 1 ); }
        }
        return $out;
    }

    public static function sensitive( $key ) {
        return (bool) preg_match( '/(?:^|[_-])(?:password|passwd|secret|token|authorization|cookie|api[_-]?key|private[_-]?key)(?:$|[_-])/i', $key );
    }

    public static function escape( $key ) { return str_replace( array( '~', '/' ), array( '~0', '~1' ), $key ); }

    /** JSON Pointer reads return children or a chunk, never recursively truncate data. */
    public static function read( $document, array $input, $scope, $revision = '' ) {
        $pointer = (string) ( $input['pointer'] ?? '' );
        if ( '' !== $pointer && '/' !== substr( $pointer, 0, 1 ) ) { return self::error( 'pointer', 'Use an RFC 6901 JSON Pointer.' ); }
        $value = $document;
        foreach ( '' === $pointer ? array() : explode( '/', substr( $pointer, 1 ) ) as $part ) {
            if ( preg_match( '/~(?:[^01]|$)/', $part ) ) { return self::error( 'pointer', 'Invalid JSON Pointer escape.' ); }
            $key = str_replace( array( '~1', '~0' ), array( '/', '~' ), $part );
            if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) { return self::error( 'not_found', 'Pointer not found.', 404 ); }
            $value = $value[ $key ];
        }
        $revision = $revision ?: self::hash( $document );
        if ( is_array( $value ) ) {
            $rows = array();
            foreach ( $value as $key => $item ) {
                $row = array( 'key' => $key, 'pointer' => $pointer . '/' . self::escape( (string) $key ), 'type' => get_debug_type( $item ) );
                if ( is_array( $item ) ) { $row['child_count'] = count( $item ); }
                elseif ( is_string( $item ) && strlen( $item ) > 2048 ) { $row['bytes'] = strlen( $item ); $row['value_available_by_pointer'] = true; }
                else { $row['value'] = $item; }
                $rows[] = $row;
            }
            return self::page( $rows, $input, array( $scope, $pointer ), $revision );
        }
        if ( is_string( $value ) ) {
            if ( ! preg_match( '//u', $value ) ) { return self::error( 'encoding', 'Source is not valid UTF-8.', 422 ); }
            $chunks = array(); $offset = 0; $length = strlen( $value );
            do {
                $end = min( $offset + 8000, $length );
                while ( $end < $length && $end > $offset && ( ord( $value[ $end ] ) & 0xC0 ) === 0x80 ) { $end--; }
                $chunks[] = array( 'offset_bytes' => $offset, 'text' => substr( $value, $offset, $end - $offset ) );
                $offset = $end;
            } while ( $offset < $length );
            $result = self::page( $chunks, $input, array( $scope, $pointer ), $revision );
            if ( ! is_wp_error( $result ) ) { $result['value_type'] = 'string'; $result['value_bytes'] = strlen( $value ); }
            return $result;
        }
        return self::page( array( array( 'value' => $value ) ), $input, array( $scope, $pointer ), $revision );
    }

    public static function page( array $rows, array $input, $scope, $revision = '' ) {
        $limit = (int) ( $input['limit'] ?? 40 );
        if ( $limit < 1 || $limit > 100 ) { return self::error( 'limit', 'limit must be 1..100.' ); }
        $revision = $revision ?: self::hash( $rows );
        $scope = self::hash( array( get_current_user_id(), home_url( '/' ), $scope ) );
        $offset = 0; $expires = time() + self::TTL;
        if ( ! empty( $input['snapshot_id'] ) && ! hash_equals( $revision, (string) $input['snapshot_id'] ) ) {
            return self::error( 'snapshot_stale', 'Data changed. Restart this query.', 409 );
        }
        if ( ! empty( $input['cursor'] ) ) {
            $cursor = self::decode( (string) $input['cursor'] );
            if ( is_wp_error( $cursor ) ) { return $cursor; }
            if ( $cursor['scope'] !== $scope ) { return self::error( 'cursor_scope', 'Cursor belongs to another owner or query.', 403 ); }
            if ( $cursor['revision'] !== $revision ) { return self::error( 'snapshot_stale', 'Data changed. Restart this query.', 409 ); }
            $offset = $cursor['offset']; $expires = $cursor['expires'];
        }
        if ( $offset > count( $rows ) ) { return self::error( 'cursor_offset', 'Cursor is outside the result.' ); }
        $items = array(); $bytes = 0;
        for ( $i = $offset; $i < count( $rows ) && count( $items ) < $limit; $i++ ) {
            $size = strlen( self::json( $rows[ $i ] ) );
            if ( $size > self::PAGE_BYTES ) { return self::error( 'item_too_large', 'Read this item through its detail/pointer operation.', 413 ); }
            if ( $items && $bytes + $size > self::PAGE_BYTES ) { break; }
            $items[] = $rows[ $i ]; $bytes += $size;
        }
        $next = $offset + count( $items );
        return array(
            'status' => 'ok', 'contract_version' => self::VERSION, 'snapshot_id' => $revision,
            'items' => $items, 'returned' => count( $items ), 'total' => count( $rows ),
            'has_more' => $next < count( $rows ), 'next_cursor' => $next < count( $rows ) ? self::encode( array( 'scope' => $scope, 'revision' => $revision, 'offset' => $next, 'expires' => $expires ) ) : null,
            'truncated' => false, 'completeness' => 'complete_for_authorized_query',
        );
    }

    private static function encode( array $cursor ) {
        $body = rtrim( strtr( base64_encode( self::json( $cursor ) ), '+/', '-_' ), '=' );
        return $body . '.' . hash_hmac( 'sha256', $body, wp_salt( 'auth' ) );
    }
    private static function decode( $token ) {
        if ( strlen( $token ) > 2048 ) { return self::error( 'cursor', 'Invalid cursor.' ); }
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 2 || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) { return self::error( 'cursor', 'Invalid cursor signature.' ); }
        $cursor = json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
        if ( ! is_array( $cursor ) || ! isset( $cursor['scope'], $cursor['revision'], $cursor['offset'], $cursor['expires'] ) || ! is_int( $cursor['offset'] ) || $cursor['offset'] < 0 ) { return self::error( 'cursor', 'Invalid cursor payload.' ); }
        if ( $cursor['expires'] < time() ) { return self::error( 'cursor_expired', 'Cursor expired. Restart this query.', 410 ); }
        return $cursor;
    }
}
