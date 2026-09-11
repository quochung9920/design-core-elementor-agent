<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded WordPress management operations for the owner-only API.
 * Elementor-managed page content is deliberately excluded from generic post_content writes;
 * those pages must continue through Design Core preview -> apply -> verify.
 */
class Design_Core_Elementor_WordPress_Owner_Service {
    const MAX_CONTENT_ITEMS = 100;
    const MAX_MEDIA_BYTES = 20971520; // 20 MB

    public function content_index( array $args = array() ) {
        $post_type = sanitize_key( (string) ( $args['post_type'] ?? 'page' ) );
        if ( ! $this->allowed_post_type( $post_type ) ) {
            return new WP_Error( 'design_core_wp_post_type_invalid', 'Requested post_type is not allowed through the owner API.', array( 'status' => 400 ) );
        }
        $limit = min( self::MAX_CONTENT_ITEMS, max( 1, (int) ( $args['limit'] ?? 50 ) ) );
        $status = sanitize_key( (string) ( $args['status'] ?? 'any' ) );
        $allowed_statuses = array_merge( array( 'any' ), array_keys( get_post_stati( array(), 'names' ) ) );
        if ( ! in_array( $status, $allowed_statuses, true ) ) { $status = 'any'; }
        $query = array(
            'post_type' => $post_type,
            'post_status' => $status,
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => false,
        );
        $search = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
        if ( '' !== $search ) { $query['s'] = $search; }
        $posts = get_posts( $query );
        return array(
            'post_type' => $post_type,
            'limit' => $limit,
            'items' => array_map( array( $this, 'serialize_post' ), (array) $posts ),
        );
    }

    public function content_get( $post_id ) {
        $post = get_post( (int) $post_id );
        if ( ! $post || ! $this->allowed_post_type( (string) $post->post_type ) ) {
            return new WP_Error( 'design_core_wp_content_not_found', 'Requested WordPress content was not found or is not API-manageable.', array( 'status' => 404 ) );
        }
        return $this->serialize_post( $post, true );
    }

    public function content_create( array $body, $can_publish = false ) {
        $post_type = sanitize_key( (string) ( $body['post_type'] ?? 'post' ) );
        if ( ! $this->allowed_post_type( $post_type ) ) {
            return new WP_Error( 'design_core_wp_post_type_invalid', 'Requested post_type is not allowed through the owner API.', array( 'status' => 400 ) );
        }
        if ( 'page' === $post_type && ! empty( $body['elementor'] ) ) {
            return new WP_Error( 'design_core_wp_use_design_core_page_flow', 'Elementor pages must be created with the Design Core page endpoint and built through preview/apply.', array( 'status' => 409 ) );
        }
        $title = trim( sanitize_text_field( (string) ( $body['title'] ?? '' ) ) );
        if ( '' === $title ) { return new WP_Error( 'design_core_wp_title_required', 'title is required.', array( 'status' => 400 ) ); }
        $status = $this->sanitize_status( (string) ( $body['status'] ?? 'draft' ), $can_publish );
        if ( is_wp_error( $status ) ) { return $status; }
        $postarr = array(
            'post_type' => $post_type,
            'post_status' => $status,
            'post_title' => $title,
            'post_content' => wp_kses_post( (string) ( $body['content'] ?? '' ) ),
            'post_excerpt' => wp_kses_post( (string) ( $body['excerpt'] ?? '' ) ),
        );
        $slug = sanitize_title( (string) ( $body['slug'] ?? '' ) );
        if ( $slug ) { $postarr['post_name'] = $slug; }
        if ( 'page' === $post_type ) {
            $parent_id = max( 0, (int) ( $body['parent_id'] ?? 0 ) );
            if ( $parent_id > 0 && ! $this->valid_page_id( $parent_id ) ) {
                return new WP_Error( 'design_core_wp_parent_invalid', 'parent_id must reference an existing page.', array( 'status' => 400 ) );
            }
            $postarr['post_parent'] = $parent_id;
            $postarr['menu_order'] = (int) ( $body['menu_order'] ?? 0 );
        }
        $id = wp_insert_post( $postarr, true );
        if ( is_wp_error( $id ) ) { return $id; }
        return array( 'status' => 'success', 'content' => $this->serialize_post( get_post( (int) $id ), true ) );
    }

