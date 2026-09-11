<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Built-in, generic fidelity rules. These are compiler invariants, not project
 * hacks. Learned lessons supplement this registry but never silently mutate it.
 */
class Design_Core_Elementor_Fidelity_Rule_Registry {
    const VERSION = 1;

    public function all() {
        return array(
            array(
                'id' => 'preserve-vector-component-assets',
                'signature_pattern' => 'figma.vector-component.asset-loss',
                'priority' => 100,
                'min_confidence' => 0.85,
                'action' => array( 'strategy' => 'preserve-vector-asset', 'flatten_parent' => false, 'prefer_svg_export' => true, 'fixed_intrinsic_size' => true ),
                'safeguards' => array( 'never-replace-glyph-with-empty-container', 'never-hand-draw-unknown-vector' ),
                'rationale' => 'Figma vector/component assets must survive as the authored asset or a verified native equivalent.'
            ),
            array(
                'id' => 'protect-small-primitives-from-flex-stretch',
                'signature_pattern' => 'figma.small-primitive.flex-stretch',
                'priority' => 95,
                'min_confidence' => 0.90,
                'action' => array( 'strategy' => 'fixed-primitive-size', 'flex_grow' => 0, 'flex_shrink' => 0, 'preserve_width_height' => true ),
                'safeguards' => array( 'applies-only-when-reference-both-dimensions-lte-32' ),
                'rationale' => 'Small authored dots/icons must not inherit fill/stretch semantics from a generic Elementor container.'
            ),
            array(
                'id' => 'preserve-mixed-text-composition',
                'signature_pattern' => 'figma.mixed-text.composition',
                'priority' => 100,
                'min_confidence' => 0.90,
                'action' => array( 'strategy' => 'compose-rich-text', 'merge_visual_text_cluster' => true, 'preserve_runs' => true, 'preserve_line_breaks' => true ),
                'safeguards' => array( 'do-not-stack-inline-runs-as-separate-widgets' ),
                'rationale' => 'Mixed Figma text runs describe one visual text composition and must not become unrelated Elementor widgets.'
            ),
            array(
                'id' => 'preserve-absolute-anchor-intent',
                'signature_pattern' => 'figma.absolute.anchor-loss',
                'priority' => 90,
                'min_confidence' => 0.90,
                'action' => array( 'strategy' => 'preserve-anchor', 'respect_constraints' => true, 'preserve_relative_owner' => true ),
                'safeguards' => array( 'do-not-convert-bottom-anchor-to-top-without-geometry-proof' ),
                'rationale' => 'Absolute Figma nodes are anchored relative to a visual owner; changing the anchor changes composition.'
            ),
            array(
                'id' => 'preserve-fill-flex-semantics',
                'signature_pattern' => 'figma.fill-sizing.flex-loss',
                'priority' => 88,
                'min_confidence' => 0.88,
                'action' => array( 'strategy' => 'preserve-fill-sizing', 'flex' => '1 0 0%', 'min_width' => '0px' ),
                'safeguards' => array( 'only-when-parent-axis-matches-fill-direction' ),
                'rationale' => 'Figma FILL in auto-layout maps to flex participation, not arbitrary fixed width.'
            ),
            array(
                'id' => 'preserve-image-crop-position',
                'signature_pattern' => 'figma.image.crop-position',
                'priority' => 85,
                'min_confidence' => 0.90,
                'action' => array( 'strategy' => 'preserve-image-transform', 'map_crop_to_object_position' => true, 'map_scale_mode' => true ),
                'safeguards' => array( 'do-not-default-to-center-when-transform-exists' ),
                'rationale' => 'A correct image with a wrong focal point is a fidelity failure.'
            ),
            array(
                'id' => 'require-font-proof',
                'signature_pattern' => 'figma.font.fidelity',
                'priority' => 82,
                'min_confidence' => 0.95,
                'action' => array( 'strategy' => 'verify-font-availability', 'block_visual_pass_if_missing' => true ),
                'safeguards' => array( 'do-not-substitute-brand-font-silently' ),
                'rationale' => 'Typography geometry cannot be trusted while the authored font is unavailable.'
            ),
        );
    }

    public function match( $signature ) {
        $signature = strtolower( trim( (string) $signature ) );
        $matches = array();
        foreach ( $this->all() as $rule ) {
            $pattern = strtolower( (string) ( $rule['signature_pattern'] ?? '' ) );
            if ( '' === $pattern ) { continue; }
            if ( $signature === $pattern || str_starts_with( $signature, $pattern . '.' ) || str_starts_with( $pattern, $signature . '.' ) ) { $matches[] = $rule; }
        }
        usort( $matches, static fn( $a, $b ) => (int) ( $b['priority'] ?? 0 ) <=> (int) ( $a['priority'] ?? 0 ) );
        return $matches;
    }
}
