<?php
require dirname(__DIR__) . '/bootstrap-standalone.php';
dc_require(array(
    'core/source-fidelity-engine.php',
    'core/source-asset-contract.php',
    'core/control-coverage-intelligence.php',
    'core/breakpoint-registry.php',
    'core/computed-style-hydrator.php',
    'core/decision-engine.php',
    'core/build-plan.php',
    'core/build-plan-validator.php',
    'core/analysis-engine.php',
));

$blank = static function ( $id, $tag, $children = array(), $extra = array() ) {
    return array_replace_recursive(array(
        'id' => $id,
        'source' => array( 'tag'=>$tag, 'classes'=>array(), 'attributes'=>array(), 'dom_path'=>'/body/'.$tag.'[1]' ),
        'semantic' => array( 'role'=>$tag ),
        'content' => array( 'text'=>'', 'fields'=>array() ),
        'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'assets' => array(), 'interaction' => array(),
        'component' => array( 'fingerprint'=>array('version'=>2,'semantic'=>$tag,'structure'=>$tag,'content_schema'=>array(),'layout'=>'','interaction'=>''), 'repeated'=>false, 'dynamic'=>false, 'content_schema'=>array() ),
        'children' => $children,
    ), $extra);
};

$nodes = array(
    $blank('card','div',array('title','copy','photo'),array('semantic'=>array('role'=>'feature-card'))),
    $blank('title','h3',array(),array('content'=>array('text'=>'Several shipments'))),
    $blank('copy','p',array(),array('content'=>array('text'=>'Shared air cargo capacity.'))),
    $blank('photo','img',array(),array('content'=>array('image'=>array('url'=>'https://example.test/card.webp','alt'=>'Cargo')))),
    $blank('form','form',array('label','email','submit'),array('semantic'=>array('role'=>'form'),'source'=>array('attributes'=>array('aria-label'=>'Air enquiry')))),
    $blank('label','label',array(),array('source'=>array('attributes'=>array('for'=>'work_email')),'content'=>array('text'=>'Work email'))),
    $blank('email','input',array(),array('source'=>array('attributes'=>array('id'=>'work_email','name'=>'work_email','type'=>'email','placeholder'=>'you@example.com','required'=>'required')))),
    $blank('submit','button',array(),array('content'=>array('text'=>'Talk to our team'))),
);
$ir = array('nodes'=>$nodes,'root_ids'=>array('card','form'),'diagnostics'=>array());
$engine = new Design_Core_Elementor_Source_Fidelity_Engine();
$enriched = $engine->enrich($ir);
$by_id = array(); foreach($enriched['nodes'] as $node){$by_id[$node['id']]=$node;}

dc_assert('preserve-children' === ($by_id['card']['semantic']['composition_policy']??''), 'Feature card with heading/text/image preserves child composition.');
dc_assert(1 === (int)($by_id['card']['fidelity']['critical']['images']??0), 'Card fidelity contract records the source image atom.');
dc_assert('replace-with-verified-native' === ($by_id['form']['semantic']['composition_policy']??''), 'Form may collapse only into a verified native form implementation.');
dc_assert('work_email' === ($by_id['form']['content']['fields'][0]['id']??''), 'Form hydration preserves a stable source field ID.');
dc_assert('Work email' === ($by_id['form']['content']['fields'][0]['label']??''), 'Form hydration resolves label[for] to the source field.');
dc_assert('Talk to our team' === ($by_id['form']['content']['button_text']??''), 'Form hydration preserves source submit copy.');
dc_assert(array() === ($by_id['form']['content']['submit_actions']??null), 'Form hydration never invents email/CRM/webhook actions.');

$missing_image_tree = array(array('id'=>'f1','elType'=>'widget','widgetType'=>'form','settings'=>array('form_fields'=>array(array('custom_id'=>'work_email'))),'elements'=>array()));
$failed = $engine->verify_elementor_tree($enriched,$missing_image_tree);
dc_assert('fail' === ($failed['status']??''), 'Compiled tree fails closed when a critical source image is dropped.');
dc_assert(in_array('images-dropped:0/1',(array)($failed['issues']??array()),true), 'Critical atom failure reports the exact missing image count.');

$complete_tree = array(
    array('id'=>'i1','elType'=>'widget','widgetType'=>'image','settings'=>array('image'=>array('url'=>'https://example.test/card.webp')),'elements'=>array()),
    array('id'=>'f1','elType'=>'widget','widgetType'=>'form','settings'=>array('form_fields'=>array(array('custom_id'=>'work_email'))),'elements'=>array()),
);
$passed = $engine->verify_elementor_tree($enriched,$complete_tree);
dc_assert('pass' === ($passed['status']??''), 'Critical atom verification passes when image/form/field atoms survive compilation.');

