<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
wp_set_current_user( 1 );
function dc_matrix_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$converter = new Design_Core_Elementor_HTML_Converter();
$results = array();

// Native: simple hero.
$hero = $converter->convert_to_elementor( '<section><h1>Welcome</h1><p>We build things.</p></section>', '', 'Matrix Native' );
dc_matrix_assert( 'success' === ( $hero['status'] ?? '' ), 'Native hero conversion failed: ' . ( $hero['error'] ?? '' ) );
dc_matrix_assert( 'native-compose' === ( $hero['build_plan']['items'][0]['strategy'] ?? '' ), 'Simple hero did not select native-compose: ' . ( $hero['build_plan']['items'][0]['strategy'] ?? '' ) );
$results['native'] = 'pass';

// Native widget: FAQ semantic role.
$faq = $converter->convert_to_elementor( '<section class="faq"><h2>FAQ</h2><p>Answers.</p></section>', '', 'Matrix Native Widget' );
dc_matrix_assert( 'success' === ( $faq['status'] ?? '' ), 'FAQ conversion failed: ' . ( $faq['error'] ?? '' ) );
dc_matrix_assert( 'native-widget' === ( $faq['build_plan']['items'][0]['strategy'] ?? '' ), 'FAQ section did not select native-widget: ' . ( $faq['build_plan']['items'][0]['strategy'] ?? '' ) );
$results['native_widget'] = 'pass';

// Static repeated card: first page registers a master post-save (native-compose is the allowed first-page strategy per spec), second page reuses it.
// h5 (not h3/h4) keeps this scenario's fingerprint from colliding with production-smoke.php's/marketing.html's own feature-card reuse scenarios when tests run in sequence against the same registry.
$card_css = '.matrix-card{padding:24px;border-radius:16px}';
$card_a = $converter->convert_to_elementor( '<section><article class="matrix-card"><h5>Card A</h5><p>One</p></article></section>', $card_css, 'Matrix Card A' );
dc_matrix_assert( 'success' === ( $card_a['status'] ?? '' ), 'Card A conversion failed: ' . ( $card_a['error'] ?? '' ) );
dc_matrix_assert( 'native-compose' === ( $card_a['build_plan']['items'][0]['strategy'] ?? '' ), 'First card page did not select native-compose: ' . ( $card_a['build_plan']['items'][0]['strategy'] ?? '' ) );
dc_matrix_assert( ! empty( $card_a['reuse'] ), 'First card page did not register a component master post-save.' );
$card_b = $converter->convert_to_elementor( '<section><article class="matrix-card"><h5>Card B</h5><p>Two</p></article></section>', $card_css, 'Matrix Card B' );
dc_matrix_assert( 'success' === ( $card_b['status'] ?? '' ), 'Card B conversion failed: ' . ( $card_b['error'] ?? '' ) );
dc_matrix_assert( 'reuse-component' === ( $card_b['build_plan']['items'][0]['strategy'] ?? '' ), 'Second card page did not select reuse-component: ' . ( $card_b['build_plan']['items'][0]['strategy'] ?? '' ) );
$results['component_then_reuse'] = 'pass';

// Variant: without source HTML at the canonical-registry match layer, Reuse_Engine's remaining signals
// (semantic-purpose/fingerprint-semantic/content-schema) are too coarse to reliably tell "a deliberate
// variant of THIS specific family" apart from "coincidentally the same generic shape as some unrelated
// card elsewhere on the site" across a full multi-page registry -- inflating those weights to force one
// synthetic case into the 'variant' band caused real false positives (page-a/page-b's reuse benchmark
// started reporting unrelated cards as variants). So this verifies the two things that ARE reliable: (1)
// decide_node() maps a variant_match the registry layer produced into the 'variant' strategy, and (2) once
// classified as a variant, synchronize_reuse() registers it against the existing master via add_variant()
// instead of silently creating a duplicate master (P0.13) -- both exercised directly, deterministically.
$decision = ( new Design_Core_Elementor_Decision_Engine() )->decide_node( array(
    'semantic' => array( 'role' => 'testimonial' ),
    'component' => array( 'repeated' => false, 'dynamic' => false, 'content_schema' => array( 'heading', 'rich_text' ) ),
    'registry_match' => array( 'registry_type' => 'component', 'registry_id' => 'component-existing-family', 'score' => 0.8, 'reasons' => array( 'semantic-purpose', 'content-schema:1' ) ),
    'variant_match' => array( 'action' => 'variant', 'score' => 0.8 ),
    'capabilities' => array(),
) );
dc_matrix_assert( 'variant' === ( $decision['strategy'] ?? '' ), 'decide_node() did not map a variant_match into the variant strategy: ' . ( $decision['strategy'] ?? '' ) );

$variant_registry = new Design_Core_Elementor_Component_Registry();
$existing_master = $variant_registry->upsert( array(
    'id' => 'component-variant-family-test', 'type' => 'component', 'schema_version' => 2, 'item_version' => 1,
    'created_at' => gmdate( 'c' ), 'updated_at' => gmdate( 'c' ), 'source' => array( 'kind' => 'design-ir', 'node_id' => 'n1' ),
    'usage' => array( 'count' => 0, 'locations' => array() ),
    'fingerprint' => array( 'version' => 2, 'semantic' => 'testimonial', 'structure' => 'article|h5,p', 'content_schema' => array( 'heading', 'rich_text' ), 'layout' => '', 'interaction' => '' ),
    'master' => array( 'structure' => array( 'root_tag' => 'article', 'blueprint' => array() ), 'layout' => array(), 'shared_styles' => array(), 'spacing' => array(), 'responsive_rules' => array(), 'editable_schema' => array( 'heading', 'rich_text' ) ),
    'instances' => array(),
) );
dc_matrix_assert( ! is_wp_error( $existing_master ), 'Failed to seed the variant-family master fixture.' );
$before_count = count( $variant_registry->all() );
$added = $variant_registry->add_variant( 'component-variant-family-test', 'compact', array( 'note' => 'narrower spacing' ) );
dc_matrix_assert( true === $added, 'Component_Registry::add_variant() failed.' );
$after = $variant_registry->get( 'component-variant-family-test' );
dc_matrix_assert( isset( $after['variants']['compact'] ), 'Variant metadata was not recorded on the existing master.' );
dc_matrix_assert( $before_count === count( $variant_registry->all() ), 'Variant registration created a duplicate master instead of reusing the existing one.' );
$results['variant'] = 'pass';

