<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
dc_require( array( 'core/design-token-service.php', 'core/global-style-bridge.php' ) );

$tokens = new Design_Core_Elementor_Design_Token_Service();
$tokens->save( array(
    'colors' => array( 'ink' => '#131417', 'copy' => '#5b5955' ),
    'typography' => array(
        'section-heading' => array(
            'family' => 'Geist',
            'weight' => '600',
            'size' => '34',
            'line_height' => '1.1',
            'letter_spacing' => '-2',
            'font_size_mobile' => '26',
        ),
    ),
) );
$bridge = new Design_Core_Elementor_Global_Style_Bridge( $tokens );
$sync = array(
    'mode' => 'v3',
    'status' => 'synced',
    'colors' => array(
        'ink' => 'globals/colors?id=ink1234',
        'copy' => 'globals/colors?id=copy123',
    ),
    'typography' => array(
        'section-heading' => 'globals/typography?id=type123',
    ),
);

$elements = array(
    array(
        'id' => 'heading1', 'elType' => 'widget', 'widgetType' => 'heading',
        'settings' => array(
            'title_color' => '#131417',
            'typography_typography' => 'custom',
            'typography_font_family' => 'Geist',
            'typography_font_weight' => '600',
            'typography_font_size' => array( 'size' => 34, 'unit' => 'px' ),
            'typography_line_height' => array( 'size' => 1.1, 'unit' => 'em' ),
            'typography_letter_spacing' => array( 'size' => -2, 'unit' => 'px' ),
            'typography_font_size_mobile' => array( 'size' => 26, 'unit' => 'px' ),
            '__globals__' => array( 'link_color' => 'globals/colors?id=user999' ),
        ),
        'elements' => array(),
    ),
    array(
        'id' => 'heading2', 'elType' => 'widget', 'widgetType' => 'heading',
        'settings' => array(
            'title_color' => '#131417',
            'typography_typography' => 'custom',
            'typography_font_family' => 'Geist',
            'typography_font_weight' => '600',
            'typography_font_size' => array( 'size' => 34, 'unit' => 'px' ),
            'typography_line_height' => array( 'size' => 1.1, 'unit' => 'em' ),
            'typography_letter_spacing' => array( 'size' => -2, 'unit' => 'px' ),
            'typography_font_size_mobile' => array( 'size' => 25, 'unit' => 'px' ),
        ),
        'elements' => array(),
    ),
    array(
        'id' => 'container1', 'elType' => 'container',
        'settings' => array( 'background_color' => '#5b5955' ),
        'elements' => array(),
    ),
    array(
        'id' => 'user-bound', 'elType' => 'widget', 'widgetType' => 'heading',
        'settings' => array( 'title_color' => '#131417', '__globals__' => array( 'title_color' => 'globals/colors?id=user-owned' ) ),
        'elements' => array(),
    ),
);

$bound = $bridge->apply_v3_references( $elements, $sync );
dc_assert( 'globals/colors?id=ink1234' === ( $bound[0]['settings']['__globals__']['title_color'] ?? '' ), 'Matching color is linked to its Elementor global' );
dc_assert( 'globals/typography?id=type123' === ( $bound[0]['settings']['__globals__']['typography_typography'] ?? '' ), 'Alias-based exact typography is linked to its Elementor global' );
dc_assert( 'globals/colors?id=user999' === ( $bound[0]['settings']['__globals__']['link_color'] ?? '' ), 'Existing user-owned global references are preserved' );
dc_assert( empty( $bound[1]['settings']['__globals__']['typography_typography'] ), 'Mismatched responsive typography is not bound to a destructive full preset' );
dc_assert( 'globals/colors?id=copy123' === ( $bound[2]['settings']['__globals__']['background_color'] ?? '' ), 'Nested container background receives the matching color reference' );
dc_assert( 26 === ( $bound[0]['settings']['typography_font_size_mobile']['size'] ?? null ), 'Responsive local overrides survive global desktop typography binding' );
dc_assert( 'globals/colors?id=user-owned' === ( $bound[3]['settings']['__globals__']['title_color'] ?? '' ), 'Existing same-control user global binding is never reassigned implicitly' );

dc_finish( 'Global style bridge' );
