<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Runtime-schema-first semantic layer used by the V3 adapter. */
class Design_Core_Elementor_Semantic_Native_Mapping_V2 {
    private $base;
    private $registry;
    private $ir = array();
    private $nodes = array();

    public function __construct() {
        $this->base = new Design_Core_Elementor_Mapping_Engine();
        $this->registry = class_exists( 'Design_Core_Elementor_Control_Schema_Registry' ) ? new Design_Core_Elementor_Control_Schema_Registry() : null;
    }

    public function map_ir( array $ir ) {
        ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
        $this->ir = $ir;
        $this->nodes = array();
        foreach ( $ir['nodes'] as $node ) { if ( ! empty( $node['id'] ) ) { $this->nodes[ $node['id'] ] = $node; } }
        $out = array();
        foreach ( $ir['root_ids'] as $id ) { if ( isset( $this->nodes[ $id ] ) && ( $element = $this->node( $this->nodes[ $id ], array() ) ) ) { $out[] = $element; } }
        if ( class_exists( 'Design_Core_Elementor_Source_Fidelity_Engine' ) ) {
            $fidelity = ( new Design_Core_Elementor_Source_Fidelity_Engine() )->verify_elementor_tree( $ir, $out );
            if ( 'fail' === ( $fidelity['status'] ?? '' ) ) {
                throw new UnexpectedValueException( 'Source fidelity check failed: ' . implode( ', ', (array) ( $fidelity['issues'] ?? array() ) ) );
            }
        }
        return $out;
    }

    private function node( array $node, array $inherited ) {
        $tag = strtolower( (string) ( $node['source']['tag'] ?? 'div' ) );
        $role = sanitize_key( (string) ( $node['semantic']['role'] ?? '' ) );
        $policy = sanitize_key( (string) ( $node['semantic']['composition_policy'] ?? '' ) );
        $preserve_children = 'preserve-children' === $policy;
        $text_style = $this->inherit_text( $inherited, $node );

        if ( 'faq' === $role && ( $native = $this->faq( $node, $text_style ) ) ) { return $this->semantic_wrapper( $node, $text_style, $native, 'faq' ); }
        if ( 'faq' !== $role && $this->has_disclosures( $node, 2 ) && ( $native = $this->faq( $node, $text_style ) ) ) { return $this->semantic_wrapper( $node, $text_style, $native, 'faq' ); }
        if ( ( 'navigation' === $role || $this->is_jump_nav( $node ) ) && ( $native = $this->jump_nav( $node, $text_style ) ) ) { return $this->semantic_wrapper( $node, $text_style, $native, 'navigation' ); }
        if ( 'navigation' === $role && ( $native = $this->menu( $node, $text_style ) ) ) { return $this->semantic_wrapper( $node, $text_style, $native, 'navigation' ); }
        if ( ( 'table' === $tag || $this->is_data_table( $node ) ) && ( $native = $this->data_table( $node, $text_style ) ) ) { return $this->outer_or_widget( $node, $text_style, $native ); }
        if ( $this->is_process_steps( $node ) && ( $native = $this->process_steps( $node, $text_style ) ) ) { return $this->outer_or_widget( $node, $text_style, $native ); }
        if ( ( 'form' === $role || 'form' === $tag ) && ( $native = $this->form( $node, $text_style ) ) ) { return $this->outer_or_widget( $node, $text_style, $native ); }
        if ( ! $preserve_children && 'cta' === $role && ( $native = $this->cta( $node, $text_style ) ) ) { return $this->outer_or_widget( $node, $text_style, $native ); }
        if ( ( 'global-time-bar' === $role || $this->is_time_bar( $node ) ) && ( $native = $this->time_bar( $node, $text_style ) ) ) { return $this->outer_or_widget( $node, $text_style, $native ); }

        $text = trim( wp_strip_all_tags( (string) ( $node['content']['text'] ?? '' ) ) );
        if ( $this->style_literal( $text ) ) { return null; }
        $base = $this->single( $node, $text_style );
        if ( $preserve_children && 'widget' === ( $base['elType'] ?? '' ) && ! empty( $node['children'] ) ) {
            // A source component graph that owns independently editable descendants
            // may not collapse into a leaf widget. Keep a structural owner instead.
            $base = $this->container();
        }
        if ( ! $base || 'widget' === ( $base['elType'] ?? '' ) ) { return $base; }
        $base['elements'] = array();
        foreach ( $node['children'] ?? array() as $id ) {
            if ( isset( $this->nodes[ $id ] ) && ( $child = $this->node( $this->nodes[ $id ], $text_style ) ) ) { $base['elements'][] = $child; }
        }
        return $base;
    }

