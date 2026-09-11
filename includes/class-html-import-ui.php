<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Design_Core_Elementor_HTML_Import_UI {
    public function render_import_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'design-core-elementor' ) );
        }

        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['convert_html'] ) ) {
            $this->render_conversion_result();
            return;
        }

        $this->render_import_form();
    }

    private function render_import_form() {
        ?>
        <div class="wrap design-core-admin-shell design-core-html-import">
            <h1><?php esc_html_e( 'HTML to Elementor Converter', 'design-core-elementor' ); ?></h1>
            <p><?php esc_html_e( 'Analyze HTML/CSS, reuse Design Core components, and build editable Elementor structures.', 'design-core-elementor' ); ?></p>
            <div class="design-core-card">
                <form method="post">
                    <?php wp_nonce_field( 'design_core_html_import', 'design_core_nonce' ); ?>
                    <p><label><strong><?php esc_html_e( 'Page Title', 'design-core-elementor' ); ?></strong></label><br>
                    <input type="text" name="page_title" class="regular-text" value="<?php echo isset( $_POST['page_title'] ) ? esc_attr( wp_unslash( $_POST['page_title'] ) ) : ''; ?>"></p>
                    <p><label><strong><?php esc_html_e( 'HTML Content', 'design-core-elementor' ); ?></strong></label><br>
                    <textarea name="html_content" rows="16" class="large-text code"><?php echo isset( $_POST['html_content'] ) ? esc_textarea( wp_unslash( $_POST['html_content'] ) ) : ''; ?></textarea></p>
                    <p><label><strong><?php esc_html_e( 'Associated CSS', 'design-core-elementor' ); ?></strong></label><br>
                    <textarea name="css_content" rows="12" class="large-text code"><?php echo isset( $_POST['css_content'] ) ? esc_textarea( wp_unslash( $_POST['css_content'] ) ) : ''; ?></textarea></p>
                    <p><label><input type="checkbox" name="analyze_only" value="1"> <?php esc_html_e( 'Analyze only (do not create page)', 'design-core-elementor' ); ?></label></p>
                    <p><button type="submit" name="convert_html" value="1" class="button button-primary button-large"><?php esc_html_e( 'Convert to Elementor', 'design-core-elementor' ); ?></button></p>
                </form>
            </div>
        </div>
        <?php
    }

    private function render_conversion_result() {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['design_core_nonce'] ?? '' ) ), 'design_core_html_import' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'design-core-elementor' ) );
        }

        $page_title = sanitize_text_field( wp_unslash( $_POST['page_title'] ?? 'Imported Page' ) );
        // Keep the source intact for analysis. It is never executed directly by this screen.
        $html = wp_unslash( $_POST['html_content'] ?? '' );
        $css = wp_unslash( $_POST['css_content'] ?? '' );
        $analyze_only = ! empty( $_POST['analyze_only'] );

        if ( '' === trim( $html ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Please provide HTML content.', 'design-core-elementor' ) . '</p></div>';
            $this->render_import_form();
            return;
        }

        $analysis_engine = new Design_Core_Elementor_Analysis_Engine();
        $analysis = $analysis_engine->analyze_html( $html, $css );
        $result = null;

        if ( ! $analyze_only ) {
            $converter = new Design_Core_Elementor_HTML_Converter();
            $result = $converter->convert_to_elementor( $html, $css, $page_title );
            if ( 'success' === ( $result['status'] ?? '' ) ) {
                $edit_url = admin_url( 'post.php?post=' . intval( $result['page_id'] ) . '&action=elementor' );
                echo '<div class="notice notice-success"><p>' . esc_html__( 'Page created successfully.', 'design-core-elementor' ) . ' <a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Open in Elementor', 'design-core-elementor' ) . '</a></p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html( $result['error'] ?? __( 'Failed to create page.', 'design-core-elementor' ) ) . '</p></div>';
            }
        }

        ?>
        <div class="wrap design-core-admin-shell design-core-html-import">
            <div class="design-core-card">
                <h2><?php esc_html_e( 'Analysis Results', 'design-core-elementor' ); ?></h2>
                <p><strong><?php esc_html_e( 'Sections:', 'design-core-elementor' ); ?></strong> <?php echo esc_html( (string) ( $analysis['summary']['section_count'] ?? 0 ) ); ?></p>
                <p><strong><?php esc_html_e( 'Tokens:', 'design-core-elementor' ); ?></strong> <?php echo esc_html( (string) ( $analysis['summary']['token_count'] ?? 0 ) ); ?></p>
                <p><strong><?php esc_html_e( 'Repeated components:', 'design-core-elementor' ); ?></strong> <?php echo esc_html( (string) ( $analysis['summary']['component_count'] ?? 0 ) ); ?></p>
                <?php if ( is_array( $result ) && ! empty( $result['editor_mode'] ) ) : ?>
                    <p><strong><?php esc_html_e( 'Elementor mode:', 'design-core-elementor' ); ?></strong> <?php echo esc_html( $result['editor_mode'] ); ?></p>
                <?php endif; ?>
            </div>
            <p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=design-core-html-import' ) ); ?>"><?php esc_html_e( 'Convert another page', 'design-core-elementor' ); ?></a></p>
        </div>
        <?php
    }
}