    public function content_update( $post_id, array $body, $can_publish = false ) {
        $post = get_post( (int) $post_id );
        if ( ! $post || ! $this->allowed_post_type( (string) $post->post_type ) ) {
            return new WP_Error( 'design_core_wp_content_not_found', 'Requested WordPress content was not found or is not API-manageable.', array( 'status' => 404 ) );
        }
        $conflict = $this->modified_conflict( $post, (string) ( $body['expected_modified_gmt'] ?? '' ) );
        if ( is_wp_error( $conflict ) ) { return $conflict; }

        $update = array( 'ID' => (int) $post_id );
        if ( array_key_exists( 'title', $body ) ) { $update['post_title'] = sanitize_text_field( (string) $body['title'] ); }
        if ( array_key_exists( 'slug', $body ) ) { $update['post_name'] = sanitize_title( (string) $body['slug'] ); }
        if ( array_key_exists( 'excerpt', $body ) ) { $update['post_excerpt'] = wp_kses_post( (string) $body['excerpt'] ); }
        if ( array_key_exists( 'status', $body ) ) {
            $status = $this->sanitize_status( (string) $body['status'], $can_publish );
            if ( is_wp_error( $status ) ) { return $status; }
            $update['post_status'] = $status;
        }
        if ( array_key_exists( 'content', $body ) ) {
            if ( $this->is_elementor_managed( (int) $post_id ) ) {
                return new WP_Error( 'design_core_wp_elementor_content_protected', 'Generic post_content writes are blocked for Elementor-managed content. Use Design Core preview/apply.', array( 'status' => 409 ) );
            }
            $update['post_content'] = wp_kses_post( (string) $body['content'] );
        }
        if ( 'page' === (string) $post->post_type && array_key_exists( 'parent_id', $body ) ) {
            $parent_id = max( 0, (int) $body['parent_id'] );
            if ( $parent_id === (int) $post_id ) { return new WP_Error( 'design_core_wp_parent_cycle', 'A page cannot be its own parent.', array( 'status' => 400 ) ); }
            if ( $parent_id > 0 && ! $this->valid_page_id( $parent_id ) ) { return new WP_Error( 'design_core_wp_parent_invalid', 'parent_id must reference an existing page.', array( 'status' => 400 ) ); }
            $update['post_parent'] = $parent_id;
        }
        if ( 'page' === (string) $post->post_type && array_key_exists( 'menu_order', $body ) ) { $update['menu_order'] = (int) $body['menu_order']; }
        if ( 1 === count( $update ) ) { return array( 'status' => 'no-op', 'content' => $this->serialize_post( $post, true ) ); }
        $result = wp_update_post( $update, true );
        if ( is_wp_error( $result ) ) { return $result; }
        return array( 'status' => 'success', 'content' => $this->serialize_post( get_post( (int) $post_id ), true ) );
    }

    public function content_trash( $post_id, array $body ) {
        $trash_guard = $this->ensure_trash_available(); if ( is_wp_error( $trash_guard ) ) { return $trash_guard; }
        $post = get_post( (int) $post_id );
        if ( ! $post || ! $this->allowed_post_type( (string) $post->post_type ) ) {
            return new WP_Error( 'design_core_wp_content_not_found', 'Requested WordPress content was not found or is not API-manageable.', array( 'status' => 404 ) );
        }
        $conflict = $this->modified_conflict( $post, (string) ( $body['expected_modified_gmt'] ?? '' ) );
        if ( is_wp_error( $conflict ) ) { return $conflict; }
        if ( 'page' === (string) $post->post_type && (int) get_option( 'page_on_front', 0 ) === (int) $post_id && empty( $body['confirm_front_page'] ) ) {
            return new WP_Error( 'design_core_wp_front_page_confirmation_required', 'Trashing the current front page requires confirm_front_page=true.', array( 'status' => 428 ) );
        }
        $trashed = wp_trash_post( (int) $post_id );
        if ( ! $trashed ) { return new WP_Error( 'design_core_wp_trash_failed', 'WordPress could not move this item to Trash.', array( 'status' => 500 ) ); }
        return array( 'status' => 'trashed', 'id' => (int) $post_id );
    }

