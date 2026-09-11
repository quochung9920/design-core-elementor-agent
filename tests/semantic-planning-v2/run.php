<?php
require dirname(__DIR__).'/bootstrap-standalone.php';
dc_require(array('core/control-schema-mapper.php','core/control-schema-registry.php','core/widget-intelligence.php','core/semantic-tokenizer.php','core/component-ontology.php','core/widget-fit-engine.php','core/semantic-widget-intelligence-v2.php','core/component-planner.php'));
class DC_Semantic_Test_Widget{private $name,$title,$keywords;public function __construct($n,$t,$k=array()){$this->name=$n;$this->title=$t;$this->keywords=$k;}public function get_name(){return $this->name;}public function get_title(){return $this->title;}public function get_categories(){return array('test');}public function get_keywords(){return $this->keywords;}}
$controls=array(
'heading'=>array('title'=>array('name'=>'title','type'=>'text'),'_transform_rotateZ_effect'=>array('name'=>'_transform_rotateZ_effect','type'=>'slider')),
'price-list'=>array('items'=>array('name'=>'items','type'=>'repeater','fields'=>array('price'=>array('name'=>'price','type'=>'text')))),
'mega-menu'=>array('menu_items'=>array('name'=>'menu_items','type'=>'repeater','fields'=>array('title'=>array('name'=>'title','type'=>'text'))),'breakpoint'=>array('name'=>'breakpoint','type'=>'select','responsive'=>true)),
'accordion'=>array('tabs'=>array('name'=>'tabs','type'=>'repeater','fields'=>array('tab_title'=>array('name'=>'tab_title','type'=>'text'),'tab_content'=>array('name'=>'tab_content','type'=>'wysiwyg'))),'title_spacing'=>array('name'=>'title_spacing','type'=>'slider','responsive'=>true)),
'icon-box'=>array('title_text'=>array('name'=>'title_text','type'=>'text'),'description_text'=>array('name'=>'description_text','type'=>'textarea'),'selected_icon'=>array('name'=>'selected_icon','type'=>'icons'),'padding'=>array('name'=>'padding','type'=>'dimensions','responsive'=>true)),
'dc-steps'=>array('steps'=>array('name'=>'steps','type'=>'repeater','fields'=>array('title'=>array('name'=>'title','type'=>'text'),'description'=>array('name'=>'description','type'=>'textarea'))),'gap'=>array('name'=>'gap','type'=>'slider','responsive'=>true)),
'dc-enquiry-drawer'=>array('launcher_placeholder'=>array('name'=>'launcher_placeholder','type'=>'text'),'drawer_fields'=>array('name'=>'drawer_fields','type'=>'repeater','fields'=>array('field_id'=>array('name'=>'field_id','type'=>'text'))),'panel_width'=>array('name'=>'panel_width','type'=>'slider','responsive'=>true)));
$registry=new Design_Core_Elementor_Control_Schema_Registry(function($type,$widget)use($controls){return array('controls'=>$controls[$widget]??array());});
$widgets=array('heading'=>new DC_Semantic_Test_Widget('heading','Heading',array('title','text')),'price-list'=>new DC_Semantic_Test_Widget('price-list','Price List',array('price','commerce')),'mega-menu'=>new DC_Semantic_Test_Widget('mega-menu','Mega Menu',array('menu','navigation')),'accordion'=>new DC_Semantic_Test_Widget('accordion','Accordion',array('faq','disclosure')),'icon-box'=>new DC_Semantic_Test_Widget('icon-box','Icon Box',array('feature','icon','content')),'dc-steps'=>new DC_Semantic_Test_Widget('dc-steps','Process Steps',array('process','steps','sequence')),'dc-enquiry-drawer'=>new DC_Semantic_Test_Widget('dc-enquiry-drawer','Enquiry Drawer',array('enquiry','drawer','prefill')));
$intel=new Design_Core_Elementor_Semantic_Widget_Intelligence_V2($registry,function()use($widgets){return $widgets;});$catalog=$intel->catalog();
dc_assert(($catalog['widgets']['heading']['semantic']['primary_intent']??'')==='content','Heading is content, never form because transform control exists.');
dc_assert(!in_array('form',(array)($catalog['widgets']['heading']['semantic']['intents']??array()),true),'transform must not create form intent.');
dc_assert(($catalog['widgets']['price-list']['semantic']['primary_intent']??'')==='commerce','Price List is commerce.');
dc_assert(($catalog['widgets']['mega-menu']['semantic']['primary_intent']??'')==='navigation','Mega Menu is navigation.');
$planner=new Design_Core_Elementor_Component_Planner($intel,new Design_Core_Elementor_Widget_Fit_Engine($registry));
$nav=$planner->plan(array('component_type'=>'navigation','behaviors'=>array('navigate','menu-toggle'),'limit'=>10));dc_assert(($nav['recommendation']['widget']??'')==='mega-menu','Mega Menu should win navigation contract.');
$rejected=false;foreach((array)($nav['rejected_examples']??array()) as $r){if(($r['name']??'')==='price-list')$rejected=true;}dc_assert($rejected,'Price List must be rejected for navigation.');
$process=$planner->plan(array('component_type'=>'process-steps','behaviors'=>array('ordered-sequence'),'repeated'=>true));dc_assert(($process['recommendation']['widget']??'')==='dc-steps','Process selects dc-steps.');
$faq=$planner->plan(array('component_type'=>'faq','behaviors'=>array('disclosure'),'repeated'=>true));dc_assert(($faq['recommendation']['widget']??'')==='accordion','FAQ selects Accordion.');
$feature=$planner->plan(array('component_type'=>'feature-card'));dc_assert(($feature['recommendation']['widget']??'')==='icon-box','Feature card prefers Icon Box.');
$enquiry=$planner->plan(array('component_type'=>'enquiry-launcher','behaviors'=>array('open-drawer','prefill','focus-trap','escape-close')));dc_assert(($enquiry['recommendation']['widget']??'')==='dc-enquiry-drawer','Enquiry preserves drawer behavior.');
$unsupported=$planner->plan(array('component_type'=>'enquiry-launcher','behaviors'=>array('open-drawer','prefill','focus-trap','escape-close','swipe-down')));dc_assert(($unsupported['recommendation']['type']??'')==='blocked','Unknown required drawer interaction must block, not silently pass custom widget.');
dc_assert(strlen((string)($nav['decision_hash']??''))===64,'Planner emits decision hash.');
dc_assert(in_array('hard_requirements_before-score',(array)($nav['decision_rules']??array()),true),'Hard requirements run before scoring.');
dc_finish('semantic-planning-v2');
