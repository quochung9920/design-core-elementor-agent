<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Normalized CSS rule service. Uses sabberworm/php-css-parser when available for
 * standards-aware parsing/normalization, then converts the result into a compact
 * Design Core rule model. A bounded scanner remains as a fail-safe when vendor
 * dependencies are not present in a source checkout.
 */
class Design_Core_Elementor_CSS_AST_Service {
    const VERSION = 1;
    const MAX_BYTES = 2097152;
    const MAX_RULES = 10000;
    const MAX_DEPTH = 24;

    public function engine() { return class_exists( '\\Sabberworm\\CSS\\Parser' ) ? 'sabberworm' : 'design-core-scanner'; }

    public function parse( $css ) {
        $css = (string) $css;
        if ( strlen( $css ) > self::MAX_BYTES ) { return new WP_Error( 'design_core_css_too_large', 'CSS exceeds the 2 MB parser limit.' ); }
        $engine = $this->engine(); $warnings = array(); $normalized = $css;
        if ( 'sabberworm' === $engine ) {
            try {
                $document = ( new \Sabberworm\CSS\Parser( $css ) )->parse();
                $normalized = method_exists( $document, 'render' ) ? (string) $document->render() : $css;
            } catch ( Throwable $exception ) {
                return new WP_Error( 'design_core_css_parse_failed', $exception->getMessage() );
            }
        } else {
            $warnings[] = 'sabberworm/php-css-parser is not loaded; using the bounded Design Core scanner.';
        }
        try { $rules = $this->scan_block( $this->strip_comments( $normalized ), '', 0 ); }
        catch ( Throwable $exception ) { return new WP_Error( 'design_core_css_scan_failed', $exception->getMessage() ); }
        return array( 'schema_version'=>self::VERSION, 'engine'=>$engine, 'rules'=>$rules, 'rule_count'=>count($rules), 'warnings'=>$warnings );
    }

    public function rules_for_node( DOMElement $node, $css ) {
        $parsed = is_array( $css ) ? $css : $this->parse( $css );
        if ( is_wp_error( $parsed ) ) { return $parsed; }
        $out = array();
        foreach ( (array) ( $parsed['rules'] ?? array() ) as $rule ) {
            foreach ( (array) ( $rule['selectors'] ?? array() ) as $selector ) {
                if ( $this->matches_selector( $node, $selector ) ) { $out[] = $rule; break; }
            }
        }
        return $out;
    }

    public function max_width_for_media( $media ) {
        if ( preg_match( '/max-width\s*:\s*(\d+(?:\.\d+)?)px/i', (string) $media, $m ) ) { return (int) round( (float) $m[1] ); }
        return null;
    }

    public function declarations( $body ) {
        $result = array();
        foreach ( $this->split_top_level( (string) $body, ';' ) as $declaration ) {
            $colon = $this->first_top_level_colon( $declaration );
            if ( false === $colon ) { continue; }
            $property = strtolower( trim( substr( $declaration, 0, $colon ) ) );
            $value = trim( substr( $declaration, $colon + 1 ) );
            if ( '' === $property || '' === $value ) { continue; }
            $important = (bool) preg_match( '/\s*!important\s*$/i', $value );
            $value = preg_replace( '/\s*!important\s*$/i', '', $value );
            $result[ $property ] = array( 'value'=>trim((string)$value), 'important'=>$important );
        }
        return $result;
    }

    private function scan_block( $css, $media, $depth ) {
        if ( $depth > self::MAX_DEPTH ) { throw new OverflowException( 'CSS nesting exceeds the bounded parser depth.' ); }
        $rules = array(); $length = strlen( $css ); $offset = 0;
        while ( $offset < $length ) {
            while ( $offset < $length && ctype_space( $css[$offset] ) ) { $offset++; }
            if ( $offset >= $length ) { break; }
            $open = $this->find_open_brace( $css, $offset ); if ( false === $open ) { break; }
            $header = trim( substr( $css, $offset, $open - $offset ) );
            $close = $this->matching_brace( $css, $open ); if ( false === $close ) { throw new RuntimeException( 'Unbalanced CSS block.' ); }
            $body = substr( $css, $open + 1, $close - $open - 1 ); $offset = $close + 1;
            if ( '' === $header ) { continue; }
            if ( 0 === stripos( $header, '@media' ) ) {
                $condition = trim( substr( $header, 6 ) );
                $nested_media = $media ? $media . ' and ' . $condition : $condition;
                $rules = array_merge( $rules, $this->scan_block( $body, $nested_media, $depth + 1 ) );
                continue;
            }
            if ( 0 === strpos( $header, '@' ) ) { continue; }
            $selectors = array_values( array_filter( array_map( 'trim', $this->split_top_level( $header, ',' ) ) ) );
            $declarations = $this->declarations( $body );
            if ( ! $selectors || ! $declarations ) { continue; }
            $rules[] = array( 'selectors'=>$selectors, 'declarations'=>$declarations, 'media'=>$media, 'source_order'=>count($rules) );
            if ( count( $rules ) > self::MAX_RULES ) { throw new OverflowException( 'CSS contains too many rules.' ); }
        }
        return $rules;
    }

