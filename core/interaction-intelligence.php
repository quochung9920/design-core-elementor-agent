<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Platform-neutral Interaction Intelligence for Design IR v4.
 *
 * The service infers user-behavior contracts from canonical DOM/ARIA evidence already
 * present in Design IR. It never executes source JavaScript and never writes Elementor
 * fields into the IR.
 */
class Design_Core_Elementor_Interaction_Intelligence {
    const VERSION = 1;

    public function enrich( array $ir ) {
        if ( empty( $ir['nodes'] ) || ! is_array( $ir['nodes'] ) ) { return $ir; }

        $index_by_id = array();
        $dom_ids = array();
        foreach ( $ir['nodes'] as $index => $node ) {
            if ( ! is_array( $node ) || empty( $node['id'] ) ) { continue; }
            $index_by_id[ $node['id'] ] = $index;
            $dom_id = sanitize_key( $node['source']['attributes']['id'] ?? '' );
            if ( $dom_id ) { $dom_ids[ $dom_id ] = $node['id']; }
        }

        $interactive = 0;
        foreach ( $ir['nodes'] as $index => $node ) {
            $behavior = $this->infer( $node, $dom_ids );
            if ( ! $behavior ) { continue; }
            $ir['nodes'][ $index ]['interaction']['behavior'] = $behavior;
            $ir['nodes'][ $index ]['interaction'][ 'pattern_' . sanitize_key( $behavior['pattern'] ) ] = true;
            $interactive++;
        }

        $nodes = array();
        foreach ( $ir['nodes'] as $node ) { if ( is_array( $node ) && ! empty( $node['id'] ) ) { $nodes[ $node['id'] ] = $node; } }
        foreach ( $nodes as $id => $node ) {
            $container = $this->container_behavior( $node, $nodes );
            if ( ! $container || ! isset( $index_by_id[ $id ] ) ) { continue; }
            $index = $index_by_id[ $id ];
            $existing = (array) ( $ir['nodes'][ $index ]['interaction']['behavior'] ?? array() );
            if ( ! $existing || (float) ( $container['confidence'] ?? 0 ) > (float) ( $existing['confidence'] ?? 0 ) ) {
                if ( $existing && isset( $ir['nodes'][ $index ]['interaction'][ 'pattern_' . sanitize_key( $existing['pattern'] ?? '' ) ] ) ) {
                    unset( $ir['nodes'][ $index ]['interaction'][ 'pattern_' . sanitize_key( $existing['pattern'] ) ] );
                }
                $ir['nodes'][ $index ]['interaction']['behavior'] = $container;
                $ir['nodes'][ $index ]['interaction'][ 'pattern_' . sanitize_key( $container['pattern'] ) ] = true;
            }
        }

        foreach ( $ir['nodes'] as $index => $node ) {
            $signature = self::signature( (array) ( $node['interaction'] ?? array() ) );
            if ( '' === $signature || ! isset( $ir['nodes'][ $index ]['component']['fingerprint'] ) ) { continue; }
            $ir['nodes'][ $index ]['component']['fingerprint']['interaction'] = $signature;
            // Behavior is part of component family identity, not merely presentation.
            $structure = (string) ( $ir['nodes'][ $index ]['component']['fingerprint']['structure'] ?? '' );
            $behavior_suffix = '|behavior:' . substr( hash( 'sha256', $signature ), 0, 12 );
            if ( false === strpos( $structure, '|behavior:' ) ) { $ir['nodes'][ $index ]['component']['fingerprint']['structure'] = $structure . $behavior_suffix; }
        }

        if ( ! isset( $ir['analysis_quality'] ) || ! is_array( $ir['analysis_quality'] ) ) { $ir['analysis_quality'] = array(); }
        $ir['analysis_quality']['interaction'] = $interactive ? 'inferred' : 'partial';
        if ( ! isset( $ir['diagnostics'] ) || ! is_array( $ir['diagnostics'] ) ) { $ir['diagnostics'] = array(); }
        $ir['diagnostics']['interaction_intelligence'] = array(
            'version' => self::VERSION,
            'interactive_nodes' => $interactive,
            'runtime_observation' => false,
            'source_javascript_executed' => false,
        );
        return $ir;
    }

