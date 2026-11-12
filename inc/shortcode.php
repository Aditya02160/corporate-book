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
      $html = '<div class="corporate-book-books">';
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
   * Sign-in box shown to guests instead of a locked book.
   *
   * @return string HTML.
   */
  public function sign_in_box() {
    $base = $this->base;
    $here = get_permalink() ? get_permalink() : home_url( '/' );
    $html = '<div class="cb-sign-in"><p>' . esc_html( $base->get_translate( 'text_sign_in' ) ) . '</p>';
    $html .= '<a class="cb-button" href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Sign in', '3d-corporate-book' ) . '</a> ';
    if ( get_option( 'users_can_register' ) ) {
      $html .= '<a class="cb-button" href="' . esc_url( wp_registration_url() ) . '">' . esc_html__( 'Sign up', '3d-corporate-book' ) . '</a>';
    }
    $login_shortcode = (string) $base->get_config( 'subscription_login_shortcode' );
    if ( '' !== $login_shortcode ) {
      $html .= '<div class="cb-social-login">' . do_shortcode( $login_shortcode ) . '</div>';
    }
    return $html . '</div>';
  }

  /**
   * Lock box shown to non-subscribers when a locked PDF book has no preview file.
   *
   * @return string HTML.
   */
  public function lock_box() {
    $lock = $this->base->lock_options();
    $html = '<div class="cb-lock-box"><p>' . esc_html( $lock['button'] ) . '</p>';
    if ( '' !== $lock['url'] ) {
      $html .= '<a class="cb-button" href="' . esc_url( $lock['url'] ) . '">' . esc_html( $lock['button'] ) . '</a>';
    }
    return $html . '</div>';
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
	    // Subscription lock: guests sign in first; non-subscribers get the preview only.
	    $locked = ! $base->user_has_access( $post_id );
	    if ( $locked ) {
		    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			    define( 'DONOTCACHEPAGE', true );
		    }
		    if ( ! is_user_logged_in() ) {
			    return $this->sign_in_box();
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
      $post_data['enableDownload'] = isset( $post_data['enable_download'] ) ? $post_data['enable_download'] : null;
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
          'enableDownload'      => $base->get_default( 'enable_download' ),
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
          if ( '' === $preview_url ) {
            return $this->lock_box();
          }
          $post_data['source'] = $preview_url;
        } elseif ( is_array( $post_data['source'] ) ) {
          $post_data['source'] = array_slice( $post_data['source'], 0, $base->preview_pages( count( $post_data['source'] ) ) );
        }
        $post_data['cbLock'] = $base->lock_options();
        wp_enqueue_script( 'corporate-book-lock', plugins_url( 'assets/js/corporate-book-lock.js', $base->file ), array( 'jquery' ), $base->version, true );
      }
      // Readers with access load a locked book's full PDF through the protected link, never its file URL.
      if ( ! $locked && 'pdf' === $source_type && $base->book_is_locked( $post_id ) && '' !== Corporate_Book_Protected_PDF::local_path( $post_id ) ) {
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
    if ( $type == 'thumb' || $type == 'button' ) {
      $html = '<div class="_df_' . $type . ' ' . esc_attr( $class ) . '" id="' . esc_attr( $id ) . '" ' . $attrHTML . '>' . esc_attr( $title ) . '</div>';
    }
    else {
      $html = '<div class="_df_book df-lite' . esc_attr( $class ) . '" id="' . esc_attr( $id ) . '" ' . $attrHTML . '></div>';
    }

    if ( count( $post_data ) > 0 ) {
	    
	    //escape saved outline
	    if(is_array($post_data['outline'])){
		    $post_data['outline'] = $this->array_outline_escaped($post_data['outline']);
	    }
			
      /*Normally this occurs only when a valid post id is added*/
      $code = 'window.option_' . esc_js($id) . ' = ' . json_encode( $post_data ) . '; if(window.CORPORATE_BOOK && window.CORPORATE_BOOK.parseBooks){window.CORPORATE_BOOK.parseBooks();}';

      $html .= '<script class="df-shortcode-script" nowprocket type="application/javascript">' . $code . '</script>';

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


