<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Builds section-level family and variant fingerprints without changing Design IR v4 contracts. */
class Design_Core_Elementor_Section_Fingerprint_Service {
    const VERSION = 3;

    public function from_root( array $root, array $nodes_by_id ) {
        $family = array(
            'semantic' => $this->semantic_family( $root ),
            'topology' => $this->topology( $root, $nodes_by_id ),
            'slots' => $this->slot_schema( $root, $nodes_by_id ),
            'layout_topology' => $this->layout_topology( $root, $nodes_by_id ),
            'responsive_topology' => $this->responsive_topology( $root, $nodes_by_id ),
            'media_topology' => $this->media_topology( $root, $nodes_by_id ),
            'interaction_topology' => $this->interaction_topology( $root, $nodes_by_id ),
        );
        // Preserve the original v3 variant-hash contract for compatibility. detail_hash below is the stricter discriminator.
        $variant = array(
            'family' => $family,
            'surface' => $this->surface_signature( $root ),
            'spacing' => $this->stable_value( $root['spacing'] ?? array() ),
            'layout' => $this->stable_value( $root['layout'] ?? array() ),
            'style' => $this->variant_style( $root['style'] ?? array() ),
            'responsive' => $this->stable_value( $root['responsive'] ?? array() ),
        );
        $detail = $this->presentation_tree( $root, $nodes_by_id );
        return array(
            'version' => self::VERSION,
            'semantic' => $family['semantic'],
            'topology' => $family['topology'],
            'slots' => $family['slots'],
            'layout_topology' => $family['layout_topology'],
            'responsive_topology' => $family['responsive_topology'],
            'media_topology' => $family['media_topology'],
            'interaction_topology' => $family['interaction_topology'],
            'family_hash' => hash( 'sha256', wp_json_encode( $family ) ),
            'variant_hash' => hash( 'sha256', wp_json_encode( $variant ) ),
            // Optional v3 refinement. It is deliberately not required so earlier v3 registry data remains readable.
            'detail_hash' => hash( 'sha256', wp_json_encode( $detail ) ),
        );
    }

    private function semantic_family( array $node ) {
        $role = sanitize_key( $node['semantic']['role'] ?? '' );
        $tag = sanitize_key( $node['source']['tag'] ?? 'section' );
        $classes = implode( ' ', array_map( 'sanitize_html_class', (array) ( $node['source']['classes'] ?? array() ) ) );
        $haystack = strtolower( $classes . ' ' . $role );

        // Section-level classes are more trustworthy here than broad component-level analyzer roles
        // such as "feature-card", which may be assigned to a whole <section class="services">.
        $patterns = array(
            'hero' => '/hero|masthead|banner/',
            'cta' => '/(?:^|[-_ ])cta(?:$|[-_ ])|call[-_ ]?to[-_ ]?action/',
            'faq' => '/faq|questions|accordion/',
            'comparison' => '/compare|comparison/',
            'locations' => '/location|city|cities|coverage/',
            'resources' => '/resource|guide|knowledge|insight/',
            'process' => '/process|steps|how[-_ ]?it[-_ ]?works|how[-_ ]?we[-_ ]?work/',
            'benefits' => '/benefit|why[-_ ]|advantages?/',
            'services' => '/services?|solutions?/',
            'contact' => '/contact|enquiry|inquiry|get[-_ ]?in[-_ ]?touch/',
            'stats' => '/stats|metrics|numbers|figures/',
            'logo-cloud' => '/logo|partners|clients|trusted[-_ ]?by/',
            'testimonials' => '/testimonial|reviews?|quotes?/',
            'pricing' => '/pricing|costs?|rates?/',
        );
        foreach ( $patterns as $family => $pattern ) { if ( preg_match( $pattern, $haystack ) ) { return $family; } }

        if ( in_array( $role, array( 'hero', 'cta', 'faq', 'testimonial', 'pricing', 'navigation', 'pricing-calculator', 'product-comparison' ), true ) ) { return $role; }
        if ( in_array( $role, array( 'feature-card', 'product', 'post' ), true ) && ! in_array( $tag, array( 'section', 'main', 'nav', 'aside' ), true ) ) { return $role; }
        return in_array( $tag, array( 'section', 'article', 'main', 'nav', 'aside' ), true ) ? $tag : 'section';
    }

