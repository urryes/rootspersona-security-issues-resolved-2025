<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class Rootspersona_SVG_Generator
 * Generates SVG family tree from a person ID using iterative traversal.
 */
class Rootspersona_SVG_Generator {

    private $batch_id;
    private $options;
    private $persons_cache = [];
    private $families_cache = [];

    public function __construct( $batch_id, $options = [] ) {
        $this->batch_id = $batch_id;
        $this->options = wp_parse_args( $options, [
            'include_photos' => true,
            'orientation'    => 'vertical', // vertical | horizontal
            'max_nodes'      => 500,
            'max_depth'      => 10,
        ] );
    }

    public function generate_svg( $person_id ) {
        $cache_key = 'rp_svg_' . md5( $this->batch_id . '|' . $person_id . '|' . serialize( $this->options ) );
        $svg = get_transient( $cache_key );
        if ( $svg !== false ) {
            return $svg;
        }

        $tree_data = $this->build_tree_iteratively( $person_id );
        $svg = $this->render_svg( $tree_data );

        set_transient( $cache_key, $svg, DAY_IN_SECONDS );
        return $svg;
    }

    private function build_tree_iteratively( $start_id ) {
        $visited = [];
        $nodes = [];
        $edges = [];

        $stack = [ [ 'id' => $start_id, 'level' => 0, 'side' => 'center', 'parent_edge' => null ] ];

        while ( ! empty( $stack ) && count( $nodes ) < $this->options['max_nodes'] ) {
            $item = array_pop( $stack );
            $id = $item['id'];
            $level = $item['level'];
            $side = $item['side'];

            if ( isset( $visited[ $id ] ) || $level > $this->options['max_depth'] ) {
                continue;
            }
            $visited[ $id ] = true;

            $person = $this->get_person_cached( $id );
            if ( ! $person ) {
                continue;
            }

            $nodes[ $id ] = [
                'id'          => $id,
                'name'        => $this->format_name( $person ),
                'birth'       => $this->get_event_date( $person, 'BIRT' ),
                'death'       => $this->get_event_date( $person, 'DEAT' ),
                'sex'         => $person->SEX ?? 'U',
                'permalink'   => get_permalink( $this->get_page_id_by_person_id( $id ) ),
                'photo_url'   => $this->get_photo_url( $id ),
            ];

            // Add spouses
            $families = $this->get_families_for_person_cached( $id );
            foreach ( $families as $fam ) {
                $spouse_id = $this->get_spouse_id( $fam, $id );
                if ( $spouse_id && ! isset( $visited[ $spouse_id ] ) ) {
                    $edges[] = [ 'from' => $id, 'to' => $spouse_id, 'type' => 'spouse' ];
                    $stack[] = [ 'id' => $spouse_id, 'level' => $level, 'side' => $side, 'parent_edge' => null ];
                }
                // Add children
                if ( isset( $fam->CHIL ) ) {
                    foreach ( (array) $fam->CHIL as $child_ref ) {
                        $child_id = $child_ref->__toString();
                        if ( ! isset( $visited[ $child_id ] ) ) {
                            $edges[] = [ 'from' => $id, 'to' => $child_id, 'type' => 'parent-child' ];
                            $stack[] = [ 'id' => $child_id, 'level' => $level + 1, 'side' => 'descendant', 'parent_edge' => $id ];
                        }
                    }
                }
            }

            // Add parents (once, via first family)
            if ( isset( $person->FAMC ) ) {
                $famc_refs = is_array( $person->FAMC ) ? $person->FAMC : [ $person->FAMC ];
                foreach ( $famc_refs as $famc_ref ) {
                    $fam_id = $famc_ref->__toString();
                    $family = $this->get_family_cached( $fam_id );
                    if ( $family ) {
                        $father_id = $family->HUSB ?? null;
                        $mother_id = $family->WIFE ?? null;
                        foreach ( [ $father_id, $mother_id ] as $parent_id ) {
                            if ( $parent_id && ! isset( $visited[ $parent_id ] ) ) {
                                $edges[] = [ 'from' => $parent_id, 'to' => $id, 'type' => 'parent-child' ];
                                $stack[] = [ 'id' => $parent_id, 'level' => $level - 1, 'side' => 'ancestor', 'parent_edge' => $id ];
                            }
                        }
                    }
                }
            }
        }

        return compact( 'nodes', 'edges' );
    }

