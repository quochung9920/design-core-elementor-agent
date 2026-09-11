<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pre-IR Figma normalization stage. Inspired by compiler-style Figma pipelines:
 * read -> normalize -> optimize -> explain. It keeps source evidence, filters
 * invisible nodes, makes absolute positioning explicit, preserves authored
 * stacking semantics and emits warnings rather than inventing target behavior.
 */
class Design_Core_Elementor_Figma_Normalization_Service {
    const VERSION = 1;
    const MAX_NODES = 3000;
    const MAX_DEPTH = 96;

    private $nodes=0;
    private $warnings=array();
    private $variable_refs=array();

    public function normalize( array $root ) {
        $this->nodes=0;$this->warnings=array();$this->variable_refs=array();
        $normalized=$this->node($root,null,0);
        return array(
            'node'=>$normalized,
            'report'=>array(
                'version'=>self::VERSION,
                'nodes'=>$this->nodes,
                'warning_count'=>count($this->warnings),
                'warnings'=>array_values(array_unique($this->warnings)),
                'variable_refs'=>array_values($this->variable_refs),
            ),
        );
    }

    private function node( array $node, $parent_box, $depth ) {
        if($depth>self::MAX_DEPTH){throw new OverflowException('Figma normalization exceeds the bounded depth limit.');}
        if(++$this->nodes>self::MAX_NODES){throw new OverflowException('Figma normalization exceeds the bounded node limit.');}
        $box=is_array($node['absoluteBoundingBox']??null)?$node['absoluteBoundingBox']:array();
        $layout=array();
        if('ABSOLUTE'===strtoupper((string)($node['layoutPositioning']??''))){
            $layout['position']='absolute';
            if(is_array($parent_box)){
                if(isset($box['x'],$parent_box['x'])&&is_numeric($box['x'])&&is_numeric($parent_box['x'])){$layout['left']=array('value'=>(float)$box['x']-(float)$parent_box['x'],'unit'=>'px');}
                if(isset($box['y'],$parent_box['y'])&&is_numeric($box['y'])&&is_numeric($parent_box['y'])){$layout['top']=array('value'=>(float)$box['y']-(float)$parent_box['y'],'unit'=>'px');}
            }
        }

        $refs=$this->collect_variable_refs($node);
        foreach($refs as $ref){$this->variable_refs[$ref['id'].'|'.$ref['path']]=$ref;}
        $warnings=$this->node_warnings($node);
        foreach($warnings as $warning){$this->warnings[]=$warning;}
        $node['_design_core_layout']=$layout;
        $node['_design_core_variable_refs']=$refs;
        $node['_design_core_warnings']=$warnings;
        $node['_design_core_normalization']=array('version'=>self::VERSION,'visibility'=>'included');

        $children=array();
        foreach((array)($node['children']??array()) as $child){
            if(!is_array($child)||false===($child['visible']??true)){continue;}
            $children[]=$this->node($child,$box,$depth+1);
        }
        if(!empty($node['itemReverseZIndex'])&&'NONE'!==strtoupper((string)($node['layoutMode']??'NONE'))){
            $absolute=array();$flow=array();
            foreach($children as $child){if('ABSOLUTE'===strtoupper((string)($child['layoutPositioning']??''))){$absolute[]=$child;}else{$flow[]=$child;}}
            $children=array_merge(array_reverse($absolute),$flow);
        }
        $node['children']=$children;
        return $node;
    }

    private function collect_variable_refs( array $node ) {
        $refs=array();
        $this->walk_bound_variables((array)($node['boundVariables']??array()),'node.boundVariables',$refs);
        foreach(array('fills','strokes','effects') as $field){
            foreach((array)($node[$field]??array()) as $index=>$item){
                if(!is_array($item)){continue;}
                $this->walk_bound_variables((array)($item['boundVariables']??array()),$field.'.'.$index.'.boundVariables',$refs);
                foreach((array)($item['gradientStops']??array()) as $stop_index=>$stop){if(is_array($stop)){$this->walk_bound_variables((array)($stop['boundVariables']??array()),$field.'.'.$index.'.gradientStops.'.$stop_index,$refs);}}
            }
        }
        $unique=array();foreach($refs as $ref){$unique[$ref['id'].'|'.$ref['path']]=$ref;}return array_values($unique);
    }

    private function walk_bound_variables( array $bound, $path, array &$refs ) {
        foreach($bound as $name=>$value){
            if(is_array($value)&&isset($value['id'])){$refs[]=array('id'=>sanitize_text_field((string)$value['id']),'name'=>sanitize_text_field((string)($value['name']??$name)),'path'=>sanitize_text_field($path.'.'.$name));continue;}
            if(is_array($value)){$this->walk_bound_variables($value,$path.'.'.$name,$refs);}
        }
    }

    private function node_warnings( array $node ) {
        $warnings=array();$type=strtoupper((string)($node['type']??''));$name=sanitize_text_field((string)($node['name']??$type));
        if(in_array($type,array('STAR','POLYGON','BOOLEAN_OPERATION','LINE','VECTOR'),true)){$warnings[]=$name.': vector geometry is target-dependent; preserve source evidence and verify rendered Elementor fidelity.';}
        foreach((array)($node['fills']??array()) as $fill){if(!is_array($fill)){continue;}$paint=strtoupper((string)($fill['type']??''));if(in_array($paint,array('GRADIENT_ANGULAR','GRADIENT_DIAMOND'),true)){$warnings[]=$name.': '.$paint.' has no guaranteed native Elementor equivalent; do not silently approximate it.';}}
        foreach((array)($node['effects']??array()) as $effect){if(!is_array($effect)){continue;}$effect_type=strtoupper((string)($effect['type']??''));if($effect_type&&!in_array($effect_type,array('DROP_SHADOW','INNER_SHADOW','LAYER_BLUR','BACKGROUND_BLUR'),true)){$warnings[]=$name.': effect '.$effect_type.' requires target capability verification.';}}
        if('ABSOLUTE'===strtoupper((string)($node['layoutPositioning']??''))){$warnings[]=$name.': absolute Figma positioning was preserved explicitly; responsive behavior requires viewport verification.';}
        return $warnings;
    }
}
