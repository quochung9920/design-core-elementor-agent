<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Local, runtime-safe UI/UX knowledge catalog.
 *
 * WordPress never executes the upstream Python search engine. A normalized JSON
 * bundle is loaded from this plugin instead, so recommendations remain deterministic,
 * reviewable and available without network access.
 */
class Design_Core_Elementor_Design_Intelligence_Catalog {
    const SCHEMA_VERSION = 1;
    const DEFAULT_RELATIVE_PATH = 'resources/design-intelligence/catalog.json';

    private $path;
    private $data = null;

    public function __construct( $path = '' ) {
        $this->path = $path ? (string) $path : DESIGN_CORE_ELEMENTOR_PATH . self::DEFAULT_RELATIVE_PATH;
    }

    public function load() {
        if ( null !== $this->data ) { return $this->data; }
        if ( ! is_readable( $this->path ) ) {
            return new WP_Error( 'design_core_design_catalog_missing', 'The Design Intelligence catalog is not available.' );
        }
        $raw = file_get_contents( $this->path );
        if ( false === $raw || '' === trim( $raw ) ) {
            return new WP_Error( 'design_core_design_catalog_empty', 'The Design Intelligence catalog is empty.' );
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) || self::SCHEMA_VERSION !== (int) ( $decoded['schema_version'] ?? 0 ) ) {
            return new WP_Error( 'design_core_design_catalog_invalid', 'The Design Intelligence catalog has an unsupported schema.' );
        }
        $decoded['product_profiles'] = array_values( array_filter( (array) ( $decoded['product_profiles'] ?? array() ), 'is_array' ) );
        $decoded['ux_rules'] = array_values( array_filter( (array) ( $decoded['ux_rules'] ?? array() ), 'is_array' ) );
        $this->data = $decoded;
        return $this->data;
    }

    public function status() {
        $data = $this->load();
        if ( is_wp_error( $data ) ) {
            return array( 'available' => false, 'schema_version' => self::SCHEMA_VERSION, 'error' => $data->get_error_code(), 'profiles' => 0, 'ux_rules' => 0 );
        }
        return array(
            'available' => true,
            'schema_version' => (int) $data['schema_version'],
            'profiles' => count( $data['product_profiles'] ),
            'ux_rules' => count( $data['ux_rules'] ),
            'source' => Design_Core_Elementor_Change_Ledger::transport_safe( (array) ( $data['source'] ?? array() ) ),
        );
    }

    public function profiles() {
        $data = $this->load();
        return is_wp_error( $data ) ? array() : $data['product_profiles'];
    }

    public function ux_rules( $categories = array(), $limit = 50 ) {
        $data = $this->load();
        if ( is_wp_error( $data ) ) { return array(); }
        $wanted = array_values( array_filter( array_map( 'sanitize_key', (array) $categories ) ) );
        $rules = array();
        foreach ( $data['ux_rules'] as $rule ) {
            $category = sanitize_key( (string) ( $rule['category'] ?? '' ) );
            if ( $wanted && ! in_array( $category, $wanted, true ) ) { continue; }
            $rules[] = $rule;
            if ( count( $rules ) >= max( 1, min( 200, (int) $limit ) ) ) { break; }
        }
        return $rules;
    }

    /**
     * Deterministic lexical matcher. It intentionally does not pretend to be an LLM.
     * Exact category/id hits win, then phrase/keyword coverage, then catalog order.
     */
    public function match_profile( $brief, $explicit_product_type = '' ) {
        $profiles = $this->profiles();
        if ( ! $profiles ) { return new WP_Error( 'design_core_design_profiles_unavailable', 'No Design Intelligence product profiles are available.' ); }

        $brief = trim( (string) $brief );
        $explicit_product_type = trim( (string) $explicit_product_type );
        $query = trim( $explicit_product_type . ' ' . $brief );
        $query_normalized = $this->normalize_text( $query );
        $query_tokens = $this->tokens( $query );
        $best = null; $best_score = -1.0; $best_evidence = array();

        foreach ( $profiles as $index => $profile ) {
            $id = sanitize_key( (string) ( $profile['id'] ?? '' ) );
            $category = (string) ( $profile['category'] ?? '' );
            $keywords = array_values( array_filter( array_map( 'strval', (array) ( $profile['keywords'] ?? array() ) ) ) );
            $score = 0.0; $evidence = array();

            $category_normalized = $this->normalize_text( $category );
            $id_words = str_replace( '-', ' ', $id );
            if ( $explicit_product_type ) {
                $explicit_normalized = $this->normalize_text( $explicit_product_type );
                if ( $explicit_normalized === $category_normalized || $explicit_normalized === $this->normalize_text( $id_words ) ) {
                    $score += 50; $evidence[] = 'explicit-product-exact';
                } elseif ( $explicit_normalized && false !== strpos( $category_normalized . ' ' . $this->normalize_text( implode( ' ', $keywords ) ), $explicit_normalized ) ) {
                    $score += 20; $evidence[] = 'explicit-product-partial';
                }
            }

            if ( $category_normalized && false !== strpos( $query_normalized, $category_normalized ) ) {
                $score += 24; $evidence[] = 'category-phrase';
            }
            if ( $id_words && false !== strpos( $query_normalized, $this->normalize_text( $id_words ) ) ) {
                $score += 18; $evidence[] = 'profile-id';
            }

            foreach ( $keywords as $keyword ) {
                $keyword_normalized = $this->normalize_text( $keyword );
                if ( '' === $keyword_normalized ) { continue; }
                if ( false !== strpos( $query_normalized, $keyword_normalized ) ) {
                    $score += count( explode( ' ', $keyword_normalized ) ) > 1 ? 8 : 4;
                    $evidence[] = 'keyword:' . sanitize_key( str_replace( ' ', '-', $keyword_normalized ) );
                }
            }

            $profile_tokens = $this->tokens( $category . ' ' . implode( ' ', $keywords ) . ' ' . implode( ' ', (array) ( $profile['considerations'] ?? array() ) ) );
            $overlap = array_intersect( $query_tokens, $profile_tokens );
            if ( $overlap ) {
                $score += min( 15, count( array_unique( $overlap ) ) * 1.5 );
                $evidence[] = 'token-overlap:' . count( array_unique( $overlap ) );
            }

            // Stable tie-break: earlier curated/synced records win by a tiny margin.
            $score += max( 0, 0.001 - ( $index * 0.000001 ) );
            if ( $score > $best_score ) {
                $best = $profile; $best_score = $score; $best_evidence = array_values( array_unique( $evidence ) );
            }
        }

        if ( ! is_array( $best ) ) { return new WP_Error( 'design_core_design_profile_not_found', 'No Design Intelligence profile matched this request.' ); }
        $confidence = $best_score >= 30 ? 'high' : ( $best_score >= 12 ? 'medium' : 'low' );
        return array(
            'profile' => $best,
            'score' => round( $best_score, 3 ),
            'confidence' => $confidence,
            'evidence' => $best_evidence,
            'query_tokens' => array_slice( $query_tokens, 0, 30 ),
        );
    }

    private function normalize_text( $value ) {
        $value = strtolower( wp_strip_all_tags( (string) $value ) );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    private function tokens( $value ) {
        $tokens = preg_split( '/\s+/', $this->normalize_text( $value ) );
        $stop = array( 'the','and','for','with','from','this','that','website','page','site','design','make','build','create','a','an','of','to','in','on','or','is','it' );
        $out = array();
        foreach ( (array) $tokens as $token ) {
            if ( strlen( $token ) < 2 || in_array( $token, $stop, true ) ) { continue; }
            $out[] = $token;
        }
        return array_values( array_unique( $out ) );
    }
}