    private function get_person_cached( $id ) {
        if ( isset( $this->persons_cache[ $id ] ) ) return $this->persons_cache[ $id ];
        $person = Rootspersona::get_person_by_id( $id, $this->batch_id );
        $this->persons_cache[ $id ] = $person;
        return $person;
    }

    private function get_family_cached( $id ) {
        if ( isset( $this->families_cache[ $id ] ) ) return $this->families_cache[ $id ];
        $family = Rootspersona::get_family_by_id( $id, $this->batch_id );
        $this->families_cache[ $id ] = $family;
        return $family;
    }

    private function get_families_for_person_cached( $person_id ) {
        $person = $this->get_person_cached( $person_id );
        if ( ! $person ) return [];

        $fams = [];
        foreach ( [ 'FAMS', 'FAMC' ] as $tag ) {
            if ( isset( $person->$tag ) ) {
                $refs = is_array( $person->$tag ) ? $person->$tag : [ $person->$tag ];
                foreach ( $refs as $ref ) {
                    $fam_id = $ref->__toString();
                    $fam = $this->get_family_cached( $fam_id );
                    if ( $fam ) $fams[ $fam_id ] = $fam;
                }
            }
        }
        return array_values( $fams );
    }

    private function get_spouse_id( $family, $person_id ) {
        $husb = $family->HUSB ?? '';
        $wife = $family->WIFE ?? '';
        if ( $husb && $husb->__toString() !== $person_id ) return $husb->__toString();
        if ( $wife && $wife->__toString() !== $person_id ) return $wife->__toString();
        return null;
    }

