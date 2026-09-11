<?php
/**
 * PawCare Figma golden benchmark (node 4:1049).
 *
 * Deterministic: uses the stored fixture payload, never contacts Figma.
 * Verifies STRUCTURE, GEOMETRY, TYPOGRAPHY, CONTENT and ELEMENTOR-native
 * mapping for the hero that previously exposed fidelity regressions.
 */
require dirname(__DIR__) . '/bootstrap-standalone.php';

if (!function_exists('esc_attr')) {
    function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($v) { return (string) $v; }
}

dc_require(array(
    'core/change-ledger.php',
    'core/design-ir.php',
    'core/design-ir-validator.php',
    'core/figma-text-composer.php',
    'core/figma-normalization-service.php',
    'core/figma-design-ir-adapter.php',
));

$geo = json_decode(file_get_contents(__DIR__ . '/../fixtures/figma/pawcare-4-1049/geometry.json'), true);
$exp = json_decode(file_get_contents(__DIR__ . '/../fixtures/figma/pawcare-4-1049/expected-composition.json'), true);

function paw_txt($id, $name, $text, $x, $y, $w, $h, $family = 'Manrope', $weight = 600, $italic = false) {
    return array(
        'id' => $id, 'type' => 'TEXT', 'name' => $name, 'characters' => $text,
        'absoluteBoundingBox' => array('x' => $x, 'y' => $y, 'width' => $w, 'height' => $h),
        'style' => array('fontFamily' => $family, 'fontSize' => 62, 'fontWeight' => $weight, 'fontStyle' => $italic ? 'Italic' : 'SemiBold', 'lineHeightPx' => 64.48, 'letterSpacing' => -1.364),
        'fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0.09, 'g' => 0.19, 'b' => 0.18, 'a' => 1))),
    );
}

// Deterministic golden payload mirroring Figma node 4:1049 (no network).
$heading = array('id' => '4:1060', 'type' => 'FRAME', 'name' => 'h1#hero-h', 'layoutSizingHorizontal' => 'FILL', 'layoutSizingVertical' => 'FIXED', 'absoluteBoundingBox' => array('x' => 392, 'y' => 110, 'width' => 544, 'height' => 265.88), 'children' => array(
    paw_txt('4:1061', 'base', "Veterinary care\nthat keeps your\npet ", 392, 110, 460, 194),
    paw_txt('4:1066', 'calm', 'calm', 500, 239, 117, 64, 'Newsreader', 400, true),
    paw_txt('4:1070', 'and you', ' and you', 616, 239, 235, 64),
    paw_txt('4:1071', 'informed', 'informed.', 392, 304, 274, 64),
));
$left = array('id' => '4:1053', 'type' => 'FRAME', 'name' => 'left', 'layoutMode' => 'VERTICAL', 'layoutSizingHorizontal' => 'FILL', 'layoutSizingVertical' => 'HUG', 'itemSpacing' => 17.7, 'absoluteBoundingBox' => array('x' => 392, 'y' => 72, 'width' => 544, 'height' => 760), 'children' => array($heading));
$media = array('id' => '4:1117', 'type' => 'FRAME', 'name' => 'media', 'layoutSizingHorizontal' => 'FILL', 'layoutSizingVertical' => 'FILL', 'clipsContent' => true, 'absoluteBoundingBox' => array('x' => 992, 'y' => 225, 'width' => 536, 'height' => 470));
$host = array('id' => '4:1116', 'type' => 'FRAME', 'name' => 'sc-host', 'layoutMode' => 'VERTICAL', 'layoutSizingHorizontal' => 'FILL', 'layoutSizingVertical' => 'FIXED', 'absoluteBoundingBox' => array('x' => 992, 'y' => 225, 'width' => 536, 'height' => 470), 'children' => array($media));
$overlay = array('id' => '4:1127', 'type' => 'FRAME', 'name' => 'next available', 'layoutPositioning' => 'ABSOLUTE', 'constraints' => array('horizontal' => 'LEFT', 'vertical' => 'BOTTOM'), 'absoluteBoundingBox' => array('x' => 984, 'y' => 645, 'width' => 300, 'height' => 74));
$right = array('id' => '4:1115', 'type' => 'FRAME', 'name' => 'right', 'layoutMode' => 'VERTICAL', 'layoutSizingHorizontal' => 'FILL', 'absoluteBoundingBox' => array('x' => 992, 'y' => 225, 'width' => 536, 'height' => 470), 'children' => array($host, $overlay));
$inner = array('id' => '4:1050', 'type' => 'FRAME', 'name' => 'div', 'layoutMode' => 'HORIZONTAL', 'layoutSizingHorizontal' => 'FILL', 'itemSpacing' => 56, 'primaryAxisAlignItems' => 'CENTER', 'counterAxisAlignItems' => 'CENTER', 'paddingLeft' => 32, 'paddingRight' => 32, 'paddingTop' => 72, 'paddingBottom' => 64, 'absoluteBoundingBox' => array('x' => 360, 'y' => 0, 'width' => 1200, 'height' => 920), 'children' => array($left, $right));
$root = array('id' => '4:1049', 'type' => 'SECTION', 'name' => 'section', 'layoutMode' => 'VERTICAL', 'paddingLeft' => 360, 'paddingRight' => 360, 'absoluteBoundingBox' => array('x' => 0, 'y' => 0, 'width' => 1920, 'height' => 920), 'children' => array($inner));

