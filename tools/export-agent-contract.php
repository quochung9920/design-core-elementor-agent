<?php
/** Generate transport schemas and verify their tracked fingerprint. No WP bootstrap. */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/core/agent/protocol.php';
$contract = array( 'contract_version' => Design_Core_Agent_Protocol::VERSION, 'namespace' => 'design-core/v1', 'operations' => Design_Core_Agent_Protocol::catalog() );
$encoded = json_encode( $contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
$directory = dirname( __DIR__ ) . '/docs/vi/';
$fingerprint = hash( 'sha256', $encoded ) . "\n";
if ( in_array( '--write', $argv, true ) ) {
    file_put_contents( $directory . 'agent-contract.json', $encoded );
    file_put_contents( $directory . 'agent-contract.sha256', $fingerprint );
    echo "Agent contract and fingerprint generated.\n"; exit( 0 );
}
if ( ! is_file( $directory . 'agent-contract.sha256' ) || file_get_contents( $directory . 'agent-contract.sha256' ) !== $fingerprint ) {
    fwrite( STDERR, "Agent contract fingerprint is stale. Run php tools/export-agent-contract.php --write\n" ); exit( 1 );
}
if ( is_file( $directory . 'agent-contract.json' ) && file_get_contents( $directory . 'agent-contract.json' ) !== $encoded ) {
    fwrite( STDERR, "Local generated agent-contract.json is stale. Regenerate it.\n" ); exit( 1 );
}
echo "Agent REST/MCP contract matches its tracked fingerprint.\n";
