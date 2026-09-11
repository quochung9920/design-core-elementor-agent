<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Design_Core_Elementor_Advanced_Analyzer {
    /**
     * Perform deep semantic analysis on HTML
     *
     * @param string $html The HTML to analyze
     * @param string $css Associated CSS
     * @return array Comprehensive analysis results
     */
    public function analyze_semantic_structure( $html, $css = '' ) {
        return array(
            'component_hierarchy' => $this->extract_component_hierarchy( $html ),
            'semantic_sections' => $this->identify_semantic_sections( $html ),
            'layout_patterns' => $this->detect_layout_patterns( $html ),
            'interactive_elements' => $this->extract_interactive_elements( $html ),
            'visual_hierarchy' => $this->analyze_visual_hierarchy( $html, $css ),
            'accessibility_score' => $this->score_accessibility( $html ),
        );
    }

    private function extract_component_hierarchy( $html ) {
        $dom = new DOMDocument();
        @$dom->loadHTML( $html );
        $xpath = new DOMXPath( $dom );

        $components = array();

        // Find all semantic containers
        $containers = array(
            'header' => $xpath->query( '//header' ),
            'nav' => $xpath->query( '//nav' ),
            'main' => $xpath->query( '//main' ),
            'section' => $xpath->query( '//section' ),
            'article' => $xpath->query( '//article' ),
            'aside' => $xpath->query( '//aside' ),
            'footer' => $xpath->query( '//footer' ),
        );

        foreach ( $containers as $type => $node_list ) {
            if ( $node_list->length > 0 ) {
                $components[ $type ] = array(
                    'count' => $node_list->length,
                    'children_types' => $this->get_children_types( $node_list ),
                );
            }
        }

        return $components;
    }

    private function get_children_types( $node_list ) {
        $types = array();

        foreach ( $node_list as $node ) {
            foreach ( $node->childNodes as $child ) {
                if ( $child instanceof DOMElement ) {
                    $tag = strtolower( $child->tagName );
                    $types[ $tag ] = ( $types[ $tag ] ?? 0 ) + 1;
                }
            }
        }

        arsort( $types );
        return $types;
    }

    private function identify_semantic_sections( $html ) {
        $sections = array();

        // Hero section pattern
        if ( $this->match_pattern( $html, '/hero|banner|jumbotron/i' ) ) {
            $sections[] = 'hero';
        }

        // CTA section pattern
        if ( $this->match_pattern( $html, '/(call.?to.?action|cta|action)/i' ) ) {
            $sections[] = 'cta';
        }

        // Features/Services section
        if ( $this->match_pattern( $html, '/(features|services|benefits)/i' ) ) {
            $sections[] = 'features';
        }

        // Testimonials section
        if ( $this->match_pattern( $html, '/(testimonial|review|client)/i' ) ) {
            $sections[] = 'testimonials';
        }

        // Pricing section
        if ( $this->match_pattern( $html, '/pricing|plans|packages/i' ) ) {
            $sections[] = 'pricing';
        }

        // Team section
        if ( $this->match_pattern( $html, '/(team|staff|people|about)/i' ) ) {
            $sections[] = 'team';
        }

        // FAQ section
        if ( $this->match_pattern( $html, '/(faq|frequently|questions)/i' ) ) {
            $sections[] = 'faq';
        }

        // Contact/Footer section
        if ( $this->match_pattern( $html, '/(contact|footer|subscribe)/i' ) ) {
            $sections[] = 'contact';
        }

        return $sections;
    }

    private function detect_layout_patterns( $html ) {
        $patterns = array();

        // Grid layout
        if ( $this->match_pattern( $html, '/display:\s*grid|grid-template|grid-column/i' ) ||
             $this->match_pattern( $html, '/-webkit-column-count|-moz-column-count|column-count/i' ) ) {
            $patterns[] = 'grid';
        }

        // Flexbox layout
        if ( $this->match_pattern( $html, '/display:\s*flex|flex-direction|justify-content|align-items/i' ) ) {
            $patterns[] = 'flexbox';
        }

        // Two-column layout
        if ( $this->count_elements( $html, '/(sidebar|col|column)/i' ) >= 2 ) {
            $patterns[] = 'two-column';
        }

        // Card layout
        if ( $this->count_elements( $html, '/card|box|tile/i' ) >= 3 ) {
            $patterns[] = 'cards';
        }

        // Carousel/Slider
        if ( $this->match_pattern( $html, '/(carousel|slider|swiper)/i' ) ) {
            $patterns[] = 'carousel';
        }

        return array_unique( $patterns );
    }

    private function extract_interactive_elements( $html ) {
        $dom = new DOMDocument();
        @$dom->loadHTML( $html );
        $xpath = new DOMXPath( $dom );

        return array(
            'buttons' => $xpath->query( '//button' )->length + $xpath->query( '//*[@class="btn"]' )->length,
            'forms' => $xpath->query( '//form' )->length,
            'inputs' => $xpath->query( '//input' )->length,
            'images' => $xpath->query( '//img' )->length,
            'links' => $xpath->query( '//a' )->length,
            'videos' => $xpath->query( '//video' )->length + $xpath->query( '//iframe' )->length,
        );
    }

    private function analyze_visual_hierarchy( $html, $css = '' ) {
        // Analyze heading structure
        $heading_levels = array();
        for ( $i = 1; $i <= 6; $i++ ) {
            $count = substr_count( strtolower( $html ), "<h{$i}" );
            if ( $count > 0 ) {
                $heading_levels[ "h{$i}" ] = $count;
            }
        }

        // Analyze font sizes
        $font_sizes = array();
        if ( preg_match_all( '/font-size:\s*(\d+)/', $css, $matches ) ) {
            $font_sizes = array_count_values( $matches[1] );
            arsort( $font_sizes );
        }

        return array(
            'heading_structure' => $heading_levels,
            'font_sizes' => array_slice( $font_sizes, 0, 5 ),
        );
    }

    private function score_accessibility( $html ) {
        $score = 100;

        // Check for alt text on images
        $img_count = substr_count( strtolower( $html ), '<img' );
        if ( $img_count > 0 ) {
            $alt_count = substr_count( strtolower( $html ), 'alt=' );
            $alt_ratio = $alt_count / $img_count;
            $score -= ( 1 - $alt_ratio ) * 20;
        }

        // Check for landmark elements
        $landmarks = array( 'header', 'nav', 'main', 'footer', 'article', 'section' );
        foreach ( $landmarks as $landmark ) {
            if ( strpos( strtolower( $html ), "<{$landmark}" ) === false ) {
                $score -= 5;
            }
        }

        // Check for semantic HTML
        $divs = substr_count( strtolower( $html ), '<div' );
        $semantic = $img_count + substr_count( strtolower( $html ), '<section' ) +
                   substr_count( strtolower( $html ), '<article' );
        $semantic_ratio = $semantic / max( 1, $divs + $semantic );
        $score -= ( 1 - $semantic_ratio ) * 15;

        return max( 0, min( 100, $score ) );
    }

    private function match_pattern( $html, $pattern ) {
        return (bool) preg_match( $pattern, $html );
    }

    private function count_elements( $html, $pattern ) {
        $matches = array();
        preg_match_all( $pattern, $html, $matches );
        return count( $matches[0] ?? array() );
    }
}
