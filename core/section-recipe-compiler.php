<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Compiles a declarative section recipe + instance bindings into a Design IR subtree.
 * Recipes are platform-neutral; Elementor storage is still produced only by adapters.
 */
class Design_Core_Elementor_Section_Recipe_Compiler {
    public function compile( array $recipe, array $bindings = array(), $root_id = '' ) {
        $family = sanitize_key( $recipe['family'] ?? 'section' );
        $root_id = $root_id ? sanitize_key( $root_id ) : 'recipe-' . substr( md5( $family . '|' . wp_json_encode( $recipe ) ), 0, 12 );
        $nodes = array(); $children = array();
        foreach ( (array) ( $recipe['slots'] ?? array() ) as $slot_name => $slot ) {
            if ( ! is_array( $slot ) ) { continue; }
            $slot_id = $root_id . '-' . sanitize_key( $slot_name );
            $value = $bindings[ $slot_name ] ?? ( $slot['default'] ?? null );
            if ( null === $value && empty( $slot['required'] ) ) { continue; }
            $nodes[] = $this->slot_node( $slot_id, (string) $slot_name, $slot, $value );
            $children[] = $slot_id;
        }
        $layout = is_array( $recipe['layout'] ?? null ) ? $recipe['layout'] : array();
        $style = is_array( $recipe['style'] ?? null ) ? $recipe['style'] : array();
        $spacing = is_array( $recipe['spacing'] ?? null ) ? $recipe['spacing'] : array();
        $schema = array_values( array_unique( array_map( static function ( $slot ) { return sanitize_key( is_array( $slot ) ? ( $slot['type'] ?? 'text' ) : 'text' ); }, (array) ( $recipe['slots'] ?? array() ) ) ) );
        $root = array(
            'id' => $root_id,
            'source' => array( 'tag' => sanitize_key( $recipe['tag'] ?? 'section' ), 'classes' => array_values( array_map( 'sanitize_html_class', (array) ( $recipe['classes'] ?? array( 'dc-section-' . $family ) ) ) ), 'attributes' => array(), 'dom_path' => '/recipe/' . $root_id ),
            'semantic' => array( 'role' => $family, 'component_type' => '', 'confidence' => 1.0 ),
            'content' => array( 'text' => '', 'rich_text' => '', 'link' => array(), 'image' => array(), 'list' => array(), 'fields' => array() ),
            'layout' => $layout, 'style' => $style, 'spacing' => $spacing,
            'responsive' => is_array( $recipe['responsive'] ?? null ) ? $recipe['responsive'] : array(),
            'assets' => array(), 'interaction' => is_array( $recipe['interaction'] ?? null ) ? $recipe['interaction'] : array(),
            'component' => array(
                'fingerprint' => array( 'version' => 2, 'semantic' => $family, 'structure' => 'section|' . implode( ',', array_map( 'sanitize_key', array_keys( (array) ( $recipe['slots'] ?? array() ) ) ) ), 'content_schema' => $schema, 'layout' => sanitize_text_field( $layout['display'] ?? '' ), 'interaction' => '' ),
                'repeated' => false, 'reusable' => true, 'dynamic' => false, 'content_schema' => $schema,
            ),
            'children' => $children,
        );
        array_unshift( $nodes, $root );
        $ir = array(
            'schema_version' => Design_Core_Elementor_Design_IR::SCHEMA_VERSION,
            'type' => 'design-ir', 'source_name' => 'section-recipe', 'nodes' => $nodes, 'root_ids' => array( $root_id ),
            'analysis_quality' => array( 'browser_runtime' => 'recipe', 'computed_styles' => 'recipe', 'geometry' => 'recipe', 'css_static' => 'recipe', 'interaction' => 'recipe' ),
            'tokens' => array(), 'breakpoints' => array(), 'diagnostics' => array( 'source_html_available' => false, 'recipe_family' => $family ),
        );
        ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
        if ( class_exists( 'Design_Core_Elementor_Section_Intelligence' ) ) { $ir = ( new Design_Core_Elementor_Section_Intelligence() )->prepare( $ir ); }
        ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
        return $ir;
    }

    private function slot_node( $id, $name, array $slot, $value ) {
        $type = sanitize_key( $slot['type'] ?? 'text' );
        $tag = 'div'; $content = array( 'text' => '', 'rich_text' => '', 'link' => array(), 'image' => array(), 'list' => array(), 'fields' => array() );
        if ( 'heading' === $type ) { $tag = sanitize_key( $slot['tag'] ?? 'h2' ); $content['text'] = sanitize_text_field( is_scalar( $value ) ? $value : '' ); }
        elseif ( in_array( $type, array( 'text', 'rich_text' ), true ) ) { $tag = 'p'; $content['text'] = sanitize_text_field( is_scalar( $value ) ? $value : '' ); $content['rich_text'] = 'rich_text' === $type ? wp_kses_post( is_scalar( $value ) ? $value : '' ) : ''; }
        elseif ( 'link' === $type ) { $tag = 'a'; $value = is_array( $value ) ? $value : array( 'text' => (string) $value ); $content['text'] = sanitize_text_field( $value['text'] ?? '' ); $content['link'] = array( 'url' => esc_url_raw( $value['url'] ?? '' ), 'is_external' => ! empty( $value['is_external'] ), 'nofollow' => ! empty( $value['nofollow'] ) ); }
        elseif ( 'media' === $type ) { $tag = 'img'; $value = is_array( $value ) ? $value : array( 'url' => (string) $value ); $content['image'] = array( 'url' => esc_url_raw( $value['url'] ?? '' ), 'id' => (int) ( $value['id'] ?? 0 ), 'alt' => sanitize_text_field( $value['alt'] ?? '' ) ); }
        elseif ( 'list' === $type ) { $tag = 'ul'; $content['list'] = array_values( array_map( 'sanitize_text_field', (array) $value ) ); }
        elseif ( 'form' === $type ) { $tag = 'form'; $content['fields'] = is_array( $value ) ? $value : array(); }
        return array(
            'id' => $id,
            'source' => array( 'tag' => $tag, 'classes' => array( 'dc-slot-' . sanitize_html_class( $name ) ), 'attributes' => array(), 'dom_path' => '/recipe/' . $id ),
            'semantic' => array( 'role' => $type, 'component_type' => '', 'confidence' => 1.0 ),
            'content' => $content,
            'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(),
            'assets' => 'media' === $type ? array( 'images' => array( $content['image'] ) ) : array(), 'interaction' => array(),
            'component' => array( 'fingerprint' => array( 'version' => 2, 'semantic' => $type, 'structure' => $tag . '|', 'content_schema' => array( $type ), 'layout' => '', 'interaction' => '' ), 'repeated' => false, 'reusable' => false, 'dynamic' => false, 'content_schema' => array( $type ) ),
            'children' => array(),
        );
    }
}
