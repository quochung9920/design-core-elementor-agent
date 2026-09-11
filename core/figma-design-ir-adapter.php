<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Figma REST/export payload -> canonical Design IR v4.
 *
 * v3 adds a compiler-style normalization pass before IR conversion: hidden-node
 * filtering, authored stacking order, explicit absolute positioning evidence,
 * variable-reference collection and conversion warnings. No target behavior is
 * guessed when Elementor fidelity cannot be proven.
 */
class Design_Core_Elementor_Figma_Design_IR_Adapter {
    const VERSION = 3;
    const MAX_NODES = 2500;
    const MAX_DEPTH = 96;

    private $nodes = array();
    private $node_count = 0;
    private $image_fills = array();
    private $transport_source = array();
    private $normalization_report = array();

    public function convert( array $payload, $selected_node_id = '' ) {
        $this->nodes=array();$this->node_count=0;$this->image_fills=array();$this->transport_source=array();$this->normalization_report=array();
        if(is_array($payload['figma']??null)){
            $this->image_fills=is_array($payload['image_fills']??null)?$payload['image_fills']:array();
            $this->transport_source=is_array($payload['source']??null)?$payload['source']:array();
            if(''===$selected_node_id&&!empty($this->transport_source['node_id'])){$selected_node_id=(string)$this->transport_source['node_id'];}
            $payload=$payload['figma'];
        }elseif(is_array($payload['image_fills']??null)){$this->image_fills=$payload['image_fills'];}

        $root=$this->resolve_root($payload,(string)$selected_node_id);
        if(!is_array($root)){return new WP_Error('design_core_figma_root_missing','Figma root node could not be resolved.');}
        if(class_exists('Design_Core_Elementor_Figma_Normalization_Service')){
            try{$normalized=(new Design_Core_Elementor_Figma_Normalization_Service())->normalize($root);$root=(array)($normalized['node']??$root);$this->normalization_report=(array)($normalized['report']??array());}
            catch(Throwable $exception){return new WP_Error('design_core_figma_normalization_failed',$exception->getMessage());}
        }
        try{$root_id=$this->convert_node($root,'',0);}catch(Throwable $exception){return new WP_Error('design_core_figma_invalid',$exception->getMessage());}
        if(!$root_id){return new WP_Error('design_core_figma_empty','Figma selection produced no convertible nodes.');}

        $ir=array(
            'schema_version'=>Design_Core_Elementor_Design_IR::SCHEMA_VERSION,'type'=>'design-ir','source_name'=>'figma',
            'nodes'=>array_values($this->nodes),'root_ids'=>array($root_id),
            'analysis_quality'=>array('browser_runtime'=>'figma','computed_styles'=>'figma','geometry'=>'figma','css_static'=>'figma','interaction'=>'figma'),
            'tokens'=>$this->extract_tokens($payload),'breakpoints'=>array(),
            'diagnostics'=>array(
                'figma_adapter_version'=>self::VERSION,'figma_source_node_id'=>(string)($root['id']??''),'node_count'=>$this->node_count,
                'resolved_image_fills'=>count($this->image_fills),'transport_source'=>Design_Core_Elementor_Change_Ledger::transport_safe($this->transport_source),
                'normalization'=>Design_Core_Elementor_Change_Ledger::transport_safe($this->normalization_report),
            ),
        );
        try{(new Design_Core_Elementor_Design_IR_Validator())->validate($ir);}catch(Throwable $exception){return new WP_Error('design_core_figma_ir_invalid',$exception->getMessage());}
        return $ir;
    }

    private function resolve_root(array $payload,$selected_id){$root=is_array($payload['document']??null)?$payload['document']:$payload;if(''===$selected_id){return $root;}return $this->find_node($root,$selected_id);}
    private function find_node(array $node,$id){if((string)($node['id']??'')===(string)$id){return $node;}foreach((array)($node['children']??array()) as $child){if(!is_array($child)){continue;}$found=$this->find_node($child,$id);if($found){return $found;}}return null;}

