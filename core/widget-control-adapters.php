<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Control_Value_Encoder {
    public function encode( $value, $type ) {
        if ( 'dimension' === $type ) { return $this->dimension( $value ); }
        if ( 'dimensions' === $type ) { return $this->dimensions( $value ); }
        if ( 'gaps' === $type ) { return $this->gaps( $value ); }
        return $value;
    }

    private function dimension( $value ) {
        if ( is_array( $value ) && array_key_exists( 'value', $value ) ) {
            return array( 'size' => $value['value'], 'unit' => $value['unit'] ?? 'px' );
        }
        if ( is_numeric( $value ) ) { return array( 'size' => $value, 'unit' => 'px' ); }
        return $value;
    }

    private function dimensions( $value ) {
        if ( ! is_array( $value ) ) { return $value; }
        if ( array_key_exists( 'value', $value ) ) {
            $size = (string) $value['value'];
            return array( 'unit' => $value['unit'] ?? 'px', 'top' => $size, 'right' => $size, 'bottom' => $size, 'left' => $size, 'isLinked' => true );
        }
        $top = (string) ( $value['top'] ?? 0 );
        $right = (string) ( $value['right'] ?? $top );
        $bottom = (string) ( $value['bottom'] ?? $top );
        $left = (string) ( $value['left'] ?? $right );
        return array( 'unit' => $value['unit'] ?? 'px', 'top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left, 'isLinked' => count( array_unique( array( $top, $right, $bottom, $left ) ) ) === 1 );
    }

    private function gaps( $value ) {
        if ( is_array( $value ) && array_key_exists( 'value', $value ) ) {
            return array( 'column' => $value['value'], 'row' => $value['value'], 'isLinked' => true, 'unit' => $value['unit'] ?? 'px' );
        }
        if ( is_array( $value ) ) {
            $column = $value['column'] ?? $value['right'] ?? 0;
            $row = $value['row'] ?? $value['top'] ?? $column;
            return array( 'column' => $column, 'row' => $row, 'isLinked' => $column === $row, 'unit' => $value['unit'] ?? 'px' );
        }
        return $value;
    }
}

interface Design_Core_Elementor_Widget_Control_Adapter_Interface {
    public function supports( $element_type, $widget_type );
    public function map( $node );
}

abstract class Design_Core_Elementor_Abstract_Control_Adapter implements Design_Core_Elementor_Widget_Control_Adapter_Interface {
    protected $registry;
    protected $encoder;
    protected $element_type;
    protected $widget_type;

    public function __construct( Design_Core_Elementor_Control_Schema_Registry $registry ) {
        $this->registry = $registry;
        $this->encoder = new Design_Core_Elementor_Control_Value_Encoder();
    }

    public function supports( $element_type, $widget_type ) {
        return $this->element_type === $element_type && $this->widget_type === $widget_type;
    }

    public function map( $node ) {
        $desktop = array_merge( $node['layout'] ?? array(), $node['style'] ?? array(), $node['spacing'] ?? array() );
        $settings = array(); $native = array(); $unsupported = array();
        $this->map_values( $desktop, '', $settings, $native, $unsupported );
        foreach ( $node['responsive'] ?? array() as $device => $values ) {
            if ( ! in_array( $device, array( 'desktop', 'widescreen', 'laptop', 'tablet_extra', 'tablet', 'mobile_extra', 'mobile' ), true ) ) { continue; }
            if ( 'desktop' === $device ) { continue; }
            $this->map_values( $this->flatten_responsive_values( $values ), '_' . $device, $settings, $native, $unsupported );
        }
        return array( 'settings' => $settings, 'native' => array_values( array_unique( $native ) ), 'custom_css' => array(), 'unsupported' => array_values( array_unique( $unsupported ) ) );
    }

    protected function flatten_responsive_values( $values ) {
        if ( ! is_array( $values ) ) { return array(); }
        if ( isset( $values['layout'] ) || isset( $values['style'] ) || isset( $values['spacing'] ) ) {
            return array_merge(
                is_array( $values['layout'] ?? null ) ? $values['layout'] : array(),
                is_array( $values['style'] ?? null ) ? $values['style'] : array(),
                is_array( $values['spacing'] ?? null ) ? $values['spacing'] : array()
            );
        }
        return $values;
    }

    protected function map_values( $values, $suffix, &$settings, &$native, &$unsupported ) {
        foreach ( $this->semantic_map() as $source => $definition ) {
            if ( ! array_key_exists( $source, $values ) ) { continue; }
            // Zero-effect declarations (box-sizing, content) are dropped here,
            // never recorded as unsupported and never emitted as custom CSS.
            if ( class_exists( 'Design_Core_Elementor_Binding_Governor' ) && Design_Core_Elementor_Binding_Governor::is_trivial_css( $source ) ) { continue; }
            if ( 'margin' === $source && $this->is_zero_margin( $values[ $source ] ) ) { continue; }
            $candidates = $definition[0]; $encoding = $definition[1] ?? 'raw'; $expected = $definition[2] ?? '';
            $control_id = $this->registry->first_supported( $this->element_type, $this->widget_type, $candidates, $expected );
            if ( ! $control_id ) { $unsupported[] = $source; continue; }
            if ( $suffix && ! $this->registry->is_responsive( $this->element_type, $this->widget_type, $control_id ) ) { $unsupported[] = $source . $suffix; continue; }
            $target = $control_id . $suffix;
            $settings[ $target ] = $this->encoder->encode( $values[ $source ], $encoding );
            $native[] = $target;
        }
        $this->map_typography( $values, $suffix, $settings, $native, $unsupported );
    }

    protected function is_zero_margin( $value ) {
        if ( ! is_array( $value ) ) { return false; }
        if ( array_key_exists( 'value', $value ) ) { return is_numeric( $value['value'] ) && 0.0 === (float) $value['value']; }
        $found = false;
        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
            if ( ! array_key_exists( $side, $value ) ) { continue; }
            $found = true;
            if ( ! is_numeric( $value[ $side ] ) || 0.0 !== (float) $value[ $side ] ) { return false; }
        }
        return $found;
    }

    protected function map_typography( $values, $suffix, &$settings, &$native, &$unsupported ) {
        $typography = array(
            'font_family' => array( 'typography_font_family', 'raw', 'font' ),
            'font_weight' => array( 'typography_font_weight', 'raw', 'select' ),
            'font_size' => array( 'typography_font_size', 'dimension', 'slider' ),
            'line_height' => array( 'typography_line_height', 'dimension', 'slider' ),
            'letter_spacing' => array( 'typography_letter_spacing', 'dimension', 'slider' ),
            'text_transform' => array( 'typography_text_transform', 'raw', 'select' ),
            'font_style' => array( 'typography_font_style', 'raw', 'select' ),
            'text_decoration' => array( 'typography_text_decoration', 'raw', 'select' ),
        );
        $mapped = false;
        foreach ( $typography as $source => $definition ) {
            if ( ! array_key_exists( $source, $values ) ) { continue; }
            $control_id = $this->registry->first_supported( $this->element_type, $this->widget_type, array( $definition[0] ), $definition[2] ?? '' );
            if ( ! $control_id || ( $suffix && ! $this->registry->is_responsive( $this->element_type, $this->widget_type, $control_id ) ) ) { $unsupported[] = $source . $suffix; continue; }
            $target = $control_id . $suffix;
            $settings[ $target ] = $this->encoder->encode( $values[ $source ], $definition[1] );
            $native[] = $target; $mapped = true;
        }
        if ( $mapped && ! $suffix && $this->registry->has( $this->element_type, $this->widget_type, 'typography_typography' ) ) {
            $settings['typography_typography'] = 'custom'; $native[] = 'typography_typography';
        }
    }

    abstract protected function semantic_map();

    /**
     * layout.align records flex/grid align-items, never text alignment. Leaf
     * widgets must not inherit it as a text-align control: only style.align
     * (from text-align) may feed text alignment. Containers keep layout.align
     * for flex_align_items -- this helper is for text-content widgets only.
     */
    protected function without_flex_align( array $node ) {
        if ( ! isset( $node['style']['align'] ) ) { unset( $node['layout']['align'] ); }
        return $node;
    }
}

