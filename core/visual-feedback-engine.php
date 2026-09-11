<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Section/element-aware visual feedback.
 *
 * v3 keeps screenshot similarity as the visual truth, automatically collects
 * rendered DOM/computed-style evidence when Playwright is available, aligns
 * reference/candidate elements conservatively and emits element-addressable,
 * native-control-first correction directives.
 */
class Design_Core_Elementor_Visual_Feedback_Engine {
    const VERSION = 3;

    public function evaluate_targets( $reference_target, $candidate_target, array $context = array() ) {
        $reference_target = trim( (string) $reference_target );
        $candidate_target = trim( (string) $candidate_target );
        if ( '' === $reference_target || '' === $candidate_target ) { return new WP_Error( 'design_core_visual_target_missing', 'Reference and candidate visual targets are required.' ); }
        $reference_target = $this->validate_capture_target( $reference_target );
        if ( is_wp_error( $reference_target ) ) { return $reference_target; }
        $candidate_target = $this->validate_capture_target( $candidate_target );
        if ( is_wp_error( $candidate_target ) ) { return $candidate_target; }

        $comparison = ( new Design_Core_Elementor_Screenshot_Service() )->compare_targets( $reference_target, $candidate_target, $context['workdir'] ?? '' );
        if ( is_wp_error( $comparison ) ) { return $comparison; }

        $reference_analysis = is_array( $context['reference_analysis'] ?? null ) ? $context['reference_analysis'] : array();
        $candidate_analysis = is_array( $context['candidate_analysis'] ?? null ) ? $context['candidate_analysis'] : array();
        $analysis_errors = array();
        if ( ( ! $reference_analysis || ! $candidate_analysis ) && class_exists( 'Design_Core_Elementor_Browser_Analysis_Service' ) ) {
            $browser = new Design_Core_Elementor_Browser_Analysis_Service();
            if ( $browser->is_available() ) {
                $viewports = array_map( 'intval', array_keys( $comparison ) );
                if ( ! $reference_analysis ) {
                    $result = $browser->analyze_target( $reference_target, $viewports, (array) ( $context['reference_allowed_hosts'] ?? array() ) );
                    if ( is_wp_error( $result ) ) { $analysis_errors['reference'] = $result->get_error_message(); } else { $reference_analysis = $result; }
                }
                if ( ! $candidate_analysis ) {
                    $result = $browser->analyze_target( $candidate_target, $viewports, (array) ( $context['candidate_allowed_hosts'] ?? array() ) );
                    if ( is_wp_error( $result ) ) { $analysis_errors['candidate'] = $result->get_error_message(); } else { $candidate_analysis = $result; }
                }
            } else { $analysis_errors['browser'] = 'Rendered DOM analysis is unavailable.'; }
        }

        $geometry = $reference_analysis && $candidate_analysis ? $this->compare_analysis( $reference_analysis, $candidate_analysis ) : array();
        $result = $this->evaluate( $comparison, $geometry, (float) ( $context['target_similarity'] ?? 0.95 ) );
        $result['analysis_used'] = ! empty( $geometry );
        $result['analysis_errors'] = $analysis_errors;
        $result['reference_analysis_schema'] = (int) ( $reference_analysis['schema_version'] ?? 0 );
        $result['candidate_analysis_schema'] = (int) ( $candidate_analysis['schema_version'] ?? 0 );
        if ( ! empty( $context['page_id'] ) && function_exists( 'update_post_meta' ) ) { update_post_meta( (int) $context['page_id'], '_design_core_visual_feedback', $result ); }
        return $result;
    }