    private function topology( array $root, array $nodes ) { return $this->walk_signature( $root, $nodes, 0, 5 ); }

    private function walk_signature( array $node, array $nodes, $depth, $max_depth ) {
        $tag = sanitize_key( $node['source']['tag'] ?? 'div' );
        if ( $depth >= $max_depth ) { return $tag . ':*'; }
        $children = array();
        foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) {
            if ( isset( $nodes[ $child_id ] ) ) { $children[] = $this->walk_signature( $nodes[ $child_id ], $nodes, $depth + 1, $max_depth ); }
        }
        return $tag . '[' . implode( ',', $children ) . ']';
    }

    private function slot_schema( array $root, array $nodes ) {
        $counts = array(); $queue = array( $root );
        while ( $queue ) {
            $node = array_shift( $queue ); $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
            $content = (array) ( $node['content'] ?? array() ); $slot = '';
            if ( preg_match( '/^h[1-6]$/', $tag ) ) { $slot = 'heading'; }
            elseif ( in_array( $tag, array( 'p', 'blockquote' ), true ) ) { $slot = 'rich_text'; }
            elseif ( in_array( $tag, array( 'a', 'button' ), true ) ) { $slot = 'link'; }
            elseif ( 'img' === $tag || ! empty( $content['image'] ) ) { $slot = 'media'; }
            elseif ( in_array( $tag, array( 'ul', 'ol' ), true ) ) { $slot = 'list'; }
            elseif ( 'form' === $tag || ! empty( $content['fields'] ) ) { $slot = 'form'; }
            if ( $slot ) { $counts[ $slot ] = ( $counts[ $slot ] ?? 0 ) + 1; }
            foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) { if ( isset( $nodes[ $child_id ] ) ) { $queue[] = $nodes[ $child_id ]; } }
        }
        ksort( $counts ); return $counts;
    }

    private function layout_topology( array $root, array $nodes ) {
        $parts = array(); $queue = array( $root ); $seen = 0;
        while ( $queue && $seen < 32 ) {
            $node = array_shift( $queue ); $seen++;
            $layout = (array) ( $node['layout'] ?? array() );
            $parts[] = implode( ':', array(
                sanitize_key( $layout['display'] ?? '' ), sanitize_key( $layout['direction'] ?? '' ),
                sanitize_key( $node['semantic']['layout_mode'] ?? '' ), count( (array) ( $node['children'] ?? array() ) )
            ) );
            foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) { if ( isset( $nodes[ $child_id ] ) ) { $queue[] = $nodes[ $child_id ]; } }
        }
        return implode( '|', $parts );
    }

    private function responsive_topology( array $root, array $nodes ) {
        $states = array(); $queue = array( $root );
        while ( $queue ) {
            $node = array_shift( $queue );
            foreach ( array_keys( (array) ( $node['responsive'] ?? array() ) ) as $state ) { $states[ sanitize_key( $state ) ] = true; }
            foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) { if ( isset( $nodes[ $child_id ] ) ) { $queue[] = $nodes[ $child_id ]; } }
        }
        $states = array_keys( $states ); sort( $states ); return $states;
    }

    private function media_topology( array $root, array $nodes ) {
        $roles = array(); $queue = array( $root );
        while ( $queue ) {
            $node = array_shift( $queue );
            if ( ! empty( $node['content']['image'] ) ) { $roles[] = 'foreground'; }
            if ( ! empty( $node['style']['background_image'] ) ) { $roles[] = 'background'; }
            if ( ! empty( $node['media']['role'] ) ) { $roles[] = sanitize_key( $node['media']['role'] ); }
            foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) { if ( isset( $nodes[ $child_id ] ) ) { $queue[] = $nodes[ $child_id ]; } }
        }
        $roles = array_values( array_unique( $roles ) ); sort( $roles ); return $roles;
    }

    private function interaction_topology( array $root, array $nodes ) {
        $signals = array(); $queue = array( $root );
        while ( $queue ) {
            $node = array_shift( $queue );
            foreach ( array_keys( (array) ( $node['interaction'] ?? array() ) ) as $key ) { $signals[ sanitize_key( $key ) ] = true; }
            if ( ! empty( $node['component']['dynamic'] ) ) { $signals['dynamic'] = true; }
            foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) { if ( isset( $nodes[ $child_id ] ) ) { $queue[] = $nodes[ $child_id ]; } }
        }
        $signals = array_keys( $signals ); sort( $signals ); return $signals;
    }

    private function surface_signature( array $root ) {
        return array(
            'layout_mode' => sanitize_key( $root['semantic']['layout_mode'] ?? '' ),
            'surface_owner' => sanitize_text_field( $root['semantic']['surface_owner'] ?? '' ),
            'content_owner' => sanitize_text_field( $root['semantic']['content_owner'] ?? '' ),
        );
    }

    private function presentation_tree( array $root, array $nodes ) {
        $out = array(); $queue = array( $root ); $seen = 0;
        while ( $queue && $seen < 96 ) {
            $node = array_shift( $queue ); $seen++;
            $out[] = array(
                'tag' => sanitize_key( $node['source']['tag'] ?? 'div' ),
                'layout' => $this->stable_value( $node['layout'] ?? array() ),
                'style' => $this->presentation_style( (array) ( $node['style'] ?? array() ) ),
                'spacing' => $this->stable_value( $node['spacing'] ?? array() ),
                'responsive' => $this->presentation_responsive( (array) ( $node['responsive'] ?? array() ) ),
            );
            foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) { if ( isset( $nodes[ $child_id ] ) ) { $queue[] = $nodes[ $child_id ]; } }
        }
        return $out;
    }

    private function presentation_style( array $style ) { return $this->asset_neutral_value( $style ); }

    private function presentation_responsive( array $responsive ) { return $this->asset_neutral_value( $responsive ); }

    private function asset_neutral_value( $value, $key = '' ) {
        $normalized_key = strtolower( str_replace( '-', '_', (string) $key ) );
        if ( in_array( $normalized_key, array( 'background_image', 'image', 'src', 'url', 'attachment_id' ), true ) ) { return empty( $value ) ? '' : '__asset__'; }
        if ( ! is_array( $value ) ) {
            if ( is_string( $value ) && preg_match( '/url\s*\(/i', $value ) ) {
                // Strip only asset identity; preserve gradient/overlay/position differences around it.
                return preg_replace( '/url\s*\(\s*(?:["\'][^"\']*["\']|[^\)]*)\s*\)/i', 'url(__asset__)', $value );
            }
            return $value;
        }
        $out = array();
        foreach ( $value as $nested_key => $nested ) { $out[ $nested_key ] = $this->asset_neutral_value( $nested, $nested_key ); }
        return $out;
    }

    private function variant_style( array $style ) {
        $keep = array();
        foreach ( array( 'background', 'color', 'border_color', 'border_radius', 'shadow', 'object_fit', 'object_position', 'background_size', 'background_position' ) as $key ) {
            if ( array_key_exists( $key, $style ) ) { $keep[ $key ] = $style[ $key ]; }
        }
        return $this->stable_value( $keep );
    }

    private function stable_value( $value ) {
        if ( ! is_array( $value ) ) { return $value; }
        $assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
        if ( $assoc ) { ksort( $value ); }
        foreach ( $value as $key => $nested ) { $value[ $key ] = $this->stable_value( $nested ); }
        return $value;
    }
}
