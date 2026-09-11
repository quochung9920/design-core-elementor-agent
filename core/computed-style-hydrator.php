<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Converts bounded Playwright computed-style evidence into canonical Design IR values.
 * Browser evidence is authoritative for cascade/inheritance at viewports that map to
 * real Elementor responsive states. Geometry remains evidence only: used pixel width
 * and height are not blindly authored back as fixed controls.
 */
final class Design_Core_Elementor_Computed_Style_Hydrator {
    const VERSION = 1;

    private $breakpoints;
    private $owned = array(
        'layout' => array( 'display','direction','wrap','justify','align','gap','columns','overflow','position','z_index' ),
        'style' => array( 'color','background','font_family','font_size','font_weight','line_height','letter_spacing','align','text_transform','radius','shadow','opacity','border_width','border_style','border_color','object_fit','object_position','background_size','background_position','css_fallback' ),
        'spacing' => array( 'margin','padding' ),
    );

    public function __construct( Design_Core_Elementor_Breakpoint_Registry $breakpoints = null ) {
        $this->breakpoints = $breakpoints ?: new Design_Core_Elementor_Breakpoint_Registry();
    }

    public function hydrate( array $ir, array $browser_analysis ) {
        $payload = (array) ( $browser_analysis['data'] ?? $browser_analysis );
        $viewports = (array) ( $payload['viewports'] ?? array() );
        if ( ! $viewports ) {
            $ir['computed_style_hydration'] = array( 'version'=>self::VERSION, 'status'=>'not_available', 'hydrated_nodes'=>0, 'viewports'=>array() );
            return $ir;
        }
        $index = $this->index( $viewports );
        $available_widths = array_map( 'intval', array_keys( $viewports ) );
        rsort( $available_widths, SORT_NUMERIC );
        $matrix = $this->breakpoints->viewport_matrix();
        $desktop_width = isset( $matrix['desktop'] ) && isset( $viewports[ (string) $matrix['desktop'] ] ) ? (int) $matrix['desktop'] : (int) ( $available_widths[0] ?? 0 );
        if ( $desktop_width <= 0 ) {
            $ir['computed_style_hydration'] = array( 'version'=>self::VERSION, 'status'=>'invalid_browser_evidence', 'hydrated_nodes'=>0, 'viewports'=>$available_widths );
            return $ir;
        }

        $device_widths = array();
        foreach ( $matrix as $device => $width ) {
            $device = sanitize_key( (string) $device ); $width = (int) $width;
            if ( 'desktop' === $device || 'mobile-small' === $device || ! isset( $viewports[ (string) $width ] ) ) { continue; }
            if ( in_array( $device, array( 'widescreen','laptop','tablet_extra','tablet','mobile_extra','mobile' ), true ) ) { $device_widths[ $device ] = $width; }
        }

        $hydrated = 0; $missing_paths = array(); $small_viewport_drift = array();
        foreach ( $ir['nodes'] ?? array() as $node_index => $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $path = (string) ( $node['source']['dom_path'] ?? '' );
            if ( '' === $path || empty( $index[ $desktop_width ][ $path ] ) ) { if ( '' !== $path ) { $missing_paths[] = $path; } continue; }
            $desktop_values = $this->values( $node, $index[ $desktop_width ][ $path ] );
            $node = $this->apply_authoritative_groups( $node, $desktop_values );

            foreach ( $device_widths as $device => $width ) {
                if ( empty( $index[ $width ][ $path ] ) ) { continue; }
                $device_values = $this->values( $node, $index[ $width ][ $path ] );
                $delta = $this->delta( $desktop_values, $device_values );
                $current = is_array( $node['responsive'][ $device ] ?? null ) ? $node['responsive'][ $device ] : array();
                $current = $this->clear_owned_groups( $current );
                foreach ( array( 'layout','style','spacing' ) as $group ) {
                    if ( ! empty( $delta[ $group ] ) ) { $current[ $group ] = array_replace_recursive( (array) ( $current[ $group ] ?? array() ), $delta[ $group ] ); }
                }
                if ( array_filter( $current, 'is_array' ) ) { $node['responsive'][ $device ] = $current; }
                else { unset( $node['responsive'][ $device ] ); }
            }

            // 390 is a QA viewport, not necessarily an authorable Elementor breakpoint.
            // Record material drift rather than inventing a private responsive control.
            if ( isset( $index[390][ $path ] ) && ! in_array( 390, $device_widths, true ) ) {
                $small_values = $this->values( $node, $index[390][ $path ] );
                $mobile_base = isset( $device_widths['mobile'], $index[ $device_widths['mobile'] ][ $path ] )
                    ? $this->values( $node, $index[ $device_widths['mobile'] ][ $path ] ) : $desktop_values;
                $small_delta = $this->delta( $mobile_base, $small_values );
                if ( $this->material_delta( $small_delta ) ) { $small_viewport_drift[] = (string) ( $node['id'] ?? $path ); }
            }
            $ir['nodes'][ $node_index ] = $node; $hydrated++;
        }

        $ir['computed_style_hydration'] = array(
            'version' => self::VERSION,
            'status' => $hydrated ? 'pass' : 'no_matching_dom_paths',
            'hydrated_nodes' => $hydrated,
            'desktop_width' => $desktop_width,
            'responsive_viewports' => $device_widths,
            'available_viewports' => $available_widths,
            'missing_dom_path_count' => count( array_unique( $missing_paths ) ),
            'unrepresented_small_viewport_drift_count' => count( array_unique( $small_viewport_drift ) ),
            'unrepresented_small_viewport_nodes' => array_slice( array_values( array_unique( $small_viewport_drift ) ), 0, 100 ),
            'evidence_hash' => hash( 'sha256', wp_json_encode( array( $desktop_width, $device_widths, $hydrated, $small_viewport_drift ) ) ),
        );
        return $ir;
    }

