<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

$file = DESIGN_CORE_ELEMENTOR_PATH . 'core/layout-media-analyzer.php';
if ( ! file_exists( $file ) ) {
    dc_assert( false, 'Layout and media analyzer exists' );
    dc_finish( 'Layout and media analysis' );
}
require_once $file;
dc_require( array( 'core/design-ir.php', 'core/design-ir-validator.php' ) );

$base = static function ( $id, $tag, $path, $children = array() ) {
    return array(
        'id' => $id,
        'source' => array( 'tag' => $tag, 'classes' => array( $id ), 'attributes' => array(), 'dom_path' => $path ),
        'semantic' => array( 'role' => 'section', 'component_type' => '', 'confidence' => 1.0 ),
        'content' => array( 'text' => '', 'rich_text' => '', 'link' => array(), 'image' => array(), 'list' => array(), 'fields' => array() ),
        'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'assets' => array(), 'interaction' => array(),
        'component' => array( 'fingerprint' => array( 'version' => 2, 'semantic' => 'section', 'structure' => $tag, 'content_schema' => array(), 'layout' => '', 'interaction' => '' ), 'repeated' => false, 'reusable' => false, 'dynamic' => false, 'content_schema' => array() ),
        'children' => $children,
    );
};
$single_ir = static function ( $node ) {
    return array( 'schema_version' => 4, 'type' => 'design-ir', 'nodes' => array( $node ), 'root_ids' => array( $node['id'] ), 'analysis_quality' => array(), 'tokens' => array(), 'diagnostics' => array() );
};

$full = $base( 'full', 'section', '/body/section[1]', array( 'full-inner' ) );
$full_inner = $base( 'full-inner', 'div', '/body/section[1]/div[1]' );
$boxed = $base( 'boxed', 'section', '/body/section[2]' );
$bleed = $base( 'bleed', 'section', '/body/section[3]', array( 'bleed-inner' ) );
$bleed_inner = $base( 'bleed-inner', 'div', '/body/section[3]/div[1]' );
$fixed = $base( 'fixed', 'section', '/body/section[4]' );
$fixed['layout']['width'] = array( 'value' => 1184, 'unit' => 'px' );
$image = $base( 'photo', 'img', '/body/img[5]' );
$image['content']['image'] = array( 'url' => 'https://example.test/photo.webp', 'alt' => '' );
$hero = $base( 'hero', 'section', '/body/section[6]' );
$hero['semantic']['role'] = 'hero';
$hero['style']['css_fallback'] = array( 'background-image' => 'url("hero.webp")', 'min-height' => 'clamp(560px, 85svh, 900px)' );
$unsafe = $base( 'unsafe', 'section', '/body/section[7]' );
$unsafe['style']['css_fallback'] = array( 'height' => '100vh', 'overflow' => 'hidden' );
$pixel = $base( 'pixel', 'section', '/body/section[8]' );
$pixel['style']['css_fallback'] = array( 'height' => '720px', 'background-image' => 'url("art.webp")' );
$asymmetric = $base( 'asymmetric', 'section', '/body/section[9]', array( 'asymmetric-child' ) );
$asymmetric_child = $base( 'asymmetric-child', 'div', '/body/section[9]/div[1]' );

$ir = array(
    'schema_version' => 4, 'type' => 'design-ir',
    'nodes' => array( $full, $full_inner, $boxed, $bleed, $bleed_inner, $fixed, $image, $hero, $unsafe, $pixel, $asymmetric, $asymmetric_child ),
    'root_ids' => array( 'full', 'boxed', 'bleed', 'fixed', 'photo', 'hero', 'unsafe', 'pixel', 'asymmetric' ),
    'analysis_quality' => array(), 'tokens' => array(), 'diagnostics' => array(),
);

