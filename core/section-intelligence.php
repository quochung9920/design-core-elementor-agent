<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Section-first enrichment, reuse application and registry synchronization. */
class Design_Core_Elementor_Section_Intelligence {
    private $fingerprints;
    private $registry;

    public function __construct() {
        $this->fingerprints = new Design_Core_Elementor_Section_Fingerprint_Service();
        $this->registry = new Design_Core_Elementor_Section_Registry();
    }

    public function prepare( array $ir ) {
        $by_id = array(); $indexes = array();
        foreach ( $ir['nodes'] as $index => $node ) { $by_id[ $node['id'] ] = $node; $indexes[ $node['id'] ] = $index; }
        foreach ( $ir['root_ids'] as $position => $root_id ) {
            if ( ! isset( $by_id[ $root_id ], $indexes[ $root_id ] ) ) { continue; }
            $root = $by_id[ $root_id ];
            if ( ! $this->is_section_root( $root ) ) { continue; }
            $fingerprint = $this->fingerprints->from_root( $root, $by_id );
            $classification = $this->registry->classify( $fingerprint );
            $variant_id = sanitize_key( $classification['variant_id'] ?? '' );
            $section = array(
                'position' => (int) $position,
                'family' => $fingerprint['semantic'],
                'reusable' => true,
                'fingerprint' => $fingerprint,
                'reuse' => array(
                    'action' => $classification['action'],
                    'master_id' => is_array( $classification['item'] ?? null ) ? (string) ( $classification['item']['id'] ?? '' ) : '',
                    'variant_id' => $variant_id,
                    'score' => (float) ( $classification['score'] ?? 0 ),
                    'reason' => (string) ( $classification['reason'] ?? '' ),
                ),
            );
            if ( $variant_id ) { $section['variant_id'] = $variant_id; }
            if ( 'reuse' === $classification['action'] && ! empty( $classification['item']['master']['blueprint'] ) ) {
                $candidate_ir = $ir;
                if ( $this->apply_blueprint( $candidate_ir, $root_id, $classification['item']['master']['blueprint'], $classification['item']['id'] ) ) {
                    $ir = $candidate_ir;
                    $section['master_id'] = $classification['item']['id'];
                }
            } elseif ( 'variant' === $classification['action'] && ! empty( $classification['item']['id'] ) ) {
                $section['master_id'] = $classification['item']['id'];
                $section['variant_id'] = $this->variant_id( $fingerprint );
                $section['reuse']['variant_id'] = $section['variant_id'];
            }
            $ir['nodes'][ $indexes[ $root_id ] ]['section'] = $section;
            $by_id[ $root_id ] = $ir['nodes'][ $indexes[ $root_id ] ];
        }
        return $ir;
    }

    public function synchronize( array $ir, $location ) {
        $by_id = array(); foreach ( $ir['nodes'] as $node ) { $by_id[ $node['id'] ] = $node; }
        $registered = array();
        foreach ( $ir['root_ids'] as $position => $root_id ) {
            if ( ! isset( $by_id[ $root_id ] ) ) { continue; }
            $node = $by_id[ $root_id ]; $section = (array) ( $node['section'] ?? array() );
            if ( empty( $section['reusable'] ) ) { continue; }
            $fingerprint = (array) ( $section['fingerprint'] ?? array() );
            if ( Design_Core_Elementor_Section_Fingerprint_Service::VERSION !== ( $fingerprint['version'] ?? null ) ) { continue; }
            // Reclassify at synchronization time because earlier roots in the same page may already have created this family/variant.
            $classification = $this->registry->classify( $fingerprint );
            $master = $classification['item'] ?? null;
            $variant_id = sanitize_key( $classification['variant_id'] ?? ( $section['variant_id'] ?? '' ) );
            if ( 'new' === ( $classification['action'] ?? 'new' ) || ! is_array( $master ) ) {
                $now = gmdate( 'c' );
                $master = array(
                    'id' => 'section-' . sanitize_key( $fingerprint['semantic'] ) . '-' . substr( $fingerprint['family_hash'], 0, 10 ),
                    'type' => 'section', 'schema_version' => 2, 'item_version' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                    'source' => array( 'kind' => 'design-ir-section', 'node_id' => $root_id ),
                    'usage' => array( 'count' => 0, 'locations' => array() ),
                    'fingerprint' => $fingerprint,
                    'master' => array( 'blueprint' => $this->capture_blueprint( $node, $by_id ), 'editable_schema' => $this->named_slots( $node, $by_id ) ),
                    'variants' => array(), 'instances' => array(),
                    'name' => sanitize_text_field( ucwords( str_replace( '-', ' ', $fingerprint['semantic'] ) ) ),
                );
                $master = $this->registry->upsert( $master );
                if ( is_wp_error( $master ) ) { throw new RuntimeException( $master->get_error_message() ); }
                $variant_id = '';
            } elseif ( 'variant' === ( $classification['action'] ?? '' ) ) {
                $variant_id = $variant_id ?: $this->variant_id( $fingerprint );
                $this->registry->add_variant( $master['id'], $variant_id, array( 'fingerprint' => $fingerprint, 'blueprint' => $this->capture_blueprint( $node, $by_id ) ) );
                $master = $this->registry->get( $master['id'] );
                if ( ! $master ) { throw new RuntimeException( 'Section variant registration lost its master.' ); }
            }
            $bindings = $this->named_bindings( $node, $by_id );
            $assets = $this->asset_bindings( $node, $by_id );
            $overrides = $variant_id ? array( 'variant_id' => $variant_id ) : array();
            $instance = $this->registry->register_instance( $master['id'], $bindings, array( 'page_id' => (int) $location, 'position' => (int) $position, 'root_id' => $root_id ), $assets, $overrides );
            if ( is_wp_error( $instance ) ) { throw new RuntimeException( $instance->get_error_message() ); }
            $registered[] = array(
                'position' => (int) $position,
                'root_id' => $root_id,
                'master_id' => $master['id'],
                'instance_id' => $instance['id'],
                'family' => $fingerprint['semantic'],
                'action' => $classification['action'] ?? 'new',
                'variant_id' => $variant_id,
                'score' => (float) ( $classification['score'] ?? 0 ),
                'reason' => (string) ( $classification['reason'] ?? '' ),
            );
        }
        return $registered;
    }