    private function convert_node(array $figma,$parent_id,$depth){
        if($depth>self::MAX_DEPTH){throw new RuntimeException('Figma nesting exceeds the bounded depth limit.');}
        if(++$this->node_count>self::MAX_NODES){throw new RuntimeException('Figma selection contains too many nodes.');}
        $type=strtoupper((string)($figma['type']??'FRAME'));
        if(in_array($type,array('DOCUMENT','CANVAS'),true)&&1===count((array)($figma['children']??array()))){return $this->convert_node($figma['children'][0],$parent_id,$depth+1);}
        $id=$this->node_id((string)($figma['id']??wp_generate_uuid4()));$children=array();
        foreach((array)($figma['children']??array()) as $child){if(!is_array($child)||false===($child['visible']??true)){continue;}$child_id=$this->convert_node($child,$id,$depth+1);if($child_id){$children[]=$child_id;}}
        $semantic=$this->semantic($figma,$type);$content=$this->content($figma,$type);$layout=$this->layout($figma);$style=$this->style($figma);$spacing=$this->spacing($figma);$assets=$this->assets($figma,$type);
        $layout_governance=$this->layout_governance($figma,$layout);$tag=$this->tag($type,$semantic,$content);$schema=$this->content_schema($content,$assets,$semantic);
        $structure=strtolower($tag).'|'.implode(',',array_map(function($child_id){return(string)($this->nodes[$child_id]['source']['tag']??'node');},$children));
        $component_properties=is_array($figma['componentProperties']??null)?$figma['componentProperties']:array();
        $node=array(
            'id'=>$id,
            'source'=>array('tag'=>$tag,'classes'=>array('figma-'.sanitize_html_class(sanitize_title((string)($figma['name']??'node')))),'attributes'=>array('data-figma-id'=>sanitize_text_field((string)($figma['id']??''))),'dom_path'=>'/figma/'.$id),
            'semantic'=>array('role'=>$semantic,'component_type'=>in_array($type,array('COMPONENT','INSTANCE','COMPONENT_SET'),true)?sanitize_key($semantic):'','confidence'=>0.95),
            'content'=>$content,'layout'=>$layout,'style'=>$style,'spacing'=>$spacing,'responsive'=>array(),'assets'=>$assets,'interaction'=>$this->interaction($figma),
            'component'=>array('fingerprint'=>array('version'=>2,'semantic'=>$semantic,'structure'=>$structure,'content_schema'=>$schema,'layout'=>sanitize_text_field($layout['display']??''),'interaction'=>''),'repeated'=>false,'reusable'=>in_array($type,array('COMPONENT','INSTANCE','COMPONENT_SET'),true),'dynamic'=>false,'content_schema'=>$schema),
            'children'=>$children,
        );
        if($layout_governance){$node['layout_governance']=$layout_governance;}
        $node['figma']=array(
            'id'=>sanitize_text_field((string)($figma['id']??'')),'type'=>$type,'name'=>sanitize_text_field((string)($figma['name']??'')),'component_id'=>sanitize_text_field((string)($figma['componentId']??'')),
            'component_properties'=>Design_Core_Elementor_Change_Ledger::transport_safe($component_properties),'bound_variables'=>Design_Core_Elementor_Change_Ledger::transport_safe((array)($figma['boundVariables']??array())),'constraints'=>Design_Core_Elementor_Change_Ledger::transport_safe((array)($figma['constraints']??array())),
            'sizing'=>array('horizontal'=>sanitize_key((string)($figma['layoutSizingHorizontal']??'')),'vertical'=>sanitize_key((string)($figma['layoutSizingVertical']??''))),'geometry'=>$this->geometry($figma),'style_evidence'=>$this->style_evidence($figma),'text_runs'=>$this->text_runs($figma),
            'variable_refs'=>Design_Core_Elementor_Change_Ledger::transport_safe((array)($figma['_design_core_variable_refs']??array())),'conversion_warnings'=>array_values(array_map('sanitize_text_field',(array)($figma['_design_core_warnings']??array()))),'normalization'=>Design_Core_Elementor_Change_Ledger::transport_safe((array)($figma['_design_core_normalization']??array())),
        );
        $this->nodes[$id]=$node;return $id;
    }

    private function semantic(array $figma,$type){$name=strtolower((string)($figma['name']??''));foreach(array('hero','navigation','footer','header','faq','testimonial','pricing','comparison','process','cta','benefits','services','team','gallery','button','card') as $role){if(false!==strpos($name,$role)){return $role;}}if('TEXT'===$type){$size=(float)($figma['style']['fontSize']??0);return $size>=28?'heading':'text';}if($this->image_fill($figma)){return'media';}if(in_array($type,array('COMPONENT','INSTANCE','COMPONENT_SET'),true)){return sanitize_key($name?:'component');}return in_array($type,array('FRAME','SECTION','GROUP'),true)?'section':strtolower($type);}
    private function content(array $figma,$type){$content=array('text'=>'','rich_text'=>'','link'=>array(),'image'=>array(),'list'=>array(),'fields'=>array());if('TEXT'===$type){$content['text']=sanitize_text_field((string)($figma['characters']??''));$content['rich_text']=$content['text'];}$image=$this->image_fill($figma);if($image){$ref=sanitize_text_field((string)($image['imageRef']??''));$content['image']=array('url'=>isset($this->image_fills[$ref])?esc_url_raw((string)$this->image_fills[$ref]):'','id'=>0,'alt'=>sanitize_text_field((string)($figma['name']??'')));}return$content;}

