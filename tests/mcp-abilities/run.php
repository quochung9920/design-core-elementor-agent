<?php
require __DIR__ . '/bootstrap.php';

$operations=Design_Core_Elementor_GPT_Actions_API::operations();
$by_id=array_column($operations,null,'operationId');
$names=Design_Core_Elementor_MCP_Ability_Bridge::ability_names();
$methods=Design_Core_Elementor_MCP_Ability_Bridge::OPERATION_METHODS;
$api_ids=array_keys($by_id); $mapped_ids=array_keys($names);
sort($api_ids); sort($mapped_ids);
dc_assert($api_ids===$mapped_ids,'all Owner API operations, including WordPress and manifest, have abilities');
dc_assert(count($api_ids)===42,'reviewed baseline is 42 operations; update this intentional contract pin when expanding');
dc_assert(count(array_unique($names))===count($names),'unique ability names');
dc_assert($names['listWordPressContent']==='design-core/list-wordpress-content','WordPress brand remains one slug token');
dc_assert($names['getWordPressSettings']==='design-core/get-wordpress-settings','WordPress settings slug is stable');
dc_assert($names['enrichDesignIR']==='design-core/enrich-design-ir','IR acronym slug stays compatible');
dc_assert($names['auditPageUX']==='design-core/audit-page-ux','UX acronym slug stays compatible');
$previous_names=array(
    'getSiteStatus'=>'get-site-status','understandSite'=>'understand-site','getSiteMap'=>'get-site-map',
    'getSiteDesignSystem'=>'get-site-design-system','searchSiteContent'=>'search-site-content',
    'getElementorCapabilities'=>'get-elementor-capabilities','getElementorCatalog'=>'get-elementor-catalog',
    'searchElementorWidgets'=>'search-elementor-widgets','getElementorWidgetSchema'=>'get-elementor-widget-schema',
    'getMediaLibrary'=>'get-media-library','planTask'=>'plan-task','getDesignIntelligenceStatus'=>'get-design-intelligence-status',
    'recommendDesign'=>'recommend-design','previewDesignSystem'=>'preview-design-system','enrichDesignIR'=>'enrich-design-ir',
    'auditPageUX'=>'audit-page-ux','previewFigma'=>'preview-figma','previewBuild'=>'preview-build',
    'createDraftPage'=>'create-draft-page','getPageSnapshot'=>'get-page-snapshot','applyPageBuild'=>'apply-page-build',
    'verifyPage'=>'verify-page','visualFeedback'=>'visual-feedback','autoCorrectPage'=>'auto-correct-page',
    'publishPage'=>'publish-page','getHistory'=>'get-history','rollbackHistory'=>'rollback-history'
);
foreach($previous_names as $id=>$slug) { dc_assert($names[$id]==='design-core/'.$slug,$id.' preserves its existing ability name'); }

dc_assert(count(array_unique($methods))===count($methods),'unique controller callbacks');
$report=Design_Core_Elementor_MCP_Ability_Bridge::contract_report();
dc_assert($report['parity']===true,'real controller implements every mapped callback');
dc_assert($report['connector_grants_verified']===false,'registration never claims OAuth/NHI grant verification');

$controller=new Design_Core_Elementor_GPT_Actions_Rest_Controller();
$controller->register_routes();
$registered=array();
foreach($GLOBALS['dc_routes'] as $r) {
    $path=preg_replace('/\(\?P<([a-z_]+)>[^)]+\)/','{$1}',$r['route']);
    $registered[$r['args']['methods'].' '.$path]=$r['args'];
}
dc_assert(count($registered)===count($operations)+1,'42 protected method/path pairs plus the public OpenAPI document');
dc_assert($registered['GET /openapi']['permission_callback']==='__return_true','only OpenAPI is intentionally public');
$openapi=Design_Core_Elementor_GPT_Actions_API::openapi('https://example.test/wp-json/design-core/v1');
$openapi_ids=array();
foreach($openapi['paths'] as $path=>$entries) {
    foreach($entries as $entry) { $openapi_ids[]=$entry['operationId']; }
}
sort($openapi_ids);
dc_assert($api_ids===$openapi_ids,'OpenAPI operation IDs match bridge exactly');