    public function evaluate( array $screenshot_comparison, array $geometry_diffs = array(), $target_similarity = 0.95 ) {
        $scores = array(); $failed = 0; $viewport_issues = array();
        foreach ( $screenshot_comparison as $width => $result ) {
            if ( 'success' !== ( $result['status'] ?? '' ) || ! isset( $result['similarity'] ) ) {
                $failed++; $viewport_issues[] = array( 'viewport' => (int) $width, 'category' => 'render', 'severity' => 'high', 'message' => (string) ( $result['error'] ?? 'Visual capture failed.' ) ); continue;
            }
            $similarity = (float) $result['similarity']; $scores[] = $similarity;
            if ( $similarity < $target_similarity ) {
                $viewport_issues[] = array( 'viewport' => (int) $width, 'category' => 'visual', 'severity' => $similarity < 0.80 ? 'high' : 'medium', 'similarity' => $similarity, 'difference_ratio' => $result['difference_ratio'] ?? null, 'message' => 'Screenshot differs from the reference at this viewport.' );
            }
        }
        $similarity = $scores ? array_sum( $scores ) / count( $scores ) : 0.0;
        $issues = array_merge( $viewport_issues, $this->geometry_issues( $geometry_diffs ) );
        $plan = $this->build_correction_plan( $issues );
        return array(
            'version' => self::VERSION,
            'status' => 0 === $failed && $similarity >= $target_similarity && empty( $plan ) ? 'pass' : 'needs-correction',
            'similarity' => $similarity,
            'target_similarity' => (float) $target_similarity,
            'failed_viewports' => $failed,
            'screenshot_comparison' => $screenshot_comparison,
            'geometry_diffs' => $geometry_diffs,
            'issues' => $issues,
            'correction_plan' => $plan,
        );
    }

    public function compare_analysis( array $reference, array $candidate ) {
        $ref = $this->index_analysis( $reference ); $cand = $this->index_analysis( $candidate ); $diffs = array();
        foreach ( $ref as $viewport => $paths ) {
            $candidate_paths = (array) ( $cand[ $viewport ] ?? array() );
            foreach ( $paths as $path => $left ) {
                $match = $this->match_element( $left, $path, $candidate_paths );
                if ( ! $match ) { continue; }
                $matched_path = $match['path']; $right = $match['element']; $rect_diff = array();
                foreach ( array( 'x', 'y', 'width', 'height' ) as $field ) {
                    $a = (float) ( $left['rect'][ $field ] ?? 0 ); $b = (float) ( $right['rect'][ $field ] ?? 0 );
                    if ( abs( $a - $b ) >= 1.0 ) { $rect_diff[ $field ] = array( 'reference' => $a, 'candidate' => $b, 'delta' => round( $b - $a, 2 ) ); }
                }
                $style_diff = array();
                foreach ( $this->comparable_style_fields() as $field ) {
                    $a = trim( (string) ( $left['styles'][ $field ] ?? '' ) ); $b = trim( (string) ( $right['styles'][ $field ] ?? '' ) );
                    if ( '' !== $a && $a !== $b ) { $style_diff[ $field ] = array( 'reference' => $a, 'candidate' => $b ); }
                }
                if ( $rect_diff || $style_diff ) {
                    $diffs[ $viewport ][ $path ] = array(
                        'matched_path' => $matched_path,
                        'match_method' => $match['method'],
                        'rect' => $rect_diff,
                        'styles' => $style_diff,
                        'reference_meta' => $this->meta( $left ),
                        'candidate_meta' => $this->meta( $right ),
                    );
                }
            }
        }
        return $diffs;
    }

