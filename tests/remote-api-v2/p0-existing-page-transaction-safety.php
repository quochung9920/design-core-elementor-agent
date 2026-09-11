<?php
/**
 * P0 CRITICAL TEST: Existing page transaction safety
 *
 * Verifies that execute_approved_plan() NEVER deletes existing pages on failure, and that
 * rollback is conflict-aware: it only ever restores an existing page to its BEFORE state
 * when the page's CURRENT state still matches EXPECTED_AFTER (the state Design Core's own
 * mutation produced). "Current differs from BEFORE" alone is never treated as proof that
 * this transaction owns the change -- a concurrent/external edit must survive rollback
 * untouched.
 *
 * Covers, at the Conversion_Transaction unit level:
 * - Safe restore when current still equals what Design Core itself produced.
 * - Concurrent external edits are never overwritten (fail closed with a stable conflict code).
 * - A missing EXPECTED_AFTER with a changed current state fails closed rather than guessing.
 * - Elementor meta existence (absent vs. present-with-empty-value) round-trips exactly.
 * - Created posts are still deleted on rollback; existing posts never are, even in the same
 *   transaction.
 * - An uncommitted transaction going out of scope still rolls back via __destruct(), and does
 *   so with the same conflict-aware rules (never blind-overwrites).
 *
 * ...and, at the execute_approved_plan() integration level (real service, real adapter, real
 * persistence path, a test-only fault-injection filter to force failure immediately after
 * Design Core's own save):
 * - Failure after save restores the exact BEFORE state.
 * - A concurrent edit landing after Design Core's save survives rollback untouched.
 */

// ============================================================================
// Minimal Elementor::Plugin stand-in so the real V3 adapter's save/reload/render path
// (and Conversion_Transaction's kit snapshot/restore, which no-ops gracefully here) can
// run for real in the execute_approved_plan() integration section below. A namespace
// declaration must be the first statement in the file, so this comes before the
// bootstrap require.
// ============================================================================
namespace Elementor {
    class Plugin {
        public static $instance;
        public $documents;
        public $frontend;
        public $kits_manager;
        public $breakpoints;
        public $widgets_manager;
        public $controls_manager;
        public $files_manager;
        public static function instance() { return self::$instance; }
    }
}

namespace {

require dirname( __DIR__ ) . '/bootstrap-standalone.php';

/** Trivial in-memory Elementor Kit stand-in: enough for Global_Style_Bridge::sync_v3_kit()
 *  and Conversion_Transaction's own kit snapshot/restore to run for real. */
class DC_Test_P0_Elementor_Kit {
    private $settings = array();
    public function get_settings( $key ) { return $this->settings[ $key ] ?? array(); }
    public function update_settings( $updates ) { $this->settings = array_merge( $this->settings, (array) $updates ); return true; }
}
class DC_Test_P0_Elementor_Kits_Manager {
    private $kit;
    public function __construct() { $this->kit = new DC_Test_P0_Elementor_Kit(); }
    public function get_active_kit() { return $this->kit; }
}

// Instantiated up-front (not just in the integration section below) so every unit test
// above gets a real, harmless Plugin::instance() instead of warning on a null one.
\Elementor\Plugin::$instance = new \Elementor\Plugin();
\Elementor\Plugin::$instance->kits_manager = new DC_Test_P0_Elementor_Kits_Manager();

// ============================================================================
// Extended mocks for post operations and transactions
// ============================================================================

$GLOBALS['dc_test_posts'] = array();
$GLOBALS['dc_test_post_meta'] = array();

function get_post( $post_id ) {
    $post_id = (int) $post_id;
    if ( ! isset( $GLOBALS['dc_test_posts'][ $post_id ] ) ) { return null; }
    $post = $GLOBALS['dc_test_posts'][ $post_id ];
    return (object) array(
        'ID' => $post['ID'],
        'post_type' => $post['post_type'],
        'post_title' => $post['post_title'],
        'post_content' => $post['post_content'],
        'post_excerpt' => $post['post_excerpt'],
        'post_status' => $post['post_status'],
        'post_name' => $post['post_name'],
        'post_parent' => $post['post_parent'],
        'menu_order' => $post['menu_order'],
        'comment_status' => $post['comment_status'],
        'ping_status' => $post['ping_status'],
    );
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
    $post = &$GLOBALS['dc_test_posts'][ $post_id ];
    foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order', 'comment_status', 'ping_status' ) as $field ) {
        if ( isset( $post_data[ $field ] ) ) { $post[ $field ] = $post_data[ $field ]; }
    }
    return $post_id;
}

