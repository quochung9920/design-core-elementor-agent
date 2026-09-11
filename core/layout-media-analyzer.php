<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Enriches canonical IR with viewport-grounded section and media geometry intent. */
class Design_Core_Elementor_Layout_Media_Analyzer {
    private $structural_tags = array( 'section', 'div', 'article', 'header', 'footer', 'main', 'nav', 'aside' );
    private $governed_viewports = array( 1440, 1366, 1024, 767, 390 );

    public function enrich( array $ir, array $browser_analysis = array() ) {
        $viewports = $this->evidence_by_path( $browser_analysis );
        $nodes = array();
        foreach ( $ir['nodes'] ?? array() as $node ) { if ( is_array( $node ) && ! empty( $node['id'] ) ) { $nodes[ $node['id'] ] = $node; } }
        $root_ids = array();
        foreach ( $ir['root_ids'] ?? array() as $root_id ) { if ( is_string( $root_id ) && '' !== $root_id ) { $root_ids[ $root_id ] = true; } }

        $content_children = array();
        foreach ( $nodes as $id => &$node ) {
            $path = (string) ( $node['source']['dom_path'] ?? '' );
            $evidence = $viewports['paths'][ $path ] ?? array();
            $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
            $node['semantic']['width_policy'] = $this->width_policy( $node, $tag );
            $node['semantic']['fixed_width_violation'] = 'fixed' === $node['semantic']['width_policy'];
            // A bounded, non-root content box (card, media, column: 320-1200px) is
            // governed, not fatal: Elementor represents it natively through column
            // widths, image sizes and content-width controls. Page roots and
            // wider-than-viewport boxes stay fatal -- they genuinely break
            // responsiveness and need human review.
            if ( ! empty( $node['semantic']['fixed_width_violation'] ) ) {
                $reason = $this->bounded_box_exception_reason( $node, $id, $path, isset( $root_ids[ $id ] ) );
                if ( '' !== $reason ) { $node['layout_governance'] = array( 'fixed_width_exception' => true, 'reason' => $reason ); }
            }

            if ( in_array( $tag, $this->structural_tags, true ) ) {
                $classification = $this->classify_layout( $node, $nodes, $evidence, $viewports['paths'] );
                $node['semantic']['layout_mode'] = $classification['mode'];
                $node['semantic']['layout_confidence'] = $classification['confidence'];
                $node['semantic']['container_role'] = $classification['role'];
                if ( 'boxed' === $classification['mode'] ) { $node['layout']['content_width'] = 'boxed'; }
                elseif ( in_array( $classification['mode'], array( 'fullwidth', 'fullwidth-content' ), true ) ) { $node['layout']['content_width'] = 'full'; }
                foreach ( $classification['content_children'] as $child_id ) { $content_children[ $child_id ] = true; }
            }
            $node['media'] = $this->analyze_media( $node, $evidence );
            // Bounded fixed-pixel heights (hero/cards: 320-800px) are governed, not
            // fatal: Elementor maps them to min-height controls with responsive
            // overrides. Viewport-unit clipping risks are never excused here.
            // Bounded fixed-pixel heights (hero/cards: 320-800px) are governed, not
            // fatal: Elementor maps them to min-height controls with responsive
            // overrides. Only when that is the SOLE risk -- any viewport-unit
            // clipping risk alongside it keeps the node fatal.
            $warnings = array_values( (array) ( $node['media']['warnings'] ?? array() ) );
            if ( array( 'fixed-pixel-height-responsive-risk' ) === array_values( array_unique( $warnings ) ) ) {
                $height_reason = $this->bounded_height_exception_reason( $node );
                if ( '' !== $height_reason ) { $node['media_governance'] = array( 'height_exception' => true, 'reason' => $height_reason ); }
            }
        }
        unset( $node );

        foreach ( array_keys( $content_children ) as $child_id ) {
            if ( isset( $nodes[ $child_id ] ) ) {
                $nodes[ $child_id ]['semantic']['container_role'] = 'content';
                $nodes[ $child_id ]['semantic']['layout_mode'] = 'boxed';
                $nodes[ $child_id ]['layout']['content_width'] = 'boxed';
            }
        }

        $ir['nodes'] = array_values( $nodes );
        $ir['diagnostics'] = is_array( $ir['diagnostics'] ?? null ) ? $ir['diagnostics'] : array();
        $ir['diagnostics']['layout_media_viewports'] = $viewports['widths'];
        return $ir;
    }