    public function build_correction_plan( array $issues ) {
        $plan = array();
        foreach ( $issues as $issue ) {
            $category = sanitize_key( (string) ( $issue['category'] ?? '' ) );
            $directive = array(
                'viewport' => (int) ( $issue['viewport'] ?? 0 ),
                'device' => $this->device_from_viewport( (int) ( $issue['viewport'] ?? 0 ) ),
                'path' => (string) ( $issue['path'] ?? '' ),
                'elementor_id' => sanitize_key( (string) ( $issue['elementor_id'] ?? '' ) ),
                'widget_type' => sanitize_key( (string) ( $issue['widget_type'] ?? '' ) ),
                'priority' => (string) ( $issue['severity'] ?? 'medium' ),
                'category' => $category,
                'prefer' => 'elementor-control',
                'changes' => (array) ( $issue['changes'] ?? array() ),
            );
            if ( 'geometry' === $category ) { $directive['controls'] = array( 'width', 'max_width', 'min_height', 'gap', 'padding', 'margin', 'justify_content', 'align_items' ); $directive['instruction'] = 'Correct container geometry and spacing using live responsive layout controls before CSS fallback.'; }
            elseif ( 'typography' === $category ) { $directive['controls'] = array( 'typography_font_size', 'typography_line_height', 'typography_letter_spacing', 'typography_font_weight', 'align' ); $directive['instruction'] = 'Match text metrics and alignment using runtime-verified widget typography controls.'; }
            elseif ( 'media' === $category ) { $directive['controls'] = array( 'object_fit', 'object_position', 'background_size', 'background_position' ); $directive['instruction'] = 'Correct media crop/position using runtime-verified image/background controls.'; }
            elseif ( 'surface' === $category ) { $directive['controls'] = array( 'border_radius' ); $directive['instruction'] = 'Correct simple surface radius using a live Elementor control.'; }
            elseif ( 'render' === $category ) { $directive['instruction'] = 'Resolve render/runtime failure before attempting visual correction.'; }
            else { $directive['controls'] = array(); $directive['instruction'] = 'Screenshot mismatch has no safe automatic control mapping yet.'; }
            if ( isset( $issue['details'] ) ) { $directive['details'] = $issue['details']; }
            $directive['auto_applicable'] = ! empty( $directive['elementor_id'] ) && ! empty( $directive['changes'] ) && in_array( $category, array( 'geometry', 'typography', 'media', 'surface' ), true );
            $plan[] = $directive;
        }
        return $plan;
    }

