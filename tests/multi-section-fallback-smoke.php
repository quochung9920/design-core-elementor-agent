<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
wp_set_current_user( 1 );
function dc_msf_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

/**
 * A real, multi-section page where the MIDDLE section needs its BuildPlan fallback must
 * still render every OTHER section on the page. Build_Plan_Executor previously returned
 * from inside its items loop the moment any one item needed a fallback, discarding every
 * section before and after it -- every other test in this repo happens to use
 * single-section fixtures, so that bug was never exercised until an independent review
 * traced it directly against the executor's own source.
 *
 * The middle section is a `navigation` role, which Native_Widget_Resolver deliberately has
 * zero candidates for (no verified-safe Elementor Pro Nav Menu construction is available --
 * see native-widget-resolver.php), so it deterministically needs its native-compose
 * fallback in ANY environment, unlike a Loop-capability-dependent scenario.
 */
$html = '<section><h1>Welcome Hero</h1><p>Top of the page.</p></section>'
    . '<section class="site-nav"><a href="#home">Home</a><a href="#about">About</a></section>'
    . '<section class="cta"><h2>Bottom CTA</h2><a href="#go">Go</a></section>';

$result = ( new Design_Core_Elementor_HTML_Converter() )->convert_to_elementor( $html, '', 'Multi Section Fallback' );
dc_msf_assert( 'success' === ( $result['status'] ?? '' ), 'Multi-section conversion failed: ' . ( $result['error'] ?? 'unknown' ) );

$strategies = array_column( $result['build_plan']['items'] ?? array(), 'strategy' );
dc_msf_assert( 3 === count( $strategies ), 'Expected 3 top-level BuildPlan items, got ' . count( $strategies ) . ' (' . implode( ',', $strategies ) . ')' );
dc_msf_assert( 'native-widget' === ( $strategies[1] ?? '' ), 'Middle (navigation) section was not decided as native-widget: ' . ( $strategies[1] ?? '' ) );

$item_results = $result['execution']['diagnostics']['item_results'] ?? array();
dc_msf_assert( 3 === count( $item_results ), 'item_results must carry an entry for all 3 items, including the one that used its fallback; got ' . count( $item_results ) );
dc_msf_assert( true === ( $item_results[1]['fallback_used'] ?? false ), 'Middle item result is not marked fallback_used (navigation has no safe native-widget construction here, so it must recover via the native-compose fallback).' );
dc_msf_assert( 'native-compose' === ( $item_results[1]['diagnostics']['actual_strategy'] ?? '' ), 'Middle item fallback diagnostics do not show native-compose as the actual strategy.' );

dc_msf_assert( 3 === count( $result['execution']['elements'] ?? array() ), 'Expected elements from all 3 sections in the final page, got ' . count( $result['execution']['elements'] ?? array() ) );

$post_id = (int) $result['page_id'];
$rendered = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $post_id, true );
dc_msf_assert( '' !== trim( (string) $rendered ), 'Multi-section page failed to render.' );
$pos_hero = strpos( $rendered, 'Welcome Hero' );
$pos_nav = strpos( $rendered, 'Home' );
$pos_cta = strpos( $rendered, 'Bottom CTA' );
dc_msf_assert( false !== $pos_hero, 'Hero section (before the fallback item) is missing from the rendered page -- it was silently dropped.' );
dc_msf_assert( false !== $pos_nav, 'Middle (fallback-recovered) section is missing from the rendered page.' );
dc_msf_assert( false !== $pos_cta, 'CTA section (after the fallback item) is missing from the rendered page -- it was silently dropped.' );
dc_msf_assert( $pos_hero < $pos_nav && $pos_nav < $pos_cta, 'Sections did not render in source order.' );

echo "multi-section-fallback=pass\n";
