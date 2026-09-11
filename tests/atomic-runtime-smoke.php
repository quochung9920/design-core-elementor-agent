<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if(!function_exists('wp_get_ability')){echo "atomic-runtime=skip:no-ability-api\n";return;}
$ability=wp_get_ability('elementor/build-composition');if(!$ability||!method_exists($ability,'execute')){echo "atomic-runtime=skip:build-composition-unavailable\n";return;}
$post_id=wp_insert_post(array('post_type'=>'page','post_status'=>'draft','post_title'=>'Atomic CI'));if(is_wp_error($post_id)){throw new RuntimeException('Unable to create Atomic CI page.');}
try{$result=$ability->execute(array('post_id'=>(int)$post_id,'xml_structure'=>'<e-flexbox configuration-id="root"><e-heading configuration-id="title"></e-heading></e-flexbox>','element_config'=>array('title'=>array('tag'=>'h2','title'=>'Atomic smoke')),'style'=>array('root'=>array('padding'=>'24px')),'classes'=>array(),'parent_id'=>'document','mode'=>'append','dry_run'=>true));if(!is_array($result)||empty($result['success'])){throw new RuntimeException('Atomic build-composition dry run failed.');}(new Design_Core_Elementor_Runtime_Evidence())->record('atomic-public-api-capability','pass',array('dry_run'=>true),'atomic-smoke');echo "atomic-public-api-capability=pass\n";}finally{wp_delete_post($post_id,true);}

// Capability evidence above proves the public API exists; it is not Design Core production evidence.
// A real roundtrip is attempted only when a governed public transformer is actually registered for this runtime.
$v4_adapter = new Design_Core_Elementor_V4_Adapter();
if ( ! $v4_adapter->supports( 'save' ) || ! $v4_adapter->supports( 'reload' ) || ! $v4_adapter->supports( 'render' ) ) {
    echo "atomic-design-core-roundtrip=skip:no-governed-transformer\n";
    return;
}
$converter = new Design_Core_Elementor_HTML_Converter();
$roundtrip = $converter->convert_to_elementor( '<section><h2>Atomic Roundtrip</h2><p>Design Core V4 path.</p></section>', '', 'Atomic Design Core Roundtrip' );
if ( 'success' !== ( $roundtrip['status'] ?? '' ) ) { throw new RuntimeException( 'Atomic Design Core roundtrip conversion failed: ' . ( $roundtrip['error'] ?? 'unknown' ) ); }
if ( 'v4' !== ( $roundtrip['editor_mode'] ?? '' ) || ! empty( $roundtrip['atomic_fallback_reason'] ) ) {
    throw new RuntimeException( 'Atomic Design Core roundtrip silently fell back to V3 (' . ( $roundtrip['atomic_fallback_reason'] ?: 'unknown reason' ) . '); refusing to record atomic-design-core-roundtrip as pass.' );
}
( new Design_Core_Elementor_Runtime_Evidence() )->record( 'atomic-design-core-roundtrip', 'pass', array( 'page_id' => (int) $roundtrip['page_id'] ), 'atomic-smoke' );
echo "atomic-design-core-roundtrip=pass\n";