    private function single( array $node, array $text_style ) {
        $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
        if ( preg_match( '/^h[1-6]$/', $tag ) || in_array( $tag, array( 'p', 'blockquote', 'a', 'button', 'span' ), true ) ) {
            $node['style'] = array_merge( $text_style, (array) ( $node['style'] ?? array() ) );
        }
        $node['children'] = array();
        $sub = $this->ir; $sub['nodes'] = array( $node ); $sub['root_ids'] = array( $node['id'] );
        try { $mapped = $this->base->map_ir( $sub ); return $mapped[0] ?? null; } catch ( Throwable $e ) { return null; }
    }

    private function faq( array $root, array $style ) {
        if ( $this->has_unrepresentable( $root ) ) { return null; }
        if ( ! $this->supports( 'accordion', array( 'tabs' => array( 'tab_title', 'tab_content' ) ) ) ) { return null; }
        // Native disclosure elements first: details/summary pairs map exactly.
        $disclosures = array();
        $queue = array( $root );
        while ( $queue ) {
            $n = array_shift( $queue );
            if ( 'details' === strtolower( (string) ( $n['source']['tag'] ?? '' ) ) ) {
                $pair = $this->qa_details( $n );
                if ( $pair ) { $disclosures[] = $pair; }
                continue;
            }
            foreach ( $n['children'] ?? array() as $id ) { if ( isset( $this->nodes[ $id ] ) ) { $queue[] = $this->nodes[ $id ]; } }
        }
        if ( count( $disclosures ) >= 2 ) {
            return $this->widget( 'accordion', $this->accordion_settings( $disclosures, $style ) );
        }
        $pairs = array(); $this->qa_pairs( $root, $pairs, true );
        if ( count( $pairs ) < 2 ) { return null; }
        return $this->widget( 'accordion', $this->accordion_settings( $pairs, $style ) );
    }

    private function accordion_settings( array $pairs, array $style ) {
        $tabs = array();
        foreach ( $pairs as $i => $pair ) { $tabs[] = array( '_id' => substr( md5( $pair['title'] . '|' . $i ), 0, 7 ), 'tab_title' => $pair['title'], 'tab_content' => $pair['content'] ); }
        $settings = array( 'tabs' => $tabs, 'faq_schema' => 'yes' );
        $this->style( $settings, 'accordion', 'title_', $style, array( 'title_color' ) );
        return $settings;
    }

    private function menu( array $root, array $style ) {
        if ( $this->has_unrepresentable( $root ) ) { return null; }
        if ( ! $this->supports( 'mega-menu', array( 'menu_items' => array( 'item_title', 'item_link' ) ) ) ) { return null; }
        // A mega-menu only owns menu items: when the header also carries a logo
        // or a CTA, collapsing it would drop content a native container
        // composition preserves. Jump-anchor groups belong to dc-jump-nav.
        if ( $this->media( $root ) || $this->has_cta( $root ) || $this->anchor_majority( $root ) ) { return null; }
        $links = array(); $this->links( $root, $links ); $items = array();
        foreach ( $links as $link ) {
            $text = trim( wp_strip_all_tags( (string) ( $link['content']['text'] ?? '' ) ) ); if ( '' === $text || $this->style_literal( $text ) ) { continue; }
            $href = (array) ( $link['content']['link'] ?? array() );
            $items[] = array( '_id'=>substr(md5((string)($link['id']??$text)),0,7), 'item_title'=>sanitize_text_field($text), 'item_link'=>array('url'=>esc_url_raw($href['url']??''),'is_external'=>!empty($href['is_external']),'nofollow'=>!empty($href['nofollow'])), 'item_dropdown_content'=>'' );
        }
        if ( count( $items ) < 2 ) { return null; }
        $settings = array( 'menu_name'=>'Imported navigation', 'menu_items'=>$items, 'item_layout'=>'horizontal', 'open_on'=>'hover' ); $this->style( $settings, 'mega-menu', 'menu_item_', $style, array('menu_item_text_color','menu_item_color') );
        return $this->widget( 'mega-menu', $settings );
    }

