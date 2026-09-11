<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Responsive structural layout inference on canonical Design IR.
 *
 * v3 describes layout as states rather than a single desktop guess. It still
 * stays Elementor-neutral: full-bleed, split, grid, stack and overlap are design
 * facts derived from IR plus optional browser geometry.
 */
class Design_Core_Elementor_Layout_Intelligence {
    const VERSION = 3;

    public function enrich( array $ir, array $browser_analysis = array() ) {
        $by_id = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) { if ( is_array( $node ) && ! empty( $node['id'] ) ) { $by_id[ $node['id'] ] = $node; } }
        $geometry_all = $this->geometry_index_all( $browser_analysis );
        $states = $this->states( $by_id );

        foreach ( array_keys( $by_id ) as $id ) {
            $state_reports = array();
            foreach ( $states as $state ) {
                $effective = $this->effective_nodes( $by_id, $state );
                $geometry = $this->geometry_for_state( $effective, $geometry_all, $state );
                $state_reports[ $state ] = $this->detect_node_state( $effective[ $id ], $effective, $geometry );
            }
            $desktop = (array) ( $state_reports['desktop'] ?? reset( $state_reports ) ?: array() );
            $transitions = array(); $previous_state = ''; $previous_pattern = '';
            foreach ( $state_reports as $state => $report ) {
                $pattern = (string) ( $report['pattern'] ?? '' );
                if ( $previous_state && $pattern && $previous_pattern && $pattern !== $previous_pattern ) { $transitions[] = $previous_state . '->' . $state . ':' . $previous_pattern . '->' . $pattern; }
                $previous_state = $state; $previous_pattern = $pattern;
            }
            $desktop['version'] = self::VERSION;
            $desktop['states'] = $state_reports;
            $desktop['transitions'] = $transitions;
            $desktop['responsive_pattern_change'] = ! empty( $transitions );
            $by_id[ $id ]['layout_intelligence'] = $desktop;
        }