    public function manifest( array $ir, $page_id = 0, $title = '' ) {
        $by_id = array(); foreach ( $ir['nodes'] as $node ) { $by_id[ $node['id'] ] = $node; }
        $sections = array();
        foreach ( $ir['root_ids'] as $position => $root_id ) {
            if ( ! isset( $by_id[ $root_id ]['section'] ) ) { continue; }
            $section = $by_id[ $root_id ]['section'];
            $sections[] = array(
                'position' => (int) $position, 'root_id' => $root_id, 'family' => $section['family'] ?? 'section',
                'action' => $section['reuse']['action'] ?? 'new', 'master_id' => $section['reuse']['master_id'] ?? ( $section['master_id'] ?? '' ),
                'variant_id' => $section['variant_id'] ?? ( $section['reuse']['variant_id'] ?? '' ), 'score' => (float) ( $section['reuse']['score'] ?? 0 ), 'reason' => $section['reuse']['reason'] ?? '',
            );
        }
        return array( 'schema_version' => 1, 'page_id' => (int) $page_id, 'title' => sanitize_text_field( $title ), 'sections' => $sections );
    }

    private function variant_id( array $fingerprint ) {
        $identity = (string) ( $fingerprint['detail_hash'] ?? '' );
        if ( '' === $identity ) { $identity = (string) ( $fingerprint['variant_hash'] ?? '' ); }
        return 'variant-' . substr( $identity ?: hash( 'sha256', wp_json_encode( $fingerprint ) ), 0, 10 );
    }

    private function is_section_root( array $root ) {
        $tag = strtolower( (string) ( $root['source']['tag'] ?? '' ) );
        if ( in_array( $tag, array( 'section', 'article', 'main', 'nav', 'aside' ), true ) ) { return true; }
        return count( (array) ( $root['children'] ?? array() ) ) > 0;
    }

    private function apply_blueprint( array &$ir, $root_id, array $blueprint, $master_id ) {
        $indexes = array(); foreach ( $ir['nodes'] as $index => $node ) { $indexes[ $node['id'] ] = $index; }
        if ( ! isset( $indexes[ $root_id ] ) ) { return false; }
        return $this->apply_blueprint_node( $ir, $indexes, $root_id, $blueprint, $master_id, true );
    }

    private function apply_blueprint_node( array &$ir, array $indexes, $node_id, array $blueprint, $master_id, $root = false ) {
        if ( ! isset( $indexes[ $node_id ] ) ) { return false; }
        $index = $indexes[ $node_id ]; $node = $ir['nodes'][ $index ];
        if ( sanitize_key( $node['source']['tag'] ?? '' ) !== sanitize_key( $blueprint['source']['tag'] ?? '' ) ) { return false; }
        $blueprint_children = (array) ( $blueprint['children'] ?? array() );
        if ( count( $blueprint_children ) !== count( (array) ( $node['children'] ?? array() ) ) ) { return false; }
        foreach ( $node['children'] as $offset => $child_id ) {
            if ( ! $this->apply_blueprint_node( $ir, $indexes, $child_id, $blueprint_children[ $offset ], $master_id, false ) ) { return false; }
        }
        $instance_style = (array) ( $node['style'] ?? array() );
        foreach ( array( 'semantic', 'layout', 'style', 'spacing', 'responsive', 'interaction' ) as $field ) {
            if ( isset( $blueprint[ $field ] ) && is_array( $blueprint[ $field ] ) ) { $ir['nodes'][ $index ][ $field ] = $blueprint[ $field ]; }
        }
        $this->restore_instance_style_assets( $instance_style, $ir['nodes'][ $index ]['style'] );
        if ( isset( $blueprint['source']['classes'] ) && is_array( $blueprint['source']['classes'] ) ) {
            $ir['nodes'][ $index ]['source']['classes'] = array_values( array_unique( array_merge( $blueprint['source']['classes'], (array) ( $node['source']['classes'] ?? array() ) ) ) );
        }
        if ( $root ) { $ir['nodes'][ $index ]['section']['master_id'] = $master_id; }
        return true;
    }

