<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
require_once DESIGN_CORE_ELEMENTOR_PATH . 'core/analysis-engine.php';

$engine = new Design_Core_Elementor_Analysis_Engine();
$method = new ReflectionMethod( $engine, 'declarations_to_ir' );
$method->setAccessible( true );
set_error_handler( static function ( $severity, $message ) { throw new ErrorException( $message, 0, $severity ); } );
try {
    $result = $method->invoke( $engine, array(
        'color' => '#112233',
        'font-family' => 'Poppins, sans-serif',
        'font-weight' => '600',
        'font-size' => '48px',
        'line-height' => '1.1',
        'width' => 'min(100%, 70rem)',
        'height' => '420px',
        'object-fit' => 'cover',
        'object-position' => '70% 50%',
        'background-image' => 'url("https://example.test/media/hero.webp")',
        'background-size' => 'cover',
        'background-position' => '30% 40%',
        'text-wrap' => 'balance',
    ) );
    $warning_free = true;
} catch ( Throwable $exception ) {
    $warning_free = false;
    $result = array( 'style' => array() );
}
restore_error_handler();

dc_assert( $warning_free, 'Valid unitless CSS values do not emit PHP warnings' );
dc_assert( 'Poppins, sans-serif' === ( $result['style']['font_family'] ?? '' ), 'Font family survives analysis into canonical IR' );
dc_assert( '600' === ( $result['style']['font_weight'] ?? '' ), 'Font weight survives analysis into canonical IR' );
dc_assert( 1.1 === ( $result['style']['line_height']['value'] ?? null ), 'Unitless line height keeps its numeric value' );
dc_assert( '' === ( $result['style']['line_height']['unit'] ?? null ), 'Unitless line height stays unitless' );
dc_assert( 'min(100%, 70rem)' === ( $result['style']['css_fallback']['width'] ?? '' ), 'Complex dimension expressions are preserved for scoped fallback instead of becoming zero' );
dc_assert( 'balance' === ( $result['style']['css_fallback']['text-wrap'] ?? '' ), 'Unsupported CSS properties remain explicit in canonical IR' );
dc_assert( 420.0 === ( $result['layout']['height']['value'] ?? null ) && 'px' === ( $result['layout']['height']['unit'] ?? '' ), 'Authored media height enters governed canonical layout instead of disconnected fallback CSS' );
dc_assert( 'cover' === ( $result['style']['object_fit'] ?? '' ) && '70% 50%' === ( $result['style']['object_position'] ?? '' ), 'Object crop and focal intent enter canonical media style fields' );
dc_assert( 'https://example.test/media/hero.webp' === ( $result['style']['background_image']['url'] ?? '' ), 'Background image URL enters canonical media for native mapping and asset import' );
dc_assert( 'cover' === ( $result['style']['background_size'] ?? '' ) && '30% 40%' === ( $result['style']['background_position'] ?? '' ), 'Background crop and focal intent enter canonical media style fields' );

dc_finish( 'Native style analysis' );
