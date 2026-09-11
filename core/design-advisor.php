<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * High-level Design Intelligence service. It recommends design direction, never writes a page.
 * The output is platform-neutral and can be attached to Design IR before the normal planner runs.
 */
class Design_Core_Elementor_Design_Advisor {
    const MAX_BRIEF_BYTES = 16384;

    private $catalog;

    public function __construct( $catalog = null ) {
        $this->catalog = $catalog ?: new Design_Core_Elementor_Design_Intelligence_Catalog();
    }

    public function recommend( $brief, array $options = array() ) {
        $brief = trim( (string) $brief );
        if ( '' === $brief ) { return new WP_Error( 'design_core_design_brief_required', 'A design brief is required.' ); }
        if ( strlen( $brief ) > self::MAX_BRIEF_BYTES ) { return new WP_Error( 'design_core_design_brief_too_large', 'The design brief exceeds the 16 KB Design Intelligence limit.' ); }

        $match = $this->catalog->match_profile( $brief, (string) ( $options['product_type'] ?? '' ) );
        if ( is_wp_error( $match ) ) { return $match; }
        $catalog_data = $this->catalog->load();
        if ( is_wp_error( $catalog_data ) ) { return $catalog_data; }

        $rule_limit = max( 1, min( 50, (int) ( $options['max_rules'] ?? 18 ) ) );
        $rules = $this->catalog->ux_rules( array(), $rule_limit );
        $options['ux_rules'] = $rules;
        $profile = Design_Core_Elementor_Design_System_Profile::from_match( $match, $brief, $options, (array) ( $catalog_data['source'] ?? array() ) );
        try { Design_Core_Elementor_Design_System_Profile::validate( $profile ); }
        catch ( Throwable $exception ) { return new WP_Error( 'design_core_design_profile_invalid', $exception->getMessage() ); }

        $warnings = array();
        if ( 'low' === ( $profile['product']['confidence'] ?? '' ) ) {
            $warnings[] = 'The product-category match is low confidence; provide product_type explicitly before using this recommendation for a real build.';
        }
        if ( 'approximate' === ( $profile['page_strategy']['shell_fit'] ?? '' ) ) {
            $warnings[] = 'The existing Design Core Page Shell is only an approximation for this product type; preview the section plan before approval.';
        }
        if ( empty( $profile['page_strategy']['recommended_shell'] ) ) {
            $warnings[] = 'No existing Design Core Page Shell is suitable enough for automatic compilation; use this profile as guidance until a matching shell/recipe family exists.';
        }

        return array(
            'status' => 'success',
            'profile' => $profile,
            'catalog' => $this->catalog->status(),
            'warnings' => $warnings,
            'mutation' => false,
        );
    }

    /** Attach the approved/reviewed profile to IR without changing business content. */
    public function enrich_ir( array $ir, array $profile ) {
        try {
            Design_Core_Elementor_Design_System_Profile::validate( $profile );
            ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
        } catch ( Throwable $exception ) {
            return new WP_Error( 'design_core_design_ir_enrichment_invalid', $exception->getMessage() );
        }

        $token_service = new Design_Core_Elementor_Design_Token_Service();
        $profile_tokens = $token_service->normalize( (array) ( $profile['tokens'] ?? array() ) );
        $existing_tokens = $token_service->normalize( (array) ( $ir['tokens'] ?? array() ) );
        $ir['tokens'] = array(
            'schema_version' => Design_Core_Elementor_Design_Token_Service::SCHEMA_VERSION,
            'colors' => array_replace( (array) ( $profile_tokens['colors'] ?? array() ), (array) ( $existing_tokens['colors'] ?? array() ) ),
            'typography' => array_replace_recursive( (array) ( $profile_tokens['typography'] ?? array() ), (array) ( $existing_tokens['typography'] ?? array() ) ),
            'spacing' => array_replace( (array) ( $profile_tokens['spacing'] ?? array() ), (array) ( $existing_tokens['spacing'] ?? array() ) ),
            'radius' => array_replace( (array) ( $profile_tokens['radius'] ?? array() ), (array) ( $existing_tokens['radius'] ?? array() ) ),
        );
        if ( ! isset( $ir['diagnostics'] ) || ! is_array( $ir['diagnostics'] ) ) { $ir['diagnostics'] = array(); }
        $ir['diagnostics']['design_intelligence'] = array(
            'schema_version' => Design_Core_Elementor_Design_System_Profile::SCHEMA_VERSION,
            'provider' => 'ui-ux-pro-max-normalized',
            'product_id' => sanitize_key( (string) ( $profile['product']['id'] ?? '' ) ),
            'product_category' => sanitize_text_field( (string) ( $profile['product']['category'] ?? '' ) ),
            'pattern_id' => sanitize_key( (string) ( $profile['page_strategy']['pattern_id'] ?? '' ) ),
            'recommended_shell' => sanitize_key( (string) ( $profile['page_strategy']['recommended_shell'] ?? '' ) ),
            'ux_rule_ids' => array_values( array_filter( array_map( static function ( $rule ) { return sanitize_key( (string) ( $rule['id'] ?? '' ) ); }, (array) ( $profile['ux']['rules'] ?? array() ) ) ) ),
        );
        try { ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir ); }
        catch ( Throwable $exception ) { return new WP_Error( 'design_core_design_ir_enrichment_failed', $exception->getMessage() ); }
        return $ir;
    }

    public function recommend_and_enrich( array $ir, $brief, array $options = array() ) {
        $recommendation = $this->recommend( $brief, $options );
        if ( is_wp_error( $recommendation ) ) { return $recommendation; }
        $enriched = $this->enrich_ir( $ir, $recommendation['profile'] );
        if ( is_wp_error( $enriched ) ) { return $enriched; }
        return array( 'recommendation' => $recommendation, 'design_ir' => $enriched );
    }
}