    public function report( array $ir ) {
        $report = array( 'sections' => array(), 'containers' => array(), 'media' => array(), 'fixed_width_violations' => array(), 'fixed_width_exceptions' => array() );
        foreach ( $ir['nodes'] ?? array() as $node ) {
            if ( ! is_array( $node ) || empty( $node['id'] ) ) { continue; }
            $id = $node['id']; $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
            if ( in_array( $tag, $this->structural_tags, true ) && ! empty( $node['semantic']['layout_mode'] ) ) {
                $bucket = 'div' === $tag ? 'containers' : 'sections';
                $report[ $bucket ][ $id ] = array(
                    'layout_mode' => $node['semantic']['layout_mode'],
                    'confidence' => (float) ( $node['semantic']['layout_confidence'] ?? 0 ),
                    'container_role' => (string) ( $node['semantic']['container_role'] ?? '' ),
                    'width_policy' => (string) ( $node['semantic']['width_policy'] ?? '' ),
                );
            }
            if ( ! empty( $node['semantic']['fixed_width_violation'] ) ) { $report['fixed_width_violations'][] = $id; }
            $governance = is_array( $node['layout_governance'] ?? null ) ? $node['layout_governance'] : array();
            if ( ! empty( $governance['fixed_width_exception'] ) ) { $report['fixed_width_exceptions'][ $id ] = (string) ( $governance['reason'] ?? '' ); }
            $media = is_array( $node['media'] ?? null ) ? $node['media'] : array();
            if ( ( $media['kind'] ?? 'none' ) !== 'none' || ! empty( $media['warnings'] ) ) {
                $report['media'][ $id ] = array(
                    'kind' => (string) ( $media['kind'] ?? 'none' ),
                    'height_policy' => (string) ( $media['height_policy'] ?? '' ),
                    'intrinsic_ratio' => (string) ( $media['intrinsic_ratio'] ?? '' ),
                    'object_fit_by_viewport' => (array) ( $media['object_fit_by_viewport'] ?? array() ),
                    'object_position_by_viewport' => (array) ( $media['object_position_by_viewport'] ?? array() ),
                    'background_size_by_viewport' => (array) ( $media['background_size_by_viewport'] ?? array() ),
                    'background_position_by_viewport' => (array) ( $media['background_position_by_viewport'] ?? array() ),
                    'warnings' => array_values( (array) ( $media['warnings'] ?? array() ) ),
                );
            }
        }
        return $report;
    }

    private function evidence_by_path( array $browser_analysis ) {
        $source = is_array( $browser_analysis['data']['viewports'] ?? null ) ? $browser_analysis['data']['viewports'] : (array) ( $browser_analysis['viewports'] ?? array() );
        $paths = array(); $widths = array();
        foreach ( $source as $width => $elements ) {
            if ( ! is_numeric( $width ) || ! is_array( $elements ) ) { continue; }
            $viewport = (int) $width; $widths[] = $viewport;
            foreach ( $elements as $element ) {
                $path = (string) ( $element['domPath'] ?? $element['dom_path'] ?? '' );
                if ( '' !== $path ) { $paths[ $path ][ $viewport ] = $element; }
            }
        }
        rsort( $widths, SORT_NUMERIC );
        return array( 'paths' => $paths, 'widths' => array_values( array_unique( $widths ) ) );
    }

