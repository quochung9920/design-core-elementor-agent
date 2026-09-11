<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Global remote-write kill switch. Read/preview routes never consult this; every
 * write/publish/rollback route must call ensure_writes_enabled() before doing anything else.
 * 
 * P3: Environment binding check for machine credentials.
 * After credential is resolved and validated, environment must match target site environment.
 */
class Design_Core_Elementor_Remote_Write_Guard {
    public static function ensure_writes_enabled() {
        if ( Design_Core_Elementor_Remote_Settings::writes_enabled() ) { return true; }
        return new WP_Error(
            'design_core_remote_writes_disabled',
            'Remote writes are disabled for this site. An administrator must re-enable them under Design Core -> Remote Access.',
            array( 'status' => 423 )
        );
    }

    /**
     * P3: Validate credential environment matches target site environment.
     * Called in write routes after permission callback has resolved credential.
     *
     * Machine credentials are environment-bound:
     * - local credential can only write to local site
     * - staging credential can only write to staging site
     * - production credential can only write to production site
     *
     * This is a security boundary to prevent accidental writes to wrong environment.
     *
     * @param array $principal The _design_core_principal from the REST request
     * @return true|WP_Error
     */
    public static function ensure_credential_environment_match( $principal ) {
        // Only credential-based auth needs environment checking; user auth doesn't
        if ( ! is_array( $principal ) || 'credential' !== ( $principal['type'] ?? '' ) ) {
            return true;
        }

        $credential_env = isset( $principal['environment'] ) ? strtolower( trim( (string) $principal['environment'] ) ) : '';
        $site_env = strtolower( trim( (string) Design_Core_Elementor_Remote_Settings::environment() ) );

        if ( $credential_env !== $site_env ) {
            return new WP_Error(
                'design_core_credential_environment_mismatch',
                sprintf(
                    'Credential environment (%s) does not match target site environment (%s). This is a security boundary; writes are not allowed.',
                    $credential_env,
                    $site_env
                ),
                array( 'status' => 403, 'credential_env' => $credential_env, 'site_env' => $site_env )
            );
        }
        return true;
    }

    /** Production requires every one of confirm/preview/hash/capability/write-enabled to already be true; this only adds the extra environment-name signal to the error so a caller can tell why it failed. */
    public static function ensure_production_guard( $confirmed, $has_preview ) {
        if ( ! Design_Core_Elementor_Remote_Settings::is_production() ) { return true; }
        if ( $confirmed && $has_preview ) { return true; }
        return new WP_Error(
            'design_core_production_guard_failed',
            'Production environment requires an approved preview and explicit confirmation for this action.',
            array( 'status' => 428 )
        );
    }
}