$element = static function ( $path, $x, $width, $height, $background = 'rgba(0, 0, 0, 0)', $extra = array() ) {
    return array_merge( array(
        'domPath' => $path,
        'rect' => array( 'x' => $x, 'y' => 0, 'width' => $width, 'height' => $height ),
        'styles' => array(
            'backgroundColor' => $background, 'backgroundImage' => 'none', 'borderTopWidth' => '0px', 'borderRadius' => '0px', 'boxShadow' => 'none',
            'width' => $width . 'px', 'height' => $height . 'px', 'minHeight' => '0px', 'maxWidth' => 'none', 'objectFit' => 'fill', 'objectPosition' => '50% 50%', 'aspectRatio' => 'auto', 'overflow' => 'visible',
        ),
    ), $extra );
};

$viewports = array();
foreach ( array( 1440, 1366, 1024, 767, 390 ) as $width ) {
    $inner = min( $width - 32, 1200 );
    $viewports[ $width ] = array(
        $element( '/body/section[1]', 0, $width, 600, 'rgb(10, 10, 10)' ),
        $element( '/body/section[1]/div[1]', ( $width - $inner ) / 2, $inner, 500 ),
        $element( '/body/section[2]', max( 16, ( $width - $inner ) / 2 ), min( $inner, $width - 32 ), 500, 'rgb(240, 240, 240)' ),
        $element( '/body/section[3]', 0, $width, 520, 'rgb(20, 20, 20)' ),
        $element( '/body/section[3]/div[1]', 0, $width, 500 ),
        $element( '/body/section[4]', 0, min( 1184, $width ), 400 ),
        $element( '/body/img[5]', 0, min( 800, $width ), min( 450, $width * 9 / 16 ), 'rgba(0, 0, 0, 0)', array( 'naturalWidth' => 1600, 'naturalHeight' => 900 ) ),
        $element( '/body/section[6]', 0, $width, 760, 'rgba(0, 0, 0, 0)', array( 'styles' => array( 'backgroundColor' => 'rgba(0, 0, 0, 0)', 'backgroundImage' => 'url("hero.webp")', 'height' => '760px', 'minHeight' => '560px', 'objectFit' => 'fill', 'objectPosition' => '50% 50%', 'aspectRatio' => 'auto', 'overflow' => 'visible', 'borderTopWidth' => '0px', 'borderRadius' => '0px', 'boxShadow' => 'none', 'width' => $width . 'px', 'maxWidth' => 'none' ) ) ),
        $element( '/body/section[7]', 0, $width, 1200, 'rgb(0, 0, 0)' ),
        $element( '/body/section[8]', 0, $width, 720, 'rgba(0, 0, 0, 0)', array( 'styles' => array( 'backgroundColor' => 'rgba(0, 0, 0, 0)', 'backgroundImage' => 'url("art.webp")', 'height' => '720px', 'minHeight' => '0px', 'objectFit' => 'fill', 'objectPosition' => '50% 50%', 'aspectRatio' => 'auto', 'overflow' => 'visible', 'borderTopWidth' => '0px', 'borderRadius' => '0px', 'boxShadow' => 'none', 'width' => $width . 'px', 'maxWidth' => 'none' ) ) ),
        $element( '/body/section[9]', 0, $width, 500 ),
        $element( '/body/section[9]/div[1]', 0, $width * 0.4, 400 ),
    );
}
$browser = array( 'schema_version' => 3, 'viewports' => $viewports );

$analyzer = new Design_Core_Elementor_Layout_Media_Analyzer();
$enriched = $analyzer->enrich( $ir, $browser );
$nodes = array(); foreach ( $enriched['nodes'] as $node ) { $nodes[ $node['id'] ] = $node; }

