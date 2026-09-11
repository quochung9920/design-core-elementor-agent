<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
$script = DESIGN_CORE_ELEMENTOR_PATH . 'scripts/provision-air-hero-what-modernization.php';
if ( ! file_exists( $script ) ) { dc_assert( false, 'Hero/What modernization exists' ); dc_finish( 'Air Hero/What modernization' ); }
define( 'DESIGN_CORE_AIR_HERO_WHAT_BLUEPRINT_ONLY', true );
require $script;
$fixture = array(
    array( 'id'=>'hero','elType'=>'container','settings'=>array('css_classes'=>'dc-air-hero'),'elements'=>array(
        array('id'=>'card','elType'=>'container','settings'=>array('css_classes'=>'dc-hero-card'),'elements'=>array(
            array('id'=>'row','elType'=>'container','settings'=>array('css_classes'=>'dc-hero-formrow'),'elements'=>array(
                array('id'=>'html','elType'=>'widget','widgetType'=>'html','settings'=>array('_css_classes'=>'dc-hero-input','html'=>'<style>bad</style><input>'),'elements'=>array()),
                array('id'=>'button','elType'=>'widget','widgetType'=>'button','settings'=>array('_css_classes'=>'dc-hero-button'),'elements'=>array()),
            )),
        )),
    )),
    array( 'id'=>'what','elType'=>'container','settings'=>array('css_classes'=>'dc-what-section'),'elements'=>array(
        array('id'=>'container','elType'=>'container','settings'=>array('css_classes'=>'dc-what-container'),'elements'=>array()),
    )),
);
$result = design_core_air_modernize_hero_what( $fixture );
$found=array();$widgets=array();$walk=function($els)use(&$walk,&$found,&$widgets){foreach($els as $e){$s=$e['settings']??array();foreach(preg_split('/\s+/',trim($s['css_classes']??$s['_css_classes']??'')) as $c){if($c)$found[$c]=$s;}if(($e['elType']??'')==='widget')$widgets[]=$e['widgetType']??'';$walk($e['elements']??array());}};$walk($result);
dc_assert( 'full' === ( $found['dc-air-hero']['content_width'] ?? '' ), 'Hero surface is native Full Width' );
dc_assert( 'boxed' === ( $found['dc-hero-card']['content_width'] ?? '' ), 'Hero card is native Boxed' );
dc_assert( 'full' === ( $found['dc-what-section']['content_width'] ?? '' ), 'What surface is native Full Width' );
dc_assert( 'boxed' === ( $found['dc-what-container']['content_width'] ?? '' ), 'What content owner is native Boxed' );
dc_assert( in_array( 'form', $widgets, true ), 'Hero starter uses native Elementor Pro Form' );
dc_assert( ! in_array( 'html', $widgets, true ), 'Hero/What modernization removes HTML widget stylesheet' );
dc_finish( 'Air Hero/What modernization' );
