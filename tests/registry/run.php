<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
dc_require( array( 'core/fingerprint-service.php', 'core/section-fingerprint-service.php', 'core/registry-item-validator.php', 'core/versioned-registry.php', 'core/component-registry.php', 'core/section-registry.php', 'core/widget-registry.php', 'core/design-token-service.php', 'core/registry-exporter.php' ) );

function dc_registry_item( $id = 'feature-card' ) {
    return array(
        'id' => $id, 'type' => 'component', 'schema_version' => 2, 'item_version' => 1,
        'created_at' => '2026-08-31T12:00:00Z', 'updated_at' => '2026-08-31T12:00:00Z',
        'source' => array( 'kind' => 'analysis' ),
        'usage' => array( 'count' => 0, 'locations' => array() ),
        'fingerprint' => array( 'version' => 2, 'semantic' => 'feature-card', 'structure' => 'article|h3,p', 'content_schema' => array( 'heading', 'rich_text' ), 'layout' => 'stack', 'interaction' => '' ),
        'master' => array( 'structure' => array( 'article', 'h3', 'p' ), 'layout' => array(), 'shared_styles' => array(), 'responsive_rules' => array(), 'editable_schema' => array( 'heading', 'rich_text' ) ),
        'instances' => array(),
    );
}
$validator = new Design_Core_Elementor_Registry_Item_Validator();
dc_assert( true === $validator->validate( dc_registry_item() ), 'valid component accepted' );
$widget = dc_registry_item( 'widget-card' ); $widget['type'] = 'widget';
dc_assert( true === $validator->validate( $widget ), 'valid widget accepted' );
$cases = array(
    'item type' => 42,
    'id type' => array_replace( dc_registry_item(), array( 'id' => array( 'bad' ) ) ),
    'source type' => array_replace( dc_registry_item(), array( 'source' => 'bad' ) ),
    'usage type' => array_replace( dc_registry_item(), array( 'usage' => 'bad' ) ),
    'count type' => array_replace_recursive( dc_registry_item(), array( 'usage' => array( 'count' => array( 1 ) ) ) ),
    'locations type' => array_replace_recursive( dc_registry_item(), array( 'usage' => array( 'locations' => 'bad' ) ) ),
    'fingerprint type' => array_replace( dc_registry_item(), array( 'fingerprint' => 'bad' ) ),
    'fingerprint version' => array_replace_recursive( dc_registry_item(), array( 'fingerprint' => array( 'version' => 999 ) ) ),
    'fingerprint missing version' => array_replace( dc_registry_item(), array( 'fingerprint' => array( 'semantic' => 'feature-card' ) ) ),
    'fingerprint semantic type' => array_replace_recursive( dc_registry_item(), array( 'fingerprint' => array( 'semantic' => array( 'bad' ) ) ) ),
    'created string' => array_replace( dc_registry_item(), array( 'created_at' => 'not-a-date' ) ),
    'created array' => array_replace( dc_registry_item(), array( 'created_at' => array( 'not-a-date' ) ) ),
    'updated array' => array_replace( dc_registry_item(), array( 'updated_at' => array() ) ),
    'updated string' => array_replace( dc_registry_item(), array( 'updated_at' => 'not-a-date' ) ),
);
foreach ( $cases as $label => $invalid ) { try { $validator->validate( $invalid ); dc_assert( false, $label . ' rejected' ); } catch ( Throwable $e ) { dc_assert( $e instanceof InvalidArgumentException, $label . ' rejected gracefully' ); } }

