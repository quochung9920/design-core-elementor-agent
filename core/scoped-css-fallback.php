<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Last-resort styling that stays owned by the exact Elementor element. */
class Design_Core_Elementor_Scoped_CSS_Fallback {
    private $registry;
    public function __construct( Design_Core_Elementor_Control_Schema_Registry $registry ) { $this->registry = $registry; }

    public function apply( $element_type, $widget_type, array $settings, array $declarations ) {
        $unsupported = array(); $safe = array(); $safe_properties = array();
        if ( ! $this->registry->has( $element_type, $widget_type, 'custom_css' ) ) {
            return array( 'settings' => $settings, 'custom_css' => array(), 'custom_css_properties' => array(), 'unsupported' => array_keys( $declarations ) );
        }
        foreach ( $declarations as $property => $value ) {
            $checked = $this->safe_declaration( $property, $value );
            if ( ! $checked ) { $unsupported[] = strtolower( trim( (string) $property ) ); continue; }
            $safe[] = $checked['property'] . ': ' . $checked['value'] . ';'; $safe_properties[] = $checked['property'];
        }
        if ( $safe ) {
            $settings['custom_css'] = $this->append_css( $settings['custom_css'] ?? '', "selector {\n  " . implode( "\n  ", $safe ) . "\n}" );
        }
        return array( 'settings' => $settings, 'custom_css' => $safe, 'custom_css_properties' => $safe_properties, 'unsupported' => $unsupported );
    }

    /**
     * Preserve exact source width ranges that do not match an Elementor breakpoint.
     * The source selector is intentionally discarded: the rules are rebound to the
     * exact owning Elementor element through `selector`.
     */
    public function apply_responsive( $element_type, $widget_type, array $settings, array $fallbacks ) {
        $unsupported=array();$safe_blocks=array();$safe_properties=array();
        if(!$fallbacks){return array('settings'=>$settings,'custom_css'=>array(),'custom_css_properties'=>array(),'unsupported'=>array());}
        if(!$this->registry->has($element_type,$widget_type,'custom_css')){
            foreach($fallbacks as $fallback){$unsupported[]='responsive-media:'.sanitize_key((string)($fallback['media']??'unknown'));}
            return array('settings'=>$settings,'custom_css'=>array(),'custom_css_properties'=>array(),'unsupported'=>$unsupported);
        }
        $fidelity=class_exists('Design_Core_Elementor_Breakpoint_Fidelity')?new Design_Core_Elementor_Breakpoint_Fidelity():null;
        foreach($fallbacks as $fallback){
            if(!is_array($fallback)){continue;}
            $media=$fidelity?$fidelity->normalize_media((string)($fallback['media']??'')):trim((string)($fallback['media']??''));
            if(!$fidelity||!$fidelity->safe_fallback_media($media)){$unsupported[]='responsive-media:'.sanitize_key($media?:'invalid');continue;}
            $lines=array();
            foreach((array)($fallback['declarations']??array()) as $property=>$entry){
                $value=is_array($entry)?($entry['value']??''):$entry;
                $important=is_array($entry)&&!empty($entry['important']);
                $checked=$this->safe_declaration($property,$value);
                if(!$checked){$unsupported[]='responsive-'.sanitize_key((string)$property);continue;}
                $lines[]=$checked['property'].': '.$checked['value'].($important?' !important':'').';';
                $safe_properties[]=$fidelity->coverage_key($checked['property']);
            }
            if(!$lines){continue;}
            $block="@media ".$media." {\n  selector {\n    ".implode("\n    ",$lines)."\n  }\n}";
            $settings['custom_css']=$this->append_css($settings['custom_css']??'',$block);
            $safe_blocks[]=$block;
        }
        return array(
            'settings'=>$settings,
            'custom_css'=>$safe_blocks,
            'custom_css_properties'=>array_values(array_unique($safe_properties)),
            'unsupported'=>array_values(array_unique($unsupported)),
        );
    }

    private function safe_declaration( $property, $value ) {
        $property = strtolower( trim( (string) $property ) );
        // Zero-effect declarations inside Elementor (already border-box;
        // `content` only applies to pseudo-elements) must never inflate output.
        if ( class_exists( 'Design_Core_Elementor_Binding_Governor' ) && Design_Core_Elementor_Binding_Governor::is_trivial_css( $property ) ) { return null; }
        $value = trim( (string) $value );
        if ( ! preg_match( '/^(?:--[a-z0-9_-]+|[a-z][a-z0-9-]*)$/', $property ) || '' === $value || preg_match( '/[{};<>\\\\\x00-\x1F\x7F]/', $value ) || false !== stripos( $property, '@' ) || false !== stripos( $value, '@import' ) || false !== stripos( $value, 'javascript:' ) || false !== stripos( $value, 'data:' ) || false !== stripos( $value, 'url(' ) || false !== stripos( $value, 'image-set(' ) || false !== stripos( $value, 'expression(' ) ) {
            return null;
        }
        return array( 'property'=>$property, 'value'=>$value );
    }

    private function append_css( $existing, $next ) {
        $existing=trim((string)$existing);$next=trim((string)$next);
        if(''===$existing){return $next;}if(''===$next){return $existing;}
        return $existing."\n".$next;
    }
}
