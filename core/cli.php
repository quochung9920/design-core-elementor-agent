<?php
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) { return; }

class Design_Core_Elementor_CLI {
    /**
     * Elementor's Document::save() bails when no user can edit the post --
     * always the case under WP-CLI without --user. Mutating commands must
     * call this first so the governed save pipeline can persist.
     */
    private function ensure_user() {
        if ( get_current_user_id() > 0 ) { return; }
        $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
        $user_id = ! empty( $admins ) ? (int) $admins[0] : 1;
        if ( $user_id > 0 ) { wp_set_current_user( $user_id ); }
    }
    public function capabilities(){WP_CLI::print_value((new Design_Core_Elementor_Capability_Scanner())->scan());}
    public function readiness(){WP_CLI::print_value((new Design_Core_Elementor_Production_Readiness())->audit());}
    public function compatibility(){WP_CLI::print_value((new Design_Core_Elementor_Compatibility_Validator())->validate());}
    public function registry(){WP_CLI::print_value((new Design_Core_Elementor_Component_Registry())->all());}
    public function sections(){WP_CLI::print_value((new Design_Core_Elementor_Section_Registry())->all());}
    public function page_manifest($args){if(empty($args[0])){WP_CLI::error('Please provide a page ID.');}$manifest=get_post_meta((int)$args[0],'_design_core_page_manifest',true);WP_CLI::print_value(is_array($manifest)?$manifest:array());}
    public function page_snapshot($args){if(empty($args[0])){WP_CLI::error('Please provide a page ID.');}$result=(new Design_Core_Elementor_Page_Snapshot())->snapshot((int)$args[0]);if(is_wp_error($result)){WP_CLI::error($result->get_error_message());}WP_CLI::print_value($result);}
    public function export(){WP_CLI::print_value((new Design_Core_Elementor_Registry_Exporter())->export());}
    public function import($args,$assoc_args){$decoded=json_decode($assoc_args['json']??'{}',true);if(!is_array($decoded)){WP_CLI::error('The supplied JSON is invalid.');}WP_CLI::print_value((new Design_Core_Elementor_Registry_Exporter())->import($decoded));}
    public function generate_widget($args,$assoc_args){WP_CLI::print_value((new Design_Core_Elementor_Widget_Generator())->generate($args[0]??'demo-widget',$assoc_args['title']??'Demo Widget',array(),array('content_schema'=>array('heading','rich_text','link'))));}
    public function convert_html($args,$assoc_args){$this->ensure_user();$result=(new Design_Core_Elementor_HTML_Converter())->convert_to_elementor($assoc_args['html']??'<section></section>',$assoc_args['css']??'',$assoc_args['title']??'Imported Page');WP_CLI::print_value($result);if(empty($result['page_id'])){WP_CLI::error($result['error']??'Conversion failed.');}}
    public function preview_html($args,$assoc_args){$result=(new Design_Core_Elementor_Build_Plan_Preview())->preview_source($assoc_args['html']??'<section></section>',$assoc_args['css']??'','cli-preview',$assoc_args['adapter']??'auto');if(is_wp_error($result)){WP_CLI::error($result->get_error_message());}WP_CLI::print_value($result);}
    public function shell_compile($args,$assoc_args){$this->ensure_user();$shell=$args[0]??'service-landing';$bindings=json_decode($assoc_args['bindings']??'{}',true);if(!is_array($bindings)){WP_CLI::error('Bindings JSON is invalid.');}$result=(new Design_Core_Elementor_Page_Shell())->compile($shell,$bindings);if(is_wp_error($result)){WP_CLI::error($result->get_error_message());}WP_CLI::print_value($result);}
    public function figma_ir($args,$assoc_args){$json=$assoc_args['json']??'';if(!$json&&!empty($args[0])&&is_readable($args[0])){$json=file_get_contents($args[0]);}$payload=json_decode((string)$json,true);if(!is_array($payload)){WP_CLI::error('Figma JSON is invalid.');}$result=(new Design_Core_Elementor_Figma_Design_IR_Adapter())->convert($payload,$assoc_args['node']??'');if(is_wp_error($result)){WP_CLI::error($result->get_error_message());}WP_CLI::print_value($result);}
    public function figma_url($args,$assoc_args){if(empty($args[0])){WP_CLI::error('Provide a Figma URL.');}$payload=(new Design_Core_Elementor_Figma_Transport())->read_url($args[0],array('resolve_image_fills'=>true));if(is_wp_error($payload)){WP_CLI::error($payload->get_error_message());}$result=(new Design_Core_Elementor_Figma_Design_IR_Adapter())->convert($payload,$assoc_args['node']??'');if(is_wp_error($result)){WP_CLI::error($result->get_error_message());}WP_CLI::print_value($result);}
    public function history(){WP_CLI::print_value((new Design_Core_Elementor_Change_Ledger())->summaries());}
    public function rollback($args){if(empty($args[0])){WP_CLI::error('Provide a history entry ID.');}$result=(new Design_Core_Elementor_Change_Ledger())->rollback($args[0]);if(is_wp_error($result)){WP_CLI::error($result->get_error_message());}WP_CLI::print_value($result);}
    public function qa_audit($args){if(empty($args[0])){WP_CLI::error('Please provide a page ID.');}WP_CLI::print_value((new Design_Core_Elementor_Visual_QA())->audit_page((int)$args[0]));}
    public function match_component($args,$assoc_args){$score=(new Design_Core_Elementor_Semantic_Matcher())->score_similarity($assoc_args['html1']??'',$assoc_args['html2']??'');WP_CLI::log('Semantic Similarity Score: '.number_format($score,3));}
    public function advanced_analyze($args,$assoc_args){WP_CLI::print_value((new Design_Core_Elementor_Advanced_Analyzer())->analyze_semantic_structure($assoc_args['html']??'',$assoc_args['css']??''));}
    public function tokens(){WP_CLI::print_value((new Design_Core_Elementor_Design_Token_Service())->all());}
    public function sync_globals(){WP_CLI::print_value((new Design_Core_Elementor_Global_Style_Bridge())->sync((new Design_Core_Elementor_Capability_Scanner())->scan()['elementor']['editor_mode']??'v3'));}
    public function breakpoints(){WP_CLI::print_value((new Design_Core_Elementor_Breakpoint_Registry())->all());}
    public function conversions(){WP_CLI::print_value(Design_Core_Elementor_Observability::recent());}
    public function evidence($args,$assoc_args){$store=new Design_Core_Elementor_Runtime_Evidence();if(!empty($assoc_args['clear'])){$store->clear((string)$assoc_args['clear']);}WP_CLI::print_value($store->all());}
    public function record_evidence($args,$assoc_args){$key=$args[0]??'';if(!$key){WP_CLI::error('Provide an evidence key.');}$status=$assoc_args['status']??'pass';$source=$assoc_args['source']??'cli';$details=array();if(!empty($assoc_args['details'])){$decoded=json_decode($assoc_args['details'],true);if(is_array($decoded)){$details=$decoded;}}$result=(new Design_Core_Elementor_Runtime_Evidence())->record($key,$status,$details,$source);if(is_wp_error($result)){WP_CLI::error($result->get_error_message());}WP_CLI::print_value($result);}
    public function browser_analyze($args,$assoc_args){if(empty($args[0])){WP_CLI::error('Provide a local HTML file.');}$result=(new Design_Core_Elementor_Browser_Analysis_Service())->analyze_file($args[0]);if(is_wp_error($result)){WP_CLI::error($result->get_error_message());}WP_CLI::print_value($result);}
    public function browser_target($args,$assoc_args){if(empty($args[0])){WP_CLI::error('Provide a rendered file or HTTP(S) target.');}$result=(new Design_Core_Elementor_Browser_Analysis_Service())->analyze_target($args[0]);if(is_wp_error($result)){WP_CLI::error($result->get_error_message());}WP_CLI::print_value($result);}
}
