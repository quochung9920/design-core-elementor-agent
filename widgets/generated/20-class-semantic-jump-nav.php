<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Design_Core_Elementor_Jump_Nav extends Design_Core_Elementor_Widget_Base {
 use Design_Core_Elementor_Semantic_Widget_Assets_Trait; protected $slug='dc-jump-nav'; protected $title='Jump Navigation';
 public function get_icon(){return 'eicon-anchor';} public function get_keywords(){return array('jump','navigation','sticky','anchor','sections');}
 public function get_script_depends(){Design_Core_Elementor_Semantic_Widget_Assets::register();return array('design-core-semantic-widgets');}
 protected function register_controls(){
  $this->start_controls_section('content',array('label'=>esc_html__('Navigation','design-core-elementor')));$r=new \Elementor\Repeater();
  $r->add_control('label',array('label'=>esc_html__('Label','design-core-elementor'),'type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Section'));
  $r->add_control('link',array('label'=>esc_html__('Link','design-core-elementor'),'type'=>\Elementor\Controls_Manager::URL,'default'=>array('url'=>'#section')));
  $this->add_control('items',array('label'=>esc_html__('Items','design-core-elementor'),'type'=>\Elementor\Controls_Manager::REPEATER,'fields'=>$r->get_controls(),'title_field'=>'{{{ label }}}'));
  $this->add_control('active_tracking',array('label'=>esc_html__('Track Active Section','design-core-elementor'),'type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes'));
  $this->add_control('sticky',array('label'=>esc_html__('Sticky','design-core-elementor'),'type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes'));
  $this->add_responsive_control('sticky_offset',array('label'=>esc_html__('Sticky Offset','design-core-elementor'),'type'=>\Elementor\Controls_Manager::SLIDER,'size_units'=>array('px'),'selectors'=>array('{{WRAPPER}} .dc-jump-nav[data-sticky="yes"]'=>'top:{{SIZE}}{{UNIT}};')));$this->end_controls_section();
  $this->start_controls_section('style',array('label'=>esc_html__('Style','design-core-elementor'),'tab'=>\Elementor\Controls_Manager::TAB_STYLE));
  $this->add_control('background_color',array('label'=>esc_html__('Background','design-core-elementor'),'type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>array('{{WRAPPER}} .dc-jump-nav'=>'background:{{VALUE}};')));
  $this->add_control('text_color',array('label'=>esc_html__('Text','design-core-elementor'),'type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>array('{{WRAPPER}} .dc-jump-nav__link'=>'color:{{VALUE}};')));
  $this->add_control('active_color',array('label'=>esc_html__('Active','design-core-elementor'),'type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>array('{{WRAPPER}} .dc-jump-nav__link[aria-current="true"]'=>'color:{{VALUE}};')));
  $this->add_group_control(\Elementor\Group_Control_Typography::get_type(),array('name'=>'typography','selector'=>'{{WRAPPER}} .dc-jump-nav__link'));
  $this->add_responsive_control('gap',array('label'=>esc_html__('Gap','design-core-elementor'),'type'=>\Elementor\Controls_Manager::SLIDER,'size_units'=>array('px','rem'),'selectors'=>array('{{WRAPPER}} .dc-jump-nav__track'=>'gap:{{SIZE}}{{UNIT}};')));$this->end_controls_section();
 }
 protected function render(){$s=$this->get_settings_for_display();$sticky=(string)($s['sticky']??'');$sticky_enabled=in_array($sticky,array('yes','top','bottom'),true);echo '<nav class="dc-jump-nav" data-dc-jump-nav data-track="'.esc_attr(($s['active_tracking']??'')==='yes'?'yes':'no').'" data-sticky="'.esc_attr($sticky_enabled?'yes':'no').'" aria-label="On this page"><div class="dc-jump-nav__track" tabindex="0">';foreach((array)($s['items']??array()) as $item){$label=sanitize_text_field($item['label']??'');$url=esc_url((string)($item['link']['url']??''));if($label&&$url){echo '<a class="dc-jump-nav__link" href="'.$url.'">'.esc_html($label).'</a>';}}echo '</div></nav>';}
}
