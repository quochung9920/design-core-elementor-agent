<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_html_class($v){return preg_replace('/[^A-Za-z0-9_-]/','',(string)$v);}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
require dirname(__DIR__,2) . '/core/figma-text-composer.php';
require dirname(__DIR__,2) . '/core/figma-normalization-service.php';

function expect($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}echo "PASS: $message\n";}
function textNode($id,$text,$x,$y,$family='Manrope',$style='SemiBold',$color=[0.09,0.19,0.18]){
  return ['id'=>$id,'type'=>'TEXT','name'=>'text','characters'=>$text,'absoluteBoundingBox'=>['x'=>$x,'y'=>$y,'width'=>max(10,strlen($text)*26),'height'=>64.48],'style'=>['fontFamily'=>$family,'fontSize'=>62,'fontWeight'=>$family==='Newsreader'?400:600,'fontStyle'=>$style,'lineHeightPx'=>64.48,'letterSpacing'=>-1.364],'fills'=>[['type'=>'SOLID','color'=>['r'=>$color[0],'g'=>$color[1],'b'=>$color[2],'a'=>1]]]];
}
$heading=['id'=>'4:1060','type'=>'FRAME','name'=>'h1#hero-h','absoluteBoundingBox'=>['x'=>354,'y'=>108,'width'=>480,'height'=>266],'children'=>[
 textNode('4:1061',"Veterinary care\nthat keeps your\npet ",354,108),
 textNode('4:1066','calm',462,237,'Newsreader','Italic',[0.169,0.478,0.357]),
 textNode('4:1070',' and you',578,237),
 textNode('4:1071','informed.',354,302),
]];
$c=(new Design_Core_Elementor_Figma_Text_Composer())->compose_container($heading);
expect(is_array($c),'heading composition detected');
expect($c['tag']==='h1','h1 semantics preserved');
expect(str_contains($c['rich_text'],'Newsreader'),'accent font survives as rich text');
expect(str_contains($c['rich_text'],'font-style:italic'),'accent italic survives');
expect(substr_count($c['rich_text'],'<br>')===3,'visual four-line composition reconstructed');
expect(str_contains(str_replace("\n",' ',$c['text']),'pet calm and you'),'split words rejoin on the same visual line');

$root=['id'=>'4:1049','type'=>'SECTION','name'=>'section','layoutMode'=>'HORIZONTAL','absoluteBoundingBox'=>['x'=>0,'y'=>0,'width'=>1200,'height'=>600],'children'=>[
 ['id'=>'left','type'=>'FRAME','name'=>'left','layoutSizingHorizontal'=>'FILL','layoutSizingVertical'=>'HUG','absoluteBoundingBox'=>['x'=>0,'y'=>0,'width'=>572,'height'=>500]],
 ['id'=>'overlay','type'=>'FRAME','name'=>'next available','layoutPositioning'=>'ABSOLUTE','constraints'=>['horizontal'=>'LEFT','vertical'=>'BOTTOM'],'absoluteBoundingBox'=>['x'=>590,'y'=>550,'width'=>280,'height'=>74]],
]];
$n=(new Design_Core_Elementor_Figma_Normalization_Service())->normalize($root);
$left=$n['node']['children'][0];$overlay=$n['node']['children'][1];
expect($left['_design_core_sizing']['horizontal']==='FILL','FILL sizing preserved');
expect($left['_design_core_sizing']['parent_layout_mode']==='HORIZONTAL','parent auto-layout mode preserved');
expect(($overlay['_design_core_layout']['position']??'')==='absolute','absolute positioning preserved');
expect(isset($overlay['_design_core_layout']['bottom']),'bottom anchor preserved from Figma constraints');
expect(abs(($overlay['_design_core_layout']['bottom']['value']??0)-(-24))<0.001,'negative overlay bottom offset preserved');

echo "Figma fidelity unit checks passed.\n";