    public static function signature( array $interaction ) {
        $behavior = is_array( $interaction['behavior'] ?? null ) ? $interaction['behavior'] : array();
        if ( ! $behavior ) { return ! empty( $interaction['hover'] ) ? 'behavior-v1|hover' : ''; }
        $events = self::normalized( $behavior['events'] ?? array() );
        $states = self::normalized( $behavior['states'] ?? array() );
        $keyboard = self::normalized( $behavior['keyboard'] ?? array() );
        return implode( '|', array(
            'behavior-v' . self::VERSION,
            sanitize_key( $behavior['pattern'] ?? 'interaction' ),
            implode( ',', $events ),
            implode( ',', $states ),
            implode( ',', $keyboard ),
            ! empty( $behavior['stateful'] ) ? 'stateful' : 'stateless',
        ) );
    }

    private function infer( array $node, array $dom_ids ) {
        $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
        $semantic = sanitize_key( $node['semantic']['role'] ?? '' );
        $attrs = is_array( $node['source']['attributes'] ?? null ) ? $node['source']['attributes'] : array();
        $role = sanitize_key( $attrs['role'] ?? '' );
        $type = sanitize_key( $attrs['type'] ?? '' );
        $classes = strtolower( implode( ' ', (array) ( $node['source']['classes'] ?? array() ) ) );
        $toggle = sanitize_key( $attrs['data-bs-toggle'] ?? ( $attrs['data-toggle'] ?? '' ) );
        $pattern = ''; $events = array(); $states = array(); $keyboard = array(); $stateful = false; $runtime = false; $confidence = 0.0; $evidence = array();

        if ( 'pricing-calculator' === $semantic ) { $pattern='calculator'; $events=array('input','change'); $stateful=true; $runtime=true; $confidence=.97; $evidence[]='semantic:pricing-calculator'; }
        elseif ( 'product-comparison' === $semantic ) { $pattern='comparison'; $events=array('activate','change'); $stateful=true; $runtime=true; $confidence=.97; $evidence[]='semantic:product-comparison'; }
        elseif ( 'faq' === $semantic ) { $pattern='accordion'; $events=array('activate'); $states=array('collapsed','expanded'); $keyboard=array('enter','space'); $stateful=true; $confidence=.95; $evidence[]='semantic:faq'; }
        elseif ( 'tablist' === $role ) { $pattern='tabs'; $events=array('activate'); $states=array('inactive','active'); $keyboard=array('arrowleft','arrowright','home','end'); $stateful=true; $confidence=.99; $evidence[]='aria:tablist'; }
        elseif ( 'tab' === $role || 'tab' === $toggle ) { $pattern='tab'; $events=array('activate'); $states=array('inactive','active'); $keyboard=array('enter','space'); $stateful=true; $confidence=.98; $evidence[]='aria:tab'; }
        elseif ( 'tabpanel' === $role ) { $pattern='tab-panel'; $states=array('hidden','visible'); $stateful=true; $confidence=.99; $evidence[]='aria:tabpanel'; }
        elseif ( 'dialog' === $role || 'true' === strtolower( (string) ( $attrs['aria-modal'] ?? '' ) ) ) { $pattern='dialog'; $states=array('closed','open'); $keyboard=array('escape'); $stateful=true; $runtime=true; $confidence=.99; $evidence[]='aria:dialog'; }
        elseif ( 'modal' === $toggle || 'dialog' === sanitize_key( $attrs['aria-haspopup'] ?? '' ) ) { $pattern='dialog-trigger'; $events=array('activate'); $states=array('closed','open'); $keyboard=array('enter','space'); $stateful=true; $runtime=true; $confidence=.96; $evidence[]='trigger:dialog'; }
        elseif ( 'switch' === $role || array_key_exists( 'aria-pressed', $attrs ) ) { $pattern='toggle'; $events=array('activate','change'); $states=array('off','on'); $keyboard=array('enter','space'); $stateful=true; $confidence=.97; $evidence[]='aria:toggle'; }
        elseif ( array_key_exists( 'aria-expanded', $attrs ) || in_array( $toggle, array('collapse','accordion'), true ) ) { $pattern='disclosure-trigger'; $events=array('activate'); $states=array('collapsed','expanded'); $keyboard=array('enter','space'); $stateful=true; $confidence=.97; $evidence[]='aria:expanded'; }
        elseif ( 'form' === $tag ) { $pattern='form'; $events=array('submit'); $states=array('idle','submitting','success','error'); $keyboard=array('enter'); $stateful=true; $runtime=true; $confidence=.95; $evidence[]='tag:form'; }
        elseif ( 'select' === $tag ) { $pattern='selection'; $events=array('change'); $states=array('unselected','selected'); $stateful=true; $confidence=.95; $evidence[]='tag:select'; }
        elseif ( 'textarea' === $tag ) { $pattern='input'; $events=array('input','change'); $confidence=.92; $evidence[]='tag:textarea'; }
        elseif ( 'input' === $tag ) {
            if ( 'checkbox' === $type ) { $pattern='toggle'; $events=array('change'); $states=array('off','on'); $keyboard=array('space'); $stateful=true; }
            elseif ( 'radio' === $type ) { $pattern='selection'; $events=array('change'); $states=array('unselected','selected'); $keyboard=array('space'); $stateful=true; }
            elseif ( 'range' === $type ) { $pattern='range-input'; $events=array('input','change'); $stateful=true; }
            elseif ( 'search' === $type ) { $pattern='search-input'; $events=array('input','submit'); }
            else { $pattern='input'; $events=array('input','change'); }
            $confidence=.94; $evidence[]='input:' . ( $type ?: 'text' );
        }
        elseif ( 'details' === $tag ) { $pattern='disclosure'; $events=array('activate'); $states=array('collapsed','expanded'); $keyboard=array('enter','space'); $stateful=true; $confidence=.98; $evidence[]='tag:details'; }
        elseif ( isset( $attrs['data-filter'] ) || preg_match('/(?:^|[\s_-])filter(?:$|[\s_-])/', $classes) ) { $pattern='filter'; $events=array('activate','change'); $stateful=true; $runtime=true; $confidence=isset($attrs['data-filter'])?.93:.70; $evidence[]='filter-evidence'; }
        elseif ( preg_match('/(?:^|[\s_-])(carousel|slider|swiper|splide|slick)(?:$|[\s_-])/', $classes) ) { $pattern='carousel'; $events=array('activate','swipe'); $states=array('inactive','active'); $keyboard=array('arrowleft','arrowright'); $stateful=true; $runtime=true; $confidence=.78; $evidence[]='class:carousel'; }
        elseif ( 'navigation' === $semantic || 'nav' === $tag || 'a' === $tag ) { $pattern='navigation'; $events=array('activate'); $keyboard=array('enter'); $confidence=.90; $evidence[]='navigation-affordance'; }
        elseif ( 'button' === $tag || 'button' === $role ) { $pattern='action'; $events=array('activate'); $keyboard=array('enter','space'); $confidence=.84; $evidence[]='button-affordance'; }

        if ( ! $pattern ) { return array(); }
        return array(
            'version'=>self::VERSION,
            'pattern'=>$pattern,
            'events'=>array_values(array_unique($events)),
            'states'=>array_values(array_unique($states)),
            'initial_state'=>$this->initial_state($pattern,$attrs),
            'transitions'=>$this->transitions($pattern),
            'actors'=>array('trigger'=>(string)($node['id']??''),'targets'=>$this->targets($node,$dom_ids)),
            'keyboard'=>array_values(array_unique($keyboard)),
            'stateful'=>(bool)$stateful,
            'runtime_required'=>(bool)$runtime,
            'confidence'=>$confidence,
            'evidence'=>$evidence,
        );
    }