function get_post_meta( $post_id, $key, $single = false ) {
    $post_id = (int) $post_id;
    if ( ! isset( $GLOBALS['dc_test_post_meta'][ $post_id ][ $key ] ) ) { return $single ? '' : array(); }
    $value = $GLOBALS['dc_test_post_meta'][ $post_id ][ $key ];
    return $single ? $value : array( $value );
}

function update_post_meta( $post_id, $key, $value ) {
    $post_id = (int) $post_id;
    if ( ! isset( $GLOBALS['dc_test_post_meta'][ $post_id ] ) ) { $GLOBALS['dc_test_post_meta'][ $post_id ] = array(); }
    $GLOBALS['dc_test_post_meta'][ $post_id ][ $key ] = $value;
    return true;
}

/** Real exists-vs-absent semantics: get_post_meta()'s single-value return can't tell
 *  "key absent" apart from "key present with an empty value" -- metadata_exists() can. */
function metadata_exists( $meta_type, $object_id, $meta_key ) {
    $object_id = (int) $object_id;
    return isset( $GLOBALS['dc_test_post_meta'][ $object_id ] ) && array_key_exists( $meta_key, $GLOBALS['dc_test_post_meta'][ $object_id ] );
}

function delete_post_meta( $post_id, $key ) {
    $post_id = (int) $post_id;
    unset( $GLOBALS['dc_test_post_meta'][ $post_id ][ $key ] );
    return true;
}

function get_the_title( $post_id ) {
    $post = get_post( $post_id );
    return $post ? $post->post_title : '';
}

// wp_delete_post()/wp_delete_attachment() are intentionally NOT redeclared here: the shared
// bootstrap-standalone.php already provides them, instrumented (dc_test_deleted_post_ids /
// dc_test_deleted_attachment_ids) and synced against $GLOBALS['dc_test_posts'] above.

// ============================================================================
// Load transaction class with the conflict-aware rollback model
// ============================================================================

dc_require( array( 'core/conversion-transaction.php' ) );

echo "\n=== P0 Test Suite: Existing Page Transaction Safety ===\n\n";

// ----------------------------------------------------------------------------
// TEST 1: A safe restore -- Design Core's own mutation is rolled back because CURRENT
// still equals EXPECTED_AFTER (nothing else touched the page after our own write).
// ----------------------------------------------------------------------------
{
    $test_post_id = 100;
    $GLOBALS['dc_test_posts'][ $test_post_id ] = array(
        'ID' => $test_post_id, 'post_type' => 'page', 'post_title' => 'Test Page', 'post_content' => 'Original content',
        'post_excerpt' => 'Original excerpt', 'post_status' => 'publish', 'post_name' => 'test-page', 'post_parent' => 0,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );
    $GLOBALS['dc_test_post_meta'][ $test_post_id ]['_elementor_data'] = '{"elements":[{"id":"original"}]}';

    $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
    $transaction->track_existing_post_mutation( $test_post_id ); // BEFORE

    // Design Core's own mutation.
    wp_update_post( array( 'ID' => $test_post_id, 'post_content' => 'Modified content', 'post_title' => 'Modified Title' ) );
    update_post_meta( $test_post_id, '_elementor_data', '{"elements":[{"id":"modified"}]}' );
    $transaction->mark_existing_post_expected_state( $test_post_id ); // EXPECTED_AFTER

    $post = get_post( $test_post_id );
    dc_assert( 'Modified Title' === $post->post_title, 'Post was mutated' );
    dc_assert( '{"elements":[{"id":"modified"}]}' === get_post_meta( $test_post_id, '_elementor_data', true ), 'Elementor data was mutated' );

    $rollback_result = $transaction->rollback();

    $post_after = get_post( $test_post_id );
    dc_assert( 'Test Page' === $post_after->post_title, 'Post title restored after rollback' );
    dc_assert( 'Original content' === $post_after->post_content, 'Post content restored after rollback' );
    dc_assert( '{"elements":[{"id":"original"}]}' === get_post_meta( $test_post_id, '_elementor_data', true ), 'Elementor data restored after rollback' );
    dc_assert( null !== get_post( $test_post_id ), 'Post still exists after rollback (NOT DELETED)' );
    dc_assert( 'rolled-back' === ( $rollback_result['status'] ?? '' ), 'Rollback successful with no errors' );
    dc_assert( ! in_array( $test_post_id, $GLOBALS['dc_test_deleted_post_ids'], true ), 'Existing page ID never reaches the destructive delete path' );
}
echo "✓ Test 1: Existing post snapshot and restore works correctly\n";

