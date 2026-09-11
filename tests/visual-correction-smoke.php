<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
wp_set_current_user( 1 );
function dc_vc_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function dc_vc_find( array $elements, $id ) { foreach ( $elements as $element ) { if ( (string) ( $element['id'] ?? '' ) === (string) $id ) { return $element; } $found = dc_vc_find( (array) ( $element['elements'] ?? array() ), $id ); if ( $found ) { return $found; } } return null; }

$post_id = wp_insert_post( array( 'post_type'=>'page', 'post_title'=>'Visual Correction Runtime Smoke', 'post_status'=>'publish', 'post_content'=>'' ) );
dc_vc_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Failed to create visual correction smoke page.' );
$heading_id = 'dc20a001';
$elements = array(
    array(
        'id'=>'dc20root','elType'=>'container','isInner'=>false,
        'settings'=>array('content_width'=>'boxed','boxed_width'=>array('size'=>900,'unit'=>'px')),
        'elements'=>array(
            array(
                'id'=>$heading_id,'elType'=>'widget','widgetType'=>'heading','isInner'=>false,
                'settings'=>array('title'=>'Visual Correction Runtime Smoke','header_size'=>'h2','typography_typography'=>'custom','typography_font_size'=>array('size'=>24,'unit'=>'px')),
                'elements'=>array(),
            ),
        ),
    ),
);
$adapter = new Design_Core_Elementor_V3_Adapter();
$saved = $adapter->save_page( (int) $post_id, $elements, array() );
dc_vc_assert( true === $saved, is_wp_error( $saved ) ? $saved->get_error_message() : 'Initial Elementor save failed.' );

$feedback = ( new Design_Core_Elementor_Visual_Feedback_Engine() )->evaluate(
    array( 1440=>array('status'=>'success','similarity'=>0.90,'difference_ratio'=>0.10) ),
    array( 1440=>array(
        '/reference/h2[1]'=>array(
            'matched_path'=>'/candidate/h2[1]','match_method'=>'text-tag','rect'=>array(),
            'styles'=>array('fontSize'=>array('reference'=>'42px','candidate'=>'24px')),
            'candidate_meta'=>array('elementor_id'=>$heading_id,'widget_type'=>'heading','elementor_type'=>'widget','tag'=>'h2'),
        ),
    ) ),
    0.95
);
$directives = array_values( array_filter( (array) ( $feedback['correction_plan'] ?? array() ), static function( $directive ){ return ! empty( $directive['auto_applicable'] ); } ) );
dc_vc_assert( ! empty( $directives ), 'Visual Feedback did not create an addressable correction directive.' );

$result = ( new Design_Core_Elementor_Visual_Correction_Applier() )->apply( (int) $post_id, $directives, array( 'iteration'=>1 ) );
dc_vc_assert( ! is_wp_error( $result ) && 'applied' === ( $result['status'] ?? '' ), is_wp_error( $result ) ? $result->get_error_message() : 'Visual correction applier made no verified change.' );
$reloaded = $adapter->reload( (int) $post_id );
$heading = dc_vc_find( $reloaded, $heading_id );
dc_vc_assert( is_array( $heading ), 'Corrected heading is missing after reload.' );
$size = $heading['settings']['typography_font_size']['size'] ?? null;
dc_vc_assert( 42 === (int) $size, 'Heading font size was not corrected through the runtime control.' );
$rendered = $adapter->render( (int) $post_id );
dc_vc_assert( false !== strpos( (string) $rendered, 'Visual Correction Runtime Smoke' ), 'Corrected page failed frontend render verification.' );

( new Design_Core_Elementor_Runtime_Evidence() )->record( 'visual-correction-roundtrip', 'pass', array(
    'page_id'=>(int)$post_id,
    'element_id'=>$heading_id,
    'widget_type'=>'heading',
    'control'=>'typography_font_size',
    'target'=>'42px',
    'persistence'=>$result['persistence'] ?? array(),
), 'visual-correction-smoke' );
echo "visual-correction-roundtrip=pass\n";