    private function form( array $root, array $style ) {
        if ( ! $this->supports( 'form', array( 'form_fields' => array( 'custom_id', 'field_type', 'field_label' ) ) ) ) { return null; }
        // Source_Fidelity_Engine resolves labels/names from the DOM. Prefer that
        // contract over re-deriving anonymous fields from descendants here.
        $fields = ! empty( $root['content']['fields'] ) ? array_values( (array) $root['content']['fields'] ) : array();
        if ( empty( $fields ) ) { $this->fields( $root, $fields ); }
        if ( empty( $fields ) ) { return null; }
        $rows = array(); foreach ( $fields as $i => $field ) {
            $id=sanitize_key((string)($field['id']??'field_'.($i+1))); if(''===$id){$id='field_'.($i+1);} $type=sanitize_key((string)($field['type']??'text'));
            if(!in_array($type,array('text','email','textarea','url','tel','number','date','time'),true)){$type='text';}
            $rows[]=array('_id'=>substr(md5($id.'|'.$i),0,7),'custom_id'=>$id,'field_type'=>$type,'field_label'=>sanitize_text_field($field['label']??ucfirst(str_replace('_',' ',$id))),'placeholder'=>sanitize_text_field($field['placeholder']??''),'required'=>!empty($field['required'])?'true':'','width'=>(string)($field['width']??'100'));
        }
        $button = sanitize_text_field( (string) ( $root['content']['button_text'] ?? '' ) ); if ( '' === $button ) { $button = $this->submit( $root ); }
        $settings=array(
            'form_fields'=>$rows,
            'form_name'=>sanitize_text_field($root['content']['form_name']??'Imported enquiry'),
            'button_text'=>$button,
            'show_labels'=>!empty($root['content']['show_labels'])?'yes':'',
            'mark_required'=>!empty($root['content']['mark_required'])?'yes':'',
            'submit_actions'=>array_values(array_filter(array_map('sanitize_key',(array)($root['content']['submit_actions']??array())))),
            'input_size'=>sanitize_key((string)($root['content']['input_size']??'md')),
        ); $this->style($settings,'form','field_',$style,array('field_text_color'));
        return $this->widget('form',$settings);
    }

    private function cta( array $root, array $style ) {
        // Current verified CTA binding covers title/body/button/link only. If source
        // owns media, collapsing it would silently drop a critical source atom.
        if ( $this->media( $root ) ) { return null; }
        // Same for functional atoms a CTA can never represent: forms and tables
        // must survive through their own dedicated branches, never collapse.
        if ( $this->has_unrepresentable( $root ) ) { return null; }
        if ( ! $this->supports( 'call-to-action', array( 'title'=>array(), 'description'=>array(), 'button'=>array(), 'link'=>array() ) ) ) { return null; }
        $title='';$body='';$button='';$href=array();$queue=array($root);
        while($queue){$n=array_shift($queue);$tag=strtolower((string)($n['source']['tag']??''));if(''===$title&&preg_match('/^h[1-6]$/',$tag)){$title=trim((string)($n['content']['text']??''));}if(''===$body&&in_array($tag,array('p','blockquote'),true)){$body=trim(wp_strip_all_tags((string)($n['content']['rich_text']??$n['content']['text']??'')));}if(''===$button&&'a'===$tag&&!empty($n['content']['link'])){$button=trim((string)($n['content']['text']??''));$href=(array)$n['content']['link'];}foreach($n['children']??array() as $id){if(isset($this->nodes[$id])){$queue[]=$this->nodes[$id];}}}
        if(''===$title&&''===$button){return null;}$settings=array('title'=>sanitize_text_field($title),'description'=>sanitize_textarea_field($body),'button'=>sanitize_text_field($button),'link'=>array('url'=>esc_url_raw($href['url']??''),'is_external'=>!empty($href['is_external']),'nofollow'=>!empty($href['nofollow'])));$this->style($settings,'call-to-action','title_',$style,array('title_color'));return $this->widget('call-to-action',$settings);
    }