    public function content_restore( $post_id ) {
        $post = get_post( (int) $post_id );
        if ( ! $post || ! $this->allowed_post_type( (string) $post->post_type ) || 'trash' !== (string) $post->post_status ) {
            return new WP_Error( 'design_core_wp_restore_unavailable', 'Requested API-manageable content item is not currently in Trash.', array( 'status' => 409 ) );
        }
        $restored = wp_untrash_post( (int) $post_id );
        if ( ! $restored ) { return new WP_Error( 'design_core_wp_restore_failed', 'WordPress could not restore this item.', array( 'status' => 500 ) ); }
        return array( 'status' => 'restored', 'content' => $this->serialize_post( get_post( (int) $post_id ), true ) );
    }

    public function settings_get() {
        return array(
            'blogname' => (string) get_option( 'blogname', '' ),
            'blogdescription' => (string) get_option( 'blogdescription', '' ),
            'show_on_front' => (string) get_option( 'show_on_front', 'posts' ),
            'page_on_front' => (int) get_option( 'page_on_front', 0 ),
            'page_for_posts' => (int) get_option( 'page_for_posts', 0 ),
            'timezone_string' => (string) get_option( 'timezone_string', '' ),
            'date_format' => (string) get_option( 'date_format', '' ),
            'time_format' => (string) get_option( 'time_format', '' ),
        );
    }

    public function settings_update( array $body ) {
        // Validate the complete request before writing any option so a bad front-page id
        // cannot leave a half-applied settings mutation.
        $validated = array();
        $allowed = array( 'blogname', 'blogdescription', 'timezone_string', 'date_format', 'time_format' );
        foreach ( $allowed as $key ) {
            if ( array_key_exists( $key, $body ) ) { $validated[ $key ] = sanitize_text_field( (string) $body[ $key ] ); }
        }
        if ( array_key_exists( 'show_on_front', $body ) ) {
            $show = (string) $body['show_on_front'];
            if ( ! in_array( $show, array( 'posts', 'page' ), true ) ) { return new WP_Error( 'design_core_wp_show_on_front_invalid', 'show_on_front must be posts or page.', array( 'status' => 400 ) ); }
            $validated['show_on_front'] = $show;
        }
        foreach ( array( 'page_on_front', 'page_for_posts' ) as $key ) {
            if ( ! array_key_exists( $key, $body ) ) { continue; }
            $id = max( 0, (int) $body[ $key ] );
            if ( $id > 0 && ! $this->valid_page_id( $id ) ) { return new WP_Error( 'design_core_wp_page_setting_invalid', $key . ' must reference an existing page.', array( 'status' => 400 ) ); }
            $validated[ $key ] = $id;
        }
        foreach ( $validated as $key => $value ) { update_option( $key, $value, false ); }
        return array( 'status' => 'success', 'settings' => $this->settings_get() );
    }

    public function menus_get() {
        $menus = wp_get_nav_menus();
        $result = array();
        foreach ( (array) $menus as $menu ) {
            $items = wp_get_nav_menu_items( (int) $menu->term_id, array( 'post_status' => 'any' ) );
            $result[] = array(
                'id' => (int) $menu->term_id,
                'name' => sanitize_text_field( (string) $menu->name ),
                'slug' => sanitize_title( (string) $menu->slug ),
                'items' => array_map( static function ( $item ) {
                    return array(
                        'id' => (int) $item->ID,
                        'title' => sanitize_text_field( (string) $item->title ),
                        'url' => esc_url_raw( (string) $item->url ),
                        'type' => sanitize_key( (string) $item->type ),
                        'object' => sanitize_key( (string) $item->object ),
                        'object_id' => (int) $item->object_id,
                        'parent' => (int) $item->menu_item_parent,
                        'order' => (int) $item->menu_order,
                    );
                }, (array) $items ),
            );
        }
        return array( 'menus' => $result );
    }