    private function index( array $viewports ) {
        $index = array();
        foreach ( $viewports as $width => $rows ) {
            $width = (int) $width;
            foreach ( (array) $rows as $row ) {
                if ( ! is_array( $row ) || empty( $row['domPath'] ) ) { continue; }
                $index[ $width ][ (string) $row['domPath'] ] = $row;
            }
        }
        return $index;
    }

    private function values( array $node, array $evidence ) {
        $styles = (array) ( $evidence['styles'] ?? array() );
        $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
        $layout = array(); $style = array(); $spacing = array();
        $display = strtolower( trim( (string) ( $styles['display'] ?? '' ) ) );
        if ( in_array( $display, array( 'flex','inline-flex','grid','inline-grid' ), true ) ) { $layout['display'] = false !== strpos( $display, 'grid' ) ? 'grid' : 'flex'; }
        if ( false !== strpos( $display, 'flex' ) ) {
            $this->copy_nondefault( $layout, 'direction', $styles['flexDirection'] ?? '', array( 'row' ) );
            $this->copy_nondefault( $layout, 'wrap', $styles['flexWrap'] ?? '', array( 'nowrap' ) );
            $this->copy_nondefault( $layout, 'justify', $styles['justifyContent'] ?? '', array( 'normal' ) );
            $this->copy_nondefault( $layout, 'align', $styles['alignItems'] ?? '', array( 'normal','stretch' ) );
        }
        if ( false !== strpos( $display, 'grid' ) ) {
            $columns = $this->track_count( (string) ( $styles['gridTemplateColumns'] ?? '' ) );
            if ( $columns > 0 ) { $layout['columns'] = $columns; }
            $this->copy_nondefault( $layout, 'justify', $styles['justifyContent'] ?? '', array( 'normal' ) );
            $this->copy_nondefault( $layout, 'align', $styles['alignItems'] ?? '', array( 'normal','stretch' ) );
        }
        $gap = $this->gap( (string) ( $styles['gap'] ?? '' ) );
        if ( null !== $gap && $this->nonzero_gap( $gap ) ) { $layout['gap'] = $gap; }
        $this->copy_nondefault( $layout, 'overflow', $styles['overflow'] ?? '', array( 'visible','clip' ) );
        $this->copy_nondefault( $layout, 'position', $styles['position'] ?? '', array( 'static' ) );
        $z = trim( (string) ( $styles['zIndex'] ?? '' ) ); if ( '' !== $z && 'auto' !== strtolower( $z ) ) { $layout['z_index'] = sanitize_text_field( $z ); }

        $is_text = preg_match( '/^h[1-6]$/', $tag ) || in_array( $tag, array( 'p','blockquote','a','button','span','label','li','input','textarea','select' ), true );
        if ( $is_text ) {
            $color = trim( (string) ( $styles['color'] ?? '' ) ); if ( '' !== $color ) { $style['color'] = sanitize_text_field( $color ); }
            $family = $this->first_font_family( (string) ( $styles['fontFamily'] ?? '' ) ); if ( '' !== $family ) { $style['font_family'] = $family; }
            $font_size = $this->dimension( $styles['fontSize'] ?? '' ); if ( $font_size ) { $style['font_size'] = $font_size; }
            $weight = trim( (string) ( $styles['fontWeight'] ?? '' ) ); if ( '' !== $weight && 'normal' !== strtolower( $weight ) ) { $style['font_weight'] = sanitize_text_field( $weight ); }
            $line_height = $this->dimension( $styles['lineHeight'] ?? '' ); if ( $line_height ) { $style['line_height'] = $line_height; }
            $letter_spacing = $this->dimension( $styles['letterSpacing'] ?? '' ); if ( $letter_spacing ) { $style['letter_spacing'] = $letter_spacing; }
            $this->copy_nondefault( $style, 'align', $styles['textAlign'] ?? '', array( 'start','auto' ) );
            $this->copy_nondefault( $style, 'text_transform', $styles['textTransform'] ?? '', array( 'none' ) );
        }

        $background = trim( (string) ( $styles['backgroundColor'] ?? '' ) );
        if ( '' !== $background && ! $this->transparent( $background ) ) { $style['background'] = sanitize_text_field( $background ); }
        $radius = $this->box( $styles['borderRadius'] ?? '' ); if ( $radius && $this->nonzero_box( $radius ) ) { $style['radius'] = $radius; }
        $shadow = trim( (string) ( $styles['boxShadow'] ?? '' ) ); if ( '' !== $shadow && 'none' !== strtolower( $shadow ) ) { $style['shadow'] = sanitize_text_field( $shadow ); }
        $opacity = trim( (string) ( $styles['opacity'] ?? '' ) ); if ( is_numeric( $opacity ) && abs( (float) $opacity - 1.0 ) > 0.0001 ) { $style['opacity'] = (float) $opacity; }

        $border_width = $this->dimension( $styles['borderTopWidth'] ?? '' );
        $border_style = strtolower( trim( (string) ( $styles['borderTopStyle'] ?? '' ) ) );
        if ( $border_width && (float) $border_width['value'] > 0 && ! in_array( $border_style, array( '', 'none', 'hidden' ), true ) ) {
            $style['border_width'] = array( 'top'=>$border_width['value'], 'right'=>$border_width['value'], 'bottom'=>$border_width['value'], 'left'=>$border_width['value'], 'unit'=>$border_width['unit'] );
            $style['border_style'] = sanitize_key( $border_style );
            $border_color = trim( (string) ( $styles['borderTopColor'] ?? '' ) ); if ( '' !== $border_color ) { $style['border_color'] = sanitize_text_field( $border_color ); }
        }

        if ( 'img' === $tag ) {
            $this->copy_nondefault( $style, 'object_fit', $styles['objectFit'] ?? '', array( 'fill' ) );
            $position = trim( (string) ( $styles['objectPosition'] ?? '' ) ); if ( '' !== $position && ! in_array( $position, array( '50% 50%', 'center center' ), true ) ) { $style['object_position'] = sanitize_text_field( $position ); }
        }
        if ( ! empty( $node['style']['background_image'] ) ) {
            $this->copy_nondefault( $style, 'background_size', $styles['backgroundSize'] ?? '', array( 'auto','auto auto' ) );
            $position = trim( (string) ( $styles['backgroundPosition'] ?? '' ) ); if ( '' !== $position && ! in_array( $position, array( '0% 0%', '0px 0px' ), true ) ) { $style['background_position'] = sanitize_text_field( $position ); }
        }

        $padding = $this->sides( $styles, 'padding' );
        $margin = $this->sides( $styles, 'margin' );
        if ( $padding && ( $this->nonzero_box( $padding ) || $is_text ) ) { $spacing['padding'] = $padding; }
        if ( $margin && ( $this->nonzero_box( $margin ) || $is_text ) ) { $spacing['margin'] = $margin; }

        $fallback = array();
        $transform = trim( (string) ( $styles['transform'] ?? '' ) ); if ( '' !== $transform && 'none' !== strtolower( $transform ) ) { $fallback['transform'] = sanitize_text_field( $transform ); }
        $transition = trim( (string) ( $styles['transition'] ?? '' ) ); if ( '' !== $transition && ! preg_match( '/^(?:all|none) 0s (?:ease|linear) 0s$/i', $transition ) ) { $fallback['transition'] = sanitize_text_field( $transition ); }
        $aspect = trim( (string) ( $styles['aspectRatio'] ?? '' ) ); if ( '' !== $aspect && 'auto' !== strtolower( $aspect ) ) { $fallback['aspect-ratio'] = sanitize_text_field( $aspect ); }
        if ( $fallback ) { $style['css_fallback'] = $fallback; }

        return array( 'layout'=>$layout, 'style'=>$style, 'spacing'=>$spacing );
    }

