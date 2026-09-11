<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Admin surfaces for planning, fidelity, page context, history and agent bridge. */
class Design_Core_Elementor_Intelligence_Admin {
    const PREVIEW_SLUG = 'design-core-build-preview';
    const PAGE_INTELLIGENCE_SLUG = 'design-core-page-intelligence';
    const BENCHMARKS_SLUG = 'design-core-quality-benchmarks';
    const HISTORY_SLUG = 'design-core-history';
    const AGENT_SLUG = 'design-core-agent';

    public function render_preview_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $result = null; $mode = isset( $_POST['source_mode'] ) ? sanitize_key( wp_unslash( $_POST['source_mode'] ) ) : 'html';
        $source = isset( $_POST['source_payload'] ) ? wp_unslash( $_POST['source_payload'] ) : '';
        $css = isset( $_POST['source_css'] ) ? wp_unslash( $_POST['source_css'] ) : '';
        $adapter = isset( $_POST['adapter_target'] ) ? sanitize_key( wp_unslash( $_POST['adapter_target'] ) ) : 'auto';
        $figma_node_id = isset( $_POST['figma_node_id'] ) ? sanitize_text_field( wp_unslash( $_POST['figma_node_id'] ) ) : '';

        if ( isset( $_POST['design_core_preview_submit'] ) && check_admin_referer( 'design_core_build_preview', 'design_core_preview_nonce' ) ) {
            if ( 'figma-url' === $mode ) {
                $transport = new Design_Core_Elementor_Figma_Transport();
                $payload = $transport->read_url( esc_url_raw( (string) $source ), array( 'resolve_image_fills' => true ) );
                $ir = is_wp_error( $payload ) ? $payload : ( new Design_Core_Elementor_Figma_Design_IR_Adapter() )->convert( $payload, $figma_node_id );
                $result = is_wp_error( $ir ) ? $ir : ( new Design_Core_Elementor_Build_Plan_Preview() )->preview_ir( $ir, $adapter );
            } elseif ( 'figma' === $mode ) {
                $payload = json_decode( (string) $source, true );
                $ir = is_array( $payload ) ? ( new Design_Core_Elementor_Figma_Design_IR_Adapter() )->convert( $payload, $figma_node_id ) : new WP_Error( 'design_core_figma_json_invalid', 'Figma JSON is invalid.' );
                $result = is_wp_error( $ir ) ? $ir : ( new Design_Core_Elementor_Build_Plan_Preview() )->preview_ir( $ir, $adapter );
            } elseif ( 'shell' === $mode ) {
                $payload = json_decode( (string) $source, true );
                $shell = isset( $_POST['shell_id'] ) ? sanitize_key( wp_unslash( $_POST['shell_id'] ) ) : 'service-landing';
                $ir = is_array( $payload ) ? ( new Design_Core_Elementor_Page_Shell() )->compile( $shell, $payload ) : new WP_Error( 'design_core_shell_json_invalid', 'Page Shell bindings JSON is invalid.' );
                $result = is_wp_error( $ir ) ? $ir : ( new Design_Core_Elementor_Build_Plan_Preview() )->preview_ir( $ir, $adapter );
            } else {
                $result = ( new Design_Core_Elementor_Build_Plan_Preview() )->preview_source( (string) $source, (string) $css, 'admin-preview', $adapter );
            }
        }

