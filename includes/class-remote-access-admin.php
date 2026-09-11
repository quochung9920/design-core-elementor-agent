<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Design Core -> Remote Access: MCP/remote API status, the write kill switch, and machine credential lifecycle. */
class Design_Core_Elementor_Remote_Access_Admin {
    const SLUG = 'design-core-remote-access';

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized', 'design-core-elementor' ) ); }

        $registry = new Design_Core_Elementor_Machine_Credential_Registry();
        $created_token = ''; $created_name = ''; $notice = '';

        if ( isset( $_POST['design_core_save_remote_settings'] ) && check_admin_referer( 'design_core_remote_settings', 'design_core_remote_settings_nonce' ) ) {
            Design_Core_Elementor_Remote_Settings::save( array(
                'write_enabled' => ! empty( $_POST['write_enabled'] ),
                'environment' => isset( $_POST['environment'] ) ? sanitize_key( wp_unslash( $_POST['environment'] ) ) : 'local',
            ) );
            $notice = 'Remote Access settings saved.';
        }

        if ( isset( $_POST['design_core_create_credential'] ) && check_admin_referer( 'design_core_create_credential', 'design_core_create_credential_nonce' ) ) {
            $name = isset( $_POST['credential_name'] ) ? sanitize_text_field( wp_unslash( $_POST['credential_name'] ) ) : '';
            $scopes = isset( $_POST['scopes'] ) && is_array( $_POST['scopes'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['scopes'] ) ) : array();
            $created = $registry->create( array( 'name' => $name, 'scopes' => $scopes, 'environment' => Design_Core_Elementor_Remote_Settings::environment() ) );
            if ( is_wp_error( $created ) ) { $notice = $created->get_error_message(); }
            else { $created_token = $created['token']; $created_name = $created['credential']['name']; }
        }

        if ( isset( $_POST['design_core_revoke_credential'] ) && check_admin_referer( 'design_core_revoke_credential', 'design_core_revoke_credential_nonce' ) ) {
            $result = $registry->revoke( sanitize_key( wp_unslash( $_POST['design_core_revoke_credential'] ) ) );
            $notice = is_wp_error( $result ) ? $result->get_error_message() : 'Credential revoked.';
        }

        if ( isset( $_POST['design_core_rotate_credential'] ) && check_admin_referer( 'design_core_rotate_credential', 'design_core_rotate_credential_nonce' ) ) {
            $rotated = $registry->rotate( sanitize_key( wp_unslash( $_POST['design_core_rotate_credential'] ) ) );
            if ( is_wp_error( $rotated ) ) { $notice = $rotated->get_error_message(); }
            else { $created_token = $rotated['token']; $created_name = $rotated['credential']['name'] . ' (rotated)'; }
        }

        $status = ( new Design_Core_Elementor_Site_Status() )->report();
        $settings = Design_Core_Elementor_Remote_Settings::get();
        $credentials = $registry->list_public();

        echo '<div class="wrap design-core-admin-shell design-core-remote-access"><h1>Design Core Remote Access</h1><p>MCP / remote API access for ChatGPT and other machine clients, gated separately from wp-admin sessions.</p>';
        if ( $notice ) { echo '<div class="notice notice-info inline"><p>' . esc_html( $notice ) . '</p></div>'; }
        if ( $created_token ) {
            echo '<div class="notice notice-warning inline"><p><strong>Token for "' . esc_html( $created_name ) . '"</strong> -- shown once, copy it now. It cannot be retrieved again; rotate the credential if it is lost.</p><p><code style="user-select:all;word-break:break-all;">' . esc_html( $created_token ) . '</code></p></div>';
        }