    public function menu_item_upsert( $menu_id, array $body ) {
        $menu = wp_get_nav_menu_object( (int) $menu_id );
        if ( ! $menu || is_wp_error( $menu ) ) { return new WP_Error( 'design_core_wp_menu_not_found', 'Navigation menu was not found.', array( 'status' => 404 ) ); }
        $item_id = max( 0, (int) ( $body['item_id'] ?? 0 ) );
        if ( $item_id > 0 && ! $this->menu_contains_item( (int) $menu_id, $item_id ) ) {
            return new WP_Error( 'design_core_wp_menu_item_mismatch', 'item_id does not belong to the requested menu.', array( 'status' => 409 ) );
        }
        $type = sanitize_key( (string) ( $body['type'] ?? 'custom' ) );
        $object = sanitize_key( (string) ( $body['object'] ?? 'custom' ) );
        $object_id = max( 0, (int) ( $body['object_id'] ?? 0 ) );
        $parent_item_id = max( 0, (int) ( $body['parent_id'] ?? 0 ) );
        if ( $parent_item_id > 0 && ! $this->menu_contains_item( (int) $menu_id, $parent_item_id ) ) {
            return new WP_Error( 'design_core_wp_menu_parent_mismatch', 'parent_id must reference an item in the same menu.', array( 'status' => 409 ) );
        }
        if ( $item_id > 0 && $parent_item_id === $item_id ) {
            return new WP_Error( 'design_core_wp_menu_parent_cycle', 'A menu item cannot be its own parent.', array( 'status' => 400 ) );
        }
        $args = array(
            'menu-item-title' => sanitize_text_field( (string) ( $body['title'] ?? '' ) ),
            'menu-item-status' => 'publish',
            'menu-item-parent-id' => $parent_item_id,
            'menu-item-position' => max( 0, (int) ( $body['position'] ?? 0 ) ),
            'menu-item-type' => $type,
            'menu-item-object' => $object,
            'menu-item-object-id' => $object_id,
        );
        if ( 'custom' === $type ) {
            $url = esc_url_raw( (string) ( $body['url'] ?? '' ) );
            if ( '' === $url ) { return new WP_Error( 'design_core_wp_menu_url_required', 'A valid url is required for a custom menu item.', array( 'status' => 400 ) ); }
            $args['menu-item-url'] = $url;
            $args['menu-item-object'] = 'custom';
            $args['menu-item-object-id'] = 0;
        } elseif ( $object_id <= 0 || ! get_post( $object_id ) ) {
            return new WP_Error( 'design_core_wp_menu_object_invalid', 'object_id must reference an existing WordPress object for non-custom menu items.', array( 'status' => 400 ) );
        }
        $result = wp_update_nav_menu_item( (int) $menu_id, $item_id, $args );
        if ( is_wp_error( $result ) ) { return $result; }
        return array( 'status' => 'success', 'menu_id' => (int) $menu_id, 'item_id' => (int) $result );
    }

    public function menu_item_trash( $menu_id, $item_id ) {
        $trash_guard = $this->ensure_trash_available(); if ( is_wp_error( $trash_guard ) ) { return $trash_guard; }
        if ( ! $this->menu_contains_item( (int) $menu_id, (int) $item_id ) ) {
            return new WP_Error( 'design_core_wp_menu_item_mismatch', 'Menu item was not found in the requested menu.', array( 'status' => 404 ) );
        }
        $trashed = wp_trash_post( (int) $item_id );
        if ( ! $trashed ) { return new WP_Error( 'design_core_wp_menu_item_trash_failed', 'WordPress could not move this menu item to Trash.', array( 'status' => 500 ) ); }
        return array( 'status' => 'trashed', 'menu_id' => (int) $menu_id, 'item_id' => (int) $item_id );
    }