// ----------------------------------------------------------------------------
// TEST 2: Created posts ARE deleted on rollback (unchanged behavior).
// ----------------------------------------------------------------------------
{
    $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();

    $new_post_id = 200;
    $GLOBALS['dc_test_posts'][ $new_post_id ] = array(
        'ID' => $new_post_id, 'post_type' => 'page', 'post_title' => 'Created During Transaction', 'post_content' => 'New content',
        'post_excerpt' => '', 'post_status' => 'draft', 'post_name' => 'created-post', 'post_parent' => 0,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );
    $transaction->track_post( $new_post_id );

    dc_assert( null !== get_post( $new_post_id ), 'Created post exists before rollback' );

    $rollback_result = $transaction->rollback();

    dc_assert( null === get_post( $new_post_id ), 'Created post WAS DELETED on rollback (correct)' );
    dc_assert( in_array( $new_post_id, $GLOBALS['dc_test_deleted_post_ids'], true ), 'Created post ID appears in the delete instrumentation' );
    dc_assert( 'rolled-back' === ( $rollback_result['status'] ?? '' ), 'Rollback successful' );
}
echo "✓ Test 2: Created posts are correctly deleted on rollback\n";

// ----------------------------------------------------------------------------
// TEST 3: Multiple mutations tracked, all restored (still a single safe-restore case).
// ----------------------------------------------------------------------------
{
    $test_post_id = 100;
    $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();

    $GLOBALS['dc_test_posts'][ $test_post_id ]['post_title'] = 'Original Title';
    $GLOBALS['dc_test_posts'][ $test_post_id ]['post_content'] = 'Original content';
    $GLOBALS['dc_test_post_meta'][ $test_post_id ]['_elementor_data'] = '{"v":"1"}';

    $transaction->track_existing_post_mutation( $test_post_id ); // BEFORE = v1/"Original Title"

    wp_update_post( array( 'ID' => $test_post_id, 'post_title' => 'Mutated 1' ) );
    update_post_meta( $test_post_id, '_elementor_data', '{"v":"2"}' );
    wp_update_post( array( 'ID' => $test_post_id, 'post_content' => 'Mutated content' ) );
    update_post_meta( $test_post_id, '_elementor_data', '{"v":"3"}' );
    $transaction->mark_existing_post_expected_state( $test_post_id ); // EXPECTED_AFTER = v3/"Mutated 1"/"Mutated content"

    $rollback_result = $transaction->rollback();

    $post_final = get_post( $test_post_id );
    dc_assert( 'Original Title' === $post_final->post_title, 'Title restored after multiple mutations' );
    dc_assert( 'Original content' === $post_final->post_content, 'Content restored after multiple mutations' );
    dc_assert( '{"v":"1"}' === get_post_meta( $test_post_id, '_elementor_data', true ), 'Elementor data restored to exact version' );
    dc_assert( null !== get_post( $test_post_id ), 'Post still exists after rollback' );
    dc_assert( 'rolled-back' === ( $rollback_result['status'] ?? '' ), 'Rollback of multiple mutations reports success' );
}
echo "✓ Test 3: Multiple mutations tracked and all restored\n";

// ----------------------------------------------------------------------------
// TEST 4: track_existing_post_mutation() is idempotent (first call wins for BEFORE);
// mark_existing_post_expected_state() tracks the LATEST Design-Core-owned mutation.
// ----------------------------------------------------------------------------
{
    $test_post_id = 100;
    $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();

    $GLOBALS['dc_test_posts'][ $test_post_id ]['post_title'] = 'Version A';
    $GLOBALS['dc_test_post_meta'][ $test_post_id ]['_elementor_data'] = '{"v":"a"}';

    $transaction->track_existing_post_mutation( $test_post_id ); // BEFORE = A

    wp_update_post( array( 'ID' => $test_post_id, 'post_title' => 'Version B' ) );
    update_post_meta( $test_post_id, '_elementor_data', '{"v":"b"}' );

    $transaction->track_existing_post_mutation( $test_post_id ); // no-op: BEFORE stays A, not B

    wp_update_post( array( 'ID' => $test_post_id, 'post_title' => 'Version C' ) );
    update_post_meta( $test_post_id, '_elementor_data', '{"v":"c"}' );
    $transaction->mark_existing_post_expected_state( $test_post_id ); // EXPECTED_AFTER = C

    $transaction->rollback();

    $post_final = get_post( $test_post_id );
    dc_assert( 'Version A' === $post_final->post_title, 'Rollback uses FIRST snapshot, not subsequent state' );
    dc_assert( '{"v":"a"}' === get_post_meta( $test_post_id, '_elementor_data', true ), 'Elementor data restored to first snapshot' );
}
echo "✓ Test 4: track_existing_post_mutation() is idempotent\n";

