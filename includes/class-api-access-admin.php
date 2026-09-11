<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** WordPress admin UI for the owner-only REST/GPT Actions API. */
class Design_Core_Elementor_API_Access_Admin {
    const SLUG = 'design-core-api-access';

    public static function boot() {
        add_action( 'admin_menu', static function () {
            add_submenu_page( 'design-core-elementor', 'API Access', 'API Access', 'manage_options', self::SLUG, array( new self(), 'render_page' ) );
        }, 20 );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized', 'design-core-elementor' ) ); }

        $notice = '';
        $created_token = '';
        $created_name = '';
        $settings = Design_Core_Elementor_API_Access_Settings::get();
        $owner_id = (int) ( $settings['owner_user_id'] ?? 0 );
        $current_id = (int) get_current_user_id();

        if ( isset( $_POST['design_core_claim_api_owner'] ) && check_admin_referer( 'design_core_claim_api_owner', 'design_core_claim_api_owner_nonce' ) ) {
            $result = Design_Core_Elementor_API_Access_Settings::claim_for_current_user();
            $notice = is_wp_error( $result ) ? $result->get_error_message() : 'Design Core API ownership is now locked to your WordPress user.';
        }

        $settings = Design_Core_Elementor_API_Access_Settings::get();
        $owner_id = (int) ( $settings['owner_user_id'] ?? 0 );
        $is_owner = $owner_id > 0 && $owner_id === $current_id;

        if ( $is_owner && isset( $_POST['design_core_save_owner_api'] ) && check_admin_referer( 'design_core_save_owner_api', 'design_core_save_owner_api_nonce' ) ) {
            $result = Design_Core_Elementor_API_Access_Settings::set_enabled_for_current_owner( ! empty( $_POST['api_enabled'] ) );
            $notice = is_wp_error( $result ) ? $result->get_error_message() : 'Owner API settings saved.';
        }

        $registry = new Design_Core_Elementor_API_Credential_Registry();
        if ( $is_owner && isset( $_POST['design_core_create_api_credential'] ) && check_admin_referer( 'design_core_create_api_credential', 'design_core_create_api_credential_nonce' ) ) {
            $scopes = isset( $_POST['scopes'] ) && is_array( $_POST['scopes'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['scopes'] ) ) : array();
            $created = $registry->create( array(
                'name' => sanitize_text_field( wp_unslash( $_POST['credential_name'] ?? 'ChatGPT Web' ) ),
                'scopes' => $scopes,
                'environment' => Design_Core_Elementor_Remote_Settings::environment(),
                'expires_days' => max( 1, min( 365, (int) ( $_POST['expires_days'] ?? 90 ) ) ),
            ) );
            if ( is_wp_error( $created ) ) { $notice = $created->get_error_message(); }
            else { $created_token = $created['token']; $created_name = $created['credential']['name']; }
        }

        if ( $is_owner && isset( $_POST['design_core_revoke_api_credential'] ) && check_admin_referer( 'design_core_revoke_api_credential', 'design_core_revoke_api_credential_nonce' ) ) {
            $result = $registry->revoke( sanitize_key( wp_unslash( $_POST['design_core_revoke_api_credential'] ) ) );
            $notice = is_wp_error( $result ) ? $result->get_error_message() : 'API credential revoked.';
        }

        if ( $is_owner && isset( $_POST['design_core_rotate_api_credential'] ) && check_admin_referer( 'design_core_rotate_api_credential', 'design_core_rotate_api_credential_nonce' ) ) {
            $rotated = $registry->rotate( sanitize_key( wp_unslash( $_POST['design_core_rotate_api_credential'] ) ), 90 );
            if ( is_wp_error( $rotated ) ) { $notice = $rotated->get_error_message(); }
            else { $created_token = $rotated['token']; $created_name = $rotated['credential']['name'] . ' (rotated)'; }
        }

        $settings = Design_Core_Elementor_API_Access_Settings::get();
        $owner_id = (int) ( $settings['owner_user_id'] ?? 0 );
        $is_owner = $owner_id > 0 && $owner_id === $current_id;
        $credentials = $registry->list_public();
        $openapi_url = rest_url( Design_Core_Elementor_GPT_Actions_API::REST_NAMESPACE . '/openapi' );
        $manifest_url = rest_url( Design_Core_Elementor_GPT_Actions_API::REST_NAMESPACE . '/manifest' );

        echo '<div class="wrap design-core-admin-shell"><h1>Design Core API Access</h1>';
        echo '<p>Direct HTTPS REST API for ChatGPT GPT Actions and other owner-controlled API clients. MCP is not required.</p>';
        if ( $notice ) { echo '<div class="notice notice-info inline"><p>' . esc_html( $notice ) . '</p></div>'; }
        if ( $created_token ) {
            echo '<div class="notice notice-warning inline"><p><strong>API token for "' . esc_html( $created_name ) . '"</strong> — shown once. Copy it into the GPT Action authentication field now; it cannot be retrieved later.</p>';
            echo '<p><code style="user-select:all;word-break:break-all">' . esc_html( $created_token ) . '</code></p></div>';
        }

        echo '<div class="design-core-card"><h2>Owner lock</h2>';
        if ( $owner_id <= 0 ) {
            echo '<p>No API owner has been claimed. The API cannot authenticate until one administrator explicitly claims ownership.</p>';
            echo '<form method="post">'; wp_nonce_field( 'design_core_claim_api_owner', 'design_core_claim_api_owner_nonce' );
            echo '<button class="button button-primary" name="design_core_claim_api_owner" value="1">Claim API ownership for my user</button></form>';
        } elseif ( $is_owner ) {
            echo '<p><strong>Locked to your WordPress user.</strong> Other administrators cannot mint or manage owner API credentials.</p>';
        } else {
            echo '<p><strong>API ownership is locked to a different WordPress user.</strong> This account cannot enable the API or manage credentials.</p>';
        }
        echo '</div>';

        echo '<div class="design-core-card"><h2>API status</h2>';
        echo '<p>Namespace: <code>/wp-json/' . esc_html( Design_Core_Elementor_GPT_Actions_API::REST_NAMESPACE ) . '</code></p>';
        echo '<p>OpenAPI: <code>' . esc_html( $openapi_url ) . '</code></p>';
        echo '<p>Manifest: <code>' . esc_html( $manifest_url ) . '</code></p>';
        echo '<p>Environment: <strong>' . esc_html( Design_Core_Elementor_Remote_Settings::environment() ) . '</strong> · Remote writes: <strong>' . esc_html( Design_Core_Elementor_Remote_Settings::writes_enabled() ? 'enabled' : 'disabled' ) . '</strong></p>';
        if ( $is_owner ) {
            echo '<form method="post">'; wp_nonce_field( 'design_core_save_owner_api', 'design_core_save_owner_api_nonce' );
            echo '<label><input type="checkbox" name="api_enabled" value="1"' . checked( ! empty( $settings['enabled'] ), true, false ) . '> Enable owner-only REST API</label> ';
            echo '<button class="button button-primary" name="design_core_save_owner_api" value="1">Save API state</button></form>';
        }
        echo '</div>';

        echo '<div class="design-core-card"><h2>Owner API credentials</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Scopes</th><th>Environment</th><th>Expires</th><th>Last used</th><th>Status</th><th></th></tr></thead><tbody>';
        if ( ! $credentials ) { echo '<tr><td colspan="7">No dcapi credentials yet.</td></tr>'; }
        foreach ( $credentials as $credential ) {
            echo '<tr><td>' . esc_html( $credential['name'] ) . '</td><td>' . esc_html( implode( ', ', $credential['scopes'] ) ) . '</td><td>' . esc_html( $credential['environment'] ) . '</td><td>' . esc_html( $credential['expires_at'] ?: '—' ) . '</td><td>' . esc_html( $credential['last_used_at'] ?: '—' ) . '</td><td>' . esc_html( $credential['active'] ? 'Active' : ( $credential['expired'] ? 'Expired' : 'Revoked' ) ) . '</td><td>';
            if ( $is_owner && $credential['active'] ) {
                echo '<form method="post" style="display:inline"><input type="hidden" name="design_core_rotate_api_credential" value="' . esc_attr( $credential['id'] ) . '">'; wp_nonce_field( 'design_core_rotate_api_credential', 'design_core_rotate_api_credential_nonce' ); echo '<button class="button">Rotate</button></form> ';
                echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Revoke this API credential? ChatGPT will lose access immediately.\');"><input type="hidden" name="design_core_revoke_api_credential" value="' . esc_attr( $credential['id'] ) . '">'; wp_nonce_field( 'design_core_revoke_api_credential', 'design_core_revoke_api_credential_nonce' ); echo '<button class="button">Revoke</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        if ( $is_owner ) {
            echo '<h3>Create ChatGPT/API credential</h3><form method="post" class="design-core-intelligence-grid">';
            wp_nonce_field( 'design_core_create_api_credential', 'design_core_create_api_credential_nonce' );
            echo '<label>Name <input type="text" name="credential_name" required value="ChatGPT Web Owner"></label>';
            echo '<label>Expires in days <input type="number" name="expires_days" min="1" max="365" value="90"></label>';
            echo '<fieldset><legend>Scopes</legend>';
            foreach ( Design_Core_Elementor_Capabilities::all() as $capability ) {
                echo '<label style="display:block"><input type="checkbox" name="scopes[]" value="' . esc_attr( $capability ) . '" checked> ' . esc_html( $capability ) . '</label>';
            }
            echo '</fieldset><div class="design-core-intelligence-action"><button class="button button-primary" name="design_core_create_api_credential" value="1">Create owner API token</button></div></form>';
        }
        echo '</div>';

        echo '<div class="design-core-card"><h2>ChatGPT Web setup</h2><ol>';
        echo '<li>Create a Custom GPT and keep visibility <strong>Only me</strong>.</li>';
        echo '<li>Actions → Create new action → Authentication: <strong>API key → Bearer</strong>.</li>';
        echo '<li>Paste the <code>dcapi_...</code> token shown once above.</li>';
        echo '<li>Import or paste the OpenAPI document from <code>' . esc_html( $openapi_url ) . '</code>.</li>';
        echo '<li>Test read-only actions first, then preview/apply on a disposable draft before production use.</li></ol></div>';
        echo '</div>';
    }
}
