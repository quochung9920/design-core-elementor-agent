<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Conservative structural diff for rendered browser evidence.
 * v2 keeps the old document/layout checks and adds exact Figma-parent ownership
 * when both sides expose dc-figma-node-* evidence. It never mutates Elementor.
 */
class Design_Core_Elementor_Structural_Diff_Engine {
    const VERSION = 2;

    public function compare( array $reference, array $candidate ) {
        $r = $this->states( $reference );
        $c = $this->states( $candidate );
        $views = array_values( array_unique( array_merge( array_keys( $r ), array_keys( $c ) ) ) );
        sort( $views );
        $issues = array(); $penalty = 0;

        foreach ( $views as $viewport ) {
            $a = (array) ( $r[ $viewport ] ?? array() );
            $b = (array) ( $c[ $viewport ] ?? array() );
            $ac = count( $a ); $bc = count( $b );
            $ratio = max( $ac, $bc ) ? abs( $ac - $bc ) / max( $ac, $bc ) : 0;
            if ( $ratio > 0.12 ) {
                $severity = $ratio > 0.30 ? 'high' : 'medium';
                $issues[] = array( 'viewport' => (int) $viewport, 'category' => 'element-count', 'severity' => $severity, 'reference' => $ac, 'candidate' => $bc, 'ratio' => round( $ratio, 3 ) );
                $penalty += 'high' === $severity ? 22 : 10;
            }

            $as = $this->sections( $a ); $bs = $this->sections( $b );
            if ( $as !== $bs ) {
                $issues[] = array( 'viewport' => (int) $viewport, 'category' => 'section-order', 'severity' => 'high', 'reference' => $as, 'candidate' => $bs );
                $penalty += 24;
            }

            $ah = $this->height( $a ); $bh = $this->height( $b );
            if ( $ah > 0 && $bh > 0 ) {
                $height_ratio = abs( $ah - $bh ) / max( $ah, $bh );
                if ( $height_ratio > 0.10 ) {
                    $severity = $height_ratio > 0.25 ? 'high' : 'medium';
                    $issues[] = array( 'viewport' => (int) $viewport, 'category' => 'document-height', 'severity' => $severity, 'reference' => $ah, 'candidate' => $bh, 'ratio' => round( $height_ratio, 3 ) );
                    $penalty += 'high' === $severity ? 16 : 7;
                }
            }

            if ( $this->layout( $a ) !== $this->layout( $b ) ) {
                $issues[] = array( 'viewport' => (int) $viewport, 'category' => 'layout-pattern', 'severity' => 'medium' );
                $penalty += 6;
            }

            $tree = $this->compare_figma_tree( $a, $b, (int) $viewport );
            foreach ( $tree['issues'] as $issue ) { $issues[] = $issue; }
            $penalty += (int) $tree['penalty'];
        }

        $score = max( 0, 100 - min( 100, $penalty / max( 1, count( $views ) ) ) );
        $high = count( array_filter( $issues, static fn( $issue ) => 'high' === ( $issue['severity'] ?? '' ) ) );
        $rebuild = $high > 0 || $score < 85;
        return array(
            'version' => self::VERSION,
            'status' => $rebuild ? 'needs-rebuild' : ( $issues ? 'warning' : 'pass' ),
            'score' => round( $score, 2 ),
            'issues' => $issues,
            'requires_rebuild' => $rebuild,
            'recommended_action' => $rebuild ? 'regenerate-affected-section-through-build-plan' : 'continue-with-control-level-correction',
            'mutation' => false,
        );
    }

