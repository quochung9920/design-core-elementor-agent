<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Design_Core_Elementor_Responsive_Table extends Design_Core_Elementor_Widget_Base {
 use Design_Core_Elementor_Semantic_Widget_Assets_Trait; protected $slug='dc-responsive-table'; protected $title='Responsive Data Table';
 public function get_icon(){return 'eicon-table';} public function get_keywords(){return array('table','data','comparison','matrix','responsive');}
 protected function register_controls(){
  $this->start_controls_section('content',array('label'=>esc_html__('Table','design-core-elementor')));
   $this->add_control('column_count',array('label'=>esc_html__('Columns','design-core-elementor'),'type'=>\Elementor\Controls_Manager::SELECT,'default'=>'3','options'=>array('2'=>'2','3'=>'3','4'=>'4','5'=>'5','6'=>'6')));
   for($i=1;$i<=6;$i++){$args=array('label'=>sprintf(esc_html__('Column %d Label','design-core-elementor'),$i),'type'=>\Elementor\Controls_Manager::TEXT,'default'=>$i===1?'Item':'Column '.$i);if($i===3){$args['condition']=array('column_count'=>array('3','4','5','6'));}elseif($i===4){$args['condition']=array('column_count'=>array('4','5','6'));}elseif($i===5){$args['condition']=array('column_count'=>array('5','6'));}elseif($i===6){$args['condition']=array('column_count'=>'6');}$this->add_control('column_'.$i.'_label',$args);}
   $r=new \Elementor\Repeater();for($i=1;$i<=6;$i++){$r->add_control('cell_'.$i,array('label'=>sprintf(esc_html__('Cell %d','design-core-elementor'),$i),'type'=>\Elementor\Controls_Manager::TEXTAREA,'dynamic'=>array('active'=>true)));}$r->add_control('emphasis',array('label'=>esc_html__('Emphasize','design-core-elementor'),'type'=>\Elementor\Controls_Manager::SWITCHER));
  $this->add_control('rows',array('label'=>esc_html__('Rows','design-core-elementor'),'type'=>\Elementor\Controls_Manager::REPEATER,'fields'=>$r->get_controls(),'title_field'=>'{{{ cell_1 }}}'));$this->end_controls_section();
  $this->start_controls_section('style',array('label'=>esc_html__('Style','design-core-elementor'),'tab'=>\Elementor\Controls_Manager::TAB_STYLE));
  $this->add_control('header_background',array('label'=>esc_html__('Header Background','design-core-elementor'),'type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>array('{{WRAPPER}} .dc-data-table thead'=>'background:{{VALUE}};')));
  $this->add_control('header_color',array('label'=>esc_html__('Header Color','design-core-elementor'),'type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>array('{{WRAPPER}} .dc-data-table th'=>'color:{{VALUE}};')));
  $this->add_group_control(\Elementor\Group_Control_Typography::get_type(),array('name'=>'header_typography','selector'=>'{{WRAPPER}} .dc-data-table th'));
  $this->add_group_control(\Elementor\Group_Control_Typography::get_type(),array('name'=>'body_typography','selector'=>'{{WRAPPER}} .dc-data-table td'));
  $this->add_responsive_control('cell_padding',array('label'=>esc_html__('Cell Padding','design-core-elementor'),'type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>array('px','rem'),'selectors'=>array('{{WRAPPER}} .dc-data-table th,{{WRAPPER}} .dc-data-table td'=>'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};')));$this->end_controls_section();
 }
   protected function render(){$s=$this->get_settings_for_display();$count=max(2,min(6,(int)($s['column_count']??3)));$labels=array();for($i=1;$i<=$count;$i++){$labels[$i]=sanitize_text_field($s['column_'.$i.'_label']??'');}echo '<div class="dc-data-table-wrap"><table class="dc-data-table"><thead><tr>';for($i=1;$i<=$count;$i++){echo '<th scope="col">'.esc_html($labels[$i]).'</th>';}echo '</tr></thead><tbody>';foreach((array)($s['rows']??array()) as $row){echo '<tr'.(!empty($row['emphasis'])?' class="dc-data-table__row--emphasis"':'').'>';for($i=1;$i<=$count;$i++){echo '<td data-label="'.esc_attr($labels[$i]).'">'.wp_kses_post(wpautop((string)($row['cell_'.$i]??''))).'</td>';}echo '</tr>';}echo '</tbody></table></div>';}
}