    /**
     * Governed exception for bounded content boxes. Returns the reason string,
     * or '' when the fixed width stays fatal (page root or wider than 1200px).
     */
    private function bounded_box_exception_reason( array $node, $id, $path, $is_root ) {
        if ( $is_root ) { return ''; }
        $max_px = 0.0;
        foreach ( $this->canonical_value_sets( $node ) as $values ) {
            foreach ( array( $values['layout']['width'] ?? null, $values['style']['css_fallback']['width'] ?? null ) as $width ) {
                $dimension = $this->px_dimension( $width );
                if ( null !== $dimension && $dimension > $max_px ) { $max_px = $dimension; }
            }
        }
        if ( $max_px <= 320 || 1200 < $max_px ) { return ''; }
        return 'Bounded ' . (int) $max_px . 'px content box at ' . $path . ' (' . $id . '): Elementor represents it natively via column/image/content-width controls; page-level and wider-than-1200px fixed widths stay fatal.';
    }

    /**
     * Governed exception for bounded fixed-pixel heights. Returns the reason
     * string, or '' when the height stays fatal (unbounded, or a viewport-unit
     * clipping risk which this method never excuses).
     */
    private function bounded_height_exception_reason( array $node ) {
        $max_px = 0.0;
        foreach ( $this->canonical_value_sets( $node ) as $values ) {
            $raw = $values['layout']['height'] ?? ( $values['style']['css_fallback']['height'] ?? null );
            if ( is_array( $raw ) && array_key_exists( 'value', $raw ) ) {
                if ( 'px' === strtolower( (string) ( $raw['unit'] ?? '' ) ) && is_numeric( $raw['value'] ) ) { $max_px = max( $max_px, (float) $raw['value'] ); }
                continue;
            }
            $scalar = $this->constant_pixel_value( $raw );
            if ( null !== $scalar ) { $max_px = max( $max_px, $scalar ); }
        }
        if ( $max_px <= 320 || 800 < $max_px ) { return ''; }
        return 'Bounded ' . (int) $max_px . 'px fixed height: Elementor maps it to a min-height control with responsive overrides; taller fixed heights stay fatal.';
    }

    private function px_dimension( $width ) {        if ( is_array( $width ) && array_key_exists( 'value', $width ) ) {
            if ( 'px' === strtolower( (string) ( $width['unit'] ?? '' ) ) && is_numeric( $width['value'] ) ) { return (float) $width['value']; }
            return null;
        }
        if ( is_scalar( $width ) && preg_match( '/^\s*([0-9.]+)px\s*$/i', (string) $width, $match ) ) { return (float) $match[1]; }
        return null;
    }

    private function width_policy( array $node, $tag ) {        if ( ! in_array( $tag, $this->structural_tags, true ) ) { return 'intrinsic'; }
        $has_max = false;
        foreach ( $this->canonical_value_sets( $node ) as $values ) {
            foreach ( array( $values['layout']['width'] ?? null, $values['style']['css_fallback']['width'] ?? null ) as $width ) {
                if ( is_array( $width ) && array_key_exists( 'value', $width ) ) {
                    $unit = strtolower( (string) ( $width['unit'] ?? '' ) );
                    if ( 'px' === $unit && (float) ( $width['value'] ?? 0 ) > 320 ) { return 'fixed'; }
                } elseif ( is_scalar( $width ) ) {
                    $constant = $this->constant_pixel_value( $width );
                    if ( null !== $constant && 320 < $constant ) { return 'fixed'; }
                }
            }
            if ( isset( $values['layout']['max_width'] ) || isset( $values['style']['css_fallback']['max-width'] ) ) { $has_max = true; }
        }
        return $has_max ? 'fluid-max' : 'fluid';
    }

