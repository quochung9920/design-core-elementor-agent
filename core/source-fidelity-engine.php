<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Source-fidelity contracts for HTML/Figma -> Elementor compilation.
 *
 * This layer is intentionally platform-neutral on input: it reads Design IR and
 * browser-analysis evidence, then records what MUST survive compilation. It does
 * not pick widgets. The planner/mapper may choose any native implementation as
 * long as critical source atoms are preserved.
 */
final class Design_Core_Elementor_Source_Fidelity_Engine {
    const VERSION = 1;

    private const STRUCTURAL_TAGS = array( 'div', 'section', 'article', 'header', 'footer', 'main', 'nav', 'aside', 'li' );
    private const COLLAPSIBLE_ROLES = array( 'faq', 'navigation', 'form', 'global-time-bar', 'jump-navigation', 'process-steps', 'comparison-table', 'data-table' );

    public function enrich( array $ir, array $browser_analysis = array() ) {
        $nodes = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            if ( is_array( $node ) && ! empty( $node['id'] ) ) { $nodes[ (string) $node['id'] ] = $node; }
        }
        $browser = $this->browser_index( $browser_analysis );
        // Hydrate canonical form content from descendant source nodes before any
        // widget planning. This prevents anonymous Elementor fields (form-field-)
        // and preserves source labels/names without inventing actions.
        foreach ( array_keys( $nodes ) as $id ) {
            if ( 'form' !== strtolower( (string) ( $nodes[ $id ]['source']['tag'] ?? '' ) ) ) { continue; }
            $nodes[ $id ]['content'] = array_merge( (array) ( $nodes[ $id ]['content'] ?? array() ), $this->form_contract( $id, $nodes ) );
        }

