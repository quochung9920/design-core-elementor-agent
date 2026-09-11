<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Design_Core_Elementor_Design_Runs_Admin {
    const SLUG = 'design-core-design-runs';
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) { return; }
        self::$booted = true;
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 99 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function register_menu() {
        add_submenu_page(
            'design-core-elementor',
            'Design Runs',
            'Design Runs',
            'manage_options',
            self::SLUG,
            array( __CLASS__, 'render' )
        );
        self::move_after_registry();
    }

    public static function enqueue_assets() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( self::SLUG !== $page ) { return; }
        wp_enqueue_style( 'design-core-design-runs', DESIGN_CORE_ELEMENTOR_URL . 'assets/admin-design-runs.css', array(), DESIGN_CORE_ELEMENTOR_VERSION );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $run_id = isset( $_GET['run'] ) ? sanitize_key( wp_unslash( $_GET['run'] ) ) : '';
        echo '<div class="wrap dc-runs-admin">';
        if ( $run_id ) { self::render_detail( $run_id ); }
        else { self::render_list(); }
        echo '</div>';
    }

    private static function render_list() {
        $result = Design_Core_Elementor_Design_Run_Trace::list_runs( array( 'limit' => 50, 'page' => 1 ) );
        echo '<div class="dc-runs-heading"><div><h1>Design Runs</h1><p>End-to-end provenance for ChatGPT/agent analysis, widget decisions, control mapping, builds, QA, corrections and promotion.</p></div>';
        echo '<div class="dc-runs-active">Active run: <code>' . esc_html( Design_Core_Elementor_Design_Run_Trace::active_run_id() ?: 'none' ) . '</code></div></div>';
        if ( is_wp_error( $result ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'; return; }
        $runs = (array) ( $result['runs'] ?? array() );
        if ( ! $runs ) { echo '<div class="dc-runs-empty"><h2>No Design Runs yet</h2><p>Start one through <code>design-core/agent-run-start</code>. Design Core will then automatically trace Agent and Semantic operations into that run.</p></div>'; return; }

        echo '<table class="widefat striped dc-runs-table"><thead><tr><th>Run</th><th>Page</th><th>Phase</th><th>Semantic</th><th>Structural</th><th>Visual</th><th>Interaction</th><th>Status</th><th>Started</th></tr></thead><tbody>';
        foreach ( $runs as $run ) {
            $summary = (array) ( $run['summary'] ?? array() );
            $qa = (array) ( $summary['qa'] ?? array() );
            $url = admin_url( 'admin.php?page=' . self::SLUG . '&run=' . rawurlencode( (string) $run['run_id'] ) );
            echo '<tr>';
            echo '<td><a class="dc-run-link" href="' . esc_url( $url ) . '"><strong>' . esc_html( (string) $run['task'] ) . '</strong><code>' . esc_html( (string) $run['run_id'] ) . '</code></a></td>';
            echo '<td>' . esc_html( $run['page_title'] ?: ( $run['page_id'] ? '#' . $run['page_id'] : '—' ) ) . '</td>';
            echo '<td><span class="dc-run-phase">' . esc_html( self::label( (string) ( $summary['current_phase'] ?? $run['current_phase'] ) ) ) . '</span></td>';
            echo '<td>' . self::status_badge( $qa['semantic'] ?? 'not_verified' ) . '</td>';
            echo '<td>' . self::status_badge( $qa['structural'] ?? 'not_verified' ) . '</td>';
            echo '<td>' . self::status_badge( $qa['visual'] ?? 'not_verified' ) . '</td>';
            echo '<td>' . self::status_badge( $qa['interaction'] ?? 'not_verified' ) . '</td>';
            echo '<td>' . self::status_badge( $run['status'] ?? 'running' ) . '</td>';
            echo '<td>' . esc_html( self::display_time( $run['started_at'] ?? '' ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_detail( $run_id ) {
        $result = Design_Core_Elementor_Design_Run_Trace::get( $run_id );
        if ( is_wp_error( $result ) ) { echo '<h1>Design Run</h1><div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'; return; }
        $run = (array) $result['run'];
        $summary = (array) $run['summary'];
        $qa = (array) $summary['qa'];
        $back = admin_url( 'admin.php?page=' . self::SLUG );
        echo '<p><a href="' . esc_url( $back ) . '">← All Design Runs</a></p>';
        echo '<div class="dc-run-hero"><div><h1>' . esc_html( (string) $run['task'] ) . '</h1><p><code>' . esc_html( (string) $run['run_id'] ) . '</code> · client <strong>' . esc_html( (string) $run['client'] ) . '</strong> · ' . esc_html( (string) $run['event_count'] ) . ' events</p></div><div class="dc-run-ready">' . ( ! empty( $summary['promotion_ready'] ) ? '<span class="dc-ready-yes">READY FOR PROMOTION</span>' : '<span class="dc-ready-no">PROMOTION LOCKED</span>' ) . '</div></div>';
        self::render_progress( $summary );
        self::render_qa_cards( $qa, $summary );

        $tabs = array(
            'timeline' => 'Timeline', 'components' => 'Components', 'decisions' => 'Widget Decisions',
            'controls' => 'Controls', 'artifacts' => 'Artifacts', 'qa' => 'QA',
            'mutations' => 'Mutations', 'raw' => 'Raw Events',
        );
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'timeline';
        if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'timeline'; }
        echo '<nav class="nav-tab-wrapper dc-run-tabs">';
        foreach ( $tabs as $slug => $label ) {
            $url = admin_url( 'admin.php?page=' . self::SLUG . '&run=' . rawurlencode( $run_id ) . '&tab=' . $slug );
            echo '<a class="nav-tab ' . ( $slug === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav><div class="dc-run-panel">';
        if ( 'timeline' === $tab ) { self::render_events( $run_id, array( 'limit' => 200 ), false ); }
        elseif ( 'components' === $tab ) { self::render_components( $run_id ); }
        elseif ( 'decisions' === $tab ) { self::render_events( $run_id, array( 'phase' => 'widget_selection', 'limit' => 250 ), true ); }
        elseif ( 'controls' === $tab ) { self::render_controls( $run_id ); }
        elseif ( 'artifacts' === $tab ) { self::render_artifacts( $run_id ); }
        elseif ( 'qa' === $tab ) { self::render_qa_detail( $run ); }
        elseif ( 'mutations' === $tab ) { self::render_mutations( $run_id ); }
        elseif ( 'raw' === $tab ) { self::render_raw( $run_id ); }
        echo '</div>';
    }

    private static function render_progress( array $summary ) {
        $ordered = array( 'source', 'source_inventory', 'component_graph', 'semantic_planning', 'widget_selection', 'control_mapping', 'compile', 'draft_write', 'visual_qa', 'interaction_qa', 'promotion' );
        $phases = (array) ( $summary['phases'] ?? array() );
        echo '<div class="dc-run-progress">';
        foreach ( $ordered as $phase ) {
            $status = (string) ( $phases[ $phase ] ?? 'pending' );
            echo '<div class="dc-run-step dc-status-' . esc_attr( $status ) . '"><span>' . esc_html( self::label( $phase ) ) . '</span><strong>' . esc_html( self::symbol( $status ) ) . '</strong></div>';
        }
        echo '</div>';
    }

    private static function render_qa_cards( array $qa, array $summary ) {
        echo '<div class="dc-run-qa-grid">';
        foreach ( array( 'semantic' => 'Semantic', 'structural' => 'Structural', 'visual' => 'Visual', 'interaction' => 'Interaction', 'editability' => 'Editability' ) as $key => $label ) {
            echo '<div class="dc-run-qa-card"><span>' . esc_html( $label ) . '</span>' . self::status_badge( $qa[ $key ] ?? 'not_verified' ) . '</div>';
        }
        echo '<div class="dc-run-qa-card"><span>Promotion</span>' . self::status_badge( ! empty( $summary['promotion_ready'] ) ? 'pass' : 'blocked' ) . '</div></div>';
    }

    private static function render_events( $run_id, array $filters, $show_decision ) {
        $result = Design_Core_Elementor_Design_Run_Trace::events( $run_id, $filters );
        if ( is_wp_error( $result ) ) { echo '<p>' . esc_html( $result->get_error_message() ) . '</p>'; return; }
        $events = (array) $result['events'];
        if ( ! $events ) { echo '<p>No matching events.</p>'; return; }
        echo '<table class="widefat striped dc-run-events"><thead><tr><th>#</th><th>Time</th><th>Actor</th><th>Phase</th><th>Status</th><th>Operation</th><th>Component</th><th>Summary</th>' . ( $show_decision ? '<th>Decision</th>' : '' ) . '</tr></thead><tbody>';
        foreach ( $events as $event ) {
            echo '<tr><td>' . esc_html( (string) ( $event['seq'] ?? '' ) ) . '</td><td>' . esc_html( self::display_time( $event['timestamp'] ?? '' ) ) . '</td><td><code>' . esc_html( (string) ( $event['actor'] ?? '' ) ) . '</code></td><td>' . esc_html( self::label( $event['phase'] ?? '' ) ) . '</td><td>' . self::status_badge( $event['status'] ?? 'not_verified' ) . '</td><td><code>' . esc_html( (string) ( $event['operation'] ?? '' ) ) . '</code></td><td>' . esc_html( (string) ( $event['component_id'] ?: $event['component_type'] ?? '—' ) ) . '</td><td><strong>' . esc_html( (string) ( $event['summary'] ?? '' ) ) . '</strong>' . ( ! empty( $event['rationale'] ) ? '<p>' . esc_html( (string) $event['rationale'] ) . '</p>' : '' ) . '</td>';
            if ( $show_decision ) { echo '<td><code>' . esc_html( self::compact_json( $event['decision'] ?? array() ) ) . '</code></td>'; }
            echo '</tr>';
        }
        echo '</tbody></table>';
        if ( ! empty( $result['has_more'] ) ) { echo '<p><em>More events exist; narrow the run filter to paginate.</em></p>'; }
    }

    private static function render_components( $run_id ) {
        $result = Design_Core_Elementor_Design_Run_Trace::components( $run_id );
        if ( is_wp_error( $result ) ) { echo '<p>' . esc_html( $result->get_error_message() ) . '</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Component</th><th>Type</th><th>Selected Widget</th><th>Implementation</th><th>Last Phase</th><th>Status</th><th>Events</th></tr></thead><tbody>';
        foreach ( (array) $result['components'] as $component ) {
            echo '<tr><td><code>' . esc_html( (string) ( $component['component_id'] ?: '—' ) ) . '</code></td><td>' . esc_html( self::label( $component['component_type'] ?? '' ) ) . '</td><td><strong>' . esc_html( (string) ( $component['selected_widget'] ?: '—' ) ) . '</strong></td><td>' . esc_html( self::label( $component['implementation_type'] ?? '' ) ) . '</td><td>' . esc_html( self::label( $component['last_phase'] ?? '' ) ) . '</td><td>' . self::status_badge( $component['last_status'] ?? 'not_verified' ) . '</td><td>' . esc_html( (string) $component['event_count'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_controls( $run_id ) {
        $mapping = Design_Core_Elementor_Design_Run_Trace::events( $run_id, array( 'phase' => 'control_mapping', 'limit' => 250 ) );
        $coverage = Design_Core_Elementor_Design_Run_Trace::events( $run_id, array( 'phase' => 'control_coverage', 'limit' => 250 ) );
        echo '<h2>Control Coverage</h2>';
        self::render_event_cards( is_wp_error( $coverage ) ? array() : (array) $coverage['events'] );
        echo '<h2>Source → Elementor Control Mapping</h2>';
        self::render_event_cards( is_wp_error( $mapping ) ? array() : (array) $mapping['events'] );
    }

    private static function render_artifacts( $run_id ) {
        $result = Design_Core_Elementor_Design_Run_Trace::artifacts( $run_id );
        if ( is_wp_error( $result ) ) { echo '<p>' . esc_html( $result->get_error_message() ) . '</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Artifact Hash</th><th>Preview</th><th>Events</th><th>Semantic</th><th>Structural</th><th>Visual</th><th>Interaction</th><th>First / Last</th></tr></thead><tbody>';
        foreach ( (array) $result['artifacts'] as $artifact ) {
            $qa = (array) $artifact['qa'];
            echo '<tr><td><code>' . esc_html( (string) $artifact['artifact_hash'] ) . '</code></td><td><code>' . esc_html( (string) ( $artifact['preview_id'] ?: '—' ) ) . '</code></td><td>' . esc_html( (string) $artifact['event_count'] ) . '</td><td>' . self::status_badge( $qa['semantic'] ) . '</td><td>' . self::status_badge( $qa['structural'] ) . '</td><td>' . self::status_badge( $qa['visual'] ) . '</td><td>' . self::status_badge( $qa['interaction'] ) . '</td><td>' . esc_html( self::display_time( $artifact['first_at'] ) . ' / ' . self::display_time( $artifact['last_at'] ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_qa_detail( array $run ) {
        $summary = (array) $run['summary'];
        echo '<h2>Promotion readiness</h2><p>' . ( ! empty( $summary['promotion_ready'] ) ? '<strong class="dc-ready-yes">All required provenance is present for the latest artifact.</strong>' : '<strong class="dc-ready-no">Not ready.</strong>' ) . '</p>';
        if ( ! empty( $summary['promotion_missing'] ) ) { echo '<p>Missing or stale: <code>' . esc_html( implode( ', ', (array) $summary['promotion_missing'] ) ) . '</code></p>'; }
        echo '<h2>Evidence identity</h2><table class="widefat striped"><tbody>';
        foreach ( array( 'source_hash' => $run['source_hash'], 'source_id' => $run['source_id'], 'latest_artifact_hash' => $run['latest_artifact_hash'], 'started_at' => $run['started_at'], 'finished_at' => $run['finished_at'] ) as $key => $value ) { echo '<tr><th>' . esc_html( self::label( $key ) ) . '</th><td><code>' . esc_html( (string) ( $value ?: '—' ) ) . '</code></td></tr>'; }
        echo '</tbody></table>';
    }

    private static function render_mutations( $run_id ) {
        $result = Design_Core_Elementor_Design_Run_Trace::events( $run_id, array( 'limit' => 250 ) );
        if ( is_wp_error( $result ) ) { echo '<p>' . esc_html( $result->get_error_message() ) . '</p>'; return; }
        $rows = array_filter( (array) $result['events'], static function ( $event ) {
            $meta = (array) ( $event['metadata'] ?? array() );
            return ! empty( $meta['history_entry_id'] ) || in_array( (string) ( $event['phase'] ?? '' ), array( 'draft_write', 'promotion' ), true );
        } );
        if ( ! $rows ) { echo '<p>No mutation evidence has been linked to this run yet.</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Phase</th><th>Operation</th><th>Status</th><th>History Entry</th><th>Artifact</th></tr></thead><tbody>';
        foreach ( $rows as $event ) { $meta = (array) ( $event['metadata'] ?? array() ); echo '<tr><td>' . esc_html( self::display_time( $event['timestamp'] ?? '' ) ) . '</td><td>' . esc_html( self::label( $event['phase'] ?? '' ) ) . '</td><td><code>' . esc_html( (string) ( $event['operation'] ?? '' ) ) . '</code></td><td>' . self::status_badge( $event['status'] ?? '' ) . '</td><td><code>' . esc_html( (string) ( $meta['history_entry_id'] ?? '—' ) ) . '</code></td><td><code>' . esc_html( (string) ( $event['artifact_hash'] ?: '—' ) ) . '</code></td></tr>'; }
        echo '</tbody></table>';
    }

    private static function render_raw( $run_id ) {
        $result = Design_Core_Elementor_Design_Run_Trace::events( $run_id, array( 'limit' => 250 ) );
        if ( is_wp_error( $result ) ) { echo '<p>' . esc_html( $result->get_error_message() ) . '</p>'; return; }
        foreach ( (array) $result['events'] as $event ) { echo '<details class="dc-run-raw"><summary>#' . esc_html( (string) $event['seq'] ) . ' · ' . esc_html( (string) $event['event_type'] ) . ' · ' . esc_html( (string) $event['status'] ) . '</summary><pre>' . esc_html( wp_json_encode( $event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>'; }
    }

    private static function render_event_cards( array $events ) {
        if ( ! $events ) { echo '<p>No explicit evidence recorded for this phase yet.</p>'; return; }
        echo '<div class="dc-run-card-grid">';
        foreach ( $events as $event ) { echo '<article class="dc-run-card"><div class="dc-run-card-head"><strong>' . esc_html( (string) ( $event['component_id'] ?: $event['component_type'] ?: $event['event_type'] ) ) . '</strong>' . self::status_badge( $event['status'] ) . '</div><p>' . esc_html( (string) $event['summary'] ) . '</p>'; if ( ! empty( $event['rationale'] ) ) { echo '<p class="dc-run-rationale">' . esc_html( (string) $event['rationale'] ) . '</p>'; } if ( ! empty( $event['decision'] ) ) { echo '<pre>' . esc_html( wp_json_encode( $event['decision'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>'; } if ( ! empty( $event['metrics'] ) ) { echo '<pre>' . esc_html( wp_json_encode( $event['metrics'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>'; } echo '</article>'; }
        echo '</div>';
    }

    private static function move_after_registry() {
        global $submenu;
        if ( empty( $submenu['design-core-elementor'] ) || ! is_array( $submenu['design-core-elementor'] ) ) { return; }
        $items = $submenu['design-core-elementor'];
        $run = null; $out = array();
        foreach ( $items as $item ) { if ( isset( $item[2] ) && self::SLUG === $item[2] ) { $run = $item; continue; } $out[] = $item; }
        if ( ! $run ) { return; }
        $rebuilt = array(); $inserted = false;
        foreach ( $out as $item ) { $rebuilt[] = $item; if ( isset( $item[2] ) && 'design-core-registry' === $item[2] ) { $rebuilt[] = $run; $inserted = true; } }
        if ( ! $inserted ) { $rebuilt[] = $run; }
        $submenu['design-core-elementor'] = $rebuilt;
    }

    private static function status_badge( $status ) {
        $status = sanitize_key( (string) $status ) ?: 'not_verified';
        return '<span class="dc-run-badge dc-status-' . esc_attr( $status ) . '">' . esc_html( strtoupper( str_replace( '_', ' ', $status ) ) ) . '</span>';
    }
    private static function label( $value ) { return ucwords( str_replace( array( '_', '-' ), ' ', (string) $value ) ); }
    private static function symbol( $status ) { $map = array( 'pass' => '✓', 'completed' => '✓', 'warning' => '!', 'fail' => '×', 'blocked' => '🔒', 'not_verified' => '·', 'pending' => '·', 'stale' => '↻', 'running' => '…' ); return $map[ $status ] ?? '·'; }
    private static function display_time( $value ) { if ( ! $value ) { return '—'; } $time = strtotime( (string) $value ); return $time ? gmdate( 'Y-m-d H:i:s', $time ) . ' UTC' : (string) $value; }
    private static function compact_json( $value ) { if ( empty( $value ) ) { return '—'; } $json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); return strlen( $json ) > 600 ? substr( $json, 0, 599 ) . '…' : $json; }
}