$assets = new Design_Core_Elementor_Source_Asset_Contract();
dc_assert('relative-bundle-unresolved' === $assets->classify('assets/hero.webp'), 'Relative bundle media is explicitly unresolved without a source base/bundle.');
dc_assert('root-relative' === $assets->classify('/wp-content/uploads/hero.webp'), 'Site-root media remains a valid resolvable reference.');
$relative_ir = array('nodes'=>array($blank('relative-photo','img',array(),array('content'=>array('image'=>array('url'=>'assets/hero.webp','alt'=>'Hero'))))), 'root_ids'=>array('relative-photo'));
$asset_audit = $assets->audit($relative_ir);
dc_assert('blocked' === ($asset_audit['status']??''), 'Unresolved relative source media blocks the asset contract instead of producing a broken image.');

$decision = (new Design_Core_Elementor_Decision_Engine())->decide_node(array(
    'semantic'=>array('role'=>'feature-card','composition_policy'=>'preserve-children'),
    'component'=>array('repeated'=>false,'dynamic'=>false), 'interaction'=>array(), 'capabilities'=>array(),
    'registry_match'=>array('registry_type'=>'widget','registry_id'=>'icon-box'), 'variant_match'=>array('action'=>'reuse','score'=>0.99),
));
dc_assert('native-compose' === ($decision['strategy']??''), 'Preserve-children contract outranks shallow widget reuse.');

$collapse_plan = Design_Core_Elementor_Build_Plan::create(array(Design_Core_Elementor_Build_Plan_Item::create('card','native-widget','elementor-v3')));
$blocked = false;
try { (new Design_Core_Elementor_Build_Plan_Validator())->validate($collapse_plan,array('nodes'=>array($by_id['card']))); }
catch(InvalidArgumentException $e){ $blocked = false !== strpos($e->getMessage(),'source-fidelity contract'); }
dc_assert($blocked,'BuildPlan validator rejects a future regression that collapses preserve-children into one widget.');

$relative_plan = Design_Core_Elementor_Build_Plan::create(array(Design_Core_Elementor_Build_Plan_Item::create('relative-photo','native-compose','elementor-v3')));
$asset_blocked = false;
try { (new Design_Core_Elementor_Build_Plan_Validator())->validate($relative_plan,$relative_ir); }
catch(InvalidArgumentException $e){ $asset_blocked = false !== strpos($e->getMessage(),'Source assets are unresolved'); }
dc_assert($asset_blocked,'BuildPlan validator fails closed before mutation when source media cannot resolve on the target site.');

$coverage = (new Design_Core_Elementor_Control_Coverage_Intelligence())->evaluate(
    array('id'=>'heading','source'=>array('tag'=>'h2'),'layout'=>array(),'style'=>array('font_size'=>array('value'=>40,'unit'=>'px'),'color'=>'#111111'),'spacing'=>array('margin'=>array('top'=>0,'right'=>0,'bottom'=>24,'left'=>0,'unit'=>'px')),'responsive'=>array('mobile'=>array('style'=>array('font_size'=>array('value'=>30,'unit'=>'px')))),'interaction'=>array(),'content'=>array('text'=>'Heading'),'component'=>array()),
    'widget','heading',array('native'=>array('typography_font_size','title_color','margin','typography_font_size_mobile'),'unsupported'=>array(),'custom_css_properties'=>array())
);
dc_assert(($coverage['score']??0) > 0.7,'Control coverage intelligence scores verified native mappings.');
dc_assert(isset($coverage['categories']['responsive']),'Control coverage reports responsive mapping separately.');