    private function matches_selector( DOMElement $node, $selector ) {
        $selector = trim( (string) $selector ); if ( '' === $selector ) { return false; }
        // For cascade approximation, match the right-most compound selector. The browser
        // analyzer remains authoritative for complex selector/state resolution.
        $parts = preg_split( '/\s+|(?=[>+~])|(?<=[>+~])/', $selector );
        $compound = trim( (string) end( $parts ) );
        $compound = preg_replace( '/::?[a-zA-Z0-9_-]+(?:\([^)]*\))?/', '', $compound );
        $compound = preg_replace( '/\[[^\]]+\]/', '', $compound );
        if ( preg_match( '/#([a-zA-Z0-9_-]+)/', $compound, $m ) && $node->getAttribute('id') !== $m[1] ) { return false; }
        if ( preg_match_all( '/\.([a-zA-Z0-9_-]+)/', $compound, $matches ) ) {
            $classes = preg_split( '/\s+/', trim( $node->getAttribute('class') ) );
            foreach ( $matches[1] as $class ) { if ( ! in_array( $class, $classes, true ) ) { return false; } }
        }
        if ( preg_match( '/^([a-zA-Z][a-zA-Z0-9_-]*)/', $compound, $m ) && strtolower($node->tagName) !== strtolower($m[1]) ) { return false; }
        return (bool) preg_match( '/[#.a-zA-Z*]/', $compound );
    }

    private function strip_comments( $css ) { return preg_replace( '#/\*.*?\*/#s', '', (string) $css ); }

    private function find_open_brace( $text, $start ) {
        $quote = ''; $escape = false; $paren = 0; $len = strlen($text);
        for ( $i=$start; $i<$len; $i++ ) {
            $c=$text[$i];
            if ( $escape ) { $escape=false; continue; }
            if ( '\\' === $c ) { $escape=true; continue; }
            if ( $quote ) { if ( $c === $quote ) { $quote=''; } continue; }
            if ( '"' === $c || "'" === $c ) { $quote=$c; continue; }
            if ( '(' === $c ) { $paren++; continue; } if ( ')' === $c && $paren ) { $paren--; continue; }
            if ( '{' === $c && 0 === $paren ) { return $i; }
        }
        return false;
    }

    private function matching_brace( $text, $open ) {
        $depth=0; $quote=''; $escape=false; $len=strlen($text);
        for ( $i=$open; $i<$len; $i++ ) {
            $c=$text[$i]; if($escape){$escape=false;continue;} if('\\'===$c){$escape=true;continue;}
            if($quote){if($c===$quote){$quote='';}continue;} if('"'===$c||"'"===$c){$quote=$c;continue;}
            if('{'===$c){$depth++;} elseif('}'===$c && 0===--$depth){return $i;}
        }
        return false;
    }

    private function split_top_level( $text, $delimiter ) {
        $parts=array();$start=0;$paren=0;$bracket=0;$quote='';$escape=false;$len=strlen($text);
        for($i=0;$i<$len;$i++){$c=$text[$i];if($escape){$escape=false;continue;}if('\\'===$c){$escape=true;continue;}if($quote){if($c===$quote){$quote='';}continue;}if('"'===$c||"'"===$c){$quote=$c;continue;}if('('===$c){$paren++;continue;}if(')'===$c&&$paren){$paren--;continue;}if('['===$c){$bracket++;continue;}if(']'===$c&&$bracket){$bracket--;continue;}if($c===$delimiter&&0===$paren&&0===$bracket){$parts[]=substr($text,$start,$i-$start);$start=$i+1;}}
        $parts[]=substr($text,$start);return $parts;
    }

    private function first_top_level_colon( $text ) {
        $paren=0;$bracket=0;$quote='';$escape=false;$len=strlen($text);
        for($i=0;$i<$len;$i++){$c=$text[$i];if($escape){$escape=false;continue;}if('\\'===$c){$escape=true;continue;}if($quote){if($c===$quote){$quote='';}continue;}if('"'===$c||"'"===$c){$quote=$c;continue;}if('('===$c){$paren++;continue;}if(')'===$c&&$paren){$paren--;continue;}if('['===$c){$bracket++;continue;}if(']'===$c&&$bracket){$bracket--;continue;}if(':'===$c&&0===$paren&&0===$bracket){return $i;}}
        return false;
    }
}
