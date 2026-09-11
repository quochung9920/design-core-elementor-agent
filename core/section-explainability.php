<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Auditable Section Registry matching diagnostics. */
class Design_Core_Elementor_Section_Explainability {
    const VERSION = 1;

    public function explain_candidate( array $fingerprint ) {
        $registry = new Design_Core_Elementor_Section_Registry(); $rows = array();
        foreach ( $registry->all() as $item ) {
            $registered = is_array( $item['fingerprint'] ?? null ) ? $item['fingerprint'] : array(); $metrics = $this->metrics( $fingerprint, $registered );
            $rows[] = array(
                'master_id' => (string) ( $item['id'] ?? '' ), 'family' => sanitize_key( $registered['semantic'] ?? '' ),
                'semantic_match' => $metrics['semantic'], 'interaction_match' => $metrics['interaction'], 'topology' => $metrics['topology'], 'slots' => $metrics['slots'], 'layout' => $metrics['layout'], 'media' => $metrics['media'], 'weighted_score' => $metrics['score'], 'eligible' => $metrics['eligible'],
                'family_hash_exact' => (string) ( $fingerprint['family_hash'] ?? '' ) !== '' && (string) ( $fingerprint['family_hash'] ?? '' ) === (string) ( $registered['family_hash'] ?? '' ),
                'variant_hash_exact' => (string) ( $fingerprint['variant_hash'] ?? '' ) !== '' && (string) ( $fingerprint['variant_hash'] ?? '' ) === (string) ( $registered['variant_hash'] ?? '' ),
                'detail_hash_exact' => (string) ( $fingerprint['detail_hash'] ?? '' ) !== '' && (string) ( $fingerprint['detail_hash'] ?? '' ) === (string) ( $registered['detail_hash'] ?? '' ),
                'usage' => (array) ( $item['usage'] ?? array() ), 'variants' => count( (array) ( $item['variants'] ?? array() ) ),
            );
        }
        usort( $rows, static function ( $a, $b ) { return (float) $a['weighted_score'] === (float) $b['weighted_score'] ? strcmp( (string) $a['master_id'], (string) $b['master_id'] ) : ( (float) $a['weighted_score'] > (float) $b['weighted_score'] ? -1 : 1 ); } );
        $classification = $registry->classify( $fingerprint );
        return array(
            'version' => self::VERSION,
            'decision' => array( 'action' => sanitize_key( $classification['action'] ?? 'new' ), 'master_id' => (string) ( $classification['item']['id'] ?? '' ), 'variant_id' => sanitize_key( $classification['variant_id'] ?? '' ), 'score' => (float) ( $classification['score'] ?? 0 ), 'reason' => sanitize_text_field( $classification['reason'] ?? '' ) ),
            'threshold' => Design_Core_Elementor_Section_Registry::COMPATIBLE_FAMILY_THRESHOLD, 'candidates' => $rows,
        );
    }

    public function explain_master( $master_id ) {
        $master_id = sanitize_text_field( (string) $master_id );
        foreach ( ( new Design_Core_Elementor_Section_Registry() )->all() as $item ) {
            if ( $master_id !== (string) ( $item['id'] ?? '' ) ) { continue; }
            return array( 'version' => self::VERSION, 'master_id' => $master_id, 'fingerprint' => (array) ( $item['fingerprint'] ?? array() ), 'variant_count' => count( (array) ( $item['variants'] ?? array() ) ), 'variants' => (array) ( $item['variants'] ?? array() ), 'usage' => (array) ( $item['usage'] ?? array() ), 'instances' => array_values( (array) ( $item['instances'] ?? array() ) ), 'item_version' => (int) ( $item['item_version'] ?? 1 ) );
        }
        return new WP_Error( 'design_core_section_master_missing', 'Section master was not found.' );
    }

