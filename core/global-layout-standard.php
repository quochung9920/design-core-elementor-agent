<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Owns native Elementor Global Content Width plus responsive content gutters. */
class Design_Core_Elementor_Global_Layout_Standard {
    const CSS_START = '/* DESIGN CORE GLOBAL LAYOUT START */';
    const CSS_END = '/* DESIGN CORE GLOBAL LAYOUT END */';

    public function devices() {
        return array(
            'desktop' => array(
                'container_width' => 1440,
                'inline_padding' => 'clamp(32px, 4vw, 64px)',
            ),
            'laptop' => array(
                'container_width' => 1366,
                'inline_padding' => 'clamp(28px, 3.5vw, 48px)',
            ),
            'tablet' => array(
                'container_width' => 1024,
                'inline_padding' => 'clamp(20px, 3vw, 32px)',
            ),
            'mobile' => array(
                'container_width' => 767,
                'inline_padding' => 'clamp(16px, 4vw, 24px)',
            ),
        );
    }

    public function class_name() { return 'dc-global-container'; }

    /** Elementor Kit exposes only a non-responsive numeric width control. */
    public function kit_settings() {
        return array(
            'container_width' => array( 'size' => 1440, 'unit' => 'px' ),
        );
    }

    /** Clamp values require the governed Kit Custom CSS fallback. */
    public function managed_css() {
        $devices = $this->devices();
        $class = $this->class_name();
        $css = self::CSS_START . "\n";
        $css .= ":root {\n";
        $css .= '  --dc-container-inline-padding: ' . $devices['desktop']['inline_padding'] . ";\n";
        $css .= "}\n";
        $css .= '.' . $class . " {\n";
        $css .= "  box-sizing: border-box;\n";
        $css .= "  padding-inline: var(--dc-container-inline-padding);\n";
        $css .= "}\n";
        foreach ( array( 'laptop', 'tablet', 'mobile' ) as $device ) {
            $definition = $devices[ $device ];
            $css .= '@media (max-width: ' . $definition['container_width'] . "px) {\n";
            $css .= "  :root {\n";
            $css .= '    --dc-container-inline-padding: ' . $definition['inline_padding'] . ";\n";
            $css .= "  }\n";
            $css .= "}\n";
        }
        return $css . self::CSS_END;
    }

    public function merge_managed_css( $existing_css ) {
        $existing_css = (string) $existing_css;
        $pattern = '/' . preg_quote( self::CSS_START, '/' ) . '.*?' . preg_quote( self::CSS_END, '/' ) . '/s';
        $clean = trim( preg_replace( $pattern, '', $existing_css ) );
        return ( $clean ? $clean . "\n\n" : '' ) . $this->managed_css();
    }
}