    private function layout(array $figma){
        $layout=array();$mode=strtoupper((string)($figma['layoutMode']??''));
        if(in_array($mode,array('HORIZONTAL','VERTICAL'),true)){$layout['display']='flex';$layout['direction']='HORIZONTAL'===$mode?'row':'column';if(isset($figma['itemSpacing'])&&is_numeric($figma['itemSpacing'])){$layout['gap']=array('value'=>(float)$figma['itemSpacing'],'unit'=>'px');}if(isset($figma['counterAxisSpacing'])&&is_numeric($figma['counterAxisSpacing'])){$layout['row_gap']=array('value'=>(float)$figma['counterAxisSpacing'],'unit'=>'px');}$layout['justify_content']=$this->axis_alignment($figma['primaryAxisAlignItems']??'');$layout['align_items']=$this->axis_alignment($figma['counterAxisAlignItems']??'');if(!empty($figma['layoutWrap'])&&'WRAP'===strtoupper((string)$figma['layoutWrap'])){$layout['wrap']='wrap';}}
        $box=(array)($figma['absoluteBoundingBox']??array());$horizontal=strtoupper((string)($figma['layoutSizingHorizontal']??''));$vertical=strtoupper((string)($figma['layoutSizingVertical']??''));
        if(isset($box['width'])&&is_numeric($box['width'])&&(float)$box['width']>0){$layout['max_width']=array('value'=>(float)$box['width'],'unit'=>'px');if('FIXED'===$horizontal){$layout['width']=array('value'=>(float)$box['width'],'unit'=>'px');}}
        if(isset($box['height'])&&is_numeric($box['height'])&&(float)$box['height']>0&&'FIXED'===$vertical){$layout['height']=array('value'=>(float)$box['height'],'unit'=>'px');}
        foreach(array('minWidth'=>'min_width','maxWidth'=>'max_width','minHeight'=>'min_height','maxHeight'=>'max_height') as $figma_key=>$ir_key){if(isset($figma[$figma_key])&&is_numeric($figma[$figma_key])){$layout[$ir_key]=array('value'=>(float)$figma[$figma_key],'unit'=>'px');}}
        if(is_array($figma['_design_core_layout']??null)){$layout=array_merge($layout,$figma['_design_core_layout']);}
        return array_filter($layout,static function($value){return''!==$value&&null!==$value;});
    }

    private function layout_governance(array $figma,array $layout){$horizontal=strtoupper((string)($figma['layoutSizingHorizontal']??''));$width=$layout['width']??null;if('FIXED'!==$horizontal||!is_array($width)||'px'!==($width['unit']??'')||(float)($width['value']??0)<=320){return array();}return array('fixed_width_exception'=>true,'reason'=>'Figma layoutSizingHorizontal=FIXED is an explicit authored fixed-width frame or instance, not an inferred width.');}
    private function spacing(array $figma){$spacing=array();foreach(array('Top'=>'top','Right'=>'right','Bottom'=>'bottom','Left'=>'left') as $suffix=>$side){$key='padding'.$suffix;if(isset($figma[$key])&&is_numeric($figma[$key])){$spacing['padding_'.$side]=array('value'=>(float)$figma[$key],'unit'=>'px');}}return$spacing;}

    private function style(array $figma){
        $style=array();foreach((array)($figma['fills']??array()) as $fill){if(!is_array($fill)||false===($fill['visible']??true)||'SOLID'!==strtoupper((string)($fill['type']??''))){continue;}$style['background_color']=$this->rgba((array)($fill['color']??array()),(float)($fill['opacity']??1));break;}
        $radius=$figma['cornerRadius']??null;if(is_numeric($radius)){$style['border_radius']=array('value'=>(float)$radius,'unit'=>'px');}if(isset($figma['opacity'])&&is_numeric($figma['opacity'])){$style['opacity']=(float)$figma['opacity'];}
        $text=(array)($figma['style']??array());if($text){if(isset($text['fontFamily'])){$style['font_family']=sanitize_text_field((string)$text['fontFamily']);}if(isset($text['fontSize'])&&is_numeric($text['fontSize'])){$style['font_size']=array('value'=>(float)$text['fontSize'],'unit'=>'px');}if(isset($text['fontWeight'])&&is_numeric($text['fontWeight'])){$style['font_weight']=(int)$text['fontWeight'];}if(isset($text['lineHeightPx'])&&is_numeric($text['lineHeightPx'])){$style['line_height']=array('value'=>(float)$text['lineHeightPx'],'unit'=>'px');}if(isset($text['letterSpacing'])&&is_numeric($text['letterSpacing'])){$style['letter_spacing']=array('value'=>(float)$text['letterSpacing'],'unit'=>'px');}if(isset($text['textAlignHorizontal'])){$style['text_align']=strtolower((string)$text['textAlignHorizontal']);}}
        $shadows=array();foreach((array)($figma['effects']??array()) as $effect){if(is_array($effect)&&false!==strpos(strtoupper((string)($effect['type']??'')),'SHADOW')){$shadows[]=Design_Core_Elementor_Change_Ledger::transport_safe($effect);}}if($shadows){$style['shadows']=$shadows;}return$style;
    }

