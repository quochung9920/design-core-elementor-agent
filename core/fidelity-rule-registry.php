<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Allow-list of compiler-safe learning strategies.
 *
 * Design Memory can select these strategies, but it can never inject arbitrary
 * PHP/CSS or invent a new mutation. New strategies must be implemented and
 * reviewed here first, which keeps future learning deterministic and auditable.
 */
class Design_Core_Elementor_Fidelity_Rule_Registry {
    const VERSION = 1;

    public static function strategies() {
        return array(
            'preserve-vector-asset' => 'Resolve authored Figma vectors before conversion and keep them as media rather than generic containers.',
            'preserve-icon-composition' => 'Do not flatten a component/button when its subtree owns an icon or vector asset.',
            'fixed-small-primitive' => 'Small authored primitives/icons keep exact width/height and cannot flex-grow or stretch.',
            'preserve-bottom-anchor' => 'Keep authored absolute bottom/left/right anchoring relative to the owning composition.',
            'preserve-rich-text-composition' => 'Merge visually composed mixed-style heading runs into one semantic rich heading.',
            'preserve-fill-flex' => 'Translate Figma FILL sizing into deterministic non-collapsing flex behavior.',
            'preserve-fixed-media-height' => 'Keep authored fixed media height and clipping instead of letting content collapse the frame.',
            'verify-figma-node-geometry' => 'Use exact Figma-node identity and source geometry during rendered verification.',
        );
    }

    public static function supports_strategy( $strategy ) {
        return isset( self::strategies()[ sanitize_key( (string) $strategy ) ] );
    }

    /** Core lessons are verified by regression incidents already fixed in RC23/RC24. */
    public static function seed_lessons() {
        $common = array(
            'scope' => 'global',
            'scope_key' => '',
            'verified' => true,
            'confidence' => 0.99,
            'verified_hits' => 1,
            'origin' => 'core-seed',
            'source_kind' => 'figma',
            'min_core_version' => '1.0.0-rc24',
        );
        $definitions = array(
            array( 'signature' => 'figma.vector-component.asset', 'strategy' => 'preserve-vector-asset', 'reason' => 'Missing vector resolution turns icons into empty/generic Elementor containers.' ),
            array( 'signature' => 'figma.button.icon-composition', 'strategy' => 'preserve-icon-composition', 'reason' => 'Flattening icon-bearing button instances deletes authored arrows/icons.' ),
            array( 'signature' => 'figma.small-primitive.fixed-size', 'strategy' => 'fixed-small-primitive', 'reason' => 'Tiny dots, rules and icons must not inherit flex stretch behavior.' ),
            array( 'signature' => 'figma.absolute.bottom-anchor', 'strategy' => 'preserve-bottom-anchor', 'reason' => 'Floating cards must keep authored bottom/left/right anchors.' ),
            array( 'signature' => 'figma.mixed-text.composition', 'strategy' => 'preserve-rich-text-composition', 'reason' => 'Mixed typography in one visual heading must remain one semantic heading.' ),
            array( 'signature' => 'figma.auto-layout.fill', 'strategy' => 'preserve-fill-flex', 'reason' => 'FILL columns must not collapse when mapped to Elementor containers.' ),
            array( 'signature' => 'figma.media.fixed-height', 'strategy' => 'preserve-fixed-media-height', 'reason' => 'Fixed Figma media frames require a deterministic rendered height.' ),
            array( 'signature' => 'figma.rendered-node.geometry', 'strategy' => 'verify-figma-node-geometry', 'reason' => 'Rendered verification must compare the exact Figma node to its Elementor owner, not infer by text/index.' ),
        );
        return array_map( static function ( $lesson ) use ( $common ) { return array_merge( $common, $lesson ); }, $definitions );
    }