// ----------------------------------------------------------------------------
// TEST 5: Committed transaction does NOT rollback.
// ----------------------------------------------------------------------------
{
    $test_post_id = 100;
    $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();

    $GLOBALS['dc_test_posts'][ $test_post_id ]['post_title'] = 'Original';
    $transaction->track_existing_post_mutation( $test_post_id );

    wp_update_post( array( 'ID' => $test_post_id, 'post_title' => 'Modified' ) );
    $transaction->mark_existing_post_expected_state( $test_post_id );

    $transaction->commit();
    $rollback_result = $transaction->rollback();

    dc_assert( 'not-rolled-back' === ( $rollback_result['status'] ?? '' ), 'Committed transaction cannot be rolled back' );
    dc_assert( 'Modified' === get_post( $test_post_id )->post_title, 'Mutation persists when transaction committed' );
}
echo "✓ Test 5: Committed transactions cannot be rolled back\n";

// ----------------------------------------------------------------------------
// TEST 6: Nonexistent post ID gracefully handled.
// ----------------------------------------------------------------------------
{
    $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
    $transaction->track_existing_post_mutation( 9999 );
    $rollback_result = $transaction->rollback();
    dc_assert( 'rolled-back' === ( $rollback_result['status'] ?? '' ), 'Rollback succeeds for nonexistent post (no-op)' );
    dc_assert( empty( $rollback_result['errors'] ), 'No errors for nonexistent post' );
}
echo "✓ Test 6: Nonexistent post ID handled gracefully\n";