    private function restore_instance_style_assets( array $source_style, array &$target_style ) {
        if ( array_key_exists( 'background_image', $source_style ) ) { $target_style['background_image'] = $source_style['background_image']; }
        $source_fallback = is_array( $source_style['css_fallback'] ?? null ) ? $source_style['css_fallback'] : array();
        if ( $source_fallback ) {
            if ( ! isset( $target_style['css_fallback'] ) || ! is_array( $target_style['css_fallback'] ) ) { $target_style['css_fallback'] = array(); }
            foreach ( array( 'background-image', 'background_image' ) as $key ) {
                if ( array_key_exists( $key, $source_fallback ) ) { $target_style['css_fallback'][ $key ] = $source_fallback[ $key ]; }
            }
        }
    }

    private function capture_blueprint( array $node, array $nodes ) {
        $children = array(); foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) { if ( isset( $nodes[ $child_id ] ) ) { $children[] = $this->capture_blueprint( $nodes[ $child_id ], $nodes ); } }
        return array(
            'source' => array( 'tag' => $node['source']['tag'] ?? 'div', 'classes' => (array) ( $node['source']['classes'] ?? array() ) ),
            'semantic' => (array) ( $node['semantic'] ?? array() ), 'layout' => (array) ( $node['layout'] ?? array() ), 'style' => (array) ( $node['style'] ?? array() ),
            'spacing' => (array) ( $node['spacing'] ?? array() ), 'responsive' => (array) ( $node['responsive'] ?? array() ), 'interaction' => (array) ( $node['interaction'] ?? array() ), 'children' => $children,
        );
    }

    private function named_slots( array $root, array $nodes ) {
        $slots = array();
        foreach ( $this->ordered_nodes( $root, $nodes ) as $node ) {
            $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) ); $type = '';
            if ( preg_match( '/^h[1-6]$/', $tag ) ) { $type = 'heading'; }
            elseif ( in_array( $tag, array( 'p', 'blockquote' ), true ) ) { $type = 'rich_text'; }
            elseif ( in_array( $tag, array( 'a', 'button' ), true ) ) { $type = 'link'; }
            elseif ( 'img' === $tag || ! empty( $node['content']['image'] ) ) { $type = 'media'; }
            elseif ( in_array( $tag, array( 'ul', 'ol' ), true ) ) { $type = 'list'; }
            elseif ( 'form' === $tag || ! empty( $node['content']['fields'] ) ) { $type = 'form'; }
            if ( ! $type ) { continue; }
            $name = $type . '_' . ( 1 + count( array_filter( $slots, static function ( $slot ) use ( $type ) { return $slot['type'] === $type; } ) ) );
            $slots[ $name ] = array( 'type' => $type, 'node_id' => $node['id'], 'required' => true );
        }
        return $slots;
    }

    private function named_bindings( array $root, array $nodes ) {
        $bindings = array(); $slots = $this->named_slots( $root, $nodes );
        foreach ( $slots as $name => $slot ) {
            $node = $nodes[ $slot['node_id'] ] ?? array(); $content = (array) ( $node['content'] ?? array() );
            if ( 'heading' === $slot['type'] ) { $bindings[ $name ] = $content['text'] ?? ''; }
            elseif ( 'rich_text' === $slot['type'] ) { $bindings[ $name ] = $content['rich_text'] ?? ( $content['text'] ?? '' ); }
            elseif ( 'link' === $slot['type'] ) { $bindings[ $name ] = array( 'text' => $content['text'] ?? '', 'link' => $content['link'] ?? array() ); }
            elseif ( 'media' === $slot['type'] ) { $bindings[ $name ] = $content['image'] ?? array(); }
            elseif ( 'list' === $slot['type'] ) { $bindings[ $name ] = $content['list'] ?? array(); }
            elseif ( 'form' === $slot['type'] ) { $bindings[ $name ] = $content['fields'] ?? array(); }
        }
        return $bindings;
    }

    private function asset_bindings( array $root, array $nodes ) {
        $assets = array();
        foreach ( $this->ordered_nodes( $root, $nodes ) as $node ) {
            if ( ! empty( $node['content']['image'] ) ) { $assets[] = $node['content']['image']; }
            if ( ! empty( $node['style']['background_image'] ) ) { $assets[] = $node['style']['background_image']; }
        }
        return $assets;
    }

    private function ordered_nodes( array $root, array $nodes ) {
        $out = array();
        $walk = function ( array $node ) use ( &$walk, &$out, $nodes ) {
            $out[] = $node;
            foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) { if ( isset( $nodes[ $child_id ] ) ) { $walk( $nodes[ $child_id ] ); } }
        };
        $walk( $root );
        return $out;
    }
}