    private function classify_layout( array $node, array $nodes, array $evidence, array $all_paths ) {
        $available = array_map( 'intval', array_keys( $evidence ) );
        $missing = array_diff( $this->governed_viewports, $available );
        if ( $missing ) {
            $coverage = count( array_intersect( $this->governed_viewports, $available ) ) / count( $this->governed_viewports );
            return array( 'mode' => 'unknown', 'confidence' => min( 0.4, 0.1 + 0.3 * $coverage ), 'role' => 'structural', 'content_children' => array() );
        }

        $full = 0; $boxed = 0; $visual = 0; $samples = 0; $child_metrics = array();
        foreach ( $node['children'] ?? array() as $child_id ) { $child_metrics[ $child_id ] = array( 'seen' => 0, 'full' => 0, 'constrained' => 0 ); }
        foreach ( $this->governed_viewports as $viewport ) {
            $sample = $evidence[ $viewport ] ?? array();
            $rect = is_array( $sample['rect'] ?? null ) ? $sample['rect'] : array();
            $width = (float) ( $rect['width'] ?? 0 ); $x = (float) ( $rect['x'] ?? 0 );
            if ( $width <= 0 ) { continue; }
            $samples++; $ratio = $width / $viewport; $right = $viewport - ( $x + $width );
            if ( $ratio >= 0.985 && abs( $x ) <= 3 ) { $full++; }
            if ( $ratio < 0.985 && $x >= 8 && $right >= 8 && abs( $x - $right ) <= max( 4, $viewport * 0.03 ) ) { $boxed++; }
            if ( $this->has_visual_surface( $sample['styles'] ?? array() ) ) { $visual++; }
            foreach ( $child_metrics as $child_id => &$metrics ) {
                $child_path = (string) ( $nodes[ $child_id ]['source']['dom_path'] ?? '' );
                $child = $all_paths[ $child_path ][ $viewport ] ?? null;
                if ( ! is_array( $child ) ) { continue; }
                $child_rect = $child['rect'] ?? array(); $child_width = (float) ( $child_rect['width'] ?? 0 ); $child_x = (float) ( $child_rect['x'] ?? 0 );
                if ( $child_width <= 0 ) { continue; }
                $metrics['seen']++; $child_right = $viewport - ( $child_x + $child_width ); $child_ratio = $child_width / $viewport;
                if ( $child_ratio >= 0.985 && abs( $child_x ) <= 3 ) { $metrics['full']++; }
                if ( $child_ratio < 0.96 && $child_x >= 8 && $child_right >= 8 && abs( $child_x - $child_right ) <= max( 4, $viewport * 0.03 ) ) { $metrics['constrained']++; }
            }
            unset( $metrics );
        }
        if ( count( $this->governed_viewports ) !== $samples ) { return array( 'mode' => 'unknown', 'confidence' => 0.4, 'role' => 'structural', 'content_children' => array() ); }
        $majority = (int) floor( $samples / 2 ) + 1;
        $constrained_children = array(); $all_children_full = ! empty( $child_metrics ); $all_children_constrained = ! empty( $child_metrics );
        foreach ( $child_metrics as $child_id => $metrics ) {
            if ( $metrics['constrained'] >= $majority ) { $constrained_children[] = $child_id; } else { $all_children_constrained = false; }
            if ( $metrics['seen'] !== $samples || $metrics['full'] < $majority ) { $all_children_full = false; }
        }
        if ( $full >= $majority ) {
            if ( $constrained_children && $all_children_constrained ) { return array( 'mode' => 'fullwidth', 'confidence' => 0.95, 'role' => 'surface', 'content_children' => $constrained_children ); }
            if ( $constrained_children ) { return array( 'mode' => 'mixed', 'confidence' => 0.92, 'role' => 'surface', 'content_children' => $constrained_children ); }
            if ( $all_children_full ) { return array( 'mode' => 'fullwidth-content', 'confidence' => 0.94, 'role' => 'surface-content', 'content_children' => array() ); }
            return array( 'mode' => 'mixed', 'confidence' => 0.72, 'role' => 'structural', 'content_children' => array() );
        }
        if ( $boxed >= $majority && $visual >= $majority ) { return array( 'mode' => 'boxed', 'confidence' => 0.93, 'role' => 'surface-content', 'content_children' => array() ); }
        return array( 'mode' => 'mixed', 'confidence' => 0.65, 'role' => 'structural', 'content_children' => array() );
    }