    /**
     * Apply only deterministic, source-derived rules. Most strategies influence
     * earlier transport/adapter decisions; this final IR pass hardens the two
     * cases that can safely be repaired without guessing: small primitives and
     * fixed media height. Everything applied is reported in diagnostics.
     */
    public function apply_to_ir( array $ir, array $retrieval = array() ) {
        $strategies = array();
        foreach ( (array) ( $retrieval['lessons'] ?? array() ) as $lesson ) {
            $strategy = sanitize_key( (string) ( $lesson['strategy'] ?? '' ) );
            if ( self::supports_strategy( $strategy ) ) { $strategies[ $strategy ] = true; }
        }
        $applied = array();
        foreach ( $ir['nodes'] ?? array() as $index => $node ) {
            if ( ! is_array( $node ) ) { continue; }
            if ( isset( $strategies['fixed-small-primitive'] ) && $this->is_small_primitive( $node ) ) {
                $geo = (array) ( $node['figma']['geometry'] ?? array() );
                $width = (float) ( $geo['width'] ?? 0 );
                $height = (float) ( $geo['height'] ?? 0 );
                $ir['nodes'][ $index ]['style'] = is_array( $node['style'] ?? null ) ? $node['style'] : array();
                $ir['nodes'][ $index ]['style']['css_fallback'] = is_array( $ir['nodes'][ $index ]['style']['css_fallback'] ?? null ) ? $ir['nodes'][ $index ]['style']['css_fallback'] : array();
                $ir['nodes'][ $index ]['style']['css_fallback']['width'] = $this->px( $width );
                $ir['nodes'][ $index ]['style']['css_fallback']['height'] = $this->px( $height );
                $ir['nodes'][ $index ]['style']['css_fallback']['min-width'] = $this->px( $width );
                $ir['nodes'][ $index ]['style']['css_fallback']['min-height'] = $this->px( $height );
                $ir['nodes'][ $index ]['style']['css_fallback']['flex'] = '0 0 auto';
                $ir['nodes'][ $index ]['figma']['primitive_lock'] = array( 'width' => $width, 'height' => $height, 'rule_version' => self::VERSION );
                $applied[] = array( 'strategy' => 'fixed-small-primitive', 'figma_id' => (string) ( $node['figma']['id'] ?? '' ) );
            }
            if ( isset( $strategies['preserve-fixed-media-height'] ) && $this->is_fixed_media( $node ) ) {
                $height = (float) ( $node['figma']['geometry']['height'] ?? 0 );
                if ( $height > 0 ) {
                    $ir['nodes'][ $index ]['style'] = is_array( $node['style'] ?? null ) ? $node['style'] : array();
                    $ir['nodes'][ $index ]['style']['css_fallback'] = is_array( $ir['nodes'][ $index ]['style']['css_fallback'] ?? null ) ? $ir['nodes'][ $index ]['style']['css_fallback'] : array();
                    $ir['nodes'][ $index ]['style']['css_fallback']['height'] = $this->px( $height );
                    $applied[] = array( 'strategy' => 'preserve-fixed-media-height', 'figma_id' => (string) ( $node['figma']['id'] ?? '' ) );
                }
            }
        }
        $ir['diagnostics'] = is_array( $ir['diagnostics'] ?? null ) ? $ir['diagnostics'] : array();
        $ir['diagnostics']['design_memory'] = array(
            'rule_registry_version' => self::VERSION,
            'retrieved_signatures' => array_values( array_unique( array_map( static fn( $x ) => sanitize_key( (string) ( $x['signature'] ?? '' ) ), (array) ( $retrieval['lessons'] ?? array() ) ) ) ),
            'strategies' => array_keys( $strategies ),
            'applied' => $applied,
        );
        return $ir;
    }

    private function is_small_primitive( array $node ) {
        $type = strtoupper( (string) ( $node['figma']['type'] ?? '' ) );
        if ( ! in_array( $type, array( 'ELLIPSE', 'RECTANGLE', 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'STAR', 'POLYGON', 'INSTANCE', 'FRAME' ), true ) ) { return false; }
        if ( '' !== trim( (string) ( $node['content']['text'] ?? '' ) ) ) { return false; }
        $geo = (array) ( $node['figma']['geometry'] ?? array() );
        $width = (float) ( $geo['width'] ?? 0 );
        $height = (float) ( $geo['height'] ?? 0 );
        if ( $width <= 0 || $height <= 0 || $width > 32 || $height > 32 ) { return false; }
        return true;
    }

    private function is_fixed_media( array $node ) {
        $sizing = (array) ( $node['figma']['sizing'] ?? array() );
        $vertical = strtoupper( (string) ( $sizing['vertical'] ?? $sizing['vertical_mode'] ?? '' ) );
        $has_media = ! empty( $node['assets']['images'] ) || ! empty( $node['style']['background_image'] );
        $geo = (array) ( $node['figma']['geometry'] ?? array() );
        return $has_media && in_array( $vertical, array( 'FIXED', 'FILL' ), true ) && (float) ( $geo['height'] ?? 0 ) >= 40;
    }

    private function px( $value ) {
        return rtrim( rtrim( number_format( (float) $value, 3, '.', '' ), '0' ), '.' ) . 'px';
    }
}
