<?php
/*
 Plugin Name: RootsPersona
 Plugin URI: https://rootspersona.com
 Description: Build one or more family history pages from a Gedcom file.
 Version: 3.7.6
 Author: Ed Thompson
 Author URI: http://rootspersona.com/
 Text Domain: rootspersona
 Domain Path: /rootspersona/localization
 License: GPL2
 */

/*  Copyright 2010-2022  Ed Thompson  ( email : ed@rootspersona.com )

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copt of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-includes/pluggable.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/dao/dao/class-RP-DAO-Factory.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/class-RP-Persona-Helper.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/class-RP-Persona-Installer.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/class-RP-Persona-Site-Mender.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/pages/class-RP-Option-Page-Builder.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/pages/class-RP-Tools-Page-Builder.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/pages/class-RP-Edit-Page-Builder.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/pages/class-RP-Add-Page-Builder.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/pages/class-RP-Include-Page-Builder.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/pages/class-RP-Upload-Page-Builder.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/pages/class-RP-Index-Page-Builder.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/pages/class-RP-Persona-Page-Builder.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/pages/class-RP-Evidence-Page-Builder.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/class-RP-Gedcom-Loader.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/class-RP-Persona-Factory.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/class-RP-Index-Factory.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/class-RP-Evidence-Factory.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/class-RP-Persona-Validator.php';
require_once WP_PLUGIN_DIR . '/rootspersona/php/class-RP-Persona-Manager.php';
require_once WP_PLUGIN_DIR . '/rootspersona/surname_widget.php';

function Roots_Persona_init() { // Added by Horacio for plugin translation (Begin)
 $plugin_dir = basename(dirname(__FILE__));
 load_plugin_textdomain( 'rootspersona', false, $plugin_dir  . '/localization' );
}
add_action('plugins_loaded', 'Roots_Persona_init'); // Added by Horacio for plugin translation (End)
/**
 * First, make sure class exists
 */