    private function apply_authoritative_groups( array $node, array $values ) {
        foreach ( array( 'layout','style','spacing' ) as $group ) {
            $existing = is_array( $node[ $group ] ?? null ) ? $node[ $group ] : array();
            foreach ( $this->owned[ $group ] as $key ) { unset( $existing[ $key ] ); }
            $node[ $group ] = array_replace_recursive( $existing, (array) ( $values[ $group ] ?? array() ) );
        }
        return $node;
    }

    private function clear_owned_groups( array $values ) {
        foreach ( array( 'layout','style','spacing' ) as $group ) {
            $current = is_array( $values[ $group ] ?? null ) ? $values[ $group ] : array();
            foreach ( $this->owned[ $group ] as $key ) { unset( $current[ $key ] ); }
            if ( $current ) { $values[ $group ] = $current; } else { unset( $values[ $group ] ); }
        }
        return $values;
    }

    private function delta( array $base, array $next ) {
        $out = array();
        foreach ( array( 'layout','style','spacing' ) as $group ) {
            $keys = array_unique( array_merge( array_keys( (array) ( $base[ $group ] ?? array() ) ), array_keys( (array) ( $next[ $group ] ?? array() ) ) ) );
            foreach ( $keys as $key ) {
                $a = $base[ $group ][ $key ] ?? null; $b = $next[ $group ][ $key ] ?? null;
                if ( wp_json_encode( $a ) !== wp_json_encode( $b ) && null !== $b ) { $out[ $group ][ $key ] = $b; }
            }
        }
        return $out;
    }