class Design_Core_Elementor_Container_Control_Adapter extends Design_Core_Elementor_Abstract_Control_Adapter {
    protected $element_type = 'container'; protected $widget_type = '';

    public function map( $node ) {
        $result = parent::map( $node );
        if ( isset( $result['settings']['background_color'] ) || isset( $result['settings']['background_image'] ) ) {
            $background_mode = $this->registry->first_supported( $this->element_type, $this->widget_type, array( 'background_background' ), 'choose' );
            if ( $background_mode ) {
                $result['settings'][ $background_mode ] = 'classic';
                $result['native'][] = $background_mode;
            } else {
                $result['unsupported'][] = 'background_mode';
            }
        }
        $hover = is_array( $node['interaction']['hover'] ?? null ) ? $node['interaction']['hover'] : array();
        if ( array_key_exists( 'background', $hover ) ) {
            $mode = $this->registry->first_supported( $this->element_type, $this->widget_type, array( 'background_hover_background' ), 'choose' );
            $color = $this->registry->first_supported( $this->element_type, $this->widget_type, array( 'background_hover_color' ), 'color' );
            if ( $mode && $color ) {
                $result['settings'][ $mode ] = 'classic';
                $result['settings'][ $color ] = $hover['background'];
                $result['native'][] = $mode;
                $result['native'][] = $color;
            } else {
                $result['unsupported'][] = 'hover_background';
            }
        }
        if ( isset( $hover['duration'] ) ) {
            $transition = $this->registry->first_supported( $this->element_type, $this->widget_type, array( 'background_hover_transition' ), 'slider' );
            if ( $transition ) {
                $result['settings'][ $transition ] = $this->encoder->encode( $hover['duration'], 'dimension' );
                $result['native'][] = $transition;
            } else {
                $result['unsupported'][] = 'hover_duration';
            }
        }
        $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
        if ( in_array( $tag, array( 'section', 'article', 'header', 'footer', 'main', 'nav', 'aside' ), true ) ) {
            $html_tag = $this->registry->first_supported( $this->element_type, $this->widget_type, array( 'html_tag' ), 'select' );
            if ( $html_tag ) {
                $result['settings'][ $html_tag ] = $tag;
                $result['native'][] = $html_tag;
            } else {
                $result['unsupported'][] = 'semantic_html_tag';
            }
        }
        $hidden_on = is_array( $node['semantic']['hidden_on'] ?? null ) ? $node['semantic']['hidden_on'] : array();
        foreach ( array( 'desktop', 'widescreen', 'laptop', 'tablet_extra', 'tablet', 'mobile_extra', 'mobile' ) as $device ) {
            if ( ! in_array( $device, $hidden_on, true ) ) { continue; }
            $control = $this->registry->first_supported( $this->element_type, $this->widget_type, array( 'hide_' . $device ), 'switcher' );
            if ( $control ) {
                $result['settings'][ $control ] = 'hidden-' . $device;
                $result['native'][] = $control;
            } else {
                $result['unsupported'][] = 'visibility_' . $device;
            }
        }
        return $result;
    }

