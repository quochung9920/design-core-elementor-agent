<?php
/**
 * Design Core REAL WordPress + Elementor runtime verification: compensated history rollback.
 *
 * Proves, against a real WordPress install with real Elementor active and a real
 * execute_approved_plan() save (no stubs, no fakes): a Change_Ledger::rollback() failure
 * injected at the FIRST restore stage compensates the page back to the EXACT pre-rollback
 * CURRENT state, a failure injected at a LATER restore stage does too, the ledger entry stays
 * rollback-available (not marked rolled_back) after each compensated failure, a subsequent
 * clean rollback (once the injected failure is removed) still succeeds with exact restoration,
 * and the disposable page is never deleted except by this harness's own final, marker-checked
 * cleanup step.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/design-core-elementor/tests/runtime/history-rollback-compensation.php
 *
 * Exits 0 only if every mandatory assertion passed. Exits non-zero on any failure, including
 * being blocked by the environment guard.
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "This script must run under WordPress (wp eval-file), not plain php.\n" );
    exit( 1 );
}

$GLOBALS['rt_assertions'] = 0;
$GLOBALS['rt_failures'] = 0;
$GLOBALS['rt_owned_page_id'] = 0;

function rt_assert( $condition, $label ) {
    $GLOBALS['rt_assertions']++;
    if ( $condition ) { echo "[PASS] {$label}\n"; return true; }
    echo "[FAIL] {$label}\n";
    $GLOBALS['rt_failures']++;
    return false;
}

function rt_capture_state( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) { return null; }
    $state = array(
        'post' => array(
            'post_title' => $post->post_title, 'post_content' => $post->post_content, 'post_excerpt' => $post->post_excerpt,
            'post_status' => $post->post_status, 'post_name' => $post->post_name, 'post_parent' => $post->post_parent,
            'menu_order' => $post->menu_order, 'comment_status' => $post->comment_status, 'ping_status' => $post->ping_status,
        ),
        'meta' => array(),
    );
    foreach ( array( '_elementor_data', '_elementor_edit_mode', '_elementor_version', '_elementor_template_type', '_design_core_page_manifest' ) as $meta_key ) {
        $exists = metadata_exists( 'post', $post_id, $meta_key );
        $state['meta'][ $meta_key ] = array( 'exists' => $exists, 'value' => $exists ? get_post_meta( $post_id, $meta_key, true ) : null );
    }
    return $state;
}

function rt_canonicalize( $value ) {
    if ( ! is_array( $value ) ) { return $value; }
    $normalized = array();
    foreach ( $value as $key => $item ) { $normalized[ $key ] = rt_canonicalize( $item ); }
    ksort( $normalized );
    return $normalized;
}

function rt_hash( $state ) { return hash( 'sha256', wp_json_encode( rt_canonicalize( $state ) ) ); }

function rt_cleanup_and_finish( $hard_failure ) {
    $page_id = (int) $GLOBALS['rt_owned_page_id'];
    if ( $page_id ) {
        $marker = get_post_meta( $page_id, '_design_core_runtime_test', true );
        if ( '1' === $marker ) {
            $deleted = wp_delete_post( $page_id, true );
            if ( $deleted && null === get_post( $page_id ) ) {
                echo "[PASS] cleanup deleted only the owned disposable page\n";
                $GLOBALS['rt_assertions']++;
                echo "CLEANUP=PASS\n";
            } else {
                echo "[FAIL] cleanup: wp_delete_post() did not remove page {$page_id}\n";
                $GLOBALS['rt_assertions']++; $GLOBALS['rt_failures']++;
                echo "CLEANUP=FAIL\n";
                $hard_failure = true;
            }
        } else {
            echo "[FAIL] cleanup refused: ownership marker missing/mismatched on page {$page_id} (marker=" . var_export( $marker, true ) . ") -- page left in place, NOT deleted\n";
            $GLOBALS['rt_assertions']++; $GLOBALS['rt_failures']++;
            echo "CLEANUP=FAIL\n";
            $hard_failure = true;
        }
    } else {
        echo "CLEANUP=PASS (no disposable page was ever created)\n";
    }

    echo "\nAssertions: {$GLOBALS['rt_assertions']}\n";
    echo "Failures: {$GLOBALS['rt_failures']}\n";
    $pass = ! $hard_failure && 0 === $GLOBALS['rt_failures'];
    echo "\nRUNTIME RESULT: " . ( $pass ? 'PASS' : 'FAIL' ) . "\n";
    exit( $pass ? 0 : 1 );
}

echo "=== DESIGN CORE REAL RUNTIME VERIFICATION: COMPENSATED HISTORY ROLLBACK ===\n\n";

try {
    $environment = wp_get_environment_type();
    echo "Environment: {$environment}\n";
    if ( ! in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) {
        echo "\nRUNTIME BLOCKED — production environment\n";
        exit( 1 );
    }

    if ( ! class_exists( '\\Elementor\\Plugin' ) || ! defined( 'ELEMENTOR_VERSION' ) ) {
        echo "[FATAL] Real Elementor runtime is not available.\n";
        exit( 1 );
    }
    if ( ! defined( 'DESIGN_CORE_ELEMENTOR_VERSION' ) || ! class_exists( 'Design_Core_Elementor_Conversion_Service' ) || ! class_exists( 'Design_Core_Elementor_Change_Ledger' ) ) {
        echo "[FATAL] Design Core is not active/loaded.\n";
        exit( 1 );
    }

    $acting_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
    if ( empty( $acting_admins ) ) {
        echo "[FATAL] No administrator user exists on this site; cannot establish a real acting user for Elementor's Document::save() capability check.\n";
        exit( 1 );
    }
    wp_set_current_user( $acting_admins[0]->ID );
    echo 'Acting user: ' . $acting_admins[0]->user_login . " (ID {$acting_admins[0]->ID})\n";

    // ==================================================================
    // Disposable page.
    // ==================================================================
    $random_id = substr( wp_generate_uuid4(), 0, 8 );
    $before_marker = 'DC_COMP_BEFORE_' . $random_id;
    $after_marker = 'DC_COMP_AFTER_' . $random_id;
    $title = 'Design Core Compensation Verification ' . gmdate( 'Y-m-d\TH:i:s\Z' ) . ' ' . $random_id;

    $page_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => $title, 'post_content' => $before_marker, 'post_status' => 'draft' ), true );
    if ( is_wp_error( $page_id ) || ! $page_id ) {
        echo '[FATAL] disposable page creation failed: ' . ( is_wp_error( $page_id ) ? $page_id->get_error_message() : 'unknown error' ) . "\n";
        exit( 1 );
    }
    $page_id = (int) $page_id;
    if ( 2 === $page_id ) {
        echo "[FATAL] refusing to proceed: the newly created page landed on ID 2. Aborting without any further mutation.\n";
        exit( 1 );
    }
    update_post_meta( $page_id, '_design_core_runtime_test', '1' );
    $GLOBALS['rt_owned_page_id'] = $page_id;
    echo "PAGE_ID={$page_id}\n";
    rt_assert( '1' === get_post_meta( $page_id, '_design_core_runtime_test', true ), 'ownership marker set on the disposable page' );

    $before_state = rt_capture_state( $page_id );
    $before_hash = rt_hash( $before_state );
    echo "BEFORE_HASH={$before_hash}\n";

    // ==================================================================
    // Real Design IR -> real BuildPlan -> real execute_approved_plan().
    // ==================================================================
    $blank = array( 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'assets' => array(), 'interaction' => array() );
    $fp = static function ( $semantic, $structure ) { return array( 'version' => 2, 'semantic' => $semantic, 'structure' => $structure, 'content_schema' => array(), 'layout' => '', 'interaction' => '' ); };
    $heading = array_merge( $blank, array(
        'id' => 'comp-heading', 'source' => array( 'tag' => 'h2', 'classes' => array(), 'attributes' => array(), 'dom_path' => '/section[1]/h2[1]' ),
        'semantic' => array( 'role' => 'heading', 'component_type' => '', 'confidence' => 1.0 ),
        'content' => array( 'text' => $after_marker, 'rich_text' => '', 'link' => array(), 'image' => array(), 'list' => array(), 'fields' => array() ),
        'layout' => array(), 'component' => array( 'fingerprint' => $fp( 'heading', 'heading' ), 'repeated' => false, 'reusable' => false, 'dynamic' => false, 'content_schema' => array() ),
        'children' => array(),
    ) );
    $root = array_merge( $blank, array(
        'id' => 'comp-root', 'source' => array( 'tag' => 'section', 'classes' => array( 'dc-compensation-verification' ), 'attributes' => array(), 'dom_path' => '/section[1]' ),
        'semantic' => array( 'role' => 'section', 'component_type' => '', 'confidence' => 1.0 ),
        'content' => array( 'text' => '', 'rich_text' => '', 'link' => array(), 'image' => array(), 'list' => array(), 'fields' => array() ),
        'layout' => array( 'display' => 'flex' ), 'component' => array( 'fingerprint' => $fp( 'section', 'section' ), 'repeated' => false, 'reusable' => false, 'dynamic' => false, 'content_schema' => array() ),
        'children' => array( 'comp-heading' ),
    ) );
    $raw_ir = array( 'schema_version' => 4, 'type' => 'design-ir', 'nodes' => array( $root, $heading ), 'root_ids' => array( 'comp-root' ), 'analysis_quality' => array(), 'tokens' => array(), 'diagnostics' => array() );

    ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $raw_ir );
    $normalized_ir = ( new Design_Core_Elementor_Normalization_Pipeline() )->normalize( $raw_ir );
    ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $normalized_ir );
    $plan = ( new Design_Core_Elementor_Build_Planner() )->plan( $normalized_ir, 'elementor-v3' );
    ( new Design_Core_Elementor_Build_Plan_Validator() )->validate( $plan, $normalized_ir );
    rt_assert( is_array( $plan ) && ! empty( $plan['items'] ), 'real Design_Core_Elementor_Design_IR_Validator + Build_Planner produced a real BuildPlan' );

    $service = new Design_Core_Elementor_Conversion_Service();
    $result = $service->execute_approved_plan( $page_id, $normalized_ir, $plan );
    $save_status = $result['status'] ?? 'unknown';
    echo "SAVE_STATUS={$save_status}\n";
    if ( ! rt_assert( 'success' === $save_status, 'real execute_approved_plan() succeeded' ) ) {
        echo 'Save error: ' . ( $result['error'] ?? 'unknown' ) . "\n";
        rt_cleanup_and_finish( true );
    }
    $entry_id = (string) ( $result['history_entry_id'] ?? '' );
    rt_assert( '' !== $entry_id, 'a real Change_Ledger history entry was recorded for this save' );

    $after_state = rt_capture_state( $page_id );
    $after_hash = rt_hash( $after_state );
    echo "AFTER_HASH={$after_hash}\n";
    rt_assert( $after_hash !== $before_hash, 'AFTER_HASH != BEFORE_HASH' );

    $ledger = new Design_Core_Elementor_Change_Ledger();
    $entry = $ledger->get( $entry_id );
    rt_assert( is_array( $entry ) && Design_Core_Elementor_Change_Ledger::rollback_available_for( $entry ), 'the real history entry reports rollback_available:true before any rollback attempt' );
    $tracks_page_manifest = array_key_exists( 'page_manifest_before', (array) ( $entry['context'] ?? array() ) ) && array_key_exists( 'page_manifest_after', (array) ( $entry['context'] ?? array() ) );
    echo 'Entry tracks page manifest: ' . ( $tracks_page_manifest ? 'yes' : 'no' ) . "\n";

    // CURRENT, right before any rollback attempt -- must equal AFTER (nothing else touched the page).
    $current_before_rollback_state = rt_capture_state( $page_id );
    $current_before_rollback_hash = rt_hash( $current_before_rollback_state );
    echo "CURRENT_BEFORE_ROLLBACK_HASH={$current_before_rollback_hash}\n";
    rt_assert( $current_before_rollback_hash === $after_hash, 'CURRENT_BEFORE_ROLLBACK_HASH === AFTER_HASH (nothing touched the page since the save)' );

    /** Injects a rollback-stage failure scoped to this exact page/entry, runs rollback(), and
     *  asserts the compensated-failure contract: distinct error code, exact compensation back
     *  to CURRENT_BEFORE_ROLLBACK, and the entry remains NOT marked rolled back. */
    $assert_compensated_failure = static function ( $stage, $label ) use ( $ledger, $entry_id, $page_id, $current_before_rollback_hash ) {
        $callback = function ( $should_fail, $filter_stage, $filter_post_id ) use ( $stage, $page_id ) {
            if ( (int) $filter_post_id !== (int) $page_id ) { return $should_fail; }
            return $filter_stage === $stage ? true : $should_fail;
        };
        add_filter( Design_Core_Elementor_Change_Ledger::TEST_FAIL_STAGE_FILTER, $callback, 10, 3 );
        $result = $ledger->rollback( $entry_id );
        remove_filter( Design_Core_Elementor_Change_Ledger::TEST_FAIL_STAGE_FILTER, $callback, 10 );

        rt_assert( is_wp_error( $result ) && 'design_core_history_restore_failed_compensated' === $result->get_error_code(), "{$label}: injected failure at \"{$stage}\" returns the compensated error code" );
        $compensated_hash = rt_hash( rt_capture_state( $page_id ) );
        echo "COMPENSATED_HASH ({$stage})={$compensated_hash}\n";
        rt_assert( $compensated_hash === $current_before_rollback_hash, "{$label}: COMPENSATED_HASH === CURRENT_BEFORE_ROLLBACK_HASH exactly" );
        rt_assert( null !== get_post( $page_id ), "{$label}: page still exists after the compensated failure" );
        $reloaded = $ledger->get( $entry_id );
        rt_assert( is_array( $reloaded ) && empty( $reloaded['rolled_back_at'] ), "{$label}: entry NOT marked rolled_back_at after compensation" );
        rt_assert( is_array( $reloaded ) && Design_Core_Elementor_Change_Ledger::rollback_available_for( $reloaded ), "{$label}: entry still reports rollback_available:true, may be retried" );
    };

    // B: failure injected after the FIRST restore stage.
    $assert_compensated_failure( 'after-elementor-data', 'Compensation (first stage)' );

    // C: failure injected after a LATER restore stage -- the last one this entry tracks, so
    // compensating it necessarily also re-verifies every earlier stage's own compensation path.
    $later_stage = $tracks_page_manifest ? 'after-page-manifest' : 'after-post-content';
    $assert_compensated_failure( $later_stage, 'Compensation (later stage)' );

    // E: a subsequent CLEAN rollback (no injection) still succeeds, with exact restoration.
    $clean_result = $ledger->rollback( $entry_id );
    rt_assert( is_array( $clean_result ) && 'rolled-back' === ( $clean_result['status'] ?? '' ), 'clean rollback (no injected failure) reports rolled-back' );
    $restored_hash = rt_hash( rt_capture_state( $page_id ) );
    echo "RESTORED_HASH={$restored_hash}\n";
    rt_assert( $restored_hash === $before_hash, 'RESTORED_HASH === BEFORE_HASH exactly' );
    rt_assert( null !== get_post( $page_id ), 'page still exists after the real rollback' );
    $reloaded = $ledger->get( $entry_id );
    rt_assert( is_array( $reloaded ) && ! empty( $reloaded['rolled_back_at'] ), 'entry marked rolled_back_at only after the genuinely successful rollback' );

} catch ( Throwable $exception ) {
    echo '[FATAL] Uncaught ' . get_class( $exception ) . ': ' . $exception->getMessage() . "\n";
    echo $exception->getTraceAsString() . "\n";
    $GLOBALS['rt_failures']++;
    rt_cleanup_and_finish( true );
}

rt_cleanup_and_finish( false );
