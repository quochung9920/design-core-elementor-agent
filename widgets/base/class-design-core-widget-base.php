<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class Design_Core_Elementor_Widget_Base extends \Elementor\Widget_Base {
    protected $slug = 'design-core-widget';
    protected $title = 'Design Core Widget';

    public function get_name() { return $this->slug; }
    public function get_title() { return $this->title; }
    public function get_icon() { return 'eicon-kit'; }
    public function get_categories() { return array( 'design-core' ); }
    public function get_keywords() { return array( 'design core', 'custom widget' ); }

    protected function register_controls() {
        $this->start_controls_section( 'content', array( 'label' => esc_html__( 'Content', 'design-core-elementor' ) ) );
        $this->add_control( 'title', array( 'label' => esc_html__( 'Title', 'design-core-elementor' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => $this->title, 'dynamic' => array( 'active' => true ) ) );
        $this->add_control( 'description', array( 'label' => esc_html__( 'Description', 'design-core-elementor' ), 'type' => \Elementor\Controls_Manager::TEXTAREA, 'default' => '' ) );
        $this->end_controls_section();

        $this->start_controls_section( 'style', array( 'label' => esc_html__( 'Style', 'design-core-elementor' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_control( 'text_color', array( 'label' => esc_html__( 'Text Color', 'design-core-elementor' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .design-core-widget' => 'color: {{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'typography', 'selector' => '{{WRAPPER}} .design-core-widget' ) );
        $this->add_responsive_control( 'padding', array( 'label' => esc_html__( 'Padding', 'design-core-elementor' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem', '%' ), 'selectors' => array( '{{WRAPPER}} .design-core-widget' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'border', 'selector' => '{{WRAPPER}} .design-core-widget' ) );
        $this->add_responsive_control( 'border_radius', array( 'label' => esc_html__( 'Border Radius', 'design-core-elementor' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', '%' ), 'selectors' => array( '{{WRAPPER}} .design-core-widget' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        echo '<div class="design-core-widget">';
        if ( ! empty( $settings['title'] ) ) { echo '<h3>' . esc_html( $settings['title'] ) . '</h3>'; }
        if ( ! empty( $settings['description'] ) ) { echo '<div>' . wp_kses_post( wpautop( $settings['description'] ) ) . '</div>'; }
        echo '</div>';
    }
}