    protected function semantic_map() { return array(
        'content_width' => array( array( 'content_width' ), 'raw', 'select' ),
        'direction' => array( array( 'flex_direction' ), 'raw' ),
        'wrap' => array( array( 'flex_wrap' ), 'raw' ),
        'justify' => array( array( 'flex_justify_content' ), 'raw' ),
        'align' => array( array( 'flex_align_items' ), 'raw' ),
        'gap' => array( array( 'flex_gap' ), 'gaps', 'gaps' ),
        'width' => array( array( 'width' ), 'dimension', 'slider' ),
        'max_width' => array( array( 'max_width' ), 'dimension', 'slider' ),
        'min_height' => array( array( 'min_height' ), 'dimension', 'slider' ),
        'position' => array( array( 'position' ), 'raw' ),
        'z_index' => array( array( 'z_index' ), 'raw' ),
        'background' => array( array( 'background_color' ), 'raw', 'color' ),
        'background_image' => array( array( 'background_image' ), 'raw', 'media' ),
        'background_size' => array( array( 'background_size' ), 'raw', 'select' ),
        'background_position' => array( array( 'background_position' ), 'raw', 'select' ),
        'border_style' => array( array( 'border_border' ), 'raw', 'select' ),
        'border_color' => array( array( 'border_color' ), 'raw', 'color' ),
        'border_width' => array( array( 'border_width' ), 'dimensions' ),
        'padding' => array( array( 'padding' ), 'dimensions' ),
        'margin' => array( array( 'margin' ), 'dimensions' ),
        'radius' => array( array( 'border_radius' ), 'dimensions' ),
    ); }
}