dc_assert( 'fullwidth' === ( $nodes['full']['semantic']['layout_mode'] ?? '' ), 'Full viewport surface with constrained inner content is classified fullwidth' );
dc_assert( 'content' === ( $nodes['full-inner']['semantic']['container_role'] ?? '' ), 'Constrained child is classified as the content container' );
dc_assert( 'boxed' === ( $nodes['full-inner']['semantic']['layout_mode'] ?? '' ), 'Constrained child is classified for native Elementor Boxed content width' );
dc_assert( 'boxed' === ( $nodes['boxed']['semantic']['layout_mode'] ?? '' ), 'Constrained visual surface is classified boxed' );
dc_assert( 'fullwidth-content' === ( $nodes['bleed']['semantic']['layout_mode'] ?? '' ), 'Full viewport surface and content are classified fullwidth-content' );
dc_assert( 'fixed' === ( $nodes['fixed']['semantic']['width_policy'] ?? '' ) && ! empty( $nodes['fixed']['semantic']['fixed_width_violation'] ), 'Large structural fixed width is explicitly governed' );
dc_assert( 'intrinsic-ratio' === ( $nodes['photo']['media']['height_policy'] ?? '' ), 'Natural image ratio is preferred over fixed height' );
dc_assert( '1600/900' === ( $nodes['photo']['media']['intrinsic_ratio'] ?? '' ), 'Image natural dimensions are retained as ratio evidence' );
dc_assert( 'viewport-min-height' === ( $nodes['hero']['media']['height_policy'] ?? '' ), 'Clamp with svh is recognized as a responsive hero min-height policy' );
dc_assert( empty( $nodes['hero']['media']['warnings'] ?? array() ), 'Safe svh min-height does not produce a warning' );
dc_assert( in_array( 'fixed-height-100vh-content-clipping-risk', $nodes['unsafe']['media']['warnings'] ?? array(), true ), 'Fixed 100vh plus hidden overflow is flagged as unsafe' );
dc_assert( in_array( 'fixed-pixel-height-responsive-risk', $nodes['pixel']['media']['warnings'] ?? array(), true ), 'Large fixed pixel section height is flagged as a responsive risk' );
dc_assert( 'mixed' === ( $nodes['asymmetric']['semantic']['layout_mode'] ?? '' ), 'Full-width parent with an asymmetric partial-width child is classified mixed rather than fullwidth-content' );
dc_assert( array( 1440, 1366, 1024, 767, 390 ) === ( $enriched['diagnostics']['layout_media_viewports'] ?? array() ), 'All governed viewport tiers contribute evidence' );

$partial_node = $base( 'partial-matrix', 'section', '/body/section[10]', array( 'partial-child' ) );
$partial_child = $base( 'partial-child', 'div', '/body/section[10]/div[1]' );
$partial_ir = array( 'schema_version' => 4, 'type' => 'design-ir', 'nodes' => array( $partial_node, $partial_child ), 'root_ids' => array( 'partial-matrix' ), 'analysis_quality' => array(), 'tokens' => array(), 'diagnostics' => array() );
$partial_browser = array( 'schema_version' => 3, 'viewports' => array( 1440 => array( $element( '/body/section[10]', 0, 1440, 500 ), $element( '/body/section[10]/div[1]', 0, 1440, 400 ) ) ) );
$partial_enriched = $analyzer->enrich( $partial_ir, $partial_browser );
dc_assert( 'unknown' === ( $partial_enriched['nodes'][0]['semantic']['layout_mode'] ?? '' ) && 0.5 > (float) ( $partial_enriched['nodes'][0]['semantic']['layout_confidence'] ?? 1 ), 'Incomplete governed viewport evidence cannot produce a high-confidence layout classification' );

$split_root = $base( 'split-root', 'section', '/body/section[15]', array( 'split-content', 'split-media' ) );
$split_content = $base( 'split-content', 'div', '/body/section[15]/div[1]' );
$split_media = $base( 'split-media', 'div', '/body/section[15]/div[2]' );
$split_ir = array( 'schema_version' => 4, 'type' => 'design-ir', 'nodes' => array( $split_root, $split_content, $split_media ), 'root_ids' => array( 'split-root' ), 'analysis_quality' => array(), 'tokens' => array(), 'diagnostics' => array() );
$split_browser = array( 'schema_version' => 3, 'viewports' => array() );
foreach ( array( 1440, 1366, 1024, 767, 390 ) as $viewport ) {
    $inner_width = min( 1184, $viewport - 32 );
    $split_browser['viewports'][ $viewport ] = array(
        $element( '/body/section[15]', 0, $viewport, 600 ),
        $element( '/body/section[15]/div[1]', ( $viewport - $inner_width ) / 2, $inner_width, 400 ),
        $element( '/body/section[15]/div[2]', 0, $viewport, 200 ),
    );
}
$split_enriched = $analyzer->enrich( $split_ir, $split_browser );
$split_nodes = array(); foreach ( $split_enriched['nodes'] as $split_node ) { $split_nodes[ $split_node['id'] ] = $split_node; }
dc_assert( 'mixed' === ( $split_nodes['split-root']['semantic']['layout_mode'] ?? '' ) && 'content' === ( $split_nodes['split-content']['semantic']['container_role'] ?? '' ), 'Constrained content plus a full-bleed media child is classified mixed/split-bleed with explicit content ownership' );

