<?php
/** Standalone contract tests; WordPress/Elementor I/O is deliberately simulated. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DESIGN_CORE_ELEMENTOR_PATH', dirname( __DIR__, 2 ) . '/' );
$GLOBALS['actor'] = 1; $GLOBALS['allow'] = true; $GLOBALS['env'] = 'staging'; $GLOBALS['write_enabled'] = true;
$GLOBALS['transients'] = array(); $GLOBALS['options'] = array(); $GLOBALS['pages'] = array(); $GLOBALS['meta'] = array();
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message, $data = array() ) { $this->code=$code;$this->message=$message;$this->data=$data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_current_user_id(){return $GLOBALS['actor'];}
function current_user_can($cap,...$args){return $GLOBALS['allow'];}
function home_url($path=''){return 'https://example.invalid'.$path;}
function wp_salt($kind){return 'unit-test-signing-key-not-a-production-credential';}
function wp_strip_all_tags($s){return strip_tags($s);}
function get_post($id){return $GLOBALS['pages'][$id]??null;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function get_transient($key){$r=$GLOBALS['transients'][$key]??null;return $r&&$r['expires']>=time()?$r['data']:false;}
function set_transient($key,$value,$ttl){$GLOBALS['transients'][$key]=array('data'=>$value,'expires'=>time()+$ttl);return true;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function add_option($key,$value,$unused='',$autoload=false){if(array_key_exists($key,$GLOBALS['options']))return false;$GLOBALS['options'][$key]=$value;return true;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
function delete_option($key){unset($GLOBALS['options'][$key]);return true;}
function wp_check_post_lock($id){return $GLOBALS['human_lock']??false;}
function register_rest_route($namespace,$path,$args){$GLOBALS['routes'][$path]=$args;}
function wp_register_ability($name,$args){$GLOBALS['abilities'][$name]=$args;}
function rest_ensure_response($x){return $x;}
function add_action($hook,$callback,$priority=10){$GLOBALS['hooks'][$hook][]=$callback;}
// A narrow schema double, NOT a substitute for the real WP validator integration test.
function rest_validate_value_from_schema($value,$schema,$path=''){
    $type=$schema['type']??null;
    $valid= match($type){'object'=>is_array($value)&&(!$value||!array_is_list($value)), 'array'=>is_array($value)&&array_is_list($value), 'string'=>is_string($value), 'integer'=>is_int($value), 'boolean'=>is_bool($value), default=>true};
    if(!$valid)return new WP_Error('schema_type',$path);
    if(isset($schema['enum'])&&!in_array($value,$schema['enum'],true))return new WP_Error('schema_enum',$path);
    if('object'===$type){
        foreach($schema['required']??array() as $key){if(!array_key_exists($key,$value))return new WP_Error('schema_required',$path.'/'.$key);}
        foreach($value as $key=>$item){if(!isset($schema['properties'][$key])){if(false===($schema['additionalProperties']??true))return new WP_Error('schema_additional',$path.'/'.$key);continue;} $r=rest_validate_value_from_schema($item,$schema['properties'][$key],$path.'/'.$key);if(is_wp_error($r))return $r;}
    }
    if('array'===$type){if(count($value)<($schema['minItems']??0)||count($value)>($schema['maxItems']??PHP_INT_MAX))return new WP_Error('schema_length',$path);foreach($value as $i=>$item){$r=rest_validate_value_from_schema($item,$schema['items']??array(),$path.'/'.$i);if(is_wp_error($r))return $r;}}
    if(is_string($value)&&(strlen($value)<($schema['minLength']??0)||strlen($value)>($schema['maxLength']??PHP_INT_MAX)))return new WP_Error('schema_length',$path);
    if(is_int($value)&&($value<($schema['minimum']??PHP_INT_MIN)||$value>($schema['maximum']??PHP_INT_MAX)))return new WP_Error('schema_range',$path);
    return true;
}
class Design_Core_Elementor_MCP_Ability_Bridge { public static function permission($cap){return $GLOBALS['actor']===1&&$GLOBALS['allow']?true:new WP_Error('owner_forbidden','Denied');} }
class Design_Core_Elementor_Remote_Settings {public static function environment(){return $GLOBALS['env'];}}
class Design_Core_Elementor_Remote_Write_Guard {
    public static function ensure_writes_enabled(){return $GLOBALS['write_enabled']?true:new WP_Error('writes_disabled','Disabled');}
    public static function ensure_credential_environment_match($p){return isset($p['environment'])&&$p['environment']!==$GLOBALS['env']?new WP_Error('env_mismatch','Mismatch'):true;}
}
class Design_Core_Elementor_API_Credential_Auth {
    public static function resolve_from_request($r){return $r->principal??new WP_Error('auth_required','Required');}
    public static function principal_has_scope($p,$s){return in_array($s,$p['scopes']??array(),true);}
}
class Design_Core_Elementor_Breakpoint_Registry {public function all(){return array('mobile'=>767,'tablet'=>1024,'laptop'=>1366);}}
class Design_Core_Elementor_Site_Intelligence {public function site_design_system(){return array('generated_at'=>gmdate('c'),'tokens'=>array('color'=>$GLOBALS['kit_color']??'#000000'));}}
class Design_Core_Elementor_Component_Registry {public function all(){return array('component-a'=>array('title'=>'Card','private_key'=>'should never appear','structure'=>array('children'=>array())));}}
class Design_Core_Elementor_V3_Adapter {
    public function save_page($id,$elements,$settings=array()){
        $GLOBALS['save_calls']=($GLOBALS['save_calls']??0)+1;
        $GLOBALS['meta'][$id]['_elementor_data']=json_encode($elements);
        if(!empty($GLOBALS['save_fail']))return new WP_Error('render_failed','Simulated');
        return true;
    }
    public function reload($id){return json_decode($GLOBALS['meta'][$id]['_elementor_data'],true);}
    public function last_persistence_evidence(){return array('history_entry_id'=>'test-history');}
}
require_once DESIGN_CORE_ELEMENTOR_PATH.'core/design-run-trace.php';
require_once DESIGN_CORE_ELEMENTOR_PATH.'core/agent-draft-writes.php';
foreach(array('contract','knowledge','validator','plans','protocol') as $file)require_once DESIGN_CORE_ELEMENTOR_PATH.'core/agent/'.$file.'.php';
$assertions=0;$failures=0;
function check($condition,$name){global $assertions,$failures;$assertions++;if(!$condition){$failures++;fwrite(STDERR,"FAIL: $name\n");}}
function code_is($x,$code){return is_wp_error($x)&&$x->get_error_code()===$code;}
function collect_pages($callback,$input=array()){
    $rows=array();$cursor=null;$calls=0;
    do{$p=$callback(array_merge($input,$cursor?array('cursor'=>$cursor):array()));if(is_wp_error($p))throw new RuntimeException($p->get_error_code());$rows=array_merge($rows,$p['items']);$cursor=$p['next_cursor'];if(++$calls>1000)throw new RuntimeException('Pagination loop');}while($cursor);
    return $rows;
}
class TestRegistry {
    public $controls; public function __construct($controls){$this->controls=$controls;}
    public function schema($type,$name=''){return array('controls'=>$this->controls,'runtime'=>array('elementor'=>'test'));}
}
class TestWidget {private $name;public function __construct($name){$this->name=$name;}public function get_title(){return $this->name;}public function get_keywords(){return array('heading');}}

// Complete stable pagination, cursor scope, expiry and tampering.
$rows=array_map(static fn($n)=>array('n'=>$n),range(1,1251));
$all=collect_pages(static fn($i)=>Design_Core_Agent_Contract::page($rows,$i,'big'),array('limit'=>37));
check($all===$rows,'1251 rows are retrieved without omission or duplication');
$p=Design_Core_Agent_Contract::page($rows,array('limit'=>1),'big');
check(code_is(Design_Core_Agent_Contract::page($rows,array('cursor'=>$p['next_cursor'].'x'),'big'),'design_core_agent_cursor'),'tampered cursor denied');
check(code_is(Design_Core_Agent_Contract::page($rows,array('cursor'=>$p['next_cursor']),'other'),'design_core_agent_cursor_scope'),'cross-query cursor denied');
$GLOBALS['actor']=2;check(code_is(Design_Core_Agent_Contract::page($rows,array('cursor'=>$p['next_cursor']),'big'),'design_core_agent_cursor_scope'),'cross-owner cursor denied');$GLOBALS['actor']=1;
check(code_is(Design_Core_Agent_Contract::page(array_slice($rows,1),array('cursor'=>$p['next_cursor']),'big'),'design_core_agent_snapshot_stale'),'revision change denied');
check(code_is(Design_Core_Agent_Contract::page($rows,array('snapshot_id'=>'old'),'big'),'design_core_agent_snapshot_stale'),'explicit stale snapshot denied');
$token=explode('.',$p['next_cursor']);$body=json_decode(base64_decode(strtr($token[0],'-_','+/')),true);$body['expires']=time()-1;$encoded=rtrim(strtr(base64_encode(json_encode($body)),'+/','-_'),'=');$expired=$encoded.'.'.hash_hmac('sha256',$encoded,wp_salt('auth'));
check(code_is(Design_Core_Agent_Contract::page($rows,array('cursor'=>$expired),'big'),'design_core_agent_cursor_expired'),'expired cursor denied');
$huge=array_fill(0,100,array('text'=>str_repeat('x',1000)));$p=Design_Core_Agent_Contract::page($huge,array('limit'=>100),'bytes');
check($p['returned']<100&&$p['has_more'],'byte limit creates continuation rather than truncation');
check(collect_pages(static fn($i)=>Design_Core_Agent_Contract::page($huge,$i,'bytes'))===$huge,'byte-bounded pages round trip');

// Pointer access preserves long Unicode text, null and escaped keys.
$text=str_repeat("Ti\u{1ebf}ng Vi\u{1ec7}t \u{1f642}\n",3000);
$parts=collect_pages(static fn($i)=>Design_Core_Agent_Contract::read(array('a/b~'=>$text),$i,'unicode'),array('pointer'=>'/a~1b~0','limit'=>2));
check(implode('',array_column($parts,'text'))===$text,'UTF-8 chunks round trip exactly');
check(code_is(Design_Core_Agent_Contract::read(array(),array('pointer'=>'/bad~2'),'x'),'design_core_agent_pointer'),'malformed pointer denied');
$null=Design_Core_Agent_Contract::read(array('a'=>null),array('pointer'=>'/a'),'null');check(array_key_exists('value',$null['items'][0])&&null===$null['items'][0]['value'],'null is preserved');
$omitted=array();$safe=Design_Core_Agent_Contract::safe(array('api_key'=>'secret','callback'=>static fn()=>1,'items'=>range(1,700)),$omitted);
check(count($safe['items'])===700,'transport does not cut arrays at 500');
check(count($omitted)===2&&!str_contains(json_encode($safe),'secret'),'secrets and closures explicitly unavailable');

// Catalog beyond 200, schema beyond 500, nested definitions and container support.
$controls=array();for($i=0;$i<701;$i++)$controls['setting_'.$i]=array('name'=>'setting_'.$i,'type'=>'text');
$controls['form_fields']=array('type'=>'form-fields-repeater','fields'=>array(array('name'=>'field_label','type'=>'text'),array('name'=>'required','type'=>'switcher','return_value'=>'true')));
$controls['_transform_rotateZ_effect']=array('type'=>'number');
$registry=new TestRegistry($controls);$widgets=array();for($i=0;$i<251;$i++)$widgets['w'.$i]=new TestWidget('w'.$i);
$knowledge=new Design_Core_Agent_Knowledge($registry,$widgets);
$catalog=collect_pages(fn($i)=>$knowledge->catalog($i),array('limit'=>23));check(count($catalog)===251,'catalog retrieves 251 widgets');
$schema=collect_pages(fn($i)=>$knowledge->element_schema($i),array('element_type'=>'container','limit'=>31));
check(count($schema)===705,'container schema retrieves every control including nested fields');
check(in_array('form_fields.field_label',array_column($schema,'control_path'),true),'nested repeater field is addressable');
$detail=$knowledge->control_detail(array('element_type'=>'container','pointer'=>'/controls/form_fields/fields/1/return_value'));
check($detail['items'][0]['text']==='true','runtime switcher return_value is preserved');
check($knowledge->catalog(array('query'=>'form'))['total']===0,'transform is not mistaken for form identity');
check($knowledge->catalog(array('required_controls'=>array('nonexistent')))['total']===0,'required controls are hard filters');
$cursor=$knowledge->element_schema(array('element_type'=>'container','limit'=>1))['next_cursor'];$registry->controls['new']=array('type'=>'text');
check(code_is($knowledge->element_schema(array('element_type'=>'container','cursor'=>$cursor)),'design_core_agent_snapshot_stale'),'schema cursor rejects runtime changes');

// Realistic settings contracts with type, conditions, defaults, dimensions and repeaters.
$registry->controls=array(
    'title'=>array('type'=>'text'),'editor'=>array('type'=>'wysiwyg'),'section'=>array('type'=>'section'),
    'mode'=>array('type'=>'select','options'=>array('plain'=>'Plain','custom'=>'Custom'),'default'=>'plain'),
    'font'=>array('type'=>'font','condition'=>array('mode'=>'custom')),
    'visible'=>array('type'=>'switcher','return_value'=>'true'),
    'size'=>array('type'=>'slider','size_units'=>array('px','em'),'range'=>array('px'=>array('min'=>0,'max'=>100))),
    'padding'=>array('type'=>'dimensions','size_units'=>array('px')),
    'form_fields'=>array('type'=>'form-fields-repeater','fields'=>array('_id'=>array('type'=>'hidden'),'field_label'=>array('type'=>'text'),'required'=>array('type'=>'switcher','return_value'=>'true'))),
    'custom_css'=>array('type'=>'code'),'size_mobile'=>array('type'=>'slider','size_units'=>array('px')),
    'size_tablet_extra'=>array('type'=>'slider','size_units'=>array('px')), 'mystery'=>array('type'=>'addon-special'),
    'link'=>array('type'=>'url'),'media'=>array('type'=>'media'),
    // Matches real Elementor: a responsive control has exactly ONE schema entry (is_responsive
    // true); padding_mobile/gap_tablet etc. are never separately registered controls, only
    // settings-level device suffixes resolved against this single definition.
    'gap'=>array('type'=>'slider','is_responsive'=>true,'size_units'=>array('px'),'range'=>array('px'=>array('min'=>0,'max'=>200))),
    'align_items'=>array('type'=>'select','options'=>array('start'=>'Start','center'=>'Center')),
    // Matches the live Container's flex_direction: a choose control whose real value domain
    // lives in selectors_dictionary, not options.
    'direction'=>array('type'=>'choose','is_responsive'=>true,'selectors_dictionary'=>array('row'=>'--x:row','column'=>'--x:column'))
);
$validator=new Design_Core_Agent_Validator($knowledge);
function valid_settings($v,$s){$r=$v->validate(array('element_type'=>'container','settings'=>$s));return !is_wp_error($r)&&$r['valid'];}
function issue_codes($v,$s){$r=$v->validate(array('element_type'=>'container','settings'=>$s));return is_wp_error($r)?array('WP_ERROR:'.$r->get_error_code()):array_column($r['issues'],'code');}
check(valid_settings($validator,array('title'=>'Hello')),'ordinary text accepted');
check(valid_settings($validator,array('editor'=>'<p>Hello <strong>world</strong></p>')),'inline rich text accepted');
check(!valid_settings($validator,array('totally_invented_font_size'=>44)),'invented controls denied');
check(!valid_settings($validator,array('section'=>'yes')),'editor UI control denied');
check(!valid_settings($validator,array('font'=>'Inter')),'inactive condition denied');
check(valid_settings($validator,array('mode'=>'custom','font'=>'Inter')),'satisfied condition accepted');
check(valid_settings($validator,array('visible'=>'true')),'switcher runtime return_value accepted');
check(!valid_settings($validator,array('visible'=>'yes')),'guessed switcher value denied');
check(valid_settings($validator,array('size'=>array('size'=>24,'unit'=>'px'))),'valid slider accepted');
check(!valid_settings($validator,array('size'=>array('size'=>500,'unit'=>'px'))),'out-of-range slider denied');
check(!valid_settings($validator,array('size'=>array('size'=>24,'unit'=>'vh'))),'unsupported slider unit denied');
check(!valid_settings($validator,array('padding'=>array('top'=>'1','unit'=>'px'))),'partial dimensions denied');
check(!valid_settings($validator,array('size_tablet_extra'=>array('size'=>24,'unit'=>'px'))),'inactive breakpoint denied');
check(valid_settings($validator,array('size_mobile'=>array('size'=>24,'unit'=>'px'))),'active breakpoint accepted');

// Regression: rc21 field report -- container padding validated on desktop but a device-suffixed
// name with no literal control entry (real Elementor shape) was rejected unknown_control because
// the breakpoint check ran after an unconditional exact-name lookup, never a reachable path.
check(valid_settings($validator,array('gap'=>array('size'=>16,'unit'=>'px'))),'responsive base control validates on desktop (no suffix)');
check(valid_settings($validator,array('gap_mobile'=>array('size'=>16,'unit'=>'px'))),'device-suffixed name with no literal control resolves to its responsive base on an active breakpoint');
check(valid_settings($validator,array('gap_tablet'=>array('size'=>16,'unit'=>'px'))),'a second active breakpoint also resolves against the same responsive base');
check(!valid_settings($validator,array('gap'=>array('size'=>999,'unit'=>'px'))),'range validation still applies to the responsive base itself');
check(in_array('inactive_breakpoint',issue_codes($validator,array('gap_widescreen'=>array('size'=>16,'unit'=>'px'))),true),'device suffix on an inactive breakpoint is denied even though the base control is responsive');
check(in_array('unknown_control',issue_codes($validator,array('nonexistent_base_mobile'=>array('size'=>16,'unit'=>'px'))),true),'a device suffix is never blindly accepted -- no matching base control is still unknown_control');
check(in_array('not_responsive_control',issue_codes($validator,array('align_items_mobile'=>'center')),true),'a device suffix on a control that is not responsive is rejected, not silently accepted');
check(valid_settings($validator,array('align_items'=>'center')),'the same non-responsive control validates normally without a device suffix');

// Regression: choose controls (e.g. Container flex_direction) can carry their real enum in
// selectors_dictionary instead of options; confirmed against the live Elementor runtime.
check(valid_settings($validator,array('direction'=>'column')),'choose control validates its enum from selectors_dictionary when options are absent');
check(!valid_settings($validator,array('direction'=>'diagonal')),'choose control rejects a value outside the selectors_dictionary enum');
check(valid_settings($validator,array('direction_mobile'=>'row')),'choose control responsive device suffix resolves to base and validates against the same enum');
check(in_array('inactive_breakpoint',issue_codes($validator,array('direction_widescreen'=>'row')),true),'choose control device suffix is still subject to the active-breakpoint gate');
check(!valid_settings($validator,array('custom_css'=>'selector{display:grid}')),'strict plans reject CSS fallback');
check(!valid_settings($validator,array('editor'=>'<div class="steps"><article>one</article></div>')),'layout inside Text Editor denied');
check(!valid_settings($validator,array('editor'=>'<style>body{display:none}</style>')),'stylesheet inside Text Editor denied');
check(!valid_settings($validator,array('editor'=>'<img src=x onerror=alert(1)>')),'event handlers denied');
check(!valid_settings($validator,array('link'=>array('url'=>'javascript:alert(1)'))),'unsafe link denied');
check(!valid_settings($validator,array('mystery'=>array('x'=>1))),'unknown control type fails explicitly');
check(valid_settings($validator,array('form_fields'=>array(array('_id'=>'row1','field_label'=>'Email','required'=>'true')))),'repeater contract accepted');
check(!valid_settings($validator,array('form_fields'=>array(array('_id'=>'x'),array('_id'=>'x')))),'duplicate repeater IDs denied');
check(!valid_settings($validator,array('form_fields'=>array(array('invented'=>'x')))),'unknown repeater property denied');

// Page read is detailed, revision-bound and non-mutating.
$GLOBALS['pages'][81]=(object)array('post_status'=>'draft','post_modified_gmt'=>'2026-09-07 00:00:00');
$elements=array(array('id'=>'abcdef1','elType'=>'container','settings'=>array(),'elements'=>array(array('id'=>'abcdef2','elType'=>'widget','widgetType'=>'w1','settings'=>array('title'=>'Example'),'elements'=>array()))));
$GLOBALS['meta'][81]['_elementor_data']=json_encode($elements);
$tree=$knowledge->page_tree(array('page_id'=>81,'limit'=>1));check($tree['total']===2&&$tree['has_more'],'tree index paginates');
$read=$knowledge->page_element(array('page_id'=>81,'element_id'=>'abcdef2','pointer'=>'/settings/title'));
check($read['items'][0]['text']==='Example','element stored content readable');
$lib=$knowledge->library(array('kind'=>'components','pointer'=>'/component-a/private_key'));
check($lib['items'][0]['value']==='redacted','library secret is explicitly redacted');
check(code_is($knowledge->docs(array('document'=>'../../wp-config.php')),'design_core_agent_document_not_found'),'arbitrary filesystem read denied');

// Exact compilation and opt-in draft write workflow.
$plans=new Design_Core_Agent_Plans($knowledge);
$source=$plans->register_source(array('name'=>'Fixture','html'=>'<h1>Example</h1>'));
check(!$source['scripts_executed'],'source registration does not execute scripts');
$input=array('source_id'=>$source['source_id'],'page_id'=>81,'page_revision'=>$knowledge->page(81)['revision'],'elements'=>$elements,
    'components'=>array(array('source_ref'=>'h1','purpose'=>'Page title','behavior'=>'Static','rationale'=>'Heading displays the title; container owns layout.','element_ids'=>array('abcdef1','abcdef2'))));
$preview=$plans->preview($input);check($preview['status']==='compiled'&&!$preview['selection_changed'],'explicit plan compiled without selection changes');
check($preview['qa']['visual']==='not_verified'&&!$preview['promotion_allowed'],'compile never claims visual QA or promotion');
check(($GLOBALS['save_calls']??0)===0,'preview is mutation-free for page data');
$artifact=$plans->read_preview(array('preview_id'=>$preview['preview_id'],'pointer'=>'/elements/0/elements/0/widgetType'));
check($artifact['items'][0]['text']==='w1','compiled widget stays exactly selected');
$apply=array('preview_id'=>$preview['preview_id'],'artifact_hash'=>$preview['artifact_hash'],'confirm'=>true,'idempotency_key'=>'test-apply-001');
check(code_is($plans->apply_draft($apply),'design_core_agent_writes_disabled'),'draft writes disabled by default');
define('DESIGN_CORE_AGENT_ENABLE_DRAFT_WRITES',true);
$bad=$input;$bad['elements'][0]['elements'][0]['settings']['editor']='<div>layout</div>';$result=$plans->preview($bad);
check($result['status']==='invalid'&&!$result['can_apply'],'invalid plan does not get a ticket');
$bad=$input;$bad['components'][0]['element_ids']=array('abcdef1');check($plans->preview($bad)['status']==='invalid','unexplained widget denied');
$bad=$apply;$bad['artifact_hash']='wrong';check(code_is($plans->apply_draft($bad),'design_core_agent_artifact_mismatch'),'wrong artifact hash denied');
$bad=$apply;$bad['confirm']=false;check(code_is($plans->apply_draft($bad),'design_core_agent_confirm'),'missing confirmation denied');
$GLOBALS['env']='production';check(code_is($plans->apply_draft($apply),'design_core_agent_environment'),'production writes denied');$GLOBALS['env']='staging';
$GLOBALS['kit_color']='#ffffff';check(code_is($plans->apply_draft($apply),'design_core_agent_design_system_stale'),'changed kit invalidates artifact');unset($GLOBALS['kit_color']);
$registry->controls['change']=array('type'=>'text');check(code_is($plans->apply_draft($apply),'design_core_agent_schema_stale'),'schema change invalidates artifact');unset($registry->controls['change']);
$GLOBALS['human_lock']=true;check(code_is($plans->apply_draft($apply),'design_core_agent_editor_lock'),'human editor lock respected');$GLOBALS['human_lock']=false;
$GLOBALS['pages'][81]->post_status='publish';check(code_is($plans->apply_draft($apply),'design_core_agent_page_stale'),'published target denied');$GLOBALS['pages'][81]->post_status='draft';
$saved=$plans->apply_draft($apply);check($saved['status']==='saved_to_draft'&&$saved['exact_tree_match'],'governed draft write reloaded exactly');

// Regression: a live apply-draft against real Elementor persistence reported
// exact_tree_match=false even though the reload was semantically identical, because
// Elementor's own save path fills a default isInner=false that the protocol documents
// as optional on the way in. strip_default_is_inner must neutralize only that default.
$strip=new ReflectionMethod('Design_Core_Agent_Plans','strip_default_is_inner');$strip->setAccessible(true);
$omitted=array(array('id'=>'x','elType'=>'container','elements'=>array()));
$defaulted=array(array('id'=>'x','elType'=>'container','elements'=>array(),'isInner'=>false));
$meaningful=array(array('id'=>'x','elType'=>'container','elements'=>array(),'isInner'=>true));
check(Design_Core_Agent_Contract::hash($strip->invoke(null,$omitted))===Design_Core_Agent_Contract::hash($strip->invoke(null,$defaulted)),'an omitted isInner and an explicit default isInner=false normalize identically');
check(Design_Core_Agent_Contract::hash($strip->invoke(null,$omitted))!==Design_Core_Agent_Contract::hash($strip->invoke(null,$meaningful)),'a genuinely non-default isInner is never stripped, so a real divergence still fails the match');
$again=$plans->apply_draft($apply);check($again===$saved&&$GLOBALS['save_calls']===1,'idempotent retry does not write again');
$another=$plans->preview($input);$bad=$apply;$bad['preview_id']=$another['preview_id'];$bad['artifact_hash']=$another['artifact_hash'];
// Source/plan content is identical so the artifact hash is identical: replay is safe.
check($plans->apply_draft($bad)===$saved,'same immutable content can replay its result');
$readback=$knowledge->page_tree(array('page_id'=>81));check($readback['total']===2,'readback tree retained');
$audit=$plans->audit(array('page_id'=>81));check($audit['visual_qa']==='not_verified'&&!$audit['promotion_allowed'],'audit does not manufacture browser evidence');

// promote_draft: updates an already-published target with an artifact already applied to,
// and QA-verified on, its own draft (page 81 / idempotency key 'test-apply-001' above).
$GLOBALS['pages'][82]=(object)array('post_status'=>'publish','post_modified_gmt'=>'2026-09-07 00:00:00');
$GLOBALS['meta'][82]['_elementor_data']=json_encode(array());
$target_revision=$knowledge->page(82)['revision'];
$promote_base=array('preview_id'=>$apply['preview_id'],'artifact_hash'=>$apply['artifact_hash'],'draft_idempotency_key'=>'test-apply-001','target_page_id'=>82,'target_page_revision'=>$target_revision,'confirm'=>true);
$missing=$promote_base;$missing['draft_idempotency_key']='no-such-draft-write';$missing['idempotency_key']='promote-missing-003';
check(code_is($plans->promote_draft($missing),'design_core_agent_draft_write_not_found'),'promotion refuses without a matching recorded draft write');
$unverified=$promote_base;$unverified['idempotency_key']='promote-unverified-001';
check(code_is($plans->promote_draft($unverified),'design_core_agent_qa_not_verified'),'promotion refuses an artifact whose draft write has no passing QA record -- the honest state of every draft write until independent browser QA exists');
// Simulate what an independent browser QA runner would attach once it exists.
$draft_key='dc_agent_apply_'.hash('sha256',get_current_user_id().':test-apply-001');
$verified_record=get_option($draft_key);$verified_record['result']['visual_qa']='pass';$verified_record['result']['interaction_qa']='pass';update_option($draft_key,$verified_record,false);
$promoted=$promote_base;$promoted['idempotency_key']='promote-ok-001';$promote_ok=$plans->promote_draft($promoted);
check($promote_ok['status']==='promoted'&&$promote_ok['exact_tree_match']&&!$promote_ok['publish_status_changed'],'once real QA evidence is attached, promotion writes the verified tree to the published target');
check($plans->promote_draft($promoted)===$promote_ok,'idempotent promotion replay returns the same recorded result without writing again');
$readback_target=$knowledge->page_tree(array('page_id'=>82));check($readback_target['total']===2,'promoted target reflects the exact promoted tree');
$GLOBALS['pages'][82]->post_status='draft';$fresh_revision=$knowledge->page(82)['revision'];
$wrong_status=$promote_base;$wrong_status['target_page_revision']=$fresh_revision;$wrong_status['idempotency_key']='promote-status-001';
check(code_is($plans->promote_draft($wrong_status),'design_core_agent_target_not_published'),'promotion refuses a target that is not published');
$GLOBALS['pages'][82]->post_status='publish';
$stale=$promote_base;$stale['idempotency_key']='promote-stale-001';
check(code_is($plans->promote_draft($stale),'design_core_agent_target_page_stale'),'promotion refuses a stale target revision, even with a fully verified artifact');

// Additional adversarial and failure-path checks.
$GLOBALS['actor']=2;check(code_is($plans->read_source(array('source_id'=>$source['source_id'])),'design_core_agent_artifact_owner'),'private source is owner-bound');$GLOBALS['actor']=1;
$source_record=get_transient('dc_agent_'.$source['source_id']);$tampered=$source_record;$tampered['data']['html']='changed';set_transient('dc_agent_'.$source['source_id'],$tampered,3600);
check(code_is($plans->read_source(array('source_id'=>$source['source_id'])),'design_core_agent_artifact_integrity'),'tampered private artifact denied');set_transient('dc_agent_'.$source['source_id'],$source_record,3600);
$old=$source_record;$old['expires']=time()-1;set_transient('dc_agent_'.$source['source_id'],$old,3600);
check(code_is($plans->read_source(array('source_id'=>$source['source_id'])),'design_core_agent_artifact_expired'),'expired source denied');set_transient('dc_agent_'.$source['source_id'],$source_record,3600);
$changed=$input;$changed['elements'][0]['elements'][0]['settings']['title']='A different title';$changed_preview=$plans->preview($changed);
$conflict=$apply;$conflict['preview_id']=$changed_preview['preview_id'];$conflict['artifact_hash']=$changed_preview['artifact_hash'];
check(code_is($plans->apply_draft($conflict),'design_core_agent_idempotency_conflict'),'same idempotency key cannot authorize another artifact');
$failed_apply=$conflict;$failed_apply['idempotency_key']='test-failure-001';$GLOBALS['save_fail']=true;
$failed=$plans->apply_draft($failed_apply);$calls=$GLOBALS['save_calls'];
check($failed['status']==='failed'&&$failed['write_may_have_occurred'],'persistence failure admits possible mutation');
check($plans->apply_draft($failed_apply)===$failed&&$GLOBALS['save_calls']===$calls,'failed write retry does not write twice');
$GLOBALS['save_fail']=false;$GLOBALS['meta'][81]['_elementor_data']=json_encode($elements);
$nested=$input;$nested['elements'][0]['elements'][0]['elements']=array(array('id'=>'abcdef3','elType'=>'container','settings'=>array(),'elements'=>array()));$nested['components'][0]['element_ids'][]='abcdef3';
check(in_array('nested_widget',array_column($plans->preview($nested)['issues'],'code'),true),'unverified nested widget composition rejected');
$duplicate=$input;$duplicate['elements'][0]['elements'][0]['id']='abcdef1';
check(in_array('element_id',array_column($plans->preview($duplicate)['issues'],'code'),true),'duplicate native IDs rejected');
$unscoped=$input;$unscoped['elements'][0]['arbitrary_meta']='x';
check($plans->preview($unscoped)['status']==='invalid','arbitrary top-level element properties denied');
check(code_is($knowledge->library(array('kind'=>'media')),'design_core_agent_inventory_unavailable'),'unavailable inventory is not an empty success');
$registry->controls['gallery']=array('type'=>'gallery');$registry->controls['shadow']=array('type'=>'box_shadow');$registry->controls['icons']=array('type'=>'icons','default'=>array('value'=>'runtime-icon','library'=>'runtime-library'));
check(valid_settings($validator,array('gallery'=>array(array('id'=>1,'url'=>'https://example.invalid/image.png')))),'gallery uses structured media values');
check(!valid_settings($validator,array('gallery'=>array(array('id'=>'invalid')))),'invalid gallery media rejected');
check(valid_settings($validator,array('shadow'=>array('blur'=>10,'color'=>'#000000'))),'native shadow structure accepted');
check(!valid_settings($validator,array('shadow'=>array('blur'=>'arbitrary'))),'invalid shadow offset denied');
check(valid_settings($validator,array('icons'=>array('value'=>'runtime-icon','library'=>'runtime-library'))),'exact registered icon default accepted');
check(!valid_settings($validator,array('icons'=>array('value'=>'invented','library'=>'invented'))),'unregistered icon library denied');
unset($registry->controls['gallery'],$registry->controls['shadow'],$registry->controls['icons']);
check(!valid_settings($validator,array('link'=>array('url'=>'#section','is_external'=>'yes'))),'URL flags require booleans');
check(!valid_settings($validator,array('media'=>array('alt'=>array('not'=>'text')))),'media alt requires a string');
$GLOBALS['pages'][81]->post_modified_gmt='2026-09-07 01:00:00';
check(code_is($plans->preview($input),'design_core_agent_page_stale'),'modified timestamp invalidates plan input');$GLOBALS['pages'][81]->post_modified_gmt='2026-09-07 00:00:00';

// Contract-generated transports, owner recheck, output schemas and scoped REST.
$protocol=new Design_Core_Agent_Protocol($knowledge);$protocol->register_abilities();$protocol->register_routes();
check(count($GLOBALS['abilities'])===16&&count($GLOBALS['routes'])===16,'REST and MCP are generated from the same 16-operation catalog');
foreach(Design_Core_Agent_Protocol::catalog() as $slug=>$op){check($GLOBALS['abilities'][$op['ability']]['input_schema']===$op['input_schema'],'input schema parity: '.$slug);check(isset($GLOBALS['routes'][$op['path']]),'REST route exists: '.$slug);}
$GLOBALS['actor']=2;check(code_is($protocol->execute('context',array()),'owner_forbidden'),'public execute rechecks owner');$GLOBALS['actor']=1;
check(is_wp_error($protocol->execute('catalog',array('owner_user_id'=>2))),'caller cannot supply auth context');
check(is_wp_error($protocol->execute('catalog',array('limit'=>101))),'input schema bound enforced');
$context=$protocol->execute('context',array());check($context['agent_operation_count']===16&&$context['promotion_supported'],'context declares actual scope');
class TestRequest {public $principal;private $data;public function __construct($principal,$data=array()){$this->principal=$principal;$this->data=$data;}public function get_query_params(){return $this->data;}public function get_json_params(){return $this->data;}}
$route=$GLOBALS['routes']['/agent/context'];$request=new TestRequest(array('scopes'=>array()));
check(code_is($route['permission_callback']($request),'design_core_agent_scope'),'REST token scope checked');
$request=new TestRequest(array('scopes'=>array('design_core_read')));check(true===$route['permission_callback']($request),'REST read scope accepted');
check($route['callback']($request)['status']==='ok','REST callback returns structured data');
$bucket='dc_agent_rate_1_'.(int)floor(time()/60);set_transient($bucket,120,65);check(code_is($protocol->execute('context',array()),'design_core_agent_rate_limit'),'MCP request budget enforced');

$result=array('suite'=>'agent-contract','assertions'=>$assertions,'failures'=>$failures,'runtime'=>'PHP '.PHP_VERSION,'scope'=>'Standalone tests with WordPress/Elementor I/O doubles. Not browser or live OAuth E2E.');
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($failures?1:0);
