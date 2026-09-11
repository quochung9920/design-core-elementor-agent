<?php
/** WordPress integration regression for canonical BuildPlan execution. */
class Design_Core_Elementor_Build_Plan_Executor_Test extends WP_UnitTestCase {
    public function test_native_compose_consumes_ir_and_produces_elements() {
        $analysis = ( new Design_Core_Elementor_Analysis_Engine() )->analyze_html( '<section><article class="feature-card"><h3>A</h3><p>One</p></article></section>', '.feature-card{padding:24px;}' );
        $ir = ( new Design_Core_Elementor_Normalization_Pipeline() )->normalize( $analysis['design_ir'] );
        $plan = ( new Design_Core_Elementor_Build_Planner() )->plan( $ir, 'elementor-v3' );
        $result = ( new Design_Core_Elementor_Build_Plan_Executor() )->execute( $plan, array( 'ir' => $ir, 'adapter' => new Design_Core_Elementor_V3_Adapter() ) );
        $this->assertSame( 'success', $result['status'] );
        $this->assertNotEmpty( $result['elements'] );
        $this->assertNotEmpty( $result['created_artifacts'] );
        $this->assertSame( 'container', $result['elements'][0]['elType'] );
    }
}