    private function time_bar( array $root, array $style ) {
        if ( $this->has_unrepresentable( $root ) ) { return null; }
        if ( ! $this->supports( 'dc-global-time-bar', array( 'locations'=>array('label','timezone') ) ) ) { return null; }
        $known=array('australia'=>'Australia/Sydney','china'=>'Asia/Shanghai','singapore'=>'Asia/Singapore','japan'=>'Asia/Tokyo','south korea'=>'Asia/Seoul','korea'=>'Asia/Seoul','us'=>'America/New_York','united states'=>'America/New_York','uk'=>'Europe/London','united kingdom'=>'Europe/London');$locations=array();$queue=array($root);
        while($queue){$n=array_shift($queue);$label=trim(wp_strip_all_tags((string)($n['content']['text']??'')));$key=strtolower($label);$a=(array)($n['source']['attributes']??array());$tz=(string)($a['data-timezone']??$a['data-tz']??$a['timezone']??($known[$key]??''));if(''!==$tz&&''!==$label){$locations[$key]=array('_id'=>substr(md5($label.$tz),0,7),'label'=>sanitize_text_field($label),'timezone'=>sanitize_text_field($tz));}foreach($n['children']??array() as $id){if(isset($this->nodes[$id])){$queue[]=$this->nodes[$id];}}}
        if(count($locations)<2){return null;}$settings=array('title'=>'Current time in:','locations'=>array_values($locations));$this->style($settings,'dc-global-time-bar','',$style,array('title_color','location_color','time_color'));return $this->widget('dc-global-time-bar',$settings);
    }

    private function semantic_wrapper( array $root, array $style, array $native, $kind ) {
        $base=$this->single($root,$style);if(!$base||'widget'===($base['elType']??'')){$base=$this->container();}$base['elements']=array();
        foreach($root['children']??array() as $id){if(!isset($this->nodes[$id])){continue;}$child=$this->nodes[$id];if('faq'===$kind&&$this->qa_count($child)>0){continue;}if('navigation'===$kind&&!$this->media($child)&&$this->link_count($child)>0){continue;}if($mapped=$this->node($child,$style)){$base['elements'][]=$mapped;}}
        $base['elements'][]=$native;return $base;
    }
    private function outer_or_widget(array $root,array $style,array $native){$base=$this->single($root,$style);if($base&&'widget'!==($base['elType']??'')&&(!empty($base['settings'])||!empty($root['layout'])||!empty($root['spacing'])||!empty($root['style']))){$base['elements']=array($native);return $base;}return $native;}

    private function jump_nav( array $root, array $style ) {
        if ( $this->has_unrepresentable( $root ) ) { return null; }
        // A jump nav owns anchor links only: when the subtree also carries a
        // logo or other media, native composition preserves more content.
        if ( $this->media( $root ) ) { return null; }
        if ( $this->has_unrepresentable( $root ) ) { return null; }
        if ( ! $this->supports( 'dc-jump-nav', array( 'items' => array( 'label', 'link' ) ) ) ) { return null; }
        $links = array(); $this->links( $root, $links ); $items = array();
        foreach ( $links as $link ) {
            $text = trim( wp_strip_all_tags( (string) ( $link['content']['text'] ?? '' ) ) );
            $href = (string) ( $link['content']['link']['url'] ?? '' );
            if ( '' === $text || '#' === $href || $this->style_literal( $text ) ) { continue; }
            if ( 0 !== strpos( $href, '#' ) ) { continue; }
            $items[] = array( '_id' => substr( md5( (string) ( $link['id'] ?? $text ) ), 0, 7 ), 'label' => sanitize_text_field( $text ), 'link' => array( 'url' => esc_url_raw( $href ), 'is_external' => false, 'nofollow' => false ) );
        }
        if ( count( $items ) < 2 ) { return null; }
        return $this->widget( 'dc-jump-nav', array( 'items' => $items ) );
    }

    private function is_jump_nav( array $node ) {
        if ( 'jump-navigation' === sanitize_key( (string) ( $node['semantic']['role'] ?? '' ) ) ) { return true; }
        return $this->anchor_majority( $node );
    }

    private function anchor_majority( array $node ) {
        $links = array(); $this->links( $node, $links );
        if ( count( $links ) < 2 ) { return false; }
        $anchors = 0;
        foreach ( $links as $link ) {
            $url = (string) ( $link['content']['link']['url'] ?? '' );
            // Bare "#" placeholders are not section anchors.
            if ( strlen( $url ) > 1 && 0 === strpos( $url, '#' ) ) { $anchors++; }
        }
        return $anchors * 2 >= count( $links );
    }