    private function material_delta( array $delta ) { foreach ( $delta as $group ) { if ( ! empty( $group ) ) { return true; } } return false; }

    private function copy_nondefault( array &$target, $key, $value, array $defaults ) {
        $value = strtolower( trim( (string) $value ) );
        if ( '' !== $value && ! in_array( $value, $defaults, true ) ) { $target[ $key ] = sanitize_text_field( $value ); }
    }

    private function dimension( $value ) {
        if ( ! preg_match( '/^\s*(-?[0-9.]+)\s*(px|%|em|rem|vh|vw|vmin|vmax|ch|ex)?\s*$/i', (string) $value, $match ) ) { return null; }
        return array( 'value'=>(float)$match[1], 'unit'=>strtolower( $match[2] ?: 'px' ) );
    }

    private function sides( array $styles, $prefix ) {
        $values = array();
        foreach ( array( 'Top'=>'top','Right'=>'right','Bottom'=>'bottom','Left'=>'left' ) as $suffix => $key ) {
            $dimension = $this->dimension( $styles[ $prefix . $suffix ] ?? '' ); if ( ! $dimension || 'px' !== $dimension['unit'] ) { return null; }
            $values[ $key ] = $dimension['value'];
        }
        $values['unit'] = 'px'; return $values;
    }

