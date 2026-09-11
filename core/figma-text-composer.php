<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reconstructs designer-authored Figma heading/text compositions before the
 * Design IR is lowered to Elementor widgets.
 *
 * Figma often represents one visual heading as several absolutely positioned
 * TEXT nodes (for example an italic accent word inside an otherwise sans-serif
 * heading). Treating those nodes independently destroys line composition. This
 * service merges only explicit heading-like containers and preserves per-run
 * typography as text-level HTML.
 */
class Design_Core_Elementor_Figma_Text_Composer {
    const VERSION = 1;
    const MAX_TEXT_LEAVES = 48;
    const MAX_DEPTH = 8;
    const MAX_CHARACTERS = 4000;

    public function compose_container( array $node ) {
        $tag = $this->heading_tag( $node );
        if ( '' === $tag ) { return null; }

        $leaves = array();
        $blocking = false;
        $this->collect_text_leaves( $node, $leaves, $blocking, 0 );
        if ( $blocking || count( $leaves ) < 2 || count( $leaves ) > self::MAX_TEXT_LEAVES ) { return null; }

        $character_count = 0;
        $fragments = array();
        foreach ( $leaves as $index => $leaf ) {
            $character_count += $this->strlen( (string) ( $leaf['characters'] ?? '' ) );
            if ( $character_count > self::MAX_CHARACTERS ) { return null; }
            foreach ( $this->text_fragments( $leaf, $index ) as $fragment ) { $fragments[] = $fragment; }
        }
        if ( count( $fragments ) < 2 ) { return null; }

        usort( $fragments, static function ( $a, $b ) {
            $dy = (float) $a['y'] - (float) $b['y'];
            if ( abs( $dy ) > 0.5 ) { return $dy < 0 ? -1 : 1; }
            $dx = (float) $a['x'] - (float) $b['x'];
            if ( abs( $dx ) > 0.5 ) { return $dx < 0 ? -1 : 1; }
            return (int) $a['order'] <=> (int) $b['order'];
        } );

        $lines = array();
        foreach ( $fragments as $fragment ) {
            $placed = false;
            foreach ( $lines as &$line ) {
                $tolerance = max( 5.0, min( 24.0, max( (float) $line['font_size'], (float) $fragment['font_size'] ) * 0.42 ) );
                if ( abs( (float) $line['y'] - (float) $fragment['y'] ) <= $tolerance ) {
                    $line['fragments'][] = $fragment;
                    $line['font_size'] = max( (float) $line['font_size'], (float) $fragment['font_size'] );
                    $line['y'] = min( (float) $line['y'], (float) $fragment['y'] );
                    $placed = true;
                    break;
                }
            }
            unset( $line );
            if ( ! $placed ) {
                $lines[] = array( 'y' => (float) $fragment['y'], 'font_size' => (float) $fragment['font_size'], 'fragments' => array( $fragment ) );
            }
        }
        usort( $lines, static fn( $a, $b ) => (float) $a['y'] <=> (float) $b['y'] );

        $plain_lines = array();
        $rich_lines = array();
        $all_runs = array();
        $source_ids = array();
        foreach ( $lines as $line ) {
            usort( $line['fragments'], static function ( $a, $b ) {
                $dx = (float) $a['x'] - (float) $b['x'];
                return abs( $dx ) > 0.5 ? ( $dx < 0 ? -1 : 1 ) : ( (int) $a['order'] <=> (int) $b['order'] );
            } );
            $plain = '';
            $rich = '';
            foreach ( $line['fragments'] as $fragment ) {
                $text = (string) $fragment['text'];
                if ( '' !== $plain && '' !== $text && ! preg_match( '/\s$/u', $plain ) && ! preg_match( '/^\s/u', $text ) && $this->needs_visual_space( $fragment, $line['fragments'] ) ) {
                    $plain .= ' ';
                    $rich .= ' ';
                }
                $plain .= $text;
                $rich .= (string) $fragment['html'];
                foreach ( (array) $fragment['runs'] as $run ) { $all_runs[] = $run; }
                if ( ! empty( $fragment['source_id'] ) ) { $source_ids[] = (string) $fragment['source_id']; }
            }
            $plain_lines[] = rtrim( $plain );
            $rich_lines[] = rtrim( $rich );
        }

        $base = $this->dominant_style( $fragments );
        return array(
            'version' => self::VERSION,
            'tag' => $tag,
            'text' => implode( "\n", $plain_lines ),
            'rich_text' => implode( '<br>', $rich_lines ),
            'style' => $this->ir_style( $base ),
            'runs' => $all_runs,
            'source_ids' => array_values( array_unique( $source_ids ) ),
            'geometry' => $this->geometry( $node ),
            'line_count' => count( $lines ),
            'composition' => 'figma-geometry-text-merge',
        );
    }