// ----------------------------------------------------------------------------
// TEST 7 (acceptance C): failure after Design Core's own save restores EXACT BEFORE,
// and the existing page is proven to never touch the delete path.
// ----------------------------------------------------------------------------
{
    $post_id = 300;
    $GLOBALS['dc_test_posts'][ $post_id ] = array(
        'ID' => $post_id, 'post_type' => 'page', 'post_title' => 'Failure Page', 'post_content' => 'A',
        'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'failure-page', 'post_parent' => 0,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );
    $GLOBALS['dc_test_post_meta'][ $post_id ] = array( '_elementor_data' => 'A' );
    $deleted_before = count( $GLOBALS['dc_test_deleted_post_ids'] );

    $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
    $transaction->track_existing_post_mutation( $post_id ); // BEFORE = A

    update_post_meta( $post_id, '_elementor_data', 'B' ); // Design Core's own mutation
    $transaction->mark_existing_post_expected_state( $post_id ); // EXPECTED_AFTER = B

    // Simulate a later stage (reload/render/QA/registry) failing -- rollback triggered.
    $rollback_result = $transaction->rollback();

    dc_assert( 'rolled-back' === ( $rollback_result['status'] ?? '' ), 'failure-after-save: rollback reports rolled-back' );
    dc_assert( empty( $rollback_result['errors'] ), 'failure-after-save: rollback has no errors' );
    dc_assert( null !== get_post( $post_id ), 'failure-after-save: existing page still exists' );
    dc_assert( 'A' === get_post_meta( $post_id, '_elementor_data', true ), 'failure-after-save: elementor data restored to exact BEFORE (A)' );
    dc_assert( 'Failure Page' === get_post( $post_id )->post_title, 'failure-after-save: post fields restored to BEFORE' );
    dc_assert( count( $GLOBALS['dc_test_deleted_post_ids'] ) === $deleted_before, 'failure-after-save: no new delete calls were made' );
    dc_assert( ! in_array( $post_id, $GLOBALS['dc_test_deleted_post_ids'], true ), 'failure-after-save: existing page ID never appears in delete instrumentation' );
}
echo "✓ Test 7: failure after save restores exact BEFORE state, page never deleted\n";

// ----------------------------------------------------------------------------
// TEST 8 (acceptance D/E): a concurrent external edit landing after Design Core's own
// save is NEVER overwritten by rollback; the conflict is reported explicitly.
// ----------------------------------------------------------------------------
{
    $post_id = 301;
    $GLOBALS['dc_test_posts'][ $post_id ] = array(
        'ID' => $post_id, 'post_type' => 'page', 'post_title' => 'Concurrent Page', 'post_content' => 'A',
        'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'concurrent-page', 'post_parent' => 0,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );
    $GLOBALS['dc_test_post_meta'][ $post_id ] = array( '_elementor_data' => 'A' );

    $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
    $transaction->track_existing_post_mutation( $post_id ); // BEFORE = A

    update_post_meta( $post_id, '_elementor_data', 'B' ); // Design Core writes B
    $transaction->mark_existing_post_expected_state( $post_id ); // EXPECTED_AFTER = B

    update_post_meta( $post_id, '_elementor_data', 'C' ); // external/concurrent edit after our own save

    $rollback_result = $transaction->rollback();

    dc_assert( 'rollback-failed' === ( $rollback_result['status'] ?? '' ), 'concurrent-conflict: rollback status reflects the conflict' );
    dc_assert( in_array( 'post-rollback-conflict:' . $post_id, (array) ( $rollback_result['errors'] ?? array() ), true ), 'concurrent-conflict: stable conflict error code is present' );
    dc_assert( 'C' === get_post_meta( $post_id, '_elementor_data', true ), 'concurrent-conflict: external edit C survives rollback untouched' );
    dc_assert( null !== get_post( $post_id ), 'concurrent-conflict: page still exists' );
}
echo "✓ Test 8: concurrent external edit after save is never overwritten\n";

// ----------------------------------------------------------------------------
// TEST 9 (acceptance F): EXPECTED_AFTER was never marked and current state changed --
// fail closed rather than assume the change was Design Core's own.
// ----------------------------------------------------------------------------
{
    $post_id = 302;
    $GLOBALS['dc_test_posts'][ $post_id ] = array(
        'ID' => $post_id, 'post_type' => 'page', 'post_title' => 'Original Title', 'post_content' => 'A',
        'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'expected-missing', 'post_parent' => 0,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );
    $GLOBALS['dc_test_post_meta'][ $post_id ] = array( '_elementor_data' => 'A' );

    $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
    $transaction->track_existing_post_mutation( $post_id ); // BEFORE = A

    // Something changes the page, but mark_existing_post_expected_state() is deliberately
    // never called -- this transaction cannot prove it produced the new state B.
    update_post_meta( $post_id, '_elementor_data', 'B' );

    $rollback_result = $transaction->rollback();

    dc_assert( 'rollback-failed' === ( $rollback_result['status'] ?? '' ), 'expected-after-missing: rollback fails closed' );
    dc_assert( in_array( 'post-rollback-expected-state-missing:' . $post_id, (array) ( $rollback_result['errors'] ?? array() ), true ), 'expected-after-missing: stable error code is present' );
    dc_assert( 'B' === get_post_meta( $post_id, '_elementor_data', true ), 'expected-after-missing: current state B is left untouched, not overwritten' );
}

// Sub-case: EXPECTED_AFTER missing but current still equals BEFORE -- a safe no-op.
{
    $post_id = 3020;
    $GLOBALS['dc_test_posts'][ $post_id ] = array(
        'ID' => $post_id, 'post_type' => 'page', 'post_title' => 'Untouched', 'post_content' => 'A',
        'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'expected-missing-noop', 'post_parent' => 0,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );
    $GLOBALS['dc_test_post_meta'][ $post_id ] = array( '_elementor_data' => 'A' );

    $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
    $transaction->track_existing_post_mutation( $post_id ); // BEFORE = A; nothing mutates it afterward.

    $rollback_result = $transaction->rollback();

    dc_assert( 'rolled-back' === ( $rollback_result['status'] ?? '' ), 'expected-after-missing-but-unchanged: no-op rollback still succeeds' );
}
echo "✓ Test 9: missing EXPECTED_AFTER with a changed current state fails closed\n";

// ----------------------------------------------------------------------------
// TEST 10 (acceptance G/H): Elementor meta that was originally ABSENT is exactly absent
// again after a safe restore -- never an empty string, never null, never stale.
// ----------------------------------------------------------------------------
{
    $post_id = 303;
    $GLOBALS['dc_test_posts'][ $post_id ] = array(
        'ID' => $post_id, 'post_type' => 'page', 'post_title' => 'Meta Absence', 'post_content' => 'A',
        'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'meta-absence', 'post_parent' => 0,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );
    $GLOBALS['dc_test_post_meta'][ $post_id ] = array(); // _elementor_edit_mode / _elementor_version absent

    dc_assert( ! metadata_exists( 'post', $post_id, '_elementor_edit_mode' ), 'meta-absence: edit_mode starts absent' );

    $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
    $transaction->track_existing_post_mutation( $post_id ); // BEFORE: both meta keys absent

    update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
    update_post_meta( $post_id, '_elementor_version', '3.28.0' );
    $transaction->mark_existing_post_expected_state( $post_id ); // EXPECTED_AFTER: both present

    dc_assert( metadata_exists( 'post', $post_id, '_elementor_edit_mode' ), 'meta-absence: edit_mode exists after Design Core mutation' );

    $rollback_result = $transaction->rollback(); // no further external change -> safe restore

    dc_assert( 'rolled-back' === ( $rollback_result['status'] ?? '' ), 'meta-absence-restore: rollback succeeds' );
    dc_assert( ! metadata_exists( 'post', $post_id, '_elementor_edit_mode' ), 'meta-absence-restore: edit_mode meta key is fully absent again, not empty' );
    dc_assert( ! metadata_exists( 'post', $post_id, '_elementor_version' ), 'meta-absence-restore: version meta key is fully absent again, not empty' );
    dc_assert( '' === get_post_meta( $post_id, '_elementor_edit_mode', true ), 'meta-absence-restore: single-value read still degrades to empty string for absent meta' );
}
echo "✓ Test 10: meta originally absent is exactly absent again after restore\n";

// ----------------------------------------------------------------------------
// TEST 11 (acceptance B/K distinction): existing-page-never-deleted and
// created-page-is-deleted hold simultaneously within the SAME transaction/rollback.
// ----------------------------------------------------------------------------
{
    $existing_id = 500; $created_id = 501;
    $GLOBALS['dc_test_posts'][ $existing_id ] = array(
        'ID' => $existing_id, 'post_type' => 'page', 'post_title' => 'Existing', 'post_content' => 'A',
        'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'existing', 'post_parent' => 0,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );
    $GLOBALS['dc_test_post_meta'][ $existing_id ] = array( '_elementor_data' => 'A' );
    $GLOBALS['dc_test_posts'][ $created_id ] = array(
        'ID' => $created_id, 'post_type' => 'page', 'post_title' => 'Created', 'post_content' => 'New',
        'post_excerpt' => '', 'post_status' => 'draft', 'post_name' => 'created', 'post_parent' => 0,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );

    $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
    $transaction->track_existing_post_mutation( $existing_id );
    $transaction->track_post( $created_id );
    update_post_meta( $existing_id, '_elementor_data', 'B' );
    $transaction->mark_existing_post_expected_state( $existing_id );

    $transaction->rollback();

    dc_assert( null !== get_post( $existing_id ), 'distinction: existing page survives rollback in the same transaction as a created page' );
    dc_assert( 'A' === get_post_meta( $existing_id, '_elementor_data', true ), 'distinction: existing page content is restored' );
    dc_assert( null === get_post( $created_id ), 'distinction: created page is deleted in the same rollback' );
    dc_assert( ! in_array( $existing_id, $GLOBALS['dc_test_deleted_post_ids'], true ), 'distinction: existing page ID never touches the delete path' );
    dc_assert( in_array( $created_id, $GLOBALS['dc_test_deleted_post_ids'], true ), 'distinction: created page ID does go through the delete path' );
}
echo "✓ Test 11: existing-page-never-deleted and created-page-is-deleted hold together\n";

// ----------------------------------------------------------------------------
// TEST 12 (acceptance K): an uncommitted existing-page-only transaction still rolls back
// when it goes out of scope, via __destruct().
// ----------------------------------------------------------------------------
{
    $post_id = 502;
    $GLOBALS['dc_test_posts'][ $post_id ] = array(
        'ID' => $post_id, 'post_type' => 'page', 'post_title' => 'Destructor', 'post_content' => 'A',
        'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'destructor', 'post_parent' => 0,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );
    $GLOBALS['dc_test_post_meta'][ $post_id ] = array( '_elementor_data' => 'A' );

    ( function () use ( $post_id ) {
        $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
        $transaction->track_existing_post_mutation( $post_id );
        update_post_meta( $post_id, '_elementor_data', 'B' );
        $transaction->mark_existing_post_expected_state( $post_id );
        // $transaction is never committed or explicitly rolled back -- going out of scope
        // here must still trigger a safe rollback via __destruct().
    } )();

    dc_assert( 'A' === get_post_meta( $post_id, '_elementor_data', true ), 'destructor: uncommitted existing-page mutation is rolled back on scope exit' );
    dc_assert( null !== get_post( $post_id ), 'destructor: existing page still exists after destructor-triggered rollback' );
}

// TEST 13 (acceptance L): the destructor never blind-overwrites a conflict either.
{
    $post_id = 503;
    $GLOBALS['dc_test_posts'][ $post_id ] = array(
        'ID' => $post_id, 'post_type' => 'page', 'post_title' => 'Destructor Conflict', 'post_content' => 'A',
        'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'destructor-conflict', 'post_parent' => 0,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );
    $GLOBALS['dc_test_post_meta'][ $post_id ] = array( '_elementor_data' => 'A' );

    ( function () use ( $post_id ) {
        $transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
        $transaction->track_existing_post_mutation( $post_id );
        update_post_meta( $post_id, '_elementor_data', 'B' );
        $transaction->mark_existing_post_expected_state( $post_id );
        update_post_meta( $post_id, '_elementor_data', 'C' ); // concurrent edit before scope ends
    } )();

    dc_assert( 'C' === get_post_meta( $post_id, '_elementor_data', true ), 'destructor: conflicting concurrent state survives an uncommitted transaction going out of scope' );
}
echo "✓ Test 12/13: destructor considers existing-page mutations and stays conflict-aware\n";

// ============================================================================
// INTEGRATION: execute_approved_plan() exercised through the real service, real V3
// adapter, and real persistence path, with a bounded test-only failure injected right
// after Design Core's own save -- proving the transaction wiring inside the actual
// production method, not just Conversion_Transaction in isolation.
// ============================================================================

class DC_Test_P0_Elementor_Document {
    private $post_id;
    public function __construct( $post_id ) { $this->post_id = $post_id; }
    public function save( $data ) {
        update_post_meta( $this->post_id, '_elementor_data', wp_json_encode( $data['elements'] ?? array() ) );
        return true;
    }
}
class DC_Test_P0_Elementor_Documents_Manager {
    public function get( $post_id ) { return get_post( $post_id ) ? new DC_Test_P0_Elementor_Document( $post_id ) : null; }
}
class DC_Test_P0_Elementor_Frontend {
    public function get_builder_content_for_display( $post_id, $with_css = true ) { return '<div class="elementor" data-id="' . (int) $post_id . '">rendered</div>'; }
}
\Elementor\Plugin::$instance->documents = new DC_Test_P0_Elementor_Documents_Manager();
\Elementor\Plugin::$instance->frontend = new DC_Test_P0_Elementor_Frontend();

/** Bypasses real planning/mapping entirely: execute_approved_plan() only calls
 *  $this->executor->execute(), so a fixed, already-valid elements array is enough to
 *  reach the real save/reload/render/transaction wiring under test. */
class DC_Test_P0_Fake_Executor {
    public function execute( $plan, $context ) {
        return array(
            'status' => 'success',
            'elements' => array(
                array( 'id' => 'root1234', 'elType' => 'container', 'settings' => array(), 'elements' => array(
                    array( 'id' => 'text1234', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => 'Design Core' ), 'elements' => array() ),
                ) ),
            ),
            'created_artifacts' => array(), 'warnings' => array(), 'fallback_used' => false, 'diagnostics' => array(),
        );
    }
}

dc_require( array(
    'core/observability.php',
    'includes/class-capability-scanner.php',
    'core/breakpoint-registry.php',
    'core/browser-analysis-service.php',
    'core/design-ir.php',
    'core/design-ir-validator.php',
    'core/build-plan.php',
    'core/build-plan-validator.php',
    'core/elementor-adapter-interface.php',
    'core/change-ledger.php',
    'core/elementor-persistence-service.php',
    'core/elementor-v3-adapter.php',
    'core/elementor-v4-adapter.php',
    'core/global-style-bridge.php',
    'core/global-style-adapters.php',
    'core/design-token-service.php',
    'core/conversion-service.php',
) );

$p0_ir_node = array(
    'id' => 'node-1',
    'source' => array( 'tag' => 'section', 'classes' => array( 'hero' ), 'attributes' => array(), 'dom_path' => '/section[1]' ),
    'semantic' => array( 'role' => 'section', 'component_type' => '', 'confidence' => 1.0 ),
    'content' => array( 'text' => '', 'rich_text' => '', 'link' => array(), 'image' => array(), 'list' => array(), 'fields' => array() ),
    'layout' => array( 'display' => 'flex' ), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'assets' => array(), 'interaction' => array(),
    'component' => array( 'fingerprint' => array( 'version' => 2, 'semantic' => 'section', 'structure' => 'section', 'content_schema' => array(), 'layout' => 'flex', 'interaction' => '' ), 'repeated' => false, 'reusable' => false, 'dynamic' => false, 'content_schema' => array() ),
    'children' => array(),
);
$p0_ir = array( 'schema_version' => 4, 'type' => 'design-ir', 'nodes' => array( $p0_ir_node ), 'root_ids' => array( 'node-1' ), 'analysis_quality' => array(), 'tokens' => array(), 'diagnostics' => array() );
$p0_plan = Design_Core_Elementor_Build_Plan::create( array( Design_Core_Elementor_Build_Plan_Item::create( 'node-1', 'native-compose', 'elementor-v3' ) ) );
$p0_dummy_collaborator = new stdClass(); // analysis/normalizer/planner are never used by execute_approved_plan()

// ----------------------------------------------------------------------------
// TEST 14 (acceptance C, integration): failure after save restores exact BEFORE
// through the real execute_approved_plan() pipeline.
// ----------------------------------------------------------------------------
{
    $post_id = 400;
    $GLOBALS['dc_test_posts'][ $post_id ] = array(
        'ID' => $post_id, 'post_type' => 'page', 'post_title' => 'Integration Page', 'post_content' => '',
        'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'integration-page', 'post_parent' => 0,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );
    $GLOBALS['dc_test_post_meta'][ $post_id ] = array( '_elementor_data' => 'A' );

    add_filter( 'design_core_elementor_test_fail_after_stage', function ( $value, $stage, $failing_post_id ) use ( $post_id ) {
        return $failing_post_id === $post_id ? new WP_Error( 'design_core_test_injected', 'Injected test failure after save.' ) : $value;
    }, 10, 3 );

    $service = new Design_Core_Elementor_Conversion_Service( $p0_dummy_collaborator, $p0_dummy_collaborator, $p0_dummy_collaborator, new DC_Test_P0_Fake_Executor() );
    $result = $service->execute_approved_plan( $post_id, $p0_ir, $p0_plan );

    dc_assert( 'failed' === ( $result['status'] ?? '' ), 'integration failure-after-save: execute_approved_plan reports failure' );
    dc_assert( 'Injected test failure after save.' === ( $result['error'] ?? '' ), 'integration failure-after-save: failure is the injected one, not an earlier stage' );
    dc_assert( 'rolled-back' === ( $result['rollback']['status'] ?? '' ), 'integration failure-after-save: rollback status is rolled-back' );
    dc_assert( null !== get_post( $post_id ), 'integration failure-after-save: existing page still exists' );
    dc_assert( 'A' === get_post_meta( $post_id, '_elementor_data', true ), 'integration failure-after-save: elementor data restored to exact BEFORE through the real service' );
    dc_assert( ! in_array( $post_id, $GLOBALS['dc_test_deleted_post_ids'], true ), 'integration failure-after-save: existing page ID never sent to the delete path' );
}
echo "✓ Test 14: execute_approved_plan() integration -- failure after save restores BEFORE\n";

// ----------------------------------------------------------------------------
// TEST 15 (acceptance D/E, integration): a concurrent edit landing after Design Core's
// own save survives rollback through the real execute_approved_plan() pipeline.
// ----------------------------------------------------------------------------
{
    $post_id = 401;
    $GLOBALS['dc_test_posts'][ $post_id ] = array(
        'ID' => $post_id, 'post_type' => 'page', 'post_title' => 'Integration Conflict Page', 'post_content' => '',
        'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'integration-conflict', 'post_parent' => 0,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed',
    );
    $GLOBALS['dc_test_post_meta'][ $post_id ] = array( '_elementor_data' => 'A' );

    add_filter( 'design_core_elementor_test_fail_after_stage', function ( $value, $stage, $failing_post_id ) use ( $post_id ) {
        if ( $failing_post_id !== $post_id ) { return $value; }
        update_post_meta( $post_id, '_elementor_data', 'C-external' ); // simulated concurrent edit
        return new WP_Error( 'design_core_test_injected', 'Injected test failure after save (concurrent-edit scenario).' );
    }, 10, 3 );

    $service = new Design_Core_Elementor_Conversion_Service( $p0_dummy_collaborator, $p0_dummy_collaborator, $p0_dummy_collaborator, new DC_Test_P0_Fake_Executor() );
    $result = $service->execute_approved_plan( $post_id, $p0_ir, $p0_plan );

    dc_assert( 'failed' === ( $result['status'] ?? '' ), 'integration concurrent-conflict: execute_approved_plan reports failure' );
    dc_assert( 'Injected test failure after save (concurrent-edit scenario).' === ( $result['error'] ?? '' ), 'integration concurrent-conflict: failure is the injected one, not an earlier stage' );
    dc_assert( 'rollback-failed' === ( $result['rollback']['status'] ?? '' ), 'integration concurrent-conflict: rollback reports the conflict, not a clean rollback' );
    dc_assert( in_array( 'post-rollback-conflict:' . $post_id, (array) ( $result['rollback']['errors'] ?? array() ), true ), 'integration concurrent-conflict: stable conflict error code surfaces through the real service' );
    dc_assert( 'C-external' === get_post_meta( $post_id, '_elementor_data', true ), 'integration concurrent-conflict: external edit survives rollback through the real service' );
    dc_assert( null !== get_post( $post_id ), 'integration concurrent-conflict: page still exists' );
}
echo "✓ Test 15: execute_approved_plan() integration -- concurrent edit survives rollback\n";

// ============================================================================
// Summary
// ============================================================================

dc_finish( 'P0: Existing Page Transaction Safety' );

}
