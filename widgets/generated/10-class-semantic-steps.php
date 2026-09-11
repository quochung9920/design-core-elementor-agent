<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Design_Core_Elementor_Steps extends Design_Core_Elementor_Widget_Base {
 use Design_Core_Elementor_Semantic_Widget_Assets_Trait; protected $slug='dc-steps'; protected $title='Process Steps';
 public function get_icon(){return 'eicon-time-line';} public function get_keywords(){return array('steps','process','timeline','workflow','sequence');}
 protected function register_controls(){
  $this->start_controls_section('content',array('label'=>esc_html__('Steps','design-core-elementor')));$r=new \Elementor\Repeater();
  $r->add_control('number',array('label'=>esc_html__('Number','design-core-elementor'),'type'=>\Elementor\Controls_Manager::TEXT,'default'=>'01'));
  $r->add_control('title',array('label'=>esc_html__('Title','design-core-elementor'),'type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Collection','dynamic'=>array('active'=>true)));
  $r->add_control('description',array('label'=>esc_html__('Description','design-core-elementor'),'type'=>\Elementor\Controls_Manager::TEXTAREA,'default'=>'Describe this step.','dynamic'=>array('active'=>true)));
  $this->add_control('steps',array('label'=>esc_html__('Steps','design-core-elementor'),'type'=>\Elementor\Controls_Manager::REPEATER,'fields'=>$r->get_controls(),'title_field'=>'{{{ number }}} {{{ title }}}'));
  $this->add_control('layout',array('label'=>esc_html__('Layout','design-core-elementor'),'type'=>\Elementor\Controls_Manager::SELECT,'default'=>'vertical','options'=>array('vertical'=>'Vertical','grid'=>'Grid')));
  $this->add_responsive_control('columns',array('label'=>esc_html__('Columns','design-core-elementor'),'type'=>\Elementor\Controls_Manager::SELECT,'default'=>'2','tablet_default'=>'2','mobile_default'=>'1','options'=>array('1'=>'1','2'=>'2','3'=>'3','4'=>'4'),'condition'=>array('layout'=>'grid'),'selectors'=>array('{{WRAPPER}} .dc-steps--grid'=>'grid-template-columns:repeat({{VALUE}},minmax(0,1fr));')));$this->end_controls_section();
  $this->start_controls_section('style',array('label'=>esc_html__('Style','design-core-elementor'),'tab'=>\Elementor\Controls_Manager::TAB_STYLE));
  $this->add_control('number_color',array('label'=>esc_html__('Number Color','design-core-elementor'),'type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>array('{{WRAPPER}} .dc-step__number'=>'color:{{VALUE}};')));
  $this->add_control('title_color',array('label'=>esc_html__('Title Color','design-core-elementor'),'type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>array('{{WRAPPER}} .dc-step__title'=>'color:{{VALUE}};')));
  $this->add_group_control(\Elementor\Group_Control_Typography::get_type(),array('name'=>'title_typography','selector'=>'{{WRAPPER}} .dc-step__title'));
  $this->add_responsive_control('gap',array('label'=>esc_html__('Gap','design-core-elementor'),'type'=>\Elementor\Controls_Manager::SLIDER,'size_units'=>array('px','rem'),'selectors'=>array('{{WRAPPER}} .dc-steps'=>'gap:{{SIZE}}{{UNIT}};')));
  $this->add_responsive_control('item_padding',array('label'=>esc_html__('Item Padding','design-core-elementor'),'type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>array('px','rem'),'selectors'=>array('{{WRAPPER}} .dc-step'=>'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};')));$this->end_controls_section();
 }
 protected function render(){$s=$this->get_settings_for_display();$layout=in_array(($s['layout']??''),array('vertical','grid'),true)?$s['layout']:'vertical';echo '<div class="dc-steps dc-steps--'.esc_attr($layout).'">';foreach((array)($s['steps']??array()) as $step){echo '<article class="dc-step"><div class="dc-step__number">'.esc_html($step['number']??'').'</div><h3 class="dc-step__title">'.esc_html($step['title']??'').'</h3><div class="dc-step__description">'.wp_kses_post(wpautop((string)($step['description']??''))).'</div></article>';}echo '</div>';}
}
