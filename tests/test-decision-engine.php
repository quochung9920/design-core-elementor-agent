<?php
class Design_Core_Elementor_Decision_Engine_Test extends WP_UnitTestCase {
    public function test_static_repeated_cards_are_components_not_loops() {
        $engine = new Design_Core_Elementor_Decision_Engine();
        $result = $engine->decide_component( array( 'type'=>'feature-card','instance_count'=>4,'html'=>'<article class="feature-card"><h3>A</h3></article>' ), array( 'elementor'=>array( 'loop_available'=>true ) ) );
        $this->assertSame( 'component', $result['strategy'] );
    }
    public function test_dynamic_repeated_products_can_use_loop() {
        $engine = new Design_Core_Elementor_Decision_Engine();
        $result = $engine->decide_component( array( 'type'=>'product','instance_count'=>4,'html'=>'<article class="product" data-product-id="12"></article>' ), array( 'elementor'=>array( 'loop_available'=>true ) ) );
        $this->assertSame( 'loop', $result['strategy'] );
    }
}