if ( ! class_exists( 'Roots_Persona' ) ) {
    class Roots_Persona {
        /**
         *
         * @var string
         */
        var $persona_version = '3.7.6';

        /**
         *
         * @global wpdb $wpdb
         */
        function __construct() {
            global $wpdb;
            $credentials = new RP_Credentials();
            $credentials->set_prefix( $wpdb->prefix );
            RP_Query_Executor::get_connection($credentials);
        }

        /**
         *
         * @param array $atts
         * @param string $content
         * @param string $callback
         * @return string
         */
        function persona_handler( $atts, $content = null, $callback = null ) {
            //$time_start = microtime( true );
            global $post;
            try {
                $block = '';
                $persona_id = $atts['personid'];
                if( $persona_id == '0' ) unset( $persona_id );

                if ( isset( $persona_id ) ) {
                    $batch_id = isset( $atts['batchid'] ) ? $atts['batchid'] : '1';
                    $options = get_option( 'persona_plugin' );
                    if ( (empty( $callback ) || strtolower($callback) == 'rootspersona')
                            && isset($options['custom_page']) && !empty($options['custom_page'])) {
                        
                        $picfile1 = isset( $atts['picfile1'] ) ? $atts['picfile1'] : plugins_url('images/boy-silhouette.gif', __FILE__ );
                        $pageContent = html_entity_decode($options['custom_page'], ENT_QUOTES);
                        $pageContent = str_replace("{%personid%}" , $persona_id, $pageContent);
                        $pageContent = str_replace("{%batchid%}" , $batch_id, $pageContent);
			$pageContent = str_replace("{%picfile1%}" , $picfile1, $pageContent);
                        $block = do_shortcode($pageContent);
                    } else {
                        $builder = new RP_Persona_Page_Builder();
                        $options = $builder->get_persona_options( $atts, $callback, $options );
                        $factory = new RP_Persona_Factory();
                        $persona = $factory->get_with_options( $persona_id, $batch_id, $options );
                        //if($persona->full_name == 'Private')
                        //    $post->post_title = 'Private';
                        $block = $builder->build( $persona, $options, RP_Persona_Helper::get_page_id() );
                    }
                } else {
                    $msg = __('Invalid person id.', 'rootspersona');
                    $block = RP_Persona_Helper::return_default_empty( $msg, WP_PLUGIN_URL );
                }

                //$time = microtime( true ) - $time_start;
                //echo "\nDone in $time seconds using "
                //    . memory_get_peak_usage( true ) / 1024 / 1024 . 'MB.';
                return $block;
            } catch ( Exception $e ) {
                error_log($e->getMessage() . "::" . RP_Persona_Helper::trace_caller(),0);
                return '<span style="color:red;margin-top:20px;display:inline-block;">' . $e->getMessage() . "::" . RP_Persona_Helper::trace_caller() . '</span>';
            }
        }

        /**
         *
         * @return string
         */
        function index_page_handler( $atts, $content = null, $callback = null ) {
            try {
                //$time_start = microtime( true );
                $batch_id = isset( $atts['batchid'] )? $atts['batchid'] : '1';
                $builder = new RP_Index_Page_Builder();
                $options = get_option( 'persona_plugin' );
                $options = $builder->get_options( $options, $atts );

                $factory = new RP_Index_Factory();
                $index = $factory->get_with_options( $batch_id, $options );
                $cnt = null;
                if( 'paginated' == $options['style']) {
                    $cnt = $factory->get_cnt( $batch_id, $options );
                }

                $block = "<div id = 'personaIndex'>"
                . $builder->build( $index, $cnt, $options )
                . '</div>';

                //$time = microtime( true ) - $time_start;
                //echo "\nDone in $time seconds using "
                //    . memory_get_peak_usage( true ) / 1024 / 1024 . 'MB.';
                return $block;
            } catch ( Exception $e ) {
                error_log($e->getMessage() . "::" . RP_Persona_Helper::trace_caller(),0);
                return '<span style="color:red;margin-top:20px;display:inline-block;">' . $e->getMessage() . "::" . RP_Persona_Helper::trace_caller() . '</span>';
            }
        }

        /**
         *
         * @param array $atts
         * @param string $content
         * @param string $callback
         * @return string
         */
        function evidence_page_handler( $atts, $content = null, $callback = null ) {
            try {
                $block = null;
                //$time_start = microtime( true );
                $batch_id = isset( $atts['batchid'] )?$atts['batchid']:'1';
                $builder = new RP_Evidence_Page_Builder();
                $options = get_option( 'persona_plugin' );
                $options = $builder->get_options( $options, $atts );

                $block = '';
                $factory = new RP_Evidence_Factory();

                if ( isset( $atts['sourceid'] ) ) {
                    $evidence = $factory->get_with_options( $atts['sourceid'], $batch_id, $options );

                    $block .= "<div id = 'rp_evidence'>"
                        . $builder->build( $evidence, $options )
                        . '</div>';
                } else {
                    $cnt = null;
                    if( 'paginated' == $options['style']) {
                        $cnt = $factory->get_cnt( $batch_id, $options );
                    }
                    $sources = $factory->get_index_with_options( $batch_id, $options );

                    $block .= "<div id = 'personaIndex'>"
                        . $builder->build_index( $sources, $cnt, $options )
                        . '</div>';
                }

                //$time = microtime( true ) - $time_start;
                //echo "\nDone in $time seconds using "
                //    . memory_get_peak_usage( true ) / 1024 / 1024 . 'MB.';
                return $block;
            } catch ( Exception $e ) {
                error_log($e->getMessage() . "::" . RP_Persona_Helper::trace_caller(),0);
                return '<span style="color:red;margin-top:20px;display:inline-block;">' . $e->getMessage() . "::" . RP_Persona_Helper::trace_caller() . '</span>';
            }
        }

        /**
         *
         * @global wpdb $wpdb
         * @return string
         */
        function edit_persona_page_handler( ) {
            try {
                // Security: Check if user has appropriate permissions
                if ( !current_user_can( 'manage_rootspersona2' ) ) {
                    wp_die( __( 'You do not have permission to perform this action.', 'rootspersona' ) );
                }
                
                $action = admin_url('/tools.php?page=rootsPersona&rootspage=edit');
                $batch_id = isset( $atts['batchid'] )?$atts['batchid']:'1';
                $options = get_option( 'persona_plugin' );
                $isSOR = ($options['is_system_of_record'] == '1'?true:false);

                if ( !isset( $_POST['submitPersonForm'] ) ) {
                    $persona_id  = isset( $_GET['personId'] )
                            ? trim( esc_attr( $_GET['personId'] ) )  : '';
                    $batch_id = isset( $_GET['batchId'] )?$_GET['batchId']:'1';
                    if ( ! empty( $persona_id ) ) {
                        $edit_action = isset( $_GET['action'] )
                                ? trim( esc_attr( $_GET['action'] ) )  : '';
                        $src_page = isset( $_GET['srcPage'] )
                                ? trim( esc_attr( $_GET['srcPage'] ) )  : '';
                        if ( $edit_action == 'edit' ) {
                            $options['src_page'] = $src_page;
                            $builder = new RP_Edit_Page_Builder();
                            $options = $builder->get_persona_options( $options );

                            $factory = new RP_Persona_Factory();
                            if($isSOR == false ) {
                                $persona = $factory->get_for_edit( $persona_id, $batch_id, $options );
                            } else {
                                $persona = $factory->get_with_options( $persona_id, $batch_id, $options );
                            }
                            return $builder->build( $persona, $action, $options );
                        } elseif ( $edit_action == 'delete' ) {
                            // Security: Verify nonce for delete action
                            if ( !isset( $_GET['_wpnonce'] ) || !wp_verify_nonce( $_GET['_wpnonce'], 'delete_persona_nonce' ) ) {
                                wp_die( __( 'Security check failed. Please refresh and try again.', 'rootspersona' ) );
                            }
                            wp_delete_post( $src_page );
                            return RP_Persona_Helper::redirect_to_page( $src_page );
                        }
                    } else {
                        if($isSOR === true) {
                            $builder = new RP_Edit_Page_Builder();
                            $options = $builder->get_persona_options( $options );
                            $persona = new RP_Persona();
                            $famc = isset( $_GET['famc'] )
                                    ? trim( esc_attr( $_GET['famc'] ) )  : '';
                            $fams = isset( $_GET['fams'] )
                                    ? trim( esc_attr( $_GET['fams'] ) )  : '';
                            $child = isset( $_GET['child'] )
                                    ? trim( esc_attr( $_GET['child'] ) )  : '';
                            $sseq = isset( $_GET['sseq'] )
                                    ? trim( esc_attr( $_GET['sseq'] ) )  : '';
                            $spouse = isset( $_GET['spouse'] )
                                    ? trim( esc_attr( $_GET['spouse'] ) )  : '';
                            if($famc != '') {
                                $persona->famc = $famc;
                            } else if($fams != '') {
                                $persona->fams = $fams;
                                $persona->spouse = $spouse;
                                $persona->sseq = $sseq=='1'?'2':'1';
                            } else if ($child != '') {
                                //add person as parent of child, no family record exists
                                $persona->fams = -1;
                                $persona->child = $child;
                                $persona->sseq = $sseq=='1'?'2':'1';
                            }
                            return $builder->build( $persona, $action, $options );
                        }
                        return  __( 'Missing', 'rootspersona' ) . ' personId:' . $persona_id;
                    }
                }
            } catch ( Exception $e ) {
                error_log($e->getMessage() . "::" . RP_Persona_Helper::trace_caller(),0);
                return '<span style="color:red;margin-top:20px;display:inline-block;">' . $e->getMessage() . "::" . RP_Persona_Helper::trace_caller() . '</span>';
            }
            return "";
        }

        function rp_action_callback() {
            global $wpdb; // this is how you get access to the database
            try {
                // Security: Check if user has appropriate permissions
                if ( !current_user_can( 'manage_rootspersona2' ) ) {
                    $response = array();
                    $response['error'] = __( 'You do not have permission to perform this action.', 'rootspersona' );
                    echo json_encode( $response );
                    die();
                }
                
                // Security: Verify nonce to prevent CSRF attacks
                if ( isset( $_POST['form_action'] ) ) {
                    if ( !isset( $_POST['_wpnonce'] ) || !wp_verify_nonce( $_POST['_wpnonce'], 'rp_action_nonce' ) ) {
                        $response = array();
                        $response['error'] = __( 'Security check failed. Please refresh and try again.', 'rootspersona' );
                        echo json_encode( $response );
                        die();
                    }
                }
                
                $options = get_option( 'persona_plugin' );
                $options['home_url'] = home_url();
                $options['admin_url'] = admin_url();
                if ( isset( $_GET['refresh'] ) ) {
                    $batch_id = isset( $_GET['batch_id'] )? trim( esc_attr( $_GET['batch_id'] ) ):'1';
                    $persons = RP_Dao_Factory::get_rp_persona_dao()
                            ->get_persons_no_page( $batch_id );
                    echo json_encode($persons);
                } else if (isset( $_POST['form_action'] ) ) {
                    $mgr = new Persona_Manager();
                    if ( $_POST['form_action'] == 'getFamilies' ) {
                        $response =  $mgr->process_getfamilies( $this->credentials, $_POST['datastr']['term'], $options );
                    } else if ( $_POST['form_action'] == 'getSpouses' ) {
                        $response =  $mgr->process_getspouses( $this->credentials, $_POST['datastr']['term'], $options );
                    }/* else if ( $_POST['form_action'] == 'getFamily' ) {
                        $response =  $mgr->process_getfamily( $this->credentials, $_POST['datastr'], $options );
                        $response = $response->to_array();
                    }*/ else {
                        $data = urldecode( $_POST['datastr'] );
                        $parms = explode( '&', $data );
                        $form = array();
                        foreach ( $parms AS $p ) {
                            $pair = explode( '=', $p );
                            $form[$pair[0]] = isset($pair[1])?$pair[1]:'';
                        }
                        if($_POST['form_action'] == 'updatePersona') {
                            $response =  $mgr->process_form($form, $options );
                        }
                    }

                    if( isset( $response ) ) {
                        $t = json_encode( $response );
                        echo $t;
                    }
                }

            } catch (Exception $e) {
                $msg = $e->getMessage() . "::" . RP_Persona_Helper::trace_caller();
                error_log($msg,0);
                $response = array();
                $response['error'] = $msg;
                $t = json_encode( $response );
                echo $t;
            }

            die(); // this is required to return a proper result
        }

        /**
         *
         * @global wpdb $wpdb
         * @return string
         */
        function add_page_handler(  ) {
            try {
                // Security: Check if user has appropriate permissions
                if ( !current_user_can( 'manage_rootspersona2' ) ) {
                    wp_die( __( 'You do not have permission to perform this action.', 'rootspersona' ) );
                }
                
                $action = admin_url('/tools.php?page=rootsPersona&rootspage=create');
                $msg = '';
                $options = get_option( 'persona_plugin' );
                if ( isset( $_POST['submitAddPageForm'] ) ) {
                    // Security: Verify nonce for form submission
                    if ( !isset( $_POST['_wpnonce'] ) || !wp_verify_nonce( $_POST['_wpnonce'], 'rp_add_page_nonce' ) ) {
                        wp_die( __( 'Security check failed. Please refresh and try again.', 'rootspersona' ) );
                    }
                    
                    $persons  = $_POST['persons'];
                    $batch_id = isset( $_POST['batch_id'] )? trim( esc_attr( $_POST['batch_id'] ) ):'1';
                    if ( !isset( $persons ) || count( $persons ) == 0 ) {
                        $msg = __( 'No people selected.', 'rootspersona' );
                    } else {
                        foreach ( $persons as $p ) {
							list($p, $name) = explode("|", $p, 2); // Modified by Horacio (Begin)
                            //$name = RP_Dao_Factory::get_rp_persona_dao()
                            //        ->get_fullname( $p, $batch_id ); // Modified by Horacio (End)
                            $pageId = RP_Persona_Helper::add_page( $p, $name, $options, $batch_id, null, null );
                            if ( $pageId != false ) {
                                RP_Dao_Factory::get_rp_indi_dao()
                                        ->update_page( $p, $batch_id, $pageId );
                                $msg = $msg . '<br/>'
                                        . sprintf( __( 'Page %s created for', 'rootspersona' ),
                                                $pageId )
                                        . ' ' . $p . ': ' . $name; // Modified by Horacio
                            }
                            else {
                                $msg .= '<br/>'
                                        . __( 'Error creating page for', 'rootspersona' )
                                        . ' ' . $p;
                            }
                            set_time_limit( 60 );
                        }
                    }
                }

                $batch_ids = RP_Dao_Factory::get_rp_persona_dao()
                            ->get_batch_ids( );

                if( isset( $_GET['batch_id'] ) ) {
                    $batch_ids[0] = $_GET['batch_id'];
                } else if(count($batch_ids) == 0) {
                    $batch_ids[0] = 1;
                }

                $builder = new RP_Add_Page_Builder();
                $persons = RP_Dao_Factory::get_rp_persona_dao()
                        ->get_persons_no_page( $batch_ids[0] );
                $retStr = $builder->build( $action, $persons, $msg, $options, $batch_ids );

                return $retStr;
            } catch ( Exception $e ) {
                error_log($e->getMessage() . "::" . RP_Persona_Helper::trace_caller(),0);
                return '<span style="color:red;margin-top:20px;display:inline-block;">' . $e->getMessage() . "::" . RP_Persona_Helper::trace_caller() . '</span>';
            }
        }

        /**
         *
         * @global wpdb $wpdb
         * @return string
         */
        function include_page_handler(  ) {
            try {
                // Security: Check if user has appropriate permissions
                if ( !current_user_can( 'manage_rootspersona2' ) ) {
                    wp_die( __( 'You do not have permission to perform this action.', 'rootspersona' ) );
                }
                
                $action = admin_url('/tools.php?page=rootsPersona&rootspage=include');
                $msg = '';
                $options = get_option( 'persona_plugin' );
                $batch_id = isset( $_POST['batch_id'] )? trim( esc_attr( $_POST['batch_id'] ) ):'1';
                if ( isset( $_POST['submitIncludePageForm'] ) )
                {
                    // Security: Verify nonce for form submission
                    if ( !isset( $_POST['_wpnonce'] ) || !wp_verify_nonce( $_POST['_wpnonce'], 'rp_include_page_nonce' ) ) {
                        wp_die( __( 'Security check failed. Please refresh and try again.', 'rootspersona' ) );
                    }
                    
                    $persons  = $_POST['persons'];

                    if ( !isset( $persons ) || count( $persons ) == 0 ) {
                        $msg = __( 'No people selected.', 'rootspersona' );
                    } else {

                        foreach ( $persons as $id ) {
                            RP_Dao_Factory::get_rp_persona_dao()
                                ->update_persona_privacy( $id, $batch_id, '', '' );
                        }
                    }
                }


                $persons = RP_Dao_Factory::get_rp_persona_dao()
                                ->get_excluded( $batch_id );
                $builder = new RP_Include_Page_Builder();
                return $builder->build( $persons, $options, $action, $msg );
            } catch ( Exception $e ) {
                error_log($e->getMessage() . "::" . RP_Persona_Helper::trace_caller(),0);
                return '<span style="color:red;margin-top:20px;display:inline-block;">' . $e->getMessage() . "::" . RP_Persona_Helper::trace_caller() . '</span>';
            }
        }

        /**
         *
         * @return string
         */
        function utility_page_handler(  ) {
            try {
                if ( isset( $_GET['utilityAction'] ) ) {
                    $options = get_option( 'persona_plugin' );
                    $action  = $_GET['utilityAction'];
                    if( isset( $_GET['batch_id'] ) ) {
                        $batch_id = $_GET['batch_id'];
                    } else {
                        $batch_id = 1;
                    }

                    $mender = new RP_Persona_Site_Mender();
                    if ( $action == 'validatePages' ) {
                        return $mender->validate_pages( $options, false, $batch_id  );
                    }else if ( $action == 'repairPages' ) {
                        return $mender->validate_pages( $options, true, $batch_id  );
                    }/* else if ( $action == 'validateEvidencePages' ) {
                        return $mender->validate_evidence_pages( $options, false, $batch_id  );
                    } else if ( $action == 'repairEvidencePages' ) {
                        return $mender->validate_evidence_pages( $options, true, $batch_id  );
                    }*/ else if ( $action == 'delete' ) {
                        echo $mender->delete_pages( $options, $batch_id  );
                        return "";
                    } else if ( $action == 'deldata' ) {
                        echo $mender->delete_data( $options, $batch_id  );
                        return "";
                    } else if ( $action == 'evidence' ) {
                        return $mender->add_evidence_pages( $options, $batch_id );
                    } else {
                        return $action . ' ' . __('action not supported', 'rootspersona' ) . '.<br/>';
                    }
                }
                return __('For internal use only', 'rootspersona' ) . '.<br/>';
            } catch ( Exception $e ) {
                error_log($e->getMessage() . "::" . RP_Persona_Helper::trace_caller(),0);
                return '<span style="color:red;margin-top:20px;display:inline-block;">' . $e->getMessage() . "::" . RP_Persona_Helper::trace_caller() . '</span>';
            }
        }

        /**
         *
         * @return string
         */
        function upload_gedcom_handler( ) {
            try {
                if ( !current_user_can( 'upload_files' ) ) {
                    wp_die( _( 'You do not have permission to upload files.', 'rootspersona' ) );
                }
                $action = admin_url('/tools.php?page=rootsPersona&rootspage=upload');
                $msg = '';
                $retStr = '';
                $batch_id='1';
                if ( isset( $_POST['submitUploadGedcomForm'] ) )
                {
                    // Security: Verify nonce for form submission
                    if ( !isset( $_POST['_wpnonce'] ) || !wp_verify_nonce( $_POST['_wpnonce'], 'rp_upload_gedcom_nonce' ) ) {
                        wp_die( __( 'Security check failed. Please refresh and try again.', 'rootspersona' ) );
                    }
                    
                if ($_FILES['gedcomFile']['error'] != UPLOAD_ERR_OK ) {
                        $msg = __( 'Error Uploading File.', 'rootspersona' );
                } else  if ( !is_uploaded_file( $_FILES['gedcomFile']['tmp_name'] ) ) {
                        $msg = __( 'Empty File.', 'rootspersona' );
                } else {
                        set_time_limit( 60 );
                        if ( WP_DEBUG === true ){
                            $time_start = microtime( true );
                        }
                        $batch_id = isset( $_POST['batch_id'] )? trim( esc_attr( $_POST['batch_id'] ) ):'1';
                        $fileName = $_FILES['gedcomFile']['tmp_name'];
                        $loader = new RP_Gedcom_Loader();
                        set_time_limit( 60 );
                        $loader->load_tables( $fileName, $batch_id );
                        unlink( $_FILES['gedcomFile']['tmp_name'] );

                        if ( WP_DEBUG === true ){
                            $time = microtime( true ) - $time_start;
                            error_log( "Done in $time seconds using "
                                    . memory_get_peak_usage( true ) / 1024 / 1024 . 'MB.' );
                        }
                    }
                }
                $options = get_option( 'persona_plugin' );
                if ( empty( $msg ) && isset( $_POST['submitUploadGedcomForm'] ) ) {
                    // The wp_redirect command uses a PHP redirect at its core,
                    // therefore, it will not work either after header information
                    // has been defined for a page.
                    $location = admin_url('/tools.php?page=rootsPersona&rootspage=create&batch_id=' . $batch_id);
                    $retStr = '<script type = "text/javascript">window.location = "'
                            . $location . '"; </script>';
                } else {
                    $batch_ids = RP_Dao_Factory::get_rp_persona_dao()
                                ->get_batch_ids( );

                    $builder = new RP_Upload_Page_Builder();
                    $retStr = $builder->build( $action,$msg,$options, $batch_ids );
                }
                return $retStr;
            } catch ( Exception $e ) {
                error_log($e->getMessage() . "::" . RP_Persona_Helper::trace_caller(),0);
                return '<span style="color:red;margin-top:20px;display:inline-block;">' . $e->getMessage() . "::" . RP_Persona_Helper::trace_caller() . '</span>';
            }
        }

        /**
         *
         * @param string $content
         * @return string
         */
        function check_permissions( $content = '' ) {
            $perms = get_post_meta( RP_Persona_Helper::get_page_id(), 'permissions', true );
            if ( !empty( $perms ) && $perms == 'true' && !is_user_logged_in() ) {
                $content = '<br/>'
                    . __( 'Content reserved for registered members.', 'rootspersona' )
                    .'<br/>'
                    . "<br/><div class='personBanner'><br/></div>";
            }
            return $content;
        }

        /**
         *
         */
        function insert_persona_styles() {
            wp_enqueue_style('thickbox');
            wp_register_style( 'rootsPersona-1',
                    plugins_url( 'css/familyGroup.css',__FILE__ ), false, '1.0', 'screen' );
            wp_enqueue_style( 'rootsPersona-1' );
            wp_register_style( 'rootsPersona-2',
                    plugins_url( 'css/ancestors.css',__FILE__ ), false, '1.0', 'screen' );
            wp_enqueue_style( 'rootsPersona-2' );
            wp_register_style( 'rootsPersona-3',
                    plugins_url( 'css/person.css',__FILE__ ), false, '1.0', 'screen' );
            wp_enqueue_style( 'rootsPersona-3' );
            wp_register_style( 'rootsPersona-4',
                    plugins_url( 'css/indexTable.css',__FILE__ ), false, '1.0', 'screen' );
            wp_enqueue_style( 'rootsPersona-4' );
            wp_register_style('jquery.ui.autocomplete', plugins_url('css/jquery.ui.autocomplete.css',__FILE__), false);
			wp_enqueue_style( 'jquery.ui.autocomplete');

        }

        function inject_custom_style () {
            if ( !is_admin() ) {
                $options = get_option( 'persona_plugin' );
                if ( isset($options['custom_style']) && !empty($options['custom_style'])) {
                    echo '<style type="text/css">' . $options['custom_style'] . '</style>';
                }
            }
        }

        /**
         *
         */
        function insert_persona_scripts() {
            $this->insert_admin_scripts();
        }

        /**
         *
         */
        function insert_admin_scripts() {
            wp_enqueue_script('media-upload');
            wp_enqueue_script('thickbox');
            wp_enqueue_script('jquery');
            wp_enqueue_script('jquery-ui-core');
            wp_enqueue_script('jquery-ui-widget');
            wp_enqueue_script('jquery-ui-mouse');
            wp_enqueue_script('jquery-ui-accordian');
            wp_enqueue_script('jquery-ui-button');
            wp_enqueue_script('jquery-ui-autocomplete');
            wp_enqueue_script('jquery-ui-position');
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_script('jquery-ui-dialog');
            wp_enqueue_script('jquery-ui-draggable');
            wp_enqueue_script('jquery-ui-droppable');
            wp_enqueue_script('jquery-effects-core');
            wp_enqueue_script('jquery-effects-blind'); 
            wp_enqueue_script('jquery-effects-bounce'); 
            wp_enqueue_script('jquery-effects-clip'); 
            wp_enqueue_script('jquery-effects-drop'); 
            wp_enqueue_script('jquery-effects-explode'); 
            wp_enqueue_script('jquery-effects-fade'); 
            wp_enqueue_script('jquery-effects-fold'); 
            wp_enqueue_script('jquery-effects-highlight'); 
            wp_enqueue_script('jquery-effects-pulsate'); 
            wp_enqueue_script('jquery-effects-scale'); 
            wp_enqueue_script('jquery-effects-shake'); 
            wp_enqueue_script('jquery-effects-slide'); 
            wp_enqueue_script('jquery-effects-transfer'); 
            wp_enqueue_script('jquery-ui-menu'); 
            wp_enqueue_script('jquery-ui-progressbar'); 
            wp_enqueue_script('jquery-ui-resizable'); 
            wp_enqueue_script('jquery-ui-selectable'); 
            wp_enqueue_script('jquery-ui-slider'); 
            wp_enqueue_script('jquery-ui-sortable'); 
            wp_enqueue_script('jquery-ui-spinner'); 
            wp_enqueue_script('jquery-ui-tabs'); 
            wp_enqueue_script('jquery.ui.tooltip.js');

            wp_register_script( 'jquery.validate',
                                plugins_url( 'js/jquery.validate.min.js', __FILE__ ),
                                array('jquery') );
            wp_enqueue_script( 'jquery.validate' );

            wp_register_script( 'jquery.maskedinput',
                plugins_url( 'js/jquery.maskedinput.min.js', __FILE__ ),
                array('jquery') );
            wp_enqueue_script( 'jquery.maskedinput' );
/*
            wp_register_script( 'jquery.tablesorter',
                plugins_url( 'js/jquery.tablesorter.js', __FILE__ ),
                array('jquery') );
            wp_enqueue_script( 'jquery.tablesorter' );

            wp_register_script( 'jquery.tablesorter.widgets',
                plugins_url( 'js/jquery.tablesorter.widgets.js', __FILE__ ),
                array('jquery') );
            wp_enqueue_script( 'jquery.tablesorter.widgets' );
*/
            wp_register_script( 'rootsUtilities',
                                plugins_url( 'js/rootsUtilities.js',__FILE__ ));
            wp_enqueue_script( 'rootsUtilities' );
        }

        /**
         * @throws Exception
         *
         */
        function persona_activate() {
            global $wpdb;
            try {
                $options = get_option( 'persona_plugin' );
                $installer = new RP_Persona_Installer();

                if (function_exists('is_multisite') && is_multisite()) {
                    // check if it is a network activation - if so, run the activation function for each blog id
                    if (isset($_GET['networkwide']) && ($_GET['networkwide'] == 1)) {
                        $old_blog = $wpdb->blogid;
                        // Get all blog ids
                        $blogids = $wpdb->get_col($wpdb->prepare("SELECT `blog_id` FROM `%s`", $wpdb->blogs));
                        foreach ($blogids as $blog_id) {
                            switch_to_blog($blog_id);
                            $installer->persona_install(
                                WP_PLUGIN_DIR . '/rootspersona/',
                                $this->persona_version,
                                $options, $wpdb->prefix
                                );
                        }
                        switch_to_blog($old_blog);
                        return;
                    }
                }
                $installer->persona_install(
                        WP_PLUGIN_DIR . '/rootspersona/',
                        $this->persona_version,
                        $options, $wpdb->prefix
                    );
            } catch ( Exception $e ) {
                error_log($e->getMessage() . "::" . RP_Persona_Helper::trace_caller(),0);
                trigger_error($e->getMessage(), E_USER_ERROR);
                throw new Exception($e);
            }
            $sql = "SELECT COUNT(1) FROM information_schema.tables WHERE table_schema='"
                            . DB_NAME
                            . "' AND table_name='" 
                            . $wpdb->prefix . "rp_fam'";
            $sql_query = new RP_Sql_Query($sql);
            $tab = RP_Query_Executor::execute( $sql_query);
            if($tab[0][0] !== "1") {
                error_log("RootsPersona tables not created.",0);
                trigger_error("RootsPersona tables not created.", E_USER_ERROR);
                throw new Exception("RootsPersona tables not created.");
            }
        }

        function persona_upgrade() {
            global $wpdb;
            try {
                $options = get_option( 'persona_plugin' );
                if ( ! isset( $options ) || ! isset ( $options['version'] ) ) {
                    $options = array();
                    $options['version'] = get_option( 'persona_version' );
                }
                if ( $this->persona_version != $options['version'] ) {
                    $installer = new RP_Persona_Installer();
                    $installer->persona_upgrade(
                            WP_PLUGIN_DIR . '/rootspersona/',
                            $this->persona_version,
                            $options,
                            $wpdb->prefix
                            );
                }
                return '';

            } catch ( Exception $e ) {
                error_log($e->getMessage() . "::" . RP_Persona_Helper::trace_caller(),0);
                return '<span style="color:red;margin-top:20px;display:inline-block;">' . $e->getMessage() . "::" . RP_Persona_Helper::trace_caller() . '</span>';
            }
        }

        /**
         *
         */
        function persona_deactivate() {
            //$installer = new PersonaInstaller();
            //$installer->rootsPersonaUninstall();
        }

        /**
         *
         * @return string
         */
        function _toString() {
            return __CLASS__;
        }

        /**
         *
         */
        function persona_menus() {
            $hook = add_options_page(
                        'RootsPersona Options',
                        'RootsPersona',
                        'manage_rootspersona1',
                        'rootsPersona',
                        array( $this, 'build_roots_options_page' )
                        );
            add_action( 'admin_print_styles-' . $hook, array( $this, 'insert_persona_styles' ) );
            add_action( 'admin_print_scripts-' . $hook, array( $this, 'insert_admin_scripts' ) );

            $hook = add_submenu_page(
                                'tools.php',
                                'RootsPersona Tools',
                                'RootsPersona',
                                'manage_rootspersona2',
                                'rootsPersona',
                                array( $this, 'build_roots_tools_page' )
                                );
            add_action( 'admin_print_styles-' . $hook, array( $this, 'insert_persona_styles' ) );
            add_action( 'admin_print_scripts-' . $hook, array( $this, 'insert_admin_scripts' ) );
            
            $role = get_role( 'administrator' );

            if($role != null) {
                $role->add_cap('manage_rootspersona1');
                $role->add_cap('manage_rootspersona2');
            }

            $options = get_option( 'persona_plugin' );
            $role = get_role( 'editor' );
            if($role != null) {
                if (isset($options['is_editor']) && $options['is_editor'] == '1') {
                    $role->add_cap('manage_rootspersona2');
                } else {
                    $role->remove_cap('manage_rootspersona2');
                }
            }
        }

        /**
         * @return string
         */
        function build_roots_options_page() {
            try {
                $options = get_option( 'persona_plugin' );
                $options['home_url'] = home_url();
                $builder = new RP_Option_Page_Builder();

                $builder->build( $options );
                return '';
            } catch ( Exception $e ) {
                error_log($e->getMessage() . "::" . RP_Persona_Helper::trace_caller(),0);
                return '<span style="color:red;margin-top:20px;display:inline-block;">' . $e->getMessage() . "::" . RP_Persona_Helper::trace_caller() . '</span>';
            }
        }

        /**
         *
         * @return string
         */
        function build_roots_tools_page() {
            try {
                if( isset( $_GET['rootspage'] ) ) {
                    $page = $_GET['rootspage'];
                    if( $page == 'upload' ) {
                        echo $this->upload_gedcom_handler();
                    } else if ( $page == 'create' ) {
                        echo $this->add_page_handler();
                    } else if ( $page == 'include' ) {
                        echo $this->include_page_handler();
                    } else if ( $page == 'edit' ) {
                        echo $this->edit_persona_page_handler();
                    } else if ( $page == 'util' ) {
                        echo $this->utility_page_handler();
                    }
                } else {

                    $batch_ids = RP_Dao_Factory::get_rp_persona_dao()
                            ->get_batch_ids( );
                    $options = get_option( 'persona_plugin' );
                    $options['home_url'] = home_url();

                    $builder = new RP_Tools_Page_Builder();
                    echo $builder->build( $options, $batch_ids );
                }
            } catch ( Exception $e ) {
                error_log($e->getMessage() . "::" . RP_Persona_Helper::trace_caller(),0);
                echo '<span style="color:red;margin-top:20px;display:inline-block;">' . $e->getMessage() . "::" . RP_Persona_Helper::trace_caller() . '</span>';
            }

        }

        /**
         *
         */
        function persona_options_init() {
            register_setting( 'persona_plugin', 'persona_plugin', array( $this, 'persona_options_validate' ) );
        }

        /**
         * Sanitize and validate input. Accepts an array, return a sanitized array.
         *
         * @param array $input
         * @return array $options
         */
        function persona_options_validate( $input ) {
            $options = get_option( 'persona_plugin' );
            $options['version'] = trim( esc_attr( $this->persona_version ) );
            $options['parent_page'] = intval( $input['parent_page'] );
            $options['per_page'] = intval( $input['per_page'] );
            $options['is_system_of_record']  = ( $input['is_system_of_record'] == 'true' ? 1 : 0 );
            $options['index_even_color'] = trim( esc_attr( $input['index_even_color'] ) );
            $options['index_odd_color'] = trim( esc_attr( $input['index_odd_color'] ) );
            $options['index_hdr_color'] = trim( esc_attr( $input['index_hdr_color'] ) );
            $options['banner_bcolor'] = trim( esc_attr( $input['banner_bcolor'] ) );
            $options['banner_fcolor'] = trim( esc_attr( $input['banner_fcolor'] ) );
            $options['banner_image'] = trim( esc_attr( $input['banner_image'] ) );
            $options['group_bcolor'] = trim( esc_attr( $input['group_bcolor'] ) );
            $options['group_fcolor'] = trim( esc_attr( $input['group_fcolor'] ) );
            $options['group_image'] = trim( esc_attr( $input['group_image'] ) );
            $options['pframe_color'] = trim( esc_attr( $input['pframe_color'] ) );
            $options['header_style'] = ( $input['header_style'] == 2 ? 2 : 1 );
            $options['hide_header'] = ( $input['hide_header'] == 1 ? 1 : 0 );
            $options['hide_facts'] = ( $input['hide_facts'] == 1 ? 1 : 0 );
            $options['hide_bio'] = ( $input['hide_bio'] == 1 ? 1 : 0 );
            $options['hide_ancestors'] = ( $input['hide_ancestors'] == 1 ? 1 : 0 );
            $options['hide_descendancy'] = ( $input['hide_descendancy'] == 1 ? 1 : 0 );
            $options['hide_family_c'] = ( $input['hide_family_c'] == 1 ? 1 : 0 );
            $options['hide_family_s'] = ( $input['hide_family_s'] == 1 ? 1 : 0 );
            $options['hide_evidence'] = ( $input['hide_evidence'] == 1 ? 1 : 0 );
            $options['hide_pictures'] = ( $input['hide_pictures'] == 1 ? 1 : 0 );
            $options['hide_edit_links'] = ( $input['hide_edit_links'] == 1 ? 1 : 0 );
            $options['hide_undef_pics'] = ( $input['hide_undef_pics'] == 1 ? 1 : 0 );
            $options['hide_dates'] = ( $input['hide_dates'] == 1 ? 1 : 0 );
            $options['hide_places'] = ( $input['hide_places'] == 1 ? 1 : 0 );
            $options['hide_donation'] = ( (isset($input['hide_donation']) && $input['hide_donation'] == true)? 1 : 0 );
            $options['is_editor'] = ( (isset($input['is_editor']) && $input['is_editor'] == true)? 1 : 0 );
            $options['debug'] = ( (isset($input['debug']) && $input['debug'] == true)? 1 : 0 );
            $options['post_page'] = ( (isset($input['post_page']) && $input['post_page'] == true)? 1 : 0 );
            $options['privacy_default'] =
                in_array( $input['privacy_default'], array( 'Pub', 'Pvt', 'Mbr' ) )
                    ? $input['privacy_default'] : 'Pub';
            $options['privacy_living'] =
                in_array( $input['privacy_living'], array( 'Pub', 'Pvt', 'Mbr', 'Exc' ) )
                    ? $input['privacy_living'] : 'Mbr';
            $options['custom_style'] = trim( esc_attr( $input['custom_style'] ) );
            $options['custom_page'] = trim( esc_attr( $input['custom_page'] ) );
            return $options;
        }

        /**
         *
         * @param array $qvars
         * @return array $qvars
         */
        function parameter_queryvars( $qvars )
        {
            $qvars[] = 'rootsvar';
            $qvars[] = 'override-surname';

            return $qvars;
        }

        function person_menu_filter( $args )
        {
            $options = get_option( 'persona_plugin' );
            if ( isset( $options['parent_page'] ) ) {
                if ( isset ( $args['exclude_tree'] ) ) {
                    $args['exclude_tree'] .= ',' . $options['parent_page'];
                } else {
                    $args['exclude_tree'] = $options['parent_page'];
                }
            }
            return $args;
        }
    }
}

