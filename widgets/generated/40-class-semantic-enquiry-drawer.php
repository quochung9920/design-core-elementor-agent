<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Design_Core_Elementor_Enquiry_Drawer extends Design_Core_Elementor_Widget_Base {
    use Design_Core_Elementor_Semantic_Widget_Assets_Trait;
    protected $slug='dc-enquiry-drawer'; protected $title='Enquiry Drawer';
    public function get_icon(){return 'eicon-form-horizontal';}
    public function get_keywords(){return array('enquiry','drawer','launcher','prefill','dialog');}
    public function get_script_depends(){Design_Core_Elementor_Semantic_Widget_Assets::register();return array('design-core-semantic-widgets');}
    protected function register_controls(){
        $this->start_controls_section('launcher',array('label'=>esc_html__('Launcher','design-core-elementor')));
        $this->add_control('launcher_label',array('label'=>esc_html__('Label','design-core-elementor'),'type'=>\Elementor\Controls_Manager::TEXT,'default'=>'What are you importing?'));
        $this->add_control('launcher_placeholder',array('label'=>esc_html__('Placeholder','design-core-elementor'),'type'=>\Elementor\Controls_Manager::TEXT,'default'=>'e.g. machine parts'));
        $this->add_control('launcher_button',array('label'=>esc_html__('Button','design-core-elementor'),'type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Start enquiry'));
        $this->end_controls_section();
        $this->start_controls_section('drawer',array('label'=>esc_html__('Drawer','design-core-elementor')));
        $this->add_control('drawer_title',array('label'=>esc_html__('Title','design-core-elementor'),'type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Talk to our team'));
        $r=new \Elementor\Repeater();
        foreach(array('field_id'=>'Field ID','label'=>'Label','placeholder'=>'Placeholder') as $id=>$label){$r->add_control($id,array('label'=>esc_html__($label,'design-core-elementor'),'type'=>\Elementor\Controls_Manager::TEXT));}
        $r->add_control('field_type',array('label'=>esc_html__('Type','design-core-elementor'),'type'=>\Elementor\Controls_Manager::SELECT,'default'=>'text','options'=>array('text'=>'Text','email'=>'Email','tel'=>'Phone','textarea'=>'Textarea')));
        $r->add_control('required',array('label'=>esc_html__('Required','design-core-elementor'),'type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes'));
        $this->add_control('drawer_fields',array('label'=>esc_html__('Fields','design-core-elementor'),'type'=>\Elementor\Controls_Manager::REPEATER,'fields'=>$r->get_controls(),'title_field'=>'{{{ label }}}','default'=>array(array('field_id'=>'name','label'=>'Name','field_type'=>'text','required'=>'yes'),array('field_id'=>'email','label'=>'Email','field_type'=>'email','required'=>'yes'),array('field_id'=>'importing','label'=>'What are you importing?','field_type'=>'textarea','required'=>'yes'))));
        $this->add_control('submit_button',array('label'=>esc_html__('Submit Button','design-core-elementor'),'type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Talk to our team'));
        $this->add_control('prototype_mode',array('label'=>esc_html__('Prototype Mode','design-core-elementor'),'type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes'));
        $this->end_controls_section();
        $this->start_controls_section('style',array('label'=>esc_html__('Style','design-core-elementor'),'tab'=>\Elementor\Controls_Manager::TAB_STYLE));
        $this->add_control('surface_color',array('label'=>esc_html__('Surface','design-core-elementor'),'type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>array('{{WRAPPER}} .dc-enquiry-panel'=>'background:{{VALUE}};')));
        $this->add_control('text_color',array('label'=>esc_html__('Text','design-core-elementor'),'type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>array('{{WRAPPER}} .dc-enquiry-drawer'=>'color:{{VALUE}};')));
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(),array('name'=>'typography','selector'=>'{{WRAPPER}} .dc-enquiry-drawer'));
        $this->add_responsive_control('panel_width',array('label'=>esc_html__('Panel Width','design-core-elementor'),'type'=>\Elementor\Controls_Manager::SLIDER,'size_units'=>array('px','vw'),'range'=>array('px'=>array('min'=>280,'max'=>900)),'selectors'=>array('{{WRAPPER}} .dc-enquiry-panel'=>'width:{{SIZE}}{{UNIT}};')));
        $this->end_controls_section();
    }
    protected function render(){
        $s=$this->get_settings_for_display();$uid='dc-enquiry-'.esc_attr($this->get_id());
        echo '<div id="'.$uid.'" class="dc-enquiry-drawer" data-dc-enquiry data-prototype="'.esc_attr(($s['prototype_mode']??'')==='yes'?'yes':'no').'">';
        echo '<form class="dc-enquiry-launcher" data-dc-enquiry-launcher><label><span>'.esc_html($s['launcher_label']??'').'</span><input type="text" data-dc-enquiry-seed placeholder="'.esc_attr($s['launcher_placeholder']??'').'"></label><button type="submit">'.esc_html($s['launcher_button']??'').'</button></form>';
        echo '<div class="dc-enquiry-overlay" data-dc-enquiry-overlay hidden><button type="button" class="dc-enquiry-scrim" data-dc-enquiry-close aria-label="'.esc_attr__('Close','design-core-elementor').'"></button><section class="dc-enquiry-panel" role="dialog" aria-modal="true" aria-labelledby="'.$uid.'-title"><button type="button" data-dc-enquiry-close class="dc-enquiry-close" aria-label="'.esc_attr__('Close','design-core-elementor').'">×</button><h2 id="'.$uid.'-title">'.esc_html($s['drawer_title']??'').'</h2><form data-dc-enquiry-form>';
        foreach((array)($s['drawer_fields']??array()) as $f){$id=sanitize_key($f['field_id']??'field');$type=in_array(($f['field_type']??'text'),array('text','email','tel','textarea'),true)?$f['field_type']:'text';$req=!empty($f['required'])?' required':'';echo '<label><span>'.esc_html($f['label']??$id).'</span>';if('textarea'===$type){echo '<textarea name="'.esc_attr($id).'" placeholder="'.esc_attr($f['placeholder']??'').'"'.$req.'></textarea>';}else{echo '<input type="'.esc_attr($type).'" name="'.esc_attr($id).'" placeholder="'.esc_attr($f['placeholder']??'').'"'.$req.'>';}echo '</label>';}
        echo '<button type="submit">'.esc_html($s['submit_button']??'Submit').'</button></form></section></div></div>';
    }
}
