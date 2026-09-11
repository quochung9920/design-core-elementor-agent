<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Analysis_Engine {
    public function analyze_html( $html, $css = '', $source_name = 'source' ) {
        $security = new Design_Core_Elementor_Security_Policy();
        $size_check = $security->validate_source_size( $html, $css );
        if ( is_wp_error( $size_check ) ) {
            return array( 'error' => $size_check->get_error_code(), 'message' => $size_check->get_error_message(), 'design_ir' => array() );
        }
        $complexity_check = $this->validate_html_complexity( (string) $html );
        if ( is_wp_error( $complexity_check ) ) {
            return array( 'error' => $complexity_check->get_error_code(), 'message' => $complexity_check->get_error_message(), 'design_ir' => array() );
        }

        $document = new DOMDocument();
        @$document->loadHTML( '<?xml encoding="UTF-8">' . $html );
        $xpath = new DOMXPath( $document );
        $effective_css = $this->effective_css( $document, (string) $css );
        $effective_css = $this->resolve_root_variables( $effective_css );
        $sections = $this->extract_sections( $xpath );
        $tokens = $this->extract_tokens( $effective_css );
        $components = $this->detect_components( $xpath, $effective_css );
        $nodes = $this->extract_ir_nodes( $xpath, $effective_css );
        $token_count = $this->count_tokens( $tokens );

        $result = array(
            'source_name' => sanitize_text_field( $source_name ),
            'summary' => array( 'section_count' => count( $sections ), 'token_count' => $token_count, 'component_count' => count( $components ) ),
            'tokens' => $tokens,
            'components' => $components,
            'sections' => $sections,
            'nodes' => $nodes,
            'source_html' => (string) $html,
            'breakpoints' => ( new Design_Core_Elementor_Breakpoint_Registry() )->all(),
            'browser_analysis' => array( 'status' => 'unavailable' ),
            'recommendation' => array( 'strategy' => 'native-compose', 'notes' => 'Prefer native Elementor controls, preserve source component atoms, reuse registered assets, then scoped fallbacks.' ),
            'diagnostics' => array(
                'inline_stylesheet_count' => $this->inline_stylesheet_count( $document ),
                'effective_css_bytes' => strlen( $effective_css ),
            ),
        );

        if ( apply_filters( 'design_core_elementor_run_browser_analysis', true, $html, $effective_css ) ) {
            $browser = new Design_Core_Elementor_Browser_Analysis_Service();
            if ( $browser->is_available() ) {
                $browser_result = $browser->analyze_html( $html, $effective_css );
                $result['browser_analysis'] = is_wp_error( $browser_result )
                    ? array( 'status' => 'failed', 'error' => $browser_result->get_error_message() )
                    : array( 'status' => 'success', 'data' => $browser_result );
            }
        }

        $design_ir = ( new Design_Core_Elementor_Design_IR() )->build_from_analysis( $result );
        if ( class_exists( 'Design_Core_Elementor_Layout_Media_Analyzer' ) ) {
            $layout_media_analyzer = new Design_Core_Elementor_Layout_Media_Analyzer();
            $design_ir = $layout_media_analyzer->enrich( $design_ir, $result['browser_analysis'] );
            $result['nodes'] = $design_ir['nodes'];
            $result['diagnostics'] = array_merge( $result['diagnostics'], (array) ( $design_ir['diagnostics'] ?? array() ) );
            $design_ir['diagnostics'] = $result['diagnostics'];
            $result['layout_media_analysis'] = $layout_media_analyzer->report( $design_ir );
        }
        $result['design_ir'] = $design_ir;
        return $result;
    }

    /** Embedded <style> blocks are part of the source design contract. */
    private function effective_css( DOMDocument $document, $css ) {
        $parts = array();
        if ( '' !== trim( (string) $css ) ) { $parts[] = (string) $css; }
        foreach ( $document->getElementsByTagName( 'style' ) as $style ) {
            $value = trim( (string) $style->textContent );
            if ( '' !== $value ) { $parts[] = $value; }
        }
        return implode( "\n", $parts );
    }

    private function inline_stylesheet_count( DOMDocument $document ) { return $document->getElementsByTagName( 'style' )->length; }

    /** Resolve only :root custom properties. Contextual variables remain CSS fallback. */
    private function resolve_root_variables( $css ) {
        $variables = array();
        if ( preg_match_all( '/:root\s*\{([^}]*)\}/is', (string) $css, $blocks ) ) {
            foreach ( $blocks[1] as $block ) {
                if ( preg_match_all( '/(--[a-z0-9_-]+)\s*:\s*([^;}{]+)\s*;?/i', $block, $matches, PREG_SET_ORDER ) ) {
                    foreach ( $matches as $match ) { $variables[ strtolower( $match[1] ) ] = trim( $match[2] ); }
                }
            }
        }
        if ( ! $variables ) { return (string) $css; }
        $resolved = (string) $css;
        for ( $pass = 0; $pass < 8; $pass++ ) {
            $before = $resolved;
            $resolved = preg_replace_callback( '/var\(\s*(--[a-z0-9_-]+)\s*(?:,\s*([^\)]+))?\)/i', static function ( $match ) use ( $variables ) {
                $key = strtolower( $match[1] );
                if ( isset( $variables[ $key ] ) ) { return $variables[ $key ]; }
                return isset( $match[2] ) ? trim( $match[2] ) : $match[0];
            }, $resolved );
            if ( $resolved === $before ) { break; }
        }
        return $resolved;
    }

    private function validate_html_complexity( $html ) {
        $max_elements = 2500; $max_depth = 128; $elements = 0; $depth = 0; $open_tags = array(); $offset = 0; $length = strlen( $html );
        $void_tags = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );
        while ( $offset < $length && false !== ( $start = strpos( $html, '<', $offset ) ) ) {
            if ( 0 === substr_compare( $html, '<!--', $start, 4 ) ) { $comment_end = strpos( $html, '-->', $start + 4 ); $offset = false === $comment_end ? $length : $comment_end + 3; continue; }
            $cursor = $start + 1; $closing = false;
            while ( $cursor < $length && ctype_space( $html[ $cursor ] ) ) { $cursor++; }
            if ( $cursor < $length && '/' === $html[ $cursor ] ) { $closing = true; $cursor++; while ( $cursor < $length && ctype_space( $html[ $cursor ] ) ) { $cursor++; } }
            if ( $cursor >= $length || ! ctype_alpha( $html[ $cursor ] ) ) { $offset = $start + 1; continue; }
            $name_start = $cursor;
            while ( $cursor < $length && ( ctype_alnum( $html[ $cursor ] ) || in_array( $html[ $cursor ], array( '-', ':', '_' ), true ) ) ) { $cursor++; }
            $tag = strtolower( substr( $html, $name_start, $cursor - $name_start ) ); $quote = ''; $end = $cursor;
            for ( ; $end < $length; $end++ ) {
                $character = $html[ $end ];
                if ( '' !== $quote ) { if ( $character === $quote ) { $quote = ''; } continue; }
                if ( '"' === $character || "'" === $character ) { $quote = $character; continue; }
                if ( '>' === $character ) { break; }
            }
            if ( $end >= $length ) { break; }
            if ( $closing ) {
                if ( $open_tags && end( $open_tags ) === $tag ) { array_pop( $open_tags ); $depth = count( $open_tags ); }
                $offset = $end + 1; continue;
            }
            $elements++;
            if ( $elements > $max_elements ) { return new WP_Error( 'design_core_html_element_limit', 'HTML contains too many elements for bounded analysis.' ); }
            $before_end = rtrim( substr( $html, $cursor, $end - $cursor ) );
            if ( ! in_array( $tag, $void_tags, true ) && ( '' === $before_end || '/' !== substr( $before_end, -1 ) ) ) {
                $open_tags[] = $tag; $depth = count( $open_tags );
                if ( $depth > $max_depth ) { return new WP_Error( 'design_core_html_depth_limit', 'HTML nesting depth exceeds the bounded analysis limit.' ); }
            }
            $offset = $end + 1;
        }
        return true;
    }

    private function extract_sections( DOMXPath $xpath ) {
        $sections = array();
        $blocks = $xpath->query( '//*[self::header or self::main or self::section or self::footer or self::article or self::nav]' );
        foreach ( $blocks ?: array() as $index => $block ) {
            if ( ! $block instanceof DOMElement ) { continue; }
            $sections[] = array(
                'index' => $index,
                'tag' => strtolower( $block->tagName ),
                'classes' => array_values( array_filter( preg_split( '/\s+/', trim( $block->getAttribute( 'class' ) ) ) ) ),
                'semantic_role' => $this->infer_semantic_role( $block ),
                'text_length' => strlen( trim( $block->textContent ) ),
                'children_count' => $block->childNodes->length,
                'source_html' => $block->ownerDocument->saveHTML( $block ),
            );
        }
        return $sections;
    }

    private function detect_components( DOMXPath $xpath, $css ) {
        $groups = array();
        foreach ( $xpath->query( '//*[self::article or self::li or self::div or self::section]' ) ?: array() as $node ) {
            if ( ! $node instanceof DOMElement ) { continue; }
            $signature = $this->component_signature( $node );
            $groups[ $signature ][] = $node;
        }
        $components = array();
        foreach ( $groups as $signature => $nodes ) {
            if ( count( $nodes ) < 2 ) { continue; }
            $sample = $nodes[0];
            $sample_html = $sample->ownerDocument->saveHTML( $sample );
            $semantic = $this->infer_semantic_role( $sample );
            $dynamic = $this->has_dynamic_evidence( $sample, $semantic );
            $schema = $this->content_schema( $sample );
            $components[] = array(
                'signature' => $signature,
                'type' => $semantic,
                'kind' => $dynamic ? 'dynamic-repeatable-block' : 'repeatable-block',
                'html' => $sample_html,
                'tokens' => $this->extract_component_tokens( $sample_html . "\n" . $css ),
                'instance_count' => count( $nodes ),
                'repeated' => true,
                'dynamic_evidence' => $dynamic,
                'classes' => array_values( array_filter( preg_split( '/\s+/', trim( $sample->getAttribute( 'class' ) ) ) ) ),
                'content_schema' => $schema,
                'fingerprint' => array( 'signature' => $signature, 'semantic' => $semantic, 'content_schema' => $schema ),
            );
        }
        return $components;
    }

    private function has_dynamic_evidence( DOMElement $node, $semantic ) {
        if ( in_array( $semantic, array( 'product', 'post', 'listing' ), true ) ) { return true; }
        $attrs = strtolower( $node->getAttribute( 'class' ) . ' ' . $node->getAttribute( 'data-source' ) . ' ' . $node->getAttribute( 'data-query' ) );
        return (bool) preg_match( '/woocommerce|product|posts?|query|dynamic|loop|collection|listing/', $attrs );
    }

    private function component_signature( DOMElement $node ) {
        $child_tags = array(); $grandchild_count = 0;
        foreach ( $node->childNodes as $child ) {
            if ( $child instanceof DOMElement && ! $this->ignorable_source_element( $child ) ) { $child_tags[] = strtolower( $child->tagName ); $grandchild_count += $child->childNodes->length; }
        }
        return strtolower( $node->tagName ) . '|' . implode( ',', $child_tags ) . '|n' . count( $child_tags ) . '|g' . $grandchild_count;
    }

    /** Direct text only so a wrapper is never classified from an incidental descendant keyword. */
    private function direct_text( DOMElement $node ) {
        $text = '';
        foreach ( $node->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) { $text .= ' ' . $child->textContent; }
        }
        return trim( $text );
    }

    private function infer_semantic_role( DOMElement $node ) {
        // Structural tags with unambiguous semantics win over class-keyword
        // guessing: a <form class="form-card"> is a form, never a feature card.
        $tag_roles = array( 'form' => 'form', 'table' => 'data-table', 'nav' => 'navigation', 'details' => 'faq' );
        $tag = strtolower( $node->tagName );
        if ( isset( $tag_roles[ $tag ] ) ) { return $tag_roles[ $tag ]; }
        $haystack = strtolower( $node->tagName . ' ' . $node->getAttribute( 'class' ) . ' ' . $node->getAttribute( 'id' ) . ' ' . substr( $this->direct_text( $node ), 0, 120 ) );
        $patterns = array(
            'hero' => '/hero|jumbotron/', 'cta' => '/cta|call[-_ ]?to[-_ ]?action/', 'testimonial' => '/testimonial|review|quote/',
            'pricing-calculator' => '/pricing.{0,20}calculator|price.{0,20}calculator/', 'pricing' => '/pricing|plan|package/',
            'product-comparison' => '/comparison|compare.{0,20}product/', 'faq' => '/faq|question|accordion/',
            'feature-card' => '/feature|benefit|service|card/', 'product' => '/product|woocommerce/', 'post' => '/post|article|blog/',
            'navigation' => '/nav|menu/', 'footer' => '/footer/'
        );
        foreach ( $patterns as $role => $pattern ) { if ( preg_match( $pattern, $haystack ) ) { return $role; } }
        return strtolower( $node->tagName );
    }

    private function content_schema( DOMElement $node ) {
        $schema = array();
        if ( $node->getElementsByTagName( 'img' )->length || 'img' === strtolower( $node->tagName ) ) { $schema[] = 'media'; }
        for ( $i = 1; $i <= 6; $i++ ) { if ( $node->getElementsByTagName( 'h' . $i )->length || 'h' . $i === strtolower( $node->tagName ) ) { $schema[] = 'heading'; break; } }
        if ( $node->getElementsByTagName( 'p' )->length || $node->getElementsByTagName( 'blockquote' )->length || in_array( strtolower( $node->tagName ), array( 'p', 'blockquote' ), true ) ) { $schema[] = 'rich_text'; }
        if ( $node->getElementsByTagName( 'a' )->length || 'a' === strtolower( $node->tagName ) ) { $schema[] = 'link'; }
        if ( $node->getElementsByTagName( 'ul' )->length || $node->getElementsByTagName( 'ol' )->length || in_array( strtolower( $node->tagName ), array( 'ul', 'ol' ), true ) ) { $schema[] = 'list'; }
        if ( $node->getElementsByTagName( 'form' )->length || $node->getElementsByTagName( 'input' )->length || 'form' === strtolower( $node->tagName ) ) { $schema[] = 'interactive'; }
        return array_values( array_unique( $schema ) );
    }

    private function extract_component_tokens( $source ) {
        $tokens = array();
        if ( preg_match( '/#[0-9a-f]{3,8}\b/i', $source, $m ) ) { $tokens['color'] = $m[0]; }
        if ( preg_match( '/font-size\s*:\s*([^;]+)/i', $source, $m ) ) { $tokens['font_size'] = trim( $m[1] ); }
        if ( preg_match( '/border-radius\s*:\s*([^;]+)/i', $source, $m ) ) { $tokens['radius'] = trim( $m[1] ); }
        return $tokens;
    }

    private function extract_tokens( $css ) {
        $result = array( 'colors'=>array(), 'typography'=>array(), 'spacing'=>array(), 'radius'=>array(), 'shadows'=>array(), 'variables'=>array() );
        if ( ! $css ) { return $result; }
        preg_match_all( '/#[0-9a-fA-F]{3,8}\b|rgba?\([^\)]+\)|hsla?\([^\)]+\)/', $css, $colors );
        $result['colors'] = array_values( array_unique( $colors[0] ?? array() ) );
        foreach ( array( 'font-family','font-size','font-weight','line-height','letter-spacing' ) as $prop ) {
            preg_match_all( '/' . preg_quote( $prop, '/' ) . '\s*:\s*([^;}{]+)/i', $css, $m );
            if ( ! empty( $m[1] ) ) { $result['typography'][ $prop ] = array_values( array_unique( array_map( 'trim', $m[1] ) ) ); }
        }
        foreach ( array( 'padding','margin','gap' ) as $prop ) {
            preg_match_all( '/' . $prop . '\s*:\s*([^;}{]+)/i', $css, $m );
            if ( ! empty( $m[1] ) ) { $result['spacing'][ $prop ] = array_values( array_unique( array_map( 'trim', $m[1] ) ) ); }
        }
        preg_match_all( '/border-radius\s*:\s*([^;}{]+)/i', $css, $radius ); $result['radius'] = array_values( array_unique( array_map( 'trim', $radius[1] ?? array() ) ) );
        preg_match_all( '/box-shadow\s*:\s*([^;}{]+)/i', $css, $shadows ); $result['shadows'] = array_values( array_unique( array_map( 'trim', $shadows[1] ?? array() ) ) );
        preg_match_all( '/(--[a-z0-9_-]+)\s*:\s*([^;}{]+)/i', $css, $vars, PREG_SET_ORDER );
        foreach ( $vars as $var ) { $result['variables'][ $var[1] ] = trim( $var[2] ); }
        return $result;
    }

    private function count_tokens( $tokens ) { $count = 0; foreach ( $tokens as $group ) { $count += is_array( $group ) ? count( $group ) : 0; } return $count; }

    private function extract_ir_nodes( DOMXPath $xpath, $css ) {
        $nodes = array();
        $roots = $xpath->query( '//body/*' );
        if ( ! $roots || ! $roots->length ) { $roots = $xpath->query( '/*/*' ); }
        $visible_index = 0;
        foreach ( $roots ?: array() as $root ) {
            if ( ! $root instanceof DOMElement || $this->ignorable_source_element( $root ) ) { continue; }
            $visible_index++;
            $this->extract_ir_node( $root, '/body/' . strtolower( $root->tagName ) . '[' . $visible_index . ']', $css, $nodes );
        }
        return $nodes;
    }

    private function ignorable_source_element( DOMElement $element ) {
        return in_array( strtolower( $element->tagName ), array( 'style', 'script', 'link', 'meta', 'title', 'base', 'noscript', 'template' ), true );
    }

    private function extract_ir_node( DOMElement $element, $path, $css, &$nodes ) {
        $id = 'node-' . substr( md5( $path ), 0, 12 );
        $children = array(); $child_elements = array(); $element_index = 0;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || $this->ignorable_source_element( $child ) ) { continue; }
            $element_index++; $child_path = $path . '/' . strtolower( $child->tagName ) . '[' . $element_index . ']';
            $children[] = $this->extract_ir_node( $child, $child_path, $css, $nodes ); $child_elements[] = $child;
        }
        $this->mark_repeated_siblings( $children, $child_elements, $nodes );
        $tag = strtolower( $element->tagName ); $role = $this->infer_semantic_role( $element );
        $classes = array_values( array_filter( preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) ) ) );
        $attributes = array(); foreach ( $element->attributes ?: array() as $attribute ) { if ( ! in_array( strtolower( $attribute->name ), array( 'style', 'onload', 'onclick', 'onerror' ), true ) ) { $attributes[ sanitize_key( $attribute->name ) ] = sanitize_text_field( $attribute->value ); } }
        $content_schema = $this->content_schema( $element );
        $responsive = ( new Design_Core_Elementor_Responsive_Style_Parser() )->resolve_for_node( $element, $css );
        $desktop = $this->declarations_to_ir( $responsive['desktop'] ?? array() ); unset( $responsive['desktop'] );
        $normalized_responsive = array(); foreach ( $responsive as $device => $declarations ) { $values = $this->declarations_to_ir( $declarations ); if ( $this->has_ir_values( $values ) ) { $normalized_responsive[ sanitize_key( $device ) ] = $values; } }
        $list = array(); if ( in_array( $tag, array( 'ul', 'ol' ), true ) ) { foreach ( $element->childNodes as $child ) { if ( $child instanceof DOMElement && 'li' === strtolower( $child->tagName ) ) { $list[] = trim( $child->textContent ); } } }
        $link = in_array( $tag, array( 'a', 'button' ), true ) ? array( 'url' => esc_url_raw( $element->getAttribute( 'href' ) ), 'is_external' => '_blank' === $element->getAttribute( 'target' ), 'nofollow' => false !== strpos( $element->getAttribute( 'rel' ), 'nofollow' ) ) : array();
        $image = 'img' === $tag ? array( 'url' => esc_url_raw( $element->getAttribute( 'src' ) ), 'alt' => sanitize_text_field( $element->getAttribute( 'alt' ) ) ) : array();
        $structure = $tag . '|' . implode( ',', array_map( static function ( $child_id ) use ( &$nodes ) { return $nodes[ $child_id ]['source']['tag'] ?? ''; }, $children ) );
        $node = array(
            'id' => $id,
            'source' => array( 'tag' => $tag, 'classes' => array_map( 'sanitize_html_class', $classes ), 'attributes' => $attributes, 'dom_path' => $path ),
            'semantic' => array( 'role' => $role, 'component_type' => in_array( $role, array( 'feature-card', 'testimonial', 'cta', 'pricing', 'product' ), true ) ? $role : '', 'confidence' => 0.8 ),
            'content' => array( 'text' => trim( $element->textContent ), 'rich_text' => in_array( $tag, array( 'p', 'blockquote' ), true ) ? $element->ownerDocument->saveHTML( $element ) : '', 'link' => $link, 'image' => $image, 'list' => $list, 'fields' => array() ),
            'layout' => $desktop['layout'], 'style' => $desktop['style'], 'spacing' => $desktop['spacing'], 'responsive' => $normalized_responsive,
            'assets' => $image ? array( 'images' => array( $image ) ) : array(), 'interaction' => array(),
            'component' => array( 'fingerprint' => array( 'version' => 2, 'semantic' => $role, 'structure' => $structure, 'content_schema' => $content_schema, 'layout' => (string) ( $desktop['layout']['display'] ?? '' ), 'interaction' => '' ), 'repeated' => false, 'reusable' => (bool) ( $element->getAttribute( 'class' ) ), 'dynamic' => $this->has_dynamic_evidence( $element, $role ), 'content_schema' => $content_schema ),
            'children' => $children,
        );
        $nodes[ $id ] = $node; return $id;
    }

    private function has_ir_values( array $values ) { return ! empty( $values['layout'] ) || ! empty( $values['style'] ) || ! empty( $values['spacing'] ); }

    /** Stamp component.repeated on canonical IR nodes whose immediate siblings share a structural signature. */
    private function mark_repeated_siblings( $child_ids, $child_elements, &$nodes ) {
        $groups = array();
        foreach ( $child_elements as $index => $element ) {
            if ( ! isset( $child_ids[ $index ] ) ) { continue; }
            $groups[ $this->component_signature( $element ) ][] = $child_ids[ $index ];
        }
        foreach ( $groups as $ids ) {
            if ( count( $ids ) < 2 ) { continue; }
            foreach ( $ids as $id ) { if ( isset( $nodes[ $id ] ) ) { $nodes[ $id ]['component']['repeated'] = true; } }
        }
    }

    private function declarations_to_ir( $declarations ) {
        $layout = array(); $style = array(); $spacing = array(); $consumed = array(); $fallback = array();
        $simple_layout = array( 'display'=>'display', 'flex-direction'=>'direction', 'flex-wrap'=>'wrap', 'justify-content'=>'justify', 'align-items'=>'align', 'position'=>'position', 'overflow'=>'overflow', 'z-index'=>'z_index' );
        foreach ( $simple_layout as $css => $field ) { if ( isset( $declarations[ $css ] ) ) { $layout[ $field ] = sanitize_text_field( $declarations[ $css ] ); $consumed[ $css ] = true; } }

        if ( isset( $declarations['gap'] ) ) {
            $gap = $this->try_ir_gap( $declarations['gap'] );
            if ( null !== $gap ) { $layout['gap'] = $gap; } else { $fallback['gap'] = sanitize_text_field( $declarations['gap'] ); }
            $consumed['gap'] = true;
        }
        if ( isset( $declarations['row-gap'] ) || isset( $declarations['column-gap'] ) ) {
            $row = $this->try_ir_dimension( $declarations['row-gap'] ?? $declarations['column-gap'] ?? '' );
            $column = $this->try_ir_dimension( $declarations['column-gap'] ?? $declarations['row-gap'] ?? '' );
            if ( $row && $column && $row['unit'] === $column['unit'] ) { $layout['gap'] = array( 'row'=>$row['value'], 'column'=>$column['value'], 'unit'=>$row['unit'] ); }
            else { if(isset($declarations['row-gap'])){$fallback['row-gap']=sanitize_text_field($declarations['row-gap']);} if(isset($declarations['column-gap'])){$fallback['column-gap']=sanitize_text_field($declarations['column-gap']);} }
            $consumed['row-gap'] = true; $consumed['column-gap'] = true;
        }
        foreach ( array( 'width'=>'width', 'max-width'=>'max_width', 'height'=>'height', 'min-height'=>'min_height' ) as $css => $field ) {
            if ( ! isset( $declarations[ $css ] ) ) { continue; }
            $dimension = $this->try_ir_dimension( $declarations[ $css ] );
            if ( null !== $dimension ) { $layout[ $field ] = $dimension; } else { $fallback[ $css ] = sanitize_text_field( $declarations[ $css ] ); }
            $consumed[ $css ] = true;
        }
        if ( isset( $declarations['grid-template-columns'] ) ) {
            $columns = $this->grid_column_count( $declarations['grid-template-columns'] );
            if ( $columns > 0 ) { $layout['columns'] = $columns; } else { $fallback['grid-template-columns'] = sanitize_text_field( $declarations['grid-template-columns'] ); }
            $consumed['grid-template-columns'] = true;
        }

        foreach ( array( 'color'=>'color', 'background-color'=>'background', 'opacity'=>'opacity', 'box-shadow'=>'shadow', 'text-align'=>'align' ) as $css => $field ) {
            if ( isset( $declarations[ $css ] ) ) { $style[ $field ] = sanitize_text_field( $declarations[ $css ] ); $consumed[ $css ] = true; }
        }
        if ( isset( $declarations['background'] ) && ! isset( $style['background'] ) && $this->looks_like_color( $declarations['background'] ) ) { $style['background'] = sanitize_text_field( $declarations['background'] ); $consumed['background'] = true; }
        foreach ( array( 'object-fit'=>'object_fit', 'object-position'=>'object_position', 'background-size'=>'background_size', 'background-position'=>'background_position' ) as $css => $field ) {
            if ( isset( $declarations[ $css ] ) ) { $style[ $field ] = sanitize_text_field( $declarations[ $css ] ); $consumed[ $css ] = true; }
        }
        if ( isset( $declarations['background-image'] ) && preg_match( '/^\s*url\(["\']?([^"\')]+)["\']?\)\s*$/i', (string) $declarations['background-image'], $background_match ) ) {
            $background_url = esc_url_raw( trim( $background_match[1] ) );
            if ( '' !== $background_url ) { $style['background_image'] = array( 'url'=>$background_url, 'id'=>0 ); $consumed['background-image'] = true; }
        }

        foreach ( array( 'font-family'=>'font_family', 'font-weight'=>'font_weight', 'text-transform'=>'text_transform', 'font-style'=>'font_style', 'text-decoration'=>'text_decoration' ) as $css => $field ) {
            if ( isset( $declarations[ $css ] ) ) { $style[ $field ] = sanitize_text_field( $declarations[ $css ] ); $consumed[ $css ] = true; }
        }
        foreach ( array( 'font-size'=>'font_size', 'letter-spacing'=>'letter_spacing' ) as $css => $field ) {
            if ( ! isset( $declarations[ $css ] ) ) { continue; }
            $dimension = $this->try_ir_dimension( $declarations[ $css ] );
            if ( null !== $dimension ) { $style[ $field ] = $dimension; } else { $fallback[ $css ] = sanitize_text_field( $declarations[ $css ] ); }
            $consumed[ $css ] = true;
        }
        if ( isset( $declarations['line-height'] ) ) {
            $dimension = $this->try_ir_dimension( $declarations['line-height'], '' );
            if ( null !== $dimension ) { $style['line_height'] = $dimension; } else { $fallback['line-height'] = sanitize_text_field( $declarations['line-height'] ); }
            $consumed['line-height'] = true;
        }

        foreach ( array( 'padding'=>'padding', 'margin'=>'margin', 'border-radius'=>'radius' ) as $css => $field ) {
            if ( ! isset( $declarations[ $css ] ) ) { continue; }
            $box = $this->try_ir_box( $declarations[ $css ] );
            if ( null !== $box ) { $spacing[ $field ] = $box; } else { $fallback[ $css ] = sanitize_text_field( $declarations[ $css ] ); }
            $consumed[ $css ] = true;
        }
        foreach ( array( 'padding'=>'padding', 'margin'=>'margin' ) as $prefix => $field ) {
            $sides = $this->individual_box_sides( $declarations, $prefix );
            if ( $sides ) { $existing = (array) ( $spacing[ $field ] ?? array() ); $merged = $this->merge_box_sides( $existing, $sides ); if ( $merged ) { $spacing[ $field ] = $merged; } }
            foreach ( array( 'top','right','bottom','left' ) as $side ) { if ( isset( $declarations[ $prefix . '-' . $side ] ) ) { $consumed[ $prefix . '-' . $side ] = true; } }
        }

        foreach ( array( 'border-style'=>'border_style', 'border-color'=>'border_color' ) as $css => $field ) {
            if ( isset( $declarations[ $css ] ) ) { $style[ $field ] = sanitize_text_field( $declarations[ $css ] ); $consumed[ $css ] = true; }
        }
        if ( isset( $declarations['border-width'] ) ) {
            $box = $this->try_ir_box( $declarations['border-width'] );
            if ( $box ) { $style['border_width'] = $box; } else { $fallback['border-width'] = sanitize_text_field( $declarations['border-width'] ); }
            $consumed['border-width'] = true;
        }
        if ( isset( $declarations['border'] ) ) {
            $border = $this->parse_border( $declarations['border'] );
            if ( $border ) { $style = array_merge( $style, $border ); $consumed['border'] = true; }
        }

        foreach ( $declarations as $property => $value ) {
            if ( ! isset( $consumed[ $property ] ) ) { $fallback[ strtolower( trim( (string) $property ) ) ] = sanitize_text_field( $value ); }
        }
        if ( $fallback ) { $style['css_fallback'] = $fallback; }
        return array( 'layout'=>$layout, 'style'=>$style, 'spacing'=>$spacing );
    }

    private function try_ir_dimension( $value, $default_unit = 'px' ) {
        if ( ! preg_match( '/^\s*(-?[0-9.]+)\s*(px|%|em|rem|svh|dvh|lvh|vh|vw|vmin|vmax|ch|ex)?\s*$/i', (string) $value, $match ) ) { return null; }
        $unit = isset( $match[2] ) && '' !== $match[2] ? strtolower( $match[2] ) : $default_unit;
        return array( 'value'=>(float)$match[1], 'unit'=>$unit );
    }

    private function try_ir_box( $value ) {
        $parts = preg_split( '/\s+/', trim( (string) $value ) );
        $parts = array_values( array_filter( $parts, static function ( $part ) { return '' !== $part; } ) );
        if ( ! $parts || count( $parts ) > 4 ) { return null; }
        $dims = array(); foreach ( $parts as $part ) { $dim = $this->try_ir_dimension( $part ); if ( ! $dim ) { return null; } $dims[] = $dim; }
        $unit = $dims[0]['unit']; foreach ( $dims as $dim ) { if ( $dim['unit'] !== $unit ) { return null; } }
        if ( 1 === count( $dims ) ) { $values = array( $dims[0],$dims[0],$dims[0],$dims[0] ); }
        elseif ( 2 === count( $dims ) ) { $values = array( $dims[0],$dims[1],$dims[0],$dims[1] ); }
        elseif ( 3 === count( $dims ) ) { $values = array( $dims[0],$dims[1],$dims[2],$dims[1] ); }
        else { $values = $dims; }
        return array( 'top'=>$values[0]['value'], 'right'=>$values[1]['value'], 'bottom'=>$values[2]['value'], 'left'=>$values[3]['value'], 'unit'=>$unit );
    }

    private function try_ir_gap( $value ) {
        $parts = array_values( array_filter( preg_split( '/\s+/', trim( (string) $value ) ) ) );
        if ( ! $parts || count( $parts ) > 2 ) { return null; }
        $row = $this->try_ir_dimension( $parts[0] ); $column = $this->try_ir_dimension( $parts[1] ?? $parts[0] );
        if ( ! $row || ! $column || $row['unit'] !== $column['unit'] ) { return null; }
        if ( $row['value'] === $column['value'] ) { return array( 'value'=>$row['value'], 'unit'=>$row['unit'] ); }
        return array( 'row'=>$row['value'], 'column'=>$column['value'], 'unit'=>$row['unit'] );
    }

    private function individual_box_sides( array $declarations, $prefix ) {
        $out = array();
        foreach ( array( 'top','right','bottom','left' ) as $side ) {
            $key = $prefix . '-' . $side; if ( ! isset( $declarations[ $key ] ) ) { continue; }
            $dim = $this->try_ir_dimension( $declarations[ $key ] ); if ( $dim ) { $out[ $side ] = $dim; }
        }
        return $out;
    }

    private function merge_box_sides( array $existing, array $sides ) {
        $unit = (string) ( $existing['unit'] ?? '' );
        foreach ( $sides as $dim ) { if ( '' === $unit ) { $unit = $dim['unit']; } if ( $dim['unit'] !== $unit ) { return null; } }
        $out = array( 'top'=>$existing['top']??0, 'right'=>$existing['right']??0, 'bottom'=>$existing['bottom']??0, 'left'=>$existing['left']??0, 'unit'=>$unit ?: 'px' );
        foreach ( $sides as $side => $dim ) { $out[ $side ] = $dim['value']; }
        return $out;
    }

    private function grid_column_count( $value ) {
        $value = trim( (string) $value );
        if ( preg_match( '/^repeat\(\s*(\d+)\s*,/i', $value, $match ) ) { return max( 1, min( 24, (int) $match[1] ) ); }
        if ( false !== stripos( $value, 'auto-fit' ) || false !== stripos( $value, 'auto-fill' ) ) { return 0; }
        $depth = 0; $tracks = 0; $token = '';
        for ( $i=0, $length=strlen($value); $i<$length; $i++ ) {
            $char=$value[$i]; if('('===$char){$depth++;}$token.=$char;if(')'===$char){$depth=max(0,$depth-1);}if(0===$depth&&ctype_space($char)){if(''!==trim($token)){$tracks++;$token='';}}
        }
        if ( '' !== trim( $token ) ) { $tracks++; }
        return $tracks > 0 && $tracks <= 24 ? $tracks : 0;
    }

    private function parse_border( $value ) {
        $value = trim( (string) $value ); $out = array();
        if ( preg_match( '/(?:^|\s)([0-9.]+(?:px|em|rem)?)(?:\s|$)/i', $value, $width ) ) { $box=$this->try_ir_box($width[1]); if($box){$out['border_width']=$box;} }
        if ( preg_match( '/\b(solid|dashed|dotted|double|groove|ridge|inset|outset|none)\b/i', $value, $style ) ) { $out['border_style']=strtolower($style[1]); }
        if ( preg_match( '/(#[0-9a-f]{3,8}\b|rgba?\([^\)]+\)|hsla?\([^\)]+\)|\btransparent\b)/i', $value, $color ) ) { $out['border_color']=sanitize_text_field($color[1]); }
        return $out;
    }

    private function looks_like_color( $value ) { return (bool) preg_match( '/^\s*(?:#[0-9a-f]{3,8}|rgba?\([^\)]+\)|hsla?\([^\)]+\)|transparent|currentcolor|[a-z]+)\s*$/i', (string) $value ); }

    private function ir_dimension( $value, $default_unit = 'px' ) {
        $dimension = $this->try_ir_dimension( $value, $default_unit );
        return null !== $dimension ? $dimension : array( 'value'=>0.0, 'unit'=>$default_unit );
    }
}