    private function container_behavior( array $root, array $nodes ) {
        if ( empty( $root['children'] ) ) { return array(); }
        $counts=array(); $triggers=array(); $targets=array();
        foreach ( $this->descendants($root,$nodes,128) as $node ) {
            $behavior=(array)($node['interaction']['behavior']??array());
            $pattern=sanitize_key($behavior['pattern']??'');
            if(!$pattern){continue;}
            $counts[$pattern]=($counts[$pattern]??0)+1;
            if(!empty($behavior['actors']['trigger'])){$triggers[]=$behavior['actors']['trigger'];}
            foreach((array)($behavior['actors']['targets']??array()) as $target){$targets[]=$target;}
        }
        $semantic=sanitize_key($root['semantic']['role']??'');
        if('faq'===$semantic || ($counts['disclosure-trigger']??0)>=2){$pattern='accordion';$states=array('collapsed','expanded');$keyboard=array('enter','space');$confidence='faq'===$semantic?.97:.91;}
        elseif(($counts['tabs']??0)>0 || (($counts['tab']??0)>=2 && ($counts['tab-panel']??0)>0)){$pattern='tabs';$states=array('inactive','active');$keyboard=array('arrowleft','arrowright','home','end');$confidence=.98;}
        else{return array();}
        return array('version'=>self::VERSION,'pattern'=>$pattern,'events'=>array('activate'),'states'=>$states,'initial_state'=>'','transitions'=>$this->transitions($pattern),'actors'=>array('trigger'=>(string)($root['id']??''),'triggers'=>array_values(array_unique($triggers)),'targets'=>array_values(array_unique($targets))),'keyboard'=>$keyboard,'stateful'=>true,'runtime_required'=>false,'confidence'=>$confidence,'evidence'=>array('descendant-topology'));
    }

