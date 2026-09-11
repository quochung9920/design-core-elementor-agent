<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Demo_Pricing_Calculator_Widget extends Design_Core_Elementor_Widget_Base {
    protected $slug = 'design-core-demo-pricing-calculator';
    protected $title = 'Design Core Pricing Calculator';

    protected function register_controls() {
        $this->start_controls_section( 'content_section', array( 'label'=>esc_html__( 'Content', 'design-core-elementor' ) ) );
        $repeater = new \Elementor\Repeater();
        $repeater->add_control( 'name', array( 'label'=>'Plan name','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Starter' ) );
        $repeater->add_control( 'price', array( 'label'=>'Price per user','type'=>\Elementor\Controls_Manager::NUMBER,'default'=>10,'min'=>0,'step'=>0.01 ) );
        $this->add_control( 'plans', array( 'label'=>'Plans','type'=>\Elementor\Controls_Manager::REPEATER,'fields'=>$repeater->get_controls(),'default'=>array( array('name'=>'Starter','price'=>10), array('name'=>'Pro','price'=>20) ),'title_field'=>'{{{ name }}}' ) );
        $this->add_control( 'selected_plan', array( 'label'=>'Selected plan index','type'=>\Elementor\Controls_Manager::NUMBER,'default'=>0,'min'=>0 ) );
        $this->add_control( 'users', array( 'label'=>'Users','type'=>\Elementor\Controls_Manager::SLIDER,'size_units'=>array('custom'),'range'=>array('custom'=>array('min'=>1,'max'=>500,'step'=>1)),'default'=>array('size'=>1,'unit'=>'custom') ) );
        $this->add_control( 'currency', array( 'label'=>'Currency','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'$' ) );
        $this->add_control( 'cta_text', array( 'label'=>'CTA text','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Get started' ) );
        $this->add_control( 'cta_url', array( 'label'=>'CTA link','type'=>\Elementor\Controls_Manager::URL,'default'=>array('url'=>'#') ) );
        $this->end_controls_section();

        $this->start_controls_section( 'style_card', array( 'label'=>'Card','tab'=>\Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_group_control( \Elementor\Group_Control_Background::get_type(), array( 'name'=>'background','types'=>array('classic','gradient'),'selector'=>'{{WRAPPER}} .design-core-pricing-calculator' ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name'=>'border','selector'=>'{{WRAPPER}} .design-core-pricing-calculator' ) );
        $this->add_responsive_control( 'card_radius', array( 'label'=>'Border radius','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>array('px','%'),'selectors'=>array('{{WRAPPER}} .design-core-pricing-calculator'=>'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};') ) );
        $this->add_responsive_control( 'card_padding', array( 'label'=>'Padding','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>array('px','em','rem'),'selectors'=>array('{{WRAPPER}} .design-core-pricing-calculator'=>'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};') ) );
        $this->add_responsive_control( 'card_gap', array( 'label'=>'Gap','type'=>\Elementor\Controls_Manager::SLIDER,'size_units'=>array('px','rem'),'selectors'=>array('{{WRAPPER}} .design-core-pricing-calculator'=>'gap: {{SIZE}}{{UNIT}};') ) );
        $this->end_controls_section();

        $this->start_controls_section( 'style_text', array( 'label'=>'Typography','tab'=>\Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_control( 'title_color', array( 'label'=>'Title color','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>array('{{WRAPPER}} .dc-plan-title'=>'color: {{VALUE}};') ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name'=>'title_typography','selector'=>'{{WRAPPER}} .dc-plan-title' ) );
        $this->add_control( 'price_color', array( 'label'=>'Price color','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>array('{{WRAPPER}} .dc-price'=>'color: {{VALUE}};') ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name'=>'price_typography','selector'=>'{{WRAPPER}} .dc-price' ) );
        $this->end_controls_section();
    }

    protected function render() {
        $s = $this->get_settings_for_display(); $plans = is_array( $s['plans'] ?? null ) ? $s['plans'] : array();
        $index = max( 0, (int) ( $s['selected_plan'] ?? 0 ) ); $plan = $plans[ $index ] ?? ( $plans[0] ?? array( 'name'=>'Starter','price'=>0 ) );
        $users = max( 1, (int) ( $s['users']['size'] ?? 1 ) ); $price = (float) ( $plan['price'] ?? 0 ) * $users;
        echo '<div class="design-core-pricing-calculator" style="display:flex;flex-direction:column">';
        echo '<h3 class="dc-plan-title">' . esc_html( $plan['name'] ?? 'Plan' ) . '</h3>';
        echo '<div class="dc-users">' . esc_html( sprintf( _n( '%d user', '%d users', $users, 'design-core-elementor' ), $users ) ) . '</div>';
        echo '<div class="dc-price">' . esc_html( $s['currency'] ?? '$' ) . esc_html( number_format_i18n( $price, 2 ) ) . '</div>';
        if ( ! empty( $s['cta_text'] ) ) { echo '<a class="elementor-button" href="' . esc_url( $s['cta_url']['url'] ?? '#' ) . '">' . esc_html( $s['cta_text'] ) . '</a>'; }
        echo '</div>';
    }
}
