<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
dc_require( array( 'core/control-schema-registry.php' ) );

$adapter_file = DESIGN_CORE_ELEMENTOR_PATH . 'core/widget-control-adapters.php';
if ( ! file_exists( $adapter_file ) ) {
    dc_assert( false, 'Widget-specific control adapters exist' );
    dc_finish( 'Widget control adapters' );
}
require_once $adapter_file;
require_once DESIGN_CORE_ELEMENTOR_PATH . 'core/scoped-css-fallback.php';

$schemas = array(
    'container:' => array(
        'flex_direction' => array( 'type' => 'choose', 'responsive' => true ),
        'flex_direction_tablet' => array( 'type' => 'choose', 'responsive' => true ),
        'flex_direction_mobile' => array( 'type' => 'choose', 'responsive' => true ),
        'flex_justify_content' => array( 'type' => 'choose', 'responsive' => true ),
        'flex_align_items' => array( 'type' => 'choose', 'responsive' => true ),
        'flex_gap' => array( 'type' => 'gaps', 'responsive' => true ),
        'padding' => array( 'type' => 'dimensions', 'responsive' => true ),
        'background_background' => array( 'type' => 'choose' ),
        'background_color' => array( 'type' => 'color' ),
        'background_image' => array( 'type' => 'media' ),
        'background_size' => array( 'type' => 'select', 'responsive' => true ),
        'background_position' => array( 'type' => 'select', 'responsive' => true ),
        'background_hover_background' => array( 'type' => 'choose' ),
        'background_hover_color' => array( 'type' => 'color' ),
        'background_hover_transition' => array( 'type' => 'slider' ),
        'border_radius' => array( 'type' => 'dimensions', 'responsive' => true ),
        'content_width' => array( 'type' => 'select' ),
        'html_tag' => array( 'type' => 'select' ),
        'css_classes' => array( 'type' => 'text' ),
        'hide_mobile' => array( 'type' => 'switcher' ),
        'hide_laptop' => array( 'type' => 'switcher' ),
        'border_border' => array( 'type' => 'select' ),
        'border_color' => array( 'type' => 'color' ),
        'border_width' => array( 'type' => 'dimensions', 'responsive' => true ),
        'custom_css' => array( 'type' => 'code' ),
    ),
    'widget:heading' => array(
        'title_color' => array( 'type' => 'color' ),
        'typography_typography' => array( 'type' => 'popover_toggle' ),
        'typography_font_family' => array( 'type' => 'font' ),
        'typography_font_weight' => array( 'type' => 'select' ),
        'typography_font_size' => array( 'type' => 'slider', 'responsive' => true ),
        'typography_font_size_tablet' => array( 'type' => 'slider', 'responsive' => true ),
        'typography_font_size_mobile' => array( 'type' => 'slider', 'responsive' => true ),
        'typography_line_height' => array( 'type' => 'slider', 'responsive' => true ),
        '_margin' => array( 'type' => 'dimensions', 'responsive' => true ),
        '_element_width' => array( 'type' => 'select', 'responsive' => true ),
        'custom_css' => array( 'type' => 'code' ),
    ),
    'widget:text-editor' => array(
        'text_color' => array( 'type' => 'color' ),
        'typography_typography' => array( 'type' => 'popover_toggle' ),
        'typography_font_size' => array( 'type' => 'slider', 'responsive' => true ),
        '_element_width' => array( 'type' => 'select', 'responsive' => true ),
        '_element_custom_width' => array( 'type' => 'slider', 'responsive' => true ),
        'custom_css' => array( 'type' => 'code' ),
    ),
    'widget:button' => array(
        'button_text_color' => array( 'type' => 'color' ),
        'background_color' => array( 'type' => 'color' ),
        'text_padding' => array( 'type' => 'dimensions', 'responsive' => true ),
        'align' => array( 'type' => 'choose', 'responsive' => true ),
        'border_radius' => array( 'type' => 'dimensions', 'responsive' => true ),
    ),
    'widget:image' => array(
        'align' => array( 'type' => 'choose', 'responsive' => true ),
        'width' => array( 'type' => 'slider', 'responsive' => true ),
        'height' => array( 'type' => 'slider', 'responsive' => true ),
        'object-fit' => array( 'type' => 'select', 'responsive' => true ),
        'object-position' => array( 'type' => 'select', 'responsive' => true ),
        'image_border_radius' => array( 'type' => 'dimensions', 'responsive' => true ),
    ),
    'widget:form' => array(
        '_css_classes' => array( 'type' => 'text' ),
        'column_gap' => array( 'type' => 'slider' ),
        'row_gap' => array( 'type' => 'slider' ),
        'field_text_color' => array( 'type' => 'color' ),
        'field_background_color' => array( 'type' => 'color' ),
        'field_border_color' => array( 'type' => 'color' ),
        'field_border_width' => array( 'type' => 'dimensions' ),
        'field_border_radius' => array( 'type' => 'dimensions' ),
        'field_typography_typography' => array( 'type' => 'popover_toggle' ),
        'field_typography_font_family' => array( 'type' => 'font' ),
        'field_typography_font_size' => array( 'type' => 'slider', 'responsive' => true ),
        'field_typography_font_weight' => array( 'type' => 'select' ),
        'button_background_color' => array( 'type' => 'color' ),
        'button_text_color' => array( 'type' => 'color' ),
        'button_border_radius' => array( 'type' => 'dimensions' ),
        'button_text_padding' => array( 'type' => 'dimensions' ),
        'button_typography_typography' => array( 'type' => 'popover_toggle' ),
        'button_typography_font_family' => array( 'type' => 'font' ),
        'button_typography_font_size' => array( 'type' => 'slider', 'responsive' => true ),
        'button_typography_font_weight' => array( 'type' => 'select' ),
        'button_width' => array( 'type' => 'select', 'responsive' => true ),
    ),
);
$provider = static function ( $element_type, $widget_type ) use ( $schemas ) {
    return array( 'controls' => array(), 'style_controls' => $schemas[ $element_type . ':' . $widget_type ] ?? array() );
};
$registry = new Design_Core_Elementor_Control_Schema_Registry( $provider );
$mapper = new Design_Core_Elementor_Widget_Control_Mapper( $registry );