    private function has_cta( array $node ) {
        $queue = array( $node );
        while ( $queue ) {
            $n = array_shift( $queue );
            if ( 'a' === strtolower( (string) ( $n['source']['tag'] ?? '' ) ) ) {
                $classes = implode( ' ', (array) ( $n['source']['classes'] ?? array() ) );
                if ( preg_match( '/btn|button|cta/i', $classes ) && '' !== trim( (string) ( $n['content']['text'] ?? '' ) ) ) { return true; }
            }
            foreach ( $n['children'] ?? array() as $id ) { if ( isset( $this->nodes[ $id ] ) ) { $queue[] = $this->nodes[ $id ]; } }
        }
        return false;
    }

    private function is_data_table( array $node ) {
        if ( 'table' === strtolower( (string) ( $node['source']['tag'] ?? '' ) ) ) { return true; }
        if ( in_array( sanitize_key( (string) ( $node['semantic']['role'] ?? '' ) ), array( 'comparison-table', 'data-table' ), true ) ) { return true; }
        $queue = array( $node );
        while ( $queue ) {
            $n = array_shift( $queue );
            $tag = strtolower( (string) ( $n['source']['tag'] ?? '' ) );
            if ( in_array( $tag, array( 'thead', 'tbody', 'tr', 'th', 'td' ), true ) ) { return true; }
            foreach ( $n['children'] ?? array() as $id ) { if ( isset( $this->nodes[ $id ] ) ) { $queue[] = $this->nodes[ $id ]; } }
        }
        return false;
    }

    private function data_table( array $root, array $style ) {
        if ( $this->has_unrepresentable( $root ) ) { return null; }
        if ( ! $this->supports( 'dc-responsive-table', array( 'rows' => array( 'cell_1' ) ) ) ) { return null; }
        $headers = array(); $rows = array();
        $queue = array( $root );
        while ( $queue ) {
            $n = array_shift( $queue );
            $tag = strtolower( (string) ( $n['source']['tag'] ?? '' ) );
            if ( 'th' === $tag ) { $headers[] = $this->cell_text( $n ); }
            if ( 'tr' === $tag ) {
                $cells = array();
                foreach ( $n['children'] ?? array() as $id ) {
                    if ( ! isset( $this->nodes[ $id ] ) ) { continue; }
                    $child = $this->nodes[ $id ];
                    if ( in_array( strtolower( (string) ( $child['source']['tag'] ?? '' ) ), array( 'td', 'th' ), true ) ) {
                        $cells[] = $this->cell_text( $child );
                    }
                }
                $cells = array_values( array_filter( $cells, static function ( $c ) { return '' !== $c; } ) );
                if ( $cells ) { $rows[] = $cells; }
            }
            foreach ( $n['children'] ?? array() as $id ) { if ( isset( $this->nodes[ $id ] ) ) { $queue[] = $this->nodes[ $id ]; } }
        }
        $width = max( count( $headers ), $rows ? max( array_map( 'count', $rows ) ) : 0 );
        if ( $width < 2 || 6 < $width ) { return null; }
        if ( count( $rows ) < 1 ) { return null; }
        $settings = array( 'column_count' => (string) $width );
        for ( $i = 1; $i <= $width; $i++ ) { $settings[ 'column_' . $i . '_label' ] = sanitize_text_field( $headers[ $i - 1 ] ?? ( 1 === $i ? 'Item' : 'Column ' . $i ) ); }
        $items = array();
        foreach ( $rows as $r => $cells ) {
            $row = array( '_id' => substr( md5( 'row|' . $r ), 0, 7 ) );
            for ( $i = 1; $i <= 6; $i++ ) { $row[ 'cell_' . $i ] = sanitize_textarea_field( $cells[ $i - 1 ] ?? '' ); }
            $items[] = $row;
        }
        $settings['rows'] = $items;
        return $this->widget( 'dc-responsive-table', $settings );
    }