        echo '<div class="design-core-card"><h2>Status</h2><div class="design-core-widget-stats design-core-intelligence-stats">';
        $this->stat( 'MCP API', 'v' . (int) $status['mcp_api_version'], $status['plugin_version'] );
        $this->stat( 'Environment', $settings['environment'], 'site binding' );
        $this->stat( 'Remote writes', $settings['write_enabled'] ? 'Enabled' : 'Disabled', 'reads always allowed' );
        $this->stat( 'Editor mode', $status['editor_mode'], 'Elementor' );
        $this->stat( 'Readiness', $status['production_readiness_status'], 'production readiness' );
        echo '</div></div>';

        echo '<div class="design-core-card"><h2>Remote write settings</h2><form method="post">';
        wp_nonce_field( 'design_core_remote_settings', 'design_core_remote_settings_nonce' );
        echo '<p><label>Environment <select name="environment">';
        foreach ( Design_Core_Elementor_Remote_Settings::ENVIRONMENTS as $environment ) { echo '<option value="' . esc_attr( $environment ) . '"' . selected( $settings['environment'], $environment, false ) . '>' . esc_html( ucfirst( $environment ) ) . '</option>'; }
        echo '</select></label></p>';
        echo '<p><label><input type="checkbox" name="write_enabled" value="1"' . checked( $settings['write_enabled'], true, false ) . '> Allow remote writes (update / auto-correct / publish / rollback)</label></p>';
        echo '<p class="description">Read and preview routes always work regardless of this setting. Disabling it immediately blocks every remote write and destructive route with HTTP 423, without touching wp-admin.</p>';
        echo '<p><button class="button button-primary" name="design_core_save_remote_settings" value="1">Save</button></p></form></div>';

        echo '<div class="design-core-card"><h2>Machine Credentials</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Scopes</th><th>Environment</th><th>Created</th><th>Last used</th><th>Status</th><th></th></tr></thead><tbody>';
        if ( ! $credentials ) { echo '<tr><td colspan="7">No machine credentials yet.</td></tr>'; }
        foreach ( $credentials as $credential ) {
            echo '<tr><td>' . esc_html( $credential['name'] ) . '</td><td>' . esc_html( implode( ', ', $credential['scopes'] ) ) . '</td><td>' . esc_html( $credential['environment'] ) . '</td><td>' . esc_html( $credential['created_at'] ) . '</td><td>' . esc_html( $credential['last_used_at'] ?: '—' ) . '</td><td>' . ( $credential['active'] ? 'Active' : 'Revoked' ) . '</td><td>';
            if ( $credential['active'] ) {
                echo '<form method="post" style="display:inline"><input type="hidden" name="design_core_rotate_credential" value="' . esc_attr( $credential['id'] ) . '">'; wp_nonce_field( 'design_core_rotate_credential', 'design_core_rotate_credential_nonce' ); echo '<button class="button">Rotate</button></form> ';
                echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Revoke this credential? Any client using it will lose access immediately.\');"><input type="hidden" name="design_core_revoke_credential" value="' . esc_attr( $credential['id'] ) . '">'; wp_nonce_field( 'design_core_revoke_credential', 'design_core_revoke_credential_nonce' ); echo '<button class="button">Revoke</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h3>Create credential</h3><form method="post" class="design-core-intelligence-grid">';
        wp_nonce_field( 'design_core_create_credential', 'design_core_create_credential_nonce' );
        echo '<label>Name <input type="text" name="credential_name" required placeholder="e.g. ChatGPT MCP bridge"></label>';
        echo '<fieldset><legend>Scopes</legend>';
        foreach ( Design_Core_Elementor_Capabilities::all() as $capability ) { echo '<label style="display:block"><input type="checkbox" name="scopes[]" value="' . esc_attr( $capability ) . '"> ' . esc_html( $capability ) . '</label>'; }
        echo '</fieldset>';
        echo '<div class="design-core-intelligence-action"><button class="button button-primary" name="design_core_create_credential" value="1">Create credential</button></div></form></div></div>';
    }

    private function stat( $label, $value, $note ) { echo '<div class="design-core-widget-stat"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong><small>' . esc_html( $note ) . '</small></div>'; }
}