    /** Preserve styleOverrideTable runs for a normal single Figma TEXT node. */
    public function compose_text_node( array $node ) {
        if ( 'TEXT' !== strtoupper( (string) ( $node['type'] ?? '' ) ) ) { return null; }
        $characters = (string) ( $node['characters'] ?? '' );
        if ( '' === $characters ) { return null; }
        $lines = $this->styled_lines( $node );
        $plain = array();
        $rich = array();
        $runs = array();
        foreach ( $lines as $line ) {
            $plain_text = '';
            $rich_text = '';
            foreach ( $line as $run ) {
                $plain_text .= $run['text'];
                $rich_text .= $this->run_html( $run['text'], $run['style'] );
                $runs[] = $run;
            }
            $plain[] = $plain_text;
            $rich[] = $rich_text;
        }
        return array(
            'text' => implode( "\n", $plain ),
            'rich_text' => implode( '<br>', $rich ),
            'runs' => $runs,
            'style' => $this->ir_style( $this->merged_style( $node, null ) ),
        );
    }

    private function heading_tag( array $node ) {
        $type = strtoupper( (string) ( $node['type'] ?? '' ) );
        if ( ! in_array( $type, array( 'FRAME', 'GROUP', 'SECTION', 'COMPONENT', 'INSTANCE' ), true ) ) { return ''; }
        $name = strtolower( trim( (string) ( $node['name'] ?? '' ) ) );
        if ( preg_match( '/(?:^|[^a-z0-9])h([1-6])(?:[^a-z0-9]|$)/i', $name, $match ) ) { return 'h' . $match[1]; }
        if ( preg_match( '/\b(?:hero[-_ ]?(?:heading|headline|title)|headline|heading|page[-_ ]?title)\b/i', $name ) ) {
            return false !== strpos( $name, 'hero' ) ? 'h1' : 'h2';
        }
        return '';
    }

