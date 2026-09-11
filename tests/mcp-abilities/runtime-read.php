<?php
/**
 * Read-only WordPress runtime check. Run with the confirmed owner identity:
 * wp --user=<OWNER_ID> eval-file wp-content/plugins/design-core-elementor/tests/mcp-abilities/runtime-read.php
 * Does not prove OAuth/NHI transport or any write workflow.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }
if ( ! function_exists( 'wp_get_abilities' ) || ! class_exists( 'Design_Core_Elementor_MCP_Ability_Bridge' ) ) {
    WP_CLI::error( 'Abilities API or the Design Core bridge is unavailable.' );
}
wp_get_abilities(); // Initialize the lazy registry through the public API.
$names=Design_Core_Elementor_MCP_Ability_Bridge::ability_names();
$report=array(
    'kind'=>'wordpress-read-smoke',
    'contract'=>Design_Core_Elementor_MCP_Ability_Bridge::contract_report(),
    'missing_abilities'=>array(),
    'checks'=>array(),
    'connector_e2e_verified'=>false,
    'write_e2e_verified'=>false,
);
foreach($names as $name) {
    if(!wp_get_ability($name)) { $report['missing_abilities'][]=$name; }
}
$cases=array(
    'getManifest'=>array(),
    'getSiteStatus'=>array(),
    'getSiteMap'=>array('limit'=>3),
    'getElementorCapabilities'=>array(),
    'getElementorCatalog'=>array('limit'=>1),
);
$failed=!$report['contract']['parity'] || !empty($report['missing_abilities']);
foreach($cases as $id=>$input) {
    $ability=wp_get_ability($names[$id]);
    if(!$ability) { $failed=true; continue; }
    try {
        $result=$ability->execute($input);
        $ok=!is_wp_error($result) && is_array($result);
        $report['checks'][$id]=array('pass'=>$ok,'error_code'=>is_wp_error($result)?$result->get_error_code():null);
        if(!$ok) { $failed=true; }
    } catch(Throwable $error) {
        $failed=true;
        $report['checks'][$id]=array('pass'=>false,'error_code'=>'runtime_exception');
    }
}
// Never print the returned site content, tokens, raw headers or exceptions.
WP_CLI::line(wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
if($failed) { WP_CLI::error('Read smoke failed. Check owner, API enabled state, registry and runtime dependencies.'); }
WP_CLI::success('Read smoke passed. Real connector discovery and disposable-page write E2E still need separate verification.');