    private function validate_capture_target( $target ) {
        $target = trim( (string) $target );
        if ( preg_match( '#^https?://#i', $target ) ) {
            $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $target ) : parse_url( $target );
            $host = strtolower( (string) ( $parts['host'] ?? '' ) ); $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
            $port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
            $home_host = ''; $home_scheme = ''; $home_port = 0;
            if ( function_exists( 'home_url' ) ) {
                $home = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url( '/' ) ) : parse_url( home_url( '/' ) );
                $home_host = strtolower( (string) ( $home['host'] ?? '' ) ); $home_scheme = strtolower( (string) ( $home['scheme'] ?? '' ) );
                $home_port = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === $home_scheme ? 443 : 80 );
            }
            if ( $host && $home_host && $host === $home_host && $scheme === $home_scheme && $port === $home_port ) { return $target; }
            if ( class_exists( 'Design_Core_Elementor_Security_Policy' ) ) {
                $validated = ( new Design_Core_Elementor_Security_Policy() )->validate_remote_url( $target ); return is_wp_error( $validated ) ? $validated : $validated;
            }
            return $target;
        }
        $path = 0 === strpos( $target, 'file://' ) ? substr( $target, 7 ) : $target; $real = realpath( $path );
        if ( ! $real || ! is_readable( $real ) ) { return new WP_Error( 'design_core_visual_target_invalid', 'Visual target must be a readable local file or an allowed HTTP(S) URL.' ); }
        $roots = array_filter( array( defined( 'ABSPATH' ) ? realpath( ABSPATH ) : '', defined( 'DESIGN_CORE_ELEMENTOR_PATH' ) ? realpath( DESIGN_CORE_ELEMENTOR_PATH ) : '', realpath( sys_get_temp_dir() ) ) );
        if ( function_exists( 'wp_upload_dir' ) ) { $upload = wp_upload_dir(); if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) { $roots[] = realpath( $upload['basedir'] ); } }
        foreach ( array_filter( array_unique( $roots ) ) as $root ) {
            $root = rtrim( (string) $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
            if ( 0 === strpos( $real . ( is_dir( $real ) ? DIRECTORY_SEPARATOR : '' ), $root ) || rtrim( $root, DIRECTORY_SEPARATOR ) === $real ) { return $real; }
        }
        return new WP_Error( 'design_core_visual_local_target_forbidden', 'Local visual target is outside the allowed WordPress/temp directories.' );
    }

    private function geometry_issues( array $diffs ) {
        $issues = array();
        $geometry_fields = array( 'display', 'flexDirection', 'justifyContent', 'alignItems', 'gap', 'rowGap', 'columnGap', 'width', 'minWidth', 'maxWidth', 'height', 'minHeight', 'maxHeight', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'marginTop', 'marginRight', 'marginBottom', 'marginLeft' );
        $typography_fields = array( 'fontSize', 'lineHeight', 'letterSpacing', 'fontWeight', 'textAlign' );
        $media_fields = array( 'objectFit', 'objectPosition', 'backgroundSize', 'backgroundPosition' );
        foreach ( $diffs as $viewport => $paths ) {
            foreach ( $paths as $path => $diff ) {
                $rect = (array) ( $diff['rect'] ?? array() ); $styles = (array) ( $diff['styles'] ?? array() ); $meta = (array) ( $diff['candidate_meta'] ?? array() );
                $base = array( 'viewport' => (int) $viewport, 'path' => $path, 'elementor_id' => $meta['elementor_id'] ?? '', 'widget_type' => $meta['widget_type'] ?? '' );
                $geometry_styles = array_intersect_key( $styles, array_flip( $geometry_fields ) );
                if ( $rect || $geometry_styles ) {
                    $max = 0.0; foreach ( $rect as $value ) { $max = max( $max, abs( (float) ( $value['delta'] ?? 0 ) ) ); }
                    $issues[] = array_merge( $base, array( 'category' => 'geometry', 'severity' => $max >= 48 ? 'high' : ( $max >= 12 ? 'medium' : 'low' ), 'details' => array( 'rect' => $rect, 'styles' => $geometry_styles ), 'changes' => $this->changes_from_styles( $geometry_styles ) ) );
                }
                $typography = array_intersect_key( $styles, array_flip( $typography_fields ) );
                if ( $typography ) { $issues[] = array_merge( $base, array( 'category' => 'typography', 'severity' => 'medium', 'details' => $typography, 'changes' => $this->changes_from_styles( $typography ) ) ); }
                $media = array_intersect_key( $styles, array_flip( $media_fields ) );
                if ( $media ) { $issues[] = array_merge( $base, array( 'category' => 'media', 'severity' => 'medium', 'details' => $media, 'changes' => $this->changes_from_styles( $media ) ) ); }
                if ( isset( $styles['borderRadius'] ) ) { $issues[] = array_merge( $base, array( 'category' => 'surface', 'severity' => 'low', 'details' => array( 'borderRadius' => $styles['borderRadius'] ), 'changes' => $this->changes_from_styles( array( 'borderRadius' => $styles['borderRadius'] ) ) ) ); }
            }
        }
        return $issues;
    }

    private function changes_from_styles( array $styles ) {
        $map = array(
            'width' => 'width', 'maxWidth' => 'max_width', 'minHeight' => 'min_height', 'height' => 'height', 'gap' => 'gap', 'rowGap' => 'row_gap', 'columnGap' => 'column_gap',
            'paddingTop' => 'padding_top', 'paddingRight' => 'padding_right', 'paddingBottom' => 'padding_bottom', 'paddingLeft' => 'padding_left',
            'marginTop' => 'margin_top', 'marginRight' => 'margin_right', 'marginBottom' => 'margin_bottom', 'marginLeft' => 'margin_left',
            'justifyContent' => 'justify_content', 'alignItems' => 'align_items', 'fontSize' => 'font_size', 'lineHeight' => 'line_height', 'letterSpacing' => 'letter_spacing', 'fontWeight' => 'font_weight', 'textAlign' => 'align',
            'objectFit' => 'object_fit', 'objectPosition' => 'object_position', 'backgroundSize' => 'background_size', 'backgroundPosition' => 'background_position', 'borderRadius' => 'border_radius',
        );
        $changes = array();
        foreach ( $styles as $field => $values ) {
            if ( ! isset( $map[ $field ] ) || ! is_array( $values ) || ! array_key_exists( 'reference', $values ) ) { continue; }
            $changes[] = array( 'property' => $map[ $field ], 'css_property' => $field, 'reference' => (string) $values['reference'], 'candidate' => (string) ( $values['candidate'] ?? '' ) );
        }
        return $changes;
    }

    private function comparable_style_fields() {
        return array(
            'display','flexDirection','justifyContent','alignItems','gap','rowGap','columnGap','width','minWidth','maxWidth','height','minHeight','maxHeight',
            'paddingTop','paddingRight','paddingBottom','paddingLeft','marginTop','marginRight','marginBottom','marginLeft',
            'fontSize','lineHeight','letterSpacing','fontWeight','textAlign','objectFit','objectPosition','backgroundSize','backgroundPosition','borderRadius'
        );
    }

    private function index_analysis( array $analysis ) {
        $source = is_array( $analysis['data']['viewports'] ?? null ) ? $analysis['data']['viewports'] : (array) ( $analysis['viewports'] ?? array() ); $out = array();
        foreach ( $source as $viewport => $elements ) {
            if ( ! is_array( $elements ) ) { continue; }
            foreach ( $elements as $element ) {
                $path = (string) ( $element['domPath'] ?? $element['dom_path'] ?? '' ); if ( ! $path ) { continue; }
                $out[ (int) $viewport ][ $path ] = array(
                    'rect' => (array) ( $element['rect'] ?? array() ), 'styles' => (array) ( $element['styles'] ?? array() ),
                    'tag' => strtolower( (string) ( $element['tag'] ?? '' ) ), 'id' => (string) ( $element['id'] ?? '' ),
                    'text' => $this->normalize_text( $element['ownText'] ?? $element['text'] ?? '' ), 'index' => (int) ( $element['index'] ?? 0 ),
                    'elementor_id' => sanitize_key( (string) ( $element['elementorId'] ?? $element['elementor_id'] ?? '' ) ),
                    'elementor_type' => sanitize_key( (string) ( $element['elementorType'] ?? $element['elementor_type'] ?? '' ) ),
                    'widget_type' => sanitize_key( (string) ( $element['widgetType'] ?? $element['widget_type'] ?? '' ) ),
                );
            }
        }
        return $out;
    }

    private function match_element( array $left, $path, array $candidate_paths ) {
        if ( isset( $candidate_paths[ $path ] ) ) { return array( 'path' => $path, 'element' => $candidate_paths[ $path ], 'method' => 'dom-path' ); }
        if ( ! empty( $left['id'] ) ) {
            foreach ( $candidate_paths as $candidate_path => $candidate ) { if ( (string) ( $candidate['id'] ?? '' ) === (string) $left['id'] ) { return array( 'path' => $candidate_path, 'element' => $candidate, 'method' => 'html-id' ); } }
        }
        $text = (string) ( $left['text'] ?? '' );
        if ( strlen( $text ) >= 3 ) {
            $fallback = null;
            foreach ( $candidate_paths as $candidate_path => $candidate ) {
                if ( (string) ( $candidate['text'] ?? '' ) !== $text ) { continue; }
                if ( (string) ( $candidate['tag'] ?? '' ) === (string) ( $left['tag'] ?? '' ) ) { return array( 'path' => $candidate_path, 'element' => $candidate, 'method' => 'text-tag' ); }
                if ( null === $fallback ) { $fallback = array( 'path' => $candidate_path, 'element' => $candidate, 'method' => 'text' ); }
            }
            if ( $fallback ) { return $fallback; }
        }
        foreach ( $candidate_paths as $candidate_path => $candidate ) {
            if ( (int) ( $candidate['index'] ?? -1 ) === (int) ( $left['index'] ?? -2 ) && (string) ( $candidate['tag'] ?? '' ) === (string) ( $left['tag'] ?? '' ) ) {
                return array( 'path' => $candidate_path, 'element' => $candidate, 'method' => 'tag-index' );
            }
        }
        return null;
    }

    private function meta( array $element ) {
        return array( 'tag' => $element['tag'] ?? '', 'id' => $element['id'] ?? '', 'elementor_id' => $element['elementor_id'] ?? '', 'elementor_type' => $element['elementor_type'] ?? '', 'widget_type' => $element['widget_type'] ?? '' );
    }

    private function normalize_text( $value ) { return trim( preg_replace( '/\s+/u', ' ', (string) $value ) ); }

    private function device_from_viewport( $viewport ) {
        $viewport = (int) $viewport;
        if ( $viewport <= 480 ) { return 'mobile'; }
        if ( $viewport <= 900 ) { return 'tablet'; }
        if ( $viewport <= 1200 ) { return 'laptop'; }
        return 'desktop';
    }
}