class Design_Core_Elementor_Heading_Control_Adapter extends Design_Core_Elementor_Abstract_Control_Adapter {
    protected $element_type = 'widget'; protected $widget_type = 'heading';
    public function map( $node ) { return parent::map( $this->without_flex_align( $node ) ); }
    protected function semantic_map() { return array(
        'color' => array( array( 'title_color', 'text_color' ), 'raw', 'color' ),
        'align' => array( array( 'align' ), 'raw' ),
        'width' => array( array( 'width' ), 'dimension', 'slider' ),
        'padding' => array( array( '_padding', 'padding' ), 'dimensions' ),
        'margin' => array( array( '_margin', 'margin' ), 'dimensions' ),
    ); }
}

class Design_Core_Elementor_Text_Control_Adapter extends Design_Core_Elementor_Abstract_Control_Adapter {
    protected $element_type = 'widget'; protected $widget_type = 'text-editor';

    public function map( $node ) {
        $node = $this->without_flex_align( $node );
        $result = parent::map( $node );
        if ( isset( $result['settings']['_element_custom_width'] ) && $this->registry->first_supported( $this->element_type, $this->widget_type, array( '_element_width' ), 'select' ) ) {
            $result['settings']['_element_width'] = 'initial';
            $result['native'][] = '_element_width';
        }
        return $result;
    }

    protected function semantic_map() { return array(
        'max_width' => array( array( '_element_custom_width', 'max_width' ), 'dimension', 'slider' ),
        'color' => array( array( 'text_color', 'title_color' ), 'raw', 'color' ),
        'align' => array( array( 'align' ), 'raw' ),
        'padding' => array( array( '_padding', 'padding' ), 'dimensions' ),
        'margin' => array( array( '_margin', 'margin' ), 'dimensions' ),
    ); }
}

class Design_Core_Elementor_Button_Control_Adapter extends Design_Core_Elementor_Abstract_Control_Adapter {
    protected $element_type = 'widget'; protected $widget_type = 'button';
    public function map( $node ) {
        $node = $this->without_flex_align( $node );
        $result = parent::map( $node );
        // background_color is conditional on a classic background mode. The
        // source never states the mode explicitly, so declare it or the live
        // schema drops the designed background during governance.
        if ( isset( $result['settings']['background_color'] ) && $this->registry->first_supported( $this->element_type, $this->widget_type, array( 'background_background' ), 'choose' ) ) {
            $result['settings']['background_background'] = 'classic';
            $result['native'][] = 'background_background';
        }
        // The button widget schema exposes no min-height control. Express the
        // authored minimum height on the rendered button itself: min-height on
        // the widget wrapper would not size the inner .elementor-button.
        $min = $node['layout']['min_height'] ?? null;
        if ( is_array( $min ) && isset( $min['value'] ) && is_numeric( $min['value'] ) ) {
            $unit = in_array( $min['unit'] ?? '', array( 'px', '%', 'em', 'rem', 'vh' ), true ) ? (string) $min['unit'] : 'px';
            $css = trim( (string) ( $result['settings']['custom_css'] ?? '' ) );
            if ( '' !== $css ) { $css .= "\n"; }
            $result['settings']['custom_css'] = $css . 'selector .elementor-button{min-height:' . $min['value'] . $unit . ';}';
        }
        return $result;
    }
    protected function semantic_map() { return array(
        'color' => array( array( 'button_text_color', 'text_color' ), 'raw', 'color' ),
        'background' => array( array( 'background_color' ), 'raw', 'color' ),
        'align' => array( array( 'align' ), 'raw' ),
        'padding' => array( array( 'text_padding', '_padding', 'padding' ), 'dimensions' ),
        'margin' => array( array( '_margin', 'margin' ), 'dimensions' ),
        'radius' => array( array( 'border_radius' ), 'dimensions' ),
    ); }
}

