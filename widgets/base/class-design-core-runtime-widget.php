<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Runtime_Widget extends Design_Core_Elementor_Widget_Base {
    private $definition = array();

    /**
     * One PHP class backs every generic-runtime widget type, so the definition (content
     * schema, label) can't be a constructor parameter of its own -- Elementor always
     * reconstructs elements as `new $class( $element_data, $args )` (see
     * Elements_Manager::create_element_instance()), so the definition is looked up from
     * $data['widgetType'] instead. Registration (see Design_Core_Elementor_Plugin::
     * register_elementor_widgets() / Abstract_Strategy_Executor::ensure_runtime_widget_registered())
     * uses the exact same shape: `new self( array( 'widgetType' => $slug ) )`.
     */
    public function __construct( $data = array(), $args = null ) {
        $data = is_array( $data ) ? $data : array();
        // Registration passes the slug via $args (data must stay empty so Elementor treats this
        // as a type/prototype instance, not "a full widget instance missing its $args").
        // Reconstruction from saved page data (Elements_Manager::create_element_instance())
        // passes it back via $data['widgetType'] instead, with $args built from get_default_args().
        $widget_type = sanitize_key( $data['widgetType'] ?? ( is_array( $args ) ? ( $args['widgetType'] ?? '' ) : '' ) );
        if ( $widget_type && class_exists( 'Design_Core_Elementor_Widget_Registry' ) ) {
            $found = ( new Design_Core_Elementor_Widget_Registry() )->get( $widget_type );
            $this->definition = is_array( $found ) ? $found : array();
        }
        $this->slug = $widget_type ?: sanitize_key( $this->definition['slug'] ?? 'design-core-runtime' );
        $this->title = sanitize_text_field( $this->definition['label'] ?? 'Design Core Runtime Widget' );
        parent::__construct( $data, $args );
    }

    protected function register_controls() {
        $schema = (array) ( $this->definition['content_schema'] ?? array( 'heading', 'rich_text', 'link' ) );
        $this->start_controls_section( 'dc_content', array( 'label' => __( 'Content', 'design-core-elementor' ) ) );
        if ( in_array( 'media', $schema, true ) ) {
            $this->add_control( 'image', array( 'label'=>__( 'Image', 'design-core-elementor' ), 'type'=>\Elementor\Controls_Manager::MEDIA ) );
        }
        if ( in_array( 'heading', $schema, true ) ) {
            $this->add_control( 'title', array( 'label'=>__( 'Title', 'design-core-elementor' ), 'type'=>\Elementor\Controls_Manager::TEXT, 'default'=>$this->title ) );
        }
        if ( in_array( 'rich_text', $schema, true ) ) {
            $this->add_control( 'description', array( 'label'=>__( 'Description', 'design-core-elementor' ), 'type'=>\Elementor\Controls_Manager::WYSIWYG, 'default'=>'' ) );
        }
        if ( in_array( 'link', $schema, true ) ) {
            $this->add_control( 'button_text', array( 'label'=>__( 'Button Text', 'design-core-elementor' ), 'type'=>\Elementor\Controls_Manager::TEXT, 'default'=>__( 'Learn more', 'design-core-elementor' ) ) );
            $this->add_control( 'link', array( 'label'=>__( 'Link', 'design-core-elementor' ), 'type'=>\Elementor\Controls_Manager::URL ) );
        }
        $this->end_controls_section();

        $this->start_controls_section( 'dc_style', array( 'label'=>__( 'Style', 'design-core-elementor' ), 'tab'=>\Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_control( 'dc_background', array( 'label'=>__( 'Background', 'design-core-elementor' ), 'type'=>\Elementor\Controls_Manager::COLOR, 'selectors'=>array( '{{WRAPPER}} .design-core-runtime-widget'=>'background-color: {{VALUE}};' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name'=>'dc_typography', 'selector'=>'{{WRAPPER}} .design-core-runtime-widget' ) );
        $this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name'=>'dc_border', 'selector'=>'{{WRAPPER}} .design-core-runtime-widget' ) );
        $this->add_responsive_control( 'dc_padding', array( 'label'=>__( 'Padding', 'design-core-elementor' ), 'type'=>\Elementor\Controls_Manager::DIMENSIONS, 'size_units'=>array('px','em','rem','%'), 'selectors'=>array( '{{WRAPPER}} .design-core-runtime-widget'=>'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->add_responsive_control( 'dc_radius', array( 'label'=>__( 'Border Radius', 'design-core-elementor' ), 'type'=>\Elementor\Controls_Manager::DIMENSIONS, 'size_units'=>array('px','em','rem','%'), 'selectors'=>array( '{{WRAPPER}} .design-core-runtime-widget'=>'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        echo '<div class="design-core-runtime-widget">';
        if ( ! empty( $settings['image']['url'] ) ) { echo '<img src="' . esc_url( $settings['image']['url'] ) . '" alt="">'; }
        if ( ! empty( $settings['title'] ) ) { echo '<h3>' . esc_html( $settings['title'] ) . '</h3>'; }
        if ( ! empty( $settings['description'] ) ) { echo '<div class="design-core-runtime-description">' . wp_kses_post( $settings['description'] ) . '</div>'; }
        if ( ! empty( $settings['button_text'] ) && ! empty( $settings['link']['url'] ) ) { echo '<a class="design-core-runtime-button" href="' . esc_url( $settings['link']['url'] ) . '">' . esc_html( $settings['button_text'] ) . '</a>'; }
        echo '</div>';
    }
}