    private function has_visual_surface( array $styles ) {
        $background = strtolower( trim( (string) ( $styles['backgroundColor'] ?? '' ) ) );
        $image = strtolower( trim( (string) ( $styles['backgroundImage'] ?? 'none' ) ) );
        return ( '' !== $background && ! in_array( $background, array( 'transparent', 'rgba(0, 0, 0, 0)', 'rgba(0,0,0,0)' ), true ) )
            || ( '' !== $image && 'none' !== $image )
            || 0 < (float) ( $styles['borderTopWidth'] ?? 0 )
            || ! in_array( strtolower( trim( (string) ( $styles['boxShadow'] ?? 'none' ) ) ), array( '', 'none' ), true )
            || 0 < (float) ( $styles['borderRadius'] ?? 0 );
    }

    private function analyze_media( array $node, array $evidence ) {
        $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
        $fallback = is_array( $node['style']['css_fallback'] ?? null ) ? $node['style']['css_fallback'] : array();
        $background_value = strtolower( (string) ( $fallback['background-image'] ?? ( $node['style']['background_image']['url'] ?? '' ) ) );
        foreach ( $evidence as $sample ) {
            $computed = strtolower( (string) ( $sample['styles']['backgroundImage'] ?? 'none' ) );
            if ( 'none' !== $computed && '' !== $computed ) { $background_value = $computed; break; }
        }
        $kind = 'img' === $tag ? 'image' : ( $background_value ? 'background' : 'none' );
        $result = array( 'kind' => $kind, 'height_policy' => 'content-driven', 'warnings' => $this->height_warnings( $node ), 'viewport_evidence_count' => count( $evidence ) );

        $object_fits = array(); $object_positions = array(); $background_sizes = array(); $background_positions = array();
        foreach ( $evidence as $viewport => $sample ) {
            $styles = is_array( $sample['styles'] ?? null ) ? $sample['styles'] : array();
            if ( '' !== (string) ( $styles['objectFit'] ?? '' ) ) { $object_fits[ (int) $viewport ] = strtolower( (string) $styles['objectFit'] ); }
            if ( '' !== (string) ( $styles['objectPosition'] ?? '' ) ) { $object_positions[ (int) $viewport ] = (string) $styles['objectPosition']; }
            if ( '' !== (string) ( $styles['backgroundSize'] ?? '' ) ) { $background_sizes[ (int) $viewport ] = (string) $styles['backgroundSize']; }
            if ( '' !== (string) ( $styles['backgroundPosition'] ?? '' ) ) { $background_positions[ (int) $viewport ] = (string) $styles['backgroundPosition']; }
        }
        if ( $object_fits ) { $result['object_fit_by_viewport'] = $object_fits; }
        if ( $object_positions ) { $result['object_position_by_viewport'] = $object_positions; }
        if ( $background_sizes ) { $result['background_size_by_viewport'] = $background_sizes; }
        if ( $background_positions ) { $result['background_position_by_viewport'] = $background_positions; }

        if ( 'image' === $kind ) {
            $best_pair = array( 0, 0 ); $best_area = 0; $ratios_match = true; $samples = 0;
            foreach ( $evidence as $sample ) {
                $natural_width = (int) ( $sample['naturalWidth'] ?? 0 ); $natural_height = (int) ( $sample['naturalHeight'] ?? 0 );
                $area = $natural_width * $natural_height;
                if ( $area > $best_area ) { $best_area = $area; $best_pair = array( $natural_width, $natural_height ); }
                $rendered_width = (float) ( $sample['rect']['width'] ?? 0 ); $rendered_height = (float) ( $sample['rect']['height'] ?? 0 );
                if ( $rendered_width > 0 && $rendered_height > 0 && $natural_width > 0 && $natural_height > 0 ) {
                    $samples++; if ( abs( $rendered_width / $rendered_height - $natural_width / $natural_height ) > 0.03 ) { $ratios_match = false; }
                }
            }
            if ( $best_pair[0] > 0 && $best_pair[1] > 0 ) { $result['intrinsic_ratio'] = $best_pair[0] . '/' . $best_pair[1]; }
            if ( in_array( 'cover', $object_fits, true ) ) { $result['height_policy'] = 'crop-ratio'; }
            elseif ( $samples && $ratios_match && ! in_array( 'contain', $object_fits, true ) ) { $result['height_policy'] = 'intrinsic-ratio'; }
            else { $result['height_policy'] = 'responsive-auto'; }
            return $result;
        }

        foreach ( $this->canonical_value_sets( $node ) as $values ) {
            $min_height = strtolower( (string) ( $values['style']['css_fallback']['min-height'] ?? '' ) );
            if ( $min_height && false !== strpos( $min_height, 'clamp(' ) && preg_match( '/[0-9.](?:svh|dvh|vh)\b/', $min_height ) ) { $result['height_policy'] = 'viewport-min-height'; break; }
            if ( isset( $values['layout']['min_height'] ) ) { $result['height_policy'] = 'responsive-min-height'; }
        }
        if ( 'content-driven' === $result['height_policy'] && 'background' === $kind ) { $result['height_policy'] = 'content-driven-background'; }
        return $result;
    }

