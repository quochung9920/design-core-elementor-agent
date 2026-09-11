<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Figma REST/export payload -> canonical Design IR v4.
 *
 * Adapter v4 preserves the semantics that matter for rendered fidelity:
 * FILL/HUG/FIXED sizing, real auto-layout alignment, padding, clipped media,
 * relative absolute offsets, vector exports, mixed text runs and multi-node
 * heading composition. Unsupported target behavior remains explicit evidence.
 */
class Design_Core_Elementor_Figma_Design_IR_Adapter {
    const VERSION = 4;
    const MAX_NODES = 2500;
    const MAX_DEPTH = 96;

    private $nodes = array();
    private $node_count = 0;
    private $image_fills = array();
    private $vector_assets = array();
    private $transport_source = array();
    private $normalization_report = array();
    private $text_composer;

    public function __construct() {
        $this->text_composer = class_exists( 'Design_Core_Elementor_Figma_Text_Composer' ) ? new Design_Core_Elementor_Figma_Text_Composer() : null;
    }

    public function convert( array $payload, $selected_node_id = '' ) {
        $this->nodes = array();
        $this->node_count = 0;
        $this->image_fills = array();
        $this->vector_assets = array();
        $this->transport_source = array();
        $this->normalization_report = array();

        if ( is_array( $payload['figma'] ?? null ) ) {
            $this->image_fills = is_array( $payload['image_fills'] ?? null ) ? $payload['image_fills'] : array();
            $this->vector_assets = is_array( $payload['vector_assets'] ?? null ) ? $payload['vector_assets'] : array();
            $this->transport_source = is_array( $payload['source'] ?? null ) ? $payload['source'] : array();
            if ( '' === $selected_node_id && ! empty( $this->transport_source['node_id'] ) ) { $selected_node_id = (string) $this->transport_source['node_id']; }
            $payload = $payload['figma'];
        } else {
            if ( is_array( $payload['image_fills'] ?? null ) ) { $this->image_fills = $payload['image_fills']; }
            if ( is_array( $payload['vector_assets'] ?? null ) ) { $this->vector_assets = $payload['vector_assets']; }
        }

        $root = $this->resolve_root( $payload, (string) $selected_node_id );
        if ( ! is_array( $root ) ) { return new WP_Error( 'design_core_figma_root_missing', 'Figma root node could not be resolved.' ); }
        if ( class_exists( 'Design_Core_Elementor_Figma_Normalization_Service' ) ) {
            try {
                $normalized = ( new Design_Core_Elementor_Figma_Normalization_Service() )->normalize( $root );
                $root = (array) ( $normalized['node'] ?? $root );
                $this->normalization_report = (array) ( $normalized['report'] ?? array() );
            } catch ( Throwable $exception ) {
                return new WP_Error( 'design_core_figma_normalization_failed', $exception->getMessage() );
            }
        }

        try { $root_id = $this->convert_node( $root, '', 0, true ); }
        catch ( Throwable $exception ) { return new WP_Error( 'design_core_figma_invalid', $exception->getMessage() ); }
        if ( ! $root_id ) { return new WP_Error( 'design_core_figma_empty', 'Figma selection produced no convertible nodes.' ); }

        $ir = array(
            'schema_version' => Design_Core_Elementor_Design_IR::SCHEMA_VERSION,
            'type' => 'design-ir',
            'source_name' => 'figma',
            'nodes' => array_values( $this->nodes ),
            'root_ids' => array( $root_id ),
            'analysis_quality' => array( 'browser_runtime' => 'figma', 'computed_styles' => 'figma', 'geometry' => 'figma', 'css_static' => 'figma', 'interaction' => 'figma' ),
            'tokens' => $this->extract_tokens( $payload ),
            'breakpoints' => array(),
            'diagnostics' => array(
                'figma_adapter_version' => self::VERSION,
                'figma_source_node_id' => (string) ( $root['id'] ?? '' ),
                'node_count' => $this->node_count,
                'resolved_image_fills' => count( $this->image_fills ),
                'resolved_vector_assets' => count( $this->vector_assets ),
                'transport_source' => Design_Core_Elementor_Change_Ledger::transport_safe( $this->transport_source ),
                'normalization' => Design_Core_Elementor_Change_Ledger::transport_safe( $this->normalization_report ),
                'fidelity_features' => array( 'text_composition', 'text_style_runs', 'figma_sizing', 'relative_absolute_offsets', 'background_media', 'vector_exports', 'padding_dimensions' ),
            ),
        );
        try { ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir ); }
        catch ( Throwable $exception ) { return new WP_Error( 'design_core_figma_ir_invalid', $exception->getMessage() ); }
        return $ir;
    }

    private function resolve_root( array $payload, $selected_id ) {
        $root = is_array( $payload['document'] ?? null ) ? $payload['document'] : $payload;
        if ( '' === $selected_id ) { return $root; }
        return $this->find_node( $root, $selected_id );
    }

    private function find_node( array $node, $id ) {
        if ( (string) ( $node['id'] ?? '' ) === (string) $id ) { return $node; }
        foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
            if ( ! is_array( $child ) ) { continue; }
            $found = $this->find_node( $child, $id );
            if ( $found ) { return $found; }
        }
        return null;
    }

    private function convert_node( array $figma, $parent_id, $depth, $is_root = false ) {
        if ( $depth > self::MAX_DEPTH ) { throw new RuntimeException( 'Figma nesting exceeds the bounded depth limit.' ); }
        if ( ++$this->node_count > self::MAX_NODES ) { throw new RuntimeException( 'Figma selection contains too many nodes.' ); }
        $type = strtoupper( (string) ( $figma['type'] ?? 'FRAME' ) );
        if ( in_array( $type, array( 'DOCUMENT', 'CANVAS' ), true ) && 1 === count( (array) ( $figma['children'] ?? array() ) ) ) {
            return $this->convert_node( $figma['children'][0], $parent_id, $depth + 1, $is_root );
        }

        if ( $this->text_composer ) {
            $composition = $this->text_composer->compose_container( $figma );
            if ( is_array( $composition ) ) { return $this->convert_composed_text( $figma, $composition, $parent_id, $is_root ); }
        }

        $id = $this->node_id( (string) ( $figma['id'] ?? wp_generate_uuid4() ) );
        $children = array();
        $stack_overlaps = 'VERTICAL' === strtoupper( (string) ( $figma['layoutMode'] ?? '' ) );
        $previous_bottom = null;
        foreach ( (array) ( $figma['children'] ?? array() ) as $child ) {
            if ( ! is_array( $child ) || false === ( $child['visible'] ?? true ) ) { continue; }
            if ( $stack_overlaps && null !== $previous_bottom ) {
                $box = (array) ( $child['absoluteBoundingBox'] ?? array() );
                if ( isset( $box['y'] ) && is_numeric( $box['y'] ) && (float) $box['y'] < $previous_bottom - 0.5 && 'ABSOLUTE' !== strtoupper( (string) ( $child['layoutPositioning'] ?? '' ) ) ) {
                    $child['_design_core_overlap_top'] = round( $previous_bottom - (float) $box['y'], 1 );
                }
            }
            $child_id = $this->convert_node( $child, $id, $depth + 1, false );
            if ( $child_id ) { $children[] = $child_id; }
            $box = (array) ( $child['absoluteBoundingBox'] ?? array() );
            if ( isset( $box['y'], $box['height'] ) && is_numeric( $box['y'] ) && is_numeric( $box['height'] ) ) { $previous_bottom = (float) $box['y'] + (float) $box['height']; }
        }

        $semantic = $this->semantic( $figma, $type );
        $content = $this->content( $figma, $type );
        $layout = $this->layout( $figma, $type, $is_root );
        $style = $this->style( $figma, $type, ! empty( $children ) );
        $style = $this->attach_layout_fallbacks( $style, $layout, $figma, $type, $is_root );
        $spacing = $this->spacing( $figma );
        $assets = $this->assets( $figma, $type );
        $layout_governance = $this->layout_governance( $figma, $layout, $style, $is_root );
        $tag = $this->tag( $type, $semantic, $content, $figma, ! empty( $children ) );
        $schema = $this->content_schema( $content, $assets, $semantic );
        $structure = strtolower( $tag ) . '|' . implode( ',', array_map( function ( $child_id ) { return (string) ( $this->nodes[ $child_id ]['source']['tag'] ?? 'node' ); }, $children ) );
        $component_properties = is_array( $figma['componentProperties'] ?? null ) ? $figma['componentProperties'] : array();

        $node = array(
            'id' => $id,
            'source' => array( 'tag' => $tag, 'classes' => $this->source_classes( $figma ), 'attributes' => array( 'data-figma-id' => sanitize_text_field( (string) ( $figma['id'] ?? '' ) ) ), 'dom_path' => '/figma/' . $id ),
            'semantic' => array( 'role' => $semantic, 'component_type' => in_array( $type, array( 'COMPONENT', 'INSTANCE', 'COMPONENT_SET' ), true ) ? sanitize_key( $semantic ) : '', 'confidence' => 0.97 ),
            'content' => $content,
            'layout' => $layout,
            'style' => $style,
            'spacing' => $spacing,
            'responsive' => array(),
            'assets' => $assets,
            'interaction' => $this->interaction( $figma ),
            'component' => array( 'fingerprint' => array( 'version' => 2, 'semantic' => $semantic, 'structure' => $structure, 'content_schema' => $schema, 'layout' => sanitize_text_field( $layout['display'] ?? '' ), 'interaction' => ! empty( $content['link']['url'] ) ? 'link' : '' ), 'repeated' => false, 'reusable' => in_array( $type, array( 'COMPONENT', 'INSTANCE', 'COMPONENT_SET' ), true ), 'dynamic' => false, 'content_schema' => $schema ),
            'children' => $children,
        );
        if ( $layout_governance ) { $node['layout_governance'] = $layout_governance; }
        $media_governance = $this->media_governance( $figma, $type );
        if ( $media_governance ) { $node['media_governance'] = $media_governance; }
        $node['figma'] = $this->figma_evidence( $figma, $type );
        $node = $this->fold_button_instance( $figma, $type, $node );
        $this->nodes[ $id ] = $node;
        return $id;
    }

    private function convert_composed_text( array $figma, array $composition, $parent_id, $is_root ) {
        $id = $this->node_id( (string) ( $figma['id'] ?? wp_generate_uuid4() ) );
        $semantic = 'heading';
        $content = array( 'text' => sanitize_textarea_field( (string) $composition['text'] ), 'rich_text' => (string) $composition['rich_text'], 'link' => array(), 'image' => array(), 'list' => array(), 'fields' => array() );
        $layout = $this->layout( $figma, strtoupper( (string) ( $figma['type'] ?? 'FRAME' ) ), $is_root );
        $style = array_merge( $this->style( $figma, strtoupper( (string) ( $figma['type'] ?? 'FRAME' ) ), false ), (array) $composition['style'] );
        $style = $this->attach_layout_fallbacks( $style, $layout, $figma, strtoupper( (string) ( $figma['type'] ?? 'FRAME' ) ), $is_root );
        $spacing = $this->spacing( $figma );
        $tag = in_array( $composition['tag'] ?? '', array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $composition['tag'] : 'h2';
        $node = array(
            'id' => $id,
            'source' => array( 'tag' => $tag, 'classes' => array_merge( $this->source_classes( $figma ), array( 'dc-figma-text-composition' ) ), 'attributes' => array( 'data-figma-id' => sanitize_text_field( (string) ( $figma['id'] ?? '' ) ) ), 'dom_path' => '/figma/' . $id ),
            'semantic' => array( 'role' => $semantic, 'component_type' => 'rich-heading', 'confidence' => 1.0 ),
            'content' => $content,
            'layout' => $layout,
            'style' => $style,
            'spacing' => $spacing,
            'responsive' => array(),
            'assets' => array(),
            'interaction' => array(),
            'component' => array( 'fingerprint' => array( 'version' => 2, 'semantic' => $semantic, 'structure' => $tag . '|figma-text-composition', 'content_schema' => array( 'heading', 'rich_text' ), 'layout' => sanitize_text_field( $layout['display'] ?? '' ), 'interaction' => '' ), 'repeated' => false, 'reusable' => false, 'dynamic' => false, 'content_schema' => array( 'heading', 'rich_text' ) ),
            'children' => array(),
            'figma' => array_merge( $this->figma_evidence( $figma, strtoupper( (string) ( $figma['type'] ?? 'FRAME' ) ) ), array( 'text_composition' => Design_Core_Elementor_Change_Ledger::transport_safe( $composition ) ) ),
        );
        $governance = $this->layout_governance( $figma, $layout, $style, $is_root );
        if ( $governance ) { $node['layout_governance'] = $governance; }
        $media_governance = $this->media_governance( $figma, strtoupper( (string) ( $figma['type'] ?? 'FRAME' ) ) );
        if ( $media_governance ) { $node['media_governance'] = $media_governance; }
        $this->nodes[ $id ] = $node;
        return $id;
    }

    private function semantic( array $figma, $type ) {
        $name = strtolower( (string) ( $figma['name'] ?? '' ) );
        foreach ( array( 'hero', 'navigation', 'footer', 'header', 'faq', 'testimonial', 'pricing', 'comparison', 'process', 'cta', 'benefits', 'services', 'team', 'gallery', 'button', 'card', 'emergency', 'appointment' ) as $role ) {
            if ( false !== strpos( $name, $role ) ) { return $role; }
        }
        if ( 'TEXT' === $type ) {
            $size = (float) ( $figma['style']['fontSize'] ?? 0 );
            return $size >= 28 ? 'heading' : 'text';
        }
        if ( $this->image_fill( $figma ) || isset( $this->vector_assets[ (string) ( $figma['id'] ?? '' ) ] ) ) { return 'media'; }
        if ( in_array( $type, array( 'COMPONENT', 'INSTANCE', 'COMPONENT_SET' ), true ) ) { return sanitize_key( $name ?: 'component' ); }
        return in_array( $type, array( 'FRAME', 'SECTION', 'GROUP' ), true ) ? 'section' : strtolower( $type );
    }

    private function content( array $figma, $type ) {
        $content = array( 'text' => '', 'rich_text' => '', 'link' => array(), 'image' => array(), 'list' => array(), 'fields' => array() );
        if ( 'TEXT' === $type ) {
            $composed = $this->text_composer ? $this->text_composer->compose_text_node( $figma ) : null;
            if ( is_array( $composed ) ) {
                $content['text'] = sanitize_textarea_field( (string) $composed['text'] );
                $content['rich_text'] = (string) $composed['rich_text'];
            } else {
                $content['text'] = sanitize_textarea_field( (string) ( $figma['characters'] ?? '' ) );
                $content['rich_text'] = esc_html( $content['text'] );
            }
        }
        $image = $this->image_fill( $figma );
        if ( $image ) {
            $ref = sanitize_text_field( (string) ( $image['imageRef'] ?? '' ) );
            $content['image'] = array( 'url' => isset( $this->image_fills[ $ref ] ) ? esc_url_raw( (string) $this->image_fills[ $ref ] ) : '', 'id' => 0, 'alt' => sanitize_text_field( (string) ( $figma['name'] ?? '' ) ) );
        }
        $figma_id = (string) ( $figma['id'] ?? '' );
        if ( isset( $this->vector_assets[ $figma_id ] ) ) {
            $content['image'] = array( 'url' => esc_url_raw( (string) $this->vector_assets[ $figma_id ] ), 'id' => 0, 'alt' => sanitize_text_field( (string) ( $figma['name'] ?? '' ) ) );
        }
        $link = $this->link_for_node( $figma );
        if ( $link ) { $content['link'] = $link; }
        return $content;
    }

    private function layout( array $figma, $type, $is_root ) {
        $layout = array();
        $mode = strtoupper( (string) ( $figma['layoutMode'] ?? '' ) );
        if ( in_array( $mode, array( 'HORIZONTAL', 'VERTICAL' ), true ) ) {
            $layout['display'] = 'flex';
            $layout['direction'] = 'HORIZONTAL' === $mode ? 'row' : 'column';
            if ( isset( $figma['itemSpacing'] ) && is_numeric( $figma['itemSpacing'] ) ) { $layout['gap'] = $this->px( $figma['itemSpacing'] ); }
            $justify = $this->axis_alignment( $figma['primaryAxisAlignItems'] ?? '' );
            $align = $this->axis_alignment( $figma['counterAxisAlignItems'] ?? '' );
            if ( $justify ) { $layout['justify'] = $justify; }
            if ( $align ) { $layout['align'] = $align; }
            if ( ! empty( $figma['layoutWrap'] ) && 'WRAP' === strtoupper( (string) $figma['layoutWrap'] ) ) { $layout['wrap'] = 'wrap'; }
        }

        $box = (array) ( $figma['absoluteBoundingBox'] ?? array() );
        $sizing = is_array( $figma['_design_core_sizing'] ?? null ) ? $figma['_design_core_sizing'] : array();
        $horizontal = strtoupper( (string) ( $sizing['horizontal'] ?? $figma['layoutSizingHorizontal'] ?? '' ) );
        $vertical = strtoupper( (string) ( $sizing['vertical'] ?? $figma['layoutSizingVertical'] ?? '' ) );
        $parent_mode = strtoupper( (string) ( $sizing['parent_layout_mode'] ?? 'NONE' ) );

        if ( 'FILL' === $horizontal && 'HORIZONTAL' !== $parent_mode ) { $layout['width'] = array( 'value' => 100, 'unit' => '%' ); }
        elseif ( 'FIXED' === $horizontal && ! $is_root && isset( $box['width'] ) && is_numeric( $box['width'] ) && (float) $box['width'] > 0 && (float) $box['width'] <= 320 ) { $layout['width'] = $this->px( $box['width'] ); }
        if ( $is_root ) { $layout['width'] = array( 'value' => 100, 'unit' => '%' ); }

        if ( 'FIXED' === $vertical && 'TEXT' !== $type && isset( $box['height'] ) && is_numeric( $box['height'] ) && (float) $box['height'] > 0 ) {
            $layout['min_height'] = $this->px( $box['height'] );
        }
        foreach ( array( 'minWidth' => 'min_width', 'maxWidth' => 'max_width', 'minHeight' => 'min_height', 'maxHeight' => 'max_height' ) as $figma_key => $ir_key ) {
            if ( isset( $figma[ $figma_key ] ) && is_numeric( $figma[ $figma_key ] ) ) { $layout[ $ir_key ] = $this->px( $figma[ $figma_key ] ); }
        }
        if ( is_array( $figma['_design_core_layout'] ?? null ) ) { $layout = array_merge( $layout, $figma['_design_core_layout'] ); }
        return array_filter( $layout, static fn( $value ) => '' !== $value && null !== $value );
    }

    private function attach_layout_fallbacks( array $style, array $layout, array $figma, $type, $is_root ) {
        $fallback = is_array( $style['css_fallback'] ?? null ) ? $style['css_fallback'] : array();
        $box = (array) ( $figma['absoluteBoundingBox'] ?? array() );
        $sizing = is_array( $figma['_design_core_sizing'] ?? null ) ? $figma['_design_core_sizing'] : array();
        $horizontal = strtoupper( (string) ( $sizing['horizontal'] ?? $figma['layoutSizingHorizontal'] ?? '' ) );
        $vertical = strtoupper( (string) ( $sizing['vertical'] ?? $figma['layoutSizingVertical'] ?? '' ) );
        $parent_mode = strtoupper( (string) ( $sizing['parent_layout_mode'] ?? 'NONE' ) );

        if ( 'FILL' === $horizontal && 'HORIZONTAL' === $parent_mode ) { $fallback['flex'] = '1 0 0%'; $fallback['min-width'] = '0px'; }
        elseif ( 'HUG' === $horizontal ) { $fallback['width'] = 'fit-content'; $fallback['flex-shrink'] = '0'; }
        elseif ( 'FIXED' === $horizontal && ! $is_root && isset( $box['width'] ) && is_numeric( $box['width'] ) && (float) $box['width'] > 320 ) { $fallback['width'] = $this->css_px( $box['width'] ); }

        if ( 'FILL' === $vertical && 'VERTICAL' === $parent_mode ) { $fallback['flex'] = isset( $fallback['flex'] ) ? $fallback['flex'] : '1 0 0%'; }
        elseif ( 'HUG' === $vertical ) { $fallback['height'] = 'fit-content'; }
        elseif ( 'FIXED' === $vertical && 'TEXT' !== $type && isset( $box['height'] ) && is_numeric( $box['height'] ) && (float) $box['height'] > 0 ) { $fallback['height'] = $this->css_px( $box['height'] ); }

        if ( ! empty( $layout['overflow'] ) ) { $fallback['overflow'] = sanitize_key( (string) $layout['overflow'] ); }
        $top = $this->dimension_css( $layout['top'] ?? null );
        $right = $this->dimension_css( $layout['right'] ?? null );
        $bottom = $this->dimension_css( $layout['bottom'] ?? null );
        $left = $this->dimension_css( $layout['left'] ?? null );
        if ( 'absolute' === ( $layout['position'] ?? '' ) && ( $top || $right || $bottom || $left ) ) {
            $fallback['inset'] = ( $top ?: 'auto' ) . ' ' . ( $right ?: 'auto' ) . ' ' . ( $bottom ?: 'auto' ) . ' ' . ( $left ?: 'auto' );
        }
        $style['css_fallback'] = $fallback;
        return $style;
    }

    private function layout_governance( array $figma, array $layout, array $style, $is_root ) {
        if ( $is_root ) { return array(); }
        $horizontal = strtoupper( (string) ( $figma['layoutSizingHorizontal'] ?? $figma['_design_core_sizing']['horizontal'] ?? '' ) );
        $box = (array) ( $figma['absoluteBoundingBox'] ?? array() );
        $width = (float) ( $box['width'] ?? 0 );
        if ( 'FIXED' === $horizontal && $width > 320 ) {
            return array( 'fixed_width_exception' => true, 'reason' => 'Figma layoutSizingHorizontal=FIXED explicitly authors this component width; v4 preserves it and requires responsive visual verification.' );
        }
        return array();
    }

    private function media_governance( array $figma, $type ) {
        $vertical = strtoupper( (string) ( $figma['layoutSizingVertical'] ?? $figma['_design_core_sizing']['vertical'] ?? '' ) );
        $height = (float) ( $figma['absoluteBoundingBox']['height'] ?? 0 );
        if ( 'FIXED' === $vertical && 'TEXT' !== $type && $height > 320 ) {
            return array( 'height_exception' => true, 'reason' => 'Figma explicitly authors a fixed component/media height; exact desktop fidelity is preserved and must be checked at responsive viewports.' );
        }
        return array();
    }

    private function spacing( array $figma ) {
        $spacing = array();
        $padding = array( 'unit' => 'px', 'top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0 );
        $has_padding = false;
        foreach ( array( 'Top' => 'top', 'Right' => 'right', 'Bottom' => 'bottom', 'Left' => 'left' ) as $suffix => $side ) {
            $key = 'padding' . $suffix;
            if ( isset( $figma[ $key ] ) && is_numeric( $figma[ $key ] ) ) { $padding[ $side ] = (float) $figma[ $key ]; $has_padding = true; }
        }
        if ( $has_padding ) { $spacing['padding'] = $padding; }
        $overlap = isset( $figma['_design_core_overlap_top'] ) ? (float) $figma['_design_core_overlap_top'] : 0;
        if ( $overlap > 0 ) { $spacing['margin'] = array( 'unit' => 'px', 'top' => -$overlap, 'right' => 0, 'bottom' => 0, 'left' => 0 ); }
        return $spacing;
    }

    private function style( array $figma, $type, $has_children ) {
        $style = array();
        $fills = (array) ( $figma['fills'] ?? array() );
        $solid = false;
        foreach ( $fills as $fill ) {
            if ( ! is_array( $fill ) || false === ( $fill['visible'] ?? true ) || 'SOLID' !== strtoupper( (string) ( $fill['type'] ?? '' ) ) ) { continue; }
            $color = $this->rgba( (array) ( $fill['color'] ?? array() ), (float) ( $fill['opacity'] ?? 1 ) );
            if ( 'TEXT' === $type ) { $style['color'] = $color; }
            else { $style['background'] = $color; }
            $solid = true;
            break;
        }
        if ( ! $solid ) {
            foreach ( $fills as $fill ) {
                if ( ! is_array( $fill ) || false === ( $fill['visible'] ?? true ) || 'GRADIENT_LINEAR' !== strtoupper( (string) ( $fill['type'] ?? '' ) ) ) { continue; }
                $gradient = $this->gradient( $fill );
                if ( '' !== $gradient ) { $style['css_fallback']['background'] = $gradient; }
                break;
            }
        }

        $image = $this->image_fill( $figma );
        if ( $image && ( $has_children || in_array( $type, array( 'FRAME', 'SECTION', 'COMPONENT', 'INSTANCE' ), true ) ) ) {
            $ref = (string) ( $image['imageRef'] ?? '' );
            $url = isset( $this->image_fills[ $ref ] ) ? esc_url_raw( (string) $this->image_fills[ $ref ] ) : '';
            if ( $url ) {
                $style['background_image'] = array( 'url' => $url, 'id' => 0 );
                $scale = strtoupper( (string) ( $image['scaleMode'] ?? 'FILL' ) );
                $style['background_size'] = 'FIT' === $scale ? 'contain' : ( 'TILE' === $scale ? 'auto' : 'cover' );
                $style['background_position'] = 'center center';
            }
        }

        $radii = array();
        foreach ( array( 'topLeftRadius' => 'top', 'topRightRadius' => 'right', 'bottomRightRadius' => 'bottom', 'bottomLeftRadius' => 'left' ) as $key => $side ) {
            if ( isset( $figma[ $key ] ) && is_numeric( $figma[ $key ] ) ) { $radii[ $side ] = (float) $figma[ $key ]; }
        }
        if ( count( $radii ) === 4 ) { $style['radius'] = array_merge( array( 'unit' => 'px' ), $radii ); }
        elseif ( is_numeric( $figma['cornerRadius'] ?? null ) ) { $style['radius'] = $this->px( $figma['cornerRadius'] ); }
        if ( 'ELLIPSE' === $type ) { $style['css_fallback']['border-radius'] = '50%'; }

        foreach ( (array) ( $figma['strokes'] ?? array() ) as $stroke ) {
            if ( ! is_array( $stroke ) || false === ( $stroke['visible'] ?? true ) || 'SOLID' !== strtoupper( (string) ( $stroke['type'] ?? '' ) ) ) { continue; }
            $style['border_style'] = 'solid';
            $style['border_color'] = $this->rgba( (array) ( $stroke['color'] ?? array() ), (float) ( $stroke['opacity'] ?? 1 ) );
            $weight = is_numeric( $figma['strokeWeight'] ?? null ) ? (float) $figma['strokeWeight'] : 1.0;
            $style['border_width'] = $this->px( $weight );
            $style['css_fallback']['border'] = $this->css_px( $weight ) . ' solid ' . $style['border_color'];
            break;
        }

        if ( isset( $figma['opacity'] ) && is_numeric( $figma['opacity'] ) ) { $style['opacity'] = (float) $figma['opacity']; }
        $text = (array) ( $figma['style'] ?? array() );
        if ( $text ) {
            if ( isset( $text['fontFamily'] ) ) { $style['font_family'] = sanitize_text_field( (string) $text['fontFamily'] ); }
            if ( isset( $text['fontSize'] ) && is_numeric( $text['fontSize'] ) ) { $style['font_size'] = $this->px( $text['fontSize'] ); }
            if ( isset( $text['fontWeight'] ) && is_numeric( $text['fontWeight'] ) ) { $style['font_weight'] = (int) $text['fontWeight']; }
            if ( isset( $text['lineHeightPx'] ) && is_numeric( $text['lineHeightPx'] ) ) { $style['line_height'] = $this->px( $text['lineHeightPx'] ); }
            if ( isset( $text['letterSpacing'] ) && is_numeric( $text['letterSpacing'] ) ) { $style['letter_spacing'] = $this->px( $text['letterSpacing'] ); }
            if ( false !== strpos( strtolower( (string) ( $text['fontStyle'] ?? '' ) ), 'italic' ) ) { $style['font_style'] = 'italic'; }
            if ( isset( $text['textAlignHorizontal'] ) ) { $style['align'] = strtolower( (string) $text['textAlignHorizontal'] ); }
        }

        $shadow_css = $this->shadow_css( (array) ( $figma['effects'] ?? array() ) );
        if ( $shadow_css ) { $style['css_fallback']['box-shadow'] = $shadow_css; }
        if ( isset( $figma['rotation'] ) && is_numeric( $figma['rotation'] ) && abs( (float) $figma['rotation'] ) > 0.01 ) { $style['css_fallback']['transform'] = 'rotate(' . round( (float) $figma['rotation'], 3 ) . 'deg)'; }
        return $style;
    }

    private function assets( array $figma, $type ) {
        $items = array();
        $image = $this->image_fill( $figma );
        if ( $image ) {
            $ref = sanitize_text_field( (string) ( $image['imageRef'] ?? '' ) );
            $items[] = array( 'source' => 'figma', 'figma_image_ref' => $ref, 'resolved_url' => isset( $this->image_fills[ $ref ] ) ? esc_url_raw( (string) $this->image_fills[ $ref ] ) : '', 'scale_mode' => sanitize_key( (string) ( $image['scaleMode'] ?? '' ) ), 'node_id' => sanitize_text_field( (string) ( $figma['id'] ?? '' ) ) );
        }
        $figma_id = (string) ( $figma['id'] ?? '' );
        if ( isset( $this->vector_assets[ $figma_id ] ) ) { $items[] = array( 'source' => 'figma-vector-export', 'resolved_url' => esc_url_raw( (string) $this->vector_assets[ $figma_id ] ), 'node_id' => sanitize_text_field( $figma_id ), 'scale_mode' => 'contain' ); }
        return $items ? array( 'images' => $items ) : array();
    }

    private function interaction( array $figma ) {
        $reactions = (array) ( $figma['reactions'] ?? array() );
        $link = $this->link_for_node( $figma );
        if ( ! $reactions && ! $link ) { return array(); }
        return array( 'figma_reactions' => Design_Core_Elementor_Change_Ledger::transport_safe( array_slice( $reactions, 0, 32 ) ), 'interactive' => true, 'link' => $link );
    }

    private function figma_evidence( array $figma, $type ) {
        $component_properties = is_array( $figma['componentProperties'] ?? null ) ? $figma['componentProperties'] : array();
        $text_runs = array();
        if ( 'TEXT' === $type && $this->text_composer ) {
            $composed = $this->text_composer->compose_text_node( $figma );
            if ( is_array( $composed ) ) { $text_runs = (array) ( $composed['runs'] ?? array() ); }
        }
        return array(
            'id' => sanitize_text_field( (string) ( $figma['id'] ?? '' ) ),
            'type' => $type,
            'name' => sanitize_text_field( (string) ( $figma['name'] ?? '' ) ),
            'component_id' => sanitize_text_field( (string) ( $figma['componentId'] ?? '' ) ),
            'component_properties' => Design_Core_Elementor_Change_Ledger::transport_safe( $component_properties ),
            'bound_variables' => Design_Core_Elementor_Change_Ledger::transport_safe( (array) ( $figma['boundVariables'] ?? array() ) ),
            'constraints' => Design_Core_Elementor_Change_Ledger::transport_safe( (array) ( $figma['constraints'] ?? array() ) ),
            'sizing' => Design_Core_Elementor_Change_Ledger::transport_safe( (array) ( $figma['_design_core_sizing'] ?? array() ) ),
            'relative_geometry' => Design_Core_Elementor_Change_Ledger::transport_safe( (array) ( $figma['_design_core_relative_geometry'] ?? array() ) ),
            'geometry' => $this->geometry( $figma ),
            'style_evidence' => $this->style_evidence( $figma ),
            'text_runs' => Design_Core_Elementor_Change_Ledger::transport_safe( $text_runs ),
            'variable_refs' => Design_Core_Elementor_Change_Ledger::transport_safe( (array) ( $figma['_design_core_variable_refs'] ?? array() ) ),
            'conversion_warnings' => array_values( array_map( 'sanitize_text_field', (array) ( $figma['_design_core_warnings'] ?? array() ) ) ),
            'normalization' => Design_Core_Elementor_Change_Ledger::transport_safe( (array) ( $figma['_design_core_normalization'] ?? array() ) ),
        );
    }

    private function fold_button_instance( array $figma, $type, array $node ) {
        if ( 'INSTANCE' !== $type || empty( $node['children'] ) ) { return $node; }
        $texts = $this->collect_texts( $node );
        if ( 1 !== count( $texts ) ) { return $node; }
        // Preserve icon-bearing/compound buttons as native Elementor container
        // composition instead of deleting decorative descendants for a button widget.
        if ( $this->subtree_has_media( $node ) ) {
            $node['semantic']['role'] = 'button-composition';
            $node['component']['fingerprint']['semantic'] = 'button-composition';
            return $node;
        }
        $name = strtolower( (string) ( $figma['name'] ?? '' ) );
        $hint = false !== strpos( $name, 'button' ) || false !== strpos( $name, 'btn' ) || false !== strpos( $name, 'cta' );
        $bg = false;
        foreach ( (array) ( $figma['fills'] ?? array() ) as $fill ) {
            if ( is_array( $fill ) && false !== ( $fill['visible'] ?? true ) && 'SOLID' === strtoupper( (string) ( $fill['type'] ?? '' ) ) ) { $bg = true; break; }
        }
        $radius = is_numeric( $figma['cornerRadius'] ?? null ) && (float) $figma['cornerRadius'] > 0;
        if ( ! ( $hint || $bg || $radius ) ) { return $node; }
        $text = array_shift( $texts );
        foreach ( $this->collect_runs( $node ) as $run ) {
            $ink = (string) ( $run['style']['color'] ?? '' );
            if ( '' !== $ink && ! isset( $node['style']['color'] ) ) { $node['style']['color'] = $ink; break; }
        }
        $drop = $this->collect_descendant_ids( $node );
        $node['source']['tag'] = 'a';
        $node['content']['text'] = $text['text'];
        $node['content']['rich_text'] = $text['text'];
        $node['semantic']['role'] = 'button';
        $node['children'] = array();
        foreach ( $drop as $gone ) { unset( $this->nodes[ $gone ] ); }
        return $node;
    }

    private function collect_runs( array $node ) {
        $out = array();
        foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) {
            if ( ! isset( $this->nodes[ $child_id ] ) || ! is_array( $this->nodes[ $child_id ] ) ) { continue; }
            $out[] = $this->nodes[ $child_id ];
            foreach ( $this->collect_runs( $this->nodes[ $child_id ] ) as $nested ) { $out[] = $nested; }
        }
        return $out;
    }

    private function collect_texts( array $node ) {
        $out = array();
        foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) {
            $child = $this->nodes[ $child_id ] ?? null;
            if ( ! is_array( $child ) ) { continue; }
            $text = trim( (string) ( $child['content']['text'] ?? '' ) );
            if ( '' !== $text ) { $out[] = array( 'text' => $text ); }
            foreach ( $this->collect_texts( $child ) as $nested ) { $out[] = $nested; }
        }
        return $out;
    }

    private function collect_descendant_ids( array $node ) {
        $out = array();
        foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) {
            $out[] = $child_id;
            if ( isset( $this->nodes[ $child_id ] ) && is_array( $this->nodes[ $child_id ] ) ) {
                foreach ( $this->collect_descendant_ids( $this->nodes[ $child_id ] ) as $nested ) { $out[] = $nested; }
            }
        }
        return $out;
    }

    private function subtree_has_media( array $node ) {
        foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) {
            $child = $this->nodes[ $child_id ] ?? null;
            if ( ! is_array( $child ) ) { continue; }
            if ( 'img' === strtolower( (string) ( $child['source']['tag'] ?? '' ) ) || ! empty( $child['content']['image']['url'] ) ) { return true; }
            if ( $this->subtree_has_media( $child ) ) { return true; }
        }
        return false;
    }

    private function link_for_node( array $figma ) {
        $hyperlink = $figma['hyperlink'] ?? null;
        if ( is_array( $hyperlink ) && 'URL' === strtoupper( (string) ( $hyperlink['type'] ?? '' ) ) && ! empty( $hyperlink['value'] ) ) {
            return array( 'url' => esc_url_raw( (string) $hyperlink['value'] ), 'is_external' => true, 'nofollow' => false );
        }
        foreach ( (array) ( $figma['reactions'] ?? array() ) as $reaction ) {
            if ( ! is_array( $reaction ) ) { continue; }
            $action = is_array( $reaction['action'] ?? null ) ? $reaction['action'] : array();
            $type = strtoupper( (string) ( $action['type'] ?? '' ) );
            $url = (string) ( $action['url'] ?? $action['value'] ?? '' );
            if ( in_array( $type, array( 'URL', 'OPEN_URL' ), true ) && '' !== $url ) { return array( 'url' => esc_url_raw( $url ), 'is_external' => true, 'nofollow' => false ); }
        }
        foreach ( (array) ( $figma['children'] ?? array() ) as $child ) {
            if ( ! is_array( $child ) ) { continue; }
            $link = $this->link_for_node( $child );
            if ( $link ) { return $link; }
        }
        return array();
    }

    private function source_classes( array $figma ) {
        $classes = array();
        $name = sanitize_title( (string) ( $figma['name'] ?? 'node' ) );
        if ( $name ) { $classes[] = 'figma-' . sanitize_html_class( $name ); }
        $id = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) ( $figma['id'] ?? '' ) ) );
        $id = trim( $id, '-' );
        if ( $id ) { $classes[] = 'dc-figma-node-' . sanitize_html_class( $id ); }
        return array_values( array_unique( $classes ) );
    }

    private function tag( $type, $semantic, array $content, array $figma, $has_children ) {
        if ( 'TEXT' === $type ) {
            $name = strtolower( (string) ( $figma['name'] ?? '' ) );
            if ( preg_match( '/(?:^|[^a-z0-9])h([1-6])(?:[^a-z0-9]|$)/i', $name, $match ) ) { return 'h' . $match[1]; }
            return 'heading' === $semantic ? ( (float) ( $figma['style']['fontSize'] ?? 0 ) >= 48 ? 'h1' : 'h2' ) : 'p';
        }
        if ( ! empty( $content['image']['url'] ) && ! $has_children && in_array( $type, array( 'RECTANGLE', 'ELLIPSE', 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'STAR', 'POLYGON', 'FRAME' ), true ) ) { return 'img'; }
        return in_array( $type, array( 'FRAME', 'SECTION', 'GROUP', 'COMPONENT', 'INSTANCE', 'COMPONENT_SET' ), true ) ? 'section' : 'div';
    }

    private function content_schema( array $content, array $assets, $semantic = '' ) {
        $schema = array();
        if ( '' !== (string) ( $content['text'] ?? '' ) ) { $schema[] = 'heading' === sanitize_key( $semantic ) ? 'heading' : 'rich_text'; }
        if ( ! empty( $assets['images'] ) ) { $schema[] = 'media'; }
        if ( ! empty( $content['link']['url'] ) ) { $schema[] = 'link'; }
        return array_values( array_unique( $schema ) );
    }

    private function style_evidence( array $figma ) {
        $corners = array();
        foreach ( array( 'topLeftRadius', 'topRightRadius', 'bottomRightRadius', 'bottomLeftRadius' ) as $key ) { if ( isset( $figma[ $key ] ) && is_numeric( $figma[ $key ] ) ) { $corners[ $key ] = (float) $figma[ $key ]; } }
        return Design_Core_Elementor_Change_Ledger::transport_safe( array( 'fills' => (array) ( $figma['fills'] ?? array() ), 'strokes' => (array) ( $figma['strokes'] ?? array() ), 'strokeWeight' => $figma['strokeWeight'] ?? null, 'effects' => (array) ( $figma['effects'] ?? array() ), 'individualCornerRadii' => $corners, 'rotation' => $figma['rotation'] ?? 0, 'blendMode' => $figma['blendMode'] ?? '' ) );
    }

    private function image_fill( array $figma ) {
        foreach ( (array) ( $figma['fills'] ?? array() ) as $fill ) { if ( is_array( $fill ) && false !== ( $fill['visible'] ?? true ) && 'IMAGE' === strtoupper( (string) ( $fill['type'] ?? '' ) ) ) { return $fill; } }
        return null;
    }

    private function axis_alignment( $value ) {
        $map = array( 'MIN' => 'flex-start', 'MAX' => 'flex-end', 'CENTER' => 'center', 'SPACE_BETWEEN' => 'space-between', 'BASELINE' => 'baseline' );
        return $map[ strtoupper( (string) $value ) ] ?? '';
    }

    private function geometry( array $figma ) {
        $box = (array) ( $figma['absoluteBoundingBox'] ?? array() );
        return array( 'x' => (float) ( $box['x'] ?? 0 ), 'y' => (float) ( $box['y'] ?? 0 ), 'width' => (float) ( $box['width'] ?? 0 ), 'height' => (float) ( $box['height'] ?? 0 ) );
    }

    private function shadow_css( array $effects ) {
        $parts = array();
        foreach ( $effects as $effect ) {
            if ( ! is_array( $effect ) || false === ( $effect['visible'] ?? true ) ) { continue; }
            $type = strtoupper( (string) ( $effect['type'] ?? '' ) );
            if ( ! in_array( $type, array( 'DROP_SHADOW', 'INNER_SHADOW' ), true ) ) { continue; }
            $offset = (array) ( $effect['offset'] ?? array() );
            $x = (float) ( $offset['x'] ?? 0 ); $y = (float) ( $offset['y'] ?? 0 );
            $blur = (float) ( $effect['radius'] ?? 0 ); $spread = (float) ( $effect['spread'] ?? 0 );
            $color = $this->rgba( (array) ( $effect['color'] ?? array() ), 1 );
            $parts[] = ( 'INNER_SHADOW' === $type ? 'inset ' : '' ) . $this->css_px( $x ) . ' ' . $this->css_px( $y ) . ' ' . $this->css_px( $blur ) . ' ' . $this->css_px( $spread ) . ' ' . $color;
        }
        return implode( ', ', $parts );
    }

    private function rgba( array $color, $opacity ) {
        $r = (int) round( 255 * (float) ( $color['r'] ?? 0 ) ); $g = (int) round( 255 * (float) ( $color['g'] ?? 0 ) ); $b = (int) round( 255 * (float) ( $color['b'] ?? 0 ) );
        $a = max( 0, min( 1, (float) ( $color['a'] ?? 1 ) * (float) $opacity ) );
        return $a >= 0.999 ? sprintf( '#%02x%02x%02x', $r, $g, $b ) : sprintf( 'rgba(%d,%d,%d,%.3f)', $r, $g, $b, $a );
    }

    private function gradient( array $fill ) {
        $stops = array();
        foreach ( (array) ( $fill['gradientStops'] ?? array() ) as $stop ) {
            if ( ! is_array( $stop ) || ! isset( $stop['position'] ) ) { continue; }
            $stops[] = array( 'p' => (float) $stop['position'], 'c' => $this->rgba( (array) ( $stop['color'] ?? array() ), (float) ( $fill['opacity'] ?? 1 ) ) );
        }
        if ( count( $stops ) < 2 ) { return ''; }
        usort( $stops, static fn( $a, $b ) => $a['p'] <=> $b['p'] );
        $handles = (array) ( $fill['gradientHandlePositions'] ?? array() );
        $angle = 135;
        if ( isset( $handles[0]['x'], $handles[0]['y'], $handles[1]['x'], $handles[1]['y'] ) ) {
            $dx = (float) $handles[1]['x'] - (float) $handles[0]['x']; $dy = (float) $handles[1]['y'] - (float) $handles[0]['y'];
            if ( $dx || $dy ) { $angle = (int) round( 90 + atan2( $dy, $dx ) * 180 / M_PI ); if ( $angle < 0 ) { $angle += 360; } }
        }
        $parts = array();
        foreach ( $stops as $stop ) { $parts[] = $stop['c'] . ' ' . round( $stop['p'] * 100, 2 ) . '%'; }
        return 'linear-gradient(' . $angle . 'deg,' . implode( ',', $parts ) . ')';
    }

    private function extract_tokens( array $payload ) {
        return array(
            'figma_styles' => Design_Core_Elementor_Change_Ledger::transport_safe( is_array( $payload['styles'] ?? null ) ? $payload['styles'] : array() ),
            'figma_components' => Design_Core_Elementor_Change_Ledger::transport_safe( is_array( $payload['components'] ?? null ) ? $payload['components'] : array() ),
            'figma_component_sets' => Design_Core_Elementor_Change_Ledger::transport_safe( is_array( $payload['componentSets'] ?? null ) ? $payload['componentSets'] : array() ),
            'figma_variables' => Design_Core_Elementor_Change_Ledger::transport_safe( is_array( $payload['variables'] ?? null ) ? $payload['variables'] : array() ),
        );
    }

    private function node_id( $figma_id ) { return 'figma-' . substr( sha1( (string) $figma_id ), 0, 14 ); }
    private function px( $value ) { return array( 'value' => round( (float) $value, 3 ), 'unit' => 'px' ); }
    private function css_px( $value ) { return rtrim( rtrim( number_format( (float) $value, 3, '.', '' ), '0' ), '.' ) . 'px'; }
    private function dimension_css( $value ) { return is_array( $value ) && isset( $value['value'] ) ? (string) $value['value'] . (string) ( $value['unit'] ?? 'px' ) : ''; }
}
