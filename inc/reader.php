<?php
/**
 * Reader extras: saved books (wishlist), "notify me when back in stock", resume reading and bookmarks
 * (kept in the reader's browser), and anonymous reading statistics for the Sales dashboard.
 *
 * @package corporate book
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

class Corporate_Book_Reader {

  const NONCE    = 'corporate_book_reader';
  const WISHLIST = 'corporate_book_wishlist';
  const NOTIFY   = '_cb_notify';
  const STATS    = '_cb_stats';

  public function __construct() {
    add_action( 'wp_enqueue_scripts', array( $this, 'script' ), 20 );
    add_action( 'wp_ajax_corporate_book_wishlist', array( $this, 'ajax_wishlist' ) );
    add_action( 'wp_ajax_corporate_book_notify', array( $this, 'ajax_notify' ) );
    add_action( 'wp_ajax_corporate_book_track', array( $this, 'ajax_track' ) );
    add_action( 'wp_ajax_corporate_book_share', array( $this, 'ajax_share' ) );
    add_action( 'wp_ajax_nopriv_corporate_book_track', array( $this, 'ajax_track' ) );
    add_action( 'update_post_meta', array( $this, 'watch_stock' ), 10, 4 );
    add_action( 'corporate_book_sales_dashboard', array( $this, 'dashboard' ) );
  }

  public function script() {
    $base = Corporate_Book::get_instance();
    wp_enqueue_script( 'corporate-book-reader', plugins_url( 'assets/js/corporate-book-reader.js', $base->file ), array(), $base->version, true );
    wp_localize_script(
      'corporate-book-reader',
      'corporateBookReader',
      array(
        'url'      => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( self::NONCE ),
        'user'     => get_current_user_id(),
        'resume'   => __( 'Continue from page %d?', '3d-corporate-book' ),
        'yes'      => __( 'Continue', '3d-corporate-book' ),
        'no'       => __( 'Start over', '3d-corporate-book' ),
        'mark'     => __( 'Bookmark this page', '3d-corporate-book' ),
        'page'     => __( 'Page %d', '3d-corporate-book' ),
        'saved'    => __( 'Saved', '3d-corporate-book' ),
        'save'     => __( 'Save for later', '3d-corporate-book' ),
        'notified' => __( 'We will email you', '3d-corporate-book' ),
        'signin'   => __( 'Sign in first, from My account.', '3d-corporate-book' ),
        'share'    => __( 'Copy preview link from this page', '3d-corporate-book' ),
        'copied'   => __( 'Link copied. Whoever opens it gets the free preview from this page.', '3d-corporate-book' ),
      )
    );
  }

  // ---- Saved books and back-in-stock emails --------------------------------------------------

  public static function wishlist( $user_id ) {
    $list = get_user_meta( $user_id, self::WISHLIST, true );
    return is_array( $list ) ? array_values( array_unique( array_map( 'intval', $list ) ) ) : array();
  }

  public static function save_button( $book_id ) {
    $saved = is_user_logged_in() && in_array( (int) $book_id, self::wishlist( get_current_user_id() ), true );
    return '<button type="button" class="cb-wish' . ( $saved ? ' cb-wished' : '' ) . '" data-book="' . (int) $book_id . '" aria-pressed="' . ( $saved ? 'true' : 'false' ) . '">' . ( $saved ? '♥ ' . esc_html__( 'Saved', '3d-corporate-book' ) : '♡ ' . esc_html__( 'Save for later', '3d-corporate-book' ) ) . '</button>';
  }

  public static function notify_button( $book_id ) {
    $waiting = (array) get_post_meta( $book_id, self::NOTIFY, true );
    $on      = is_user_logged_in() && in_array( get_current_user_id(), array_map( 'intval', $waiting ), true );
    return '<button type="button" class="cb-notify" data-book="' . (int) $book_id . '"' . ( $on ? ' disabled' : '' ) . '>' . esc_html( $on ? __( 'We will email you', '3d-corporate-book' ) : __( 'Email me when back in stock', '3d-corporate-book' ) ) . '</button>';
  }

  public function ajax_wishlist() {
    check_ajax_referer( self::NONCE );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
    $book = isset( $_POST['book'] ) ? absint( $_POST['book'] ) : 0;
    if ( ! is_user_logged_in() || 'corporate_book' !== get_post_type( $book ) ) {
      wp_send_json_error( __( 'Sign in first, from My account.', '3d-corporate-book' ), 401 );
    }
    $list  = self::wishlist( get_current_user_id() );
    $saved = ! in_array( $book, $list, true );
    $list  = $saved ? array_merge( $list, array( $book ) ) : array_diff( $list, array( $book ) );
    update_user_meta( get_current_user_id(), self::WISHLIST, array_slice( array_values( $list ), -100 ) );
    wp_send_json_success( array( 'saved' => $saved ) );
  }

  public function ajax_notify() {
    check_ajax_referer( self::NONCE );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
    $book = isset( $_POST['book'] ) ? absint( $_POST['book'] ) : 0;
    if ( ! is_user_logged_in() || 'corporate_book' !== get_post_type( $book ) ) {
      wp_send_json_error( __( 'Sign in first, from My account.', '3d-corporate-book' ), 401 );
    }
    $waiting = array_map( 'intval', (array) get_post_meta( $book, self::NOTIFY, true ) );
    $waiting = array_slice( array_values( array_unique( array_merge( array_filter( $waiting ), array( get_current_user_id() ) ) ) ), -500 );
    update_post_meta( $book, self::NOTIFY, $waiting );
    wp_send_json_success();
  }

  /**
   * When a book's stock goes from 0 to more (or to unlimited), emails everyone who asked and clears the list.
   */
  public function watch_stock( $meta_id, $post_id, $key, $value ) {
    if ( '_corporate_book_data' !== $key || ! is_array( $value ) ) {
      return;
    }
    $old = get_post_meta( $post_id, '_corporate_book_data', true );
    if ( ! is_array( $old ) || ! isset( $old['print_stock'] ) || '0' !== (string) $old['print_stock'] || ! isset( $value['print_stock'] ) || '0' === (string) $value['print_stock'] ) {
      return;
    }
    $waiting = array_filter( array_map( 'intval', (array) get_post_meta( $post_id, self::NOTIFY, true ) ) );
    delete_post_meta( $post_id, self::NOTIFY );
    $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    foreach ( $waiting as $user_id ) {
      $user = get_userdata( $user_id );
      if ( $user ) {
        /* translators: 1: site name, 2: book title */
        wp_mail( $user->user_email, sprintf( __( '[%1$s] "%2$s" is back in stock', '3d-corporate-book' ), $site, get_the_title( $post_id ) ), __( 'The printed book you asked about can be ordered again:', '3d-corporate-book' ) . "\n" . Corporate_Book_Shop::cart_url() );
      }
    }
  }

  /**
   * "Saved books" card for My account.
   *
   * @param WP_User $user Reader.
   *
   * @return string HTML.
   */
  public static function wishlist_card( $user ) {
    $rows = '';
    foreach ( self::wishlist( $user->ID ) as $book ) {
      if ( 'publish' !== get_post_status( $book ) ) {
        continue;
      }
      $link  = get_permalink( $book );
      $rows .= '<li><a href="' . esc_url( $link ) . '">' . esc_html( get_the_title( $book ) ) . '</a> ' . Corporate_Book_Shop::buy_button( $book ) . ' ' . self::save_button( $book ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buttons escape their output.
    }
    return '<div class="cb-account cb-profile-card cb-profile-wide" id="cb-sec-saved"><h3>' . esc_html__( 'Saved books', '3d-corporate-book' ) . '</h3>' . ( $rows ? '<ul class="cb-wishlist">' . $rows . '</ul>' : '<p>' . esc_html__( 'Books you save with "Save for later" show here.', '3d-corporate-book' ) . '</p>' ) . '</div>';
  }

  // ---- Reading history (per signed-in reader) -------------------------------------------------

  const READ = 'cb_read';

  /**
   * Books this reader opened: book ID => array( 'page' => furthest page, 'time' => last read ), newest first.
   */
  public static function history( $user_id ) {
    $read = get_user_meta( $user_id, self::READ, true );
    $read = is_array( $read ) ? $read : array();
    uasort( $read, function ( $a, $b ) {
      return $b['time'] <=> $a['time'];
    } );
    return $read;
  }

  public static function remember( $user_id, $book, $page ) {
    $read          = self::history( $user_id );
    $old           = isset( $read[ $book ] ) ? (int) $read[ $book ]['page'] : 0;
    $read[ $book ] = array( 'page' => max( $old, (int) $page ), 'time' => time() );
    update_user_meta( $user_id, self::READ, array_slice( $read, 0, 200, true ) );
  }

  /**
   * "Books you've read" card for My account.
   */
  public static function history_card( $user ) {
    $rows = '';
    foreach ( self::history( $user->ID ) as $book => $info ) {
      if ( 'publish' !== get_post_status( $book ) ) {
        continue;
      }
      $data  = get_post_meta( $book, '_corporate_book_data', true );
      $thumb = is_array( $data ) && ! empty( $data['pdf_thumb'] ) ? $data['pdf_thumb'] : get_the_post_thumbnail_url( $book, 'medium' );
      $link  = get_permalink( $book );
      $rows .= '<li class="cb-read-item"><a href="' . esc_url( $link ) . '">' . ( $thumb ? '<img src="' . esc_url( $thumb ) . '" alt="">' : '' ) . '<strong>' . esc_html( get_the_title( $book ) ) . '</strong></a><span>'
        /* translators: 1: page number, 2: date */
        . esc_html( sprintf( __( 'Reached page %1$d · %2$s', '3d-corporate-book' ), max( 1, $info['page'] ), wp_date( get_option( 'date_format' ), $info['time'] ) ) )
        . '</span><a class="cb-read-go" href="' . esc_url( $link ) . '">' . esc_html__( 'Continue reading', '3d-corporate-book' ) . '</a></li>';
    }
    return '<div class="cb-account cb-profile-card cb-profile-wide" id="cb-sec-read"><h3>' . esc_html__( "Books you've read", '3d-corporate-book' ) . '</h3>' . ( $rows ? '<ul class="cb-read-list">' . $rows . '</ul>' : '<p>' . esc_html__( 'Books you open show here, so you can pick up where you left off.', '3d-corporate-book' ) . '</p>' ) . '</div>';
  }

  // ---- Reading statistics --------------------------------------------------------------------

  /**
   * Counts a reading event: open (a reader opened the book), page (a reader reached this page for the
   * first time in this visit) or locked (a reader reached the end of the free preview). Anonymous.
   */
  /**
   * Preview link from a page, for the book's editors: whoever opens it gets the free preview from that page.
   */
  public function ajax_share() {
    check_ajax_referer( self::NONCE );
    $book = isset( $_POST['book'] ) ? absint( $_POST['book'] ) : 0;
    $page = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
    $url  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
    if ( 'corporate_book' !== get_post_type( $book ) || ! current_user_can( 'edit_post', $book ) ) {
      wp_send_json_error( __( 'Only the book\'s editors can make preview links.', '3d-corporate-book' ), 403 );
    }
    // Only links to this site; anything else falls back to the book's own page.
    $url = wp_validate_redirect( strtok( $url, '#' ), get_permalink( $book ) );
    wp_send_json_success( array( 'url' => Corporate_Book_Window::share_url( $book, $page, $url ) ) );
  }

  public function ajax_track() {
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- anonymous counter; nothing private is read or changed.
    $book  = isset( $_POST['book'] ) ? absint( $_POST['book'] ) : 0;
    $event = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : '';
    $page  = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 0;
    // phpcs:enable
    if ( 'corporate_book' !== get_post_type( $book ) || 'publish' !== get_post_status( $book ) || ! in_array( $event, array( 'open', 'page', 'locked' ), true ) || $page > 3000 ) {
      wp_send_json_error( null, 400 );
    }
    $stats = self::stats( $book );
    if ( 'page' === $event && $page > 0 ) {
      $stats['reach'][ $page ] = ( isset( $stats['reach'][ $page ] ) ? $stats['reach'][ $page ] : 0 ) + 1;
    } elseif ( 'open' === $event ) {
      $stats['opens']++;
    } elseif ( 'locked' === $event ) {
      $stats['locked']++;
    }
    update_post_meta( $book, self::STATS, $stats );
    if ( get_current_user_id() && 'locked' !== $event ) {
      self::remember( get_current_user_id(), $book, $page );
    }
    wp_send_json_success();
  }

  public static function stats( $book ) {
    $stats = get_post_meta( $book, self::STATS, true );
    return ( is_array( $stats ) ? $stats : array() ) + array( 'opens' => 0, 'locked' => 0, 'reach' => array() );
  }

  public function dashboard() {
    $rows = array();
    foreach ( get_posts( array( 'post_type' => 'corporate_book', 'post_status' => 'publish', 'numberposts' => 500, 'fields' => 'ids' ) ) as $book ) {
      $s = self::stats( $book );
      if ( $s['opens'] ) {
        $rows[] = array( $book, $s );
      }
    }
    usort( $rows, function ( $a, $b ) {
      return $b[1]['opens'] <=> $a[1]['opens'];
    } );
    $html = '';
    foreach ( array_slice( $rows, 0, 30 ) as $row ) {
      list( $book, $s ) = $row;
      ksort( $s['reach'] );
      $far   = $s['reach'] ? max( array_keys( $s['reach'] ) ) : 0;
      $html .= '<tr><td><a href="' . esc_url( get_edit_post_link( $book ) ) . '">' . esc_html( get_the_title( $book ) ) . '</a></td><td>' . (int) $s['opens'] . '</td><td>' . esc_html( number_format( array_sum( $s['reach'] ) / max( 1, $s['opens'] ), 1 ) ) . '</td><td>' . (int) $far . '</td><td>' . (int) $s['locked'] . ' (' . esc_html( round( 100 * $s['locked'] / max( 1, $s['opens'] ) ) ) . '%)</td></tr>';
    }
    echo '<h2>' . esc_html__( 'Reading', '3d-corporate-book' ) . '</h2><p>' . esc_html__( 'Anonymous counts from readers\' browsers.', '3d-corporate-book' ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Book', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Opened', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Average pages read', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Furthest page', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Reached the end of the free preview', '3d-corporate-book' ) . '</th></tr></thead><tbody>'
      . ( $html ? $html : '<tr><td colspan="5">' . esc_html__( 'No reading yet.', '3d-corporate-book' ) . '</td></tr>' ) . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
  }
}

new Corporate_Book_Reader();