$picture = $base( 'responsive-picture', 'img', '/body/img[11]' );
$picture['content']['image'] = array( 'url' => 'picture.webp', 'alt' => '' );
$picture['style']['css_fallback'] = array( 'height' => '500px' );
$picture_browser = array( 'schema_version' => 3, 'viewports' => array() );
foreach ( array( 1440, 1366, 1024, 767, 390 ) as $viewport ) {
    $mobile_source = 390 === $viewport;
    $picture_browser['viewports'][ $viewport ] = array( $element(
        '/body/img[11]', 0, min( 800, $viewport ), $mobile_source ? 200 : 450, 'rgba(0, 0, 0, 0)',
        array( 'naturalWidth' => $mobile_source ? 1200 : 1600, 'naturalHeight' => $mobile_source ? 1200 : 900, 'styles' => array( 'backgroundColor' => 'rgba(0, 0, 0, 0)', 'backgroundImage' => 'none', 'width' => min( 800, $viewport ) . 'px', 'height' => ( $mobile_source ? 200 : 450 ) . 'px', 'minHeight' => '0px', 'maxWidth' => 'none', 'objectFit' => 'cover', 'objectPosition' => $mobile_source ? '70% 50%' : '30% 40%', 'aspectRatio' => 'auto', 'overflow' => 'visible', 'borderTopWidth' => '0px', 'borderRadius' => '0px', 'boxShadow' => 'none' ) )
    ) );
}
$picture_enriched = $analyzer->enrich( $single_ir( $picture ), $picture_browser );
$picture_media = $picture_enriched['nodes'][0]['media'] ?? array();
dc_assert( in_array( $picture_media['intrinsic_ratio'] ?? '', array( '1600/900', '1200/1200' ), true ) && '1600/1200' !== ( $picture_media['intrinsic_ratio'] ?? '' ), 'Responsive image intrinsic ratio is selected from one real source sample, never independent maxima' );
dc_assert( '70% 50%' === ( $picture_media['object_position_by_viewport'][390] ?? '' ) && '30% 40%' === ( $picture_media['object_position_by_viewport'][1440] ?? '' ), 'Responsive image focal positions are preserved per governed viewport' );
dc_assert( in_array( 'fixed-pixel-height-responsive-risk', $picture_media['warnings'] ?? array(), true ), 'Fixed image height is analyzed rather than bypassed by the image early return' );

$responsive_unsafe_media = $base( 'responsive-unsafe-media', 'section', '/body/section[12]' );
$responsive_unsafe_media['responsive']['mobile'] = array( 'style' => array( 'css_fallback' => array( 'height' => 'calc(100vh - 10px)', 'overflow' => 'hidden' ) ) );
$responsive_unsafe_browser = array( 'schema_version' => 3, 'viewports' => array() );
foreach ( array( 1440, 1366, 1024, 767, 390 ) as $viewport ) { $responsive_unsafe_browser['viewports'][ $viewport ] = array( $element( '/body/section[12]', 0, $viewport, 600 ) ); }
$responsive_unsafe_enriched = $analyzer->enrich( $single_ir( $responsive_unsafe_media ), $responsive_unsafe_browser );
dc_assert( in_array( 'fixed-height-100vh-content-clipping-risk', $responsive_unsafe_enriched['nodes'][0]['media']['warnings'] ?? array(), true ), 'Responsive calc viewport height is analyzed and flagged from canonical responsive state' );