    private function compare_figma_tree( array $reference, array $candidate, $viewport ) {
        $ref = $this->figma_index( $reference );
        $cand = $this->figma_index( $candidate );
        if ( count( $ref ) < 2 || count( $cand ) < 2 ) { return array( 'issues' => array(), 'penalty' => 0 ); }

        $issues = array(); $penalty = 0;
        foreach ( $ref as $class => $left ) {
            if ( ! isset( $cand[ $class ] ) ) {
                $issues[] = array( 'viewport' => $viewport, 'category' => 'figma-node-missing', 'severity' => 'high', 'figma_class' => $class, 'reference_parent' => (string) ( $left['figmaParentClass'] ?? '' ) );
                $penalty += 12;
                continue;
            }
            $right = $cand[ $class ];
            $left_parent = (string) ( $left['figmaParentClass'] ?? $left['figma_parent_class'] ?? '' );
            $right_parent = (string) ( $right['figmaParentClass'] ?? $right['figma_parent_class'] ?? '' );
            if ( $left_parent !== $right_parent ) {
                $issues[] = array(
                    'viewport' => $viewport,
                    'category' => 'figma-parent-mismatch',
                    'severity' => 'high',
                    'figma_class' => $class,
                    'reference_parent' => $left_parent,
                    'candidate_parent' => $right_parent,
                    'elementor_id' => sanitize_key( (string) ( $right['elementorId'] ?? $right['elementor_id'] ?? '' ) ),
                    'parent_elementor_id' => sanitize_key( (string) ( $right['parentElementorId'] ?? $right['parent_elementor_id'] ?? '' ) ),
                );
                $penalty += 14;
            }
        }
        return array( 'issues' => $issues, 'penalty' => $penalty );
    }

    private function figma_index( array $elements ) {
        $out = array();
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) { continue; }
            $class = (string) ( $element['figmaClass'] ?? $element['figma_class'] ?? '' );
            if ( ! $class ) { continue; }
            $direct = in_array( $class, (array) ( $element['classes'] ?? array() ), true );
            if ( ! isset( $out[ $class ] ) || $direct ) { $out[ $class ] = $element; }
        }
        return $out;
    }

    private function states( array $input ) {
        $source = is_array( $input['data']['viewports'] ?? null ) ? $input['data']['viewports'] : (array) ( $input['viewports'] ?? $input['results'] ?? array() );
        $out = array();
        foreach ( $source as $key => $state ) {
            $viewport = (int) ( is_array( $state ) ? ( $state['viewport'] ?? $state['width'] ?? $key ) : $key );
            if ( $viewport <= 0 ) { continue; }
            $elements = is_array( $state ) && isset( $state['elements'] ) ? $state['elements'] : ( is_array( $state ) && isset( $state['nodes'] ) ? $state['nodes'] : $state );
            $out[ $viewport ] = array_values( array_filter( (array) $elements, 'is_array' ) );
        }
        return $out;
    }

    private function sections( array $elements ) {
        $out = array();
        foreach ( $elements as $element ) {
            $meta = (array) ( $element['elementor'] ?? $element['meta'] ?? array() );
            $type = (string) ( $meta['element_type'] ?? $element['element_type'] ?? $element['elementorType'] ?? '' );
            $tag = strtolower( (string) ( $element['tag'] ?? $element['nodeName'] ?? '' ) );
            $role = sanitize_key( (string) ( $element['role'] ?? $element['semantic_role'] ?? '' ) );
            if ( in_array( $type, array( 'section', 'container' ), true ) || in_array( $tag, array( 'section', 'header', 'main', 'footer', 'nav' ), true ) ) { $out[] = $role ?: ( $tag ?: $type ); }
        }
        return array_slice( $out, 0, 80 );
    }

    private function height( array $elements ) {
        $max = 0;
        foreach ( $elements as $element ) {
            $rect = (array) ( $element['rect'] ?? array() );
            $max = max( $max, (float) ( $rect['y'] ?? 0 ) + (float) ( $rect['height'] ?? 0 ) );
        }
        return round( $max, 2 );
    }

    private function layout( array $elements ) {
        $count = array( 'row' => 0, 'column' => 0, 'grid' => 0, 'absolute' => 0 );
        foreach ( $elements as $element ) {
            $style = (array) ( $element['styles'] ?? array() );
            $display = strtolower( (string) ( $style['display'] ?? '' ) );
            $direction = strtolower( (string) ( $style['flexDirection'] ?? '' ) );
            $position = strtolower( (string) ( $style['position'] ?? '' ) );
            if ( 'grid' === $display ) { $count['grid']++; }
            if ( 'flex' === $display && 'column' === $direction ) { $count['column']++; }
            if ( 'flex' === $display && 'column' !== $direction ) { $count['row']++; }
            if ( in_array( $position, array( 'absolute', 'fixed' ), true ) ) { $count['absolute']++; }
        }
        return $count;
    }
}