class Design_Core_Elementor_Image_Control_Adapter extends Design_Core_Elementor_Abstract_Control_Adapter {
    protected $element_type = 'widget'; protected $widget_type = 'image';
    protected function semantic_map() { return array(
        'align' => array( array( 'align' ), 'raw', 'choose' ),
        'width' => array( array( 'width' ), 'dimension' ),
        'height' => array( array( 'height' ), 'dimension' ),
        'object_fit' => array( array( 'object-fit', 'object_fit' ), 'raw' ),
        'object_position' => array( array( 'object-position', 'object_position' ), 'raw' ),
        'radius' => array( array( 'image_border_radius', 'border_radius' ), 'dimensions' ),
        'margin' => array( array( '_margin', 'margin' ), 'dimensions' ),
    ); }
}

/** Maps canonical lead-capture styling to Elementor Pro Form controls. */
class Design_Core_Elementor_Form_Control_Adapter extends Design_Core_Elementor_Abstract_Control_Adapter {
    protected $element_type = 'widget'; protected $widget_type = 'form';

    public function map( $node ) {
        $result = parent::map( $node );
        foreach ( array( 'field', 'button' ) as $group ) {
            $prefix = $group . '_typography_';
            $mapped = false;
            foreach ( array_keys( $result['settings'] ) as $setting ) {
                if ( 0 === strpos( $setting, $prefix ) && $setting !== $prefix . 'typography' ) { $mapped = true; break; }
            }
            $toggle = $prefix . 'typography';
            if ( $mapped && $this->registry->has( $this->element_type, $this->widget_type, $toggle ) ) {
                $result['settings'][ $toggle ] = 'custom';
                $result['native'][] = $toggle;
            }
        }
        $result['native'] = array_values( array_unique( $result['native'] ) );
        return $result;
    }

    protected function semantic_map() { return array(
        'column_gap' => array( array( 'column_gap' ), 'dimension', 'slider' ),
        'row_gap' => array( array( 'row_gap' ), 'dimension', 'slider' ),
        'button_width' => array( array( 'button_width' ), 'raw', 'select' ),
        'field_color' => array( array( 'field_text_color' ), 'raw', 'color' ),
        'field_background' => array( array( 'field_background_color' ), 'raw', 'color' ),
        'field_border_color' => array( array( 'field_border_color' ), 'raw', 'color' ),
        'field_border_width' => array( array( 'field_border_width' ), 'dimensions' ),
        'field_radius' => array( array( 'field_border_radius' ), 'dimensions' ),
        'field_font_family' => array( array( 'field_typography_font_family' ), 'raw', 'font' ),
        'field_font_size' => array( array( 'field_typography_font_size' ), 'dimension', 'slider' ),
        'field_font_weight' => array( array( 'field_typography_font_weight' ), 'raw', 'select' ),
        'button_background' => array( array( 'button_background_color' ), 'raw', 'color' ),
        'button_color' => array( array( 'button_text_color' ), 'raw', 'color' ),
        'button_radius' => array( array( 'button_border_radius' ), 'dimensions' ),
        'button_padding' => array( array( 'button_text_padding' ), 'dimensions' ),
        'button_font_family' => array( array( 'button_typography_font_family' ), 'raw', 'font' ),
        'button_font_size' => array( array( 'button_typography_font_size' ), 'dimension', 'slider' ),
        'button_font_weight' => array( array( 'button_typography_font_weight' ), 'raw', 'select' ),
    ); }
}