$background = $base( 'focal-background', 'section', '/body/section[13]' );
$background['style']['css_fallback'] = array( 'background-image' => 'url("focal.webp")' );
$background_browser = array( 'schema_version' => 3, 'viewports' => array() );
foreach ( array( 1440, 1366, 1024, 767, 390 ) as $viewport ) {
    $background_browser['viewports'][ $viewport ] = array( $element( '/body/section[13]', 0, $viewport, 500, 'rgba(0, 0, 0, 0)', array( 'styles' => array( 'backgroundColor' => 'rgba(0, 0, 0, 0)', 'backgroundImage' => 'url("focal.webp")', 'backgroundSize' => 'cover', 'backgroundPosition' => 390 === $viewport ? '75% 50%' : '30% 40%', 'width' => $viewport . 'px', 'height' => '500px', 'minHeight' => '0px', 'maxWidth' => 'none', 'objectFit' => 'fill', 'objectPosition' => '50% 50%', 'aspectRatio' => 'auto', 'overflow' => 'visible', 'borderTopWidth' => '0px', 'borderRadius' => '0px', 'boxShadow' => 'none' ) ) ) );
}
$background_enriched = $analyzer->enrich( $single_ir( $background ), $background_browser );
dc_assert( '75% 50%' === ( $background_enriched['nodes'][0]['media']['background_position_by_viewport'][390] ?? '' ) && 'cover' === ( $background_enriched['nodes'][0]['media']['background_size_by_viewport'][1440] ?? '' ), 'Background crop and focal evidence are preserved per governed viewport' );
$background_report = $analyzer->report( $background_enriched );
dc_assert( '75% 50%' === ( $background_report['media']['focal-background']['background_position_by_viewport'][390] ?? '' ), 'Media report exposes governed responsive focal evidence' );

$report = $analyzer->report( $enriched );
dc_assert( 'fullwidth' === ( $report['sections']['full']['layout_mode'] ?? '' ), 'Analysis report exposes section layout mode and confidence' );
dc_assert( ! isset( $report['sections']['full-inner'] ) && 'content' === ( $report['containers']['full-inner']['container_role'] ?? '' ), 'Analysis report separates semantic sections from nested content containers' );
dc_assert( 'intrinsic-ratio' === ( $report['media']['photo']['height_policy'] ?? '' ), 'Analysis report exposes image height and ratio policy' );
dc_assert( in_array( 'fixed-height-100vh-content-clipping-risk', $report['media']['unsafe']['warnings'] ?? array(), true ), 'Analysis report exposes unsafe viewport-height findings' );

$validator = new Design_Core_Elementor_Design_IR_Validator();
$reasonless = $ir;
foreach ( $reasonless['nodes'] as &$reasonless_node ) { if ( 'fixed' === $reasonless_node['id'] ) { $reasonless_node['layout_governance'] = array( 'fixed_width_exception' => true ); } }
unset( $reasonless_node );
$reasonless = $analyzer->enrich( $reasonless, $browser );
$reasonless_rejected = false;
try { $validator->validate( $reasonless ); } catch ( InvalidArgumentException $exception ) { $reasonless_rejected = false !== strpos( $exception->getMessage(), 'fixed-width' ); }
dc_assert( $reasonless_rejected, 'A fixed-width exception without a reason cannot bypass governance during enrichment' );

$fixed_rejected = false;
try { $validator->validate( $enriched ); } catch ( InvalidArgumentException $exception ) { $fixed_rejected = false !== strpos( $exception->getMessage(), 'fixed-width' ); }
dc_assert( $fixed_rejected, 'Canonical validation rejects unexplained large fixed-width structural Containers' );

$governed = $enriched;
foreach ( $governed['nodes'] as &$governed_node ) {
    if ( 'fixed' === $governed_node['id'] ) { $governed_node['layout_governance'] = array( 'fixed_width_exception' => true, 'reason' => 'Reference requires a fixed art-directed frame.' ); }
}
unset( $governed_node );
$unsafe_rejected = false;
try { $validator->validate( $governed ); } catch ( InvalidArgumentException $exception ) { $unsafe_rejected = false !== strpos( $exception->getMessage(), 'media-height' ); }
dc_assert( $unsafe_rejected, 'Canonical validation rejects unsafe fixed viewport height and clipping' );

