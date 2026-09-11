<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Screenshot_Service {
    public function capture( $target, $width, $output ) {
        if ( ! function_exists( 'exec' ) || ! function_exists( 'shell_exec' ) ) { return new WP_Error( 'design_core_capture_unavailable', 'Process execution is disabled.' ); }
        $node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
        $script = DESIGN_CORE_ELEMENTOR_PATH . 'scripts/capture-page.mjs';
        if ( ! $node || ! is_readable( $script ) ) { return new WP_Error( 'design_core_capture_unavailable', 'Node/Playwright screenshot capture is unavailable.' ); }
        $width = max( 320, min( 3840, absint( $width ) ) );
        $command = escapeshellarg( $node ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $target ) . ' ' . $width . ' ' . escapeshellarg( $output );
        $lines = array(); $code = 1; exec( $command . ' 2>&1', $lines, $code );
        if ( 0 !== $code || ! is_readable( $output ) ) { return new WP_Error( 'design_core_capture_failed', implode( "\n", $lines ) ); }
        return array( 'output'=>$output, 'width'=>$width );
    }

    public function compare_targets( $reference_target, $candidate_target, $workdir = '' ) {
        $upload = wp_upload_dir(); $workdir = $workdir ?: trailingslashit($upload['basedir']).'design-core-visual';
        if ( ! wp_mkdir_p($workdir) && ! is_dir($workdir) ) { return new WP_Error('design_core_visual_dir','Unable to create visual QA directory.'); }
        $compare=new Design_Core_Elementor_Visual_Regression_Service();$results=array();
        foreach((new Design_Core_Elementor_Breakpoint_Registry())->viewport_matrix() as $name=>$width){
            $safe=sanitize_key((string)$name).'-'.(int)$width;$ref=trailingslashit($workdir).'reference-'.$safe.'.png';$cand=trailingslashit($workdir).'candidate-'.$safe.'.png';
            $a=$this->capture($reference_target,$width,$ref);$b=$this->capture($candidate_target,$width,$cand);
            if(is_wp_error($a)||is_wp_error($b)){$results[$width]=array('status'=>'failed','viewport'=>$name,'error'=>is_wp_error($a)?$a->get_error_message():$b->get_error_message());continue;}
            $diff=$compare->compare($ref,$cand);$results[$width]=is_wp_error($diff)?array('status'=>'failed','viewport'=>$name,'error'=>$diff->get_error_message()):array_merge(array('status'=>'success','viewport'=>$name),$diff);
        }
        return $results;
    }
}
