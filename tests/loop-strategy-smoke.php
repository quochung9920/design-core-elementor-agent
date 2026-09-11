<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$source=file_get_contents(DESIGN_CORE_ELEMENTOR_PATH.'tests/fixtures/woo.html');preg_match('/<style[^>]*>(.*?)<\/style>/is',$source,$s);preg_match('/<body[^>]*>(.*?)<\/body>/is',$source,$b);
$analysis=(new Design_Core_Elementor_Analysis_Engine())->analyze_html($b[1]??$source,$s[1]??'');
$dynamic=false;$loop_decision=false;$engine=new Design_Core_Elementor_Decision_Engine();
foreach($analysis['components']??array() as $component){if(!empty($component['dynamic_evidence'])){$dynamic=true;$decision=$engine->decide_component($component,array('elementor'=>array('loop_available'=>true),'capabilities'=>array('loop'=>true)),array('item'=>null,'score'=>0));if('loop'===($decision['strategy']??'')){$loop_decision=true;}}}
if(!$dynamic){throw new RuntimeException('Woo fixture dynamic evidence was not detected.');}
if(!$loop_decision){throw new RuntimeException('Dynamic repeated entity did not select Loop strategy when Loop capability was available.');}
echo "loop-strategy=pass\n";