    private function get_page_id_by_person_id( $person_id ) {
        global $wpdb;
        $page_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            '_roots_person_id', $person_id
        ) );
        return $page_id ?: 0;
    }

    private function format_name( $person ) {
        $name = trim( (string) ( $person->NAME ?? '' ) );
        return preg_replace( '/\s*\/([^\/]+)\//', ' $1', $name ); // Remove slashes
    }

    private function get_event_date( $person, $tag ) {
        if ( ! isset( $person->$tag ) ) return '';
        $event = $person->$tag;
        if ( ! isset( $event->DATE ) ) return '';
        return trim( (string) $event->DATE );
    }

    private function get_photo_url( $person_id ) {
        if ( ! $this->options['include_photos'] ) return null;

        global $wpdb;
        $attachment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE post_id IN (
                 SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value = %s
             ) AND meta_key = %s
             ORDER BY meta_id ASC LIMIT 1",
            '_roots_person_id', $person_id,
            '_roots_image_0'
        ) );

        if ( $attachment_id ) {
            return wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
        }
        return null;
    }

    private function render_svg( $data ) {
        $nodes = $data['nodes'];
        $edges = $data['edges'];

        if ( empty( $nodes ) ) {
            return '<svg viewBox="0 0 400 100" xmlns="http://www.w3.org/2000/svg"><text x="20" y="50" font-size="16">No data</text></svg>';
        }

        // Layout: vertical tree
        $node_width = 180;
        $node_height = 100;
        $h_spacing = 220;
        $v_spacing = 160;
        $start_x = 200;
        $start_y = 100;

        $levels = [];
        foreach ( $nodes as $id => $node ) {
            $levels[ $id ] = isset( $node['level'] ) ? $node['level'] : 0;
        }

        // Simple level assignment (naive but safe)
        $id_to_level = [];
        $queue = [ key( $nodes ) ]; // center person
        $id_to_level[ key( $nodes ) ] = 0;

        while ( $queue ) {
            $id = array_shift( $queue );
            $level = $id_to_level[ $id ];

            foreach ( $edges as $e ) {
                if ( $e['from'] === $id && ! isset( $id_to_level[ $e['to'] ] ) ) {
                    $id_to_level[ $e['to'] ] = $level + ( $e['type'] === 'parent-child' ? 1 : 0 );
                    $queue[] = $e['to'];
                }
                if ( $e['to'] === $id && ! isset( $id_to_level[ $e['from'] ] ) ) {
                    $id_to_level[ $e['from'] ] = $level - 1;
                    $queue[] = $e['from'];
                }
            }
        }

        // Group by level
        $level_groups = [];
        foreach ( $nodes as $id => $node ) {
            $l = $id_to_level[ $id ] ?? 0;
            $level_groups[ $l ][] = $id;
        }
        ksort( $level_groups );

        $max_width = 0;
        foreach ( $level_groups as $ids ) {
            $max_width = max( $max_width, count( $ids ) );
        }

        $width = $start_x * 2 + $max_width * $h_spacing;
        $height = $start_y * 2 + count( $level_groups ) * $v_spacing;

        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $dom->formatOutput = true;
        $svg = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
        $svg->setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );
        $svg->setAttribute( 'xmlns:xlink', 'http://www.w3.org/1999/xlink' );
        $svg->setAttribute( 'viewBox', "0 0 {$width} {$height}" );
        $svg->setAttribute( 'width', '100%' );
        $svg->setAttribute( 'height', 'auto' );
        $dom->appendChild( $svg );

        // Define symbols
        $defs = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'defs' );
        $this->add_avatar_symbols( $dom, $defs );
        $svg->appendChild( $defs );

        $node_positions = [];

        // Render nodes
        $y = $start_y;
        foreach ( $level_groups as $level => $ids ) {
            $x_offset = ( $width - ( count( $ids ) - 1 ) * $h_spacing ) / 2;
            $x = $x_offset;
            foreach ( $ids as $id ) {
                $node = $nodes[ $id ];
                $pos = [ 'x' => $x, 'y' => $y ];
                $this->render_node( $dom, $svg, $node, $pos );
                $node_positions[ $id ] = $pos;
                $x += $h_spacing;
            }
            $y += $v_spacing;
        }

        // Render edges
        foreach ( $edges as $edge ) {
            if ( ! isset( $node_positions[ $edge['from'] ], $node_positions[ $edge['to'] ] ) ) continue;

            $from = $node_positions[ $edge['from'] ];
            $to = $node_positions[ $edge['to'] ];

            $line = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'line' );
            $line->setAttribute( 'x1', $from['x'] + $node_width / 2 );
            $line->setAttribute( 'y1', $from['y'] + $node_height );
            $line->setAttribute( 'x2', $to['x'] + $node_width / 2 );
            $line->setAttribute( 'y2', $to['y'] );
            $line->setAttribute( 'stroke', '#999' );
            $line->setAttribute( 'stroke-width', '2' );
            $line->setAttribute( 'class', 'rp-tree-edge rp-tree-edge-' . $edge['type'] );
            $svg->appendChild( $line );
        }

        return $dom->saveXML();
    }

    private function add_avatar_symbols( $dom, $defs ) {
        // Male
        $symbol = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'symbol' );
        $symbol->setAttribute( 'id', 'rp-avatar-m' );
        $symbol->setAttribute( 'viewBox', '0 0 100 100' );
        $g = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'g' );
        $g->setAttribute( 'fill', '#e0e0e0' );
        $circle = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'circle' );
        $circle->setAttribute( 'cx', '50' );
        $circle->setAttribute( 'cy', '40' );
        $circle->setAttribute( 'r', '20' );
        $path = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'path' );
        $path->setAttribute( 'd', 'M30,70 L70,70 L60,100 L40,100 Z' );
        $g->appendChild( $circle );
        $g->appendChild( $path );
        $symbol->appendChild( $g );
        $defs->appendChild( $symbol );

        // Female
        $symbol = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'symbol' );
        $symbol->setAttribute( 'id', 'rp-avatar-f' );
        $symbol->setAttribute( 'viewBox', '0 0 100 100' );
        $g = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'g' );
        $g->setAttribute( 'fill', '#e0e0e0' );
        $circle = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'circle' );
        $circle->setAttribute( 'cx', '50' );
        $circle->setAttribute( 'cy', '45' );
        $circle->setAttribute( 'r', '20' );
        $path1 = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'path' );
        $path1->setAttribute( 'd', 'M30,70 Q50,85 70,70 L65,100 L35,100 Z' );
        $g->appendChild( $circle );
        $g->appendChild( $path1 );
        $symbol->appendChild( $g );
        $defs->appendChild( $symbol );

        // Unknown
        $symbol = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'symbol' );
        $symbol->setAttribute( 'id', 'rp-avatar-u' );
        $symbol->setAttribute( 'viewBox', '0 0 100 100' );
        $circle = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'circle' );
        $circle->setAttribute( 'cx', '50' );
        $circle->setAttribute( 'cy', '50' );
        $circle->setAttribute( 'r', '30' );
        $circle->setAttribute( 'fill', '#ddd' );
        $text = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'text' );
        $text->setAttribute( 'x', '50' );
        $text->setAttribute( 'y', '58' );
        $text->setAttribute( 'text-anchor', 'middle' );
        $text->setAttribute( 'font-size', '40' );
        $text->setAttribute( 'fill', '#999' );
        $text->textContent = '?';
        $symbol->appendChild( $circle );
        $symbol->appendChild( $text );
        $defs->appendChild( $symbol );
    }

    private function render_node( $dom, $svg, $node, $pos ) {
        $g = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'g' );
        $g->setAttribute( 'class', 'rp-tree-node' );
        $g->setAttribute( 'data-id', esc_attr( $node['id'] ) );
        $g->setAttribute( 'transform', 'translate(' . $pos['x'] . ',' . $pos['y'] . ')' );

        $width = 180;
        $height = 100;

        // Link
        if ( $node['permalink'] ) {
            $a = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'a' );
            $a->setAttributeNS( 'http://www.w3.org/1999/xlink', 'xlink:href', esc_url( $node['permalink'] ) );
            $a->setAttribute( 'target', '_blank' );
        }

        // Card
        $rect = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'rect' );
        $rect->setAttribute( 'width', $width );
        $rect->setAttribute( 'height', $height );
        $rect->setAttribute( 'rx', '8' );
        $rect->setAttribute( 'ry', '8' );
        $rect->setAttribute( 'fill', '#fff' );
        $rect->setAttribute( 'stroke', '#ccc' );
        $rect->setAttribute( 'stroke-width', '1' );

        // Image or avatar
        $img_y = 10;
        $use_avatar = true;
        if ( $node['photo_url'] ) {
            $image = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'image' );
            $image->setAttribute( 'x', '10' );
            $image->setAttribute( 'y', $img_y );
            $image->setAttribute( 'width', '60' );
            $image->setAttribute( 'height', '60' );
            $image->setAttributeNS( 'http://www.w3.org/1999/xlink', 'xlink:href', esc_url( $node['photo_url'] ) );
            $use_avatar = false;
            $g->appendChild( $image );
        }

        if ( $use_avatar ) {
            $use = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'use' );
            $use->setAttribute( 'x', '20' );
            $use->setAttribute( 'y', $img_y + 5 );
            $use->setAttribute( 'width', '40' );
            $use->setAttribute( 'height', '40' );
            $sex = strtolower( $node['sex'] );
            $use->setAttributeNS( 'http://www.w3.org/1999/xlink', 'xlink:href', '#rp-avatar-' . ( in_array( $sex, [ 'm', 'f' ] ) ? $sex : 'u' ) );
            $g->appendChild( $use );
        }

        // Name
        $name_text = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'text' );
        $name_text->setAttribute( 'x', '80' );
        $name_text->setAttribute( 'y', '30' );
        $name_text->setAttribute( 'font-size', '14' );
        $name_text->setAttribute( 'font-weight', 'bold' );
        $name_text->setAttribute( 'fill', '#333' );
        $name_text->textContent = wp_kses_post( $node['name'] );

        // Dates
        $dates = trim( $node['birth'] . ( $node['death'] ? ' – ' . $node['death'] : '' ) );
        $date_text = $dom->createElementNS( 'http://www.w3.org/2000/svg', 'text' );
        $date_text->setAttribute( 'x', '80' );
        $date_text->setAttribute( 'y', '50' );
        $date_text->setAttribute( 'font-size', '12' );
        $date_text->setAttribute( 'fill', '#666' );
        $date_text->textContent = $dates ?: '—';

        $g->appendChild( $rect );
        $g->appendChild( $name_text );
        $g->appendChild( $date_text );

        if ( $node['permalink'] ) {
            $a->appendChild( $g );
            $svg->appendChild( $a );
        } else {
            $svg->appendChild( $g );
        }
    }
}