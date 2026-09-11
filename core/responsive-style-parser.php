<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Responsive_Style_Parser {
    private $breakpoints;
    private $ast;
    private $fidelity;
    private static $fallback_cache = array();

    public function __construct( $breakpoints = null ) {
        $this->breakpoints = $breakpoints instanceof Design_Core_Elementor_Breakpoint_Registry ? $breakpoints : new Design_Core_Elementor_Breakpoint_Registry();
        $this->ast = class_exists( 'Design_Core_Elementor_CSS_AST_Service' ) ? new Design_Core_Elementor_CSS_AST_Service() : null;
        $this->fidelity = class_exists( 'Design_Core_Elementor_Breakpoint_Fidelity' ) ? new Design_Core_Elementor_Breakpoint_Fidelity() : null;
    }

    public static function fallbacks_for_dom_path( $path ) {
        $path = (string) $path;
        return isset( self::$fallback_cache[ $path ] ) && is_array( self::$fallback_cache[ $path ] ) ? self::$fallback_cache[ $path ] : array();
    }

    public static function clear_fallback_cache() { self::$fallback_cache = array(); }

    /**
     * Resolve only media ranges that are exactly representable by an active
     * Elementor responsive state. Non-native thresholds are deliberately omitted
     * here and cached as scoped fallbacks; nearest-breakpoint mapping is forbidden.
     */
    public function resolve_for_node( DOMElement $node, $css ) {
        $resolved = array( 'desktop'=>array() );
        foreach ( array_keys( $this->breakpoints->all() ) as $device ) { $resolved[$device]=array(); }
        $resolved['tablet']=$resolved['tablet']??array();$resolved['mobile']=$resolved['mobile']??array();

        if ( $this->ast ) {
            $parsed=$this->ast->parse((string)$css);
            if ( ! is_wp_error($parsed) ) {
                $matched=$this->ast->rules_for_node($node,$parsed);
                if ( ! is_wp_error($matched) ) {
                    $this->remember_fallbacks($node,$this->fallbacks_from_rules($matched));
                    foreach($matched as $rule){
                        $media=(string)($rule['media']??'');
                        if(''===trim($media)){
                            $resolved['desktop']=array_merge($resolved['desktop'],$this->values((array)($rule['declarations']??array())));
                            continue;
                        }
                        $target=$this->native_device($media);
                        if(''===$target){continue;}
                        $resolved[$target]=array_merge($resolved[$target]??array(),$this->values((array)($rule['declarations']??array())));
                    }
                    $inline=$this->values($this->ast->declarations((string)$node->getAttribute('style')));
                    $resolved['desktop']=array_merge($resolved['desktop'],$inline);
                    return $this->remove_redundant_overrides($resolved);
                }
            }
        }

        // Partial-bootstrap fallback. Exact native thresholds may still be authored,
        // but custom media rules are not guessed when CSS AST evidence is unavailable.
        $this->remember_fallbacks($node,array());
        $resolved['desktop']=$this->legacy_collect($node,$this->legacy_strip_media((string)$css));
        if($css&&preg_match_all('/@media\s*(\([^{}]+\))\s*\{((?:[^{}]|\{[^{}]*\})*)\}/is',$css,$matches,PREG_SET_ORDER)){
            foreach($matches as $match){$device=$this->native_device((string)$match[1]);if(''===$device){continue;}$resolved[$device]=array_merge($resolved[$device]??array(),$this->legacy_collect($node,$match[2],false));}
        }
        return $this->remove_redundant_overrides($resolved);
    }

    public function fallbacks_for_node( DOMElement $node, $css ) {
        if(!$this->ast){return array();}
        $parsed=$this->ast->parse((string)$css);if(is_wp_error($parsed)){return array();}
        $matched=$this->ast->rules_for_node($node,$parsed);if(is_wp_error($matched)){return array();}
        return $this->fallbacks_from_rules($matched);
    }

    private function fallbacks_from_rules( array $rules ) {
        $fallbacks=array();
        foreach($rules as $rule){
            $media=(string)($rule['media']??'');
            if(''===trim($media)||''!==$this->native_device($media)){continue;}
            $declarations=(array)($rule['declarations']??array());if(!$declarations){continue;}
            $fallbacks[]=array(
                'media'=>$this->normalize_media($media),
                'declarations'=>$declarations,
                'source_order'=>(int)($rule['source_order']??count($fallbacks)),
                'reason'=>'source-breakpoint-not-native',
            );
        }
        return $fallbacks;
    }

    private function remember_fallbacks( DOMElement $node, array $fallbacks ) {
        $path=$this->visible_dom_path($node);if(''!==$path){self::$fallback_cache[$path]=$fallbacks;}
    }

    private function visible_dom_path( DOMElement $node ) {
        $parts=array();$current=$node;
        while($current instanceof DOMElement && 'body'!==strtolower($current->tagName)){
            $parent=$current->parentNode;
            if(!$parent instanceof DOMElement){return '';}
            $index=0;
            foreach($parent->childNodes as $sibling){
                if(!$sibling instanceof DOMElement||$this->ignorable($sibling)){continue;}
                $index++;
                if($sibling===$current){break;}
            }
            if($index<=0){return '';}
            array_unshift($parts,strtolower($current->tagName).'['.$index.']');
            $current=$parent;
        }
        return $parts?'/body/'.implode('/',$parts):'';
    }

    private function ignorable( DOMElement $element ) { return in_array(strtolower($element->tagName),array('style','script','link','meta','title','base','noscript','template'),true); }

    private function native_device( $media ) {
        if($this->fidelity){return $this->fidelity->native_device($media,$this->breakpoints->all());}
        return '';
    }
    private function normalize_media( $media ) { return $this->fidelity?$this->fidelity->normalize_media($media):trim((string)$media); }

    private function values( array $declarations ) { $out=array();foreach($declarations as $property=>$entry){$value=is_array($entry)?($entry['value']??''):$entry;if(''!==(string)$value){$out[strtolower((string)$property)]=(string)$value;}}return $out; }

    private function remove_redundant_overrides( $resolved ) {
        $base=$resolved['desktop']??array();foreach($resolved as $device=>$declarations){if('desktop'===$device||!is_array($declarations)){continue;}foreach($declarations as $property=>$value){if(array_key_exists($property,$base)&&$base[$property]===$value){unset($resolved[$device][$property]);}}}return $resolved;
    }

    private function legacy_strip_media( $css ) { return preg_replace('/@media[^\{]*\{(?:[^{}]|\{[^{}]*\})*\}/is','',$css); }
    private function legacy_collect( DOMElement $node, $css, $include_inline=true ) {
        $source=$include_inline?(string)$node->getAttribute('style'):'';$selectors=array();$id=trim($node->getAttribute('id'));if($id){$selectors[]='#'.preg_quote($id,'/');}
        foreach(preg_split('/\s+/',trim($node->getAttribute('class'))) as $class_name){if($class_name){$selectors[]='\\.'.preg_quote($class_name,'/');}}
        if(empty($selectors)){$selectors[]='\\b'.preg_quote(strtolower($node->tagName),'/').'\\b';}
        foreach($selectors as $selector){if(preg_match_all('/'.$selector.'(?:\s*[,>+~:\[][^\{]*)?\s*\{([^}]*)\}/i',(string)$css,$matches)){foreach($matches[1] as $block){$source.=';'.$block;}}}
        return $this->legacy_declarations($source);
    }
    private function legacy_declarations( $source ) { $result=array();foreach(preg_split('/;(?=(?:[^\(]*\([^\)]*\))*[^\)]*$)/',(string)$source) as $declaration){if(false===strpos($declaration,':')){continue;}list($property,$value)=array_map('trim',explode(':',$declaration,2));$property=strtolower($property);if($property&&''!==$value){$result[$property]=preg_replace('/\s*!important\s*$/i','',$value);}}return $result; }
}
