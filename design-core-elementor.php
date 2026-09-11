<?php
/**
 * Plugin Name: Design Core Elementor
 * Description: Local-first AI design intelligence, reusable Elementor composition, HTML/Figma conversion, rendered visual verification, governed correction and verified Design Memory.
 * Version: 1.0.0-rc24
 * Author: Design Core
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: design-core-elementor
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'DESIGN_CORE_ELEMENTOR_VERSION' ) ) { define( 'DESIGN_CORE_ELEMENTOR_VERSION', '1.0.0-rc24' ); }
if ( ! defined( 'DESIGN_CORE_ELEMENTOR_PATH' ) ) { define( 'DESIGN_CORE_ELEMENTOR_PATH', plugin_dir_path( __FILE__ ) ); }
if ( ! defined( 'DESIGN_CORE_ELEMENTOR_URL' ) ) { define( 'DESIGN_CORE_ELEMENTOR_URL', plugin_dir_url( __FILE__ ) ); }

$vendor = DESIGN_CORE_ELEMENTOR_PATH . 'vendor/autoload.php';
if ( is_readable( $vendor ) ) { require_once $vendor; }
require_once DESIGN_CORE_ELEMENTOR_PATH . 'includes/class-loader.php';
Design_Core_Elementor_Loader::register();

$files = array(
    'includes/class-settings.php',
    'core/breakpoint-registry.php', 'core/security-policy.php', 'core/observability.php', 'core/runtime-evidence.php', 'core/change-ledger.php', 'core/conversion-transaction.php', 'core/design-core-capabilities.php', 'core/fingerprint-service.php', 'core/section-fingerprint-service.php', 'core/registry-item-validator.php', 'core/versioned-registry.php', 'core/component-registry.php', 'core/section-registry.php', 'core/widget-registry.php', 'core/design-token-pipeline.php', 'core/design-token-service.php', 'core/agent-draft-writes.php', 'core/token-registry.php', 'core/registry-migrator.php',
    'core/design-intelligence-catalog.php', 'core/design-system-profile.php', 'core/design-brief.php', 'core/page-strategy-engine.php', 'core/design-advisor.php',
    'core/design-memory-store.php', 'core/failure-signature-engine.php', 'core/fidelity-rule-registry.php', 'core/lesson-retriever.php', 'core/benchmark-promoter.php', 'core/correction-learning-engine.php', 'core/design-memory-service.php',
    'core/interaction-intelligence.php', 'core/design-ir.php', 'core/design-ir-validator.php', 'core/browser-analysis-service.php', 'core/layout-media-analyzer.php', 'core/layout-intelligence.php', 'core/analysis-engine.php', 'core/advanced-analyzer.php', 'core/semantic-matcher.php', 'core/reuse-engine.php', 'core/variant-engine.php', 'core/section-boundary-detector.php', 'core/section-intelligence.php', 'core/page-manifest.php', 'core/section-recipe-compiler.php', 'core/section-recipe-library.php', 'core/page-shell.php', 'core/section-explainability.php', 'core/decision-engine.php',
    'core/css-ast-service.php', 'core/responsive-style-parser.php', 'core/responsive-compiler.php', 'core/responsive-normalizer.php', 'core/normalization-pipeline.php', 'core/build-plan.php', 'core/build-plan-validator.php', 'core/build-planner.php', 'core/build-plan-preview.php', 'core/build-plan-simulator.php', 'core/executor-result.php', 'core/strategy-executor-interface.php', 'core/strict-structure-gate.php', 'core/control-schema-mapper.php', 'core/control-schema-registry.php', 'core/binding-governor.php', 'core/widget-intelligence.php', 'core/elementor-setting-governor.php', 'core/widget-control-adapters.php', 'core/scoped-css-fallback.php', 'core/elementor-architecture-auditor.php', 'core/elementor-mapping-engine.php', 'core/global-layout-standard.php', 'core/global-style-bridge.php', 'core/global-style-adapters.php', 'core/elementor-adapter-interface.php', 'core/elementor-persistence-service.php', 'core/elementor-v3-adapter.php', 'core/elementor-v4-adapter.php', 'core/theme-part-provisioner.php', 'core/loop-executor.php', 'core/pro-loop-adapter.php', 'core/custom-widget-executor.php', 'core/native-widget-resolver.php', 'core/native-widget-binder.php', 'core/strategy-executors.php', 'core/build-plan-executor.php', 'core/asset-importer.php', 'core/asset-url-resolver.php', 'core/conversion-service.php', 'core/native-fidelity-report.php', 'core/html-converter.php',
    'core/visual-regression-service.php', 'core/screenshot-service.php', 'core/visual-feedback-engine.php', 'core/visual-correction-applier.php', 'core/visual-correction-service.php', 'core/structural-diff-engine.php', 'core/visual-quality-gate.php', 'core/ux-quality-auditor.php', 'core/visual-qa.php', 'core/design-benchmark-corpus.php',
    'core/figma-vector-asset-resolver.php', 'core/figma-transport.php', 'core/figma-normalization-service.php', 'core/figma-text-composer.php', 'core/figma-design-ir-adapter.php', 'core/figma-font-service.php', 'core/figma-fidelity-service.php', 'core/figma-agent-service.php',
    'core/page-snapshot.php', 'core/site-intelligence.php', 'core/elementor-runtime-intelligence.php', 'core/design-agent-service.php', 'core/task-intelligence.php', 'core/compatibility-validator.php', 'core/production-readiness.php', 'core/registry-exporter.php', 'core/widget-generator.php', 'core/cli.php',
    'includes/class-capability-scanner.php', 'includes/class-plugin.php',
);
foreach ( $files as $file ) {
    $path = DESIGN_CORE_ELEMENTOR_PATH . $file;
    if ( is_readable( $path ) ) { require_once $path; }
}

if ( ! defined( 'DESIGN_CORE_AGENT_ENABLE_DRAFT_WRITES' ) && class_exists( 'Design_Core_Elementor_Agent_Draft_Writes' ) ) { define( 'DESIGN_CORE_AGENT_ENABLE_DRAFT_WRITES', Design_Core_Elementor_Agent_Draft_Writes::default_enabled() ); }
new Design_Core_Elementor_Design_Token_Service();
if ( class_exists( 'Design_Core_Elementor_Figma_Font_Service' ) ) { ( new Design_Core_Elementor_Figma_Font_Service() )->boot(); }
if ( class_exists( 'Design_Core_Elementor_Design_Memory_Service' ) ) { Design_Core_Elementor_Design_Memory_Service::boot(); }
add_filter( 'design_core_elementor_run_browser_analysis', static function ( $enabled ) { $rules = Design_Core_Elementor_Settings::get_conversion_rules(); return ! empty( $rules['browser_analysis'] ); }, 10, 1 );
register_activation_hook( __FILE__, static function () { if ( class_exists( 'Design_Core_Elementor_Registry_Migrator' ) ) { ( new Design_Core_Elementor_Registry_Migrator() )->migrate(); } if ( class_exists( 'Design_Core_Elementor_Capabilities' ) ) { Design_Core_Elementor_Capabilities::migrate(); } } );
if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'Design_Core_Elementor_CLI' ) ) { WP_CLI::add_command( 'design-core', 'Design_Core_Elementor_CLI' ); }
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once DESIGN_CORE_ELEMENTOR_PATH . 'core/design-memory-cli.php';
    require_once DESIGN_CORE_ELEMENTOR_PATH . 'core/figma-fidelity-cli.php';
    if ( class_exists( 'Design_Core_Elementor_Design_Memory_CLI' ) ) { WP_CLI::add_command( 'design-core-memory', 'Design_Core_Elementor_Design_Memory_CLI' ); }
    if ( class_exists( 'Design_Core_Elementor_Figma_Fidelity_CLI' ) ) { WP_CLI::add_command( 'design-core-figma', 'Design_Core_Elementor_Figma_Fidelity_CLI' ); }
}
add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
        add_action( 'admin_notices', static function () { echo '<div class="notice notice-warning"><p>' . esc_html__( 'Design Core Elementor requires Elementor to be installed and activated.', 'design-core-elementor' ) . '</p></div>'; } );
        return;
    }
    Design_Core_Elementor_Plugin::instance();
} );
require_once DESIGN_CORE_ELEMENTOR_PATH . 'core/agent-protocol.php';