class Design_Core_Elementor_Widget_Control_Mapper {
    private $registry;
    private $adapters;

    public function __construct( Design_Core_Elementor_Control_Schema_Registry $registry = null ) {
        $this->registry = $registry ?: new Design_Core_Elementor_Control_Schema_Registry();
        $this->adapters = array(
            new Design_Core_Elementor_Container_Control_Adapter( $this->registry ),
            new Design_Core_Elementor_Heading_Control_Adapter( $this->registry ),
            new Design_Core_Elementor_Text_Control_Adapter( $this->registry ),
            new Design_Core_Elementor_Button_Control_Adapter( $this->registry ),
            new Design_Core_Elementor_Image_Control_Adapter( $this->registry ),
            new Design_Core_Elementor_Form_Control_Adapter( $this->registry ),
        );
    }

    public function map( $element_type, $widget_type, $node ) {
        if ( ! $this->registry->available( $element_type, $widget_type ) ) {
            return array( 'settings' => array(), 'native' => array(), 'custom_css' => array(), 'unsupported' => array( 'runtime-schema-unavailable:' . sanitize_key( $element_type . '-' . $widget_type ) ) );
        }
        // Typography belongs on text widgets, never on containers: children
        // carry their own analyzed copies, so dropping these here loses nothing
        // and unblocks mapping (their controls do not exist on containers).
        if ( 'container' === $element_type && is_array( $node['style'] ?? null ) ) {
            foreach ( array( 'font_family', 'font_weight', 'font_size', 'line_height', 'letter_spacing', 'text_transform', 'font_style', 'text_decoration' ) as $dropped_typography ) {
                unset( $node['style'][ $dropped_typography ] );
            }
        }
        if ( 'container' === $element_type ) { $node = $this->normalize_container_width_semantics( $node ); $node = $this->normalize_centered_boxed_container( $node ); }
        foreach ( $this->adapters as $adapter ) {
            if ( ! $adapter->supports( $element_type, $widget_type ) ) { continue; }
            $result = $adapter->map( $node );
            $this->map_source_identity( $element_type, $widget_type, $node, $result );
            $fallback_rules = is_array( $node['style']['css_fallback'] ?? null ) ? $node['style']['css_fallback'] : array();
            // Zero-effect declarations (box-sizing, content) are dropped here:
            // Elementor is already border-box and `content` only applies to
            // pseudo-elements, so persisting them only inflates output.
            // Side-specific borders, table internals and offsets have no valid
            // Elementor control on this path; emitting them as custom CSS would
            // fail strict validation, and mapping them onto all-side controls
            // would misrepresent the design -- so they are dropped and reported.
            if ( class_exists( 'Design_Core_Elementor_Binding_Governor' ) ) {
                foreach ( $fallback_rules as $fallback_property => $fallback_value ) {
                    if ( Design_Core_Elementor_Binding_Governor::is_trivial_css( $fallback_property ) || Design_Core_Elementor_Binding_Governor::is_unrepresentable_css( $fallback_property ) ) {
                        unset( $fallback_rules[ $fallback_property ] );
                        $result['dropped_css'][] = (string) $fallback_property;
                    }
                }
            }
            $semantic_fallback = $this->semantic_fallback_rules( $node, $result['unsupported'] );
            $fallback_rules = array_merge( $fallback_rules, $semantic_fallback['rules'] );
            if ( $fallback_rules && class_exists( 'Design_Core_Elementor_Scoped_CSS_Fallback' ) ) {
                $fallback = ( new Design_Core_Elementor_Scoped_CSS_Fallback( $this->registry ) )->apply( $element_type, $widget_type, $result['settings'], $fallback_rules );
                $result['settings'] = $fallback['settings'];
                $result['custom_css'] = $fallback['custom_css'];
                $result['custom_css_properties'] = $fallback['custom_css_properties'] ?? array();
                $result['unsupported'] = array_values( array_diff( $result['unsupported'], $semantic_fallback['sources'] ) );
                $result['unsupported'] = array_merge( $result['unsupported'], $fallback['unsupported'] );
            }
            return $result;
        }
        return array( 'settings' => array(), 'native' => array(), 'custom_css' => array(), 'unsupported' => array( 'no-control-adapter:' . sanitize_key( $element_type . '-' . $widget_type ) ) );
    }