(new Design_Core_Elementor_MCP_Ability_Bridge())->register_abilities();
dc_assert(count($GLOBALS['dc_abilities'])===count($operations),'every canonical ability is registered');
$forbidden=array('run-php','execute-shell','execute-sql','edit-files','install-plugin','install-theme','read-env','read-secret','read-wp-config','set-arbitrary','set-elementor-data','delete-database','docker','ssh');
foreach($by_id as $id=>$op) {
    $ability=$GLOBALS['dc_abilities'][$names[$id]];
    $schema=$ability['input_schema'];
    $route=$registered[$op['method'].' '.$op['path']]??array();
    dc_assert(($route['callback'][1]??'')===$methods[$id],$id.' uses the actual registered REST callback');
    dc_assert(($route['permission_callback']??'')!=='__return_true',$id.' remains protected on REST');
    dc_assert(in_array($op['capability'],Design_Core_Elementor_Capabilities::all(),true),$id.' has a real scope');
    dc_assert('object'===$schema['type'],$id.' has object input');
    dc_assert(false===$schema['additionalProperties'],$id.' rejects undeclared top-level arguments');
    dc_assert($ability['meta']['show_in_rest']===false,$id.' does not enable another generic REST transport');
    dc_assert($ability['meta']['design_core_operation_id']===$id,$id.' has traceable operation metadata');
    dc_assert(true===call_user_func($ability['permission_callback']),$id.' authorized owner can pass permission');
    foreach($schema['required']??array() as $key) { dc_assert(array_key_exists($key,$schema['properties']),$id.' required '.$key.' exists'); }
    if(!$op['read_only']) {
        dc_assert(in_array('confirm',$schema['required'],true),$id.' requires confirm');
        dc_assert($ability['meta']['annotations']['destructiveHint']===true,$id.' is conservatively consequential');
        dc_assert($ability['meta']['annotations']['idempotentHint']===false,$id.' does not overclaim optional-key idempotency');
    }
    foreach($forbidden as $word) { dc_assert(false===strpos($names[$id],$word),$id.' is not an arbitrary '.$word.' capability'); }
}
dc_assert($by_id['publishPage']['capability']==='design_core_publish','publish is separate');
dc_assert($by_id['updateWordPressSettings']['capability']==='design_core_publish','site settings require publish-level capability');

// Transport spy: verify routing/alias/headers/principal for all 42 operations.
// This is not a business-logic or Elementor persistence test.
class DC_Transport_Spy {
    public $requests=array();
    public function __call($method,$arguments) {
        $r=$arguments[0]; $this->requests[]=$r;
        return new WP_REST_Response(array(
            'callback'=>$method,'route'=>$r->get_route(),'path'=>$r->get_url_params(),
            'query'=>$r->get_query_params(),'body'=>$r->get_json_params(),
            'idempotency'=>$r->get_header('idempotency-key'),'principal'=>$r->get_param('_design_core_principal')
        ));
    }
}
function dc_sample($schema) {
    if(isset($schema['enum'])) { return $schema['enum'][0]; }
    switch($schema['type']??'string') {
        case 'integer': return max(1,$schema['minimum']??1);
        case 'number': return $schema['minimum']??0.5;
        case 'boolean': return true;
        case 'object': return array();
        case 'array': return array();
        default: return 'probe';
    }
}
$property=new ReflectionProperty(Design_Core_Elementor_MCP_Ability_Bridge::class,'controller');
$property->setAccessible(true);
$spy=new DC_Transport_Spy();
$property->setValue(null,$spy);
foreach($by_id as $id=>$op) {
    $schema=Design_Core_Elementor_MCP_Ability_Bridge::input_schema_for($id,$op);
    $input=array();
    foreach($schema['properties'] as $field=>$field_schema) { $input[$field]=dc_sample($field_schema); }
    $response=Design_Core_Elementor_MCP_Ability_Bridge::execute($id,$input);
    dc_assert(!is_wp_error($response),$id.' dispatch accepts a schema-valid input');
    if(is_wp_error($response)) { continue; }
    dc_assert($response['callback']===$methods[$id],$id.' dispatches to the exact method');
    dc_assert(false===strpos($response['route'],'{'),$id.' route has no unresolved placeholder');
    dc_assert($response['principal']['type']==='user',$id.' never fabricates a Bearer credential');
    dc_assert($response['principal']['owner_user_id']===7,$id.' audit actor is the authenticated owner');
    dc_assert($response['principal']['scopes']===Design_Core_Elementor_Capabilities::all(),$id.' scopes are server-derived');
    foreach($op['parameters'] as $param) {
        $alias=Design_Core_Elementor_MCP_Ability_Bridge::FIELD_ALIASES[$id][$param['name']]??$param['name'];
        $bucket=$param['in']==='path'?'path':'query';
        dc_assert(($response[$bucket][$param['name']]??null)===$input[$alias],$id.' maps '.$alias.' to '.$param['name']);
        if($bucket==='path') { dc_assert(strpos($response['route'],rawurlencode((string)$input[$alias]))!==false,$id.' concrete path participates in idempotency'); }
    }
    foreach(array('_design_core_principal','scopes','environment','owner_user_id','unexpected_field') as $key) {
        $bad=$input; $bad[$key]='forged';
        dc_assert(is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute($id,$bad)),$id.' rejects injected '.$key);
    }
    foreach($schema['required']??array() as $required) {
        $bad=$input; unset($bad[$required]);
        dc_assert(is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute($id,$bad)),$id.' rejects missing '.$required);
    }
}

