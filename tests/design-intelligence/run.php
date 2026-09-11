<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); } }

dc_require( array(
    'core/change-ledger.php',
    'core/design-intelligence-catalog.php',
    'core/design-system-profile.php',
    'core/design-advisor.php',
) );

$catalog = new Design_Core_Elementor_Design_Intelligence_Catalog();
$status = $catalog->status();
dc_assert( ! empty( $status['available'] ), 'starter Design Intelligence catalog is available' );
dc_assert( (int) $status['profiles'] >= 7, 'starter catalog contains multiple product profiles' );
dc_assert( (int) $status['ux_rules'] >= 10, 'starter catalog contains UX quality rules' );
dc_assert( 'UI UX Pro Max' === ( $status['source']['name'] ?? '' ), 'catalog provenance identifies UI UX Pro Max' );

$advisor = new Design_Core_Elementor_Design_Advisor( $catalog );
$b2b = $advisor->recommend( 'International engineering construction manpower company for enterprise clients. Premium, trustworthy and focused on workforce capabilities.' );
dc_assert( ! is_wp_error( $b2b ), 'B2B engineering brief returns a recommendation' );
dc_assert( 'b2b-service' === ( $b2b['profile']['product']['id'] ?? '' ), 'engineering/construction/manpower maps to B2B Service profile' );
dc_assert( 'design-system-profile' === ( $b2b['profile']['type'] ?? '' ), 'recommendation uses canonical Design System Profile contract' );
dc_assert( false === ( $b2b['mutation'] ?? true ), 'recommendation is explicitly mutation-free' );
dc_assert( 'service-landing' === ( $b2b['profile']['page_strategy']['recommended_shell'] ?? '' ), 'B2B profile maps to the existing service landing shell' );
dc_assert( '#0F172A' === ( $b2b['profile']['tokens']['colors']['primary'] ?? '' ), 'semantic B2B primary color is available' );
dc_assert( 'Poppins' === ( $b2b['profile']['tokens']['typography']['heading']['font_family'] ?? '' ), 'B2B typography recommendation is normalized' );
dc_assert( ! empty( $b2b['profile']['ux']['rules'] ), 'UX rules are attached to the profile' );

$explicit = $advisor->recommend( 'A page with unclear generic wording.', array( 'product_type' => 'Beauty/Spa/Wellness Service' ) );
dc_assert( ! is_wp_error( $explicit ), 'explicit product type can resolve an otherwise generic brief' );
dc_assert( 'beauty-spa-wellness' === ( $explicit['profile']['product']['id'] ?? '' ), 'explicit product type wins lexical ambiguity' );

$dark = $advisor->recommend( 'SaaS analytics platform', array( 'product_type'=>'SaaS (General)', 'mode'=>'dark', 'density'=>9, 'variance'=>8, 'motion'=>2 ) );
dc_assert( '#0F172A' === ( $dark['profile']['tokens']['colors']['background'] ?? '' ), 'dark mode derives a dark background without runtime network calls' );
dc_assert( 16 === (int) ( $dark['profile']['tokens']['spacing']['xl'] ?? 0 ), 'high density uses compact spacing tokens' );
dc_assert( 8 === (int) ( $dark['profile']['direction']['variance'] ?? 0 ), 'variance dial is preserved in profile direction' );
dc_assert( 2 === (int) ( $dark['profile']['direction']['motion'] ?? 0 ), 'motion dial is preserved in profile direction' );

$empty = $advisor->recommend( '' );
dc_assert( is_wp_error( $empty ) && 'design_core_design_brief_required' === $empty->get_error_code(), 'empty briefs fail closed' );

$too_large = $advisor->recommend( str_repeat( 'x', Design_Core_Elementor_Design_Advisor::MAX_BRIEF_BYTES + 1 ) );
dc_assert( is_wp_error( $too_large ) && 'design_core_design_brief_too_large' === $too_large->get_error_code(), 'oversized briefs are rejected' );

dc_finish( 'Design Intelligence' );
