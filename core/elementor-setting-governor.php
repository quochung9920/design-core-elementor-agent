<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Runtime-aware guard for Elementor setting edge cases. The live Control Schema
 * Registry remains authoritative; no control is emitted merely because another
 * Elementor integration documents it.
 */
class Design_Core_Elementor_Elementor_Setting_Governor {
    const VERSION = 1;
    private $registry;

    public function __construct( Design_Core_Elementor_Control_Schema_Registry $registry = null ) {
        $this->registry = $registry ?: new Design_Core_Elementor_Control_Schema_Registry();
    }

    /** Post-process mapped V3 elements using their corresponding IR structure. */
    public function govern_document( array $elements, array $ir ) {
        $nodes=array();foreach((array)($ir['nodes']??array()) as $node){if(is_array($node)&&!empty($node['id'])){$nodes[$node['id']]=$node;}}
        foreach(array_values((array)($ir['root_ids']??array())) as $index=>$root_id){if(isset($elements[$index],$nodes[$root_id])&&is_array($elements[$index])){$elements[$index]=$this->govern_pair($elements[$index],$nodes[$root_id],$nodes);}}
        return $elements;
    }

    public function govern( $element_type, $widget_type, array $settings, array $node = array(), $creating = true ) {
        $warnings = $this->dimension_warnings( $settings ); $applied = array();
        if ( 'container' === sanitize_key( (string) $element_type ) && 'grid' === strtolower( (string) ( $node['layout']['display'] ?? '' ) ) ) {
            $result = $this->govern_grid( $settings, $node, (bool) $creating );
            $settings = $result['settings']; $warnings = array_merge( $warnings, $result['warnings'] ); $applied = $result['applied'];
        }
        return array( 'settings'=>$settings, 'warnings'=>array_values(array_unique($warnings)), 'applied'=>array_values(array_unique($applied)) );
    }

    private function govern_pair( array $element, array $node, array $nodes ) {
        if('container'===($element['elType']??'')){
            $governed=$this->govern('container','',(array)($element['settings']??array()),$node,true);
            $element['settings']=$governed['settings'];
        }
        $node_children=array_values((array)($node['children']??array()));$element_children=array_values((array)($element['elements']??array()));
        // Recurse only while cardinality matches. This prevents applying an IR child's
        // settings to the wrong Elementor element when a semantic node is intentionally
        // collapsed or omitted by the mapper.
        if(count($node_children)===count($element_children)){
            foreach($node_children as $i=>$child_id){if(isset($nodes[$child_id],$element_children[$i])&&is_array($element_children[$i])){$element_children[$i]=$this->govern_pair($element_children[$i],$nodes[$child_id],$nodes);}}
            $element['elements']=$element_children;
        }
        return $element;
    }

    private function govern_grid( array $settings, array $node, $creating ) {
        $warnings=array();$applied=array();
        $type_control = $this->registry->first_supported( 'container', '', array( 'container_type' ) );
        if ( ! $type_control ) {
            $warnings[] = 'Grid IR requested, but the live Elementor container schema exposes no container_type control; no grid-specific private setting was guessed.';
            return array('settings'=>$settings,'warnings'=>$warnings,'applied'=>$applied);
        }
        $settings[$type_control]='grid';$applied[]=$type_control;

        $columns = max( 1, (int) ( $node['layout']['columns'] ?? 0 ) );
        if ( $columns > 0 ) {
            $control = $this->registry->first_supported( 'container', '', array( 'grid_columns_grid' ) );
            if ( $control ) { $settings[$control]=array('unit'=>'fr','size'=>$columns);$applied[]=$control; }
            else { $warnings[]='Grid column count is present in Design IR, but grid_columns_grid is not exposed by the live Elementor runtime.'; }
        }

        $row_control = $this->registry->first_supported( 'container', '', array( 'grid_rows_grid' ) );
        if ( $creating && $row_control && ! array_key_exists( $row_control, $settings ) ) {
            // Explicit one-row seed avoids Elementor's classic grid default adding a
            // second row when Design Core authored a single-row grid recipe.
            $settings[$row_control]=array('unit'=>'fr','size'=>1);$applied[]=$row_control;
        } elseif ( $creating && ! $row_control ) {
            $warnings[]='Live Elementor runtime exposes no grid_rows_grid control; Design Core cannot safely normalize the classic grid row default.';
        }

        $gap = $node['layout']['gap'] ?? null;
        if ( null !== $gap ) {
            $gap_control = $this->registry->first_supported( 'container', '', array( 'grid_gaps' ) );
            if ( $gap_control ) {
                $encoder = new Design_Core_Elementor_Control_Value_Encoder();
                $settings[$gap_control]=$encoder->encode($gap,'gaps');$applied[]=$gap_control;
            }
        }
        // Elementor wraps boxed container children in .e-con-inner, leaving a
        // grid container with a single grid item and collapsing auto-fit tracks.
        // A grid container carrying its own track template must therefore be
        // full-width, with the IR max-width re-expressed as centering CSS.
        $has_template = isset( $settings['custom_css'] ) && false !== strpos( (string) $settings['custom_css'], 'grid-template' );
        if ( $has_template ) {
            $width_control = $this->registry->first_supported( 'container', '', array( 'content_width' ) );
            if ( $width_control ) { $settings[ $width_control ] = 'full'; $applied[] = $width_control; }
            $max = $node['layout']['max_width'] ?? null;
            if ( is_array( $max ) && isset( $max['value'] ) && is_numeric( $max['value'] ) ) {
                $unit = in_array( $max['unit'] ?? '', array( 'px', '%', 'em', 'rem', 'vw' ), true ) ? (string) $max['unit'] : 'px';
                $settings['custom_css'] = trim( (string) ( $settings['custom_css'] ?? '' ) ) . "\nselector{max-width:" . $max['value'] . $unit . ";margin-left:auto;margin-right:auto;}";
            } else { $warnings[] = 'Grid container uses full width but IR max-width is unavailable; centering CSS was not applied.'; }
        }
        return array('settings'=>$settings,'warnings'=>$warnings,'applied'=>$applied);
    }

    private function dimension_warnings( array $settings ) {
        $warnings=array();
        foreach ( $settings as $key=>$value ) {
            if ( ! is_array($value) || ! $this->looks_like_dimensions($value) ) { continue; }
            $missing=array();
            foreach(array('top','right','bottom','left') as $side){if(!array_key_exists($side,$value)||''===(string)$value[$side]){$missing[]=$side;}}
            if($missing){$warnings[]=$key.' has blank or missing dimension sides ('.implode(', ',$missing).'). Elementor may omit the whole generated CSS declaration; Design Core leaves externally supplied partial values unchanged.';}
        }
        return $warnings;
    }

    private function looks_like_dimensions( array $value ) {
        $keys=array_intersect(array_keys($value),array('top','right','bottom','left','isLinked'));
        return count($keys)>=2 || isset($value['isLinked']);
    }
}
