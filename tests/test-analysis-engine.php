<?php
/**
 * Regression tests for the analysis contract.
 * Requires the WordPress PHPUnit test suite.
 */

class Design_Core_Elementor_Analysis_Engine_Test extends WP_UnitTestCase {
    public function test_empty_css_has_zero_tokens() {
        $engine = new Design_Core_Elementor_Analysis_Engine();
        $result = $engine->analyze_html( '<section><h2>Hello</h2></section>', '' );
        $this->assertSame( 0, $result['summary']['token_count'] );
        $this->assertSame( 4, $result['design_ir']['schema_version'] );
        $this->assertNotEmpty( $result['design_ir']['nodes'] );
        $this->assertArrayNotHasKey( 'source_html', $result['design_ir']['nodes'][0] );
    }

    public function test_repeated_components_expose_converter_contract() {
        $engine = new Design_Core_Elementor_Analysis_Engine();
        $result = $engine->analyze_html(
            '<section class="features"><article class="card"><h3>A</h3><p>X</p></article><article class="card"><h3>B</h3><p>Y</p></article></section>',
            '.card{padding:24px;color:#173F35;}'
        );

        $this->assertNotEmpty( $result['components'] );
        $component = $result['components'][0];
        $this->assertArrayHasKey( 'signature', $component );
        $this->assertArrayHasKey( 'type', $component );
        $this->assertArrayHasKey( 'html', $component );
        $this->assertArrayHasKey( 'tokens', $component );
        $this->assertArrayHasKey( 'instance_count', $component );
        $this->assertTrue( $component['repeated'] );
    }
}