$heading = $blank('browser-heading','h2',array(),array('source'=>array('dom_path'=>'/body/h2[1]'),'content'=>array('text'=>'Computed heading')));
$browser_styles = static function($font_size,$line_height,$margin_bottom){ return array(
    'display'=>'block','position'=>'static','flexDirection'=>'row','flexWrap'=>'nowrap','justifyContent'=>'normal','alignItems'=>'normal','gap'=>'normal','gridTemplateColumns'=>'none','gridTemplateRows'=>'none',
    'width'=>'600px','height'=>'48px','minWidth'=>'0px','maxWidth'=>'none','minHeight'=>'0px','maxHeight'=>'none',
    'marginTop'=>'0px','marginRight'=>'0px','marginBottom'=>$margin_bottom,'marginLeft'=>'0px','paddingTop'=>'0px','paddingRight'=>'0px','paddingBottom'=>'0px','paddingLeft'=>'0px',
    'fontFamily'=>'Geist, sans-serif','fontSize'=>$font_size,'fontWeight'=>'600','lineHeight'=>$line_height,'letterSpacing'=>'0px','color'=>'rgb(19, 20, 23)','backgroundColor'=>'rgba(0, 0, 0, 0)','backgroundImage'=>'none','backgroundSize'=>'auto','backgroundPosition'=>'0% 0%',
    'borderTopWidth'=>'0px','borderTopStyle'=>'none','borderTopColor'=>'rgb(19, 20, 23)','borderRadius'=>'0px','boxShadow'=>'none','opacity'=>'1','overflow'=>'visible','objectFit'=>'fill','objectPosition'=>'50% 50%','aspectRatio'=>'auto','zIndex'=>'auto','textAlign'=>'left','textTransform'=>'none','transform'=>'none','transition'=>'all 0s ease 0s'
); };
$browser = array('data'=>array('viewports'=>array(
    1440=>array(array('domPath'=>'/body/h2[1]','styles'=>$browser_styles('40px','48px','24px'),'rect'=>array('x'=>0,'y'=>0,'width'=>600,'height'=>48))),
    767=>array(array('domPath'=>'/body/h2[1]','styles'=>$browser_styles('30px','36px','16px'),'rect'=>array('x'=>0,'y'=>0,'width'=>320,'height'=>36))),
    390=>array(array('domPath'=>'/body/h2[1]','styles'=>$browser_styles('28px','34px','14px'),'rect'=>array('x'=>0,'y'=>0,'width'=>350,'height'=>34))),
)));
$hydrated = (new Design_Core_Elementor_Computed_Style_Hydrator())->hydrate(array('nodes'=>array($heading),'root_ids'=>array('browser-heading')),$browser);
$hydrated_heading = $hydrated['nodes'][0];
dc_assert('pass' === ($hydrated['computed_style_hydration']['status']??''),'Computed style hydrator binds browser evidence by canonical DOM path.');
dc_assert('Geist' === ($hydrated_heading['style']['font_family']??''),'Computed style hydration resolves the rendered font family instead of relying on selector heuristics.');
dc_assert(40.0 === (float)($hydrated_heading['style']['font_size']['value']??0),'Desktop computed font size becomes canonical authoring evidence.');
dc_assert(30.0 === (float)($hydrated_heading['responsive']['mobile']['style']['font_size']['value']??0),'Runtime mobile breakpoint receives the computed responsive font size.');
dc_assert(16.0 === (float)($hydrated_heading['responsive']['mobile']['spacing']['margin']['bottom']??0),'Computed responsive spacing is preserved at the matching Elementor breakpoint.');
dc_assert(!isset($hydrated_heading['layout']['width']),'Used browser geometry is not blindly converted into a fixed authored width.');
dc_assert(1 === (int)($hydrated['computed_style_hydration']['unrepresented_small_viewport_drift_count']??0),'390px-only drift is recorded for QA instead of inventing a private Elementor breakpoint.');

$analysis = new Design_Core_Elementor_Analysis_Engine();
$reflection = new ReflectionClass($analysis);
$effective = $reflection->getMethod('effective_css'); $effective->setAccessible(true);
$variables = $reflection->getMethod('resolve_root_variables'); $variables->setAccessible(true);
$to_ir = $reflection->getMethod('declarations_to_ir'); $to_ir->setAccessible(true);
$doc = new DOMDocument(); @$doc->loadHTML('<html><head><style>:root{--space:24px}.card{padding:10px 20px}</style></head><body><div class="card">X</div></body></html>');
$css = $effective->invoke($analysis,$doc,'');
dc_assert(false !== strpos($css,'.card{padding:10px 20px}'),'Analysis includes embedded style blocks when separate CSS input is empty.');
$resolved = $variables->invoke($analysis,$css."\n.x{gap:var(--space)}");
dc_assert(false !== strpos($resolved,'.x{gap:24px}'),'Analysis resolves simple :root CSS custom properties before static mapping.');
$mapped = $to_ir->invoke($analysis,array('display'=>'grid','grid-template-columns'=>'repeat(2, 1fr)','padding'=>'10px 20px','gap'=>'12px 24px','border'=>'1px solid #123456','text-align'=>'center'));
dc_assert(2 === (int)($mapped['layout']['columns']??0),'Static CSS mapping preserves fixed grid column count.');
dc_assert(10.0 === (float)($mapped['spacing']['padding']['top']??-1) && 20.0 === (float)($mapped['spacing']['padding']['right']??-1),'Static CSS mapping expands two-value padding into Elementor dimensions.');
dc_assert(12.0 === (float)($mapped['layout']['gap']['row']??-1) && 24.0 === (float)($mapped['layout']['gap']['column']??-1),'Static CSS mapping preserves row/column gap independently.');
dc_assert('solid' === ($mapped['style']['border_style']??''),'Static CSS mapping extracts native border style from shorthand.');
dc_assert('#123456' === ($mapped['style']['border_color']??''),'Static CSS mapping extracts native border color from shorthand.');
dc_assert('center' === ($mapped['style']['align']??''),'Static CSS mapping preserves text alignment.');

dc_finish('fidelity-engine-v1');
