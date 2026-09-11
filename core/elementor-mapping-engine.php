<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Mapping_Engine {
    private $responsive_parser;
    private $control_mapper;
    private $mapping_report = array( 'native_controls' => array(), 'custom_css_properties' => array(), 'unsupported' => array(), 'layout_modes' => array(), 'media_height_policies' => array(), 'governance_warnings' => array(), 'control_coverage' => array() );

    public function __construct( Design_Core_Elementor_Widget_Control_Mapper $control_mapper = null ) {
        $this->responsive_parser = new Design_Core_Elementor_Responsive_Style_Parser();
        $this->control_mapper = $control_mapper ?: ( class_exists( 'Design_Core_Elementor_Widget_Control_Mapper' ) ? new Design_Core_Elementor_Widget_Control_Mapper() : null );
    }

    public function mapping_report() {
        return array(
            'native_controls' => array_values( array_unique( $this->mapping_report['native_controls'] ) ),
            'custom_css_properties' => array_values( array_unique( $this->mapping_report['custom_css_properties'] ) ),
            'unsupported' => array_values( array_unique( $this->mapping_report['unsupported'] ) ),
            'layout_modes' => array_values( array_unique( $this->mapping_report['layout_modes'] ) ),
            'media_height_policies' => array_values( array_unique( $this->mapping_report['media_height_policies'] ) ),
            'governance_warnings' => array_values( array_unique( $this->mapping_report['governance_warnings'] ) ),
            'control_coverage' => (array) ( $this->mapping_report['control_coverage'] ?? array() ),
        );
    }

    public function map_html( $html, $css = '', $directives = array() ) {
        $document = new DOMDocument();
        @$document->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
        $xpath = new DOMXPath( $document ); $body = $xpath->query( '//body' )->item( 0 ); $elements = array();
        if ( ! $body ) { return $elements; }
        foreach ( $body->childNodes as $child ) { if ( $child instanceof DOMElement ) { $mapped = $this->map_node( $child, $css, $directives ); if ( $mapped ) { $elements[] = $mapped; } } }
        return $elements;
    }

    /** Map canonical IR only. No source HTML or CSS is parsed in this path. */
    public function map_ir( $ir ) {
        ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
        $this->mapping_report = array( 'native_controls' => array(), 'custom_css_properties' => array(), 'unsupported' => array(), 'layout_modes' => array(), 'media_height_policies' => array(), 'governance_warnings' => array(), 'control_coverage' => array() );
        $nodes = array();
        foreach ( $ir['nodes'] as $node ) {
            $nodes[ $node['id'] ] = $node;
            if ( ! empty( $node['semantic']['layout_mode'] ) ) { $this->mapping_report['layout_modes'][] = $node['semantic']['layout_mode']; }
            if ( ! empty( $node['media']['height_policy'] ) ) { $this->mapping_report['media_height_policies'][] = $node['media']['height_policy']; }
            foreach ( (array) ( $node['media']['warnings'] ?? array() ) as $warning ) { $this->mapping_report['governance_warnings'][] = $node['id'] . ':' . $warning; }
        }
        $elements = array();
        foreach ( $ir['root_ids'] as $root_id ) {
            $mapped = $this->map_ir_node( $nodes[ $root_id ], $nodes );
            if ( $mapped ) { $elements[] = $mapped; }
        }
        $unsupported = array_values( array_unique( $this->mapping_report['unsupported'] ) );
        if ( $this->control_mapper && $unsupported ) {
            throw new UnexpectedValueException( 'Elementor control mapping is incomplete: ' . implode( ', ', $unsupported ) );
        }
        return $elements;
    }

    /**
     * Governed mapper used by the custom-widget/reuse-widget strategy executors: binds a
     * whole IR subtree into ONE Elementor widget element (elType=widget, widgetType=$widget_type)
     * instead of composing containers. IR-only; no source HTML/CSS is read on this path.
     */
    public function map_ir_as_widget( $ir, $widget_type ) {
        ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
        $nodes = array(); foreach ( $ir['nodes'] as $node ) { $nodes[ $node['id'] ] = $node; }
        $root_id = $ir['root_ids'][0] ?? null;
        $root = ( $root_id && isset( $nodes[ $root_id ] ) ) ? $nodes[ $root_id ] : array( 'content' => array(), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'children' => array() );
        $settings = array_merge( $this->map_ir_style( $root ), $this->widget_bindings( $root, $nodes ) );
        return $this->widget( sanitize_key( (string) $widget_type ), $settings );
    }

    /** Walks a subtree once to bind the first heading/rich-text/link/image found into the generic runtime widget's Content schema (title/description/button_text+link/image). */
    private function widget_bindings( $root, $nodes ) {
        $bindings = array();
        $queue = array( $root );
        while ( $queue ) {
            $node = array_shift( $queue );
            $tag = strtolower( $node['source']['tag'] ?? '' );
            $content = $node['content'] ?? array();
            if ( ! isset( $bindings['title'] ) && preg_match( '/^h[1-6]$/', $tag ) && '' !== trim( (string) ( $content['text'] ?? '' ) ) ) { $bindings['title'] = sanitize_text_field( $content['text'] ); }
            if ( ! isset( $bindings['description'] ) && in_array( $tag, array( 'p', 'blockquote' ), true ) && '' !== trim( (string) ( $content['rich_text'] ?? $content['text'] ?? '' ) ) ) { $bindings['description'] = wp_kses_post( $content['rich_text'] ?: $content['text'] ); }
            if ( ! isset( $bindings['link'] ) && in_array( $tag, array( 'a', 'button' ), true ) && ! empty( $content['link']['url'] ) ) {
                $bindings['button_text'] = sanitize_text_field( $content['text'] ?? '' );
                $bindings['link'] = array( 'url' => esc_url_raw( $content['link']['url'] ), 'is_external' => ! empty( $content['link']['is_external'] ), 'nofollow' => ! empty( $content['link']['nofollow'] ) );
            }
            if ( ! isset( $bindings['image'] ) && ! empty( $content['image']['url'] ) ) { $bindings['image'] = array( 'url' => esc_url_raw( $content['image']['url'] ), 'id' => 0, 'alt' => sanitize_text_field( $content['image']['alt'] ?? '' ) ); }
            foreach ( $node['children'] ?? array() as $child_id ) { if ( isset( $nodes[ $child_id ] ) ) { $queue[] = $nodes[ $child_id ]; } }
        }
        if ( ! isset( $bindings['title'] ) && '' !== trim( (string) ( $root['content']['text'] ?? '' ) ) ) { $bindings['title'] = sanitize_text_field( mb_substr( trim( $root['content']['text'] ), 0, 120 ) ); }
        return $bindings;
    }

    private function map_ir_node( $node, $nodes ) {
        $tag = strtolower( $node['source']['tag'] ?? 'div' );
        $children = array();
        foreach ( $node['children'] ?? array() as $child_id ) {
            if ( isset( $nodes[ $child_id ] ) ) { $child = $this->map_ir_node( $nodes[ $child_id ], $nodes ); if ( $child ) { $children[] = $child; } }
        }
        $content = $node['content'] ?? array();
        if ( preg_match( '/^h[1-6]$/', $tag ) ) {
            $rich = (string) ( $content['rich_text'] ?? '' );
            // Mixed inline markup (accent spans, links) cannot survive a plain
            // heading title: keep it in a text editor so runs keep styling.
            if ( '' !== $rich && preg_match( '/<(em|strong|span|a|br|i|b|u|small)\b/i', $rich ) ) {
                return $this->widget( 'text-editor', array_merge( $this->map_control_style( $node, 'widget', 'text-editor' ), array( 'editor' => $this->rich_heading_editor( $rich, $tag ) ) ) );
            }
            return $this->widget( 'heading', array_merge( $this->map_control_style( $node, 'widget', 'heading' ), array( 'title' => sanitize_text_field( $content['text'] ?? '' ), 'header_size' => $tag ) ) );
        }
        if ( in_array( $tag, array( 'p', 'blockquote' ), true ) ) { return $this->widget( 'text-editor', array_merge( $this->map_control_style( $node, 'widget', 'text-editor' ), array( 'editor' => wp_kses_post( $content['rich_text'] ?? $content['text'] ?? '' ) ) ) ); }
        if ( in_array( $tag, array( 'a', 'button' ), true ) ) { return $this->widget( 'button', array_merge( $this->map_control_style( $node, 'widget', 'button' ), array( 'text' => sanitize_text_field( $content['text'] ?? '' ), 'link' => array( 'url' => esc_url_raw( $content['link']['url'] ?? '' ), 'is_external' => ! empty( $content['link']['is_external'] ), 'nofollow' => ! empty( $content['link']['nofollow'] ) ) ) ) ); }
        if ( 'form' === $tag ) { return $this->map_form_widget( $node ); }
        if ( 'img' === $tag ) {
            $image_url = esc_url_raw( $content['image']['url'] ?? '' );
            $attachment_id = $image_url && function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $image_url ) : 0;
            return $this->widget( 'image', array_merge( $this->map_control_style( $node, 'widget', 'image' ), array( 'image' => array( 'url' => $image_url, 'id' => $attachment_id, 'alt' => sanitize_text_field( $content['image']['alt'] ?? '' ) ) ) ) );
        }
        if ( in_array( $tag, array( 'ul', 'ol' ), true ) && ! empty( $content['list'] ) ) { return $this->widget( 'icon-list', array( 'icon_list' => array_map( static function ( $text ) { return array( 'text' => sanitize_text_field( $text ) ); }, $content['list'] ) ) ); }
        if ( $children || in_array( $tag, array( 'section', 'div', 'article', 'header', 'footer', 'main', 'nav', 'aside' ), true ) ) { return $this->container( $children, $this->map_control_style( $node, 'container', '' ) ); }
        $text = trim( (string) ( $content['text'] ?? '' ) );
        return '' !== $text ? $this->widget( 'text-editor', array_merge( $this->map_control_style( $node, 'widget', 'text-editor' ), array( 'editor' => esc_html( $text ) ) ) ) : null;
    }

    /** Bind a canonical lead-capture form to validated Elementor Pro Form controls. */
    private function map_form_widget( array $node ) {
        $settings = $this->map_control_style( $node, 'widget', 'form' );
        $content = is_array( $node['content'] ?? null ) ? $node['content'] : array();
        $controls = array(
            'form_fields' => 'form-fields-repeater', 'form_name' => 'text', 'button_text' => 'text',
            'show_labels' => 'switcher', 'mark_required' => 'switcher', 'submit_actions' => 'select2', 'input_size' => 'select',
        );
        $registry = $this->control_mapper ? $this->control_mapper->registry() : null;
        foreach ( $controls as $control => $type ) {
            if ( ! $registry || ! $registry->first_supported( 'widget', 'form', array( $control ), $type ) ) {
                $this->mapping_report['unsupported'][] = 'form_' . $control;
            } else {
                $this->mapping_report['native_controls'][] = $control;
            }
        }

        $allowed_types = array( 'text', 'email', 'textarea', 'url', 'tel', 'number', 'date', 'time' );
        $allowed_widths = array( '', '20', '25', '30', '33', '40', '50', '60', '66', '70', '75', '80', '100' );
        $fields = array();
        foreach ( (array) ( $content['fields'] ?? array() ) as $index => $field ) {
            if ( ! is_array( $field ) ) { continue; }
            $custom_id = sanitize_key( (string) ( $field['id'] ?? 'field_' . ( $index + 1 ) ) );
            $type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
            if ( ! in_array( $type, $allowed_types, true ) ) { $type = 'text'; }
            $item = array(
                '_id' => substr( md5( (string) ( $node['id'] ?? 'form' ) . '|' . $custom_id ), 0, 7 ),
                'custom_id' => $custom_id,
                'field_type' => $type,
                'field_label' => sanitize_text_field( $field['label'] ?? '' ),
                'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
                'required' => ! empty( $field['required'] ) ? 'true' : '',
            );
            foreach ( array( 'width', 'width_laptop', 'width_tablet', 'width_mobile' ) as $width_key ) {
                $width = (string) ( $field[ $width_key ] ?? '' );
                if ( in_array( $width, $allowed_widths, true ) ) { $item[ $width_key ] = $width; }
            }
            $fields[] = $item;
        }
        $settings['form_fields'] = $fields;
        $settings['form_name'] = sanitize_text_field( $content['form_name'] ?? 'Lead capture' );
        $settings['button_text'] = sanitize_text_field( $content['button_text'] ?? 'Submit' );
        $settings['show_labels'] = ! empty( $content['show_labels'] ) ? 'yes' : '';
        $settings['mark_required'] = ! empty( $content['mark_required'] ) ? 'yes' : '';
        $settings['submit_actions'] = array_values( array_filter( array_map( 'sanitize_key', (array) ( $content['submit_actions'] ?? array() ) ) ) );
        $settings['input_size'] = sanitize_key( (string) ( $content['input_size'] ?? 'md' ) );
        return $this->widget( 'form', $settings );
    }

    private function map_control_style( $node, $element_type, $widget_type ) {
        if ( $this->control_mapper ) {
            $result = $this->control_mapper->map( $element_type, $widget_type, $node );
            $this->mapping_report['native_controls'] = array_merge( $this->mapping_report['native_controls'], $result['native'] ?? array() );
            $this->mapping_report['custom_css_properties'] = array_merge( $this->mapping_report['custom_css_properties'], $result['custom_css_properties'] ?? array() );
            $this->mapping_report['unsupported'] = array_merge( $this->mapping_report['unsupported'], $result['unsupported'] ?? array() );
            if ( class_exists( 'Design_Core_Elementor_Control_Coverage_Intelligence' ) ) {
                $coverage = ( new Design_Core_Elementor_Control_Coverage_Intelligence() )->evaluate( $node, $element_type, $widget_type, $result );
                $node_id = (string) ( $node['id'] ?? '' );
                if ( '' !== $node_id ) { $this->mapping_report['control_coverage'][ $node_id ] = $coverage; }
            }
            return is_array( $result['settings'] ?? null ) ? $result['settings'] : array();
        }
        return $this->map_ir_style( $node );
    }

    private function map_ir_style( $node ) {
        $settings = array();
        $desktop = array_merge( $node['layout'] ?? array(), $node['style'] ?? array(), $node['spacing'] ?? array() );
        $settings = $this->map_ir_values( $desktop );
        foreach ( $node['responsive'] ?? array() as $device => $values ) {
            foreach ( $this->map_ir_values( $values ) as $key => $value ) { $settings[ $key . '_' . $device ] = $value; }
        }
        return $settings;
    }

    private function map_ir_values( $values ) {
        $settings = array();
        $simple = array( 'display' => 'display', 'direction' => 'flex_direction', 'wrap' => 'flex_wrap', 'justify' => 'flex_justify_content', 'align' => 'flex_align_items', 'overflow' => 'overflow', 'position' => 'position', 'z_index' => 'z_index', 'color' => 'text_color', 'background' => 'background_color', 'opacity' => 'opacity' );
        foreach ( $simple as $source => $target ) { if ( isset( $values[ $source ] ) && ! is_array( $values[ $source ] ) ) { $settings[ $target ] = $values[ $source ]; } }
        $dimensions = array( 'gap' => 'flex_gap', 'width' => 'width', 'max_width' => 'content_width', 'min_height' => 'min_height', 'font_size' => 'typography_font_size', 'line_height' => 'typography_line_height', 'letter_spacing' => 'typography_letter_spacing' );
        foreach ( $dimensions as $source => $target ) { if ( isset( $values[ $source ]['value'] ) ) { $settings[ $target ] = array( 'size' => $values[ $source ]['value'], 'unit' => $values[ $source ]['unit'] ); } }
        foreach ( array( 'padding', 'margin', 'radius' ) as $source ) { if ( isset( $values[ $source ] ) && is_array( $values[ $source ] ) ) { $settings[ 'radius' === $source ? 'border_radius' : $source ] = $this->ir_dimensions( $values[ $source ] ); } }
        return $settings;
    }

    private function ir_dimensions( $value ) {
        if ( isset( $value['value'] ) ) { $size = (string) $value['value']; $unit = $value['unit']; return array( 'unit' => $unit, 'top' => $size, 'right' => $size, 'bottom' => $size, 'left' => $size, 'isLinked' => true ); }
        $unit = $value['unit'] ?? 'px';
        return array( 'unit' => $unit, 'top' => (string) ( $value['top'] ?? 0 ), 'right' => (string) ( $value['right'] ?? 0 ), 'bottom' => (string) ( $value['bottom'] ?? 0 ), 'left' => (string) ( $value['left'] ?? 0 ), 'isLinked' => false );
    }

    private function map_node( DOMElement $node, $css, $directives = array(), $ignore_strategy = false ) {
        $tag = strtolower( $node->tagName ); $signature = $this->component_signature( $node ); $directive = $ignore_strategy ? null : ( $directives[ $signature ] ?? null );
        if ( $directive && 'reuse' === ( $directive['strategy'] ?? '' ) ) {
            $stored = $directive['item']['elementor_elements'] ?? array();
            if ( ! empty( $stored ) ) { $current = $this->map_node( $node, $css, array(), true ); $blueprint = count($stored)===1?$stored[0]:$this->container($stored); return $this->apply_instance_content( $this->regenerate_ids($blueprint), $current ); }
        }
        if ( $directive && 'custom-widget' === ( $directive['strategy'] ?? '' ) ) {
            $widget_type = $directive['item']['widget_type'] ?? $directive['item']['slug'] ?? ''; if ( $widget_type ) { return $this->widget( $widget_type, array() ); }
        }
        if ( in_array( $tag, array('section','div','article','header','footer','main','nav','aside'), true ) ) { return $this->map_container( $node, $css, $directives ); }
        if ( preg_match( '/^h[1-6]$/', $tag ) ) { return $this->widget( 'heading', array_merge( array('title'=>trim($node->textContent),'header_size'=>$tag), $this->infer_widget_style_settings($node,$css) ) ); }
        if ( 'p' === $tag || 'blockquote' === $tag ) { return $this->widget( 'text-editor', array_merge( array('editor'=>wp_kses_post($node->ownerDocument->saveHTML($node))), $this->infer_widget_style_settings($node,$css) ) ); }
        if ( 'a' === $tag ) {
            $parent_tag = $node->parentNode instanceof DOMElement ? strtolower($node->parentNode->tagName) : '';
            if ( 'nav' !== $parent_tag ) { return $this->widget( 'button', array_merge( array('text'=>trim($node->textContent),'link'=>array('url'=>esc_url_raw($node->getAttribute('href')),'is_external'=>'_blank'===$node->getAttribute('target'),'nofollow'=>false)), $this->infer_widget_style_settings($node,$css) ) ); }
        }
        if ( 'img' === $tag ) { return $this->widget( 'image', array_merge( array('image'=>array('url'=>esc_url_raw($node->getAttribute('src')),'id'=>0,'alt'=>sanitize_text_field($node->getAttribute('alt')))), $this->infer_widget_style_settings($node,$css) ) ); }
        if ( 'ul' === $tag ) {
            $items=array(); foreach($node->getElementsByTagName('li') as $li){$items[]=array('text'=>trim($li->textContent));} if($items){return $this->widget('icon-list',array('icon_list'=>$items));}
        }
        $children=array(); foreach($node->childNodes as $child){if($child instanceof DOMElement){$mapped=$this->map_node($child,$css,$directives);if($mapped){$children[]=$mapped;}}}
        if($children){return $this->container($children,$this->infer_container_settings($node,$css));}
        $text=trim($node->textContent); return ''!==$text?$this->widget('text-editor',array('editor'=>esc_html($text))):null;
    }

    private function map_container( DOMElement $node, $css, $directives ) {
        $children=array(); foreach($node->childNodes as $child){if($child instanceof DOMElement){$mapped=$this->map_node($child,$css,$directives);if($mapped){$children[]=$mapped;}}}
        return $this->container($children,$this->infer_container_settings($node,$css));
    }

    private function infer_container_settings( DOMElement $node, $css ) {
        $responsive=$this->responsive_parser->resolve_for_node($node,$css); $settings=$this->map_container_declarations($responsive['desktop']??array());
        foreach($responsive as $device=>$declarations){if('desktop'===$device||empty($declarations)){continue;}foreach($this->map_container_declarations($declarations) as $key=>$value){$settings[$key.'_'.$device]=$value;}}
        return $settings;
    }

    private function map_container_declarations( $d ) {
        $s=array(); $map=array('flex-direction'=>'flex_direction','justify-content'=>'flex_justify_content','align-items'=>'flex_align_items','align-content'=>'flex_align_content','flex-wrap'=>'flex_wrap','overflow'=>'overflow','position'=>'position','z-index'=>'z_index');
        foreach($map as $css=>$setting){if(isset($d[$css])){$s[$setting]=strtolower(trim($d[$css]));}}
        if(isset($d['display'])){$s['display']=strtolower(trim($d['display']));}
        if(isset($d['gap'])){$s['flex_gap']=$this->dimension($d['gap']);}
        if(isset($d['row-gap'])){$s['row_gap']=$this->dimension($d['row-gap']);}
        if(isset($d['column-gap'])){$s['column_gap']=$this->dimension($d['column-gap']);}
        foreach(array('width'=>'width','max-width'=>'content_width','min-width'=>'min_width','height'=>'height','min-height'=>'min_height') as $css=>$setting){if(isset($d[$css])){$s[$setting]=$this->dimension($d[$css]);}}
        foreach(array('padding'=>'padding','margin'=>'margin','border-radius'=>'border_radius') as $css=>$setting){if(isset($d[$css])){$s[$setting]=$this->dimensions($d[$css]);}}
        if(isset($d['background-color'])){$s['background_background']='classic';$s['background_color']=trim($d['background-color']);}
        if(isset($d['background-image'])&&preg_match('/url\(["\']?([^"\')]+)["\']?\)/',$d['background-image'],$m)){$s['background_background']='classic';$s['background_image']=array('url'=>esc_url_raw($m[1]),'id'=>0);}
        if(isset($d['border'])||isset($d['border-width'])){$s['border_border']='solid';}
        if(isset($d['border-color'])){$s['border_color']=trim($d['border-color']);}
        if(isset($d['box-shadow'])){$s['box_shadow_box_shadow_type']='yes';$s['box_shadow_raw']=trim($d['box-shadow']);}
        foreach(array('top','right','bottom','left') as $side){if(isset($d[$side])){$s['_offset_'.$side]=$this->dimension($d[$side]);}}
        if(isset($d['opacity'])){$s['opacity']=array('size'=>(float)$d['opacity'],'unit'=>'px');}
        return $s;
    }

    private function infer_widget_style_settings( DOMElement $node, $css ) {
        $responsive=$this->responsive_parser->resolve_for_node($node,$css); $settings=$this->map_widget_declarations($responsive['desktop']??array());
        foreach($responsive as $device=>$declarations){if('desktop'===$device||empty($declarations)){continue;}foreach($this->map_widget_declarations($declarations) as $key=>$value){$settings[$key.'_'.$device]=$value;}}
        return $settings;
    }

    private function map_widget_declarations( $d ) {
        $s=array();
        if(isset($d['color'])){$s['title_color']=trim($d['color']);$s['text_color']=trim($d['color']);}
        $typography=array('font-family'=>'typography_font_family','font-weight'=>'typography_font_weight','text-align'=>'align','text-transform'=>'typography_text_transform','font-style'=>'typography_font_style','text-decoration'=>'typography_text_decoration');
        foreach($typography as $css=>$setting){if(isset($d[$css])){$s[$setting]=trim($d[$css]);}}
        foreach(array('font-size'=>'typography_font_size','line-height'=>'typography_line_height','letter-spacing'=>'typography_letter_spacing','width'=>'width','max-width'=>'max_width') as $css=>$setting){if(isset($d[$css])){$s[$setting]=$this->dimension($d[$css]);}}
        foreach(array('padding'=>'padding','margin'=>'margin','border-radius'=>'border_radius') as $css=>$setting){if(isset($d[$css])){$s[$setting]=$this->dimensions($d[$css]);}}
        if(isset($d['background-color'])){$s['background_color']=trim($d['background-color']);}
        if(isset($d['opacity'])){$s['opacity']=array('size'=>(float)$d['opacity'],'unit'=>'px');}
        return $s;
    }

    private function dimension( $value ) { if(preg_match('/(-?[0-9.]+)\s*(px|%|em|rem|vh|vw|vmin|vmax)?/i',trim((string)$value),$m)){return array('size'=>(float)$m[1],'unit'=>strtolower($m[2]?:'px'));} return array('size'=>0,'unit'=>'px'); }
    private function dimensions( $value ) {
        $parts=array_values(array_filter(preg_split('/\s+/',trim((string)$value)))); if(empty($parts)){$parts=array('0');}
        if(1===count($parts)){$parts=array($parts[0],$parts[0],$parts[0],$parts[0]);}elseif(2===count($parts)){$parts=array($parts[0],$parts[1],$parts[0],$parts[1]);}elseif(3===count($parts)){$parts=array($parts[0],$parts[1],$parts[2],$parts[1]);}
        $first=$this->dimension($parts[0]); return array('unit'=>$first['unit'],'top'=>(string)$this->dimension($parts[0])['size'],'right'=>(string)$this->dimension($parts[1])['size'],'bottom'=>(string)$this->dimension($parts[2])['size'],'left'=>(string)$this->dimension($parts[3])['size'],'isLinked'=>count(array_unique($parts))===1);
    }

    private function component_signature( DOMElement $node ) { $child_tags=array();$grandchild_count=0;foreach($node->childNodes as $child){if($child instanceof DOMElement){$child_tags[]=strtolower($child->tagName);$grandchild_count+=$child->childNodes->length;}}return strtolower($node->tagName).'|'.implode(',',$child_tags).'|n'.count($child_tags).'|g'.$grandchild_count; }
    private function apply_instance_content( $blueprint, $current ) { if(!is_array($blueprint)||!is_array($current)){return $blueprint;}if('widget'===($blueprint['elType']??'')&&($blueprint['widgetType']??'')===($current['widgetType']??'')){foreach(array('title','editor','text','link','image','icon_list') as $key){if(array_key_exists($key,$current['settings']??array())){$blueprint['settings'][$key]=$current['settings'][$key];}}}$current_children=$current['elements']??array();foreach($blueprint['elements']??array() as $i=>$child){if(isset($current_children[$i])){$blueprint['elements'][$i]=$this->apply_instance_content($child,$current_children[$i]);}}return $blueprint; }
    private function regenerate_ids( $element ) { if(!is_array($element)){return $element;}if(isset($element['id'])){$element['id']=$this->element_id();}foreach($element['elements']??array() as $i=>$child){$element['elements'][$i]=$this->regenerate_ids($child);}return $element; }
    private function container( $children, $settings=array() ) {
        if ( class_exists( 'Design_Core_Elementor_Binding_Governor' ) ) {
            $notes = array();
            $settings = Design_Core_Elementor_Binding_Governor::govern_settings( 'container', '', is_array( $settings ) ? $settings : array(), $this->control_mapper ? $this->control_mapper->registry() : null, $notes );
        }
        return array('id'=>$this->element_id(),'elType'=>'container','isInner'=>false,'settings'=>$settings,'elements'=>array_values($children));
    }
    /**
     * Designer-authored heading markup keeps its inline runs (accent spans,
     * links, breaks). Only text-level tags survive, with designer styles
     * intact -- the source file itself is the trust boundary here.
     */
    private function rich_heading_editor( $html, $tag ) {
        $allowed = array(
            'h1' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'h5' => array(), 'h6' => array(),
            'em' => array( 'style' => true, 'class' => true ), 'i' => array( 'style' => true, 'class' => true ),
            'strong' => array( 'style' => true, 'class' => true ), 'b' => array( 'style' => true, 'class' => true ),
            'u' => array( 'style' => true, 'class' => true ), 'small' => array( 'style' => true, 'class' => true ),
            'span' => array( 'style' => true, 'class' => true ), 'br' => array(),
            'a' => array( 'href' => true, 'title' => true, 'target' => true, 'rel' => true, 'style' => true, 'class' => true ),
        );
        $tag = in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $tag : 'h2';
        if ( ! preg_match( '/^\s*<' . $tag . '\b/i', (string) $html ) ) { $html = '<' . $tag . '>' . $html . '</' . $tag . '>'; }
        return wp_kses( (string) $html, $allowed );
    }
    private function widget( $widget_type, $settings ) {
        if ( class_exists( 'Design_Core_Elementor_Binding_Governor' ) ) {
            $notes = array();
            $settings = Design_Core_Elementor_Binding_Governor::govern_settings( 'widget', $widget_type, is_array( $settings ) ? $settings : array(), $this->control_mapper ? $this->control_mapper->registry() : null, $notes );
        }
        return array('id'=>$this->element_id(),'elType'=>'widget','widgetType'=>$widget_type,'settings'=>$settings,'elements'=>array());
    }
    private function element_id() { return substr(md5(uniqid('',true)),0,8); }
}