    private function assets(array $figma,$type){$image=$this->image_fill($figma);if(!$image){return array();}$ref=sanitize_text_field((string)($image['imageRef']??''));return array('images'=>array(array('source'=>'figma','figma_image_ref'=>$ref,'resolved_url'=>isset($this->image_fills[$ref])?esc_url_raw((string)$this->image_fills[$ref]):'','scale_mode'=>sanitize_key((string)($image['scaleMode']??'')),'node_id'=>sanitize_text_field((string)($figma['id']??'')))));}
    private function interaction(array $figma){$reactions=(array)($figma['reactions']??array());if(!$reactions){return array();}return array('figma_reactions'=>Design_Core_Elementor_Change_Ledger::transport_safe(array_slice($reactions,0,32)),'interactive'=>true);}
    private function style_evidence(array $figma){$corners=array();foreach(array('topLeftRadius','topRightRadius','bottomRightRadius','bottomLeftRadius') as $key){if(isset($figma[$key])&&is_numeric($figma[$key])){$corners[$key]=(float)$figma[$key];}}return Design_Core_Elementor_Change_Ledger::transport_safe(array('fills'=>(array)($figma['fills']??array()),'strokes'=>(array)($figma['strokes']??array()),'strokeWeight'=>$figma['strokeWeight']??null,'effects'=>(array)($figma['effects']??array()),'individualCornerRadii'=>$corners,'rotation'=>$figma['rotation']??0,'blendMode'=>$figma['blendMode']??''));}
    private function text_runs(array $figma){if(empty($figma['characterStyleOverrides'])||empty($figma['styleOverrideTable'])){return array();}return Design_Core_Elementor_Change_Ledger::transport_safe(array('character_style_overrides'=>$figma['characterStyleOverrides'],'style_override_table'=>$figma['styleOverrideTable']));}
    private function image_fill(array $figma){foreach((array)($figma['fills']??array()) as $fill){if(is_array($fill)&&false!==($fill['visible']??true)&&'IMAGE'===strtoupper((string)($fill['type']??''))){return$fill;}}return null;}
    private function tag($type,$semantic,array $content){if('TEXT'===$type){return'heading'===$semantic?'h2':'p';}if(!empty($content['image'])){return'img';}return in_array($type,array('FRAME','SECTION','COMPONENT','INSTANCE','COMPONENT_SET'),true)?'section':'div';}
    private function content_schema(array $content,array $assets,$semantic=''){$schema=array();if(''!==(string)($content['text']??'')){$schema[]='heading'===sanitize_key($semantic)?'heading':'rich_text';}if(!empty($assets['images'])){$schema[]='media';}return array_values(array_unique($schema));}
    private function node_id($figma_id){return'figma-'.substr(sha1((string)$figma_id),0,14);}
    private function axis_alignment($value){$map=array('MIN'=>'flex-start','MAX'=>'flex-end','CENTER'=>'center','SPACE_BETWEEN'=>'space-between','BASELINE'=>'baseline');return$map[strtoupper((string)$value)]??'';}
    private function geometry(array $figma){$box=(array)($figma['absoluteBoundingBox']??array());return array('x'=>(float)($box['x']??0),'y'=>(float)($box['y']??0),'width'=>(float)($box['width']??0),'height'=>(float)($box['height']??0));}
    private function rgba(array $color,$opacity){$r=(int)round(255*(float)($color['r']??0));$g=(int)round(255*(float)($color['g']??0));$b=(int)round(255*(float)($color['b']??0));$a=max(0,min(1,(float)($color['a']??1)*$opacity));return$a>=0.999?sprintf('#%02x%02x%02x',$r,$g,$b):sprintf('rgba(%d,%d,%d,%.3f)',$r,$g,$b,$a);}
    private function extract_tokens(array $payload){return array('figma_styles'=>Design_Core_Elementor_Change_Ledger::transport_safe(is_array($payload['styles']??null)?$payload['styles']:array()),'figma_components'=>Design_Core_Elementor_Change_Ledger::transport_safe(is_array($payload['components']??null)?$payload['components']:array()),'figma_component_sets'=>Design_Core_Elementor_Change_Ledger::transport_safe(is_array($payload['componentSets']??null)?$payload['componentSets']:array()),'figma_variables'=>Design_Core_Elementor_Change_Ledger::transport_safe(is_array($payload['variables']??null)?$payload['variables']:array()));}
}