    private function collect_text_leaves( array $node, array &$leaves, &$blocking, $depth ) {
        if ( $blocking || $depth > self::MAX_DEPTH ) { $blocking = true; return; }
        foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
            if ( ! is_array( $child ) || false === ( $child['visible'] ?? true ) ) { continue; }
            $type = strtoupper( (string) ( $child['type'] ?? '' ) );
            if ( 'TEXT' === $type ) {
                if ( '' !== trim( (string) ( $child['characters'] ?? '' ) ) ) { $leaves[] = $child; }
                continue;
            }
            if ( in_array( $type, array( 'FRAME', 'GROUP', 'SECTION' ), true ) ) {
                $this->collect_text_leaves( $child, $leaves, $blocking, $depth + 1 );
                continue;
            }
            $blocking = true;
            return;
        }
    }

    private function text_fragments( array $node, $order ) {
        $box = (array) ( $node['absoluteBoundingBox'] ?? array() );
        $x = (float) ( $box['x'] ?? 0 );
        $y = (float) ( $box['y'] ?? 0 );
        $style = $this->merged_style( $node, null );
        $line_height = (float) ( $style['lineHeightPx'] ?? $style['fontSize'] ?? 16 );
        if ( $line_height <= 0 ) { $line_height = max( 1.0, (float) ( $style['fontSize'] ?? 16 ) ); }
        $lines = $this->styled_lines( $node );
        $out = array();
        foreach ( $lines as $line_index => $runs ) {
            $text = '';
            $html = '';
            $run_evidence = array();
            $dominant = $style;
            foreach ( $runs as $run ) {
                $text .= (string) $run['text'];
                $html .= $this->run_html( (string) $run['text'], (array) $run['style'] );
                $dominant = (array) $run['style'];
                $run_evidence[] = array( 'text' => (string) $run['text'], 'style' => $this->ir_style( (array) $run['style'] ) );
            }
            if ( '' === $text ) { continue; }
            $out[] = array( 'x' => $x, 'y' => $y + $line_index * $line_height, 'order' => (int) $order, 'text' => $text, 'html' => $html, 'font_size' => (float) ( $dominant['fontSize'] ?? $style['fontSize'] ?? 16 ), 'style' => $dominant, 'runs' => $run_evidence, 'source_id' => sanitize_text_field( (string) ( $node['id'] ?? '' ) ) );
        }
        return $out;
    }

    private function styled_lines( array $node ) {
        $characters = (string) ( $node['characters'] ?? '' );
        $base = $this->merged_style( $node, null );
        $overrides = is_array( $node['characterStyleOverrides'] ?? null ) ? array_values( $node['characterStyleOverrides'] ) : array();
        $table = is_array( $node['styleOverrideTable'] ?? null ) ? $node['styleOverrideTable'] : array();
        $chars = preg_split( '//u', $characters, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $chars ) ) { $chars = str_split( $characters ); }
        $lines = array( array() ); $current_text = ''; $current_override = null;
        $flush = function () use ( &$current_text, &$current_override, &$lines, $node, $table ) {
            if ( '' === $current_text ) { return; }
            $override_style = null;
            if ( null !== $current_override && isset( $table[ (string) $current_override ] ) && is_array( $table[ (string) $current_override ] ) ) { $override_style = $table[ (string) $current_override ]; }
            elseif ( null !== $current_override && isset( $table[ $current_override ] ) && is_array( $table[ $current_override ] ) ) { $override_style = $table[ $current_override ]; }
            $lines[ count( $lines ) - 1 ][] = array( 'text' => $current_text, 'style' => $this->merged_style( $node, $override_style ) );
            $current_text = '';
        };
        foreach ( $chars as $index => $char ) {
            if ( "\n" === $char || "\r" === $char ) { $flush(); if ( "\r" === $char && isset( $chars[ $index + 1 ] ) && "\n" === $chars[ $index + 1 ] ) { continue; } $lines[] = array(); $current_override = null; continue; }
            $override = array_key_exists( $index, $overrides ) ? $overrides[ $index ] : 0;
            if ( null !== $current_override && (string) $override !== (string) $current_override ) { $flush(); }
            $current_override = $override; $current_text .= $char;
        }
        $flush();
        foreach ( $lines as &$line ) { if ( ! $line ) { $line[] = array( 'text' => '', 'style' => $base ); } } unset( $line );
        return $lines;
    }

    private function merged_style( array $node, $override ) {
        $style = is_array( $node['style'] ?? null ) ? $node['style'] : array();
        if ( is_array( $override ) ) { $style = array_replace( $style, $override ); }
        if ( is_array( $override ) && isset( $override['fills'] ) ) { $style['fills'] = $override['fills']; }
        elseif ( isset( $node['fills'] ) ) { $style['fills'] = $node['fills']; }
        return $style;
    }

    private function run_html( $text, array $style ) {
        $css = array();
        if ( ! empty( $style['fontFamily'] ) ) { $family = str_replace( array( "'", '"', ';', '{', '}' ), '', sanitize_text_field( (string) $style['fontFamily'] ) ); if ( '' !== $family ) { $css[] = "font-family:'" . $family . "'"; } }
        if ( isset( $style['fontWeight'] ) && is_numeric( $style['fontWeight'] ) ) { $css[] = 'font-weight:' . (int) $style['fontWeight']; }
        $font_style = strtolower( (string) ( $style['fontStyle'] ?? '' ) ); if ( false !== strpos( $font_style, 'italic' ) ) { $css[] = 'font-style:italic'; }
        if ( isset( $style['fontSize'] ) && is_numeric( $style['fontSize'] ) ) { $css[] = 'font-size:' . $this->number( $style['fontSize'] ) . 'px'; }
        if ( isset( $style['lineHeightPx'] ) && is_numeric( $style['lineHeightPx'] ) ) { $css[] = 'line-height:' . $this->number( $style['lineHeightPx'] ) . 'px'; }
        if ( isset( $style['letterSpacing'] ) && is_numeric( $style['letterSpacing'] ) ) { $css[] = 'letter-spacing:' . $this->number( $style['letterSpacing'] ) . 'px'; }
        $color = $this->style_color( $style ); if ( '' !== $color ) { $css[] = 'color:' . $color; }
        $escaped = esc_html( (string) $text );
        return $css ? '<span style="' . esc_attr( implode( ';', $css ) ) . '">' . $escaped . '</span>' : $escaped;
    }

    private function ir_style( array $style ) {
        $out = array();
        if ( ! empty( $style['fontFamily'] ) ) { $out['font_family'] = sanitize_text_field( (string) $style['fontFamily'] ); }
        if ( isset( $style['fontWeight'] ) && is_numeric( $style['fontWeight'] ) ) { $out['font_weight'] = (int) $style['fontWeight']; }
        if ( isset( $style['fontSize'] ) && is_numeric( $style['fontSize'] ) ) { $out['font_size'] = array( 'value' => (float) $style['fontSize'], 'unit' => 'px' ); }
        if ( isset( $style['lineHeightPx'] ) && is_numeric( $style['lineHeightPx'] ) ) { $out['line_height'] = array( 'value' => (float) $style['lineHeightPx'], 'unit' => 'px' ); }
        if ( isset( $style['letterSpacing'] ) && is_numeric( $style['letterSpacing'] ) ) { $out['letter_spacing'] = array( 'value' => (float) $style['letterSpacing'], 'unit' => 'px' ); }
        if ( false !== strpos( strtolower( (string) ( $style['fontStyle'] ?? '' ) ), 'italic' ) ) { $out['font_style'] = 'italic'; }
        $color = $this->style_color( $style ); if ( '' !== $color ) { $out['color'] = $color; }
        return $out;
    }

    private function dominant_style( array $fragments ) { $best = array(); $best_length = -1; foreach ( $fragments as $fragment ) { $length = $this->strlen( trim( (string) $fragment['text'] ) ); if ( $length > $best_length ) { $best_length = $length; $best = (array) $fragment['style']; } } return $best; }
    private function style_color( array $style ) { foreach ( (array) ( $style['fills'] ?? array() ) as $fill ) { if ( ! is_array( $fill ) || false === ( $fill['visible'] ?? true ) || 'SOLID' !== strtoupper( (string) ( $fill['type'] ?? '' ) ) ) { continue; } return $this->rgba( (array) ( $fill['color'] ?? array() ), (float) ( $fill['opacity'] ?? 1 ) ); } return ''; }
    private function needs_visual_space( array $fragment, array $siblings ) { foreach ( $siblings as $candidate ) { if ( $candidate === $fragment ) { continue; } if ( (float) $candidate['x'] < (float) $fragment['x'] && abs( (float) $candidate['y'] - (float) $fragment['y'] ) < 24 ) { return true; } } return false; }
    private function geometry( array $node ) { $box = (array) ( $node['absoluteBoundingBox'] ?? array() ); return array( 'x' => (float) ( $box['x'] ?? 0 ), 'y' => (float) ( $box['y'] ?? 0 ), 'width' => (float) ( $box['width'] ?? 0 ), 'height' => (float) ( $box['height'] ?? 0 ) ); }
    private function rgba( array $color, $opacity ) { $r = (int) round( 255 * (float) ( $color['r'] ?? 0 ) ); $g = (int) round( 255 * (float) ( $color['g'] ?? 0 ) ); $b = (int) round( 255 * (float) ( $color['b'] ?? 0 ) ); $a = max( 0, min( 1, (float) ( $color['a'] ?? 1 ) * (float) $opacity ) ); return $a >= 0.999 ? sprintf( '#%02x%02x%02x', $r, $g, $b ) : sprintf( 'rgba(%d,%d,%d,%.3f)', $r, $g, $b, $a ); }
    private function number( $value ) { return rtrim( rtrim( number_format( (float) $value, 3, '.', '' ), '0' ), '.' ); }
    private function strlen( $value ) { return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value ); }
}