    /**
     * Centered fixed-width wrappers (margin-inline/margin auto + px max-width)
     * are Elementor boxed containers. Consume them into content_width=boxed so
     * they never decay into custom CSS; the exact pixel value is recorded in
     * governance diagnostics instead of guessed onto another control.
     */
    private function normalize_centered_boxed_container( array $node ) {
        $width = $node['layout']['max_width'] ?? null;
        $px = null;
        if ( is_array( $width ) && array_key_exists( 'value', $width ) && 'px' === strtolower( (string) ( $width['unit'] ?? '' ) ) && is_numeric( $width['value'] ) ) {
            $px = (float) $width['value'];
        }
        $fallback = is_array( $node['style']['css_fallback'] ?? null ) ? $node['style']['css_fallback'] : array();
        if ( null === $px ) {
            // Modern fluid containers (width:min(1184px, ...)) state their cap
            // functionally: at most N px wide. Honor the authored cap.
            $raw_width = (string) ( $fallback['width'] ?? '' );
            if ( preg_match( '/^\s*min\(\s*([0-9.]+)px\s*,/i', $raw_width, $m ) ) { $px = (float) $m[1]; }
            elseif ( preg_match( '/^\s*max\(\s*([0-9.]+)px\s*,/i', $raw_width, $m ) ) { $px = (float) $m[1]; }
            elseif ( preg_match( '/clamp\([^,]+,[^,]+,\s*([0-9.]+)px\s*\)\s*$/i', $raw_width, $m ) ) { $px = (float) $m[1]; }
        }
        if ( null === $px || $px <= 0 || 1600 < $px ) { return $node; }
        // Only page-container scale qualifies: smaller centered boxes are
        // components whose exact width must survive, not theme-width content.
        if ( $px < 768 ) { return $node; }
        $margin = strtolower( trim( (string) ( $fallback['margin-inline'] ?? '' ) ) );
        if ( '' === $margin ) {
            $margin = strtolower( trim( (string) ( $fallback['margin'] ?? '' ) ) );
            if ( ! in_array( $margin, array( 'auto', '0 auto' ), true ) ) { return $node; }
        } elseif ( 'auto' !== $margin && ! preg_match( '/^\S+ auto( \S+ auto)?$/', $margin ) ) {
            return $node;
        }
        $node['layout']['content_width'] = 'boxed';
        unset( $node['layout']['max_width'] );
        unset( $node['style']['css_fallback']['max-width'], $node['style']['css_fallback']['margin-inline'], $node['style']['css_fallback']['margin'], $node['style']['css_fallback']['width'] );
        foreach ( $node['responsive'] ?? array() as $device => $values ) {
            if ( ! is_array( $values ) ) { continue; }
            unset( $node['responsive'][ $device ]['layout']['max_width'] );
            unset( $node['responsive'][ $device ]['style']['css_fallback']['max-width'], $node['responsive'][ $device ]['style']['css_fallback']['margin-inline'], $node['responsive'][ $device ]['style']['css_fallback']['margin'] );
        }
        return $node;
    }

