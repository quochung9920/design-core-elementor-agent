<?php
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) { return; }

class Design_Core_Elementor_CLI {
    /** Elementor Document::save() needs a real editable user under WP-CLI. */
    private function ensure_user() {
        if ( get_current_user_id() > 0 ) { return; }
        $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
        $user_id = ! empty( $admins ) ? (int) $admins[0] : 1;
        if ( $user_id > 0 ) { wp_set_current_user( $user_id ); }
    }

    private function print_result( $result ) {
        if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
        WP_CLI::print_value( $result );
    }

    public function capabilities(){ $this->print_result((new Design_Core_Elementor_Capability_Scanner())->scan()); }
    public function readiness(){ $this->print_result((new Design_Core_Elementor_Production_Readiness())->audit()); }
    public function compatibility(){ $this->print_result((new Design_Core_Elementor_Compatibility_Validator())->validate()); }
    public function registry(){ $this->print_result((new Design_Core_Elementor_Component_Registry())->all()); }
    public function sections(){ $this->print_result((new Design_Core_Elementor_Section_Registry())->all()); }
    public function page_manifest($args){ if(empty($args[0])){WP_CLI::error('Please provide a page ID.');} $manifest=get_post_meta((int)$args[0],'_design_core_page_manifest',true); $this->print_result(is_array($manifest)?$manifest:array()); }
    public function page_snapshot($args){ if(empty($args[0])){WP_CLI::error('Please provide a page ID.');} $this->print_result((new Design_Core_Elementor_Page_Snapshot())->snapshot((int)$args[0])); }
    public function export(){ $this->print_result((new Design_Core_Elementor_Registry_Exporter())->export()); }
    public function import($args,$assoc_args){ $decoded=json_decode($assoc_args['json']??'{}',true); if(!is_array($decoded)){WP_CLI::error('The supplied JSON is invalid.');} $this->print_result((new Design_Core_Elementor_Registry_Exporter())->import($decoded)); }
    public function generate_widget($args,$assoc_args){ $this->print_result((new Design_Core_Elementor_Widget_Generator())->generate($args[0]??'demo-widget',$assoc_args['title']??'Demo Widget',array(),array('content_schema'=>array('heading','rich_text','link')))); }
    public function convert_html($args,$assoc_args){ $this->ensure_user(); $result=(new Design_Core_Elementor_HTML_Converter())->convert_to_elementor($assoc_args['html']??'<section></section>',$assoc_args['css']??'',$assoc_args['title']??'Imported Page'); $this->print_result($result); if(empty($result['page_id'])){WP_CLI::error($result['error']??'Conversion failed.');} }
    public function preview_html($args,$assoc_args){ $this->print_result((new Design_Core_Elementor_Build_Plan_Preview())->preview_source($assoc_args['html']??'<section></section>',$assoc_args['css']??'','cli-preview',$assoc_args['adapter']??'auto')); }
    public function shell_compile($args,$assoc_args){ $shell=$args[0]??'service-landing'; $bindings=json_decode($assoc_args['bindings']??'{}',true); if(!is_array($bindings)){WP_CLI::error('Bindings JSON is invalid.');} $this->print_result((new Design_Core_Elementor_Page_Shell())->compile($shell,$bindings)); }

    /** Raw/local Figma payload -> IR; Design Memory is applied before output. */
    public function figma_ir($args,$assoc_args){
        $json=$assoc_args['json']??''; if(!$json&&!empty($args[0])&&is_readable($args[0])){$json=file_get_contents($args[0]);}
        $payload=json_decode((string)$json,true); if(!is_array($payload)){WP_CLI::error('Figma JSON is invalid.');}
        $ir=(new Design_Core_Elementor_Figma_Design_IR_Adapter())->convert($payload,$assoc_args['node']??'');
        if(is_wp_error($ir)){$this->print_result($ir);return;}
        if(class_exists('Design_Core_Elementor_Design_Memory_Retriever')){$prepared=(new Design_Core_Elementor_Design_Memory_Retriever())->prepare_ir($ir);$ir=$prepared['design_ir'];}
        $this->print_result($ir);
    }

