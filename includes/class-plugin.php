<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Plugin {
    private static $instance = null;
    public static function instance() { if ( null === self::$instance ) { self::$instance = new self(); } return self::$instance; }

    public function __construct() { $this->register_hooks(); $this->ensure_registry_defaults(); $this->ensure_capabilities(); }

    public static function require_widget_base_classes() {
        if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) { return; }
        if ( ! class_exists( 'Design_Core_Elementor_Widget_Base' ) ) { require_once DESIGN_CORE_ELEMENTOR_PATH . 'widgets/base/class-design-core-widget-base.php'; }
        if ( ! class_exists( 'Design_Core_Elementor_Runtime_Widget' ) ) { require_once DESIGN_CORE_ELEMENTOR_PATH . 'widgets/base/class-design-core-runtime-widget.php'; }
        require_once DESIGN_CORE_ELEMENTOR_PATH . 'widgets/class-global-time-bar.php';
        foreach ( glob( DESIGN_CORE_ELEMENTOR_PATH . 'widgets/generated/*.php' ) ?: array() as $file ) { require_once $file; }
    }

    private function register_hooks() {
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'elementor/elements/categories_registered', array( $this, 'register_elementor_category' ) );
        add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widgets' ) );
        add_action( 'elementor/frontend/after_register_scripts', array( $this, 'register_frontend_assets' ) );
        add_action( 'elementor/frontend/after_register_styles', array( $this, 'register_frontend_assets' ) );
        add_action( 'wp_abilities_api_categories_init', array( $this, 'register_ability_category' ) );
        add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
    }

    private function ensure_registry_defaults() {
        ( new Design_Core_Elementor_Component_Registry() )->ensure_defaults();
        if ( class_exists( 'Design_Core_Elementor_Section_Registry' ) ) { ( new Design_Core_Elementor_Section_Registry() )->ensure_defaults(); }
        ( new Design_Core_Elementor_Widget_Registry() )->ensure_defaults();
        ( new Design_Core_Elementor_Token_Registry() )->ensure_defaults();
    }

    /**
     * register_activation_hook() only fires on activate/deactivate-reactivate, never on an
     * in-place file update of an already-active plugin -- so an upgrade also runs this
     * versioned, idempotent check on every load (cheap: one get_option() in the common case).
     */
    private function ensure_capabilities() {
        if ( class_exists( 'Design_Core_Elementor_Capabilities' ) ) { Design_Core_Elementor_Capabilities::migrate(); }
    }

    public function register_admin_menu() {
        add_menu_page( 'Design Core', 'Design Core', 'manage_options', 'design-core-elementor', array( $this, 'render_admin_page' ), 'dashicons-art' );
        add_submenu_page( 'design-core-elementor', 'HTML Import', 'HTML Import', 'manage_options', 'design-core-html-import', array( $this, 'render_html_import_page' ) );
        add_submenu_page( 'design-core-elementor', 'Build Preview', 'Build Preview', 'manage_options', Design_Core_Elementor_Intelligence_Admin::PREVIEW_SLUG, array( $this, 'render_build_preview_page' ) );
        add_submenu_page( 'design-core-elementor', 'Elementor Widgets', 'Elementor Widgets', 'manage_options', Design_Core_Elementor_Widget_Inspector::PAGE_SLUG, array( $this, 'render_elementor_widgets_page' ) );
        add_submenu_page( 'design-core-elementor', 'Page Intelligence', 'Page Intelligence', 'manage_options', Design_Core_Elementor_Intelligence_Admin::PAGE_INTELLIGENCE_SLUG, array( $this, 'render_page_intelligence_page' ) );
        add_submenu_page( 'design-core-elementor', 'Quality Benchmarks', 'Quality Benchmarks', 'manage_options', Design_Core_Elementor_Intelligence_Admin::BENCHMARKS_SLUG, array( $this, 'render_benchmarks_page' ) );
        add_submenu_page( 'design-core-elementor', 'Registry', 'Registry', 'manage_options', 'design-core-registry', array( $this, 'render_registry_page' ) );
        add_submenu_page( 'design-core-elementor', 'History', 'History', 'manage_options', Design_Core_Elementor_Intelligence_Admin::HISTORY_SLUG, array( $this, 'render_history_page' ) );
        add_submenu_page( 'design-core-elementor', 'Agent Bridge', 'Agent Bridge', 'manage_options', Design_Core_Elementor_Intelligence_Admin::AGENT_SLUG, array( $this, 'render_agent_page' ) );
        add_submenu_page( 'design-core-elementor', 'Remote Access', 'Remote Access', 'manage_options', Design_Core_Elementor_Remote_Access_Admin::SLUG, array( $this, 'render_remote_access_page' ) );
        add_submenu_page( 'design-core-elementor', 'Settings', 'Settings', 'manage_options', 'design-core-settings', array( $this, 'render_settings_page' ) );
        add_submenu_page( 'design-core-elementor', 'Production Readiness', 'Production Readiness', 'manage_options', 'design-core-readiness', array( $this, 'render_readiness_page' ) );
    }

    public function enqueue_admin_assets() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 0 !== strpos( $page, 'design-core' ) ) { return; }
        wp_enqueue_style( 'design-core-elementor-admin', DESIGN_CORE_ELEMENTOR_URL . 'assets/admin.css', array(), DESIGN_CORE_ELEMENTOR_VERSION );
        wp_enqueue_style( 'design-core-elementor-intelligence-admin', DESIGN_CORE_ELEMENTOR_URL . 'assets/admin-intelligence.css', array( 'design-core-elementor-admin' ), DESIGN_CORE_ELEMENTOR_VERSION );
        if ( Design_Core_Elementor_Widget_Inspector::PAGE_SLUG === $page ) { wp_enqueue_script( 'design-core-elementor-widget-inspector', DESIGN_CORE_ELEMENTOR_URL . 'assets/admin-widget-inspector.js', array(), DESIGN_CORE_ELEMENTOR_VERSION, true ); }
    }

    public function register_frontend_assets() {
        wp_register_style( 'design-core-global-time-bar', DESIGN_CORE_ELEMENTOR_URL . 'assets/global-time-bar.css', array(), DESIGN_CORE_ELEMENTOR_VERSION );
        wp_register_script( 'design-core-global-time-bar', DESIGN_CORE_ELEMENTOR_URL . 'assets/global-time-bar.js', array(), DESIGN_CORE_ELEMENTOR_VERSION, true );
    }
    public function register_rest_routes() {
        ( new Design_Core_Elementor_Rest_Controller() )->register_routes();
        if ( class_exists( 'Design_Core_Elementor_Rest_Controller_V2' ) ) { ( new Design_Core_Elementor_Rest_Controller_V2() )->register_routes(); }
    }
    public function register_elementor_category( $manager ) { $manager->add_category( 'design-core', array( 'title'=>'Design Core', 'icon'=>'eicon-kit' ) ); }
    public function register_ability_category() { if ( class_exists( 'Design_Core_Elementor_Agent_Gateway' ) ) { ( new Design_Core_Elementor_Agent_Gateway() )->register_ability_category(); } }
    public function register_abilities() {
        if ( class_exists( 'Design_Core_Elementor_Agent_Gateway' ) ) { ( new Design_Core_Elementor_Agent_Gateway() )->register_abilities(); }
        if ( class_exists( 'Design_Core_Elementor_WordPress_MCP_Compatibility' ) ) { ( new Design_Core_Elementor_WordPress_MCP_Compatibility() )->register_abilities(); }
        if ( class_exists( 'Design_Core_Elementor_MCP_Ability_Bridge' ) ) { ( new Design_Core_Elementor_MCP_Ability_Bridge() )->register_abilities(); }
    }

    public function register_elementor_widgets( $widgets_manager ) {
        self::require_widget_base_classes();
        foreach ( get_declared_classes() as $class ) {
            if ( 'Design_Core_Elementor_Runtime_Widget' === $class ) { continue; }
            if ( 0 !== strpos( $class, 'Design_Core_Elementor_' ) || ! is_subclass_of( $class, 'Design_Core_Elementor_Widget_Base' ) ) { continue; }
            try { $widgets_manager->register( new $class() ); } catch ( Throwable $e ) { do_action( 'design_core_elementor_widget_registration_error', $class, $e ); }
        }
        if ( class_exists( 'Design_Core_Elementor_Runtime_Widget' ) ) {
            foreach ( ( new Design_Core_Elementor_Widget_Registry() )->all() as $definition ) {
                if ( 'design-core-runtime' !== ( $definition['source']['kind'] ?? $definition['source'] ?? '' ) ) { continue; }
                try { $widgets_manager->register( new Design_Core_Elementor_Runtime_Widget( array(), array( 'widgetType' => $definition['slug'] ?? '' ) ) ); } catch ( Throwable $e ) { do_action( 'design_core_elementor_widget_registration_error', $definition['slug'] ?? 'runtime', $e ); }
            }
        }
    }

    public function get_capability_report() { return ( new Design_Core_Elementor_Capability_Scanner() )->scan(); }
    public function render_html_import_page() { ( new Design_Core_Elementor_HTML_Import_UI() )->render_import_page(); }
    public function render_build_preview_page() { ( new Design_Core_Elementor_Intelligence_Admin() )->render_preview_page(); }
    public function render_elementor_widgets_page() { ( new Design_Core_Elementor_Widget_Inspector() )->render_page(); }
    public function render_page_intelligence_page() { ( new Design_Core_Elementor_Intelligence_Admin() )->render_page_intelligence(); }
    public function render_benchmarks_page() { ( new Design_Core_Elementor_Intelligence_Admin() )->render_benchmarks_page(); }
    public function render_history_page() { ( new Design_Core_Elementor_Intelligence_Admin() )->render_history_page(); }
    public function render_agent_page() { ( new Design_Core_Elementor_Intelligence_Admin() )->render_agent_page(); }
    public function render_remote_access_page() { ( new Design_Core_Elementor_Remote_Access_Admin() )->render_page(); }
    public function render_settings_page() { ( new Design_Core_Elementor_Settings() )->render_settings_page(); }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $caps = $this->get_capability_report(); $browser = new Design_Core_Elementor_Browser_Analysis_Service(); $figma = new Design_Core_Elementor_Figma_Transport();
        $components = count( ( new Design_Core_Elementor_Component_Registry() )->all() );
        $sections = class_exists( 'Design_Core_Elementor_Section_Registry' ) ? count( ( new Design_Core_Elementor_Section_Registry() )->all() ) : 0;
        $widgets = count( ( new Design_Core_Elementor_Widget_Registry() )->all() );
        $history = class_exists( 'Design_Core_Elementor_Change_Ledger' ) ? count( ( new Design_Core_Elementor_Change_Ledger() )->all() ) : 0;
        $benchmarks = count( (array) ( ( new Design_Core_Elementor_Design_Benchmark_Corpus() )->catalog()['benchmarks'] ?? array() ) );
        echo '<div class="wrap design-core-admin-shell design-core-dashboard"><h1>Design Core Elementor ' . esc_html( DESIGN_CORE_ELEMENTOR_VERSION ) . '</h1>';
        echo '<p>Editor mode: <strong>' . esc_html( $caps['elementor']['editor_mode'] ?? 'v3' ) . '</strong> · Browser analysis: <strong>' . esc_html( $browser->is_available() ? 'available' : 'unavailable' ) . '</strong> · Figma URL: <strong>' . esc_html( $figma->configured() ? 'configured' : 'not configured' ) . '</strong> · Abilities API: <strong>' . esc_html( function_exists( 'wp_register_ability' ) ? 'available' : 'optional / unavailable' ) . '</strong></p>';
        echo '<div class="design-core-widget-stats"><a class="design-core-widget-stat" href="' . esc_url( admin_url( 'admin.php?page=' . Design_Core_Elementor_Intelligence_Admin::PREVIEW_SLUG ) ) . '"><span>Build Preview</span><strong>v2</strong><small>Execution simulation</small></a><a class="design-core-widget-stat" href="' . esc_url( admin_url( 'admin.php?page=' . Design_Core_Elementor_Intelligence_Admin::PAGE_INTELLIGENCE_SLUG ) ) . '"><span>Visual Fidelity</span><strong>v3</strong><small>DOM-aware correction</small></a><a class="design-core-widget-stat" href="' . esc_url( admin_url( 'admin.php?page=' . Design_Core_Elementor_Intelligence_Admin::BENCHMARKS_SLUG ) ) . '"><span>Benchmarks</span><strong>' . esc_html( (string) $benchmarks ) . '</strong><small>quality corpus</small></a><a class="design-core-widget-stat" href="' . esc_url( admin_url( 'admin.php?page=' . Design_Core_Elementor_Intelligence_Admin::HISTORY_SLUG ) ) . '"><span>History</span><strong>' . esc_html( (string) $history ) . '</strong><small>verified mutations</small></a><a class="design-core-widget-stat" href="' . esc_url( admin_url( 'admin.php?page=' . Design_Core_Elementor_Intelligence_Admin::AGENT_SLUG ) ) . '"><span>Agent Bridge</span><strong>v2</strong><small>compact tools</small></a></div>';
        echo '<p>Components: ' . esc_html( (string) $components ) . ' · Sections: ' . esc_html( (string) $sections ) . ' · Persistent widgets: ' . esc_html( (string) $widgets ) . '</p></div>';
    }

    public function render_registry_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $sections = class_exists( 'Design_Core_Elementor_Section_Registry' ) ? ( new Design_Core_Elementor_Section_Registry() )->all() : array();
        $components = ( new Design_Core_Elementor_Component_Registry() )->all();
        echo '<div class="wrap design-core-admin-shell design-core-registry"><h1>Design Registry</h1>';
        echo '<h2>Sections</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Family</th><th>Family hash</th><th>Variants</th><th>Version</th><th>Usage</th><th>Explain</th></tr></thead><tbody>';
        if ( ! $sections ) { echo '<tr><td colspan="7">No section masters yet.</td></tr>'; }
        foreach ( $sections as $item ) {
            $explain = ( new Design_Core_Elementor_Section_Explainability() )->explain_master( $item['id'] ?? '' );
            echo '<tr><td>' . esc_html( $item['id'] ?? '' ) . '</td><td>' . esc_html( $item['fingerprint']['semantic'] ?? '' ) . '</td><td><code>' . esc_html( substr( (string) ( $item['fingerprint']['family_hash'] ?? '' ), 0, 14 ) ) . '</code></td><td>' . esc_html( (string) count( (array) ( $item['variants'] ?? array() ) ) ) . '</td><td>' . esc_html( (string) ( $item['item_version'] ?? 1 ) ) . '</td><td>' . esc_html( (string) ( $item['usage']['count'] ?? 0 ) ) . '</td><td>';
            if ( is_array( $explain ) ) { echo '<details><summary>Diagnostics</summary><pre class="design-core-registry-json">' . esc_html( wp_json_encode( Design_Core_Elementor_Change_Ledger::transport_safe( $explain ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>'; } else { echo '—'; }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Components</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Purpose</th><th>Variants</th><th>Version</th><th>Usage</th></tr></thead><tbody>';
        if ( ! $components ) { echo '<tr><td colspan="5">No component masters yet.</td></tr>'; }
        foreach ( $components as $item ) {
            echo '<tr><td>' . esc_html( $item['id'] ?? '' ) . '</td><td>' . esc_html( $item['fingerprint']['semantic'] ?? '' ) . '</td><td>' . esc_html( (string) count( (array) ( $item['variants'] ?? array() ) ) ) . '</td><td>' . esc_html( (string) ( $item['item_version'] ?? 1 ) ) . '</td><td>' . esc_html( (string) ( $item['usage']['count'] ?? 0 ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function render_readiness_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $report = ( new Design_Core_Elementor_Production_Readiness() )->audit();
        echo '<div class="wrap design-core-admin-shell design-core-readiness"><h1>Production Readiness</h1><p>Status: <strong>' . esc_html( $report['status'] ?? 'unknown' ) . '</strong></p><table class="widefat striped"><thead><tr><th>Check</th><th>Required</th><th>Result</th><th>Message</th></tr></thead><tbody>';
        foreach ( $report['checks'] ?? array() as $name => $check ) { echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( ! empty( $check['required'] ) ? 'Yes' : 'No' ) . '</td><td>' . esc_html( ! empty( $check['pass'] ) ? 'PASS' : 'FAIL' ) . '</td><td>' . esc_html( $check['message'] ?? '' ) . '</td></tr>'; }
        echo '</tbody></table></div>';
    }
}
