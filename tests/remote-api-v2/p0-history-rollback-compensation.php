<?php
/**
 * P0 CRITICAL TEST: Change_Ledger::rollback() is all-or-compensate.
 *
 * Every conflict check must happen before the first mutation. If every restore stage succeeds
 * and is verified, the ledger is durably marked rolled-back. If ANY stage fails partway through
 * -- including the final ledger-mark write itself -- rollback() must compensate the page back
 * to the exact pre-rollback CURRENT state rather than leaving a blend of the old and new
 * states, and must never report success or mark the entry rolled back unless that compensation
 * is itself verified. If compensation ALSO fails, a distinct fatal error is returned and the
 * page may be left in a partially-restored state requiring manual investigation -- but the
 * ledger must never lie about what happened.
 *
 * This is unit-level: a hand-built ledger entry against an in-memory post/meta store, not the
 * real execute_approved_plan() pipeline (see tests/runtime/history-rollback-compensation.php
 * for the real-WordPress proof). Design_Core_Elementor_Change_Ledger::TEST_FAIL_STAGE_FILTER
 * lets a test deterministically fail one exact restore/compensation stage without needing to
 * actually break a WordPress API call.
 */

require dirname( __DIR__ ) . '/bootstrap-standalone.php';

// ============================================================================
// Minimal post/meta store -- the same shape p0-existing-page-transaction-safety.php uses.
// ============================================================================

$GLOBALS['dc_test_posts'] = array();
$GLOBALS['dc_test_post_meta'] = array();

function get_post( $post_id ) {
    $post_id = (int) $post_id;
    if ( ! isset( $GLOBALS['dc_test_posts'][ $post_id ] ) ) { return null; }
    return (object) $GLOBALS['dc_test_posts'][ $post_id ];
}

function get_post_field( $field, $post_id, $context = 'display' ) {
    $post_id = (int) $post_id;
    if ( ! isset( $GLOBALS['dc_test_posts'][ $post_id ][ $field ] ) ) { return ''; }
    return $GLOBALS['dc_test_posts'][ $post_id ][ $field ];
}

function wp_update_post( $post_data, $wp_error = false ) {
    $post_id = (int) ( $post_data['ID'] ?? 0 );
    if ( $post_id <= 0 || ! isset( $GLOBALS['dc_test_posts'][ $post_id ] ) ) {
        return $wp_error ? new WP_Error( 'post-not-found', 'Post not found' ) : 0;
    }
    foreach ( array( 'post_content', 'post_title', 'post_excerpt', 'post_status' ) as $field ) {
        if ( isset( $post_data[ $field ] ) ) { $GLOBALS['dc_test_posts'][ $post_id ][ $field ] = $post_data[ $field ]; }
    }
    return $post_id;
}

function get_post_meta( $post_id, $key, $single = false ) {
    $post_id = (int) $post_id;
    if ( ! array_key_exists( $key, $GLOBALS['dc_test_post_meta'][ $post_id ] ?? array() ) ) { return $single ? '' : array(); }
    $value = $GLOBALS['dc_test_post_meta'][ $post_id ][ $key ];
    return $single ? $value : array( $value );
}

function update_post_meta( $post_id, $key, $value ) {
    $GLOBALS['dc_test_post_meta'][ (int) $post_id ][ $key ] = $value;
    return true;
}

function delete_post_meta( $post_id, $key ) {
    unset( $GLOBALS['dc_test_post_meta'][ (int) $post_id ][ $key ] );
    return true;
}

function metadata_exists( $meta_type, $post_id, $meta_key ) {
    return array_key_exists( $meta_key, $GLOBALS['dc_test_post_meta'][ (int) $post_id ] ?? array() );
}

dc_require( array( 'core/change-ledger.php' ) );

$ledger = new Design_Core_Elementor_Change_Ledger();

/** Scopes an injected stage failure to exactly one post ID, since the standalone add_filter()
 *  stub has no remove_filter() -- callbacks from earlier scenarios must stay harmless later. */
function dc_inject_stage_failures( $post_id, array $stages_to_fail ) {
    add_filter(
        Design_Core_Elementor_Change_Ledger::TEST_FAIL_STAGE_FILTER,
        function ( $should_fail, $stage, $filter_post_id ) use ( $post_id, $stages_to_fail ) {
            if ( (int) $filter_post_id !== (int) $post_id ) { return $should_fail; }
            return in_array( $stage, $stages_to_fail, true ) ? true : $should_fail;
        },
        10,
        3
    );
}

/**
 * Sets up a fresh disposable post whose live state already reflects a completed Elementor
 * save (AFTER), and records the matching Change_Ledger entry a real save would have produced
 * -- BEFORE was a plain, never-Elementor-edited page; AFTER has real elementor data, all three
 * owned meta keys, changed post_content (mirroring Elementor's own save_plain_text()), and a
 * synchronized page manifest. Returns the recorded entry.
 */