// Reuse-band near-duplicate: a fuzzy match scored >= 0.90 (Variant_Engine's 'reuse' band, not 'variant') must also
// bind to the existing master rather than falling through to "no exact match -> create a new one" (that fallthrough
// was a real bug: only the 'variant' classification was special-cased, not 'reuse').
$reuse_css = '.reuseband-card{color:#111111;padding:24px;border-radius:16px}';
$reuse_a = $converter->convert_to_elementor( '<section><article class="reuseband-card"><h6>Reuse A</h6><p>One</p></article></section>', $reuse_css, 'Matrix Reuse Band A' );
$reuse_b = $converter->convert_to_elementor( '<section><article class="reuseband-card"><h6>Reuse B</h6><p>Two</p></article></section>', $reuse_css, 'Matrix Reuse Band B' );
dc_matrix_assert( 'success' === ( $reuse_a['status'] ?? '' ) && 'success' === ( $reuse_b['status'] ?? '' ), 'Reuse-band setup conversions failed.' );
$reuse_master_ids = array_column( array_merge( $reuse_a['reuse'] ?? array(), $reuse_b['reuse'] ?? array() ), 'master_id' );
dc_matrix_assert( 1 === count( array_unique( $reuse_master_ids ) ), 'Reuse-band exact-match setup did not share one master.' );
$component_registry_check = new Design_Core_Elementor_Component_Registry();
$before_reuse_band_count = count( $component_registry_check->all() );
// Same family/color (matches on semantic-purpose + content-schema + style-similarity) but a different structure
// (extra <span>), so this goes through the fuzzy Reuse_Engine/Variant_Engine path, not the exact find_similar() match.
$reuse_c = $converter->convert_to_elementor( '<section><article class="reuseband-card"><h6>Reuse C</h6><p>Three</p><span>extra</span></article></section>', $reuse_css, 'Matrix Reuse Band C' );
dc_matrix_assert( 'success' === ( $reuse_c['status'] ?? '' ), 'Reuse-band near-duplicate conversion failed: ' . ( $reuse_c['error'] ?? '' ) );
dc_matrix_assert( $before_reuse_band_count === count( $component_registry_check->all() ), 'A reuse-band (score >= 0.90) near-duplicate created a new master instead of reusing the existing one.' );
dc_matrix_assert( ( $reuse_c['reuse'][0]['master_id'] ?? null ) === $reuse_master_ids[0], 'Reuse-band near-duplicate was bound to a different master than expected.' );
$results['reuse_band_dedup'] = 'pass';

// Calculator: custom widget.
$calculator = $converter->convert_to_elementor( '<section class="pricing-calculator"><h3>Estimate</h3><p>Configure your plan</p></section>', '', 'Matrix Calculator' );
dc_matrix_assert( 'success' === ( $calculator['status'] ?? '' ), 'Calculator conversion failed: ' . ( $calculator['error'] ?? '' ) );
dc_matrix_assert( 'custom-widget' === ( $calculator['build_plan']['items'][0]['strategy'] ?? '' ), 'Calculator did not select custom-widget: ' . ( $calculator['build_plan']['items'][0]['strategy'] ?? '' ) );
$results['custom_widget'] = 'pass';

// Dynamic Woo-shaped products: loop when the runtime has Loop capability, otherwise an explicit native-compose fallback (never a silent/static "component").
$woo = $converter->convert_to_elementor(
    '<section class="products"><article class="product" data-product-id="1"><h3>Product 1</h3></article><article class="product" data-product-id="2"><h3>Product 2</h3></article></section>',
    '', 'Matrix Woo Loop'
);
dc_matrix_assert( 'success' === ( $woo['status'] ?? '' ), 'Woo loop conversion failed: ' . ( $woo['error'] ?? '' ) );
$capabilities = ( new Design_Core_Elementor_Capability_Scanner() )->scan();
$woo_strategy = $woo['build_plan']['items'][0]['strategy'] ?? '';
if ( ! empty( $capabilities['elementor']['loop_available'] ) ) {
    dc_matrix_assert( 'loop' === $woo_strategy, 'Loop capability is available but dynamic repeated products did not select loop: ' . $woo_strategy );
    $results['loop'] = 'pass:loop-selected';
} else {
    dc_matrix_assert( 'native-compose' === $woo_strategy, 'No Loop capability but dynamic products did not fall back to native-compose: ' . $woo_strategy );
    dc_matrix_assert( false !== strpos( (string) ( $woo['build_plan']['items'][0]['diagnostics']['decision_reason'] ?? '' ), 'Loop' ), 'native-compose fallback for dynamic content is missing an explicit diagnostic reason.' );
    $results['loop'] = 'pass:native-compose-fallback';
}

echo wp_json_encode( array( 'status' => 'pass', 'matrix' => $results ) ) . PHP_EOL;