    public function explain_manifest( $page_id ) {
        $manifest = get_post_meta( (int) $page_id, '_design_core_page_manifest', true );
        if ( ! is_array( $manifest ) ) { return new WP_Error( 'design_core_manifest_missing', 'Page Manifest is not available.' ); }
        $sections = array();
        foreach ( (array) ( $manifest['sections'] ?? array() ) as $section ) {
            $master = ! empty( $section['master_id'] ) ? $this->explain_master( $section['master_id'] ) : null;
            $sections[] = array( 'position' => (int) ( $section['position'] ?? count( $sections ) ), 'root_id' => (string) ( $section['root_id'] ?? '' ), 'family' => sanitize_key( $section['family'] ?? '' ), 'action' => sanitize_key( $section['action'] ?? 'new' ), 'score' => (float) ( $section['score'] ?? 0 ), 'reason' => sanitize_text_field( $section['reason'] ?? '' ), 'master_id' => (string) ( $section['master_id'] ?? '' ), 'variant_id' => sanitize_key( $section['variant_id'] ?? '' ), 'master' => is_wp_error( $master ) ? array() : $master );
        }
        return $sections;
    }

    private function metrics( array $candidate, array $registered ) {
        $semantic = sanitize_key( $candidate['semantic'] ?? '' ) === sanitize_key( $registered['semantic'] ?? '' ) ? 1.0 : 0.0;
        $interaction = $this->normalized_list( (array) ( $candidate['interaction_topology'] ?? array() ) ) === $this->normalized_list( (array) ( $registered['interaction_topology'] ?? array() ) ) ? 1.0 : 0.0;
        $topology = $this->token_similarity( (string) ( $candidate['topology'] ?? '' ), (string) ( $registered['topology'] ?? '' ) );
        $slots = $this->slot_similarity( (array) ( $candidate['slots'] ?? array() ), (array) ( $registered['slots'] ?? array() ) );
        $layout = $this->token_similarity( (string) ( $candidate['layout_topology'] ?? '' ), (string) ( $registered['layout_topology'] ?? '' ) );
        $media = $this->multiset_similarity( $this->normalized_list( (array) ( $candidate['media_topology'] ?? array() ) ), $this->normalized_list( (array) ( $registered['media_topology'] ?? array() ) ) );
        $score = $semantic && $interaction ? ( 0.45 * $topology ) + ( 0.25 * $slots ) + ( 0.20 * $layout ) + ( 0.10 * $media ) : 0.0;
        $eligible = $semantic > 0 && $interaction > 0 && $topology >= 0.72 && $slots >= 0.62 && $layout >= 0.65 && $score >= Design_Core_Elementor_Section_Registry::COMPATIBLE_FAMILY_THRESHOLD;
        return compact( 'semantic', 'interaction', 'topology', 'slots', 'layout', 'media', 'score', 'eligible' );
    }

    private function token_similarity( $left, $right ) { preg_match_all( '/[a-z0-9_-]+/i', strtolower( $left ), $a ); preg_match_all( '/[a-z0-9_-]+/i', strtolower( $right ), $b ); return $this->multiset_similarity( $a[0] ?? array(), $b[0] ?? array() ); }
    private function slot_similarity( array $left, array $right ) { $keys = array_unique( array_merge( array_keys( $left ), array_keys( $right ) ) ); if ( ! $keys ) { return 1.0; } $i = 0; $u = 0; foreach ( $keys as $key ) { $a = max( 0, (int) ( $left[ $key ] ?? 0 ) ); $b = max( 0, (int) ( $right[ $key ] ?? 0 ) ); $i += min( $a, $b ); $u += max( $a, $b ); } return 0 === $u ? 1.0 : $i / $u; }
    private function multiset_similarity( array $left, array $right ) { if ( ! $left && ! $right ) { return 1.0; } $a = array_count_values( $left ); $b = array_count_values( $right ); $keys = array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ); $i = 0; $u = 0; foreach ( $keys as $key ) { $i += min( $a[ $key ] ?? 0, $b[ $key ] ?? 0 ); $u += max( $a[ $key ] ?? 0, $b[ $key ] ?? 0 ); } return 0 === $u ? 1.0 : $i / $u; }
    private function normalized_list( array $values ) { $values = array_values( array_filter( array_map( 'sanitize_key', $values ) ) ); sort( $values ); return $values; }
}