    private function cell_text( array $node ) {
        $parts = array();
        $strip = function_exists( 'wp_strip_all_tags' ) ? 'wp_strip_all_tags' : 'strip_tags';
        $queue = array( $node );
        while ( $queue ) {
            $n = array_shift( $queue );
            foreach ( (array) ( $n['children'] ?? array() ) as $id ) { if ( isset( $this->nodes[ $id ] ) ) { $queue[] = $this->nodes[ $id ]; } }
            if ( ! empty( $n['children'] ) ) { continue; }
            // Prefer whichever representation is non-empty: table cells carry
            // plain text while rich_text stays an empty string (and ?? would
            // wrongly prefer that empty string).
            $text = trim( $strip( (string) ( $n['content']['text'] ?? '' ) ) );
            if ( '' === $text ) { $text = trim( $strip( (string) ( $n['content']['rich_text'] ?? '' ) ) ); }
            if ( '' !== $text ) { $parts[] = $text; }
        }
        if ( ! $parts ) {
            $text = trim( $strip( (string) ( $node['content']['text'] ?? '' ) ) );
            if ( '' === $text ) { $text = trim( $strip( (string) ( $node['content']['rich_text'] ?? '' ) ) ); }
            if ( '' !== $text ) { $parts[] = $text; }
        }
        return implode( ' ', $parts );
    }

    private function is_process_steps( array $node ) {
        if ( 'process-steps' === sanitize_key( (string) ( $node['semantic']['role'] ?? '' ) ) ) { return true; }
        $children = array();
        foreach ( $node['children'] ?? array() as $id ) { if ( isset( $this->nodes[ $id ] ) ) { $children[] = $this->nodes[ $id ]; } }
        if ( count( $children ) < 3 ) { return false; }
        $tags = array();
        foreach ( $children as $child ) { $tags[] = strtolower( (string) ( $child['source']['tag'] ?? '' ) ); }
        if ( 1 !== count( array_unique( $tags ) ) ) { return false; }
        $numbered = 0;
        foreach ( $children as $child ) {
            if ( $this->step_parts( $child ) ) { $numbered++; }
        }
        return $numbered * 2 >= count( $children );
    }

    private function step_parts( array $node ) {
        $number = ''; $title = ''; $text = '';
        $queue = array( $node );
        while ( $queue ) {
            $n = array_shift( $queue );
            $t = trim( wp_strip_all_tags( (string) ( $n['content']['text'] ?? '' ) ) );
            if ( '' === $number && preg_match( '/^\d{1,2}$/', $t ) ) { $number = $t; }
            $tag = strtolower( (string) ( $n['source']['tag'] ?? '' ) );
            if ( '' === $title && preg_match( '/^h[1-6]$/', $tag ) && '' !== trim( (string) ( $n['content']['text'] ?? '' ) ) ) { $title = sanitize_text_field( $n['content']['text'] ); }
            if ( '' === $text && 'p' === $tag ) { $text = sanitize_textarea_field( wp_strip_all_tags( (string) ( $n['content']['text'] ?? '' ) ) ); }
            foreach ( $n['children'] ?? array() as $id ) { if ( isset( $this->nodes[ $id ] ) ) { $queue[] = $this->nodes[ $id ]; } }
        }
        if ( '' === $number || ( '' === $title && '' === $text ) ) { return null; }
        return array( 'number' => $number, 'title' => $title, 'description' => $text );
    }

    private function process_steps( array $root, array $style ) {
        if ( $this->has_unrepresentable( $root ) ) { return null; }
        if ( ! $this->supports( 'dc-steps', array( 'steps' => array( 'number', 'title', 'description' ) ) ) ) { return null; }
        $steps = array();
        foreach ( $root['children'] ?? array() as $id ) {
            if ( ! isset( $this->nodes[ $id ] ) ) { continue; }
            $parts = $this->step_parts( $this->nodes[ $id ] );
            if ( ! $parts ) { return null; }
            $parts['_id'] = substr( md5( $id ), 0, 7 );
            $steps[] = $parts;
        }
        if ( count( $steps ) < 3 ) { return null; }
        return $this->widget( 'dc-steps', array( 'steps' => $steps, 'layout' => count( $steps ) > 4 ? 'grid' : 'vertical' ) );
    }

