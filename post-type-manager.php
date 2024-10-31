<?php
/**
	Plugin Name: Post Type Manager
	Plugin URI:  https://wordpress.org/plugins/post-type-manager/
	Description: This plugin to manage posts, pages, custom posts to move other post types.
	Version:     1.2
	Author:      Abhay Yadav
	Author URI:  http://inizsoft.com
	License:     GPL2
	License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

 // The main post type manager
 final class Post_Type_Manager {

	private $asset_version = 28072017;

	public function __construct() {
		add_action( 'admin_init',     array( $this, 'admin_init'      ) );
	}

	
	// Setup admin actions
	public function admin_init() {

		// if page not allowed
		if ( ! $this->is_allowed_page() ) {
			return;
		}

		// Add column for quick-edit support
		add_action( 'manage_posts_columns',        array( $this, 'add_column'    ) );
		add_action( 'manage_pages_columns',        array( $this, 'add_column'    ) );
		add_action( 'manage_posts_custom_column',  array( $this, 'manage_column' ), 10,  2 );
		add_action( 'manage_pages_custom_column',  array( $this, 'manage_column' ), 10,  2 );

		// Add UI to "Publish" metabox
		add_action( 'admin_head',                  array( $this, 'admin_head'        ) );
		add_action( 'post_submitbox_misc_actions', array( $this, 'metabox'           ) );
		add_action( 'quick_edit_custom_box',       array( $this, 'quick_edit'        ) );
		add_action( 'bulk_edit_custom_box',        array( $this, 'quick_edit_bulk'   ) );
		add_action(	'admin_enqueue_scripts',       array( $this, 'quick_edit_script' ) );

		// Override
		add_filter( 'wp_insert_attachment_data', array( $this, 'override_type' ), 10, 2 );
		add_filter( 'wp_insert_post_data',       array( $this, 'override_type' ), 10, 2 );

		// Pass object into an action
		do_action( 'post_type_manager', $this );
	}

    //  ptm_metabox()
	public function metabox() {

		// Post types
		$post_type  = get_post_type();
		$post_types = get_post_types( $this->get_post_type_args(), 'objects' );
		$cpt_object = get_post_type_object( $post_type );

		// if object does not exist or produces an error
		if ( empty( $cpt_object ) || is_wp_error( $cpt_object ) ) {
			return;
		}

		// Force-add current post type if it's not in the list
		if ( ! in_array( $cpt_object, $post_types, true ) ) {
			$post_types[ $post_type ] = $cpt_object;
		}

		// Unset attachment types, since support seems to be broken
		if ( isset( $post_types['attachment'] ) ) {
			unset( $post_types['attachment'] );
		}

		?><div class="misc-pub-section misc-pub-section-last post-type-manager">
			<label for="ptm_post_type"><?php esc_html_e( 'Post Type:', 'post-type-manager' ); ?></label>
			<span id="post-type-display"><?php echo esc_html( $cpt_object->labels->singular_name ); ?></span>

			<?php if ( current_user_can( $cpt_object->cap->publish_posts ) ) : ?>

				<a href="#" id="edit-post-type-manager" class="hide-if-no-js"><?php esc_html_e( 'Edit', 'post-type-manager' ); ?></a>
				<div id="post-type-select">
					<select name="ptm_post_type" id="ptm_post_type"><?php

						foreach ( $post_types as $_post_type => $pt ) :

							if ( ! current_user_can( $pt->cap->publish_posts ) ) :
								continue;
							endif;

							?><option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $post_type, $_post_type ); ?>><?php echo esc_html( $pt->labels->singular_name ); ?></option><?php

						endforeach;

					?></select>
					<a href="#" id="save-post-type-manager" class="hide-if-no-js button"><?php esc_html_e( 'OK', 'post-type-manager' ); ?></a>
					<a href="#" id="cancel-post-type-manager" class="hide-if-no-js"><?php esc_html_e( 'Cancel', 'post-type-manager' ); ?></a>
				</div><?php

				wp_nonce_field( 'post-type-selector', 'ptm-nonce-select' );

			endif;

		?></div>

	<?php
	}
	
	// Adds the post type column
	public function add_column( $columns ) {
		return array_merge( $columns, array( 'post_type' => esc_html__( 'Type', 'post-type-manager' ) ) );
	}

	public function manage_column( $column, $post_id ) {
		switch( $column ) {
			case 'post_type' :
				$post_type = get_post_type_object( get_post_type( $post_id ) ); ?>

				<span data-post-type="<?php echo esc_attr( $post_type->name ); ?>"><?php echo esc_html( $post_type->labels->singular_name ); ?></span>

				<?php
				break;
		}
	}

	//  Adds quickedit button for bulk-editing post types
	public function quick_edit( $column_name = '' ) {

		// prevent multiple dropdowns in each column
		if ( 'post_type' !== $column_name ) {
			return;
		} ?>

		<div id="ptm_quick_edit" class="inline-edit-group wp-clearfix">
			<label class="alignleft">
				<span class="title"><?php esc_html_e( 'Post Type', 'post-type-manager' ); ?></span><?php

				wp_nonce_field( 'post-type-manager', 'ptm-nonce-select' );

				$this->select_box();

			?></label>
		</div>

	<?php
	}

	//  Adds quickedit button for bulk-editing post types
	public function quick_edit_bulk( $column_name = '' ) {

		// prevent multiple dropdowns in each column
		if ( 'post_type' !== $column_name ) {
			return;
		} ?>

		<label id="ptm_bulk_edit" class="alignleft">
			<span class="title"><?php esc_html_e( 'Post Type', 'post-type-manager' ); ?></span><?php

			wp_nonce_field( 'post-type-manager', 'ptm-nonce-select' );

			$this->select_box( true );

		?></label>

	<?php
	}

    // Adds quickedit script for getting values into quickedit box
	public function quick_edit_script( $hook = '' ) {

		// if not edit.php admin page
		if ( 'edit.php' !== $hook ) {
			return;
		}

		// Enqueue edit JS
		wp_enqueue_script( 'ptm_quickedit', plugin_dir_url( __FILE__ ) . 'assets/js/quickedit-ptm.js', array( 'jquery' ), $this->asset_version, true );
	}

	// Output a post-type dropdown
	public function select_box( $bulk = false ) {

		// Get post type specific data
		$args       = $this->get_post_type_args();
		$post_types = get_post_types( $args, 'objects' );
		$post_type  = get_post_type();
		$selected   = '';

		// Unset attachment types, since support seems to be broken
		if ( isset( $post_types['attachment'] ) ) {
			unset( $post_types['attachment'] );
		}

		// Start an output buffer
		ob_start();

		// Output
		?><select name="ptm_post_type" id="ptm_post_type"><?php

			// Maybe include "No Change" option for bulk
			if ( true === $bulk ) :
				?><option value="-1"><?php esc_html_e( '&mdash; No Change &mdash;', 'post-type-manager' ); ?></option><?php
			endif;

			// Loop through post types
			foreach ( $post_types as $_post_type => $pt ) :

				// Skip if user cannot publish this type of post
				if ( ! current_user_can( $pt->cap->publish_posts ) ) :
					continue;
				endif;

				// Only select if not bulk
				if ( false === $bulk ) :
					$selected = selected( $post_type, $_post_type );
				endif;

				// Output option
				?><option value="<?php echo esc_attr( $pt->name ); ?>" <?php echo $selected; // Do not escape ?>><?php echo esc_html( $pt->labels->singular_name ); ?></option><?php

			endforeach;

		?></select><?php

		// Output the current buffer
		echo ob_get_clean();
	}

	/**
	 * Override post_type in wp_insert_post()
	 *
	 * We do a bunch of sanity checks here, to make sure we're only changing the
	 * post type when the user explicitly intends to.
	 *
	 * - Not during autosave
	 * - Check nonce
	 * - Check user capabilities
	 * - Check $_POST input name
	 * - Check if revision or current post-type
	 * - Check new post-type exists
	 * - Check that user can publish posts of new type
	 * @param  array  $data
	 * @param  array  $postarr
	 *
	 * @return Maybe modified $data
	 */
	public function override_type( $data = array(), $postarr = array() ) {

		// Bail if form field is missing
		if ( empty( $_REQUEST['ptm_post_type'] ) || empty( $_REQUEST['ptm-nonce-select'] ) ) {
			return $data;
		}

		// Post type information
		$post_type        = sanitize_key( $_REQUEST['ptm_post_type'] );
		$post_type_object = get_post_type_object( $post_type );

		//  if empty post type
		if ( empty( $post_type ) || empty( $post_type_object ) ) {
			return $data;
		}

		//  if user cannot 'edit_post'
		if ( ! current_user_can( 'edit_post', $postarr['ID'] ) ) {
			return $data;
		}

		// if nonce is invalid
		if ( ! wp_verify_nonce( $_REQUEST['ptm-nonce-select'], 'post-type-manager' ) ) {
			return $data;
		}

		// if autosave
		if ( wp_is_post_autosave( $postarr['ID'] ) ) {
			return $data;
		}

		// if revision
		if ( wp_is_post_revision( $postarr['ID'] ) ) {
			return $data;
		}

		// if it's a revision
		if ( in_array( $postarr['post_type'], array( $post_type, 'revision' ), true ) ) {
			return $data;
		}

		// if user cannot 'publish_posts' on the new type
		if ( ! current_user_can( $post_type_object->cap->publish_posts ) ) {
			return $data;
		}

		// Update post type
		$data['post_type'] = $post_type;

		// Return modified post data
		return $data;
	}

	 // Adds needed JS and CSS to admin header
	public function admin_head() {
	?>

		<script type="text/javascript">
			jQuery( document ).ready( function( $ ) {
				jQuery( '.misc-pub-section.curtime.misc-pub-section-last' ).removeClass( 'misc-pub-section-last' );
				jQuery( '#edit-post-type-manager' ).on( 'click', function(e) {
					jQuery( this ).hide();
					jQuery( '#post-type-select' ).slideDown();
					e.preventDefault();
				});
				jQuery( '#save-post-type-manager' ).on( 'click', function(e) {
					jQuery( '#post-type-select' ).slideUp();
					jQuery( '#edit-post-type-manager' ).show();
					jQuery( '#post-type-display' ).text( jQuery( '#ptm_post_type :selected' ).text() );
					e.preventDefault();
				});
				jQuery( '#cancel-post-type-manager' ).on( 'click', function(e) {
					jQuery( '#post-type-select' ).slideUp();
					jQuery( '#edit-post-type-manager' ).show();
					e.preventDefault();
				});
			});
		</script>
		<style type="text/css">
			#wpbody-content .inline-edit-row .inline-edit-col-right .alignleft + .alignleft {
				float: right;
			}
			#post-type-select {
				line-height: 2.5em;
				margin-top: 3px;
				display: none;
			}
			#post-type-select select#ptm_post_type {
				margin-right: 2px;
			}
			#post-type-select a#save-post-type-manager {
				vertical-align: middle;
				margin-right: 2px;
			}
			#post-type-display {
				font-weight: bold;
			}
			#post-body .post-type-manager::before {
				content: '\f109';
				font: 400 20px/1 dashicons;
				speak: none;
				display: inline-block;
				padding: 0 2px 0 0;
				top: 0;
				left: -1px;
				position: relative;
				vertical-align: top;
				-webkit-font-smoothing: antialiased;
				-moz-osx-font-smoothing: grayscale;
				text-decoration: none !important;
				color: #888;
			}
		</style>

	<?php
	}

	 // return bool True if it should load, false if not
	private static function is_allowed_page() {

		// Only for admin area
		if ( is_blog_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX && ( ! empty( $_REQUEST['action'] ) && ( 'inline-save' === $_REQUEST['action'] ) ) ) ) {

			// Allowed admin pages
			$pages = apply_filters( 'ptm_allowed_pages', array(
				'post.php',
				'edit.php',
				'admin-ajax.php'
			) );

			// Only show manager when editing
			return (bool) in_array( $GLOBALS['pagenow'], $pages, true );
		}

		// Default to false
		return false;
	}

	 // return array
	private function get_post_type_args() {
		return (array) apply_filters( 'ptm_post_type_filter', array(
			'public'  => true,
			'show_ui' => true
		) );
	}
}
new Post_Type_Manager();
