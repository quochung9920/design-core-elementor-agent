<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Scoped Design Core remote capabilities. Existing v1 REST routes and the admin UI
 * keep using `manage_options`; these are additive capabilities for v2/MCP access so a
 * machine credential (or a dedicated role) never needs full `manage_options`.
 */
class Design_Core_Elementor_Capabilities {
    const READ = 'design_core_read';
    const PREVIEW = 'design_core_preview';
    const BUILD = 'design_core_build';
    const MODIFY = 'design_core_modify';
    const PUBLISH = 'design_core_publish';
    const ROLLBACK = 'design_core_rollback';

    const OPTION_VERSION = 'design_core_elementor_capabilities_version';
    const VERSION = 1;

    public static function all() {
        return array( self::READ, self::PREVIEW, self::BUILD, self::MODIFY, self::PUBLISH, self::ROLLBACK );
    }

    /**
     * Versioned, idempotent grant to the administrator role. Runs from the plugin
     * activation hook (matching Registry_Migrator's own versioned-migration convention)
     * so re-activating or upgrading never silently re-grants capabilities an operator
     * may have deliberately revoked from a role.
     */
    public static function migrate() {
        $installed = (int) get_option( self::OPTION_VERSION, 0 );
        if ( $installed >= self::VERSION ) { return array( 'status' => 'already-migrated', 'version' => $installed ); }
        $role = function_exists( 'get_role' ) ? get_role( 'administrator' ) : null;
        if ( $role ) { foreach ( self::all() as $capability ) { $role->add_cap( $capability ); } }
        update_option( self::OPTION_VERSION, self::VERSION, false );
        return array( 'status' => 'migrated', 'version' => self::VERSION );
    }
}
