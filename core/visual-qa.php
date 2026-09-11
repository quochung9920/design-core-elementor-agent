<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Visual_QA {
    public function audit_page( $page_id ) {
        $raw = get_post_meta( $page_id, '_elementor_data', true );
        if ( ! $raw ) { return $this->fail( $page_id, 'No Elementor data found.' ); }
        $elements = json_decode( $raw, true );
        if ( ! is_array( $elements ) ) { return $this->fail( $page_id, 'Invalid Elementor JSON.' ); }

        $issues = array(); $recommendations = array(); $penalty = 0;
        $flat = $this->flatten( $elements );
        $architecture = class_exists( 'Design_Core_Elementor_Architecture_Auditor' ) ? ( new Design_Core_Elementor_Architecture_Auditor() )->audit( $elements ) : array();
        if ( ! empty( $architecture['stylesheet_html_widget_count'] ) ) {
            $issues[] = array( 'type'=>'critical', 'message'=>$architecture['stylesheet_html_widget_count'] . ' HTML widget(s) contain stylesheets that control Elementor elements outside their ownership.' );
            $penalty += 35;
        }
        if ( ! empty( $architecture['class_only_container_count'] ) ) {
            $issues[] = array( 'type'=>'warning', 'message'=>$architecture['class_only_container_count'] . ' class-only container(s) have no native Elementor style controls.' );
            $penalty += min( 20, $architecture['class_only_container_count'] );
        }
        if ( ! empty( $architecture['important_declaration_count'] ) ) {
            $issues[] = array( 'type'=>'warning', 'message'=>$architecture['important_declaration_count'] . ' !important declaration(s) reduce editor control ownership.' );
            $penalty += min( 10, $architecture['important_declaration_count'] );
        }
        $ids = array_filter( array_column( $flat, 'id' ) );
        if ( count( $ids ) !== count( array_unique( $ids ) ) ) { $issues[] = array( 'type'=>'critical','message'=>'Duplicate Elementor element IDs detected.' ); $penalty += 40; }

        $thresholds = class_exists( 'Design_Core_Elementor_Settings' ) ? Design_Core_Elementor_Settings::get_qa_thresholds() : array();
        $colors = array(); $fonts = array(); $missing_alt = 0; $responsive = 0; $global_refs = 0; $raw_html_widgets = 0;
        foreach ( $flat as $element ) {
            $settings = $element['settings'] ?? array();
            if ( 'html' === ( $element['widgetType'] ?? '' ) ) { $raw_html_widgets++; }
            if ( ! empty( $settings['__globals__'] ) && is_array( $settings['__globals__'] ) ) { $global_refs += count( $settings['__globals__'] ); }
            foreach ( $settings as $key => $value ) {
                if ( false !== strpos( $key, 'color' ) && is_string( $value ) && $value ) { $colors[] = $value; }
                if ( false !== strpos( $key, 'font_family' ) && is_string( $value ) && $value ) { $fonts[] = $value; }
                if ( preg_match( '/_(tablet|mobile|mobile_extra|tablet_extra|laptop)$/', $key ) ) { $responsive++; }
            }
            if ( 'image' === ( $element['widgetType'] ?? '' ) && empty( $settings['image']['alt'] ) && empty( $settings['alt'] ) ) { $missing_alt++; }
        }

        $color_limit = max( 1, (int) ( $thresholds['color_limit'] ?? 8 ) );
        $font_limit = max( 1, (int) ( $thresholds['font_limit'] ?? 3 ) );
        if ( count( array_unique( $colors ) ) > $color_limit ) { $issues[] = array( 'type'=>'warning','message'=>'Color count exceeds configured design-system limit.' ); $penalty += 8; }
        if ( count( array_unique( $fonts ) ) > $font_limit ) { $issues[] = array( 'type'=>'warning','message'=>'Font count exceeds configured design-system limit.' ); $penalty += 5; }
        if ( $missing_alt ) { $issues[] = array( 'type'=>'warning','message'=>$missing_alt . ' image(s) may be missing alt text.' ); $penalty += min( 10, $missing_alt * 2 ); }
        if ( $raw_html_widgets ) { $issues[] = array( 'type'=>'warning','message'=>$raw_html_widgets . ' raw HTML widget(s) require architecture justification.' ); $penalty += min( 20, $raw_html_widgets * 5 ); }
        if ( 0 === $responsive ) { $recommendations[] = 'No responsive overrides were detected; verify browser snapshots before production publish.'; }
        if ( 0 === $global_refs ) { $recommendations[] = 'No V3 global references were detected; verify global token synchronization or V4 class usage.'; }

        $widget_count = count( array_filter( $flat, static function ( $e ) { return 'widget' === ( $e['elType'] ?? '' ); } ) );
        $depth = $this->max_depth( $elements );
        if ( $depth > 8 ) { $issues[] = array( 'type'=>'warning','message'=>'Deep nesting detected (' . $depth . ').' ); $penalty += 5; }
        if ( $widget_count > 75 ) { $recommendations[] = 'High widget count; review reusable components and Loop opportunities.'; }

        $score = (float) max( 0, 100 - $penalty );
        $min_score = (float) ( $thresholds['min_score'] ?? 85 );
        $critical = count( array_filter( $issues, static function ( $issue ) { return 'critical' === ( $issue['type'] ?? '' ); } ) );
        $status = $critical || $score < $min_score ? 'fail' : ( $issues ? 'warning' : 'pass' );
        $ux_quality = class_exists( 'Design_Core_Elementor_UX_Quality_Auditor' ) ? ( new Design_Core_Elementor_UX_Quality_Auditor() )->audit_page( (int) $page_id ) : array( 'status'=>'unavailable' );
        return array(
            'page_id'=>(int)$page_id, 'status'=>$status, 'score'=>$score, 'issues'=>$issues, 'recommendations'=>$recommendations,
            'performance'=>array( 'widget_count'=>$widget_count, 'nesting_depth'=>$depth ),
            'responsive_override_count'=>$responsive, 'global_reference_count'=>$global_refs, 'raw_html_widget_count'=>$raw_html_widgets,
            'architecture'=>$architecture,
            'ux_quality'=>$ux_quality,
        );
    }

    public function compare_targets( $reference_target, $candidate_target ) { return new WP_Error( 'design_core_screenshot_removed', 'Screenshot comparison was removed from the local-only core build.' ); }

    private function fail( $page_id, $message ) { return array( 'page_id'=>(int)$page_id, 'status'=>'fail', 'score'=>0.0, 'issues'=>array( array( 'type'=>'critical','message'=>$message ) ), 'recommendations'=>array(), 'performance'=>array() ); }
    private function flatten( $elements ) { $out = array(); foreach ( $elements as $element ) { $out[] = $element; if ( ! empty( $element['elements'] ) ) { $out = array_merge( $out, $this->flatten( $element['elements'] ) ); } } return $out; }
    private function max_depth( $elements, $depth = 0 ) { $max = $depth; foreach ( $elements as $element ) { if ( ! empty( $element['elements'] ) ) { $max = max( $max, $this->max_depth( $element['elements'], $depth + 1 ) ); } } return $max; }
}
