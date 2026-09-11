<?php
/**
 * Isolated transport-test doubles, not a WordPress/Elementor runtime.
 * Production catalog/controller/owner lock/write guard/idempotency code is loaded
 * unchanged below. The schema validator implements only the JSON Schema features
 * used by this catalog. Run runtime-read.php inside WordPress for real validation.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DESIGN_CORE_ELEMENTOR_VERSION', 'transport-test' );
$GLOBALS['dc_options'] = array();
$GLOBALS['dc_transients'] = array();
$GLOBALS['dc_user'] = 7;
$GLOBALS['dc_caps'] = array( 'manage_options', 'design_core_read', 'design_core_preview', 'design_core_build', 'design_core_modify', 'design_core_publish', 'design_core_rollback' );
$GLOBALS['dc_writes'] = true;
$GLOBALS['dc_routes'] = array();
$GLOBALS['dc_abilities'] = array();
$GLOBALS['dc_service_calls'] = array();
$GLOBALS['dc_audit'] = array();
$GLOBALS['dc_assertions'] = 0;
$GLOBALS['dc_failures'] = 0;
function dc_assert( $value, $message ) {
    ++$GLOBALS['dc_assertions'];
    if ( ! $value ) { ++$GLOBALS['dc_failures']; fwrite( STDERR, "FAIL: {$message}\n" ); }
}
function dc_finish() {
    echo "MCP parity and transport: {$GLOBALS['dc_assertions']} assertions, {$GLOBALS['dc_failures']} failures\n";
    exit( $GLOBALS['dc_failures'] ? 1 : 0 );
}
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message, $data = null ) { $this->code=$code; $this->message=$message; $this->data=$data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_option( $k, $default = false ) { return $GLOBALS['dc_options'][$k] ?? $default; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['dc_options'][$k]=$v; return true; }
function get_transient( $k ) { return $GLOBALS['dc_transients'][$k] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['dc_transients'][$k]=$v; return true; }
function get_current_user_id() { return $GLOBALS['dc_user']; }
function current_user_can( $cap, ...$args ) { return $GLOBALS['dc_user'] > 0 && in_array( $cap, $GLOBALS['dc_caps'], true ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function wp_generate_uuid4() { return 'transport-test-request'; }
function untrailingslashit( $v ) { return rtrim( $v, '/' ); }
function get_bloginfo( $field ) { return '7.1'; }
class WP_REST_Server { const READABLE='GET'; const CREATABLE='POST'; }
class WP_REST_Response {
    private $data;
    public function __construct( $data ) { $this->data=$data; }
    public function get_data() { return $this->data; }
}
function rest_ensure_response( $v ) { return $v instanceof WP_REST_Response ? $v : new WP_REST_Response( $v ); }
class WP_REST_Request {
    private $method; private $route; private $url=array(); private $query=array(); private $json=array(); private $raw=''; private $headers=array();
    public function __construct( $method='GET', $route='' ) { $this->method=$method; $this->route=$route; }
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
    public function set_url_params( $v ) { $this->url=$v; }
    public function get_url_params() { return $this->url; }
    public function set_query_params( $v ) { $this->query=$v; }
    public function get_query_params() { return $this->query; }
    public function set_header( $k, $v ) { $this->headers[strtolower($k)]=$v; }
    public function get_header( $k ) { return $this->headers[strtolower($k)] ?? ''; }
    public function set_body( $v ) { $this->raw=$v; $this->json=json_decode($v,true) ?: array(); }
    public function get_body() { return $this->raw; }
    public function get_json_params() { return $this->json; }
    public function set_param( $k, $v ) { $this->json[$k]=$v; }
    public function get_param( $k ) { return $this->json[$k] ?? $this->query[$k] ?? $this->url[$k] ?? null; }
}
function register_rest_route( $namespace, $route, $args ) {
    if ( isset($args['methods']) ) { $args=array($args); }
    foreach($args as $entry) { $GLOBALS['dc_routes'][]=array('namespace'=>$namespace,'route'=>$route,'args'=>$entry); }
}
function wp_register_ability( $name, $args ) { $GLOBALS['dc_abilities'][$name]=$args; return true; }
function wp_register_ability_category( $name, $args ) { return true; }
function rest_validate_value_from_schema( $value, $schema, $param='' ) {
    $error = static function($why) use ($param) { return new WP_Error('rest_invalid_param',$param.': '.$why,array('status'=>400)); };
    $type=$schema['type'] ?? null;
    if ( 'object'===$type && !is_array($value) && !is_object($value) ) { return $error('object required'); }
    if ( 'array'===$type && !is_array($value) ) { return $error('array required'); }
    if ( 'integer'===$type && !is_int($value) ) { return $error('integer required'); }
    if ( 'number'===$type && !is_numeric($value) ) { return $error('number required'); }
    if ( 'boolean'===$type && !is_bool($value) ) { return $error('boolean required'); }
    if ( 'string'===$type && !is_string($value) ) { return $error('string required'); }
    if ( isset($schema['enum']) && !in_array($value,$schema['enum'],true) ) { return $error('enum'); }
    if ( array_key_exists('const',$schema) && $value!==$schema['const'] ) { return $error('const'); }
    if ( isset($schema['minimum']) && $value<$schema['minimum'] ) { return $error('minimum'); }
    if ( isset($schema['maximum']) && $value>$schema['maximum'] ) { return $error('maximum'); }
    if ( is_string($value) ) {
        if ( isset($schema['minLength']) && strlen($value)<$schema['minLength'] ) { return $error('minLength'); }
        if ( isset($schema['maxLength']) && strlen($value)>$schema['maxLength'] ) { return $error('maxLength'); }
    }
    if ( 'object'===$type ) {
        $value=(array)$value; $props=(array)($schema['properties']??array());
        foreach((array)($schema['required']??array()) as $key) { if(!array_key_exists($key,$value)) { return $error('required '.$key); } }
        foreach($value as $key=>$item) {
            if(!array_key_exists($key,$props)) {
                if(false===($schema['additionalProperties']??true)) { return $error('unknown '.$key); }
                continue;
            }
            $valid=rest_validate_value_from_schema($item,$props[$key],$param.'.'.$key);
            if(is_wp_error($valid)) { return $valid; }
        }
    }
    if ( 'array'===$type && isset($schema['items']) ) {
        foreach($value as $item) { $valid=rest_validate_value_from_schema($item,$schema['items'],$param); if(is_wp_error($valid)) { return $valid; } }
    }
    if ( isset($schema['anyOf']) ) {
        $ok=false;
        foreach($schema['anyOf'] as $choice) {
            $choice['type']=$type;
            if(!is_wp_error(rest_validate_value_from_schema($value,$choice,$param))) { $ok=true; break; }
        }
        if(!$ok) { return $error('anyOf'); }
    }
    return true;
}

// Service/storage doubles below never touch a real site.
class Design_Core_Elementor_Remote_Settings {
    public static function environment() { return 'staging'; }
    public static function writes_enabled() { return $GLOBALS['dc_writes']; }
    public static function is_production() { return false; }
}
class Design_Core_Elementor_API_Credential_Registry { const TOKEN_PREFIX='dcapi'; }
class Design_Core_Elementor_API_Credential_Auth {
    public static function principal_has_scope( $principal, $scope ) { return in_array($scope,$principal['scopes']??array(),true); }
}
class Design_Core_Elementor_Change_Ledger {
    public static function transport_safe( $v ) { return $v; }
}
class Design_Core_Elementor_Remote_Audit_Log {
    public static function record( $v ) { $GLOBALS['dc_audit'][]=$v; }
}
class Design_Core_Elementor_WordPress_Owner_Service {
    public function __call( $name, $args ) {
        $GLOBALS['dc_service_calls'][]=array('name'=>$name,'args'=>$args);
        return array('service'=>$name,'args'=>$args);
    }
}
$dc_root=dirname(__DIR__,2);
foreach(array('design-core-capabilities','api-access-settings','gpt-actions-api','gpt-actions-rest-controller','idempotency-store','remote-write-guard','mcp-ability-bridge','permissions','agent-draft-writes','agent-gateway','wordpress-mcp-compatibility') as $file) {
    require_once $dc_root.'/core/'.$file.'.php';
}
update_option( Design_Core_Elementor_API_Access_Settings::OPTION_KEY, array('enabled'=>true,'owner_user_id'=>7) );