    /** Make semantic ownership authoritative over stale source width controls. */
    private function normalize_container_width_semantics( array $node ) {        $semantic = is_array( $node['semantic'] ?? null ) ? $node['semantic'] : array();
        $mode = (string) ( $semantic['layout_mode'] ?? '' );
        $role = (string) ( $semantic['container_role'] ?? '' );
        $boxed = 'content' === $role || ( 'boxed' === $mode && 'surface-content' === $role );
        $full = ( 'fullwidth' === $mode && 'surface' === $role ) || ( 'fullwidth-content' === $mode && 'surface-content' === $role );
        if ( ! $boxed && ! $full ) { return $node; }

        $node['layout'] = is_array( $node['layout'] ?? null ) ? $node['layout'] : array();
        $node['layout']['content_width'] = $boxed ? 'boxed' : 'full';
        unset( $node['layout']['max_width'] );
        if ( isset( $node['style']['css_fallback']['max-width'] ) ) { unset( $node['style']['css_fallback']['max-width'] ); }
        foreach ( $node['responsive'] ?? array() as $device => $values ) {
            if ( ! is_array( $values ) ) { continue; }
            unset( $node['responsive'][ $device ]['layout']['max_width'] );
            unset( $node['responsive'][ $device ]['style']['css_fallback']['max-width'] );
        }
        return $node;
    }

    private function map_source_identity( $element_type, $widget_type, $node, &$result ) {
        $source = is_array( $node['source'] ?? null ) ? $node['source'] : array();
        $element_id = sanitize_html_class( (string) ( $source['attributes']['id'] ?? '' ) );
        if ( $element_id && $this->registry->has( $element_type, $widget_type, '_element_id' ) ) {
            $result['settings']['_element_id'] = $element_id;
            $result['native'][] = '_element_id';
        }

        $classes = array_values( array_filter( array_map( 'sanitize_html_class', is_array( $source['classes'] ?? null ) ? $source['classes'] : array() ) ) );
        $global_class = class_exists( 'Design_Core_Elementor_Global_Layout_Standard' ) ? ( new Design_Core_Elementor_Global_Layout_Standard() )->class_name() : 'dc-global-container';
        $classes = array_values( array_diff( $classes, array( $global_class ) ) );
        if ( 'container' === $element_type ) {
            $semantic = is_array( $node['semantic'] ?? null ) ? $node['semantic'] : array();
            if ( 'content' === ( $semantic['container_role'] ?? '' ) || ( 'boxed' === ( $semantic['layout_mode'] ?? '' ) && 'surface-content' === ( $semantic['container_role'] ?? '' ) ) ) {
                $classes[] = $global_class;
            }
        }
        $classes = array_values( array_unique( array_filter( $classes ) ) );
        if ( ! $classes ) { return; }
        $control_id = $this->registry->first_supported( $element_type, $widget_type, array( 'css_classes', '_css_classes' ), 'text' );
        if ( ! $control_id ) { return; }
        $result['settings'][ $control_id ] = implode( ' ', array_unique( $classes ) );
        $result['native'][] = $control_id;
    }

    private function semantic_fallback_rules( $node, array $unsupported ) {
        $values = array_merge( $node['layout'] ?? array(), $node['style'] ?? array(), $node['spacing'] ?? array() );
        $properties = array(
            'display' => 'display', 'width' => 'width', 'max_width' => 'max-width', 'min_height' => 'min-height',
            'overflow' => 'overflow', 'shadow' => 'box-shadow', 'opacity' => 'opacity',
        );
        $rules = array(); $sources = array();
        foreach ( $unsupported as $source ) {
            if ( false !== strpos( $source, '_' ) && preg_match( '/_(?:widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', $source ) ) { continue; }
            if ( ! isset( $properties[ $source ] ) || ! array_key_exists( $source, $values ) ) { continue; }
            $css_value = $this->css_value( $values[ $source ] );
            if ( '' === $css_value ) { continue; }
            $rules[ $properties[ $source ] ] = $css_value; $sources[] = $source;
        }
        return array( 'rules' => $rules, 'sources' => $sources );
    }

    private function css_value( $value ) {
        if ( is_array( $value ) && array_key_exists( 'value', $value ) ) { return (string) $value['value'] . (string) ( $value['unit'] ?? '' ); }
        if ( is_scalar( $value ) ) { return trim( (string) $value ); }
        return '';
    }

    public function registry() { return $this->registry; }
}