    public function media_import( array $body ) {
        $url = esc_url_raw( (string) ( $body['url'] ?? '' ) );
        if ( '' === $url || ! $this->public_http_url( $url ) ) {
            return new WP_Error( 'design_core_wp_media_url_unsafe', 'Media import requires a public http/https URL; private, loopback and reserved network targets are blocked.', array( 'status' => 400 ) );
        }
        $parent_id = max( 0, (int) ( $body['parent_id'] ?? 0 ) );
        if ( $parent_id > 0 && ! get_post( $parent_id ) ) { return new WP_Error( 'design_core_wp_media_parent_invalid', 'parent_id was not found.', array( 'status' => 400 ) ); }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url, 20 );
        if ( is_wp_error( $tmp ) ) { return $tmp; }
        $size = @filesize( $tmp );
        if ( false !== $size && $size > self::MAX_MEDIA_BYTES ) {
            @unlink( $tmp );
            return new WP_Error( 'design_core_wp_media_too_large', 'Imported media exceeds the 20 MB owner API limit.', array( 'status' => 413 ) );
        }
        $path = parse_url( $url, PHP_URL_PATH );
        $filename = sanitize_file_name( basename( (string) $path ) );
        if ( '' === $filename ) { $filename = 'design-core-import'; }
        $file = array( 'name' => $filename, 'tmp_name' => $tmp );
        $attachment_id = media_handle_sideload( $file, $parent_id, sanitize_text_field( (string) ( $body['title'] ?? '' ) ) );
        if ( is_wp_error( $attachment_id ) ) { @unlink( $tmp ); return $attachment_id; }
        $alt = sanitize_text_field( (string) ( $body['alt'] ?? '' ) );
        if ( '' !== $alt ) { update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', $alt ); }
        return array( 'status' => 'success', 'media' => $this->serialize_attachment( (int) $attachment_id ) );
    }