    private function supports( $widget, array $required ) {
        if(!class_exists('\\Elementor\\Plugin')||empty(\Elementor\Plugin::instance()->widgets_manager)||!\Elementor\Plugin::instance()->widgets_manager->get_widget_types($widget)||!$this->registry){return false;}
        $controls=(array)($this->registry->schema('widget',$widget)['controls']??array());
        foreach($required as $name=>$fields){if(!isset($controls[$name])){return false;}if($fields){$have=array();foreach((array)($controls[$name]['fields']??array()) as $key=>$field){if(is_array($field)){$have[]=(string)($field['name']??(is_string($key)?$key:''));}}foreach($fields as $field){if(!in_array($field,$have,true)){return false;}}}}
        return true;
    }
    private function style(array &$settings,$widget,$prefix,array $style,array $colors){if(!$this->registry){return;}foreach($colors as $name){if(isset($style['color'])&&$this->registry->has('widget',$widget,$name)){$settings[$name]=$style['color'];break;}}$map=array('font_family'=>array('font_family','font'),'font_weight'=>array('font_weight','select'),'font_size'=>array('font_size','slider'),'line_height'=>array('line_height','slider'),'letter_spacing'=>array('letter_spacing','slider'));$mapped=false;foreach($map as $source=>$target){if(!isset($style[$source])){continue;}$control=$this->registry->first_supported('widget',$widget,array($prefix.$target[0]),$target[1]);if(!$control){continue;}$value=$style[$source];if('slider'===$target[1]&&is_array($value)&&isset($value['value'])){$value=array('size'=>$value['value'],'unit'=>$value['unit']??'px');}$settings[$control]=$value;$mapped=true;}$toggle=$prefix.'typography';if($mapped&&$this->registry->has('widget',$widget,$toggle)){$settings[$toggle]='custom';}}
    private function inherit_text(array $parent,array $node){$keys=array('color','font_family','font_weight','font_size','line_height','letter_spacing','text_transform','font_style','text_decoration');$out=$parent;foreach($keys as $key){if(array_key_exists($key,(array)($node['style']??array()))){$out[$key]=$node['style'][$key];}}return $out;}

