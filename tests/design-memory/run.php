<?php
/** Design Memory v1 deterministic contract tests. */
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array(
    'core/fidelity-rule-registry.php',
    'core/design-memory-store.php',
    'core/failure-signature-engine.php',
    'core/design-memory-retriever.php',
    'core/benchmark-promoter.php',
    'core/correction-learning-engine.php',
) );

$store = new Design_Core_Elementor_Design_Memory_Store();
$state = $store->ensure_seeded();
$global = $store->lessons( 'global' );
dc_assert( 8 <= count( $global ), 'memory: governed core lessons are seeded' );
dc_assert( 1 === (int) ( $state['seed_version'] ?? 0 ), 'memory: seed version is durable' );

$unverified = $store->upsert_lesson( array(
    'signature' => 'figma.small-primitive.fixed-size',
    'strategy' => 'fixed-small-primitive',
    'scope' => 'source',
    'scope_key' => 'source-a',
    'verified' => false,
) );
dc_assert( is_wp_error( $unverified ), 'memory: unverified lessons are rejected' );

$unknown_strategy = $store->upsert_lesson( array(
    'signature' => 'figma.unknown',
    'strategy' => 'write-arbitrary-css',
    'scope' => 'source',
    'scope_key' => 'source-a',
    'verified' => true,
) );
dc_assert( is_wp_error( $unknown_strategy ), 'memory: arbitrary learning strategies are rejected' );

$lesson = $store->upsert_lesson( array(
    'signature' => 'figma.small-primitive.fixed-size',
    'strategy' => 'fixed-small-primitive',
    'scope' => 'source',
    'scope_key' => 'source-a',
    'verified' => true,
    'confidence' => 0.96,
    'verified_hits' => 1,
    'origin' => 'contract-test',
    'source_kind' => 'figma',
    'source_fingerprint' => 'source-a',
) );
dc_assert( is_array( $lesson ) && 'fixed-small-primitive' === ( $lesson['strategy'] ?? '' ), 'memory: verified governed lesson is persisted' );

$ir = array(
    'source_name' => 'figma',
    'nodes' => array(
        array(
            'id' => 'figma-dot',
            'source' => array( 'tag' => 'div', 'classes' => array( 'dc-figma-node-4-1087' ) ),
            'semantic' => array( 'role' => 'decorative' ),
            'content' => array( 'text' => '', 'image' => array() ),
            'layout' => array(),
            'style' => array( 'css_fallback' => array() ),
            'spacing' => array(),
            'responsive' => array(),
            'assets' => array(),
            'children' => array(),
            'figma' => array(
                'id' => '4:1087',
                'type' => 'ELLIPSE',
                'geometry' => array( 'x' => 10, 'y' => 20, 'width' => 9, 'height' => 9 ),
                'sizing' => array( 'horizontal' => 'FIXED', 'vertical' => 'FIXED' ),
                'relative_geometry' => array(),
                'constraints' => array(),
            ),
        ),
    ),
    'root_ids' => array( 'figma-dot' ),
    'diagnostics' => array(),
);

$retriever = new Design_Core_Elementor_Design_Memory_Retriever( $store );
$prepared = $retriever->prepare_ir( $ir, array( 'source_fingerprint' => 'source-a', 'project_scope_key' => 'project-a' ) );
$out = $prepared['design_ir'];
$css = (array) ( $out['nodes'][0]['style']['css_fallback'] ?? array() );
dc_assert( '9px' === ( $css['width'] ?? '' ) && '9px' === ( $css['height'] ?? '' ), 'memory: small primitive exact size is restored' );
dc_assert( '0 0 auto' === ( $css['flex'] ?? '' ), 'memory: small primitive cannot flex-stretch' );
dc_assert( in_array( 'fixed-small-primitive', (array) ( $prepared['memory']['strategies'] ?? array() ), true ), 'memory: source lesson is retrieved before compile' );

$learning = new Design_Core_Elementor_Correction_Learning_Engine( $store );
$failed = array(
    'status' => 'needs-correction',
    'similarity' => 0.62,
    'target_similarity' => 0.95,
    'issues' => array(
        array( 'category' => 'geometry', 'severity' => 'high', 'path' => '/figma-node/4-1087', 'viewport' => 1920, 'message' => 'Small primitive stretched.' ),
    ),
);
$incident_result = $learning->observe_verification( $failed, array( 'source_kind' => 'figma', 'source_fingerprint' => 'source-a', 'project_scope_key' => 'project-a', 'page_id' => 12, 'design_ir' => $ir ) );
$incidents = $store->incidents();
dc_assert( 'incident-recorded' === ( $incident_result['status'] ?? '' ) && ! empty( $incidents ), 'memory: failed rendered verification becomes an incident' );
dc_assert( 'figma.small-primitive.fixed-size' === ( $incidents[0]['signature'] ?? '' ), 'memory: incident keeps stable source-aware failure signature' );

$passed = array( 'status' => 'pass', 'similarity' => 0.989, 'target_similarity' => 0.95, 'issues' => array() );
$learning->observe_verification( $passed, array( 'source_kind' => 'figma', 'source_fingerprint' => 'source-a', 'project_scope_key' => 'project-a', 'page_id' => 12, 'design_ir' => $ir ) );
$learning->observe_verification( $passed, array( 'source_kind' => 'figma', 'source_fingerprint' => 'source-a', 'project_scope_key' => 'project-a', 'page_id' => 12, 'design_ir' => $ir ) );
$source_lessons = $store->lessons( 'source' );
$primitive = null;
foreach ( $source_lessons as $candidate ) {
    if ( 'figma.small-primitive.fixed-size' === ( $candidate['signature'] ?? '' ) ) { $primitive = $candidate; break; }
}
dc_assert( is_array( $primitive ) && 2 <= (int) ( $primitive['verified_hits'] ?? 0 ), 'memory: repeated verified renders reinforce the lesson' );

$promoted = ( new Design_Core_Elementor_Benchmark_Promoter( $store ) )->catalog();
dc_assert( ! empty( $promoted['candidates'] ), 'memory: repeated high-confidence lesson becomes a benchmark candidate' );

dc_finish( 'Design Memory contract' );