// Permission is checked at execution as well as discovery.
$GLOBALS['dc_user']=0;
dc_assert(is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute('getSiteStatus',array())),'anonymous direct execution is rejected');
$GLOBALS['dc_user']=8;
dc_assert(!is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute('getSiteStatus',array())),'capable non-owner user passes on capability, owner match is not required');
$GLOBALS['dc_user']=7;
foreach(Design_Core_Elementor_Capabilities::all() as $cap) {
    $saved=$GLOBALS['dc_caps']; $GLOBALS['dc_caps']=array_values(array_diff($saved,array($cap)));
    foreach($by_id as $id=>$op) {
        if($op['capability']===$cap) { dc_assert(is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute($id,array())),$id.' missing scope blocks before dispatch'); }
    }
    $GLOBALS['dc_caps']=$saved;
}
$saved=$GLOBALS['dc_caps']; $GLOBALS['dc_caps']=array_values(array_diff($saved,array('manage_options')));
dc_assert(!is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute('getSiteStatus',array())),'capability suffices without manage_options');
$GLOBALS['dc_caps']=$saved;
update_option(Design_Core_Elementor_API_Access_Settings::OPTION_KEY,array('enabled'=>false,'owner_user_id'=>7));
dc_assert(is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute('getSiteStatus',array())),'owner API disabled also blocks the scoped abilities');
update_option(Design_Core_Elementor_API_Access_Settings::OPTION_KEY,array('enabled'=>true,'owner_user_id'=>0));
dc_assert(!is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute('getSiteStatus',array())),'unclaimed owner does not block capable users');
update_option(Design_Core_Elementor_API_Access_Settings::OPTION_KEY,array('enabled'=>true,'owner_user_id'=>7));
dc_assert(is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute('previewBuild',array('html'=>str_repeat('a',2097153)))),'2 MB transport limit is enforced');
dc_assert(is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute('getPageSnapshot',array('id'=>1))),'raw id cannot bypass the page_id alias');
dc_assert(is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute('getPageSnapshot',array('page_id'=>0))),'invalid object ID is rejected');
dc_assert(is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute('getElementorCatalog',array('limit'=>201))),'catalog limit matches the contract');
dc_assert(is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute('publishPage',array('page_id'=>1,'confirm'=>false))),'false confirmation cannot pass');
dc_assert(is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute('publishPage',array('page_id'=>1,'confirm'=>'true'))),'string confirmation cannot pass');
dc_assert(is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute('unregisteredOperation',array())),'unknown operation is rejected');