    private function qa_pairs(array $node,array &$pairs,$root=false){if(!$root&&($pair=$this->qa($node))){$pairs[]=$pair;return;}foreach($node['children']??array() as $id){if(isset($this->nodes[$id])){$this->qa_pairs($this->nodes[$id],$pairs);}}}
    private function qa(array $node){$tag=strtolower((string)($node['source']['tag']??''));if('details'===$tag){return $this->qa_details($node);}if(!in_array($tag,array('div','article','li','section'),true)){return null;}$title='';$body='';$heads=0;$queue=array($node);while($queue){$n=array_shift($queue);$t=strtolower((string)($n['source']['tag']??''));if(preg_match('/^h[2-6]$/',$t)&&''!==trim((string)($n['content']['text']??''))){$heads++;if(''===$title){$title=sanitize_text_field($n['content']['text']);}}if(''===$body&&in_array($t,array('p','blockquote'),true)){$body=wp_kses_post($n['content']['rich_text']??$n['content']['text']??'');}foreach($n['children']??array() as $id){if(isset($this->nodes[$id])){$queue[]=$this->nodes[$id];}}}return 1===$heads&&''!==trim($body)?array('title'=>$title,'content'=>$body):null;}
    private function has_disclosures(array $node,$minimum){$found=0;$queue=array($node);while($queue){$n=array_shift($queue);if('details'===strtolower((string)($n['source']['tag']??''))){$found++;if($found>=$minimum){return true;}continue;}foreach($n['children']??array() as $id){if(isset($this->nodes[$id])){$queue[]=$this->nodes[$id];}}}return false;}
    private function qa_details(array $node){$title='';$body='';foreach($node['children']??array() as $id){if(!isset($this->nodes[$id])){continue;}$child=$this->nodes[$id];$tag=strtolower((string)($child['source']['tag']??''));if('summary'===$tag&&''===$title){$title=sanitize_text_field(trim(wp_strip_all_tags((string)($child['content']['text']??''))));continue;}$text=trim(wp_strip_all_tags((string)($child['content']['rich_text']??$child['content']['text']??'')));if(''!==$text){$body.=(''===$body?'':' ').$text;}}return (''!==$title&&''!==$body)?array('title'=>$title,'content'=>$body):null;}
    private function qa_count(array $node){$pairs=array();$this->qa_pairs($node,$pairs);return count($pairs);}
    private function links(array $node,array &$links){if('a'===strtolower((string)($node['source']['tag']??''))&&!empty($node['content']['link'])){$links[]=$node;}foreach($node['children']??array() as $id){if(isset($this->nodes[$id])){$this->links($this->nodes[$id],$links);}}}
    private function fields(array $node,array &$fields){$tag=strtolower((string)($node['source']['tag']??''));$a=(array)($node['source']['attributes']??array());if(in_array($tag,array('input','textarea','select'),true)){$type='textarea'===$tag?'textarea':('select'===$tag?'text':sanitize_key((string)($a['type']??'text')));if(!in_array($type,array('submit','button','hidden','reset','checkbox','radio','file'),true)){$id=sanitize_key((string)($a['name']??$a['id']??'field_'.(count($fields)+1)));$fields[]=array('id'=>$id,'type'=>$type,'label'=>ucfirst(str_replace(array('-','_'),' ',$id)),'placeholder'=>sanitize_text_field($a['placeholder']??''),'required'=>array_key_exists('required',$a));}}foreach($node['children']??array() as $id){if(isset($this->nodes[$id])){$this->fields($this->nodes[$id],$fields);}}}
    private function submit(array $node){$queue=array($node);while($queue){$n=array_shift($queue);$tag=strtolower((string)($n['source']['tag']??''));$a=(array)($n['source']['attributes']??array());if('button'===$tag||('input'===$tag&&'submit'===strtolower((string)($a['type']??'')))){$text=trim((string)($n['content']['text']??$a['value']??''));if(''!==$text){return sanitize_text_field($text);}}foreach($n['children']??array() as $id){if(isset($this->nodes[$id])){$queue[]=$this->nodes[$id];}}}return 'Submit';}
    private function link_count(array $node){$n='a'===strtolower((string)($node['source']['tag']??''))?1:0;foreach($node['children']??array() as $id){if(isset($this->nodes[$id])){$n+=$this->link_count($this->nodes[$id]);}}return $n;}
    private function media(array $node){if('img'===strtolower((string)($node['source']['tag']??''))||!empty($node['content']['image']['url'])){return true;}foreach($node['children']??array() as $id){if(isset($this->nodes[$id])&&$this->media($this->nodes[$id])){return true;}}return false;}
    private function has_unrepresentable(array $node){$queue=array();foreach($node['children']??array() as $id){if(isset($this->nodes[$id])){$queue[]=$this->nodes[$id];}}while($queue){$n=array_shift($queue);$tag=strtolower((string)($n['source']['tag']??''));if(in_array($tag,array('form','table','video','audio','iframe','canvas'),true)){return true;}foreach($n['children']??array() as $id){if(isset($this->nodes[$id])){$queue[]=$this->nodes[$id];}}}return false;}
    private function is_time_bar(array $node){$identity=implode(' ',(array)($node['source']['classes']??array())).' '.(string)($node['source']['attributes']['id']??'');if(preg_match('/(?:global|world|current)[-_ ]?time|time[-_ ]?bar/i',$identity)){return true;}$direct=strtolower(trim(wp_strip_all_tags((string)($node['content']['text']??''))));if(false===strpos($direct,'current time in')){return false;}$all=$direct;$queue=array($node);while($queue){$n=array_shift($queue);$all.=' '.strtolower(trim(wp_strip_all_tags((string)($n['content']['text']??''))));foreach($n['children']??array() as $id){if(isset($this->nodes[$id])){$queue[]=$this->nodes[$id];}}}$count=0;foreach(array('australia','china','singapore','japan','korea','us','uk') as $label){if(false!==strpos($all,$label)){$count++;}}return $count>=2;}
    private function style_literal($text){return (bool)preg_match('/^\s*(?:#[0-9a-f]{3,8}|rgba?\([^\)]+\)|hsla?\([^\)]+\))\s*$/i',(string)$text);}
    private function widget($type,array $settings){if(class_exists('Design_Core_Elementor_Binding_Governor')){$notes=array();$settings=Design_Core_Elementor_Binding_Governor::govern_settings('widget',$type,is_array($settings)?$settings:array(),$this->registry,$notes);}$settings=is_array($settings)?$settings:array();return array('id'=>$this->id(),'elType'=>'widget','widgetType'=>$type,'settings'=>$settings,'elements'=>array());}
    private function container(){return array('id'=>$this->id(),'elType'=>'container','isInner'=>false,'settings'=>array(),'elements'=>array());}
    private function id(){return substr(md5(uniqid('',true)),0,8);}
}