function dc_setup_rolled_forward_page( $post_id, array $context_overrides = array() ) {
    $GLOBALS['dc_test_posts'][ $post_id ] = array(
        'ID' => $post_id, 'post_type' => 'page', 'post_title' => 'Rollback Compensation Test',
        'post_content' => 'BEFORE_CONTENT', 'post_excerpt' => '', 'post_status' => 'draft',
        'post_name' => '', 'post_parent' => 0, 'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );
    $GLOBALS['dc_test_post_meta'][ $post_id ] = array();

    $before_data = '';
    $after_data = '{"elements":[{"id":"after-el"}]}';
    $context = array_merge(
        array(
            'owned_meta_before' => array(
                '_elementor_edit_mode' => array( 'exists' => false, 'value' => null ),
                '_elementor_version' => array( 'exists' => false, 'value' => null ),
                '_elementor_template_type' => array( 'exists' => false, 'value' => null ),
            ),
            'post_content_before' => 'BEFORE_CONTENT',
            'post_content_after' => 'AFTER_CONTENT',
            'page_manifest_before' => array( 'exists' => false, 'value' => null ),
            'page_manifest_after' => array( 'exists' => true, 'value' => array( 'sections' => array( 'after' ) ) ),
        ),
        $context_overrides
    );

    global $ledger;
    $entry = $ledger->record( 'elementor-save', 'post', $post_id, $before_data, $after_data, $context );

    // Bring the live state up to AFTER, as the real save this entry describes would have.
    $GLOBALS['dc_test_post_meta'][ $post_id ]['_elementor_data'] = $after_data;
    $GLOBALS['dc_test_post_meta'][ $post_id ]['_elementor_edit_mode'] = 'builder';
    $GLOBALS['dc_test_post_meta'][ $post_id ]['_elementor_version'] = '3.20.0';
    $GLOBALS['dc_test_post_meta'][ $post_id ]['_elementor_template_type'] = 'wp-page';
    $GLOBALS['dc_test_posts'][ $post_id ]['post_content'] = 'AFTER_CONTENT';
    if ( ! empty( $context['page_manifest_after']['exists'] ) ) {
        $GLOBALS['dc_test_post_meta'][ $post_id ]['_design_core_page_manifest'] = $context['page_manifest_after']['value'];
    }

    return $entry;
}

function dc_snapshot( $post_id ) {
    $post = get_post( $post_id );
    return array(
        'post_content' => $post->post_content,
        'elementor_data' => get_post_meta( $post_id, '_elementor_data', true ),
        'edit_mode' => array( metadata_exists( 'post', $post_id, '_elementor_edit_mode' ), get_post_meta( $post_id, '_elementor_edit_mode', true ) ),
        'version' => array( metadata_exists( 'post', $post_id, '_elementor_version' ), get_post_meta( $post_id, '_elementor_version', true ) ),
        'template_type' => array( metadata_exists( 'post', $post_id, '_elementor_template_type' ), get_post_meta( $post_id, '_elementor_template_type', true ) ),
        'page_manifest' => array( metadata_exists( 'post', $post_id, '_design_core_page_manifest' ), get_post_meta( $post_id, '_design_core_page_manifest', true ) ),
    );
}

// ----------------------------------------------------------------------------
// TEST 1: normal rollback -- B -> A, every field restored, rolled_back_at set.
// ----------------------------------------------------------------------------
$entry = dc_setup_rolled_forward_page( 1001 );
$before_snapshot = dc_snapshot( 1001 ); // captures AFTER-state (current, pre-rollback)
$result = $ledger->rollback( $entry['id'] );
dc_assert( is_array( $result ) && 'rolled-back' === ( $result['status'] ?? '' ), 'Test 1: normal rollback reports rolled-back' );
dc_assert( '' === get_post_meta( 1001, '_elementor_data', true ), 'Test 1: _elementor_data restored to absent' );
dc_assert( 'BEFORE_CONTENT' === get_post_field( 'post_content', 1001 ), 'Test 1: post_content restored exactly' );
dc_assert( ! metadata_exists( 'post', 1001, '_elementor_edit_mode' ), 'Test 1: edit_mode restored to absent, not present-with-empty-value' );
dc_assert( ! metadata_exists( 'post', 1001, '_design_core_page_manifest' ), 'Test 1: page manifest restored to absent' );
$reloaded_entry = $ledger->get( $entry['id'] );
dc_assert( is_array( $reloaded_entry ) && ! empty( $reloaded_entry['rolled_back_at'] ), 'Test 1: ledger entry marked rolled_back_at' );
echo "Test 1: normal rollback -- PASS\n";

// ----------------------------------------------------------------------------
// TEST 2: conflict -- CURRENT=C (external edit after save), rollback refused, zero mutation.
// ----------------------------------------------------------------------------
$entry = dc_setup_rolled_forward_page( 1002 );
$GLOBALS['dc_test_post_meta'][1002]['_elementor_data'] = '{"elements":[{"id":"external-edit-C"}]}';
$c_snapshot = dc_snapshot( 1002 );
$result = $ledger->rollback( $entry['id'] );
dc_assert( is_wp_error( $result ) && 'design_core_history_conflict' === $result->get_error_code(), 'Test 2: conflicting current state refuses rollback' );
dc_assert( $c_snapshot === dc_snapshot( 1002 ), 'Test 2: state C survives exactly untouched -- zero mutation on conflict' );
$reloaded_entry = $ledger->get( $entry['id'] );
dc_assert( empty( $reloaded_entry['rolled_back_at'] ), 'Test 2: entry not marked rolled back after a refused conflict' );
echo "Test 2: conflict refuses rollback with zero mutation -- PASS\n";

// ----------------------------------------------------------------------------
// TEST 3: failure after _elementor_data restore -> compensates to exact B.
// ----------------------------------------------------------------------------
$entry = dc_setup_rolled_forward_page( 1003 );
$b_snapshot = dc_snapshot( 1003 );
dc_inject_stage_failures( 1003, array( 'after-elementor-data' ) );
$result = $ledger->rollback( $entry['id'] );
dc_assert( is_wp_error( $result ) && 'design_core_history_restore_failed_compensated' === $result->get_error_code(), 'Test 3: injected elementor-data failure returns the compensated error code' );
dc_assert( $b_snapshot === dc_snapshot( 1003 ), 'Test 3: page compensated back to exact pre-rollback B' );
$reloaded_entry = $ledger->get( $entry['id'] );
dc_assert( empty( $reloaded_entry['rolled_back_at'] ), 'Test 3: entry remains NOT rolled back' );
echo "Test 3: failure after _elementor_data restore compensates to exact B -- PASS\n";

// ----------------------------------------------------------------------------
// TEST 4: failure after owned-meta restore -> compensates to exact B.
// ----------------------------------------------------------------------------
$entry = dc_setup_rolled_forward_page( 1004 );
$b_snapshot = dc_snapshot( 1004 );
dc_inject_stage_failures( 1004, array( 'after-owned-meta' ) );
$result = $ledger->rollback( $entry['id'] );
dc_assert( is_wp_error( $result ) && 'design_core_history_restore_failed_compensated' === $result->get_error_code(), 'Test 4: injected owned-meta failure returns the compensated error code' );
dc_assert( $b_snapshot === dc_snapshot( 1004 ), 'Test 4: page compensated back to exact pre-rollback B (including the already-restored _elementor_data)' );
$reloaded_entry = $ledger->get( $entry['id'] );
dc_assert( empty( $reloaded_entry['rolled_back_at'] ), 'Test 4: entry remains NOT rolled back' );
echo "Test 4: failure after owned-meta restore compensates to exact B -- PASS\n";

// ----------------------------------------------------------------------------
// TEST 5: failure after post_content restore -> compensates to exact B.
// ----------------------------------------------------------------------------
$entry = dc_setup_rolled_forward_page( 1005 );
$b_snapshot = dc_snapshot( 1005 );
dc_inject_stage_failures( 1005, array( 'after-post-content' ) );
$result = $ledger->rollback( $entry['id'] );
dc_assert( is_wp_error( $result ) && 'design_core_history_restore_failed_compensated' === $result->get_error_code(), 'Test 5: injected post-content failure returns the compensated error code' );
dc_assert( $b_snapshot === dc_snapshot( 1005 ), 'Test 5: page compensated back to exact pre-rollback B' );
$reloaded_entry = $ledger->get( $entry['id'] );
dc_assert( empty( $reloaded_entry['rolled_back_at'] ), 'Test 5: entry remains NOT rolled back' );
echo "Test 5: failure after post_content restore compensates to exact B -- PASS\n";

// ----------------------------------------------------------------------------
// TEST 6: failure at the final ledger-mark stage -- data already restored to A, but the
// durable ledger write fails. Chosen semantics: compensate back to B rather than leave the
// page at A while the ledger still (durably) says "not rolled back".
// ----------------------------------------------------------------------------
$entry = dc_setup_rolled_forward_page( 1006 );
$b_snapshot = dc_snapshot( 1006 );
dc_inject_stage_failures( 1006, array( 'before-ledger-mark' ) );
$result = $ledger->rollback( $entry['id'] );
dc_assert( is_wp_error( $result ) && 'design_core_history_restore_failed_compensated' === $result->get_error_code(), 'Test 6: injected ledger-mark failure returns the compensated error code, never a false success' );
dc_assert( $b_snapshot === dc_snapshot( 1006 ), 'Test 6: page compensated back to exact pre-rollback B, not left at A' );
$reloaded_entry = $ledger->get( $entry['id'] );
dc_assert( empty( $reloaded_entry['rolled_back_at'] ), 'Test 6: entry remains NOT rolled back, consistent with the page still being at B' );
echo "Test 6: failure at final ledger-mark compensates to exact B, never a false success -- PASS\n";

// ----------------------------------------------------------------------------
// TEST 7: compensation itself fails -> distinct fatal error, never rolled-back.
// ----------------------------------------------------------------------------
$entry = dc_setup_rolled_forward_page( 1007 );
dc_inject_stage_failures( 1007, array( 'after-elementor-data', 'compensation-after-elementor-data' ) );
$result = $ledger->rollback( $entry['id'] );
dc_assert( is_wp_error( $result ) && 'design_core_history_compensation_failed' === $result->get_error_code(), 'Test 7: a restore failure whose compensation ALSO fails returns the distinct fatal compensation error' );
dc_assert( false === strpos( $result->get_error_message(), 'rolled-back' ), 'Test 7: the fatal compensation error text never claims rolled-back' );
$reloaded_entry = $ledger->get( $entry['id'] );
dc_assert( empty( $reloaded_entry['rolled_back_at'] ), 'Test 7: entry remains NOT rolled back after a compensation failure' );
echo "Test 7: compensation failure returns a distinct fatal error, never rolled-back -- PASS\n";

// ----------------------------------------------------------------------------
// TEST 8: meta absence semantics -- BEFORE had no owned meta at all; after a clean rollback
// they must be exactly absent again, not present with an empty string/null value.
// ----------------------------------------------------------------------------
$entry = dc_setup_rolled_forward_page( 1008 );
$result = $ledger->rollback( $entry['id'] );
dc_assert( is_array( $result ) && 'rolled-back' === ( $result['status'] ?? '' ), 'Test 8: rollback succeeds' );
dc_assert( ! metadata_exists( 'post', 1008, '_elementor_edit_mode' ), 'Test 8: _elementor_edit_mode is exactly absent, not present-with-empty-value' );
dc_assert( ! metadata_exists( 'post', 1008, '_elementor_version' ), 'Test 8: _elementor_version is exactly absent' );
dc_assert( ! metadata_exists( 'post', 1008, '_elementor_template_type' ), 'Test 8: _elementor_template_type is exactly absent' );
echo "Test 8: meta-absence semantics preserved through rollback -- PASS\n";

// ----------------------------------------------------------------------------
// TEST 9: post_content concurrent edit must conflict before any mutation.
// ----------------------------------------------------------------------------
$entry = dc_setup_rolled_forward_page( 1009 );
$GLOBALS['dc_test_posts'][1009]['post_content'] = 'EXTERNALLY_EDITED_CONTENT';
$c_snapshot = dc_snapshot( 1009 );
$result = $ledger->rollback( $entry['id'] );
dc_assert( is_wp_error( $result ) && 'design_core_history_conflict' === $result->get_error_code(), 'Test 9: an external post_content edit refuses rollback as a conflict' );
dc_assert( $c_snapshot === dc_snapshot( 1009 ), 'Test 9: the externally-edited content survives exactly, untouched' );
echo "Test 9: post_content concurrent edit conflicts before any mutation -- PASS\n";

// ----------------------------------------------------------------------------
// TEST 10: page manifest -- exact restoration on a clean rollback, and conflict-checked
// against an out-of-band change before any mutation.
// ----------------------------------------------------------------------------
$entry = dc_setup_rolled_forward_page( 1010 );
$result = $ledger->rollback( $entry['id'] );
dc_assert( is_array( $result ) && 'rolled-back' === ( $result['status'] ?? '' ), 'Test 10a: rollback succeeds' );
dc_assert( ! metadata_exists( 'post', 1010, '_design_core_page_manifest' ), 'Test 10a: page manifest restored to exactly absent (it did not exist before this save)' );

$entry = dc_setup_rolled_forward_page( 1011 );
$GLOBALS['dc_test_post_meta'][1011]['_design_core_page_manifest'] = array( 'sections' => array( 'externally-changed' ) );
$c_snapshot = dc_snapshot( 1011 );
$result = $ledger->rollback( $entry['id'] );
dc_assert( is_wp_error( $result ) && 'design_core_history_conflict' === $result->get_error_code(), 'Test 10b: an out-of-band page manifest change refuses rollback as a conflict' );
dc_assert( $c_snapshot === dc_snapshot( 1011 ), 'Test 10b: the externally-changed manifest survives exactly, untouched' );
echo "Test 10: page manifest exact restoration and conflict-checking -- PASS\n";

dc_finish( 'P0: History Rollback Compensation' );
