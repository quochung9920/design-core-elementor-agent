<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Design_Core_Elementor_Semantic_Matcher {
    /**
     * Score semantic similarity between two HTML strings
     *
     * @param string $html_a First HTML
     * @param string $html_b Second HTML
     * @return float Score between 0 and 1
     */
    public function score_similarity( $html_a, $html_b ) {
        $score = 0;

        // Structure similarity
        $score += $this->score_structure_similarity( $html_a, $html_b ) * 0.3;

        // Tag similarity
        $score += $this->score_tag_similarity( $html_a, $html_b ) * 0.2;

        // Content similarity (text length, class patterns)
        $score += $this->score_content_similarity( $html_a, $html_b ) * 0.25;

        // Attribute pattern similarity
        $score += $this->score_attribute_similarity( $html_a, $html_b ) * 0.15;

        // Token similarity (colors, sizes)
        $score += $this->score_token_similarity( $html_a, $html_b ) * 0.1;

        return min( 1.0, max( 0.0, $score ) );
    }

    private function score_structure_similarity( $html_a, $html_b ) {
        $depth_a = $this->get_depth( $html_a );
        $depth_b = $this->get_depth( $html_b );

        if ( $depth_a === 0 || $depth_b === 0 ) {
            return 0;
        }

        $depth_diff = abs( $depth_a - $depth_b );
        return max( 0, 1 - ( $depth_diff / max( $depth_a, $depth_b ) ) );
    }

    private function score_tag_similarity( $html_a, $html_b ) {
        $tags_a = $this->extract_tags( $html_a );
        $tags_b = $this->extract_tags( $html_b );

        if ( empty( $tags_a ) || empty( $tags_b ) ) {
            return 0;
        }

        $common_tags = count( array_intersect( $tags_a, $tags_b ) );
        $total_tags = count( array_unique( array_merge( $tags_a, $tags_b ) ) );

        return $total_tags > 0 ? $common_tags / $total_tags : 0;
    }

    private function score_content_similarity( $html_a, $html_b ) {
        $text_a = $this->extract_text( $html_a );
        $text_b = $this->extract_text( $html_b );

        $len_a = strlen( $text_a );
        $len_b = strlen( $text_b );

        if ( $len_a === 0 || $len_b === 0 ) {
            return 0.5; // Equal weight if one is empty
        }

        $len_diff = abs( $len_a - $len_b );
        $len_similarity = max( 0, 1 - ( $len_diff / max( $len_a, $len_b ) ) );

        // Class pattern similarity
        $classes_a = $this->extract_classes( $html_a );
        $classes_b = $this->extract_classes( $html_b );

        $common_classes = count( array_intersect( $classes_a, $classes_b ) );
        $total_classes = count( array_unique( array_merge( $classes_a, $classes_b ) ) );
        $class_similarity = $total_classes > 0 ? $common_classes / $total_classes : 0;

        return ( $len_similarity + $class_similarity ) / 2;
    }

    private function score_attribute_similarity( $html_a, $html_b ) {
        $attrs_a = $this->extract_attributes( $html_a );
        $attrs_b = $this->extract_attributes( $html_b );

        $common_attrs = 0;
        foreach ( $attrs_a as $attr => $value ) {
            if ( isset( $attrs_b[ $attr ] ) ) {
                $common_attrs++;
            }
        }

        $total_attrs = count( array_unique( array_merge( array_keys( $attrs_a ), array_keys( $attrs_b ) ) ) );
        return $total_attrs > 0 ? $common_attrs / $total_attrs : 0;
    }

    private function score_token_similarity( $html_a, $html_b ) {
        $tokens_a = $this->extract_tokens( $html_a );
        $tokens_b = $this->extract_tokens( $html_b );

        $score = 0;

        // Color similarity
        if ( ! empty( $tokens_a['colors'] ) && ! empty( $tokens_b['colors'] ) ) {
            $color_score = $this->calculate_color_distance( $tokens_a['colors'][0], $tokens_b['colors'][0] );
            $score += $color_score * 0.4;
        }

        // Font size similarity
        if ( ! empty( $tokens_a['sizes'] ) && ! empty( $tokens_b['sizes'] ) ) {
            $size_a = (int) $tokens_a['sizes'][0];
            $size_b = (int) $tokens_b['sizes'][0];
            if ( $size_a > 0 && $size_b > 0 ) {
                $size_diff = abs( $size_a - $size_b );
                $size_similarity = max( 0, 1 - ( $size_diff / max( $size_a, $size_b ) ) );
                $score += $size_similarity * 0.3;
            }
        }

        // Spacing similarity
        if ( ! empty( $tokens_a['spacing'] ) && ! empty( $tokens_b['spacing'] ) ) {
            $spacing_similarity = count( array_intersect( $tokens_a['spacing'], $tokens_b['spacing'] ) ) / 
                                  count( array_unique( array_merge( $tokens_a['spacing'], $tokens_b['spacing'] ) ) );
            $score += $spacing_similarity * 0.3;
        }

        return $score;
    }

    private function get_depth( $html ) {
        $depth = 0;
        $max_depth = 0;

        for ( $i = 0; $i < strlen( $html ); $i++ ) {
            if ( $html[ $i ] === '<' && isset( $html[ $i + 1 ] ) && $html[ $i + 1 ] !== '/' ) {
                $depth++;
                $max_depth = max( $max_depth, $depth );
            } elseif ( substr( $html, $i, 2 ) === '</' ) {
                $depth--;
            }
        }

        return $max_depth;
    }

    private function extract_tags( $html ) {
        $tags = array();
        if ( preg_match_all( '/<([a-z]+)/i', $html, $matches ) ) {
            $tags = array_unique( array_map( 'strtolower', $matches[1] ) );
        }

        return array_values( $tags );
    }

    private function extract_text( $html ) {
        return wp_strip_all_tags( $html );
    }

    private function extract_classes( $html ) {
        $classes = array();
        if ( preg_match_all( '/class=["\']([^"\']+)["\']/', $html, $matches ) ) {
            foreach ( $matches[1] as $class_string ) {
                $classes = array_merge( $classes, explode( ' ', $class_string ) );
            }
        }

        return array_unique( array_filter( $classes ) );
    }

    private function extract_attributes( $html ) {
        $attributes = array();
        if ( preg_match_all( '/(\w+)=["\']([^"\']*)["\']/', $html, $matches ) ) {
            foreach ( $matches[1] as $idx => $attr ) {
                if ( 'class' !== $attr ) {
                    $attributes[ $attr ] = $matches[2][ $idx ];
                }
            }
        }

        return $attributes;
    }

    private function extract_tokens( $html ) {
        $tokens = array(
            'colors' => array(),
            'sizes' => array(),
            'spacing' => array(),
        );

        // Extract hex colors
        if ( preg_match_all( '/#[0-9a-f]{3,6}/i', $html, $matches ) ) {
            $tokens['colors'] = $matches[0];
        }

        // Extract sizes (px, em, rem)
        if ( preg_match_all( '/(\d+)(px|em|rem)/', $html, $matches ) ) {
            $tokens['sizes'] = $matches[1];
        }

        // Extract spacing patterns
        if ( preg_match_all( '/(padding|margin)[^;]*?(\d+)/', $html, $matches ) ) {
            $tokens['spacing'] = array_unique( $matches[2] );
        }

        return $tokens;
    }

    private function calculate_color_distance( $color_a, $color_b ) {
        $rgb_a = $this->hex_to_rgb( $color_a );
        $rgb_b = $this->hex_to_rgb( $color_b );

        if ( ! $rgb_a || ! $rgb_b ) {
            return 0;
        }

        $distance = sqrt(
            pow( $rgb_a[0] - $rgb_b[0], 2 ) +
            pow( $rgb_a[1] - $rgb_b[1], 2 ) +
            pow( $rgb_a[2] - $rgb_b[2], 2 )
        );

        // Normalize to 0-1 range (max distance is sqrt(3 * 255^2))
        return max( 0, 1 - ( $distance / 441 ) );
    }

    private function hex_to_rgb( $hex ) {
        $hex = ltrim( $hex, '#' );

        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if ( strlen( $hex ) !== 6 ) {
            return null;
        }

        return array(
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
        );
    }
}