        foreach ( $nodes as $id => &$node ) {
            $atoms = $this->subtree_atoms( $id, $nodes );
            $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
            $role = sanitize_key( (string) ( $node['semantic']['role'] ?? '' ) );
            $policy = $this->composition_policy( $tag, $role, $atoms, $node );
            $node['semantic'] = is_array( $node['semantic'] ?? null ) ? $node['semantic'] : array();
            $node['semantic']['composition_policy'] = $policy;
            $node['fidelity'] = array(
                'version' => self::VERSION,
                'composition_policy' => $policy,
                'atoms' => $atoms,
                'critical' => array(
                    'images' => (int) ( $atoms['images'] ?? 0 ),
                    'forms' => (int) ( $atoms['forms'] ?? 0 ),
                    'fields' => (int) ( $atoms['fields'] ?? 0 ),
                    'links' => (int) ( $atoms['links'] ?? 0 ),
                ),
                'source_hash' => hash( 'sha256', wp_json_encode( array(
                    $node['source']['dom_path'] ?? '', $node['source']['tag'] ?? '', $node['source']['classes'] ?? array(),
                    $node['content'] ?? array(), $node['layout'] ?? array(), $node['style'] ?? array(), $node['spacing'] ?? array(), $node['responsive'] ?? array(),
                ) ) ),
                'browser' => $browser[ (string) ( $node['source']['dom_path'] ?? '' ) ] ?? array(),
            );
        }
        unset( $node );
        $ir['nodes'] = array_values( $nodes );
        $ir['component_graph'] = $this->graph( $ir );
        $ir['source_fidelity'] = $this->contract( $ir );
        return $ir;
    }

    public function contract( array $ir ) {
        $counts = $this->atom_counts( (array) ( $ir['nodes'] ?? array() ) );
        $preserve = 0;
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            if ( 'preserve-children' === ( $node['semantic']['composition_policy'] ?? '' ) ) { $preserve++; }
        }
        return array(
            'version' => self::VERSION,
            'critical_atoms' => array(
                'images' => (int) ( $counts['images'] ?? 0 ),
                'forms' => (int) ( $counts['forms'] ?? 0 ),
                'fields' => (int) ( $counts['fields'] ?? 0 ),
            ),
            'content_atoms' => $counts,
            'preserve_children_nodes' => $preserve,
            'contract_hash' => hash( 'sha256', wp_json_encode( array( $counts, $preserve ) ) ),
        );
    }

    /**
     * Verify only critical source atoms against a compiled Elementor tree.
     * This is deliberately conservative: it never claims pixel/interaction QA.
     */
    public function verify_elementor_tree( array $ir, array $elements ) {
        $contract = $this->contract( $ir );
        $compiled = $this->compiled_atoms( $elements );
        $issues = array();
        foreach ( array( 'images', 'forms' ) as $kind ) {
            $expected = (int) ( $contract['critical_atoms'][ $kind ] ?? 0 );
            $actual = (int) ( $compiled[ $kind ] ?? 0 );
            if ( $actual < $expected ) { $issues[] = $kind . '-dropped:' . $actual . '/' . $expected; }
        }
        if ( 0 < (int) ( $contract['critical_atoms']['fields'] ?? 0 ) && 0 < (int) ( $compiled['forms'] ?? 0 ) ) {
            $expected = (int) $contract['critical_atoms']['fields'];
            $actual = (int) ( $compiled['fields'] ?? 0 );
            if ( $actual < $expected ) { $issues[] = 'fields-dropped:' . $actual . '/' . $expected; }
        }
        return array(
            'version' => self::VERSION,
            'status' => $issues ? 'fail' : 'pass',
            'contract' => $contract,
            'compiled' => $compiled,
            'issues' => $issues,
        );
    }

    public function collapse_risk( array $node, $strategy ) {
        $strategy = sanitize_key( (string) $strategy );
        if ( ! in_array( $strategy, array( 'native-widget', 'reuse-widget', 'custom-widget' ), true ) ) { return array(); }
        if ( 'preserve-children' !== ( $node['semantic']['composition_policy'] ?? '' ) ) { return array(); }
        return array( 'strategy-collapses-preserve-children-subtree' );
    }

    private function composition_policy( $tag, $role, array $atoms, array $node ) {
        if ( in_array( $role, self::COLLAPSIBLE_ROLES, true ) ) { return 'replace-with-verified-native'; }
        if ( ! in_array( $tag, self::STRUCTURAL_TAGS, true ) ) { return 'leaf-native'; }
        $semantic_atoms = (int) ( $atoms['headings'] ?? 0 ) + (int) ( $atoms['paragraphs'] ?? 0 ) + (int) ( $atoms['images'] ?? 0 ) + (int) ( $atoms['links'] ?? 0 ) + (int) ( $atoms['forms'] ?? 0 ) + (int) ( $atoms['lists'] ?? 0 );
        if ( $semantic_atoms > 1 || count( (array) ( $node['children'] ?? array() ) ) > 1 ) { return 'preserve-children'; }
        return 'structural-native';
    }

    private function graph( array $ir ) {
        $graph_nodes = array(); $edges = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            $id = (string) ( $node['id'] ?? '' ); if ( '' === $id ) { continue; }
            $graph_nodes[] = array(
                'id' => $id,
                'role' => sanitize_key( (string) ( $node['semantic']['role'] ?? '' ) ),
                'tag' => strtolower( (string) ( $node['source']['tag'] ?? '' ) ),
                'composition_policy' => (string) ( $node['semantic']['composition_policy'] ?? '' ),
                'atoms' => (array) ( $node['fidelity']['atoms'] ?? array() ),
                'source_hash' => (string) ( $node['fidelity']['source_hash'] ?? '' ),
            );
            foreach ( (array) ( $node['children'] ?? array() ) as $child ) { $edges[] = array( 'from' => $id, 'to' => (string) $child ); }
        }
        return array( 'version' => self::VERSION, 'nodes' => $graph_nodes, 'edges' => $edges, 'graph_hash' => hash( 'sha256', wp_json_encode( array( $graph_nodes, $edges ) ) ) );
    }

    private function subtree_atoms( $root_id, array $nodes ) {
        $queue = array( (string) $root_id ); $subset = array(); $seen = array();
        while ( $queue ) {
            $id = array_shift( $queue );
            if ( isset( $seen[ $id ] ) || ! isset( $nodes[ $id ] ) ) { continue; }
            $seen[ $id ] = true; $subset[] = $nodes[ $id ];
            foreach ( (array) ( $nodes[ $id ]['children'] ?? array() ) as $child ) { $queue[] = (string) $child; }
        }
        return $this->atom_counts( $subset );
    }

    private function atom_counts( array $nodes ) {
        $counts = array( 'headings'=>0, 'paragraphs'=>0, 'images'=>0, 'links'=>0, 'forms'=>0, 'fields'=>0, 'lists'=>0, 'buttons'=>0 );
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
            if ( preg_match( '/^h[1-6]$/', $tag ) ) { $counts['headings']++; }
            elseif ( in_array( $tag, array( 'p', 'blockquote' ), true ) ) { $counts['paragraphs']++; }
            elseif ( 'img' === $tag ) { $counts['images']++; }
            elseif ( 'a' === $tag ) { $counts['links']++; }
            elseif ( 'form' === $tag ) { $counts['forms']++; $counts['fields'] += count( (array) ( $node['content']['fields'] ?? array() ) ); }
            elseif ( in_array( $tag, array( 'ul', 'ol' ), true ) ) { $counts['lists']++; }
            elseif ( 'button' === $tag || ( 'input' === $tag && 'submit' === strtolower( (string) ( $node['source']['attributes']['type'] ?? '' ) ) ) ) { $counts['buttons']++; }
        }
        return $counts;
    }

    private function compiled_atoms( array $elements ) {
        $counts = array( 'images'=>0, 'forms'=>0, 'fields'=>0, 'headings'=>0, 'text'=>0, 'buttons'=>0 );
        $walk = function ( array $element ) use ( &$walk, &$counts ) {
            if ( 'widget' === ( $element['elType'] ?? '' ) ) {
                $type = sanitize_key( (string) ( $element['widgetType'] ?? '' ) );
                $settings = (array) ( $element['settings'] ?? array() );
                if ( 'image' === $type ) { $counts['images']++; }
                if ( 'form' === $type ) { $counts['forms']++; $counts['fields'] += count( (array) ( $settings['form_fields'] ?? array() ) ); }
                if ( 'heading' === $type ) { $counts['headings']++; }
                if ( 'text-editor' === $type ) { $counts['text']++; }
                if ( 'button' === $type ) { $counts['buttons']++; }
                if ( 'image' !== $type ) {
                    foreach ( $settings as $value ) {
                        if ( is_array( $value ) && ! empty( $value['url'] ) && preg_match( '#^(?:https?:|/|\.\.?/)#', (string) $value['url'] ) ) { $counts['images']++; break; }
                    }
                }
            }
            foreach ( (array) ( $element['elements'] ?? array() ) as $child ) { if ( is_array( $child ) ) { $walk( $child ); } }
        };
        foreach ( $elements as $element ) { if ( is_array( $element ) ) { $walk( $element ); } }
        return $counts;
    }

    private function form_contract( $root_id, array $nodes ) {
        $queue = array( (string) $root_id ); $descendants = array(); $labels = array(); $free_labels = array(); $submit = ''; $fields = array(); $seen = array();
        while ( $queue ) {
            $id = array_shift( $queue );
            if ( isset( $seen[ $id ] ) || ! isset( $nodes[ $id ] ) ) { continue; }
            $seen[ $id ] = true; $node = $nodes[ $id ]; $descendants[] = $node;
            $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) ); $attrs = (array) ( $node['source']['attributes'] ?? array() );
            if ( 'label' === $tag ) {
                $for = sanitize_key( (string) ( $attrs['for'] ?? '' ) );
                $text = sanitize_text_field( trim( (string) ( $node['content']['text'] ?? '' ) ) );
                if ( '' !== $for ) { $labels[ $for ] = $text; }
                elseif ( '' !== $text ) { $free_labels[] = $text; }
            }
            if ( 'button' === $tag || ( 'input' === $tag && 'submit' === strtolower( (string) ( $attrs['type'] ?? '' ) ) ) ) {
                $candidate = trim( (string) ( $node['content']['text'] ?? ( $attrs['value'] ?? '' ) ) );
                if ( '' !== $candidate && '' === $submit ) { $submit = sanitize_text_field( $candidate ); }
            }
            foreach ( (array) ( $node['children'] ?? array() ) as $child ) { $queue[] = (string) $child; }
        }
        foreach ( $descendants as $node ) {
            $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
            if ( ! in_array( $tag, array( 'input', 'textarea', 'select' ), true ) ) { continue; }
            $attrs = (array) ( $node['source']['attributes'] ?? array() );
            $type = 'textarea' === $tag ? 'textarea' : ( 'select' === $tag ? 'text' : sanitize_key( (string) ( $attrs['type'] ?? 'text' ) ) );
            if ( in_array( $type, array( 'submit','button','hidden','reset','checkbox','radio','file' ), true ) ) { continue; }
            if ( ! in_array( $type, array( 'text','email','textarea','url','tel','number','date','time' ), true ) ) { $type = 'text'; }
            $raw_id = (string) ( $attrs['name'] ?? $attrs['id'] ?? 'field_' . ( count( $fields ) + 1 ) );
            $field_id = sanitize_key( $raw_id ); if ( '' === $field_id ) { $field_id = 'field_' . ( count( $fields ) + 1 ); }
            $html_id = sanitize_key( (string) ( $attrs['id'] ?? '' ) );
            // Anonymous inputs (no name/id, common in hand-written markup) take
            // the nearest unclaimed sibling label in document order instead of
            // a generated field_N placeholder.
            if ( $html_id && isset( $labels[ $html_id ] ) ) { $label = $labels[ $html_id ]; }
            elseif ( ! $html_id && ! isset( $attrs['name'] ) && $free_labels ) { $label = array_shift( $free_labels ); }
            else { $label = ucfirst( str_replace( array( '-', '_' ), ' ', $field_id ) ); }
            $fields[] = array(
                'id' => $field_id,
                'type' => $type,
                'label' => sanitize_text_field( $label ),
                'placeholder' => sanitize_text_field( (string) ( $attrs['placeholder'] ?? '' ) ),
                'required' => array_key_exists( 'required', $attrs ),
                'width' => '100',
            );
        }
        $root = $nodes[ $root_id ]; $attrs = (array) ( $root['source']['attributes'] ?? array() );
        $name = trim( (string) ( $attrs['aria-label'] ?? $attrs['name'] ?? $attrs['id'] ?? 'Imported enquiry' ) );
        return array(
            'fields' => $fields,
            'form_name' => sanitize_text_field( $name ?: 'Imported enquiry' ),
            'button_text' => $submit ?: 'Submit',
            'show_labels' => ! empty( $labels ) || ! empty( $free_labels ),
            'mark_required' => (bool) array_filter( $fields, static function ( $field ) { return ! empty( $field['required'] ); } ),
            'submit_actions' => array(),
            'input_size' => 'md',
        );
    }

    private function browser_index( array $browser_analysis ) {
        $data = (array) ( $browser_analysis['data'] ?? $browser_analysis );
        $viewports = (array) ( $data['viewports'] ?? array() );
        $index = array();
        foreach ( $viewports as $width => $rows ) {
            foreach ( (array) $rows as $row ) {
                if ( ! is_array( $row ) || empty( $row['domPath'] ) ) { continue; }
                $path = (string) $row['domPath'];
                $styles = (array) ( $row['styles'] ?? array() );
                $index[ $path ][ (string) $width ] = array(
                    'rect' => (array) ( $row['rect'] ?? array() ),
                    'styles' => array_intersect_key( $styles, array_flip( array(
                        'display','position','flexDirection','flexWrap','justifyContent','alignItems','gap','gridTemplateColumns','gridTemplateRows',
                        'width','height','minWidth','maxWidth','minHeight','maxHeight','fontFamily','fontSize','fontWeight','lineHeight','letterSpacing',
                        'color','backgroundColor','borderRadius','boxShadow','opacity','overflow','objectFit','objectPosition','textAlign','textTransform'
                    ) ) ),
                );
            }
        }
        return $index;
    }
}