$registry = new Design_Core_Elementor_Component_Registry();
$first = $registry->upsert( dc_registry_item() );
dc_assert( ! is_wp_error( $first ), 'component upsert succeeds' );
$master_id = $first['id'];
$instance_a = $registry->register_instance( $master_id, array( 'heading' => 'A', 'rich_text' => 'One' ), 'page-a' );
$instance_b = $registry->register_instance( $master_id, array( 'heading' => 'B', 'rich_text' => 'Two' ), 'page-b' );
$stored = $registry->get( $master_id );
dc_assert( $master_id === $stored['id'], 'Page A and B share one master' );
dc_assert( $instance_a['content_bindings'] !== $instance_b['content_bindings'], 'instance content remains distinct' );
dc_assert( 2 === $stored['usage']['count'], 'usage incremented for both pages' );
dc_assert( 1 === count( $registry->find_similar( dc_registry_item() ) ), 'no duplicate master created' );
$GLOBALS['dc_test_options'][ Design_Core_Elementor_Component_Registry::OPTION_KEY . '_lock' ] = array( 'token' => 'other-writer', 'expires_at' => time() + 30 );
$locked = $registry->register_instance( $master_id, array( 'heading' => 'Concurrent' ), 'page-c' );
dc_assert( is_wp_error( $locked ) && 'design_core_registry_locked' === $locked->get_error_code(), 'concurrent registry mutation fails closed while lock is held' );
unset( $GLOBALS['dc_test_options'][ Design_Core_Elementor_Component_Registry::OPTION_KEY . '_lock' ] );
$GLOBALS['dc_test_options'][ Design_Core_Elementor_Registry_Exporter::IMPORT_LOCK ] = array( 'token' => 'active-import', 'expires_at' => time() + 30 );
$blocked_by_import = $registry->register_instance( $master_id, array( 'heading' => 'Import overlap' ), 'page-import-overlap' );
dc_assert( is_wp_error( $blocked_by_import ) && 'design_core_registry_import_locked' === $blocked_by_import->get_error_code(), 'normal registry writer cannot overlap import transaction' );
$token_writer_blocked = false; try { ( new Design_Core_Elementor_Design_Token_Service() )->save( array( 'colors' => array( 'blocked' => '#fff' ) ) ); } catch ( RuntimeException $exception ) { $token_writer_blocked = 'design_core_registry_import_locked' === $exception->getMessage(); }
dc_assert( $token_writer_blocked, 'normal token writer cannot overlap registry import transaction' );
unset( $GLOBALS['dc_test_options'][ Design_Core_Elementor_Registry_Exporter::IMPORT_LOCK ] );
$GLOBALS['design_core_elementor_registry_import_token'] = 'lost-import-owner';
$lost_registry_lock = $registry->register_instance( $master_id, array( 'heading' => 'Lost import' ), 'page-lost-import' );
$lost_token_lock = false; try { ( new Design_Core_Elementor_Design_Token_Service() )->save( array() ); } catch ( RuntimeException $exception ) { $lost_token_lock = 'design_core_registry_import_lock_lost' === $exception->getMessage(); }
dc_assert( is_wp_error( $lost_registry_lock ) && 'design_core_registry_locked' === $lost_registry_lock->get_error_code() && $lost_token_lock, 'import owner fails closed after losing global import lock' );
unset( $GLOBALS['design_core_elementor_registry_import_token'] );
$malformed_tokens_safe = true; try { $normalized_tokens = ( new Design_Core_Elementor_Design_Token_Service() )->normalize( array( 'colors' => 'invalid', 'typography' => array( 'body' => array( 'family' => array( 'nested' ) ) ) ) ); } catch ( Throwable $exception ) { $malformed_tokens_safe = false; }
dc_assert( $malformed_tokens_safe && array() === $normalized_tokens['colors'] && array() === $normalized_tokens['typography']['body'], 'token normalization rejects malformed nested types without Throwable' );

$exporter = new Design_Core_Elementor_Registry_Exporter();
$export = $exporter->export();
$result = $exporter->import( $export );
dc_assert( 'imported' === $result['status'], 'export/import roundtrip succeeds' );
$valid_components = get_option( Design_Core_Elementor_Component_Registry::OPTION_KEY );
$malformed_current = array( 'schema_version' => 2, 'items' => 'untrusted-current-state' );
$GLOBALS['dc_test_options'][ Design_Core_Elementor_Component_Registry::OPTION_KEY ] = $malformed_current;
$uncaught_import = false; try { $invalid_state_result = $exporter->import( $export ); } catch ( Throwable $exception ) { $uncaught_import = true; }
dc_assert( ! $uncaught_import && 'invalid-state' === ( $invalid_state_result['status'] ?? '' ) && $malformed_current === get_option( Design_Core_Elementor_Component_Registry::OPTION_KEY ), 'import boundary governs malformed current state without mutation or Throwable' );
$GLOBALS['dc_test_options'][ Design_Core_Elementor_Component_Registry::OPTION_KEY ] = $valid_components;
$GLOBALS['dc_test_options']['design_core_elementor_registry_import_lock'] = array( 'token' => 'other-import', 'expires_at' => time() + 30 );
$result = $exporter->import( $export );
dc_assert( 'busy' === $result['status'], 'concurrent registry import fails closed' );
unset( $GLOBALS['dc_test_options']['design_core_elementor_registry_import_lock'] );
$malformed = $export; $malformed['components']['items'][0]['created_at'] = array( 'not-a-date' );
$result = $exporter->import( $malformed );
dc_assert( 'invalid-payload' === $result['status'], 'malformed timestamp returns invalid-payload' );
$before = get_option( Design_Core_Elementor_Component_Registry::OPTION_KEY );
$payload = $export; $payload['components']['items'][] = dc_registry_item( 'second' );
$widget_for_failure = dc_registry_item( 'second-widget' ); $widget_for_failure['type'] = 'widget';
$payload['widgets']['items'][] = $widget_for_failure;
$GLOBALS['dc_fail_option'] = Design_Core_Elementor_Widget_Registry::OPTION_KEY;
$result = $exporter->import( $payload );
$GLOBALS['dc_fail_option'] = '';
dc_assert( 'rolled-back' === $result['status'], 'partial persistence triggers rollback' );
dc_assert( $before === get_option( Design_Core_Elementor_Component_Registry::OPTION_KEY ), 'rollback restores component registry' );

dc_finish( 'Registry' );
