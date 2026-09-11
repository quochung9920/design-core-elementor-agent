<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
class WP_Error { public function __construct(public $code='',public $message=''){} public function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_html_class($v){return preg_replace('/[^A-Za-z0-9_-]/','',(string)$v);}
function sanitize_title($v){return trim(strtolower(preg_replace('/[^A-Za-z0-9]+/','-',(string)$v)),'-');}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function esc_url_raw($v){return (string)$v;}
function wp_generate_uuid4(){return '00000000-0000-4000-8000-000000000000';}
class Design_Core_Elementor_Design_IR { const SCHEMA_VERSION=4; }
class Design_Core_Elementor_Design_IR_Validator { function validate($ir){return true;} }
class Design_Core_Elementor_Change_Ledger { static function transport_safe($v){return $v;} }
require dirname(__DIR__,2).'/core/figma-text-composer.php';
require dirname(__DIR__,2).'/core/figma-normalization-service.php';
require dirname(__DIR__,2).'/core/figma-design-ir-adapter.php';
function expect2($c,$m){if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function txt($id,$name,$text,$x,$y,$w,$h,$family='Manrope',$weight=600,$italic=false){return ['id'=>$id,'type'=>'TEXT','name'=>$name,'characters'=>$text,'absoluteBoundingBox'=>['x'=>$x,'y'=>$y,'width'=>$w,'height'=>$h],'style'=>['fontFamily'=>$family,'fontSize'=>62,'fontWeight'=>$weight,'fontStyle'=>$italic?'Italic':'SemiBold','lineHeightPx'=>64.48,'letterSpacing'=>-1.364],'fills'=>[['type'=>'SOLID','color'=>['r'=>.09,'g'=>.19,'b'=>.18,'a'=>1]]]];}

$heading = ['id'=>'4:1060','type'=>'FRAME','name'=>'h1#hero-h','layoutSizingHorizontal'=>'FILL','layoutSizingVertical'=>'FIXED','absoluteBoundingBox'=>['x'=>392,'y'=>110,'width'=>544,'height'=>265.88],'children'=>[
    txt('4:1061','base',"Veterinary care\nthat keeps your\npet ",392,110,460,194),
    txt('4:1066','calm','calm',500,239,117,64,'Newsreader',400,true),
    txt('4:1070','and you',' and you',616,239,235,64),
    txt('4:1071','informed','informed.',392,304,274,64),
]];
$left = ['id'=>'4:1053','type'=>'FRAME','name'=>'left','layoutMode'=>'VERTICAL','layoutSizingHorizontal'=>'FILL','layoutSizingVertical'=>'HUG','itemSpacing'=>17.7,'absoluteBoundingBox'=>['x'=>392,'y'=>72,'width'=>544,'height'=>760],'children'=>[$heading]];
$media = ['id'=>'4:1117','type'=>'FRAME','name'=>'media','layoutSizingHorizontal'=>'FILL','layoutSizingVertical'=>'FILL','clipsContent'=>true,'absoluteBoundingBox'=>['x'=>992,'y'=>225,'width'=>536,'height'=>470]];
$host = ['id'=>'4:1116','type'=>'FRAME','name'=>'sc-host','layoutMode'=>'VERTICAL','layoutSizingHorizontal'=>'FILL','layoutSizingVertical'=>'FIXED','absoluteBoundingBox'=>['x'=>992,'y'=>225,'width'=>536,'height'=>470],'children'=>[$media]];
$overlay = ['id'=>'4:1127','type'=>'FRAME','name'=>'next available','layoutPositioning'=>'ABSOLUTE','constraints'=>['horizontal'=>'LEFT','vertical'=>'BOTTOM'],'absoluteBoundingBox'=>['x'=>984,'y'=>645,'width'=>300,'height'=>74]];
$right = ['id'=>'4:1115','type'=>'FRAME','name'=>'right','layoutMode'=>'VERTICAL','layoutSizingHorizontal'=>'FILL','absoluteBoundingBox'=>['x'=>992,'y'=>225,'width'=>536,'height'=>470],'children'=>[$host,$overlay]];
$inner = ['id'=>'4:1050','type'=>'FRAME','name'=>'div','layoutMode'=>'HORIZONTAL','layoutSizingHorizontal'=>'FILL','itemSpacing'=>56,'primaryAxisAlignItems'=>'CENTER','counterAxisAlignItems'=>'CENTER','paddingLeft'=>32,'paddingRight'=>32,'paddingTop'=>72,'paddingBottom'=>64,'absoluteBoundingBox'=>['x'=>360,'y'=>0,'width'=>1200,'height'=>920],'children'=>[$left,$right]];
$root = ['id'=>'4:1049','type'=>'SECTION','name'=>'section','layoutMode'=>'VERTICAL','paddingLeft'=>360,'paddingRight'=>360,'absoluteBoundingBox'=>['x'=>0,'y'=>0,'width'=>1920,'height'=>920],'children'=>[$inner]];
$payload=['source'=>['node_id'=>'4:1049'],'image_fills'=>[],'vector_assets'=>[],'figma'=>['document'=>$root]];

$ir=(new Design_Core_Elementor_Figma_Design_IR_Adapter())->convert($payload,'4:1049');
expect2(!is_wp_error($ir),'adapter produces IR');
$nodes=[];foreach($ir['nodes'] as $n)$nodes[$n['figma']['id']]=$n;
expect2(($nodes['4:1050']['layout']['direction']??'')==='row','horizontal Auto Layout becomes row');
expect2(($nodes['4:1050']['layout']['justify']??'')==='center','primary alignment maps to canonical justify');
expect2(($nodes['4:1050']['layout']['align']??'')==='center','counter alignment maps to canonical align');
expect2(($nodes['4:1050']['spacing']['padding']['left']??0)===32.0,'Figma padding becomes Elementor-compatible dimensions');
expect2(($nodes['4:1053']['style']['css_fallback']['flex']??'')==='1 0 0%','FILL child retains flex fill semantics');
expect2(($nodes['4:1116']['style']['css_fallback']['height']??'')==='470px','fixed media frame height retained');
expect2(($nodes['4:1117']['style']['css_fallback']['overflow']??'')==='hidden','clipsContent retained');
expect2(str_contains(($nodes['4:1127']['style']['css_fallback']['inset']??''),'-24px'),'bottom overlay anchor lowered to inset fallback');
expect2(isset($nodes['4:1060']) && str_contains($nodes['4:1060']['content']['rich_text'],'Newsreader'),'multi-node heading is one rich IR heading');
expect2(!isset($nodes['4:1061']) && !isset($nodes['4:1066']),'merged heading leaf nodes are not emitted independently');
echo "Figma adapter fidelity checks passed.\n";