        $figma_configured = class_exists( 'Design_Core_Elementor_Figma_Transport' ) && ( new Design_Core_Elementor_Figma_Transport() )->configured();
        echo '<div class="wrap design-core-admin-shell design-core-intelligence-page"><h1>BuildPlan Preview / Dry Run</h1><p>Plan the real strategy and simulated Elementor output without creating posts or mutating registries.</p>';
        echo '<div class="design-core-card"><strong>rc20:</strong> execution simulation is enabled. Direct Figma URL reads are <strong>' . esc_html( $figma_configured ? 'configured' : 'not configured' ) . '</strong>.</div>';
        echo '<form method="post" class="design-core-intelligence-form">';
        wp_nonce_field( 'design_core_build_preview', 'design_core_preview_nonce' );
        echo '<div class="design-core-intelligence-grid"><label>Source type<select name="source_mode"><option value="html"' . selected( $mode, 'html', false ) . '>HTML/CSS</option><option value="figma-url"' . selected( $mode, 'figma-url', false ) . '>Figma URL</option><option value="figma"' . selected( $mode, 'figma', false ) . '>Figma JSON</option><option value="shell"' . selected( $mode, 'shell', false ) . '>Page Shell bindings</option></select></label>';
        echo '<label>Adapter<select name="adapter_target"><option value="auto"' . selected( $adapter, 'auto', false ) . '>Auto</option><option value="elementor-v3"' . selected( $adapter, 'elementor-v3', false ) . '>Elementor V3</option><option value="elementor-v4"' . selected( $adapter, 'elementor-v4', false ) . '>Elementor V4</option></select></label>';
        echo '<label>Page Shell<select name="shell_id">'; foreach ( Design_Core_Elementor_Page_Shell::definitions() as $id => $definition ) { echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $definition['label'] ) . '</option>'; } echo '</select></label>';
        echo '<label>Figma node ID<input type="text" name="figma_node_id" value="' . esc_attr( $figma_node_id ) . '" placeholder="Optional selection id"></label></div>';
        echo '<label class="design-core-intelligence-block">HTML / Figma URL / Figma JSON / Page Shell bindings<textarea name="source_payload" rows="18" spellcheck="false">' . esc_textarea( (string) $source ) . '</textarea></label>';
        echo '<label class="design-core-intelligence-block">CSS (HTML mode)<textarea name="source_css" rows="8" spellcheck="false">' . esc_textarea( (string) $css ) . '</textarea></label>';
        echo '<p><button class="button button-primary" name="design_core_preview_submit" value="1">Run dry run</button></p></form>';
        if ( $result ) { $this->render_preview_result( $result ); }
        echo '</div>';
    }

    public function render_page_intelligence() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $page_id = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : ( isset( $_GET['page_id'] ) ? absint( $_GET['page_id'] ) : 0 );
        $visual_result = null; $correction_result = null;
        $reference = isset( $_POST['reference_target'] ) ? esc_url_raw( wp_unslash( $_POST['reference_target'] ) ) : '';
        $candidate = isset( $_POST['candidate_target'] ) ? esc_url_raw( wp_unslash( $_POST['candidate_target'] ) ) : '';
        if ( $page_id && '' === $candidate && function_exists( 'get_permalink' ) ) { $candidate = (string) get_permalink( $page_id ); }

        if ( $page_id && isset( $_POST['design_core_visual_feedback_submit'] ) && check_admin_referer( 'design_core_visual_feedback', 'design_core_visual_feedback_nonce' ) ) {
            $visual_result = ( new Design_Core_Elementor_Visual_Feedback_Engine() )->evaluate_targets( $reference, $candidate, array( 'page_id' => $page_id, 'target_similarity' => 0.95 ) );
        }
        if ( $page_id && isset( $_POST['design_core_visual_correction_submit'] ) && check_admin_referer( 'design_core_visual_correction', 'design_core_visual_correction_nonce' ) ) {
            $max_iterations = isset( $_POST['max_iterations'] ) ? max( 1, min( 5, absint( $_POST['max_iterations'] ) ) ) : 3;
            $confirmed = ! empty( $_POST['confirm_visual_correction'] );
            $correction_result = $confirmed
                ? ( new Design_Core_Elementor_Visual_Correction_Service() )->run( $reference, $candidate, $max_iterations, 0.95, array( 'page_id' => $page_id ) )
                : new WP_Error( 'design_core_visual_correction_confirmation', 'Automatic visual correction requires explicit confirmation.' );
        }

        $snapshot = $page_id ? ( new Design_Core_Elementor_Page_Snapshot() )->snapshot( $page_id ) : null;
        echo '<div class="wrap design-core-admin-shell design-core-intelligence-page"><h1>Page Intelligence</h1><p>Page Snapshot + automatic rendered DOM evidence + governed visual correction.</p>';
        echo '<form method="get" class="design-core-inline-form"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_INTELLIGENCE_SLUG ) . '"><label>Page ID <input type="number" min="1" name="page_id" value="' . esc_attr( (string) $page_id ) . '"></label><button class="button button-primary">Load snapshot</button></form>';
        if ( is_wp_error( $snapshot ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $snapshot->get_error_message() ) . '</p></div>'; }
        elseif ( is_array( $snapshot ) ) {
            $el = (array) ( $snapshot['elementor'] ?? array() ); $dc = (array) ( $snapshot['design_core'] ?? array() );
            echo '<div class="design-core-widget-stats design-core-intelligence-stats">';
            $this->stat( 'Elements', $el['total_elements'] ?? 0, 'Elementor tree' ); $this->stat( 'Widgets', $el['widgets'] ?? 0, count( (array) ( $el['widget_types'] ?? array() ) ) . ' types' ); $this->stat( 'Sections', $dc['manifest_section_count'] ?? 0, 'Page Manifest' ); $this->stat( 'Responsive', $el['responsive_override_count'] ?? 0, 'overrides' ); $this->stat( 'History', count( (array) ( $snapshot['history'] ?? array() ) ), 'changes' );
            echo '</div>';

            echo '<div class="design-core-card design-core-visual-feedback-card"><h2>Visual Fidelity v3</h2><p>Screenshot comparison now automatically collects rendered DOM/computed-style evidence when Playwright is available, including the owning Elementor element ID.</p>';
            echo '<form method="post" class="design-core-intelligence-grid design-core-visual-feedback-form">';
            wp_nonce_field( 'design_core_visual_feedback', 'design_core_visual_feedback_nonce' );
            echo '<input type="hidden" name="page_id" value="' . esc_attr( (string) $page_id ) . '">';
            echo '<label>Reference URL<input type="url" name="reference_target" required value="' . esc_attr( $reference ) . '" placeholder="https://reference.example/page"></label>';
            echo '<label>Candidate URL<input type="url" name="candidate_target" value="' . esc_attr( $candidate ) . '"></label>';
            echo '<div class="design-core-intelligence-action"><button class="button button-primary" name="design_core_visual_feedback_submit" value="1">Analyze visual fidelity</button></div></form>';
            if ( is_wp_error( $visual_result ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $visual_result->get_error_message() ) . '</p></div>'; }
            elseif ( is_array( $visual_result ) ) { $this->json_panel( 'Visual Feedback v3 result', $visual_result ); }

            echo '<hr><h3>Automatic correction</h3><p>Only high-confidence, addressable Elementor V3 changes with live runtime controls are applied. Unsupported/private controls fail closed, and every verified save is recorded in History.</p>';
            echo '<form method="post" class="design-core-intelligence-grid design-core-visual-feedback-form">';
            wp_nonce_field( 'design_core_visual_correction', 'design_core_visual_correction_nonce' );
            echo '<input type="hidden" name="page_id" value="' . esc_attr( (string) $page_id ) . '"><input type="hidden" name="reference_target" value="' . esc_attr( $reference ) . '"><input type="hidden" name="candidate_target" value="' . esc_attr( $candidate ) . '">';
            echo '<label>Maximum iterations<input type="number" min="1" max="5" name="max_iterations" value="3"></label>';
            echo '<label><input type="checkbox" name="confirm_visual_correction" value="1"> I understand this will modify the Elementor page.</label>';
            echo '<div class="design-core-intelligence-action"><button class="button button-secondary" name="design_core_visual_correction_submit" value="1"' . ( $reference ? '' : ' disabled' ) . '>Run governed correction</button></div></form>';
            if ( is_wp_error( $correction_result ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $correction_result->get_error_message() ) . '</p></div>'; }
            elseif ( is_array( $correction_result ) ) { $this->json_panel( 'Automatic correction result', $correction_result ); }
            echo '</div>';
            $this->json_panel( 'Page Snapshot', $snapshot );
        }
        echo '</div>';
    }

    public function render_benchmarks_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $corpus = new Design_Core_Elementor_Design_Benchmark_Corpus(); $catalog = $corpus->catalog(); $result = null;
        if ( isset( $_POST['design_core_run_benchmark'] ) && check_admin_referer( 'design_core_benchmark', 'design_core_benchmark_nonce' ) ) {
            $result = $corpus->planning( sanitize_key( wp_unslash( $_POST['design_core_run_benchmark'] ) ) );
        }
        echo '<div class="wrap design-core-admin-shell design-core-intelligence-page"><h1>Quality Benchmarks</h1><p>Fixed design corpus for measuring planning and visual fidelity instead of judging quality by code volume.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Benchmark</th><th>Fixture</th><th>Target similarity</th><th>Available</th><th>Planning</th></tr></thead><tbody>';
        foreach ( (array) ( $catalog['benchmarks'] ?? array() ) as $benchmark ) {
            echo '<tr><td><strong>' . esc_html( $benchmark['label'] ?? $benchmark['id'] ) . '</strong><br><code>' . esc_html( $benchmark['id'] ?? '' ) . '</code></td><td><code>' . esc_html( $benchmark['fixture'] ?? '' ) . '</code></td><td>' . esc_html( number_format_i18n( 100 * (float) ( $benchmark['target_similarity'] ?? 0 ), 1 ) ) . '%</td><td>' . esc_html( ! empty( $benchmark['available'] ) ? 'Yes' : 'No' ) . '</td><td><form method="post">';
            wp_nonce_field( 'design_core_benchmark', 'design_core_benchmark_nonce' );
            echo '<button class="button" name="design_core_run_benchmark" value="' . esc_attr( $benchmark['id'] ?? '' ) . '"' . ( ! empty( $benchmark['available'] ) ? '' : ' disabled' ) . '>Run planning check</button></form></td></tr>';
        }
        echo '</tbody></table>';
        if ( is_wp_error( $result ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'; }
        elseif ( is_array( $result ) ) { $this->json_panel( 'Benchmark result', $result ); }
        echo '</div>';
    }

    public function render_history_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $message = '';
        if ( isset( $_POST['design_core_rollback_entry'] ) && check_admin_referer( 'design_core_history_rollback', 'design_core_history_nonce' ) ) {
            $rollback = ( new Design_Core_Elementor_Change_Ledger() )->rollback( sanitize_key( wp_unslash( $_POST['design_core_rollback_entry'] ) ) );
            $message = is_wp_error( $rollback ) ? $rollback->get_error_message() : 'Rollback completed for page #' . (int) ( $rollback['post_id'] ?? 0 ) . '.';
        }
        $entries = ( new Design_Core_Elementor_Change_Ledger() )->summaries();
        echo '<div class="wrap design-core-admin-shell design-core-intelligence-page"><h1>Persistent History</h1><p>Completed Elementor mutations with conflict-aware rollback.</p>';
        if ( $message ) { echo '<div class="notice notice-info inline"><p>' . esc_html( $message ) . '</p></div>'; }
        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Action</th><th>Object</th><th>Actor</th><th>Before</th><th>After</th><th>Rollback</th></tr></thead><tbody>';
        if ( ! $entries ) { echo '<tr><td colspan="7">No persistent history entries yet.</td></tr>'; }
        foreach ( $entries as $entry ) {
            echo '<tr><td>' . esc_html( (string) ( $entry['timestamp'] ?? '' ) ) . '</td><td><code>' . esc_html( (string) ( $entry['action'] ?? '' ) ) . '</code></td><td>' . esc_html( (string) ( $entry['object_type'] ?? '' ) . ' #' . (string) ( $entry['object_id'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $entry['actor'] ?? 0 ) ) . '</td><td><code>' . esc_html( substr( (string) ( $entry['before_hash'] ?? '' ), 0, 10 ) ) . '</code></td><td><code>' . esc_html( substr( (string) ( $entry['after_hash'] ?? '' ), 0, 10 ) ) . '</code></td><td>';
            if ( ! empty( $entry['rollback_available'] ) ) { echo '<form method="post">'; wp_nonce_field( 'design_core_history_rollback', 'design_core_history_nonce' ); echo '<button class="button" name="design_core_rollback_entry" value="' . esc_attr( (string) $entry['id'] ) . '">Rollback</button></form>'; }
            elseif ( ! empty( $entry['rolled_back_at'] ) ) { echo 'Rolled back'; } else { echo '—'; }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function render_agent_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $gateway = new Design_Core_Elementor_Agent_Gateway(); $catalog = $gateway->catalog();
        $figma = new Design_Core_Elementor_Figma_Transport();
        echo '<div class="wrap design-core-admin-shell design-core-intelligence-page"><h1>Agent Bridge v' . esc_html( (string) Design_Core_Elementor_Agent_Gateway::VERSION ) . '</h1><p>Compact discover → schema → execute tool surface shared by REST and optional WordPress Abilities.</p>';
        echo '<div class="design-core-card"><h2>Runtime</h2><p>Abilities API: <strong>' . esc_html( function_exists( 'wp_register_ability' ) ? 'available' : 'not available on this WordPress version' ) . '</strong> · REST gateway: <strong>available</strong> · Figma transport: <strong>' . esc_html( $figma->configured() ? 'configured' : 'not configured' ) . '</strong></p></div>';
        echo '<table class="widefat striped"><thead><tr><th>Tool</th><th>Description</th><th>Read-only</th><th>Destructive</th></tr></thead><tbody>';
        foreach ( $catalog['tools'] as $tool ) { echo '<tr><td><code>' . esc_html( $tool['name'] ) . '</code></td><td>' . esc_html( $tool['description'] ) . '</td><td>' . esc_html( $tool['readonly'] ? 'Yes' : 'No' ) . '</td><td>' . esc_html( $tool['destructive'] ? 'Yes' : 'No' ) . '</td></tr>'; }
        echo '</tbody></table></div>';
    }

    private function render_preview_result( $result ) {
        if ( is_wp_error( $result ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'; return; }
        $simulation = (array) ( $result['execution_simulation']['summary'] ?? array() );
        echo '<h2>Dry-run result</h2><div class="design-core-widget-stats design-core-intelligence-stats">';
        $this->stat( 'Roots', $result['root_count'] ?? 0, 'planned' ); $this->stat( 'Elements', $result['estimated_element_count'] ?? 0, 'strategy simulated' ); $this->stat( 'Widgets', $simulation['widgets'] ?? 0, count( (array) ( $simulation['widget_types'] ?? array() ) ) . ' predicted types' ); $this->stat( 'Warnings', count( (array) ( $result['warnings'] ?? array() ) ), 'preflight' ); $this->stat( 'Safe', ! empty( $result['can_execute_safely'] ) ? 'Yes' : 'No', 'no mutation' );
        echo '</div>'; $this->json_panel( 'BuildPlan preview v' . (int) ( $result['schema_version'] ?? 0 ), $result );
    }

    private function stat( $label, $value, $note ) { echo '<div class="design-core-widget-stat"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong><small>' . esc_html( $note ) . '</small></div>'; }
    private function json_panel( $title, array $data ) { echo '<details class="design-core-json-panel" open><summary>' . esc_html( $title ) . '</summary><pre>' . esc_html( wp_json_encode( Design_Core_Elementor_Change_Ledger::transport_safe( $data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>'; }
}