    public function media_update( $attachment_id, array $body ) {
        $post = get_post( (int) $attachment_id );
        if ( ! $post || 'attachment' !== (string) $post->post_type ) { return new WP_Error( 'design_core_wp_media_not_found', 'Attachment was not found.', array( 'status' => 404 ) ); }
        $update = array( 'ID' => (int) $attachment_id );
        if ( array_key_exists( 'title', $body ) ) { $update['post_title'] = sanitize_text_field( (string) $body['title'] ); }
        if ( array_key_exists( 'caption', $body ) ) { $update['post_excerpt'] = wp_kses_post( (string) $body['caption'] ); }
        if ( array_key_exists( 'description', $body ) ) { $update['post_content'] = wp_kses_post( (string) $body['description'] ); }
        if ( 1 < count( $update ) ) {
            $result = wp_update_post( $update, true );
            if ( is_wp_error( $result ) ) { return $result; }
        }
        if ( array_key_exists( 'alt', $body ) ) { update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $body['alt'] ) ); }
        return array( 'status' => 'success', 'media' => $this->serialize_attachment( (int) $attachment_id ) );
    }

    public function media_trash( $attachment_id ) {
        $trash_guard = $this->ensure_trash_available(); if ( is_wp_error( $trash_guard ) ) { return $trash_guard; }
        $post = get_post( (int) $attachment_id );
        if ( ! $post || 'attachment' !== (string) $post->post_type ) { return new WP_Error( 'design_core_wp_media_not_found', 'Attachment was not found.', array( 'status' => 404 ) ); }
        $trashed = wp_trash_post( (int) $attachment_id );
        if ( ! $trashed ) { return new WP_Error( 'design_core_wp_media_trash_failed', 'WordPress could not move this attachment to Trash.', array( 'status' => 500 ) ); }
        return array( 'status' => 'trashed', 'id' => (int) $attachment_id );
    }

    private function ensure_trash_available() {
        if ( defined( 'EMPTY_TRASH_DAYS' ) && (int) EMPTY_TRASH_DAYS <= 0 ) {
            return new WP_Error( 'design_core_wp_trash_disabled', 'WordPress Trash is disabled on this site; owner API refuses the operation rather than permanently deleting content.', array( 'status' => 409 ) );
        }
        return true;
    }

    private function allowed_post_type( $post_type ) {
        $post_type = sanitize_key( (string) $post_type );
        if ( in_array( $post_type, array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'wp_global_styles', 'wp_template', 'wp_template_part', 'wp_navigation', 'elementor_library' ), true ) ) { return false; }
        $object = get_post_type_object( $post_type );
        if ( ! $object ) { return false; }
        return ! empty( $object->public ) || ! empty( $object->show_ui ) || in_array( $post_type, array( 'post', 'page' ), true );
    }

    private function sanitize_status( $status, $can_publish ) {
        $status = sanitize_key( (string) $status );
        if ( ! in_array( $status, array( 'draft', 'pending', 'private', 'publish' ), true ) ) {
            return new WP_Error( 'design_core_wp_status_invalid', 'status must be draft, pending, private, or publish.', array( 'status' => 400 ) );
        }
        if ( 'publish' === $status && ! $can_publish ) {
            return new WP_Error( 'design_core_wp_publish_scope_required', 'Publishing WordPress content requires design_core_publish.', array( 'status' => 403 ) );
        }
        return $status;
    }

    private function serialize_post( $post, $include_content = false ) {
        if ( ! $post ) { return array(); }
        $id = (int) $post->ID;
        $data = array(
            'id' => $id,
            'post_type' => sanitize_key( (string) $post->post_type ),
            'status' => sanitize_key( (string) $post->post_status ),
            'title' => sanitize_text_field( (string) $post->post_title ),
            'slug' => sanitize_title( (string) $post->post_name ),
            'excerpt' => (string) $post->post_excerpt,
            'parent_id' => (int) $post->post_parent,
            'menu_order' => (int) $post->menu_order,
            'modified_gmt' => sanitize_text_field( (string) $post->post_modified_gmt ),
            'url' => (string) get_permalink( $id ),
            'preview_url' => (string) get_preview_post_link( $id ),
            'elementor_managed' => $this->is_elementor_managed( $id ),
        );
        if ( $include_content ) { $data['content'] = (string) $post->post_content; }
        return $data;
    }

    private function serialize_attachment( $attachment_id ) {
        $post = get_post( (int) $attachment_id );
        $meta = wp_get_attachment_metadata( (int) $attachment_id );
        return array(
            'id' => (int) $attachment_id,
            'title' => $post ? sanitize_text_field( (string) $post->post_title ) : '',
            'url' => (string) wp_get_attachment_url( (int) $attachment_id ),
            'mime' => (string) get_post_mime_type( (int) $attachment_id ),
            'alt' => (string) get_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', true ),
            'caption' => $post ? (string) $post->post_excerpt : '',
            'width' => (int) ( is_array( $meta ) ? ( $meta['width'] ?? 0 ) : 0 ),
            'height' => (int) ( is_array( $meta ) ? ( $meta['height'] ?? 0 ) : 0 ),
        );
    }

    private function is_elementor_managed( $post_id ) {
        return 'builder' === (string) get_post_meta( (int) $post_id, '_elementor_edit_mode', true )
            || '' !== (string) get_post_meta( (int) $post_id, '_elementor_data', true );
    }

    private function modified_conflict( $post, $expected ) {
        $expected = sanitize_text_field( (string) $expected );
        if ( '' !== $expected && $expected !== (string) $post->post_modified_gmt ) {
            return new WP_Error( 'design_core_wp_content_conflict', 'Content changed since the caller last read it; refusing to overwrite concurrent changes.', array( 'status' => 409, 'expected_modified_gmt' => $expected, 'current_modified_gmt' => (string) $post->post_modified_gmt ) );
        }
        return true;
    }

    private function valid_page_id( $id ) {
        $post = get_post( (int) $id );
        return $post && 'page' === (string) $post->post_type;
    }

    private function menu_contains_item( $menu_id, $item_id ) {
        foreach ( (array) wp_get_nav_menu_items( (int) $menu_id, array( 'post_status' => 'any' ) ) as $item ) {
            if ( (int) $item->ID === (int) $item_id ) { return true; }
        }
        return false;
    }

    /** Blocks obvious SSRF targets before WordPress's own safe HTTP layer runs. */
    private function public_http_url( $url ) {
        $parts = parse_url( (string) $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) { return false; }
        if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) { return false; }
        if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ) { return false; }
        $host = strtolower( rtrim( (string) $parts['host'], '.' ) );
        if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) ) { return false; }
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
        }
        $ips = function_exists( 'gethostbynamel' ) ? gethostbynamel( $host ) : false;
        if ( ! is_array( $ips ) || ! $ips ) { return false; }
        foreach ( $ips as $ip ) {
            if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { return false; }
        }
        return true;
    }
}
