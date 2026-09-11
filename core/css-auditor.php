<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Css_Auditor {
    public function audit( $css ) {
        $native=array('display','flex-direction','flex-wrap','justify-content','align-items','gap','row-gap','column-gap','grid-template-columns','grid-template-rows','width','max-width','min-width','height','min-height','padding','margin','background','background-color','background-image','border','border-width','border-style','border-color','border-radius','box-shadow','color','font-size','font-family','font-weight','line-height','letter-spacing','text-align','text-transform','opacity','position','inset','top','right','bottom','left','z-index','overflow','object-fit','aspect-ratio','transform','transition');
        $violations=array();$fallbacks=array();$rule_count=0;$declaration_count=0;$parser=array('engine'=>'legacy-regex','warnings'=>array());

        if(class_exists('Design_Core_Elementor_CSS_AST_Service')){
            $parsed=(new Design_Core_Elementor_CSS_AST_Service())->parse((string)$css);
            if(!is_wp_error($parsed)){
                $parser=array('engine'=>$parsed['engine']??'unknown','warnings'=>$parsed['warnings']??array());
                foreach((array)($parsed['rules']??array()) as $rule){
                    $selectors=(array)($rule['selectors']??array());if(!$selectors){continue;}$rule_count++;
                    foreach((array)($rule['declarations']??array()) as $property=>$entry){
                        $property=strtolower((string)$property);$value=is_array($entry)?(string)($entry['value']??''):(string)$entry;if(''===$property){continue;}$declaration_count++;
                        foreach($selectors as $selector){
                            $pseudo=false!==strpos($selector,'::before')||false!==strpos($selector,'::after');
                            if($pseudo){$fallbacks[]=array('selector'=>$selector,'property'=>$property,'value'=>$value,'reason'=>'pseudo-element');}
                            elseif(in_array($property,$native,true)||0===strpos($property,'border-')||0===strpos($property,'background-')){$violations[]=array('selector'=>$selector,'property'=>$property,'value'=>$value,'type'=>'native-control-candidate','media'=>$rule['media']??'');}
                            else{$fallbacks[]=array('selector'=>$selector,'property'=>$property,'value'=>$value,'reason'=>'no-known-native-mapping','media'=>$rule['media']??'');}
                        }
                    }
                }
                return $this->result($rule_count,$declaration_count,$violations,$fallbacks,$parser);
            }
            $parser['warnings'][]=$parsed->get_error_message();
        }

        if(preg_match_all('/([^{}]+)\{([^{}]*)\}/s',(string)$css,$matches,PREG_SET_ORDER)){
            foreach($matches as $match){$selector=trim($match[1]);$body=trim($match[2]);if(''===$body){continue;}$rule_count++;$pseudo=false!==strpos($selector,'::before')||false!==strpos($selector,'::after');foreach(preg_split('/;(?![^\(]*\))/',$body) as $declaration){if(false===strpos($declaration,':')){continue;}list($property,$value)=array_map('trim',explode(':',$declaration,2));$property=strtolower($property);if(!$property){continue;}$declaration_count++;if($pseudo){$fallbacks[]=array('selector'=>$selector,'property'=>$property,'value'=>$value,'reason'=>'pseudo-element');}elseif(in_array($property,$native,true)||0===strpos($property,'border-')||0===strpos($property,'background-')){$violations[]=array('selector'=>$selector,'property'=>$property,'value'=>$value,'type'=>'native-control-candidate');}else{$fallbacks[]=array('selector'=>$selector,'property'=>$property,'value'=>$value,'reason'=>'no-known-native-mapping');}}}
        }
        return $this->result($rule_count,$declaration_count,$violations,$fallbacks,$parser);
    }

    private function result($rule_count,$declaration_count,array $violations,array $fallbacks,array $parser){return array('total_rules'=>$rule_count,'total_declarations'=>$declaration_count,'native_candidates'=>count($violations),'fallback_candidates'=>count($fallbacks),'violations'=>$violations,'fallbacks'=>$fallbacks,'native_violation_ratio'=>$declaration_count?count($violations)/$declaration_count:0,'parser'=>$parser,'status'=>count($violations)?'requires-native-review':'clean');}
}