    private function height_warnings( array $node ) {
        $warnings = array();
        foreach ( $this->canonical_value_sets( $node ) as $values ) {
            $height_value = $values['layout']['height'] ?? ( $values['style']['css_fallback']['height'] ?? '' );
            $height = is_array( $height_value ) && array_key_exists( 'value', $height_value )
                ? (string) $height_value['value'] . (string) ( $height_value['unit'] ?? '' )
                : strtolower( trim( (string) $height_value ) );
            $overflow = strtolower( (string) ( $values['style']['css_fallback']['overflow'] ?? ( $values['layout']['overflow'] ?? '' ) ) );
            if ( preg_match( '/(?:^|[^a-z])(?:[0-9.]+)?dvh\b/i', $height ) ) { $warnings[] = 'dynamic-viewport-height-layout-shift-risk'; }
            elseif ( preg_match( '/(?:^|[^a-z])(?:[0-9.]+)?(?:svh|lvh|vh)\b/i', $height ) ) { $warnings[] = 'hidden' === $overflow ? 'fixed-height-100vh-content-clipping-risk' : 'fixed-height-100vh-content-growth-risk'; }
            elseif ( null !== ( $fixed_height = $this->constant_pixel_value( $height ) ) && 320 < $fixed_height ) { $warnings[] = 'fixed-pixel-height-responsive-risk'; }
        }
        return array_values( array_unique( $warnings ) );
    }

    private function constant_pixel_value( $value ) {
        if ( class_exists( 'Design_Core_Elementor_Design_IR_Validator' ) ) { return Design_Core_Elementor_Design_IR_Validator::constant_pixel_value( $value ); }
        return preg_match( '/^\s*([0-9.]+)px\s*$/i', (string) $value, $match ) ? (float) $match[1] : null;
    }

    private function canonical_value_sets( array $node ) {
        $sets = array( array( 'layout' => (array) ( $node['layout'] ?? array() ), 'style' => (array) ( $node['style'] ?? array() ) ) );
        foreach ( $node['responsive'] ?? array() as $responsive ) {
            if ( is_array( $responsive ) ) { $sets[] = array( 'layout' => (array) ( $responsive['layout'] ?? array() ), 'style' => (array) ( $responsive['style'] ?? array() ) ); }
        }
        return $sets;
    }
}
