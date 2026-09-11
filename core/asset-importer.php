<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Asset_Importer {
    private $security;
    private $transaction;

    public function __construct( $transaction = null ) {
        $this->security = new Design_Core_Elementor_Security_Policy();
        $this->transaction = $transaction instanceof Design_Core_Elementor_Conversion_Transaction ? $transaction : null;
    }

    public function import_image_url( $url ) {
        if ( empty( $url ) || ! current_user_can( 'upload_files' ) ) { return array( 'id' => 0, 'url' => esc_url_raw( $url ) ); }
        $preflight = $this->security->preflight_remote_asset( $url );
        if ( is_wp_error( $preflight ) ) { return array( 'id' => 0, 'url' => esc_url_raw( $url ), 'error' => $preflight->get_error_code() ); }
        $url = $preflight['url'];
        $existing = $this->find_existing_by_source( $url );
        if ( $existing ) { return array( 'id' => $existing, 'url' => (string) wp_get_attachment_url( $existing ), 'reused' => true, 'dedupe' => 'source-url' ); }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url( $url, 20 );
        if ( is_wp_error( $tmp ) ) { return array( 'id' => 0, 'url' => $url, 'error' => $tmp->get_error_code() ); }

        $max = (int) apply_filters( 'design_core_elementor_max_asset_bytes', 20 * 1024 * 1024 );
        $size = @filesize( $tmp );
        if ( $max > 0 && is_numeric( $size ) && (int) $size > $max ) { @unlink( $tmp ); return array( 'id' => 0, 'url' => $url, 'error' => 'design_core_asset_too_large' ); }
        $content_hash = is_readable( $tmp ) ? hash_file( 'sha256', $tmp ) : '';
        if ( $content_hash ) {
            $duplicate = $this->find_existing_by_content_hash( $content_hash );
            if ( $duplicate ) { @unlink( $tmp ); return array( 'id' => $duplicate, 'url' => (string) wp_get_attachment_url( $duplicate ), 'reused' => true, 'dedupe' => 'content-hash' ); }
        }

        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $name = sanitize_file_name( basename( $path ) ?: 'design-core-asset' );
        $file = array( 'name' => $name, 'tmp_name' => $tmp );
        $attachment_id = media_handle_sideload( $file, 0 );
        if ( is_wp_error( $attachment_id ) ) { @unlink( $tmp ); return array( 'id' => 0, 'url' => $url, 'error' => $attachment_id->get_error_code() ); }
        update_post_meta( $attachment_id, '_design_core_source_url', esc_url_raw( $url ) );
        update_post_meta( $attachment_id, '_design_core_source_hash', hash( 'sha256', $url ) );
        if ( $content_hash ) { update_post_meta( $attachment_id, '_design_core_content_hash', $content_hash ); }
        if ( $this->transaction ) { $this->transaction->track_attachment( $attachment_id ); }
        return array( 'id' => (int) $attachment_id, 'url' => (string) wp_get_attachment_url( $attachment_id ), 'reused' => false );
    }

    private function find_existing_by_source( $url ) { return $this->find_existing_by_meta( '_design_core_source_hash', hash( 'sha256', $url ) ); }
    private function find_existing_by_content_hash( $hash ) { return $this->find_existing_by_meta( '_design_core_content_hash', $hash ); }
    private function find_existing_by_meta( $key, $value ) {
        $ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => $key, 'meta_value' => $value, 'no_found_rows' => true ) );
        return $ids ? (int) $ids[0] : 0;
    }
}
