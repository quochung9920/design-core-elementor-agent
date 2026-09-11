<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Section_Registry extends Design_Core_Elementor_Versioned_Registry {
    public const OPTION_KEY = 'design_core_elementor_sections';
    const COMPATIBLE_FAMILY_THRESHOLD = 0.78;
    protected $type = 'section';

    public function defaults() { return array(); }

    public function validate( $items ) {
        parent::validate( $items );
        foreach ( $items as $item ) {
            $family_hash = (string) ( $item['fingerprint']['family_hash'] ?? '' );
            $variants = $item['variants'] ?? array();
            if ( ! is_array( $variants ) ) { throw new InvalidArgumentException( 'Section variants must be an array.' ); }
            foreach ( $variants as $variant_id => $definition ) {
                if ( '' === sanitize_key( (string) $variant_id ) || ! is_array( $definition ) ) { throw new InvalidArgumentException( 'Section variant definition is invalid.' ); }
                $fingerprint = is_array( $definition['fingerprint'] ?? null ) ? $definition['fingerprint'] : array();
                if ( Design_Core_Elementor_Section_Fingerprint_Service::VERSION !== ( $fingerprint['version'] ?? null ) ) { throw new InvalidArgumentException( 'Section variant fingerprint v3 is required.' ); }
                if ( $family_hash !== (string) ( $fingerprint['family_hash'] ?? '' ) ) {
                    $relation = is_array( $definition['family_relation'] ?? null ) ? $definition['family_relation'] : array();
                    if ( 'compatible' !== ( $relation['type'] ?? '' ) || $family_hash !== (string) ( $relation['master_family_hash'] ?? '' ) || (float) ( $relation['score'] ?? 0 ) < self::COMPATIBLE_FAMILY_THRESHOLD ) {
                        throw new InvalidArgumentException( 'Cross-family section variant requires a verified compatible-family relation.' );
                    }
                }
                if ( '' === (string) ( $fingerprint['variant_hash'] ?? '' ) ) { throw new InvalidArgumentException( 'Section variant hash is required.' ); }
                if ( isset( $definition['blueprint'] ) && ! is_array( $definition['blueprint'] ) ) { throw new InvalidArgumentException( 'Section variant blueprint is invalid.' ); }
            }
            $instances = $item['instances'] ?? array();
            if ( ! is_array( $instances ) ) { throw new InvalidArgumentException( 'Section instances must be an array.' ); }
            foreach ( $instances as $instance_id => $instance ) {
                if ( ! is_array( $instance ) || '' === trim( (string) $instance_id ) ) { throw new InvalidArgumentException( 'Section instance is invalid.' ); }
                if ( (string) ( $instance['master_id'] ?? '' ) !== (string) $item['id'] ) { throw new InvalidArgumentException( 'Section instance master_id mismatch.' ); }
                if ( ! is_array( $instance['bindings'] ?? null ) || ! is_array( $instance['assets'] ?? null ) ) { throw new InvalidArgumentException( 'Section instance bindings/assets are invalid.' ); }
            }
        }
        return true;
    }

    public function find_family( array $fingerprint ) {
        $hash = (string) ( $fingerprint['family_hash'] ?? '' );
        if ( '' === $hash ) { return array(); }
        return array_values( array_filter( $this->all(), static function ( $item ) use ( $hash ) {
            return $hash === (string) ( $item['fingerprint']['family_hash'] ?? '' );
        } ) );
    }

    public function classify( array $fingerprint ) {
        $family = $this->find_family( $fingerprint );
        $compatible_score = 1.0; $compatible_reason = '';
        if ( ! $family ) {
            $compatible = $this->find_compatible_family( $fingerprint );
            if ( ! $compatible ) { return array( 'action' => 'new', 'item' => null, 'score' => 0.0, 'reason' => 'section-family-not-found', 'variant_id' => '' ); }
            $family = array( $compatible['item'] );
            $compatible_score = (float) $compatible['score'];
            $compatible_reason = 'section-compatible-family:' . implode( ',', $compatible['reasons'] );
        }

        foreach ( $family as $item ) {
            if ( $this->same_variant( $fingerprint, (array) ( $item['fingerprint'] ?? array() ) ) ) {
                return array( 'action' => 'reuse', 'item' => $item, 'score' => $compatible_score, 'reason' => $compatible_reason ?: 'section-family-and-master-variant-match', 'variant_id' => '' );
            }
            foreach ( (array) ( $item['variants'] ?? array() ) as $variant_id => $variant ) {
                $registered = is_array( $variant['fingerprint'] ?? null ) ? $variant['fingerprint'] : array();
                if ( $this->same_variant( $fingerprint, $registered ) ) {
                    $matched = $item;
                    if ( ! empty( $variant['blueprint'] ) && is_array( $variant['blueprint'] ) ) { $matched['master']['blueprint'] = $variant['blueprint']; }
                    $matched['matched_variant_id'] = sanitize_key( $variant_id );
                    return array(
                        'action' => 'reuse', 'item' => $matched, 'score' => $compatible_score,
                        'reason' => $compatible_reason ?: 'section-family-and-registered-variant-match', 'variant_id' => sanitize_key( $variant_id ),
                    );
                }
            }
        }

        return array(
            'action' => 'variant', 'item' => $family[0], 'score' => $compatible_score < 1.0 ? $compatible_score : 0.85,
            'reason' => $compatible_reason ?: 'section-family-match-variant-differs', 'variant_id' => '',
            'family_relation' => $compatible_score < 1.0 ? array(
                'type' => 'compatible',
                'master_family_hash' => (string) ( $family[0]['fingerprint']['family_hash'] ?? '' ),
                'candidate_family_hash' => (string) ( $fingerprint['family_hash'] ?? '' ),
                'score' => $compatible_score,
            ) : array(),
        );
    }

    private function find_compatible_family( array $fingerprint ) {
        $semantic = sanitize_key( $fingerprint['semantic'] ?? '' );
        if ( '' === $semantic ) { return null; }
        $best = null;
        foreach ( $this->all() as $item ) {
            $registered = is_array( $item['fingerprint'] ?? null ) ? $item['fingerprint'] : array();
            if ( $semantic !== sanitize_key( $registered['semantic'] ?? '' ) ) { continue; }
            if ( $this->normalized_list( $fingerprint['interaction_topology'] ?? array() ) !== $this->normalized_list( $registered['interaction_topology'] ?? array() ) ) { continue; }

            $topology = $this->token_similarity( (string) ( $fingerprint['topology'] ?? '' ), (string) ( $registered['topology'] ?? '' ) );
            $slots = $this->slot_similarity( (array) ( $fingerprint['slots'] ?? array() ), (array) ( $registered['slots'] ?? array() ) );
            $layout = $this->token_similarity( (string) ( $fingerprint['layout_topology'] ?? '' ), (string) ( $registered['layout_topology'] ?? '' ) );
            $media = $this->set_similarity( (array) ( $fingerprint['media_topology'] ?? array() ), (array) ( $registered['media_topology'] ?? array() ) );

            // Conservative floor prevents same-semantic but structurally unrelated sections from collapsing.
            if ( $topology < 0.72 || $slots < 0.62 || $layout < 0.65 ) { continue; }
            $score = ( 0.45 * $topology ) + ( 0.25 * $slots ) + ( 0.20 * $layout ) + ( 0.10 * $media );
            if ( $score < self::COMPATIBLE_FAMILY_THRESHOLD ) { continue; }
            if ( null === $best || $score > $best['score'] ) {
                $best = array(
                    'item' => $item, 'score' => $score,
                    'reasons' => array(
                        'semantic-exact',
                        'topology-' . number_format( $topology, 2, '.', '' ),
                        'slots-' . number_format( $slots, 2, '.', '' ),
                        'layout-' . number_format( $layout, 2, '.', '' ),
                        'media-' . number_format( $media, 2, '.', '' ),
                    ),
                );
            }
        }
        return $best;
    }

    private function same_variant( array $candidate, array $registered ) {
        $candidate_detail = (string) ( $candidate['detail_hash'] ?? '' );
        $registered_detail = (string) ( $registered['detail_hash'] ?? '' );
        // New candidates always carry detail_hash. If the stored v3 record predates detail_hash,
        // treat it as a compatible family/variant migration instead of unsafe exact reuse.
        if ( '' !== $candidate_detail ) { return '' !== $registered_detail && hash_equals( $registered_detail, $candidate_detail ); }
        $candidate_variant = (string) ( $candidate['variant_hash'] ?? '' );
        $registered_variant = (string) ( $registered['variant_hash'] ?? '' );
        return '' !== $candidate_variant && '' !== $registered_variant && hash_equals( $registered_variant, $candidate_variant );
    }

    private function token_similarity( $left, $right ) {
        preg_match_all( '/[a-z0-9_-]+/i', strtolower( $left ), $a );
        preg_match_all( '/[a-z0-9_-]+/i', strtolower( $right ), $b );
        return $this->multiset_similarity( $a[0] ?? array(), $b[0] ?? array() );
    }

    private function slot_similarity( array $left, array $right ) {
        $keys = array_unique( array_merge( array_keys( $left ), array_keys( $right ) ) );
        if ( ! $keys ) { return 1.0; }
        $intersection = 0; $union = 0;
        foreach ( $keys as $key ) {
            $a = max( 0, (int) ( $left[ $key ] ?? 0 ) ); $b = max( 0, (int) ( $right[ $key ] ?? 0 ) );
            $intersection += min( $a, $b ); $union += max( $a, $b );
        }
        return 0 === $union ? 1.0 : $intersection / $union;
    }

    private function set_similarity( array $left, array $right ) {
        return $this->multiset_similarity( $this->normalized_list( $left ), $this->normalized_list( $right ) );
    }

    private function multiset_similarity( array $left, array $right ) {
        if ( ! $left && ! $right ) { return 1.0; }
        $a = array_count_values( $left ); $b = array_count_values( $right );
        $keys = array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) );
        $intersection = 0; $union = 0;
        foreach ( $keys as $key ) { $intersection += min( $a[ $key ] ?? 0, $b[ $key ] ?? 0 ); $union += max( $a[ $key ] ?? 0, $b[ $key ] ?? 0 ); }
        return 0 === $union ? 1.0 : $intersection / $union;
    }

    private function normalized_list( array $values ) {
        $values = array_values( array_filter( array_map( 'sanitize_key', $values ) ) ); sort( $values ); return $values;
    }

    public function register_instance( $master_id, array $bindings, $location, array $assets = array(), array $overrides = array() ) {
        $instance = array(
            'id' => 'section-instance-' . substr( md5( $master_id . '|' . wp_json_encode( $bindings ) . '|' . wp_json_encode( $location ) ), 0, 12 ),
            'master_id' => $master_id,
            'bindings' => $bindings,
            'assets' => $assets,
            'allowed_overrides' => $overrides,
            'location' => $location,
        );
        try {
            $this->mutate_item( $master_id, static function ( $item ) use ( $instance, $location ) {
                if ( ! isset( $item['instances'] ) || ! is_array( $item['instances'] ) ) { $item['instances'] = array(); }
                $item['instances'][ $instance['id'] ] = $instance;
                $locations = (array) ( $item['usage']['locations'] ?? array() ); $locations[] = $location;
                $locations = array_values( array_unique( $locations, SORT_REGULAR ) );
                $item['usage'] = array( 'count' => count( $locations ), 'locations' => $locations );
                $item['updated_at'] = gmdate( 'c' ); $item['item_version']++;
                return $item;
            } );
            return $instance;
        } catch ( Throwable $exception ) { return new WP_Error( 'design_core_section_write_failed', $exception->getMessage() ); }
    }

    public function add_variant( $master_id, $variant_id, array $definition ) {
        try {
            $this->mutate_item( $master_id, static function ( $item ) use ( $variant_id, $definition ) {
                $variant_id = sanitize_key( $variant_id );
                if ( '' === $variant_id ) { throw new InvalidArgumentException( 'Section variant ID is required.' ); }
                if ( ! isset( $item['variants'] ) || ! is_array( $item['variants'] ) ) { $item['variants'] = array(); }
                $item['variants'][ $variant_id ] = $definition;
                $item['updated_at'] = gmdate( 'c' ); $item['item_version']++;
                return $item;
            } );
            return true;
        } catch ( Throwable $exception ) { throw new RuntimeException( 'Section variant persistence failed: ' . $exception->getMessage(), 0, $exception ); }
    }
}
