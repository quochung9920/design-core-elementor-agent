<?php
/**
 * Regression tests for native Elementor mapping.
 * Requires the WordPress PHPUnit test suite.
 */

class Design_Core_Elementor_Mapping_Engine_Test extends WP_UnitTestCase {
    public function test_maps_common_html_to_native_widgets() {
        $engine = new Design_Core_Elementor_Mapping_Engine();
        $elements = $engine->map_html(
            '<section class="hero"><h1>Title</h1><p>Copy</p><a href="/shop">Shop</a><img src="https://example.com/a.jpg" alt="A"></section>',
            '.hero{display:flex;gap:24px;background:#ffffff;}'
        );

        $this->assertNotEmpty( $elements );
        $this->assertSame( 'container', $elements[0]['elType'] );

        $widget_types = array();
        foreach ( $elements[0]['elements'] as $element ) {
            if ( isset( $element['widgetType'] ) ) {
                $widget_types[] = $element['widgetType'];
            }
        }

        $this->assertContains( 'heading', $widget_types );
        $this->assertContains( 'text-editor', $widget_types );
        $this->assertContains( 'button', $widget_types );
        $this->assertContains( 'image', $widget_types );
        $this->assertNotContains( 'html', $widget_types );
    }
}