    private function targets( array $node, array $dom_ids ) {
        $attrs=(array)($node['source']['attributes']??array());$refs=array();
        foreach(array('aria-controls','data-target','data-controls') as $name){if(empty($attrs[$name])){continue;}foreach(preg_split('/\s+/',trim((string)$attrs[$name])) as $ref){$refs[]=ltrim($ref,'#');}}
        foreach(array((string)($node['content']['link']['url']??''),(string)($attrs['href']??'')) as $url){if(0===strpos($url,'#')&&strlen($url)>1){$refs[]=substr($url,1);}}
        $out=array();foreach(array_unique($refs) as $ref){$key=sanitize_key($ref);if($key&&isset($dom_ids[$key])){$out[]=$dom_ids[$key];}}
        return array_values(array_unique($out));
    }

    private function initial_state( $pattern, array $attrs ) {
        if('disclosure-trigger'===$pattern&&array_key_exists('aria-expanded',$attrs)){return 'true'===strtolower((string)$attrs['aria-expanded'])?'expanded':'collapsed';}
        if('tab'===$pattern&&array_key_exists('aria-selected',$attrs)){return 'true'===strtolower((string)$attrs['aria-selected'])?'active':'inactive';}
        if('toggle'===$pattern){return array_key_exists('checked',$attrs)||'true'===strtolower((string)($attrs['aria-pressed']??''))?'on':'off';}
        return '';
    }

    private function transitions( $pattern ) {
        if(in_array($pattern,array('accordion','disclosure','disclosure-trigger'),true)){return array(array('event'=>'activate','from'=>'collapsed','to'=>'expanded'),array('event'=>'activate','from'=>'expanded','to'=>'collapsed'));}
        if('toggle'===$pattern){return array(array('event'=>'change','from'=>'off','to'=>'on'),array('event'=>'change','from'=>'on','to'=>'off'));}
        if(in_array($pattern,array('tabs','tab'),true)){return array(array('event'=>'activate','from'=>'inactive','to'=>'active'));}
        if(in_array($pattern,array('dialog','dialog-trigger'),true)){return array(array('event'=>'activate','from'=>'closed','to'=>'open'),array('event'=>'dismiss','from'=>'open','to'=>'closed'));}
        return array();
    }

    private function descendants( array $root, array $nodes, $limit ) {
        $out=array();$queue=(array)($root['children']??array());$seen=array();
        while($queue&&count($out)<$limit){$id=array_shift($queue);if(isset($seen[$id])||!isset($nodes[$id])){continue;}$seen[$id]=true;$node=$nodes[$id];$out[]=$node;foreach((array)($node['children']??array()) as $child){$queue[]=$child;}}
        return $out;
    }

    private static function normalized( $values ) { $values=array_values(array_unique(array_filter(array_map('sanitize_key',(array)$values))));sort($values);return $values; }
}