        $ir['nodes'] = array_values( $by_id );
        $ir['diagnostics'] = is_array( $ir['diagnostics'] ?? null ) ? $ir['diagnostics'] : array();
        $ir['diagnostics']['layout_intelligence_version'] = self::VERSION;
        $ir['diagnostics']['layout_intelligence_states'] = $states;
        return $ir;
    }

    public function report( array $ir ) {
        $patterns = array(); $nodes = array(); $transitions = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            $info = is_array( $node['layout_intelligence'] ?? null ) ? $node['layout_intelligence'] : array();
            if ( empty( $info['pattern'] ) ) { continue; }
            $pattern = $info['pattern']; $patterns[ $pattern ] = ( $patterns[ $pattern ] ?? 0 ) + 1; $nodes[ $node['id'] ] = $info;
            foreach ( (array) ( $info['transitions'] ?? array() ) as $transition ) { $transitions[ $transition ] = ( $transitions[ $transition ] ?? 0 ) + 1; }
        }
        ksort( $patterns ); ksort( $transitions );
        return array( 'version' => self::VERSION, 'patterns' => $patterns, 'responsive_transitions' => $transitions, 'nodes' => $nodes );
    }

    /** Public pure seam retained for tests/consumers; represents the desktop state. */
    public function detect_node( array $node, array $by_id = array(), array $geometry = array() ) { return $this->detect_node_state( $node, $by_id, $geometry ); }

    public function detect_node_state( array $node, array $by_id = array(), array $geometry = array() ) {
        $children = array_values( array_filter( (array) ( $node['children'] ?? array() ), static function ( $id ) use ( $by_id ) { return isset( $by_id[ $id ] ); } ) );
        $layout = is_array( $node['layout'] ?? null ) ? $node['layout'] : array();
        $semantic = is_array( $node['semantic'] ?? null ) ? $node['semantic'] : array();
        $display = strtolower( (string) ( $layout['display'] ?? '' ) );
        $direction = strtolower( (string) ( $layout['direction'] ?? $layout['flex_direction'] ?? '' ) );
        $wrap = strtolower( (string) ( $layout['wrap'] ?? $layout['flex_wrap'] ?? '' ) );
        $evidence = array(); $confidence = 0.55;

        $absolute_children = 0;
        foreach ( $children as $child_id ) {
            $child = $by_id[ $child_id ];
            $position = strtolower( (string) ( $child['layout']['position'] ?? $child['style']['css_fallback']['position'] ?? '' ) );
            if ( 'absolute' === $position || 'fixed' === $position ) { $absolute_children++; }
        }
        if ( $absolute_children > 0 ) {
            return array( 'version' => self::VERSION, 'pattern' => 'overlap', 'confidence' => 0.9, 'columns' => max( 1, count( $children ) - $absolute_children ), 'ratios' => array(), 'evidence' => array( 'absolute-children:' . $absolute_children ) );
        }

        $role = strtolower( (string) ( $semantic['container_role'] ?? '' ) );
        $mode = strtolower( (string) ( $semantic['layout_mode'] ?? '' ) );
        if ( in_array( $mode, array( 'fullwidth', 'fullwidth-content' ), true ) && $children ) {
            foreach ( $children as $child_id ) {
                if ( 'content' === strtolower( (string) ( $by_id[ $child_id ]['semantic']['container_role'] ?? '' ) ) ) {
                    return array( 'version' => self::VERSION, 'pattern' => 'full-bleed-inner', 'confidence' => 0.96, 'columns' => 1, 'ratios' => array( 1.0 ), 'evidence' => array( 'layout-mode:' . $mode, 'content-child:' . $child_id ) );
                }
            }
        }

        $ratios = $this->child_ratios( $node, $by_id, $geometry );
        // The ratio heuristic is only a fallback for when no explicit direction is known (e.g.
        // raw scraped HTML with no declared flex-direction) -- two 100%-width stacked children
        // also trivially normalize to ratios summing to ~1.0, so it must never override an
        // explicit, already-known 'column' direction.
        if ( 2 === count( $children ) && ( in_array( $direction, array( 'row', 'horizontal' ), true ) || ( '' === $direction && $this->looks_horizontal( $ratios ) ) ) ) {
            $ratios_from_evidence = ! empty( $ratios ); if ( ! $ratios ) { $ratios = array( 0.5, 0.5 ); }
            return array( 'version' => self::VERSION, 'pattern' => 'split', 'confidence' => $ratios_from_evidence ? 0.92 : 0.75, 'columns' => 2, 'ratios' => $ratios, 'evidence' => array( 'children:2', 'direction:' . $direction ) );
        }

        $repeated = $this->repeated_children( $children, $by_id );
        if ( count( $children ) >= 3 && ( 'grid' === $display || 'wrap' === $wrap || $repeated >= 0.66 ) ) {
            $columns = $this->infer_columns( $node, $children, $by_id, $geometry );
            return array( 'version' => self::VERSION, 'pattern' => 'grid', 'confidence' => 0.88, 'columns' => $columns, 'ratios' => $ratios, 'evidence' => array( 'children:' . count( $children ), 'repeated:' . number_format( $repeated, 2, '.', '' ), 'display:' . $display ) );
        }

        if ( $children && ( in_array( $direction, array( 'column', 'vertical' ), true ) || count( $children ) === 1 ) ) {
            return array( 'version' => self::VERSION, 'pattern' => 'stack', 'confidence' => 0.84, 'columns' => 1, 'ratios' => array( 1.0 ), 'evidence' => array( 'direction:' . $direction, 'children:' . count( $children ) ) );
        }

        if ( $children && 'surface' === $role ) { $evidence[] = 'surface-container'; $confidence = 0.68; }
        return array( 'version' => self::VERSION, 'pattern' => $children ? 'flow' : 'leaf', 'confidence' => $confidence, 'columns' => $children ? 1 : 0, 'ratios' => $ratios, 'evidence' => $evidence );
    }

    private function states( array $by_id ) {
        $ordered = array( 'desktop', 'widescreen', 'laptop', 'tablet_extra', 'tablet', 'mobile_extra', 'mobile' ); $present = array( 'desktop' => true, 'tablet' => true, 'mobile' => true );
        foreach ( $by_id as $node ) { foreach ( array_keys( (array) ( $node['responsive'] ?? array() ) ) as $state ) { $present[ sanitize_key( (string) $state ) ] = true; } }
        return array_values( array_filter( $ordered, static function ( $state ) use ( $present ) { return ! empty( $present[ $state ] ); } ) );
    }

    private function effective_nodes( array $by_id, $state ) {
        if ( 'desktop' === $state ) { return $by_id; }
        $out = $by_id;
        foreach ( $out as $id => $node ) {
            $delta = is_array( $node['responsive'][ $state ] ?? null ) ? $node['responsive'][ $state ] : array();
            if ( ! $delta ) { continue; }
            $layout_keys = array( 'display','position','direction','flex_direction','wrap','flex_wrap','width','height','min_width','max_width','min_height','max_height','gap','row_gap','column_gap','justify_content','align_items','columns' );
            foreach ( $delta as $key => $value ) {
                if ( in_array( $key, $layout_keys, true ) ) { $out[ $id ]['layout'][ $key ] = $value; }
                elseif ( 'position' === $key ) { $out[ $id ]['layout']['position'] = $value; }
            }
        }
        return $out;
    }

    private function child_ratios( array $node, array $by_id, array $geometry ) {
        $children = (array) ( $node['children'] ?? array() ); $widths = array(); $total = 0.0;
        foreach ( $children as $child_id ) {
            if ( ! isset( $by_id[ $child_id ] ) ) { continue; }
            $width = $this->dimension_percent( $by_id[ $child_id ]['layout']['width'] ?? null );
            if ( null === $width && isset( $geometry[ $child_id ]['width'] ) ) { $width = (float) $geometry[ $child_id ]['width']; }
            if ( null === $width || $width <= 0 ) { return array(); }
            $widths[] = $width; $total += $width;
        }
        if ( $total <= 0 ) { return array(); }
        return array_map( static function ( $width ) use ( $total ) { return round( $width / $total, 4 ); }, $widths );
    }

    private function dimension_percent( $value ) {
        if ( is_array( $value ) && array_key_exists( 'value', $value ) ) { $unit = strtolower( (string) ( $value['unit'] ?? '' ) ); if ( '%' === $unit || '' === $unit ) { return (float) $value['value']; } }
        if ( is_scalar( $value ) && preg_match( '/^([0-9.]+)%$/', trim( (string) $value ), $m ) ) { return (float) $m[1]; }
        return null;
    }

    private function looks_horizontal( array $ratios ) { return 2 === count( $ratios ) && abs( array_sum( $ratios ) - 1.0 ) < 0.02; }

    private function repeated_children( array $children, array $by_id ) {
        if ( count( $children ) < 2 ) { return 0.0; } $signatures = array();
        foreach ( $children as $child_id ) { $fingerprint = (array) ( $by_id[ $child_id ]['component']['fingerprint'] ?? array() ); $signature = (string) ( $fingerprint['structure'] ?? $by_id[ $child_id ]['semantic']['role'] ?? '' ); if ( $signature ) { $signatures[] = $signature; } }
        if ( ! $signatures ) { return 0.0; } $counts = array_count_values( $signatures ); return max( $counts ) / count( $signatures );
    }

    private function infer_columns( array $node, array $children, array $by_id, array $geometry ) {
        $declared = (int) ( $node['layout']['columns'] ?? 0 ); if ( $declared > 0 ) { return min( 12, $declared ); }
        $ratios = $this->child_ratios( $node, $by_id, $geometry ); if ( $ratios ) { $average = array_sum( $ratios ) / count( $ratios ); if ( $average > 0 ) { return max( 1, min( 6, (int) round( 1 / $average ) ) ); } }
        $count = count( $children ); if ( $count >= 4 ) { return 4; } return min( 3, max( 1, $count ) );
    }

    private function geometry_index_all( array $browser_analysis ) {
        $viewports = is_array( $browser_analysis['data']['viewports'] ?? null ) ? $browser_analysis['data']['viewports'] : (array) ( $browser_analysis['viewports'] ?? array() ); $out = array();
        foreach ( $viewports as $viewport => $samples ) {
            foreach ( (array) $samples as $sample ) { $path = (string) ( $sample['domPath'] ?? $sample['dom_path'] ?? '' ); if ( ! $path ) { continue; } $rect = (array) ( $sample['rect'] ?? array() ); $out[ (int) $viewport ][ $path ] = array( 'width' => (float) ( $rect['width'] ?? 0 ), 'height' => (float) ( $rect['height'] ?? 0 ), 'x' => (float) ( $rect['x'] ?? 0 ), 'y' => (float) ( $rect['y'] ?? 0 ) ); }
        }
        krsort( $out, SORT_NUMERIC ); return $out;
    }

    private function geometry_for_state( array $nodes, array $all, $state ) {
        if ( ! $all ) { return array(); }
        $target = array( 'desktop' => 1440, 'widescreen' => 1600, 'laptop' => 1024, 'tablet_extra' => 900, 'tablet' => 767, 'mobile_extra' => 600, 'mobile' => 390 ); $want = (int) ( $target[ $state ] ?? 1440 );
        $best_width = null; $distance = PHP_INT_MAX; foreach ( array_keys( $all ) as $width ) { $d = abs( (int) $width - $want ); if ( $d < $distance ) { $distance = $d; $best_width = (int) $width; } }
        if ( null === $best_width ) { return array(); } $geometry = array();
        foreach ( $nodes as $id => $node ) { $path = (string) ( $node['source']['dom_path'] ?? '' ); if ( $path && isset( $all[ $best_width ][ $path ] ) ) { $geometry[ $id ] = $all[ $best_width ][ $path ]; } }
        return $geometry;
    }
}