// Real Owner controller + real Idempotency Store/Write Guard; storage and WP services
// are doubles. These assertions test transport integration, NOT actual DB writes.
$property->setValue(null,$controller);
$GLOBALS['dc_service_calls']=array(); $GLOBALS['dc_transients']=array();
$input=array('content_id'=>10,'title'=>'One','confirm'=>true,'idempotency_key'=>'same-action');
$first=Design_Core_Elementor_MCP_Ability_Bridge::execute('updateWordPressContent',$input);
$second=Design_Core_Elementor_MCP_Ability_Bridge::execute('updateWordPressContent',$input);
dc_assert(!is_wp_error($first)&&$first===$second,'same key and payload replays via the actual Owner controller/store');
dc_assert(count($GLOBALS['dc_service_calls'])===1,'replay does not dispatch a second service call');
$input['content_id']=11;
$conflict=Design_Core_Elementor_MCP_Ability_Bridge::execute('updateWordPressContent',$input);
dc_assert(is_wp_error($conflict)&&$conflict->get_error_code()==='design_core_idempotency_conflict','same key on another object conflicts instead of replaying the wrong object');
$input['content_id']=10; $input['title']='Different';
dc_assert(is_wp_error(Design_Core_Elementor_MCP_Ability_Bridge::execute('updateWordPressContent',$input)),'same key with changed content conflicts');
dc_assert($GLOBALS['dc_audit'][0]['actor']===7,'actual Owner controller attributes audit to the connected owner');
$GLOBALS['dc_writes']=false;
$blocked=Design_Core_Elementor_MCP_Ability_Bridge::execute('createWordPressContent',array('title'=>'Blocked','confirm'=>true));
dc_assert(is_wp_error($blocked)&&$blocked->get_error_code()==='design_core_remote_writes_disabled','actual global write guard blocks a WordPress create');
$GLOBALS['dc_writes']=true;
$saved=$GLOBALS['dc_caps']; $GLOBALS['dc_caps']=array_values(array_diff($saved,array('design_core_publish')));
$r=Design_Core_Elementor_MCP_Ability_Bridge::execute('createWordPressContent',array('title'=>'Draft','status'=>'publish','confirm'=>true));
dc_assert($r['args'][1]===false,'without publish capability the content service receives can_publish=false');
$GLOBALS['dc_caps']=$saved;
$r=Design_Core_Elementor_MCP_Ability_Bridge::execute('createWordPressContent',array('title'=>'Approved','status'=>'publish','confirm'=>true));
dc_assert($r['args'][1]===true,'with publish capability the same existing service receives can_publish=true');
$r=Design_Core_Elementor_MCP_Ability_Bridge::execute('trashWordPressMenuItem',array('menu_id'=>11,'item_id'=>12,'confirm'=>true));
dc_assert($r['args']===array(11,12),'menu and item path IDs stay distinct');
$r=Design_Core_Elementor_MCP_Ability_Bridge::execute('updateWordPressMedia',array('media_id'=>13,'alt'=>'Example','confirm'=>true));
dc_assert($r['args'][0]===13&&$r['args'][1]['alt']==='Example','media alias reaches the existing media service');
$r=Design_Core_Elementor_MCP_Ability_Bridge::execute('getManifest',array());
dc_assert($r['operation_count']===42&&$r['mcp_bridge']['parity']===true,'manifest reports both REST and bridge parity without claiming live grants');

// Legacy ability names are preserved, but generic mutation shortcuts are not.
$legacy=new Design_Core_Elementor_WordPress_MCP_Compatibility();
$gateway=new Design_Core_Elementor_Agent_Gateway();
$legacy->register_abilities(); $gateway->register_abilities();
dc_assert(count($GLOBALS['dc_abilities'])===50,'42 canonical + 8 legacy abilities coexist');
foreach(array('convert-html','visual-correct','history-rollback','visual-feedback') as $name) {
    $r=$gateway->ability_call_tool(array('name'=>$name,'arguments'=>array('confirm'=>true)));
    dc_assert(is_wp_error($r)&&$r->get_error_code()==='design_core_legacy_write_disabled','legacy '.$name.' cannot sidestep scoped abilities');
}
$catalog=$gateway->ability_list_tools(array());
foreach($catalog['tools'] as $tool) { dc_assert($tool['readonly']&&!$tool['destructive'],'legacy ability catalog advertises only read/preview tools'); }
$GLOBALS['dc_user']=8;
dc_assert($legacy->can_read()===true,'legacy read admits capable non-owner on capability');
dc_assert($legacy->can_preview()===true,'legacy preview admits capable non-owner on capability');
dc_assert($gateway->ability_permission()===true,'legacy meta-abilities admit capable non-owner on capability');
dc_assert(true===Design_Core_Elementor_MCP_Ability_Bridge::permission(Design_Core_Elementor_Capabilities::READ),'direct execution path rechecks capability, not owner');
dc_assert(!is_wp_error($gateway->ability_list_tools(array())),'legacy direct catalog callback enforces capability, not owner');
dc_assert(is_wp_error($gateway->ability_call_tool(array('name'=>'no-such-tool'))),'legacy direct execution rejects unknown tools at the guard');
$GLOBALS['dc_user']=0;
foreach($GLOBALS['dc_abilities'] as $name=>$definition) {
    dc_assert(true!==call_user_func($definition['permission_callback']),$name.' rejects anonymous access');
}
dc_finish();