    /** URL inputs always use the strict fidelity path: vectors + reference + memory. */
    public function figma_url($args,$assoc_args){ if(empty($args[0])){WP_CLI::error('Provide a Figma URL.');} $prepared=(new Design_Core_Elementor_Figma_Fidelity_Service())->prepare($args[0]); if(is_wp_error($prepared)){$this->print_result($prepared);return;} $this->print_result($prepared['design_ir']); }
    public function figma_prepare($args,$assoc_args){ if(empty($args[0])){WP_CLI::error('Provide a Figma URL.');} $this->print_result((new Design_Core_Elementor_Figma_Fidelity_Service())->prepare($args[0])); }
    public function figma_compile($args,$assoc_args){ if(empty($args[0])){WP_CLI::error('Provide a Figma URL.');} $this->print_result((new Design_Core_Elementor_Figma_Fidelity_Service())->compile($args[0])); }
    public function figma_build($args,$assoc_args){
        if(empty($args[0])){WP_CLI::error('Provide a Figma URL.');}
        $this->ensure_user();
        $page_id=(int)($assoc_args['page-id']??0);
        $options=array('title'=>$assoc_args['title']??'Figma Import','verify'=>!empty($assoc_args['verify']),'target_similarity'=>(float)($assoc_args['target-similarity']??0.95));
        $this->print_result((new Design_Core_Elementor_Figma_Fidelity_Service())->build_draft($args[0],$page_id,$options));
    }
    public function figma_verify($args,$assoc_args){
        if(empty($args[0])||empty($args[1])){WP_CLI::error('Provide a Figma URL and candidate target.');}
        $options=array('page_id'=>(int)($assoc_args['page-id']??0),'target_similarity'=>(float)($assoc_args['target-similarity']??0.95));
        $this->print_result((new Design_Core_Elementor_Figma_Fidelity_Service())->verify($args[0],$args[1],$options));
    }

    public function design_memory($args,$assoc_args){
        $store=new Design_Core_Elementor_Design_Memory_Store(); $store->ensure_seeded();
        $scope=$assoc_args['scope']??'';
        $snapshot=$store->snapshot();
        $this->print_result(array('schema_version'=>$snapshot['schema_version'],'seed_version'=>$snapshot['seed_version'],'generation'=>$snapshot['generation'],'updated_at'=>$snapshot['updated_at'],'lessons'=>$store->lessons($scope),'incident_count'=>count($store->incidents()),'benchmark_candidates'=>(new Design_Core_Elementor_Benchmark_Promoter($store))->catalog()));
    }
    public function design_memory_incidents(){ $this->print_result(array('incidents'=>(new Design_Core_Elementor_Design_Memory_Store())->incidents())); }
    public function design_memory_benchmarks(){ $this->print_result((new Design_Core_Elementor_Benchmark_Promoter())->catalog()); }

    public function history(){ $this->print_result((new Design_Core_Elementor_Change_Ledger())->summaries()); }
    public function rollback($args){ if(empty($args[0])){WP_CLI::error('Provide a history entry ID.');} $this->print_result((new Design_Core_Elementor_Change_Ledger())->rollback($args[0])); }
    public function qa_audit($args){ if(empty($args[0])){WP_CLI::error('Please provide a page ID.');} $this->print_result((new Design_Core_Elementor_Visual_QA())->audit_page((int)$args[0])); }
    public function match_component($args,$assoc_args){ $score=(new Design_Core_Elementor_Semantic_Matcher())->score_similarity($assoc_args['html1']??'',$assoc_args['html2']??''); WP_CLI::log('Semantic Similarity Score: '.number_format($score,3)); }
    public function advanced_analyze($args,$assoc_args){ $this->print_result((new Design_Core_Elementor_Advanced_Analyzer())->analyze_semantic_structure($assoc_args['html']??'',$assoc_args['css']??'')); }
    public function tokens(){ $this->print_result((new Design_Core_Elementor_Design_Token_Service())->all()); }
    public function sync_globals(){ $this->print_result((new Design_Core_Elementor_Global_Style_Bridge())->sync((new Design_Core_Elementor_Capability_Scanner())->scan()['elementor']['editor_mode']??'v3')); }
    public function breakpoints(){ $this->print_result((new Design_Core_Elementor_Breakpoint_Registry())->all()); }
    public function conversions(){ $this->print_result(Design_Core_Elementor_Observability::recent()); }
    public function evidence($args,$assoc_args){ $store=new Design_Core_Elementor_Runtime_Evidence(); if(!empty($assoc_args['clear'])){$store->clear((string)$assoc_args['clear']);} $this->print_result($store->all()); }
    public function record_evidence($args,$assoc_args){ $key=$args[0]??''; if(!$key){WP_CLI::error('Provide an evidence key.');} $status=$assoc_args['status']??'pass';$source=$assoc_args['source']??'cli';$details=array();if(!empty($assoc_args['details'])){$decoded=json_decode($assoc_args['details'],true);if(is_array($decoded)){$details=$decoded;}}$this->print_result((new Design_Core_Elementor_Runtime_Evidence())->record($key,$status,$details,$source)); }
    public function browser_analyze($args,$assoc_args){ if(empty($args[0])){WP_CLI::error('Provide a local HTML file.');} $this->print_result((new Design_Core_Elementor_Browser_Analysis_Service())->analyze_file($args[0])); }
    public function browser_target($args,$assoc_args){ if(empty($args[0])){WP_CLI::error('Provide a rendered file or HTTP(S) target.');} $this->print_result((new Design_Core_Elementor_Browser_Analysis_Service())->analyze_target($args[0])); }
}
