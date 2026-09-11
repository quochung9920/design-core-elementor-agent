<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Single source of truth for the agent draft-writer gate.
 *
 * Precedence (fail closed):
 *   1. An externally defined DESIGN_CORE_AGENT_ENABLE_DRAFT_WRITES wins
 *      absolutely -- true or false, on any environment. The operator's
 *      explicit configuration is never overridden.
 *   2. Otherwise the writer auto-enables only when Design Core's own
 *      environment (Remote_Settings -- the same source every other guard in
 *      this plugin enforces, including apply_draft itself) is explicitly
 *      local, development or staging.
 *   3. Production, unknown and empty environments resolve to false.
 *
 * Notes:
 * - Using the plugin environment (not wp_get_environment_type()) keeps one
 *   environment semantic across the context flag, the preview flag and the
 *   apply_draft execution gate, so the flag can never promise what execution
 *   refuses.
 * - Reporting enabled never weakens execution: apply_draft keeps its own
 *   capability, confirm, preview/hash, draft-only, lock and idempotency
 *   guards, plus its own environment allowlist.
 */
class Design_Core_Elementor_Agent_Draft_Writes {
    const CONSTANT = 'DESIGN_CORE_AGENT_ENABLE_DRAFT_WRITES';
    const ALLOWED_ENVIRONMENTS = array( 'local', 'development', 'staging' );

    /**
     * Pure policy evaluation -- no globals, fully unit-testable.
     *
     * @param bool  $is_defined      Whether the operator defined the constant externally.
     * @param mixed $external_value  The operator value when defined.
     * @param mixed $environment     The plugin environment string.
     * @return bool
     */
    public static function resolve( $is_defined, $external_value, $environment ) {
        if ( $is_defined ) { return (bool) $external_value; }
        return in_array( strtolower( trim( (string) $environment ) ), self::ALLOWED_ENVIRONMENTS, true );
    }

    /**
     * Runtime gate. Fail closed when the constant was never resolved
     * (e.g. this class loaded without the plugin bootstrap).
     *
     * @return bool
     */
    public static function enabled() {
        return defined( self::CONSTANT ) && (bool) constant( self::CONSTANT );
    }

    /**
     * Default used once by the plugin bootstrap when the operator did not
     * define the constant. Never call this to override an external value.
     *
     * @return bool
     */
    public static function default_enabled() {
        return self::resolve( false, null, self::plugin_environment() );
    }

    /**
     * @return string Plugin environment, or 'production' when the settings
     *   service is unavailable (fail closed).
     */
    public static function plugin_environment() {
        if ( function_exists( 'wp_get_environment_type' ) ) {
            return (string) wp_get_environment_type();
        }
        return 'production';
    }
}
