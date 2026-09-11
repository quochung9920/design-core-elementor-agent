<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

$file = DESIGN_CORE_ELEMENTOR_PATH . 'core/global-layout-standard.php';
if ( ! file_exists( $file ) ) {
    dc_assert( false, 'Global responsive layout standard exists in Design Core' );
    dc_finish( 'Global layout standard' );
}
require_once $file;

$standard = new Design_Core_Elementor_Global_Layout_Standard();
$devices = $standard->devices();
dc_assert( array( 'desktop', 'laptop', 'tablet', 'mobile' ) === array_keys( $devices ), 'Layout standard has exactly desktop, laptop, tablet and mobile tiers' );
dc_assert( 1440 === $devices['desktop']['container_width'], 'Desktop container width is 1440px' );
dc_assert( 1366 === $devices['laptop']['container_width'], 'Laptop container width is 1366px' );
dc_assert( 1024 === $devices['tablet']['container_width'], 'Tablet container width is 1024px' );
dc_assert( 767 === $devices['mobile']['container_width'], 'Mobile container width is 767px' );
dc_assert( 'clamp(32px, 4vw, 64px)' === $devices['desktop']['inline_padding'], 'Desktop inline padding is fluid and bounded' );
dc_assert( 'clamp(28px, 3.5vw, 48px)' === $devices['laptop']['inline_padding'], 'Laptop inline padding is fluid and bounded' );
dc_assert( 'clamp(20px, 3vw, 32px)' === $devices['tablet']['inline_padding'], 'Tablet inline padding is fluid and bounded' );
dc_assert( 'clamp(16px, 4vw, 24px)' === $devices['mobile']['inline_padding'], 'Mobile inline padding is fluid and bounded' );
dc_assert( 'dc-global-container' === $standard->class_name(), 'Standard exposes a stable Elementor class name' );

$settings = $standard->kit_settings();
dc_assert( 1440 === ( $settings['container_width']['size'] ?? null ) && 'px' === ( $settings['container_width']['unit'] ?? '' ), 'Kit base container width uses the native Elementor control' );
dc_assert( ! isset( $settings['container_padding'] ), 'Clamp padding does not corrupt Elementor non-responsive dimensions control' );

$css = $standard->managed_css();
foreach ( array( 'padding-inline: var(--dc-container-inline-padding)', '@media (max-width: 1366px)', '@media (max-width: 1024px)', '@media (max-width: 767px)' ) as $fragment ) {
    dc_assert( false !== strpos( $css, $fragment ), 'Managed CSS contains ' . $fragment );
}
dc_assert( false === strpos( $css, "\n  max-width:" ) && false === strpos( $css, '--dc-container-width:' ), 'Managed CSS never simulates Elementor Boxed width or duplicates Global Content Width' );

$user_css = '.user-rule { color: red; }';
$merged = $standard->merge_managed_css( $user_css );
dc_assert( false !== strpos( $merged, $user_css ), 'Managed layout CSS preserves user-authored Kit CSS' );
dc_assert( 1 === substr_count( $merged, Design_Core_Elementor_Global_Layout_Standard::CSS_START ), 'Managed layout block is inserted once' );
$updated = $standard->merge_managed_css( $merged );
dc_assert( 1 === substr_count( $updated, Design_Core_Elementor_Global_Layout_Standard::CSS_START ), 'Managed layout block updates idempotently without duplication' );

dc_finish( 'Global layout standard' );