    private function box( $value ) {
        $parts = array_values( array_filter( preg_split( '/\s+/', trim( (string) $value ) ) ) );
        if ( ! $parts || count( $parts ) > 4 ) { return null; }
        $dims = array(); foreach ( $parts as $part ) { $dim=$this->dimension($part); if(!$dim||'px'!==$dim['unit']){return null;} $dims[]=$dim['value']; }
        if(1===count($dims)){$dims=array($dims[0],$dims[0],$dims[0],$dims[0]);}elseif(2===count($dims)){$dims=array($dims[0],$dims[1],$dims[0],$dims[1]);}elseif(3===count($dims)){$dims=array($dims[0],$dims[1],$dims[2],$dims[1]);}
        return array('top'=>$dims[0],'right'=>$dims[1],'bottom'=>$dims[2],'left'=>$dims[3],'unit'=>'px');
    }

    private function gap( $value ) {
        $value = trim( (string) $value ); if ( '' === $value || 'normal' === strtolower( $value ) ) { return null; }
        $parts = array_values( array_filter( preg_split( '/\s+/', $value ) ) ); if ( ! $parts || count($parts)>2 ) { return null; }
        $row=$this->dimension($parts[0]);$column=$this->dimension($parts[1]??$parts[0]);if(!$row||!$column||$row['unit']!==$column['unit']){return null;}
        if($row['value']===$column['value']){return array('value'=>$row['value'],'unit'=>$row['unit']);}
        return array('row'=>$row['value'],'column'=>$column['value'],'unit'=>$row['unit']);
    }

    private function nonzero_gap( array $gap ) { return abs((float)($gap['value']??$gap['row']??0))>0.0001 || abs((float)($gap['column']??0))>0.0001; }
    private function nonzero_box( array $box ) { foreach(array('top','right','bottom','left') as $side){if(abs((float)($box[$side]??0))>0.0001){return true;}}return false; }
    private function transparent( $color ) { $color=strtolower(preg_replace('/\s+/','',(string)$color));return in_array($color,array('transparent','rgba(0,0,0,0)','rgb(0 0 0/0)','rgba(0 0 0/0)'),true); }

    private function first_font_family( $value ) {
        $first = trim( explode( ',', (string) $value )[0] ?? '' );
        return sanitize_text_field( trim( $first, " \t\n\r\0\x0B\"'" ) );
    }

    private function track_count( $value ) {
        $value=trim((string)$value);if(''===$value||'none'===strtolower($value)){return 0;}$depth=0;$count=0;$token='';
        for($i=0,$len=strlen($value);$i<$len;$i++){$char=$value[$i];if('('===$char){$depth++;}$token.=$char;if(')'===$char){$depth=max(0,$depth-1);}if(0===$depth&&ctype_space($char)){if(''!==trim($token)){$count++;$token='';}}}
        if(''!==trim($token)){$count++;}return $count>0&&$count<=24?$count:0;
    }
}
