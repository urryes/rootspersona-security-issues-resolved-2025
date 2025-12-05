<?php
/**
 * Rootspersona SVG Export Admin Interface
 * Compatible with Rootspersona 3.7.6 (forked by urryes)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rootspersona_SVG_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ], 20 );
        add_action( 'admin_post_rp_generate_svg', [ $this, 'handle_download' ] );
        add_action( 'wp_ajax_rp_clear_svg_cache', [ $this, 'ajax_clear_cache' ] );
    }

    /**
     * Add SVG Export submenu under Rootspersona (slug: 'roots-persona')
     */
    public function add_menu_page() {
        // Safety check: ensure Rootspersona main menu exists
        global $submenu;
        if ( ! isset( $submenu['roots-persona'] ) ) {
            // Fallback: add under Settings if main menu missing (unlikely)
            $parent_slug = 'options-general.php';
        } else {
            $parent_slug = 'roots-persona';
        }

        add_submenu_page(
            $parent_slug,
            __( 'SVG Tree Export', 'rootspersona' ),
            __( 'SVG Export', 'rootspersona' ),
            'manage_options',
            'roots-svg-export',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions.', 'rootspersona' ) );
        }

        $batch_id = isset( $_GET['batch_id'] ) ? sanitize_text_field( wp_unslash( $_GET['batch_id'] ) ) : 'default';
        $batches  = $this->get_batch_list();
        $persons  = $this->get_persons_for_batch( $batch_id );

        ?>
        <div class="wrap">
            <h1><?php _e( 'Rootspersona SVG Tree Export', 'rootspersona' ); ?></h1>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'rp_generate_svg' ); ?>
                <input type="hidden" name="action" value="rp_generate_svg">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="batch_id"><?php _e( 'Batch', 'rootspersona' ); ?></label>
                        </th>
                        <td>
                            <select name="batch_id" id="batch_id" onchange="this.form.submit()">
                                <?php foreach ( $batches as $id => $name ): ?>
                                    <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $batch_id ); ?>>
                                        <?php echo esc_html( $name ?: $id ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php _e( 'GEDCOM batches — choose the tree you want to export.', 'rootspersona' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="person_id"><?php _e( 'Root Person', 'rootspersona' ); ?></label>
                        </th>
                        <td>
                            <select name="person_id" id="person_id" required style="width:100%;max-width:400px;">
                                <option value=""><?php _e( '— Select a person —', 'rootspersona' ); ?></option>
                                <?php foreach ( $persons as $p ): ?>
                                    <option value="<?php echo esc_attr( $p['id'] ); ?>">
                                        <?php echo esc_html( $p['name'] ); ?>
                                        <?php if ( $p['birth'] ): ?> (<?php echo esc_html( $p['birth'] ); ?>)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ( empty( $persons ) ): ?>
                                <p class="description" style="color:#d63638;">
                                    <?php
                                    printf(
                                        __( 'No persons found for batch "%s". Please upload a GEDCOM first.', 'rootspersona' ),
                                        esc_html( $batch_id )
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php _e( 'Options', 'rootspersona' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="include_photos" value="1" checked>
                                    <?php _e( 'Include photos (fallback to gendered avatar if missing)', 'rootspersona' ); ?>
                                </label><br>

                                <label>
                                    <input type="checkbox" name="orientation" value="vertical" checked>
                                    <?php _e( 'Vertical tree (ancestor > person > descendant)', 'rootspersona' ); ?>
                                </label><br>

                                <p class="description">
                                    <?php _e( 'Large trees (>300 people) may take time. Results are cached for 24 hours.', 'rootspersona' ); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Generate & Download SVG', 'rootspersona' ), 'primary' ); ?>
            </form>

            <hr>

            <h2><?php _e( 'Cache Management', 'rootspersona' ); ?></h2>
            <p>
                <button type="button" class="button" id="rp-clear-svg-cache">
                    <?php _e( 'Clear SVG Cache', 'rootspersona' ); ?>
                </button>
                <span id="rp-cache-status"></span>
            </p>

            <script>
            jQuery( document ).ready( function( $ ) {
                $( '#rp-clear-svg-cache' ).on( 'click', function() {
                    const btn = $( this ).prop( 'disabled', true );
                    $( '#rp-cache-status' ).text( '<?php echo esc_js( __( 'Clearing...', 'rootspersona' ) ); ?>' );

                    $.post( ajaxurl, {
                        action: 'rp_clear_svg_cache',
                        _ajax_nonce: '<?php echo wp_create_nonce( 'rp_clear_svg_cache' ); ?>'
                    } )
                    .done( function( res ) {
                        let msg = res.success
                            ? '<span style="color:green"><?php echo esc_js( __( '? Cache cleared.', 'rootspersona' ) ); ?></span>'
                            : '<span style="color:red"><?php echo esc_js( __( '? Error clearing cache.', 'rootspersona' ) ); ?></span>';
                        $( '#rp-cache-status' ).html( msg );
                    } )
                    .fail( function() {
                        $( '#rp-cache-status' ).html( '<span style="color:red"><?php echo esc_js( __( '? Network error.', 'rootspersona' ) ); ?></span>' );
                    } )
                    .always( function() {
                        btn.prop( 'disabled', false );
                    } );
                } );
            } );
            </script>
        </div>
        <?php
    }

    /**
     * Get list of available batches (from Rootspersona core)
     */
    private function get_batch_list() {
        if ( method_exists( 'Rootspersona', 'get_batch_list' ) ) {
            return Rootspersona::get_batch_list();
        }

        // Fallback: scan data directory
        $data_dir = WP_CONTENT_DIR . '/uploads/rootspersona';
        $batches = [];
        if ( is_dir( $data_dir ) ) {
            foreach ( glob( $data_dir . '/*', GLOB_ONLYDIR ) as $dir ) {
                $batch = basename( $dir );
                $batches[ $batch ] = $batch;
            }
        }
        if ( empty( $batches ) ) {
            $batches['default'] = 'default';
        }
        return $batches;
    }

    /**
     * Get persons for a batch using Rootspersona’s index.xml (lightweight)
     */
    private function get_persons_for_batch( $batch_id ) {
        $index_file = WP_CONTENT_DIR . "/uploads/rootspersona/{$batch_id}/person_index.xml";
        if ( ! file_exists( $index_file ) ) {
            return [];
        }

        libxml_use_internal_errors( true );
        $xml = simplexml_load_file( $index_file );
        if ( ! $xml ) {
            return [];
        }

        $persons = [];
        foreach ( $xml->person as $p ) {
            $id    = (string) $p['id'];
            $name  = preg_replace( '/\s*\/([^\/]+)\//', ' $1', (string) $p->name );
            $birth = isset( $p->birth ) ? (string) $p->birth : '';
            $persons[] = compact( 'id', 'name', 'birth' );
        }

        usort( $persons, function( $a, $b ) {
            return strcasecmp( $a['name'], $b['name'] );
        } );

        return $persons;
    }

    /**
     * Handle SVG generation & download
     */
    public function handle_download() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'rp_generate_svg' ) ) {
            wp_die( __( 'Access denied.', 'rootspersona' ) );
        }

        $batch_id      = sanitize_text_field( wp_unslash( $_POST['batch_id'] ?? 'default' ) );
        $person_id     = sanitize_text_field( wp_unslash( $_POST['person_id'] ?? '' ) );
        $include_photos = ! empty( $_POST['include_photos'] );
        $orientation    = sanitize_text_field( wp_unslash( $_POST['orientation'] ?? 'vertical' ) );

        if ( ! $person_id ) {
            wp_die( __( 'Please select a person.', 'rootspersona' ) );
        }

        // Lazy-load generator only when needed
        require_once plugin_dir_path( __FILE__ ) . '../includes/class-rootspersona-svg-generator.php';

        $generator = new Rootspersona_SVG_Generator( $batch_id, [
            'include_photos' => $include_photos,
            'orientation'    => $orientation,
            'max_nodes'      => 500,
            'max_depth'      => 10,
        ] );

        $svg = $generator->generate_svg( $person_id );

        if ( empty( $svg ) || strpos( $svg, '<svg' ) === false ) {
            wp_die( __( 'Failed to generate SVG. Check person ID and batch data.', 'rootspersona' ) );
        }

        $filename = 'family-tree-' . sanitize_file_name( $person_id ) . '.svg';

        // Force download
        header( 'Content-Type: image/svg+xml; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate' );
        header( 'Pragma: no-cache' );
        echo $svg;
        exit;
    }

    /**
     * AJAX: clear SVG cache
     */
    public function ajax_clear_cache() {
        check_ajax_referer( 'rp_clear_svg_cache', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'rootspersona' ) ] );
        }

        global $wpdb;
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( '_transient_rp_svg_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_rp_svg_' ) . '%'
        ) );

        wp_send_json_success( [
            'deleted' => (int) $deleted,
            'message' => sprintf( _n( '%d transient deleted.', '%d transients deleted.', $deleted, 'rootspersona' ), $deleted )
        ] );
    }
}

// Init only in admin
if ( is_admin() ) {
    new Rootspersona_SVG_Admin();
}