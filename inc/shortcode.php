<?php

/**
 * Created by PhpStorm.
 * User: Deepak
 * Date: 5/3/2016
 * Time: 2:27 PM
 */
class Corporate_Book_ShortCode {

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

    // Load shortcode hooks and filters.
    add_shortcode( 'corporate_book', array( $this, 'shortcode' ) );
    add_shortcode( 'corporate_book_library', array( $this, 'library' ) );

  }

  /**
   * Builds the Corporate Book Shortcode for the plugin
   *
   * @param array  $attr    Attributes of the shortcode.
   * @param string $content Content of the button or thumb
   *
   * @return string HTML content to display image-text.
   * @since 1.0.0
   *
   */
  public function shortcode( $attr, $content = '' ) {

    if ( $this->base->selective_script_loading == true ) {
      //enqueue script
      wp_enqueue_script( 'corporate-book-script' );
      wp_enqueue_script( 'corporate-book-extras' );

      //enqueue styles
      wp_enqueue_style( 'corporate-book-style' );
    }

    $ismulti = isset( $attr['books'] ) && trim( $attr['books'] ) !== '';
    $atts_default = array(
        'class' => '',
        'id'    => '',
        'books' => ''
    );
    //atts or post defaults
    $atts = shortcode_atts( $atts_default, $attr, 'corporate_book' );

    if ( $ismulti ) {
      $limit = isset( $attr['limit'] ) ? (int) $attr['limit'] : 5;
      $ids = array();
      $books = explode( ',', $atts['books'] );
      foreach ( (array) $books as $query ) {
        $query = trim( $query );
        if ( is_numeric( $query ) ) {
          array_push( $ids, $query );
        } else {
          if ( $query == 'all' || $query == '*' ) {
	          $isdrafts = isset( $attr['drafts'] ) && trim( $attr['drafts'] ) == 'true';
	          $post_status = $isdrafts && current_user_can( 'read_private_posts' ) ? array('publish', 'draft','private') : null;
            $postslist = get_posts( array(
                'post_type'      => 'corporate_book',
                'posts_per_page' => -1,
                'numberposts'    => $limit,
                'nopaging'       => true,
                'post_status' => $post_status
            ) );
            foreach ( $postslist as $post ) {
              if(!in_array($post->ID, $ids)) {
                array_push( $ids, $post->ID );
              }
            }
          } else {
            $postslist = get_posts( array(
                'tax_query'      => array(
                    array(
                        'taxonomy' => 'corporate_book_category',
                        'field'    => 'slug',
                        'terms'    => $query,
                    )
                ),
                'post_type'      => 'corporate_book',
                'posts_per_page' => -1,
                'numberposts'    => $limit,
                'nopaging'       => true
            ) );
            foreach ( $postslist as $post ) {
              if(!in_array($post->ID, $ids)) {
                array_push( $ids, $post->ID );
              }
            }
          }
        }
      }
      $shelf = isset( $attr['shelf'] ) && 'true' === trim( $attr['shelf'] );
      $html  = '<div class="corporate-book-books' . ( $shelf ? ' df-has-shelf' : '' ) . '">';
      $limitMax = $limit == '-1' ? 999 : (int) $limit;
      $limit = 0;
      foreach ( $ids as $id ) {
        if ( $limit >= $limitMax ) {
          break;
        }
        $attr['id'] = esc_attr( $id );
        $html .= $this->book( $attr, $content, true );
        $limit ++;

      }

      return $html . '</div>';

    } else {
      return $this->book( $attr, $content );
    }
  }

  /**
   * Helper function for Corporate Book Shortcode
   *
   * @param        $attr
   * @param string $content Content of the button or thumb
   *
   * @param bool   $multi   checks if this is a part of multiple books request
   *
   * @return string HTML content to display image-text.
   * @since    1.0.0
   *
   * @internal param array $attr Attributes of the shortcode.
   */
  /**
   * Lock box shown to non-subscribers when a locked PDF book has no preview file.
   *
   * @return string HTML.
   */
  /**
   * [corporate_book_library]: every published book as a cover card, grouped by year,
   * with year buttons and a filter list of years and categories.
   *
   * @return string HTML.
   */
  public function library() {
    wp_enqueue_script( 'corporate-book-script' );
    wp_enqueue_script( 'corporate-book-extras' );
    wp_enqueue_style( 'corporate-book-style' );
    wp_enqueue_script( 'corporate-book-library', plugins_url( 'assets/js/corporate-book-library.js', $this->base->file ), array(), $this->base->version, true );
    $years  = array();
    $groups = array( 'region' => array(), 'cat' => array() );
    $query = isset( $_GET['cb_q'] ) ? sanitize_text_field( wp_unslash( $_GET['cb_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public search.
    $found = get_posts( array( 'post_type' => 'corporate_book', 'post_status' => 'publish', 'numberposts' => -1, 's' => $query ) );
    $note  = '' === $query ? '' : '<p class="cb-library-note">' . esc_html( sprintf( /* translators: 1: number of books, 2: search words */ _n( '%1$d book found for "%2$s"', '%1$d books found for "%2$s"', count( $found ), '3d-corporate-book' ), count( $found ), $query ) ) . ' · <a href="' . esc_url( remove_query_arg( 'cb_q' ) ) . '">' . esc_html__( 'Show all books', '3d-corporate-book' ) . '</a></p>';
    foreach ( $found as $book ) {
      // The Year group wins over the publish date, so an issue can be filed under its edition year.
      $year_terms = get_the_terms( $book, 'corporate_book_year' );
      $year_name  = is_array( $year_terms ) ? $year_terms[0]->name : get_the_date( 'Y', $book );
      // Filter tokens are space-separated, so "FY 2025-26" becomes "fy-2025-26"; the heading keeps the name.
      $filters    = array( sanitize_title( $year_name ) );
      foreach ( array( 'corporate_book_region' => 'region', 'corporate_book_category' => 'cat' ) as $tax => $prefix ) {
        $found_terms = get_the_terms( $book, $tax );
        foreach ( is_array( $found_terms ) ? $found_terms : array() as $t ) {
          $filters[]                       = $prefix . '-' . $t->slug;
          $groups[ $prefix ][ $t->slug ] = $t->name;
        }
      }
      $years[ $year_name ][] = '<div class="cb-library-item" data-filters="' . esc_attr( implode( ' ', $filters ) ) . '">' . $this->book( array( 'id' => $book->ID, 'type' => 'thumb' ) ) . '</div>';
    }
    krsort( $years );
    $button = function ( $filter, $label, $active = false ) {
      return '<button type="button" class="cb-filter' . ( $active ? ' cb-active' : '' ) . '" data-filter="' . esc_attr( $filter ) . '">' . esc_html( $label ) . '</button>';
    };
    $top  = $button( 'all', __( 'All', '3d-corporate-book' ), true );
    $main = '';
    foreach ( $years as $year => $cards ) {
      $top  .= $button( sanitize_title( $year ), $year );
      $main .= '<section class="cb-library-year"><h2>' . esc_html( $year ) . '</h2><div class="cb-library-grid">' . implode( '', $cards ) . '</div></section>';
    }
    $rows = '';
    foreach ( array( 'region' => __( 'Region', '3d-corporate-book' ), 'cat' => __( 'Category', '3d-corporate-book' ) ) as $prefix => $label ) {
      $chips = '';
      foreach ( $groups[ $prefix ] as $slug => $name ) {
        $chips .= $button( $prefix . '-' . $slug, $name );
      }
      if ( $chips ) {
        $rows .= '<div class="cb-chip-row"><span class="cb-chip-label">' . esc_html( $label ) . '</span><div class="cb-library-filter">' . $chips . '</div></div>';
      }
    }
    $search = '<form class="cb-library-search" role="search" method="get" action="' . esc_url( remove_query_arg( 'cb_q' ) ) . '">'
      . Corporate_Book_Account::query_fields( remove_query_arg( 'cb_q' ) )
      . '<input type="search" name="cb_q" value="' . esc_attr( $query ) . '" placeholder="' . esc_attr__( 'Search magazines…', '3d-corporate-book' ) . '" aria-label="' . esc_attr__( 'Search magazines', '3d-corporate-book' ) . '"><button type="submit">' . esc_html__( 'Search', '3d-corporate-book' ) . '</button></form>';
    return '<div class="cb-library"><div class="cb-library-bar">' . $search
      . '<div class="cb-chip-row"><span class="cb-chip-label">' . esc_html__( 'Year', '3d-corporate-book' ) . '</span><div class="cb-library-years">' . $top . '</div></div>'
      . $rows
      . '</div><div class="cb-library-main">' . $note . $main . '</div></div>';
  }

  /** "Free to read until 30 Nov" while a book is free for a limited time. */
  private function free_note( $post_id, $class ) {
    $last = is_numeric( $post_id ) ? $this->base->free_until( (int) $post_id ) : '';
    /* translators: %s: date */
    return '' === $last ? '' : '<div class="' . esc_attr( $class ) . '">' . esc_html( sprintf( __( 'Free to read until %s', '3d-corporate-book' ), date_i18n( get_option( 'date_format' ), strtotime( $last . ' 12:00:00' ) ) ) ) . '</div>';
  }

  /** "Print copy · ₹499" line on a cover card, when the book is sold in print. */
  private function card_price( $post_id ) {
    $info = is_numeric( $post_id ) && class_exists( 'Corporate_Book_Shop' ) ? Corporate_Book_Shop::book_info( (int) $post_id ) : null;
    return $info ? '<div class="cb-thumb-price">' . esc_html( sprintf( /* translators: %s: price */ __( 'Print copy · %s', '3d-corporate-book' ), Corporate_Book_Shop::money( $info['price'] ) ) ) . '</div>' : '';
  }

  /** Short "Add to cart" button for a cover card ('' when the book is not for sale). */
  private function card_cart( $post_id ) {
    return is_numeric( $post_id ) && class_exists( 'Corporate_Book_Shop' ) ? Corporate_Book_Shop::buy_button( (int) $post_id, __( 'Add to cart', '3d-corporate-book' ) ) : '';
  }

  public function lock_box() {
    $lock = $this->base->lock_options();
    return '<div class="cb-lock cb-lock-static"><div class="cb-lock-title">' . esc_html( $lock['title'] ) . '</div><div class="cb-lock-box"><p class="cb-lock-text">' . esc_html( $lock['text'] ) . '</p>' . $lock['account'] . '</div></div>';
  }

  public function book( $attr, $content = '', $multi = false ) {
    $base = $this->base;

    $atts_default = array(
        'class' => '',
        'id'    => '',
        'type'  => $multi ? 'thumb' : 'book'
    );

    //atts or post defaults
    $atts = shortcode_atts( $atts_default, $attr, 'corporate_book' );
    if($atts["type"] === "hidden"){
      return "";
    }
    //in PHP7 if $attr is not an array it causes issue
    if ( is_array( $attr ) == false ) {
      $attr = array();
    }
    $html_attr = array();

    //default data
    $id = $atts['id'] === '' ? 'df_rand' . wp_rand() : $atts['id'];
    $id = sanitize_title($id);
    $type = $atts['type'];
    $class = $atts['class'];
    $title = do_shortcode( $content );

    //get Id
    $post_id = $id;
    $hasId = false;
    $thumb_url = '';
    $thumb_tag_type = $base->get_config( 'thumb_tag_type' );
    $share_slug = $base->get_config( 'share_slug' );
	  
	  $post = trim( $post_id ) === '' ? null : get_post( $post_id );
    $post_data = array();
    $locked = false;
		
    //pull post-data if available for the script part only
    if ( $post != null && !empty( $post_id ) && is_numeric( $post_id ) ) {
	    //Corporate Book cannot display password-protected flipbook post-shortcodes.
	    //Passwords were not intended for flipbook post. It will result in a blank output
	    //As of version 2.4.29 password radio box is also hidden in post-UI.
	    if(post_password_required( $post )){
				return '';
	    }
			//If flipbook post is private, only the user with read capability should be able to view.
	    if ( ( $post->post_status === "private" && !current_user_can( 'read_post',$post_id ) ) ) {
		    return '';
	    }
	    //If flipbook post is draft, only the user with edit capability should be able to view.
	    if ( ( $post->post_status === "draft" && !current_user_can( 'edit_post',$post_id) ) ) {
		    return '';
	    }
	    // Subscription lock: guests and non-subscribers get the free preview only.
	    $locked = ! $base->user_has_access( $post_id );
	    if ( $locked ) {
		    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			    define( 'DONOTCACHEPAGE', true );
		    }
		    unset( $attr['source'] );
	    }
			
			$id = 'df_' . $post_id;

      $post_meta = get_post_meta( $post_id, '_corporate_book_data' );

      if ( $title === '' ) {
        $title = get_the_title( $post_id );
      }


      if ( is_array( $post_meta ) && count( $post_meta ) > 0 ) {
        $post_data = $post_meta[0];
      }

      //conversion
      $post_data['backgroundColor'] = isset( $post_data['bg_color'] ) ? $post_data['bg_color'] : null;
      $post_data['backgroundImage'] = isset( $post_data['bg_image'] ) ? $post_data['bg_image'] : null;
      $post_data['autoEnableOutline'] = isset( $post_data['auto_outline'] ) ? $post_data['auto_outline'] : null;
      $post_data['autoEnableThumbnail'] = isset( $post_data['auto_thumbnail'] ) ? $post_data['auto_thumbnail'] : null;
      $post_data['overwritePDFOutline'] = isset( $post_data['overwrite_outline'] ) ? $post_data['overwrite_outline'] : null;
      $post_data['soundEnable'] = isset( $post_data['auto_sound'] ) ? $post_data['auto_sound'] : null;
      $post_data['maxTextureSize'] = isset( $post_data['texture_size'] ) ? $post_data['texture_size'] : null;
      $post_data['pageMode'] = isset( $post_data['page_mode'] ) ? $post_data['page_mode'] : null;
      $post_data['singlePageMode'] = isset( $post_data['single_page_mode'] ) ? $post_data['single_page_mode'] : null;
      $post_data['pageSize'] = isset( $post_data['page_size'] ) ? $post_data['page_size'] : null;
      $post_data['controlsPosition'] = isset( $post_data['controls_position'] ) ? $post_data['controls_position'] : null;
      $post_data['viewerType'] = isset( $post_data['viewerType'] ) ? $post_data['viewerType'] : null;
      $post_data['autoPlay'] = isset( $post_data['autoplay'] ) ? $post_data['autoplay'] : null;
      $post_data['autoPlayDuration'] = isset( $post_data['autoplay_duration'] ) ? $post_data['autoplay_duration'] : null;
      $post_data['autoPlayStart'] = isset( $post_data['autoplay_start'] ) ? $post_data['autoplay_start'] : null;


      $post_defaults = array(
          'webgl'               => $base->get_default( 'webgl' ),
          'class'               => '',
          'id'                  => '',
          //internal
          'source_type'         => $base->get_default( 'source_type' ),
          'pdf_source'          => '',
          'pdf_thumb'           => '',
          'pages'               => array(),
          'outline'             => '',
          'backgroundColor'     => $base->get_default( 'bg_color' ),
          'height'              => $base->get_default( 'height' ),
          'duration'            => $base->get_default( 'duration' ),
          'hard'                => $base->get_default( 'hard' ),
          'viewerType'          => $base->get_default( 'viewerType' ),
          'autoEnableOutline'   => $base->get_default( 'auto_outline' ),
          'autoEnableThumbnail' => $base->get_default( 'auto_thumbnail' ),
          'overwritePDFOutline' => $base->get_default( 'overwrite_outline' ),
          'backgroundImage'     => $base->get_default( 'bg_image' ),
          'direction'           => $base->get_default( 'direction' ),
          'pageSize'            => $base->get_default( 'page_size' ),
          'pageMode'            => $base->get_default( 'page_mode' ),
          'singlePageMode'      => $base->get_default( 'single_page_mode' ),
          'controlsPosition'    => $base->get_default( 'controls_position' ),
          'soundEnable'         => $base->get_default( 'auto_sound' ),
          'maxTextureSize'      => $base->get_default( 'texture_size' ),
          'autoPlay'            => $base->get_default( 'autoplay' ),
          'autoPlayDuration'    => $base->get_default( 'autoplay_duration' ),
          'autoPlayStart'       => $base->get_default( 'autoplay_start' ),
          'thumb'               => '',
          'source'              => '',
          'wpOptions'           => 'true'
      );

      $post_data = shortcode_atts( $post_defaults, $post_data, 'corporate_book' );
      //			$data      = shortcode_atts( $post_data, $attr, 'corporate_book' );

      $source_type = $post_data['source_type'];
      $pdf_source = $post_data['pdf_source'];
			
      $post_data['source'] = '';

      if ( $source_type == 'pdf' ) {
        $post_data['source'] = $pdf_source;
        $thumb_url = empty( $post_data['pdf_thumb'] ) ? '' : $post_data['pdf_thumb'];
      }

      if ( $source_type == 'image' ) {
        $pages = array_map( 'maybe_unserialize', $post_data['pages'] );
        $source_list = array();
        $links = array();
        $index = 0;
        foreach ( $pages as $image ) {
          if ( $thumb_url === '' ) {
            $thumb_url = $image['url'];
          }
          if ( $image['url'] !== '' ) {
            array_push( $source_list, $image['url'] );
          }
          if ( isset( $image['hotspots'] ) && $image['hotspots'] !== '' ) {
            $links[ $index ] = $image['hotspots'];
          }
          $index ++;
        }
        $post_data['links'] = $links;
        $post_data['source'] = $source_list;
      }

			//cleanup unnecessary data
      if ( $locked ) {
        if ( 'pdf' === $source_type ) {
          $preview_url = $base->preview_url( $post_id );
          $full        = Corporate_Book_Protected_PDF::local_path( $post_id );
          $total       = '' !== $full ? Corporate_Book_Window::count_pages( $full ) : 0;
          if ( $total > 0 ) {
            // Free preview from any page: the server sends every page, but only the window starting where the reader opens is real.
            $shared      = min( $total, Corporate_Book_Window::shared_start( (int) $post_id ) );
            $saved       = min( $total, Corporate_Book_Window::reader_start( $post_id, $shared ? $shared : null ) );
            $preview_url = add_query_arg( 'start', max( 1, $saved ), Corporate_Book_Protected_PDF::url( $post_id ) );
            $cb_window   = array( 'count' => $base->preview_pages( $total ), 'total' => $total, 'start' => max( 1, $saved ), 'fixed' => $saved > 0 );
          }
          if ( '' === $preview_url ) {
            if ( 'thumb' === $atts['type'] && ! ( isset( $attr['shelf'] ) && 'true' === trim( $attr['shelf'] ) ) ) {
              $cover = empty( $thumb_url ) ? '' : '<div class="cb-cover"><img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $title ) . '" loading="lazy"/></div>';
              return '<div class="cb-thumb-card">' . $cover . '<div class="cb-thumb-title">' . esc_html( $title ) . '</div>' . $this->card_price( $post_id ) . '<details class="cb-card-lock"><summary class="cb-preview-button">' . esc_html( $base->get_translate( 'text_preview' ) ) . '</summary>' . $this->lock_box() . '</details>' . $this->card_cart( $post_id ) . '</div>';
            }
            return $this->lock_box();
          }
          $post_data['source'] = $preview_url;
        } elseif ( is_array( $post_data['source'] ) ) {
          $post_data['source'] = array_slice( $post_data['source'], 0, $base->preview_pages( count( $post_data['source'] ) ) );
        }
        $post_data['cbLock']          = $base->lock_options();
        if ( isset( $cb_window ) ) {
          $post_data['cbLock']['window'] = $cb_window;
        }
        // Second way to buy: a printed copy of this book, when it is for sale.
        $post_data['cbLock']['print'] = Corporate_Book_Shop::buy_button( $post_id );
        wp_enqueue_script( 'corporate-book-lock', plugins_url( 'assets/js/corporate-book-lock.js', $base->file ), array( 'jquery' ), $base->version, true );
      }
      // Readers with access load a locked or protected book's full PDF through the protected link, never its file URL.
      if ( ! $locked && 'pdf' === $source_type && ( $base->book_is_locked( $post_id ) || Corporate_Book_Protected_PDF::is_protected( $post_id ) ) && '' !== Corporate_Book_Protected_PDF::local_path( $post_id ) ) {
        $post_data['source'] = Corporate_Book_Protected_PDF::url( $post_id );
      }
      unset( $post_data['pages'] );
      unset( $post_data['pdf_source'] );
      unset( $post_data['pdf_thumb'] );
      unset( $post_data['thumb'] );
      unset( $post_data['source_type'] );
      unset( $post_data['class'] );
      unset( $post_data['id'] );

			//remove any data that is global and is redundant
      foreach ( $post_data as $key => $value ) {
        if ( $value === "" || $value === null || $value == "global" ) {//newly added will be null in old post
          unset( $post_data[ $key ] );
        }
      }
      $html_attr['_slug'] = get_post( $post_id )->post_name;
    }
		else {
      /*handled by new attribute support*/
    }

    //deep-link
    $html_attr['data-title'] = sanitize_title( $title );
    if ( !$multi && isset( $attr['slug'] ) && !empty( $attr['slug'] ) ) {
      $html_attr['slug'] = sanitize_title( $attr['slug'] );
    } else if ( $share_slug == 'true' ) {
      $html_attr['slug'] = get_post( $post_id )->post_name;
    }
		
    if ( empty( $title ) ) {
      $title = "Open Book";
    }

		//Region Escape and output variables
    /*Attribute overrides*/
    $attrHTML = ' ';

    $html_attr['wpoptions'] = 'true';

    if ( !isset( $attr['thumb'] ) && $thumb_url !== '' ) {
      $html_attr['thumb'] = esc_attr( $thumb_url );
    }
    if ( isset( $attr['thumb'] ) ) {
      $html_attr['thumb'] = $attr['thumb'];
    }

    if ( !isset( $attr['thumbtype'] ) ) {
      $html_attr['thumbtype'] = esc_attr( $thumb_tag_type );
    } else {
      $html_attr['thumbtype'] = $attr['thumbtype'];
    }

    //$attr is removed since it can contain insecure and malicious data, atts hold only required keys and sanitized values
    if ( isset( $attr['data-page'] ) ) {
      $html_attr['data-page'] = esc_attr( $attr['data-page'] );
    }
    if ( isset( $attr['source'] ) ) {
      $html_attr['source'] = esc_attr( $attr['source'] );
    }
    if ( isset( $attr["height"] ) ) {
      $html_attr["height"] = $attr["height"];
    }
    if ( isset( $attr["webgl"] ) ) {
      $html_attr["webgl"] = $attr["webgl"];
    }
    foreach ( $html_attr as $key => $value ) {
      $attrHTML .= esc_attr( $key ) . '="' . esc_attr( $value ) . '" ';
    }

    $html = "";
    if ( in_array( $type, array( 'thumb', 'button', 'link', 'custom' ), true ) ) {
      // Link and custom triggers open the same lightbox as a button, without the button look.
      $trigger = in_array( $type, array( 'link', 'custom' ), true ) ? '_df_button cb-trigger-' . $type : '_df_' . $type;
      $label   = 'custom' === $type ? wp_kses_post( $title ) : esc_html( $title );
      $html    = '<div class="' . $trigger . ' ' . esc_attr( $class ) . '" id="' . esc_attr( $id ) . '" ' . $attrHTML . '>' . $label . '</div>';
      // Thumbnails show the book title and a Preview button below the cover (not on a shelf, which lays books out itself).
      if ( 'thumb' === $type && ! ( isset( $attr['shelf'] ) && 'true' === trim( $attr['shelf'] ) ) ) {
        // With a thumbnail the cover is the image itself; it opens the book like the button does.
        if ( ! empty( $thumb_url ) ) {
          $html = '<div class="_df_button cb-trigger-custom cb-cover" id="' . esc_attr( $id ) . '_cover" data-option="option_' . esc_attr( $id ) . '" ' . $attrHTML . '><img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $title ) . '" loading="lazy"/></div>';
        }
        $html = '<div class="cb-thumb-card">' . $html . '<div class="cb-thumb-title">' . esc_html( $title ) . '</div>'
            . $this->free_note( $post_id, 'cb-free-badge' ) . $this->card_price( $post_id )
            . '<div class="cb-thumb-actions"><div class="_df_button cb-preview-button" id="' . esc_attr( $id ) . '_preview" data-option="option_' . esc_attr( $id ) . '" ' . $attrHTML . '>' . esc_html( $base->get_translate( 'text_preview' ) ) . '</div>'
            . $this->card_cart( $post_id ) . '</div></div>';
      }
    }
    else {
      $html = $this->free_note( $post_id, 'cb-free-note' ) . '<div class="_df_book df-lite' . esc_attr( $class ) . '" id="' . esc_attr( $id ) . '" ' . $attrHTML . '></div>';
    }

    if ( count( $post_data ) > 0 ) {
	    
	    //escape saved outline
	    if ( isset( $post_data['outline'] ) && is_array( $post_data['outline'] ) ) {
		    $post_data['outline'] = $this->array_outline_escaped($post_data['outline']);
	    }
			
      // Preview links open readers who may read everything at the linked page too.
      if ( is_numeric( $post_id ) && empty( $post_data['cbLock']['window'] ) && Corporate_Book_Window::shared_start( (int) $post_id ) ) {
        $post_data['openPage'] = Corporate_Book_Window::shared_start( (int) $post_id );
      }
      // Editors get a "Copy preview link from this page" button under the book.
      // Set for every embed: a book and its cover card on one page share the same options object.
      if ( is_numeric( $post_id ) ) {
        $post_data['cbShare'] = 1;
      }
      /*Normally this occurs only when a valid post id is added*/
      $code = 'window.option_' . esc_js($id) . ' = ' . json_encode( $post_data ) . ';'
        // Free preview from any page: the window starts at the page in the link (#cbstart-10 or a share link ending in /10/).
        . '(function(o){var w=o.cbLock&&o.cbLock.window;if(!w){return;}if(w.fixed){o.openPage=w.start;return;}var m=(location.hash||"").match(/(\\d+)\\/?$/),s=m?Math.min(Math.max(parseInt(m[1],10),1),w.total):1;w.start=s;o.openPage=s;o.source=o.source.replace(/([?&]start=)\\d+/,"$1"+s);})(window.option_' . esc_js( $id ) . ');'
        . ' if(window.CORPORATE_BOOK && window.CORPORATE_BOOK.parseBooks){window.CORPORATE_BOOK.parseBooks();}';

      $html .= '<script class="df-shortcode-script" nowprocket type="application/javascript">' . $code . '</script>';

    }

    // Signed-in readers get a line under a locked book: who they are, their subscription, and sign out.
    if ( 'book' === $type && $post != null && is_user_logged_in() && $base->book_is_locked( $post->ID ) ) {
      $html .= Corporate_Book_Account::bar();
    }

    return $html;
  }
  
  
  /**
   * escapes and returns values of an outline array. The values should be text, number and urls only
   *
   * @param array $arr Array to be sanitized and escaped
   *
   * @return array sanitized array
   * @since 2.3.53
   *
   */
  private function array_outline_escaped( $arr = array() ) {
    
    if ( is_null( $arr ) ) {
      return array();
    }
    foreach ( (array) $arr as $k => $val ) {
      if ( is_array( $val ) ) {
        $arr[ $k ] = $this->array_outline_escaped( $val );
      } else if ( $k == "title" ) {
        $arr[ $k ] = esc_html( $val );
      } else if ( $k == "dest" ) {
        if ( is_numeric( $arr[ $k ] ) ) {
          $arr[ $k ] = esc_html( $val );
        } else {
          $arr[ $k ] = esc_url( $val );
        }
      }else{
        return "";
      }
    }
    
    return $arr;
    
  }

  /**
   * Returns the singleton instance of the class.
   *
   * @return object corporateBook_PostType object.
   * @since 1.0.0
   *
   */
  public static function get_instance() {

    if ( !isset( self::$instance )
         && !( self::$instance instanceof Corporate_Book_ShortCode ) ) {
      self::$instance = new Corporate_Book_ShortCode();
    }

    return self::$instance;

  }

}

//Load the Corporate Book Plugin Class
$corporate_book_shortcode = Corporate_Book_ShortCode::get_instance();