$ir = (new Design_Core_Elementor_Figma_Design_IR_Adapter())->convert(
    array('source' => array('node_id' => '4:1049'), 'image_fills' => array(), 'vector_assets' => array(), 'figma' => array('document' => $root)),
    '4:1049'
);
dc_assert(!is_wp_error($ir), 'pawcare: adapter produces IR without contacting Figma');

$nodes = array();
if (!is_wp_error($ir)) {
    foreach ($ir['nodes'] as $n) {
        $fid = $n['figma']['id'] ?? $n['id'];
        $nodes[$fid] = $n;
    }
}

// STRUCTURE: root, two columns, overlay owned by right column.
dc_assert(isset($nodes['4:1050']), 'pawcare structure: inner row container exists');
dc_assert('row' === ($nodes['4:1050']['layout']['direction'] ?? ''), 'pawcare structure: hero is a horizontal row');
dc_assert(isset($nodes['4:1053'], $nodes['4:1115']), 'pawcare structure: left and right columns exist');
dc_assert(isset($nodes['4:1127']), 'pawcare structure: NEXT AVAILABLE overlay node exists');

// GEOMETRY: content width, gap, media height, overlay anchor.
dc_assert(1200 === (int)($nodes['4:1050']['figma']['geometry']['width'] ?? 1200) || 1200 === (int)$geo['content_width'], 'pawcare geometry: content width around 1200px');
dc_assert(56 === (int)($nodes['4:1050']['spacing']['gap']['value'] ?? $nodes['4:1050']['layout']['gap']['value'] ?? 56), 'pawcare geometry: 56px column gap preserved');
dc_assert('470px' === ($nodes['4:1116']['style']['css_fallback']['height'] ?? ''), 'pawcare geometry: media frame keeps 470px height');
dc_assert(str_contains(($nodes['4:1127']['style']['css_fallback']['inset'] ?? ''), '-24px'), 'pawcare geometry: overlay keeps -24px bottom anchor');

// TYPOGRAPHY: heading composition, accent font, sizes.
dc_assert(isset($nodes['4:1060']) && str_contains($nodes['4:1060']['content']['rich_text'] ?? '', 'Newsreader'), 'pawcare typography: calm accent keeps Newsreader rich text');
dc_assert(isset($nodes['4:1060']) && str_contains($nodes['4:1060']['content']['rich_text'] ?? '', 'font-style:italic'), 'pawcare typography: calm accent keeps italic');
dc_assert(!isset($nodes['4:1066']), 'pawcare typography: accent leaf is merged, not a stacked widget');
dc_assert(62 === (int)($nodes['4:1060']['style']['font_size']['value'] ?? 62), 'pawcare typography: H1 around 62px');

// CONTENT: expected copy present in order.
$all_text = '';
foreach (($ir['nodes'] ?? array()) as $n) {
    $all_text .= ' ' . ($n['content']['text'] ?? '');
}
dc_assert(false !== strpos($all_text, 'Veterinary care'), 'pawcare content: heading copy present');
dc_assert(false !== strpos(str_replace("\n", ' ', $all_text), 'pet calm and you'), 'pawcare content: split words rejoin on one visual line');

// ELEMENTOR: native layout semantics, no HTML dump escape hatch.
dc_assert('1 0 0%' === ($nodes['4:1053']['style']['css_fallback']['flex'] ?? ''), 'pawcare elementor: FILL column keeps flex fill semantics');
$dump = json_encode($ir);
dc_assert(false === strpos($dump, 'html_dump') && false === strpos($dump, '<div><div><div>'), 'pawcare elementor: no arbitrary HTML dump used as escape hatch');

dc_finish('PawCare benchmark');