$heading = array(
    'layout' => array( 'width' => array( 'value' => 640, 'unit' => 'px' ) ),
    'style' => array(
        'color' => '#123456', 'font_family' => 'Poppins', 'font_weight' => '600',
        'font_size' => array( 'value' => 48, 'unit' => 'px' ),
        'line_height' => array( 'value' => 1.1, 'unit' => 'em' ),
    ),
    'spacing' => array( 'margin' => array( 'top' => 0, 'right' => 0, 'bottom' => 24, 'left' => 0, 'unit' => 'px' ) ),
    'responsive' => array(
        'tablet' => array( 'font_size' => array( 'value' => 40, 'unit' => 'px' ) ),
        'mobile' => array( 'font_size' => array( 'value' => 32, 'unit' => 'px' ) ),
    ),
);
$result = $mapper->map( 'widget', 'heading', $heading );
dc_assert( '#123456' === ( $result['settings']['title_color'] ?? '' ), 'Heading uses its native title_color control' );
dc_assert( 'custom' === ( $result['settings']['typography_typography'] ?? '' ), 'Typography group is activated natively' );
dc_assert( 'Poppins' === ( $result['settings']['typography_font_family'] ?? '' ), 'Font family is preserved' );
dc_assert( '600' === ( $result['settings']['typography_font_weight'] ?? '' ), 'Font weight is preserved' );
dc_assert( 48 === ( $result['settings']['typography_font_size']['size'] ?? null ), 'Desktop font size is encoded for Elementor' );
dc_assert( 40 === ( $result['settings']['typography_font_size_tablet']['size'] ?? null ), 'Tablet font size uses a responsive control' );
dc_assert( 32 === ( $result['settings']['typography_font_size_mobile']['size'] ?? null ), 'Mobile font size uses a responsive control' );
dc_assert( '24' === ( $result['settings']['_margin']['bottom'] ?? null ), 'Explicit exceptional widget margin uses the widget Advanced control' );
$zero_margin_heading = $heading;
$zero_margin_heading['spacing']['margin'] = array( 'top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0, 'unit' => 'px' );
$zero_margin_result = $mapper->map( 'widget', 'heading', $zero_margin_heading );
dc_assert( ! isset( $zero_margin_result['settings']['_margin'] ), 'Zero margin is omitted instead of persisting a redundant widget Margin control' );
dc_assert( ! isset( $result['settings']['_element_width'] ) && false !== strpos( $result['settings']['custom_css'] ?? '', 'width: 640px;' ), 'A select control is never corrupted; numeric width falls back to scoped CSS on the heading' );

$container = array(
    'source' => array( 'tag' => 'article', 'classes' => array(), 'attributes' => array() ),
    'semantic' => array( 'layout_mode' => 'boxed', 'container_role' => 'content', 'hidden_on' => array( 'mobile', 'laptop' ) ),
    'layout' => array(
        'content_width' => 'full', 'direction' => 'row', 'justify' => 'space-between', 'align' => 'center',
        'gap' => array( 'value' => 24, 'unit' => 'px' ),
        'max_width' => array( 'value' => 1184, 'unit' => 'px' ),
    ),
    'style' => array(
        'background' => '#ffffff', 'background_image' => array( 'url' => 'https://example.test/media/hero.webp', 'id' => 42 ), 'background_size' => 'cover', 'background_position' => 'center center', 'radius' => array( 'value' => 12, 'unit' => 'px' ),
        'border_style' => 'solid', 'border_color' => '#e2e0db',
        'border_width' => array( 'top' => 0, 'right' => 0, 'bottom' => 1, 'left' => 0, 'unit' => 'px' ),
    ),
    'spacing' => array( 'padding' => array( 'value' => 32, 'unit' => 'px' ) ),
    'responsive' => array( 'tablet' => array( 'direction' => 'column' ) ),
    'interaction' => array( 'hover' => array( 'background' => '#fffaf0', 'duration' => array( 'value' => 0.2, 'unit' => '' ) ) ),
);
$result = $mapper->map( 'container', '', $container );
dc_assert( 'row' === ( $result['settings']['flex_direction'] ?? '' ), 'Container direction uses native control' );
dc_assert( 'article' === ( $result['settings']['html_tag'] ?? '' ), 'Structural source tag uses Elementor native semantic HTML tag control' );
dc_assert( 'dc-global-container' === ( $result['settings']['css_classes'] ?? '' ), 'Native boxed content owner receives only the governed responsive-gutter class' );
dc_assert( 'hidden-mobile' === ( $result['settings']['hide_mobile'] ?? '' ), 'Semantic mobile visibility uses the native Elementor responsive switcher' );
dc_assert( 'hidden-laptop' === ( $result['settings']['hide_laptop'] ?? '' ), 'Semantic laptop visibility uses the active Elementor breakpoint switcher' );
dc_assert( 'solid' === ( $result['settings']['border_border'] ?? '' ) && '#e2e0db' === ( $result['settings']['border_color'] ?? '' ), 'Container border mode and color use native controls' );
dc_assert( '1' === ( $result['settings']['border_width']['bottom'] ?? null ), 'Per-side row divider width uses native Dimensions control' );
dc_assert( 'column' === ( $result['settings']['flex_direction_tablet'] ?? '' ), 'Container responsive direction is native' );
dc_assert( 24 === ( $result['settings']['flex_gap']['column'] ?? null ) && 24 === ( $result['settings']['flex_gap']['row'] ?? null ), 'Container gap is encoded for Elementor gaps control' );
dc_assert( '#ffffff' === ( $result['settings']['background_color'] ?? '' ) && 'classic' === ( $result['settings']['background_background'] ?? '' ), 'Container background activates native classic mode and color' );
dc_assert( 42 === ( $result['settings']['background_image']['id'] ?? null ) && 'cover' === ( $result['settings']['background_size'] ?? '' ) && 'center center' === ( $result['settings']['background_position'] ?? '' ), 'Canonical background image, crop, and focal position map through native container controls' );
dc_assert( '32' === ( $result['settings']['padding']['top'] ?? null ), 'Container padding is native' );
dc_assert( 'boxed' === ( $result['settings']['content_width'] ?? '' ), 'Constrained content ownership overrides stale input with native Elementor Boxed content width' );
dc_assert( false === strpos( $result['settings']['custom_css'] ?? '', 'max-width:' ), 'Native Boxed content inherits Global Content Width without a local max-width fallback' );
dc_assert( 'classic' === ( $result['settings']['background_hover_background'] ?? '' ) && '#fffaf0' === ( $result['settings']['background_hover_color'] ?? '' ), 'Container hover background uses native Elementor hover controls' );

$non_owner = $mapper->map( 'container', '', array(
    'source' => array( 'tag' => 'div', 'classes' => array( 'dc-global-container', 'local-structure' ), 'attributes' => array() ),
    'semantic' => array( 'layout_mode' => 'mixed', 'container_role' => 'structural' ),
    'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'interaction' => array(),
) );
dc_assert( 'local-structure' === ( $non_owner['settings']['css_classes'] ?? '' ), 'Reserved global container class is stripped from non-owner source classes' );
$unvalidated_boxed = $mapper->map( 'container', '', array(
    'source' => array( 'tag' => 'div', 'classes' => array( 'local-box' ), 'attributes' => array() ),
    'semantic' => array( 'layout_mode' => 'boxed', 'container_role' => 'structural' ),
    'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'interaction' => array(),
) );
dc_assert( 'local-box' === ( $unvalidated_boxed['settings']['css_classes'] ?? '' ), 'Boxed mode cannot self-assert global ownership without the compatible surface-content role' );

$full_surface = $mapper->map( 'container', '', array(
    'source' => array( 'tag' => 'section', 'classes' => array( 'dc-global-container', 'local-surface' ), 'attributes' => array() ),
    'semantic' => array( 'layout_mode' => 'fullwidth', 'container_role' => 'surface' ),
    'layout' => array( 'content_width' => 'boxed', 'max_width' => array( 'value' => 1184, 'unit' => 'px' ) ),
    'style' => array(), 'spacing' => array(), 'responsive' => array(), 'interaction' => array(),
) );
dc_assert( 'full' === ( $full_surface['settings']['content_width'] ?? '' ), 'Fullwidth surface overrides stale input with native Elementor Full Width content' );
dc_assert( false === strpos( $full_surface['settings']['custom_css'] ?? '', 'max-width:' ), 'Fullwidth surface never persists a local max-width fallback' );
dc_assert( 'local-surface' === ( $full_surface['settings']['css_classes'] ?? '' ), 'Fullwidth surface cannot retain the reserved boxed gutter class' );

$button = $mapper->map( 'widget', 'button', array(
    'layout' => array( 'align' => 'center' ),
    'style' => array( 'color' => '#ffffff', 'background' => '#111111', 'radius' => array( 'value' => 8, 'unit' => 'px' ) ),
    'spacing' => array( 'padding' => array( 'top' => 12, 'right' => 20, 'bottom' => 12, 'left' => 20, 'unit' => 'px' ) ),
    'responsive' => array(),
) );
dc_assert( '#ffffff' === ( $button['settings']['button_text_color'] ?? '' ), 'Button uses button_text_color instead of a generic color guess' );
dc_assert( '20' === ( $button['settings']['text_padding']['right'] ?? null ), 'Button inner padding uses text_padding' );

$text = $mapper->map( 'widget', 'text-editor', array(
    'layout' => array( 'max_width' => array( 'value' => 760, 'unit' => 'px' ) ),
    'style' => array(), 'spacing' => array(), 'responsive' => array(),
) );
dc_assert( 760 === ( $text['settings']['_element_custom_width']['size'] ?? null ) && 'initial' === ( $text['settings']['_element_width'] ?? '' ) && empty( $text['settings']['custom_css'] ), 'Text measure activates the native custom-width mode and slider before scoped CSS' );

$image = $mapper->map( 'widget', 'image', array(
    'layout' => array( 'align' => 'left', 'width' => array( 'value' => 320, 'unit' => 'px' ), 'height' => array( 'value' => 180, 'unit' => 'px' ) ),
    'style' => array( 'object_fit' => 'cover', 'object_position' => '70% 50%', 'radius' => array( 'value' => 16, 'unit' => 'px' ) ),
    'spacing' => array(), 'responsive' => array(),
) );
dc_assert( 'cover' === ( $image['settings']['object-fit'] ?? '' ), 'Image object fit uses the registered image control' );
dc_assert( '70% 50%' === ( $image['settings']['object-position'] ?? '' ), 'Image focal position uses the registered responsive image control' );
dc_assert( 'left' === ( $image['settings']['align'] ?? '' ), 'Image alignment uses the image widget native control' );
dc_assert( '16' === ( $image['settings']['image_border_radius']['left'] ?? null ), 'Image radius uses image-specific control' );

$form = $mapper->map( 'widget', 'form', array(
    'source' => array( 'tag' => 'form', 'classes' => array( 'dc-conversion-form' ), 'attributes' => array() ),
    'layout' => array( 'column_gap' => array( 'value' => 10, 'unit' => 'px' ), 'row_gap' => array( 'value' => 10, 'unit' => 'px' ), 'button_width' => '25' ),
    'style' => array(
        'field_color' => '#ffffff', 'field_background' => 'rgba(255,255,255,.12)', 'field_border_color' => '#ffffff',
        'field_border_width' => array( 'value' => 1, 'unit' => 'px' ), 'field_radius' => array( 'value' => 6, 'unit' => 'px' ),
        'field_font_family' => 'Poppins', 'field_font_size' => array( 'value' => 13.5, 'unit' => 'px' ), 'field_font_weight' => '400',
        'button_background' => '#fbc925', 'button_color' => '#131417', 'button_radius' => array( 'value' => 6, 'unit' => 'px' ),
        'button_padding' => array( 'top' => 14, 'right' => 26, 'bottom' => 14, 'left' => 26, 'unit' => 'px' ),
        'button_font_family' => 'Poppins', 'button_font_size' => array( 'value' => 13.5, 'unit' => 'px' ), 'button_font_weight' => '600',
    ),
    'spacing' => array(),
    'responsive' => array( 'mobile' => array( 'layout' => array( 'button_width' => '100' ) ) ),
) );
dc_assert( '#ffffff' === ( $form['settings']['field_text_color'] ?? '' ) && '#fbc925' === ( $form['settings']['button_background_color'] ?? '' ), 'Form adapter maps field and submit colors to Elementor Pro native controls' );
dc_assert( 'custom' === ( $form['settings']['field_typography_typography'] ?? '' ) && 'custom' === ( $form['settings']['button_typography_typography'] ?? '' ), 'Form adapter activates both native typography groups' );
dc_assert( '25' === ( $form['settings']['button_width'] ?? '' ) && '100' === ( $form['settings']['button_width_mobile'] ?? '' ), 'Form adapter maps responsive native submit widths' );
dc_assert( 'dc-conversion-form' === ( $form['settings']['_css_classes'] ?? '' ), 'Form adapter preserves its editor-facing class through the runtime common control' );

dc_finish( 'Widget control adapters' );
