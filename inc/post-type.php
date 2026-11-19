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
        'all_items'          => __( 'All Magazines', '3d-corporate-book' ),
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
        'rewrite'            => $post_urls ? array( 'slug' => 'business-magazine' ) : false,
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => null,
        'menu_icon'          => 'dashicons-book',
        'supports'           => array( 'title', 'editor', 'revisions' )
    );
    
    register_post_type( 'corporate_book', $args );
    // Once, on update: sites that saved settings before kept the old defaults that load the viewer on
    // every page and start with sound on.
    if ( (int) get_option( 'corporate_book_db_version' ) < 2 ) {
      $settings = get_option( '_corporate_book_settings', array() );
      if ( is_array( $settings ) && $settings ) {
        $settings['selectiveScriptLoading'] = 'true';
        $settings['auto_sound']             = 'false';
        update_option( '_corporate_book_settings', $settings );
      }
      update_option( 'corporate_book_db_version', 2 );
      update_option( 'corporate_book_flush_rules', 1 ); // book URLs are on by default now
    }
    if ( (int) get_option( 'corporate_book_db_version' ) < 3 ) {
      $settings = get_option( '_corporate_book_settings', array() );
      if ( is_array( $settings ) && $settings ) {
        $settings['realistic']  = 'false';
        $settings['desk_scene'] = 'false';
        update_option( '_corporate_book_settings', $settings );
      }
      update_option( 'corporate_book_db_version', 3 );
    }
    if ( (int) get_option( 'corporate_book_db_version' ) < 5 ) {
      update_option( 'corporate_book_db_version', 5 );
      update_option( 'corporate_book_flush_rules', 1 ); // new address: /business-magazine/
    }
    if ( get_option( 'corporate_book_flush_rules' ) ) {
      delete_option( 'corporate_book_flush_rules' );
      flush_rewrite_rules();
    }
    
    // Old Region and Year groups: hidden now (merged into Categories), kept registered so their terms can be copied.
    foreach ( array(
        'corporate_book_region' => array( __( 'Regions', '3d-corporate-book' ), __( 'Region', '3d-corporate-book' ) ),
        'corporate_book_year'   => array( __( 'Years', '3d-corporate-book' ), __( 'Year', '3d-corporate-book' ) ),
    ) as $tax => $names ) {
      register_taxonomy( $tax, 'corporate_book', array(
          'labels'            => array( 'name' => $names[0], 'singular_name' => $names[1], 'menu_name' => $names[0], 'add_new_item' => $names[1] ),
          'hierarchical'      => true,
          'public'            => false,
          'show_ui'           => false,
          'show_in_rest'      => false,
      ) );
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
    // Once: Region and Year groups become Categories (India, Asia, 2026...).
    if ( (int) get_option( 'corporate_book_db_version' ) < 4 ) {
      foreach ( get_posts( array( 'post_type' => 'corporate_book', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $book ) {
        foreach ( array( 'corporate_book_region', 'corporate_book_year' ) as $old ) {
          $names = wp_get_object_terms( $book, $old, array( 'fields' => 'names' ) );
          if ( ! is_wp_error( $names ) && $names ) {
            wp_set_object_terms( $book, $names, 'corporate_book_category', true );
          }
        }
      }
      update_option( 'corporate_book_db_version', 4 );
    }
    
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
    add_action( 'admin_notices', array( $this, 'missing_previews_notice' ) );
    
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
    // Old links (/corporate-book/...) keep working: they move to /business-magazine/....
    add_action( 'template_redirect', function () {
      $path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared and rewritten below.
      $home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
      if ( is_404() && 0 === strpos( $path, $home . 'corporate-book' ) && ( strlen( $path ) === strlen( $home . 'corporate-book' ) || in_array( $path[ strlen( $home . 'corporate-book' ) ], array( '/', '?' ), true ) ) ) {
        wp_safe_redirect( home_url( '/business-magazine' . substr( $path, strlen( $home . 'corporate-book' ) ) ), 301 );
        exit;
      }
    } );
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
      // A book's own page: the full-width edition layout, without the theme's post title, image and byline.
      register_block_template(
        '3d-corporate-book//single-corporate_book',
        array(
          'title'   => __( 'Corporate Book edition', '3d-corporate-book' ),
          'content' => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
            . '<!-- wp:group {"tagName":"main","align":"full","layout":{"type":"constrained","contentSize":"1200px"}} --><main class="wp-block-group alignfull">'
            . '[corporate_book_edition]</main><!-- /wp:group -->'
            . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
        )
      );
    }
    add_shortcode( 'corporate_book_edition', function () {
      return is_singular( 'corporate_book' ) ? self::edition_page( get_queried_object_id() ) : '';
    } );
    
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
  /**
   * Book list: names the PDF books that still have no free preview or thumbnail (for example books
   * saved before an update). Opening a book in the editor makes both automatically.
   */
  public function missing_previews_notice() {
    $screen = get_current_screen();
    if ( ! $screen || 'edit-corporate_book' !== $screen->id || ! current_user_can( 'edit_posts' ) ) {
      return;
    }
    $locked  = 'true' === Corporate_Book::get_instance()->get_config( 'subscription_enable' );
    $missing = array();
    $urls    = array();
    foreach ( get_posts( array( 'post_type' => 'corporate_book', 'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => 200, 'fields' => 'ids' ) ) as $id ) {
      $data = get_post_meta( $id, '_corporate_book_data', true );
      if ( ! is_array( $data ) || empty( $data['pdf_source'] ) || ( isset( $data['source_type'] ) && 'pdf' !== $data['source_type'] ) ) {
        continue;
      }
      if ( empty( $data['pdf_thumb'] ) || ( $locked && empty( $data['preview_pdf'] ) ) ) {
        $missing[] = '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ? get_the_title( $id ) : '#' . $id ) . '</a>';
        $urls[]    = get_edit_post_link( $id, 'raw' );
      }
    }
    if ( $missing ) {
      echo '<div class="notice notice-warning cb-missing-previews"><p>' . esc_html__( 'These books have no thumbnail or free preview yet:', '3d-corporate-book' ) . ' ' . implode( ', ', array_slice( $missing, 0, 20 ) ) . ( count( $missing ) > 20 ? ' …' : '' ) . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links escaped above.
        . '<p><button type="button" class="button button-primary" id="cb-make-previews" data-urls="' . esc_attr( wp_json_encode( $urls ) ) . '">' . esc_html__( 'Make them all now', '3d-corporate-book' ) . '</button> <span id="cb-make-status" role="status"></span> <iframe id="cb-make-frame" title="" style="width:1px;height:1px;border:0;position:absolute;left:-9999px"></iframe></p></div>';
      // Opens each book's editor in a hidden frame: the editor makes the preview and thumbnail by itself; then the book is saved.
      echo "<script>(function(){var b=document.getElementById('cb-make-previews');if(!b)return;b.onclick=function(){var urls=JSON.parse(b.dataset.urls),i=0,f=document.getElementById('cb-make-frame'),s=document.getElementById('cb-make-status');b.disabled=true;function next(){if(i>=urls.length){s.textContent='" . esc_js( __( 'Done. Reloading…', '3d-corporate-book' ) ) . "';setTimeout(function(){location.reload();},1500);return;}s.textContent=(i+1)+' / '+urls.length;var saved=false,waited=0;f.onload=function(){if(saved){i++;next();return;}var t=setInterval(function(){waited+=1000;var d=f.contentDocument;if(!d){return;}var p=d.getElementById('corporate_book_preview_status'),h=d.getElementById('corporate_book_thumb_status');var busy=[p,h].some(function(e){return e&&e.textContent&&!/created|failed|saved/i.test(e.textContent)&&e.textContent.trim()!=='';});var started=[p,h].some(function(e){return e&&/created|failed/i.test(e.textContent||'');});if((started&&!busy)||waited>60000){clearInterval(t);saved=true;var u=d.getElementById('publish');if(u){u.click();}else{i++;next();}}},1000);};f.src=urls[i];}next();};})();</script>";
    }
  }

  public function library_template( $template ) {
    $ours = dirname( __FILE__ ) . '/../assets/templates/library.php';
    if ( wp_is_block_theme() ) {
      return $template;
    }
    if ( is_singular( 'corporate_book' ) ) {
      return dirname( __FILE__ ) . '/../assets/templates/edition.php';
    }
    return is_post_type_archive( 'corporate_book' ) ? $ours : $template;
  }

  public function book_page_content( $content ) {
    if ( 'corporate_book' !== get_post_type() || ! in_the_loop() ) {
      return $content;
    }
    if ( is_singular( 'corporate_book' ) && is_main_query() ) {
      return self::edition_page( get_the_ID() );
    }
    return $this->book_card( get_the_ID() );
  }

  /**
   * A book's own page: cover card (opens the book in a pop-up), the ways to buy, and the editor's own HTML below.
   *
   * @param int $book_id Book post ID.
   *
   * @return string HTML.
   */
  public static function edition_page( $book_id ) {
    $data    = get_post_meta( $book_id, '_corporate_book_data', true );
    $data    = is_array( $data ) ? $data : array();
    $site    = get_bloginfo( 'name' );
    $title   = get_the_title( $book_id );
    $sub     = (int) get_option( 'corporate_book_subscription_page' );
    $sub_url = $sub ? (string) get_permalink( $sub ) : '';
    $money   = array( 'Corporate_Book_Account', 'price' );

    // Plans: one tile each; Subscribe pays with Razorpay for signed-in readers, or opens the Subscription page.
    $tiles   = '';
    $plans   = Corporate_Book_Account::plans();
    $monthly = 0;
    foreach ( $plans as $plan ) {
      if ( $plan['days'] <= 31 ) {
        $monthly = $plan['price'] * 30 / $plan['days'];
      }
    }
    foreach ( $plans as $i => $plan ) {
      $per_month = $plan['price'] * 30 / $plan['days'];
      $save      = $monthly > 0 && $plan['days'] > 31 ? (int) round( 100 * ( 1 - $per_month / $monthly ) ) : 0;
      $month     = $plan['days'] > 31 ? '<small>' . esc_html( sprintf( /* translators: %s: price */ __( '%s/month', '3d-corporate-book' ), call_user_func( $money, round( $per_month ) ) ) ) . '</small>' : '<small>' . esc_html( sprintf( /* translators: %d: days */ _n( '%d day of access', '%d days of access', $plan['days'], '3d-corporate-book' ), $plan['days'] ) ) . '</small>';
      $tiles    .= '<button type="button" class="cb-ed-tile' . ( 0 === $i ? ' cb-ed-picked' : '' ) . '" data-plan="' . (int) $i . '">'
        . ( $save > 0 ? '<em class="cb-ed-best">' . esc_html( sprintf( /* translators: %d: percent */ __( 'Best value · Save %d%%', '3d-corporate-book' ), $save ) ) . '</em>' : '' )
        . '<i class="cb-ed-tick" aria-hidden="true"></i><span>' . esc_html( $plan['name'] ) . '</span><strong>' . esc_html( call_user_func( $money, $plan['price'] ) ) . '</strong>' . $month . '</button>';
    }
    $buy_plan = '';
    if ( $tiles ) {
      if ( is_user_logged_in() && Corporate_Book_Account::razorpay_ready() ) {
        Corporate_Book_Account::box( false ); // loads the payment script; its HTML is not needed here
        $buy_plan = '<button type="button" class="cb-plan cb-ed-subscribe" data-plan="0">' . esc_html__( 'Subscribe', '3d-corporate-book' ) . '</button>' . Corporate_Book_Account::autopay_tick();
      } elseif ( $sub_url ) {
        $buy_plan = '<a class="cb-ed-subscribe" href="' . esc_url( $sub_url ) . '">' . esc_html__( 'Subscribe', '3d-corporate-book' ) . '</a>';
      }
    }
    $plans_box = $tiles ? '<div class="cb-ed-block"><h2>' . esc_html( sprintf( /* translators: %s: site name */ __( 'Go Unlimited with %s', '3d-corporate-book' ), $site ) ) . '</h2>'
      . '<p class="cb-ed-lead">' . esc_html( sprintf( /* translators: %s: book title */ __( 'Read %s along with every other edition with just one subscription.', '3d-corporate-book' ), $title ) ) . ( $sub_url ? ' <a href="' . esc_url( get_post_type_archive_link( 'corporate_book' ) ) . '">' . esc_html__( 'View catalog', '3d-corporate-book' ) . '</a>' : '' ) . '</p>'
      . '<div class="cb-ed-row"><div class="cb-ed-tiles">' . $tiles . '</div>' . $buy_plan . '</div></div>' : '';

    // This issue in print.
    $issue = '';
    $info  = class_exists( 'Corporate_Book_Shop' ) ? Corporate_Book_Shop::book_info( $book_id ) : null;
    if ( $info ) {
      $issue = '<div class="cb-ed-block"><h2>' . esc_html( sprintf( /* translators: %s: book title */ __( 'Buy only %s', '3d-corporate-book' ), $title ) ) . '</h2>'
        . '<div class="cb-ed-row"><div class="cb-ed-tiles"><div class="cb-ed-tile cb-ed-static"><span>' . esc_html__( 'Printed copy', '3d-corporate-book' ) . '</span><strong>' . esc_html( Corporate_Book_Shop::money( $info['price'] ) ) . '</strong><small>' . esc_html__( 'Delivered in India', '3d-corporate-book' ) . '</small></div></div>'
        . Corporate_Book_Shop::buy_button( $book_id, __( 'Add to cart', '3d-corporate-book' ) ) . '</div></div>';
    }
    $or = $plans_box && $issue ? '<div class="cb-ed-or"><span>' . esc_html__( '(OR)', '3d-corporate-book' ) . '</span></div>' : '';

    $trust = '';
    foreach ( array(
        array( '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M6 18 18 6"/></svg>', __( 'Cancel anytime', '3d-corporate-book' ), __( 'No commitments', '3d-corporate-book' ) ),
        array( '<svg viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>', __( 'Instant access', '3d-corporate-book' ), __( 'Read on any device', '3d-corporate-book' ) ),
        array( '<svg viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5z"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg>', __( 'Secure payment', '3d-corporate-book' ), __( 'Powered by Razorpay', '3d-corporate-book' ) ),
    ) as $t ) {
      $trust .= '<div><b aria-hidden="true">' . $t[0] . '</b><span>' . esc_html( $t[1] ) . '<br>' . esc_html( $t[2] ) . '</span></div>';
    }

    // Share: the phone's share sheet when there is one, else copy the link (with a fallback for http sites and old browsers).
    $share = '<button type="button" class="cb-ed-share" data-done="' . esc_attr__( 'Link copied', '3d-corporate-book' ) . '" title="' . esc_attr__( 'Share', '3d-corporate-book' ) . '">&#x2934; ' . esc_html__( 'Share', '3d-corporate-book' ) . '</button>'
      . '<script data-no-optimize="1" data-cfasync="false" data-no-defer="1">document.addEventListener("click",function(e){var b=e.target.closest(".cb-ed-share");if(!b){return;}e.preventDefault();var u=location.href.split("#")[0],t=document.title;'
      . 'function done(){var o=b.innerHTML;b.textContent=b.getAttribute("data-done");b.classList.add("cb-done");setTimeout(function(){b.innerHTML=o;b.classList.remove("cb-done");},2000);}'
      . 'function copy(){var a=document.createElement("textarea");a.value=u;a.setAttribute("readonly","");a.style.position="fixed";a.style.opacity="0";document.body.appendChild(a);a.select();var ok=false;try{ok=document.execCommand("copy");}catch(x){}document.body.removeChild(a);ok?done():window.prompt(b.getAttribute("data-done"),u);}'
      . 'if(navigator.share&&matchMedia("(pointer:coarse)").matches){navigator.share({title:t,url:u}).catch(function(){});return;}'
      . 'if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(u).then(done,copy);}else{copy();}});</script>';
    $custom = isset( $data['custom_html'] ) && '' !== trim( $data['custom_html'] ) ? '<section class="cb-ed-custom">' . do_shortcode( $data['custom_html'] ) . '</section>' : '';

    return '<div class="cb-edition"><div class="cb-ed-cover">' . ( '' !== Corporate_Book::get_instance()->free_until( (int) $book_id ) ? '<span class="cb-free-tag">' . esc_html__( 'Free', '3d-corporate-book' ) . '</span>' : '' ) . do_shortcode( '[corporate_book id="' . (int) $book_id . '" type="thumb"][/corporate_book]' ) . '</div>'
      . '<div class="cb-ed-main">' . self::edition_tags( $book_id ) . '<h1 class="cb-ed-title">' . esc_html( $title ) . '</h1>'
      . '<div class="cb-ed-tools">' . ( class_exists( 'Corporate_Book_Reader' ) ? Corporate_Book_Reader::save_button( $book_id ) : '' ) . $share . '</div>'
      . ( $plans_box || $issue ? '<div class="cb-ed-box">' . $plans_box . $or . $issue . '</div>' : '' )
      . '<div class="cb-ed-trust">' . $trust . '</div></div></div>' . $custom
      . '<script>document.addEventListener("click",function(e){var t=e.target.closest(".cb-ed-tile[data-plan]");if(!t){return;}var box=t.closest(".cb-ed-block");box.querySelectorAll(".cb-ed-tile").forEach(function(x){x.classList.toggle("cb-ed-picked",x===t);});var b=box.querySelector(".cb-ed-subscribe[data-plan]");if(b){b.setAttribute("data-plan",t.getAttribute("data-plan"));}});</script>';
  }

  /** Category chips above an edition's title. */
  private static function edition_tags( $book_id ) {
    $terms = get_the_terms( $book_id, 'corporate_book_category' );
    $chips = '';
    foreach ( is_array( $terms ) ? $terms : array() as $t ) {
      $chips .= '<span>' . esc_html( $t->name ) . '</span>';
    }
    return '<div class="cb-ed-tags"><span class="cb-ed-new">' . esc_html__( 'Digital + Print edition', '3d-corporate-book' ) . '</span>' . $chips . '</div>';
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

