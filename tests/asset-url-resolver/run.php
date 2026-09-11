<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array(
    'core/asset-url-resolver.php',
) );

$base = 'https://designcorelementor.designcorehub.online/wp-content/uploads/2026/09/air-consolidation.html';

list( $html, $css, $count ) = Design_Core_Elementor_Asset_URL_Resolver::resolve(
    '<img src="assets/logo.png"><img src="https://cdn.example.com/a.jpg"><img src="data:image/png;base64,xx">',
    '.hero{background-image:url(assets/hero.webp)}',
    $base
);
dc_assert( 2 === $count, 'relative img and css url are resolved' );
dc_assert( false !== strpos( $html, 'https://designcorelementor.designcorehub.online/wp-content/uploads/2026/09/assets/logo.png' ), 'img src resolves against the page directory' );
dc_assert( false !== strpos( $html, 'https://cdn.example.com/a.jpg' ), 'absolute urls are untouched' );
dc_assert( false !== strpos( $html, 'data:image/png;base64,xx' ), 'data uris are untouched' );
dc_assert( false !== strpos( $css, 'https://designcorelementor.designcorehub.online/wp-content/uploads/2026/09/assets/hero.webp' ), 'css url() resolves' );

list( $srcset_html ) = Design_Core_Elementor_Asset_URL_Resolver::resolve(
    '<source srcset="assets/a.webp 1x, assets/b.webp 2x">', '', $base
);
dc_assert( false !== strpos( $srcset_html, 'uploads/2026/09/assets/a.webp 1x, https://designcorelementor.designcorehub.online/wp-content/uploads/2026/09/assets/b.webp 2x' ), 'srcset entries resolve with descriptors kept' );

list( $dotdot ) = Design_Core_Elementor_Asset_URL_Resolver::resolve( '<img src="../shared/x.png">', '', $base );
dc_assert( false !== strpos( $dotdot, 'uploads/2026/shared/x.png' ), 'dot-dot segments collapse' );

list( $rooted ) = Design_Core_Elementor_Asset_URL_Resolver::resolve( '<img src="/media/y.png">', '', $base );
dc_assert( false !== strpos( $rooted, 'https://designcorelementor.designcorehub.online/media/y.png' ), 'root-absolute paths resolve against the host' );

list( $unchanged_html, $unchanged_css, $unchanged_count ) = Design_Core_Elementor_Asset_URL_Resolver::resolve( '<p>hi</p>', '.a{color:red}', '' );
dc_assert( 0 === $unchanged_count && '<p>hi</p>' === $unchanged_html, 'empty base url leaves sources untouched' );

list( $bad ) = Design_Core_Elementor_Asset_URL_Resolver::resolve( '<img src="assets/z.png">', '', 'not-a-url' );
dc_assert( false !== strpos( $bad, 'assets/z.png' ), 'invalid base url leaves sources untouched' );

$abs = Design_Core_Elementor_Asset_URL_Resolver::absolutize( '#anchor', $base );
dc_assert( null === $abs, 'fragments are not assets' );

dc_finish( 'Asset URL Resolver' );
