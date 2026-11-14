<?php

/**
 * Corporate Book CUSTOM POST
 *
 * Initializes and Registers the required custom post for corporateBook
 *
 * @since   1.0.0
 *
 * @package corporateBook
 * @author  Deepak Ghimire
 */
class Corporate_Book_Post_Type {
  
  /**
   * Holds the singleton class object.
   *
   * @since 1.0.0
   *
   * @var object
   */
  public static $instance;
  
  /**
   * Holds the base Corporate_Book class object.
   *
   * @since 1.0.0
   *
   * @var object
   */
  public $base;
  
  /**
   * Primary class constructor.
   *
   * @since 1.0.0
   */
  public function __construct() {
    
    // Load the base class object.
    $this->base = Corporate_Book::get_instance();
    
    $labels = array(
        'name'               => __( 'Corporate Book', '3d-corporate-book' ),
        'singular_name'      => __( 'Corporate Book', '3d-corporate-book' ),
        'menu_name'          => __( 'Corporate Books', '3d-corporate-book' ),
        'name_admin_bar'     => __( 'Corporate Book', '3d-corporate-book' ),
        'add_new'            => __( 'Add New Book', '3d-corporate-book' ),
        'add_new_item'       => __( 'Add New Book', '3d-corporate-book' ),
        'new_item'           => __( 'New Corporate Book', '3d-corporate-book' ),
        'edit_item'          => __( 'Edit Corporate Book', '3d-corporate-book' ),
        'view_item'          => __( 'View Corporate Book', '3d-corporate-book' ),
        'all_items'          => __( 'All Books', '3d-corporate-book' ),
        'search_items'       => __( 'Search Corporate Books', '3d-corporate-book' ),
        'parent_item_colon'  => __( 'Parent Corporate Books:', '3d-corporate-book' ),
        'not_found'          => __( 'No Corporate Books found.', '3d-corporate-book' ),
        'not_found_in_trash' => __( 'No Corporate Books found in Trash.', '3d-corporate-book' )
    );
    
    $post_urls = 'true' === $this->base->get_config( 'post_urls' );
    $args = array(
        'labels'             => $labels,
        'description'        => __( 'Description.', '3d-corporate-book' ),
        'public'             => $post_urls,
        'publicly_queryable' => $post_urls,
        'exclude_from_search' => true, // if not excluded, posts will be displayed in normal search. This will hide it from other archive and taxonomy listing, and needs to be fetched manually.
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => $post_urls ? array( 'slug' => 'corporate-book' ) : false,
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => null,
        'menu_icon'          => 'dashicons-book',
        'supports'           => array( 'title', 'editor', 'revisions' )
    );
    
    register_post_type( 'corporate_book', $args );
    if ( get_option( 'corporate_book_flush_rules' ) ) {
      delete_option( 'corporate_book_flush_rules' );
      flush_rewrite_rules();
    }
    
    register_taxonomy( 'corporate_book_category', 'corporate_book', array(
        'hierarchical'       => true,
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => true, //display the category admin page
        'show_admin_column'  => true,
        'show_in_nav_menus'  => true,
	    'rewrite'            => array( 'slug' => 'book-category' ),
    ) );
    
    if ( is_admin() ){
      if(!( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
        $this->init_admin();
      }
    } else {// Load frontend only components.
      $this->init_front();
    }
    
  }
  
  /**
   * Loads all admin related files into scope.
   *
   * @since 1.0.0
   */
  public function init_admin() {
    
    // Remove quick editing from the Corporate Book post type row actions.
    add_filter( 'post_row_actions', array( $this, 'remove_quick_edit' ), 10, 1 );
    
    // Manage post type columns.
    add_filter( 'manage_corporate_book_posts_columns', array( $this, 'corporate_book_columns' ) );
    add_action( 'manage_corporate_book_posts_custom_column', array( $this, 'corporate_book_columns_content' ), 10, 2 );
    
    add_filter( 'manage_edit-corporate_book_category_columns', array( $this, 'corporate_book_cat_columns' ) );
    add_filter( 'manage_corporate_book_category_custom_column', array( $this, 'corporate_book_cat_columns_content' ), 10, 3 );
    
    add_action( 'restrict_manage_posts', array( $this, 'corporate_book_category_filter' ), 10, 2 );
    
  }
  
  public function init_front() {
    
    add_filter( 'the_content', array( $this, 'filter_the_pdf_attachment_content' ) );
    add_filter( 'the_content', array( $this, 'book_page_content' ) );
    add_filter( 'template_include', array( $this, 'library_template' ) );
    // In post lists (latest posts, archives, search) a book shows its cover card.
    add_filter( 'get_the_excerpt', array( $this, 'book_list_card' ), 20, 2 );
    add_filter( 'has_post_thumbnail', array( $this, 'book_has_thumbnail' ), 10, 2 );
    add_filter( 'post_thumbnail_html', array( $this, 'book_thumbnail_html' ), 10, 2 );
    // Block themes: the book list page is the library grid, not the theme's archive with full-width images.
    if ( function_exists( 'register_block_template' ) && wp_is_block_theme() ) {
      register_block_template(
        '3d-corporate-book//archive-corporate_book',
        array(
          'title'   => __( 'Corporate Book library', '3d-corporate-book' ),
          'content' => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
            . '<!-- wp:group {"tagName":"main","align":"full","layout":{"type":"constrained","contentSize":"1200px"}} --><main class="wp-block-group alignfull">'
            . '[corporate_book_library]</main><!-- /wp:group -->'
            . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
        )
      );
    }
    
  }
  
  /**
   * Filter out unnecessary row actions Corporate Book post table.
   *
   * @param array $actions Default row actions.
   *
   * @return array $actions Amended row actions.
   * @since 1.0.0
   *
   */
  public function remove_quick_edit( $actions ) {
    if ( isset( get_current_screen()->post_type ) && 'corporate_book' == get_current_screen()->post_type ) {
      unset( $actions['inline hide-if-no-js'] );
    }
    
    return $actions;
  }
  
  /**
   * Customize the post columns for the Corporate Book post type.
   *
   * @return array $columns New Updated columns.
   * @since 1.0.0
   *
   */
  public function corporate_book_columns( $columns ) {
    
    $columns['shortcode'] = __( 'Shortcode', '3d-corporate-book' );
    $columns['modified'] = __( 'Last Modified', '3d-corporate-book' );
    
    return $columns;
  }
  
  /**
   * Customize the post columns for the Corporate Book post type category page
   *
   * @param array $defaults columns.
   *
   * @return array $defaults default columns.
   * @since 1.2.9
   *
   */
  public function corporate_book_cat_columns( $defaults ) {
    $defaults['shortcode'] = 'Shortcode';
    
    return $defaults;
  }
  
  /**
   * Add data to the custom columns added to the Corporate Book post type.
   *
   * @param string $column_name Name of the custom column.
   * @param int    $post_id     Current post ID.
   *
   * @since 1.0.0
   *
   */
  public function corporate_book_columns_content( $column_name, $post_id ) {
    $post_id = absint( $post_id );
    
    switch ( $column_name ) {
      case 'shortcode':
        echo '[corporate_book id="' . esc_attr( $post_id ) . '"][/corporate_book]';
        break;
      
      case 'modified' :
        the_modified_date();
        break;
    }
  }
  
  /**
   * Add data to the custom columns added to the Corporate Book post type category page.
   *
   * @param        $c
   * @param string $column_name Name of the custom column.
   * @param        $term_id
   *
   * @return string
   * @since 1.2.9
   *
   */
  public function corporate_book_cat_columns_content( $c, $column_name, $term_id = "" ) {
    
    return '[corporate_book books="' . get_term( $term_id, 'corporate_book_category' )->slug . '" limit="-1"][/corporate_book]';
    
  }
  
  
  
  /**
   * Shows the book on its own page (Book Post URLs).
   *
   * @param string $content Post content.
   *
   * @return string
   */
  /**
   * The book list page shows the library (classic themes; block themes show the cover cards in their own list).
   *
   * @param string $template Template file.
   *
   * @return string
   */
  public function library_template( $template ) {
    $ours = dirname( __FILE__ ) . '/../assets/templates/library.php';
    return is_post_type_archive( 'corporate_book' ) && ! wp_is_block_theme() ? $ours : $template;
  }

  public function book_page_content( $content ) {
    if ( 'corporate_book' !== get_post_type() || ! in_the_loop() ) {
      return $content;
    }
    if ( is_singular( 'corporate_book' ) && is_main_query() ) {
      return do_shortcode( '[corporate_book id="' . get_the_ID() . '"][/corporate_book]' );
    }
    return $this->book_card( get_the_ID() );
  }

  /**
   * Cover card of a book: thumbnail, title and Preview button.
   *
   * @param int $book_id Book post ID.
   *
   * @return string HTML.
   */
  public function book_card( $book_id ) {
    return do_shortcode( '[corporate_book id="' . (int) $book_id . '" type="thumb"][/corporate_book]' );
  }

  /**
   * Excerpt of a book in post lists: its cover card.
   *
   * @param string  $excerpt Excerpt.
   * @param WP_Post $post    Post.
   *
   * @return string
   */
  public function book_list_card( $excerpt, $post = null ) {
    return $post && 'corporate_book' === $post->post_type && ! is_admin() ? $this->book_card( $post->ID ) : $excerpt;
  }

  /**
   * URL of a book's thumbnail image, or ''.
   *
   * @param int $book_id Book post ID.
   *
   * @return string
   */
  private function book_thumb_url( $book_id ) {
    $data = get_post_meta( $book_id, '_corporate_book_data', true );
    return is_array( $data ) && ! empty( $data['pdf_thumb'] ) ? $data['pdf_thumb'] : '';
  }

  /**
   * A book with a thumbnail counts as having a featured image.
   *
   * @param bool             $has  Whether the post has a featured image.
   * @param int|WP_Post|null $post Post.
   *
   * @return bool
   */
  public function book_has_thumbnail( $has, $post = null ) {
    $post = get_post( $post );
    return $post && 'corporate_book' === $post->post_type && ! $has ? '' !== $this->book_thumb_url( $post->ID ) : $has;
  }

  /**
   * Featured image of a book without one: its thumbnail.
   *
   * @param string $html    Image HTML.
   * @param int    $post_id Post ID.
   *
   * @return string
   */
  public function book_thumbnail_html( $html, $post_id = 0 ) {
    if ( '' === $html && 'corporate_book' === get_post_type( $post_id ) && '' !== $this->book_thumb_url( $post_id ) ) {
      return '<img class="cb-featured-thumb" style="display:block;max-width:240px;width:100%;height:auto;margin:0 auto" src="' . esc_url( $this->book_thumb_url( $post_id ) ) . '" alt="' . esc_attr( get_the_title( $post_id ) ) . '" loading="lazy"/>';
    }
    return $html;
  }

  public function filter_the_pdf_attachment_content( $content ) {
    global $post;
    
    
    // Check if we're inside the main loop in a single post page.
    if ( is_single() && in_the_loop() && is_main_query() && $post->post_mime_type == "application/pdf" ) {
      $html = "";
      $lightbox = $this->base->get_config( 'attachment_lightbox' );
      
      if ( $lightbox == 'true' ) {
        $html = do_shortcode( '[corporate_book attachment_pdf_flipbook_lightbox="true" type="link" source="' . wp_get_attachment_url( $post->ID ) . '"]Open ' . get_the_title( $post ) . '[/corporate_book]' );
      } else {
        $html = do_shortcode( '[corporate_book source="' . wp_get_attachment_url( $post->ID ) . '"][/corporate_book]' );
      }
      
      return $html;
    }
    
    return $content;
  }
  
	// $which (the position of the filters form) is either 'top' or 'bottom'
	function corporate_book_category_filter( $post_type, $which ) {
		if ( 'top' === $which && 'corporate_book' === $post_type) {
			$taxonomy = $post_type === 'corporate_book' ? 'corporate_book_category':'corporate_book_pdf_category';
			$tax = get_taxonomy( $taxonomy );            // get the taxonomy object/data
			$cat = filter_input( INPUT_GET, $taxonomy ); // get the selected category slug
			
			echo '<label class="screen-reader-text" for="my_tax">Filter by ' .
				esc_html( $tax->labels->singular_name ) . '</label>';
			
			wp_dropdown_categories( [
				'show_option_all' => $tax->labels->all_items,
				'hide_empty' => 0, // include categories that have no posts
				'hierarchical' => $tax->hierarchical,
				'show_count' => 0, // don't show the category's posts count
				'orderby' => 'name',
				'selected' => $cat,
				'taxonomy' => $taxonomy,
				'name' => $taxonomy,
				'value_field' => 'slug',
			] );
		}
	}
  
  /**
   * Returns the singleton instance of the class.
   *
   * @return object Corporate_Book_Post_Type object.
   * @since 1.0.0
   *
   */
  public static function get_instance() {
    
    if ( !isset( self::$instance ) && !( self::$instance instanceof Corporate_Book_Post_Type ) ) {
      self::$instance = new Corporate_Book_Post_Type();
    }
    
    return self::$instance;
    
  }
}

// Load the post-type class.
$corporate_book_post_type = Corporate_Book_Post_Type::get_instance();