/**
 * Second, instantiate a reference to an instance of the class
 */
if ( class_exists( 'Roots_Persona' ) ) {
    $roots_persona_plugin = new  Roots_Persona();
}

/**
 * Third, activate the plugin and any actions or filters
 */
if ( isset( $roots_persona_plugin ) ) {
    register_activation_hook( __FILE__,array( $roots_persona_plugin, 'persona_activate' ) );
    register_deactivation_hook( __FILE__, array( $roots_persona_plugin, 'persona_deactivate' ) );
    add_action( 'admin_init', array( $roots_persona_plugin, 'persona_upgrade' ) );
    add_action( 'admin_init', array( $roots_persona_plugin, 'persona_options_init' ) );

    add_shortcode( 'rootsPersona', array( $roots_persona_plugin, 'persona_handler' ) );
    add_shortcode( 'rootsPersonaHeader', array( $roots_persona_plugin, 'persona_handler' ) );
    add_shortcode( 'rootsPersonaBio', array( $roots_persona_plugin, 'persona_handler' ) );
    add_shortcode( 'rootsPersonaFacts', array( $roots_persona_plugin, 'persona_handler' ) );
    add_shortcode( 'rootsPersonaAncestors', array( $roots_persona_plugin, 'persona_handler' ) );
    add_shortcode( 'rootsPersonaDescendancy', array( $roots_persona_plugin, 'persona_handler' ) );
    add_shortcode( 'rootsPersonaFamilyC', array( $roots_persona_plugin, 'persona_handler' ) );
    add_shortcode( 'rootsPersonaFamilyS', array( $roots_persona_plugin, 'persona_handler' ) );
    add_shortcode( 'rootsPersonaPictures', array( $roots_persona_plugin, 'persona_handler' ) );
    add_shortcode( 'rootsPersonaEvidence', array( $roots_persona_plugin, 'persona_handler' ) );
    add_shortcode( 'rootsPersonaIndexPage', array( $roots_persona_plugin, 'index_page_handler' ) );
    //add_shortcode( 'rootsEditPersonaForm', array( $roots_persona_plugin, 'edit_persona_page_handler' ) );
    add_shortcode( 'rootsEvidencePage', array( $roots_persona_plugin, 'evidence_page_handler' ) );
    add_action( 'admin_menu', array( $roots_persona_plugin, 'persona_menus' ) );
    add_action( 'wp_print_styles', array( $roots_persona_plugin, 'insert_persona_styles' ) );
    add_action( 'wp_print_scripts', array( $roots_persona_plugin, 'insert_persona_scripts' ) );
    add_filter( 'the_content', array( $roots_persona_plugin, 'check_permissions' ), 2 );
    load_plugin_textdomain('rootspersona', false,  '/rootspersona/localization');
    add_filter( 'query_vars', array( $roots_persona_plugin, 'parameter_queryvars' ) );
    add_filter( 'wp_nav_menu_args', array( $roots_persona_plugin, 'person_menu_filter' ) );
    add_action('wp_ajax_rp_action', array( $roots_persona_plugin, 'rp_action_callback' ) );
    add_action('wp_head', array( $roots_persona_plugin, 'inject_custom_style' ) );
    add_action('wp_head', array( $roots_persona_plugin, 'insert_persona_scripts' ) );
    add_action('admin_head', array( $roots_persona_plugin, 'insert_admin_scripts' ));
    $options = get_option( 'persona_plugin' );
    if(isset($options['debug']) && $options['debug'] == '1') {
        if(!defined('WP_DEBUG')) define( 'WP_DEBUG', true ); // turn on debug mode
        if(!defined('WP_DEBUG_LOG')) define( 'WP_DEBUG_LOG', true ); // log to wp-content/debug.log
        if(!defined('WP_DEBUG_DISPLAY')) define( 'WP_DEBUG_DISPLAY', false ); // don't force display_errors to on
        @ini_set( 'display_errors', 0 ); // hide errors
    }
}
?>
