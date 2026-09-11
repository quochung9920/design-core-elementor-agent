<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Editable multi-timezone announcement bar for global headers. */
class Design_Core_Elementor_Global_Time_Bar extends Design_Core_Elementor_Widget_Base {
    protected $slug = 'dc-global-time-bar';
    protected $title = 'Global Time Bar';

    public function get_icon() { return 'eicon-clock-o'; }
    public function get_keywords() { return array( 'time', 'timezone', 'announcement', 'header' ); }
    public function get_script_depends() { return array( 'design-core-global-time-bar' ); }
    public function get_style_depends() { return array( 'design-core-global-time-bar' ); }

    protected function register_controls() {
        $this->start_controls_section( 'content', array( 'label' => esc_html__( 'Locations', 'design-core-elementor' ) ) );
        $this->add_control( 'title', array( 'label' => esc_html__( 'Title', 'design-core-elementor' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Current time in:', 'dynamic' => array( 'active' => true ) ) );
        $repeater = new \Elementor\Repeater();
        $repeater->add_control( 'label', array( 'label' => esc_html__( 'Location', 'design-core-elementor' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Australia' ) );
        $repeater->add_control( 'timezone', array( 'label' => esc_html__( 'IANA timezone', 'design-core-elementor' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Australia/Sydney' ) );
        $this->add_control( 'locations', array(
            'label' => esc_html__( 'Locations', 'design-core-elementor' ), 'type' => \Elementor\Controls_Manager::REPEATER,
            'fields' => $repeater->get_controls(), 'title_field' => '{{{ label }}}',
            'default' => array(
                array( 'label' => 'Australia', 'timezone' => 'Australia/Sydney' ),
                array( 'label' => 'China', 'timezone' => 'Asia/Shanghai' ),
            ),
        ) );
        $this->end_controls_section();

        $this->start_controls_section( 'style', array( 'label' => esc_html__( 'Style', 'design-core-elementor' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_control( 'title_color', array( 'label' => esc_html__( 'Title color', 'design-core-elementor' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#fbc925', 'selectors' => array( '{{WRAPPER}} .dc-time-bar__title' => 'color: {{VALUE}};' ) ) );
        $this->add_control( 'location_color', array( 'label' => esc_html__( 'Location color', 'design-core-elementor' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#ffffff', 'selectors' => array( '{{WRAPPER}} .dc-time-bar__label' => 'color: {{VALUE}};' ) ) );
        $this->add_control( 'time_color', array( 'label' => esc_html__( 'Time color', 'design-core-elementor' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#b9bcc5', 'selectors' => array( '{{WRAPPER}} .dc-time-bar__value' => 'color: {{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'typography', 'selector' => '{{WRAPPER}} .dc-time-bar' ) );
        $this->add_responsive_control( 'gap', array( 'label' => esc_html__( 'Gap', 'design-core-elementor' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'default' => array( 'size' => 24, 'unit' => 'px' ), 'selectors' => array( '{{WRAPPER}} .dc-time-bar' => 'gap: {{SIZE}}{{UNIT}};' ) ) );
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        echo '<div class="dc-time-bar" aria-label="' . esc_attr__( 'Current time by location', 'design-core-elementor' ) . '">';
        echo '<span class="dc-time-bar__title">' . esc_html( $settings['title'] ?? '' ) . '</span>';
        foreach ( (array) ( $settings['locations'] ?? array() ) as $location ) {
            $label = sanitize_text_field( $location['label'] ?? '' );
            $timezone = sanitize_text_field( $location['timezone'] ?? '' );
            if ( '' === $label || '' === $timezone ) { continue; }
            echo '<span class="dc-time-bar__location" data-timezone="' . esc_attr( $timezone ) . '"><i aria-hidden="true"></i><strong class="dc-time-bar__label">' . esc_html( $label ) . '</strong><span class="dc-time-bar__value">--:--</span></span>';
        }
        echo '</div>';
    }
}
