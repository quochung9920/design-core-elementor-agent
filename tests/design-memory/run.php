<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array(
    'core/design-memory-store.php',
    'core/failure-signature-engine.php',
    'core/fidelity-rule-registry.php',
    'core/lesson-retriever.php',
    'core/benchmark-promoter.php',
    'core/correction-learning-engine.php',
    'core/figma-vector-asset-resolver.php',
) );

$store = new Design_Core_Elementor_Design_Memory_Store();
$engine = new Design_Core_Elementor_Failure_Signature_Engine();

$signature = $engine->signature( array(
    'source_type' => 'figma',
    'asset_type' => 'svg',
    'category' => 'asset-missing',
    'component_role' => 'button',
) );
dc_assert( 'figma.vector-component.asset-loss' === $signature, 'memory: vector asset loss has a stable generic signature' );

$small = $engine->signature( array(
    'source_type' => 'figma',
    'category' => 'size',
    'reference_width' => 9,
    'reference_height' => 9,
    'candidate_width' => 140,
    'candidate_height' => 30,
) );
dc_assert( 'figma.small-primitive.flex-stretch' === $small, 'memory: stretched 9px primitive is classified generically' );

$rules = ( new Design_Core_Elementor_Fidelity_Rule_Registry() )->match( $signature );
dc_assert( ! empty( $rules ) && 'preserve-vector-component-assets' === $rules[0]['id'], 'memory: vector signature retrieves built-in preservation rule' );

$failed = ( new Design_Core_Elementor_Correction_Learning_Engine( $store ) )->learn( array(
    'status' => 'needs-correction',
    'before_score' => 0.62,
    'after_score' => 0.82,
    'target_similarity' => 0.95,
    'context' => array( 'source_type' => 'figma', 'asset_type' => 'svg', 'category' => 'asset-missing' ),
    'correction' => array( 'strategy' => 'preserve-vector-asset' ),
) );
dc_assert( false === ( $failed['learned'] ?? true ), 'memory: failed correction is never learned' );
dc_assert( 0 === count( $store->lessons() ), 'memory: failed correction creates no lesson' );
dc_assert( 1 === count( $store->incidents() ), 'memory: failed correction is still retained as an incident' );

$verified = ( new Design_Core_Elementor_Correction_Learning_Engine( $store ) )->learn( array(
    'status' => 'verified',
    'before_score' => 0.62,
    'after_score' => 0.982,
    'target_similarity' => 0.95,
    'quality_gate' => array( 'publishable' => true ),
    'context' => array( 'source_type' => 'figma', 'asset_type' => 'svg', 'category' => 'asset-missing', 'component_role' => 'button' ),
    'correction' => array( 'strategy' => 'preserve-vector-asset', 'flatten_parent' => false ),
) );
dc_assert( true === ( $verified['learned'] ?? false ), 'memory: verified visual correction is learned' );
dc_assert( 1 === count( $store->lessons() ), 'memory: verified correction creates one durable lesson' );
dc_assert( ! empty( $verified['benchmark_candidate'] ), 'memory: verified lesson proposes a review-gated regression benchmark' );

$retrieved = ( new Design_Core_Elementor_Lesson_Retriever( $store ) )->retrieve( array(
    'source_type' => 'figma', 'asset_type' => 'svg', 'category' => 'asset-missing', 'component_role' => 'button'
) );
dc_assert( ! empty( $retrieved ), 'memory: a future matching context retrieves the verified lesson' );
dc_assert( (float) ( $retrieved[0]['match_score'] ?? 0 ) >= 0.5, 'memory: exact signature retrieval has strong relevance' );

$resolver = new Design_Core_Elementor_Figma_Vector_Asset_Resolver();
$icon = array(
    'id' => '10:20', 'type' => 'INSTANCE', 'visible' => true,
    'absoluteBoundingBox' => array( 'x' => 0, 'y' => 0, 'width' => 17, 'height' => 17 ),
    'children' => array(
        array( 'id' => '10:21', 'type' => 'VECTOR', 'visible' => true, 'absoluteBoundingBox' => array( 'x' => 0, 'y' => 0, 'width' => 17, 'height' => 17 ) ),
    ),
);
dc_assert( true === $resolver->is_export_candidate( $icon ), 'figma assets: small vector-only component is exported as one SVG asset' );
dc_assert( array( '10:20' ) === $resolver->collect( $icon, 48 ), 'figma assets: composite icon export suppresses redundant child export' );

$dot = array( 'id' => '10:30', 'type' => 'ELLIPSE', 'visible' => true, 'absoluteBoundingBox' => array( 'x' => 0, 'y' => 0, 'width' => 9, 'height' => 9 ) );
dc_assert( true === $resolver->is_export_candidate( $dot ), 'figma assets: small ellipse primitive is preserved as an exact asset' );

$large_frame = array(
    'id' => '10:40', 'type' => 'FRAME', 'visible' => true,
    'absoluteBoundingBox' => array( 'x' => 0, 'y' => 0, 'width' => 536, 'height' => 470 ),
    'children' => array( array( 'id' => '10:41', 'type' => 'VECTOR', 'visible' => true ) ),
);
dc_assert( false === $resolver->is_export_candidate( $large_frame ), 'figma assets: large layout/media frame is not collapsed into an SVG icon' );

dc_finish( 'Design Memory' );