foreach ( $governed['nodes'] as &$governed_node ) {
    if ( 'unsafe' === $governed_node['id'] ) { $governed_node['media_governance'] = array( 'height_exception' => true, 'reason' => 'Fullscreen artwork intentionally clips and contains no editable content.' ); }
    if ( 'pixel' === $governed_node['id'] ) { $governed_node['media_governance'] = array( 'height_exception' => true, 'reason' => 'Fixed art-directed frame is required by the reference.' ); }
}
unset( $governed_node );
$governed_valid = true;
try { $validator->validate( $governed ); } catch ( InvalidArgumentException $exception ) { $governed_valid = false; }
dc_assert( $governed_valid, 'Rare fixed width and media height exceptions require explicit reasons' );

$raw_fixed = $base( 'raw-fixed', 'section', '/body/section[9]' );
$raw_fixed['layout']['width'] = array( 'value' => 1184, 'unit' => 'px' );
$raw_fixed_rejected = false;
try { $validator->validate( $single_ir( $raw_fixed ) ); } catch ( InvalidArgumentException $exception ) { $raw_fixed_rejected = false !== strpos( $exception->getMessage(), 'fixed-width' ); }
dc_assert( $raw_fixed_rejected, 'Validator derives structural fixed-width risk directly from unannotated canonical IR' );
$raw_fixed_calc = $base( 'raw-fixed-calc', 'section', '/body/section[16]' );
$raw_fixed_calc['style']['css_fallback']['width'] = 'calc(1184px)';
$raw_fixed_calc_enriched = $analyzer->enrich( $single_ir( $raw_fixed_calc ), array() );
$raw_fixed_calc_report = $analyzer->report( $raw_fixed_calc_enriched );
dc_assert( 'fixed' === ( $raw_fixed_calc_enriched['nodes'][0]['semantic']['width_policy'] ?? '' ) && in_array( 'raw-fixed-calc', $raw_fixed_calc_report['fixed_width_violations'] ?? array(), true ), 'Analyzer and report derive fixed-width policy from raw constant pixel expressions' );
$dual_width = $base( 'dual-width', 'section', '/body/section[18]' );
$dual_width['layout']['width'] = array( 'value' => 100, 'unit' => '%' );
$dual_width['style']['css_fallback']['width'] = 'calc(1184px)';
$dual_width_enriched = $analyzer->enrich( $single_ir( $dual_width ), array() );
$dual_width_report = $analyzer->report( $dual_width_enriched );
dc_assert( 'fixed' === ( $dual_width_enriched['nodes'][0]['semantic']['width_policy'] ?? '' ) && in_array( 'dual-width', $dual_width_report['fixed_width_violations'] ?? array(), true ), 'Analyzer evaluates native and fallback width candidates independently in the same canonical state' );
$raw_fixed_calc_rejected = false;
try { $validator->validate( $single_ir( $raw_fixed_calc ) ); } catch ( InvalidArgumentException $exception ) { $raw_fixed_calc_rejected = false !== strpos( $exception->getMessage(), 'fixed-width' ); }
dc_assert( $raw_fixed_calc_rejected, 'Validator rejects constant calc structural width instead of allowing an expression bypass' );

$raw_responsive_height = $base( 'raw-responsive-height', 'section', '/body/section[10]' );
$raw_responsive_height['responsive']['mobile'] = array( 'style' => array( 'css_fallback' => array( 'height' => 'calc(100vh - 10px)', 'overflow' => 'hidden' ) ) );
$raw_height_rejected = false;
try { $validator->validate( $single_ir( $raw_responsive_height ) ); } catch ( InvalidArgumentException $exception ) { $raw_height_rejected = false !== strpos( $exception->getMessage(), 'media-height' ); }
dc_assert( $raw_height_rejected, 'Validator derives unsafe responsive calc viewport height directly from unannotated canonical IR' );
$raw_pixel_calc = $base( 'raw-pixel-calc', 'section', '/body/section[17]' );
$raw_pixel_calc['style']['css_fallback']['height'] = 'calc(720px)';
$raw_pixel_calc_enriched = $analyzer->enrich( $single_ir( $raw_pixel_calc ), array() );
dc_assert( in_array( 'fixed-pixel-height-responsive-risk', $raw_pixel_calc_enriched['nodes'][0]['media']['warnings'] ?? array(), true ), 'Analyzer flags a constant calc pixel height instead of allowing an expression bypass' );
$raw_pixel_calc_rejected = false;
try { $validator->validate( $single_ir( $raw_pixel_calc ) ); } catch ( InvalidArgumentException $exception ) { $raw_pixel_calc_rejected = false !== strpos( $exception->getMessage(), 'media-height' ); }
dc_assert( $raw_pixel_calc_rejected, 'Validator rejects a constant calc fixed-pixel height directly from raw canonical IR' );

