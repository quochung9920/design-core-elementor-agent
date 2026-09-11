<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Adds an explicit UX/accessibility quality dimension without pretending that
 * structure-only checks can prove rendered contrast, focus, touch size or motion behavior.
 */
class Design_Core_Elementor_UX_Quality_Auditor {
    private $catalog;

    public function __construct( $catalog = null ) { $this->catalog = $catalog ?: new Design_Core_Elementor_Design_Intelligence_Catalog(); }

    public function audit_page( $page_id ) {
        $page_id = (int) $page_id;
        $raw = get_post_meta( $page_id, '_elementor_data', true );
        if ( ! $raw ) { return array( 'status'=>'unavailable', 'score'=>0, 'issues'=>array(), 'verified_checks'=>array(), 'runtime_checks_required'=>array(), 'reason'=>'no-elementor-data' ); }
        $elements = json_decode( $raw, true );
        if ( ! is_array( $elements ) ) { return array( 'status'=>'unavailable', 'score'=>0, 'issues'=>array(), 'verified_checks'=>array(), 'runtime_checks_required'=>array(), 'reason'=>'invalid-elementor-data' ); }

        $flat = $this->flatten( $elements );
        $issues = array(); $verified = array();
        $heading_levels = array();

        foreach ( $flat as $element ) {
            $settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
            $widget = sanitize_key( (string) ( $element['widgetType'] ?? '' ) );
            $id = sanitize_key( (string) ( $element['id'] ?? '' ) );

            if ( 'image' === $widget ) {
                $verified['elementor-image-alt'] = true;
                $alt = '';
                if ( isset( $settings['image']['alt'] ) ) { $alt = trim( (string) $settings['image']['alt'] ); }
                elseif ( isset( $settings['alt'] ) ) { $alt = trim( (string) $settings['alt'] ); }
                if ( '' === $alt ) {
                    $issues[] = array( 'rule_id'=>'uuxpm-image-alt', 'severity'=>'high', 'element_id'=>$id, 'message'=>'Image widget has no explicit alternative text evidence.' );
                }
            }

            if ( 'button' === $widget ) {
                $verified['elementor-control-name'] = true;
                $label = trim( (string) ( $settings['text'] ?? ( $settings['button_text'] ?? '' ) ) );
                if ( '' === $label ) {
                    $issues[] = array( 'rule_id'=>'uuxpm-control-name', 'severity'=>'high', 'element_id'=>$id, 'message'=>'Button widget has no visible label evidence.' );
                }
            }

            if ( 'heading' === $widget ) {
                $tag = strtolower( (string) ( $settings['header_size'] ?? 'h2' ) );
                if ( preg_match( '/^h([1-6])$/', $tag, $matches ) ) { $heading_levels[] = array( 'level'=>(int)$matches[1], 'element_id'=>$id ); }
            }
        }

        if ( $heading_levels ) {
            $verified['elementor-heading-order'] = true;
            $previous = null;
            foreach ( $heading_levels as $heading ) {
                if ( null !== $previous && $heading['level'] > $previous + 1 ) {
                    $issues[] = array( 'rule_id'=>'uuxpm-heading-hierarchy', 'severity'=>'medium', 'element_id'=>$heading['element_id'], 'message'=>'Heading hierarchy jumps from H' . $previous . ' to H' . $heading['level'] . '.' );
                }
                $previous = $heading['level'];
            }
        }

        $runtime_required = array();
        foreach ( $this->catalog->ux_rules( array(), 100 ) as $rule ) {
            $check = sanitize_key( (string) ( $rule['check'] ?? '' ) );
            if ( 0 === strpos( $check, 'browser-' ) || 'interaction' === $check ) {
                $runtime_required[] = array(
                    'rule_id' => sanitize_key( (string) ( $rule['id'] ?? '' ) ),
                    'check' => $check,
                    'severity' => sanitize_key( (string) ( $rule['severity'] ?? '' ) ),
                    'guidance' => sanitize_text_field( (string) ( $rule['guidance'] ?? '' ) ),
                );
            }
        }

        $penalty = 0;
        foreach ( $issues as $issue ) {
            $severity = $issue['severity'] ?? 'medium';
            $penalty += 'high' === $severity ? 10 : ( 'critical' === $severity ? 20 : ( 'low' === $severity ? 2 : 5 ) );
        }
        $score = max( 0, 100 - min( 100, $penalty ) );
        return array(
            'status' => $issues ? 'warning' : 'pass',
            'score' => (float) $score,
            'issues' => $issues,
            'verified_checks' => array_values( array_keys( $verified ) ),
            'runtime_checks_required' => $runtime_required,
            'knowledge_source' => $this->catalog->status(),
            'note' => 'Rendered contrast, focus visibility, touch target size, overflow and reduced-motion behavior remain runtime/browser checks; this structural audit does not fabricate them as passed.',
        );
    }

    private function flatten( array $elements ) {
        $out = array();
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) { continue; }
            $out[] = $element;
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) { $out = array_merge( $out, $this->flatten( $element['elements'] ) ); }
        }
        return $out;
    }
}