$orphan_owner = $base( 'orphan-owner', 'div', '/body/div[14]' );
$orphan_owner['semantic']['layout_mode'] = 'mixed';
$orphan_owner['semantic']['container_role'] = 'content';
$ownership_rejected = false;
try { $validator->validate( $single_ir( $orphan_owner ) ); } catch ( InvalidArgumentException $exception ) { $ownership_rejected = false !== strpos( $exception->getMessage(), 'container ownership' ); }
dc_assert( $ownership_rejected, 'Canonical validation rejects a self-asserted global content owner without a governed parent surface' );

$width_root = $base( 'width-root', 'section', '/body/section[18]', array( 'width-content' ) );
$width_root['semantic']['layout_mode'] = 'fullwidth';
$width_root['semantic']['container_role'] = 'surface';
$width_root['layout']['content_width'] = 'full';
$width_content = $base( 'width-content', 'div', '/body/section[18]/div[1]' );
$width_content['semantic']['layout_mode'] = 'boxed';
$width_content['semantic']['container_role'] = 'content';
$width_content['layout']['content_width'] = 'full';
$width_ir = array( 'schema_version' => 4, 'type' => 'design-ir', 'nodes' => array( $width_root, $width_content ), 'root_ids' => array( 'width-root' ), 'analysis_quality' => array(), 'tokens' => array(), 'diagnostics' => array() );
$boxed_width_rejected = false;
try { $validator->validate( $width_ir ); } catch ( InvalidArgumentException $exception ) { $boxed_width_rejected = false !== strpos( $exception->getMessage(), 'content width semantics' ); }
dc_assert( $boxed_width_rejected, 'Canonical validation rejects Boxed content ownership paired with Full Width controls' );
$width_ir['nodes'][1]['layout']['content_width'] = 'boxed';
$width_ir['nodes'][0]['layout']['content_width'] = 'boxed';
$full_width_rejected = false;
try { $validator->validate( $width_ir ); } catch ( InvalidArgumentException $exception ) { $full_width_rejected = false !== strpos( $exception->getMessage(), 'content width semantics' ); }
dc_assert( $full_width_rejected, 'Canonical validation rejects a Full Width surface paired with Boxed controls' );

$browser_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'scripts/browser-analyze.mjs' );
dc_assert( false !== strpos( $browser_source, 'domPath' ), 'Browser analyzer emits stable DOM paths for IR evidence matching' );
dc_assert( false !== strpos( $browser_source, 'naturalWidth' ) && false !== strpos( $browser_source, 'naturalHeight' ), 'Browser analyzer captures intrinsic image dimensions' );
dc_assert( false !== strpos( $browser_source, '1440,1366,1024,767,390' ), 'Browser analyzer defaults to all four governed tiers plus real mobile verification' );
dc_assert( false !== strpos( $browser_source, 'MAX_ELEMENTS' ) && false !== strpos( $browser_source, 'element-limit-exceeded' ), 'Browser analyzer fails closed before serializing an unbounded DOM' );
$browser_service_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/browser-analysis-service.php' );
dc_assert( false !== strpos( $browser_service_source, 'proc_open' ) && false !== strpos( $browser_service_source, 'MAX_OUTPUT_BYTES' ) && false !== strpos( $browser_service_source, 'PROCESS_TIMEOUT_SECONDS' ), 'Browser service bounds process duration and captured output' );

dc_finish( 'Layout and media analysis' );
