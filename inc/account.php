<?php
/**
 * Reader accounts and paid subscriptions inside Corporate Book.
 *
 * Readers sign in, sign up and sign out without leaving the book, and buy a plan through
 * Razorpay Checkout. A paid plan gives access until a date stored on the user.
 *
 * @package corporate book
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

class Corporate_Book_Account {

  const NONCE  = 'corporate_book_account';
  const UNTIL  = 'corporate_book_subscription_until';
  const ORDERS = 'corporate_book_orders';
  const ROLE   = 'cb_reader';

  public function __construct() {
    foreach ( array( 'signin', 'signup' ) as $action ) {
      add_action( 'wp_ajax_nopriv_corporate_book_' . $action, array( $this, $action ) );
      add_action( 'wp_ajax_corporate_book_' . $action, array( $this, $action ) );
    }
    foreach ( array( 'lost', 'reset', 'google' ) as $action ) {
      add_action( 'wp_ajax_nopriv_corporate_book_' . $action, array( $this, $action ) );
      add_action( 'wp_ajax_corporate_book_' . $action, array( $this, $action ) );
    }
    add_action( 'wp_ajax_corporate_book_profile', array( $this, 'save_profile' ) );
    add_action( 'wp_ajax_corporate_book_password', array( $this, 'change_password' ) );
    add_shortcode( 'corporate_book_profile', array( $this, 'profile_page' ) );
    add_action( 'admin_init', array( $this, 'create_profile_page' ) );
    // Readers live on the front end only: no toolbar, no dashboard, no wp-login screens.
    if ( ! get_role( self::ROLE ) ) {
      add_role( self::ROLE, __( 'Corporate Book Reader', '3d-corporate-book' ), array( 'read' => true ) );
    }
    add_filter( 'show_admin_bar', array( $this, 'hide_toolbar' ) );
    add_action( 'admin_init', array( $this, 'keep_out_of_dashboard' ), 1 );
    add_filter( 'login_redirect', array( $this, 'login_redirect' ), 10, 3 );
    add_action( 'login_init', array( $this, 'keep_out_of_wp_login' ) );
    add_action( 'wp_ajax_corporate_book_razorpay_test', array( $this, 'razorpay_test' ) );
    add_action( 'wp_ajax_nopriv_corporate_book_webhook', array( $this, 'webhook' ) );
    add_action( 'wp_ajax_corporate_book_webhook', array( $this, 'webhook' ) );
    add_action( 'corporate_book_reminders', array( $this, 'send_reminders' ) );
    if ( ! wp_next_scheduled( 'corporate_book_reminders' ) ) {
      wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'corporate_book_reminders' );
    }
    add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
    add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
    add_action( 'wp_ajax_corporate_book_order', array( $this, 'order' ) );
    add_action( 'wp_ajax_corporate_book_verify', array( $this, 'verify' ) );
    add_shortcode( 'corporate_book_account', array( $this, 'shortcode' ) );
    add_shortcode( 'corporate_book_subscription', array( $this, 'subscription_page' ) );
    add_action( 'wp_body_open', array( $this, 'site_header' ) );
    add_action( 'admin_init', array( $this, 'create_subscription_page' ) );
    add_filter( 'manage_users_columns', array( $this, 'users_column' ) );
    add_filter( 'manage_users_custom_column', array( $this, 'users_column_value' ), 10, 3 );
    add_action( 'show_user_profile', array( $this, 'profile_field' ) );
    add_action( 'edit_user_profile', array( $this, 'profile_field' ) );
    add_action( 'personal_options_update', array( $this, 'save_profile_field' ) );
    add_action( 'edit_user_profile_update', array( $this, 'save_profile_field' ) );
  }

  private static function config( $key ) {
    return (string) Corporate_Book::get_instance()->get_config( $key );
  }

  /**
   * Time until which a user's paid subscription runs (0 when none).
   *
   * @param int $user_id User ID.
   *
   * @return int Unix time.
   */
  public static function until( $user_id ) {
    return (int) get_user_meta( $user_id, self::UNTIL, true );
  }

  public static function is_active( $user_id ) {
    return self::until( $user_id ) > time();
  }

  /**
   * Plans from the settings, one per line: Name | Price | Days of access.
   *
   * @return array[] Each with name, price and days.
   */
  public static function plans() {
    $plans = array();
    foreach ( preg_split( '/\r\n|\r|\n/', self::config( 'subscription_plans' ) ) as $line ) {
      $parts = array_map( 'trim', explode( '|', $line ) );
      if ( 3 === count( $parts ) && '' !== $parts[0] && is_numeric( $parts[1] ) && $parts[1] > 0 && ctype_digit( $parts[2] ) && $parts[2] > 0 ) {
        $plans[] = array( 'name' => $parts[0], 'price' => (float) $parts[1], 'days' => (int) $parts[2] );
      }
    }
    return $plans;
  }

  public static function razorpay_ready() {
    return '' !== self::config( 'razorpay_key_id' ) && '' !== self::config( 'razorpay_key_secret' ) && array() !== self::plans();
  }

  public static function price( $amount ) {
    $number = rtrim( rtrim( number_format( $amount, 2, '.', ',' ), '0' ), '.' );
    $symbols = array( 'INR' => "\u{20B9}", 'USD' => '$', 'EUR' => "\u{20AC}", 'GBP' => "\u{00A3}" );
    $code    = self::config( 'subscription_currency' );
    return isset( $symbols[ $code ] ) ? $symbols[ $code ] . $number : $code . ' ' . $number;
  }

  private static function date( $time ) {
    return date_i18n( get_option( 'date_format' ), $time );
  }

  private static function here() {
    return get_permalink() ? get_permalink() : home_url( '/' );
  }

  private static function orders( $user_id ) {
    $orders = get_user_meta( $user_id, self::ORDERS, true );
    return is_array( $orders ) ? $orders : array();
  }

  /**
   * Sign-in and sign-up forms for guests; plans, status and sign out for signed-in readers.
   *
   * @param bool $with_plans Whether signed-in readers get the plan buttons here.
   *
   * @return string HTML.
   */
  public static function box( $with_plans = true ) {
    $base = Corporate_Book::get_instance();
    if ( ! wp_script_is( 'corporate-book-account', 'enqueued' ) ) {
      wp_enqueue_script( 'corporate-book-account', plugins_url( 'assets/js/corporate-book-account.js', $base->file ), array(), $base->version, true );
      wp_localize_script(
        'corporate-book-account',
        'corporateBookAccount',
        array(
          'url'       => admin_url( 'admin-ajax.php' ),
          'nonce'     => wp_create_nonce( self::NONCE ),
          'failed'    => __( 'Something went wrong. Try again.', '3d-corporate-book' ),
          'cancelled' => __( 'Payment cancelled.', '3d-corporate-book' ),
        )
      );
    }
    $link = self::config( 'subscription_url' );
    if ( '' === $link && get_option( 'corporate_book_subscription_page' ) ) {
      $link = (string) get_permalink( (int) get_option( 'corporate_book_subscription_page' ) );
    }
    // The subscription page lists the plans itself, so its box needs no Subscribe link.
    $link = $with_plans && '' !== $link && ! self::razorpay_ready()
      ? '<a class="cb-lock-subscribe" href="' . esc_url( $link ) . '">' . esc_html( $base->get_translate( 'text_subscribe_button' ) ) . '</a>'
      : '';
    $html = '<div class="cb-account">';

    if ( ! is_user_logged_in() ) {
      $signup = 'true' === self::config( 'subscription_signup' );
      $html  .= '' !== $link ? $link . '<div class="cb-lock-or">' . esc_html__( 'OR', '3d-corporate-book' ) . '</div>' : '';
      $html  .= '<form class="cb-signin" method="post" data-action="corporate_book_signin">'
        . '<input type="text" name="log" required autocomplete="username" placeholder="' . esc_attr__( 'Email or username', '3d-corporate-book' ) . '" aria-label="' . esc_attr__( 'Email or username', '3d-corporate-book' ) . '"/>'
        . '<input type="password" name="pwd" required autocomplete="current-password" placeholder="' . esc_attr__( 'Password', '3d-corporate-book' ) . '" aria-label="' . esc_attr__( 'Password', '3d-corporate-book' ) . '"/>'
        . '<button type="submit" class="cb-lock-signin">' . esc_html( $base->get_translate( 'text_sign_in' ) ) . '</button>'
        . '<p class="cb-account-links"><a href="' . esc_url( add_query_arg( 'cb_lost', '1', self::profile_url() ) ) . '">' . esc_html__( 'Forgot password?', '3d-corporate-book' ) . '</a>'
        . ( $signup ? ' &middot; <a href="#" class="cb-show-signup">' . esc_html__( 'Create an account', '3d-corporate-book' ) . '</a>' : '' ) . '</p></form>';
      if ( $signup ) {
        $html .= '<form class="cb-signup" method="post" data-action="corporate_book_signup" hidden>'
          . '<input type="email" name="email" required autocomplete="email" placeholder="' . esc_attr__( 'Email', '3d-corporate-book' ) . '" aria-label="' . esc_attr__( 'Email', '3d-corporate-book' ) . '"/>'
          . '<input type="password" name="pwd" required minlength="8" autocomplete="new-password" placeholder="' . esc_attr__( 'Password (8 characters or more)', '3d-corporate-book' ) . '" aria-label="' . esc_attr__( 'Password', '3d-corporate-book' ) . '"/>'
          . '<button type="submit" class="cb-lock-signin">' . esc_html__( 'Create account', '3d-corporate-book' ) . '</button>'
          . '<p class="cb-account-links"><a href="#" class="cb-show-signin">' . esc_html__( 'I already have an account', '3d-corporate-book' ) . '</a></p></form>';
      }
      if ( '' !== self::config( 'google_client_id' ) ) {
        wp_enqueue_script( 'google-gsi', 'https://accounts.google.com/gsi/client', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google serves one current version.
        $html .= '<div class="cb-google" data-client="' . esc_attr( self::config( 'google_client_id' ) ) . '"></div>';
      }
      $login_shortcode = self::config( 'subscription_login_shortcode' );
      $html           .= '' !== $login_shortcode ? '<div class="cb-lock-login">' . do_shortcode( $login_shortcode ) . '</div>' : '';
    } else {
      $user = wp_get_current_user();
      if ( ! self::is_active( $user->ID ) && self::razorpay_ready() ) {
        self::reconcile( $user->ID );
      }
      if ( self::is_active( $user->ID ) ) {
        /* translators: %s: date */
        $html .= '<p class="cb-account-status">' . esc_html( sprintf( __( 'Subscription active until %s.', '3d-corporate-book' ), self::date( self::until( $user->ID ) ) ) ) . '</p>';
      } elseif ( self::razorpay_ready() && $with_plans ) {
        foreach ( self::plans() as $index => $plan ) {
          /* translators: 1: plan name, 2: price, 3: days of access */
          $label = sprintf( __( '%1$s: %2$s for %3$d days', '3d-corporate-book' ), $plan['name'], self::price( $plan['price'] ), $plan['days'] );
          $html .= '<button type="button" class="cb-plan" data-plan="' . (int) $index . '">' . esc_html( $label ) . '</button>';
        }
      } elseif ( ! self::razorpay_ready() ) {
        $html .= $link;
      }
      /* translators: %s: reader's name */
      $html .= '<p class="cb-account-links">' . esc_html( sprintf( __( 'Signed in as %s', '3d-corporate-book' ), $user->display_name ) )
        . ' &middot; <a class="cb-my-account" href="' . esc_url( self::profile_url() ) . '">' . esc_html__( 'My account', '3d-corporate-book' ) . '</a>'
        . ' &middot; <a class="cb-signout" href="' . esc_url( wp_logout_url( self::here() ) ) . '">' . esc_html__( 'Sign out', '3d-corporate-book' ) . '</a></p>';
    }
    return $html . '<p class="cb-account-message" role="alert"></p></div>';
  }

  /**
   * One line under a locked book for signed-in readers: who they are, their subscription, sign out.
   *
   * @return string HTML.
   */
  public static function bar() {
    $user = wp_get_current_user();
    /* translators: %s: reader's name */
    $text = sprintf( __( 'Signed in as %s', '3d-corporate-book' ), $user->display_name );
    if ( self::is_active( $user->ID ) ) {
      /* translators: %s: date */
      $text .= ' · ' . sprintf( __( 'Subscription active until %s', '3d-corporate-book' ), self::date( self::until( $user->ID ) ) );
    }
    return '<p class="cb-account-bar">' . esc_html( $text ) . ' &middot; <a class="cb-my-account" href="' . esc_url( self::profile_url() ) . '">' . esc_html__( 'My account', '3d-corporate-book' ) . '</a> &middot; <a class="cb-signout" href="' . esc_url( wp_logout_url( self::here() ) ) . '">' . esc_html__( 'Sign out', '3d-corporate-book' ) . '</a></p>';
  }

  /**
   * [corporate_book_account]: the account box on any page.
   *
   * @return string HTML.
   */
  public function shortcode() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
      define( 'DONOTCACHEPAGE', true );
    }
    wp_enqueue_style( 'corporate-book-style' );
    return '<div class="cb-lock cb-lock-static"><div class="cb-lock-box">' . self::box() . '</div></div>';
  }

  /**
   * [corporate_book_subscription]: the subscription page. One card per plan with its price and a
   * Subscribe button, and below it sign in / sign up for guests or the reader's status and sign out.
   *
   * @return string HTML.
   */
  public function subscription_page() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
      define( 'DONOTCACHEPAGE', true );
    }
    wp_enqueue_style( 'corporate-book-style' );
    $base   = Corporate_Book::get_instance();
    $box    = self::box( false );
    $active = is_user_logged_in() && self::is_active( get_current_user_id() );
    $cards  = '';
    foreach ( self::plans() as $index => $plan ) {
      /* translators: %d: days of access */
      $days = sprintf( _n( '%d day of access', '%d days of access', $plan['days'], '3d-corporate-book' ), $plan['days'] );
      $external = self::config( 'subscription_url' );
      if ( ! is_user_logged_in() ) {
        $action = '<a class="cb-plan-signin" href="#cb-plans-account">' . esc_html__( 'Sign in to subscribe', '3d-corporate-book' ) . '</a>';
      } elseif ( self::razorpay_ready() ) {
        // Subscribers can buy more days; they are added after the current end date.
        $action = '<button type="button" class="cb-plan" data-plan="' . (int) $index . '">' . esc_html( $active ? __( 'Extend', '3d-corporate-book' ) : $base->get_translate( 'text_subscribe_button' ) ) . '</button>';
      } elseif ( '' !== $external && false === strpos( $external, (string) get_permalink() ) ) {
        $action = '<a class="cb-plan-signin" href="' . esc_url( $external ) . '">' . esc_html( $base->get_translate( 'text_subscribe_button' ) ) . '</a>';
      } else {
        $action = '<button type="button" class="cb-plan" disabled>' . esc_html( $base->get_translate( 'text_subscribe_button' ) ) . '</button><p class="cb-plan-note">'
          . esc_html( current_user_can( 'manage_options' ) ? __( 'Admin: add your Razorpay keys in Global Settings > Subscription to sell plans.', '3d-corporate-book' ) : __( 'Online payment opens soon.', '3d-corporate-book' ) ) . '</p>';
      }
      $cards .= '<div class="cb-plan-card"><h3>' . esc_html( $plan['name'] ) . '</h3><div class="cb-plan-price">' . esc_html( self::price( $plan['price'] ) ) . '</div><div class="cb-plan-days">' . esc_html( $days ) . '</div>' . $action . '</div>';
    }
    $gift = is_user_logged_in() && self::razorpay_ready() && ! $active ? '<p class="cb-gift-sub"><label><input type="checkbox" class="cb-gift-check"/> ' . esc_html__( 'Buy it as a gift for someone else', '3d-corporate-book' ) . '</label> <input type="email" id="cb-gift-email" placeholder="' . esc_attr__( "Their email address", '3d-corporate-book' ) . '" hidden/></p>' : '';
    return '<div class="cb-ui cb-account cb-plans-page"><h2 class="cb-plans-heading">' . esc_html__( 'Digital access', '3d-corporate-book' ) . '</h2>' . $gift . '<div class="cb-plans">' . $cards . '</div>' . Corporate_Book_Shop::print_section() . '<div id="cb-plans-account" class="cb-lock cb-lock-static"><div class="cb-lock-box">' . $box . '</div></div></div>';
  }

  /**
   * Creates the "Subscription" page once, the first time an administrator opens the dashboard.
   */
  public function create_subscription_page() {
    if ( get_option( 'corporate_book_subscription_page' ) || ! current_user_can( 'manage_options' ) ) {
      return;
    }
    $page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => __( 'Subscription', '3d-corporate-book' ), 'post_name' => 'subscription', 'post_content' => '[corporate_book_subscription]' ) );
    // Remembered even when the page is deleted later, so it is not created again.
    update_option( 'corporate_book_subscription_page', $page ? (int) $page : -1 );
  }

  // ---- Readers stay on the front end ---------------------------------------------------------

  /**
   * Whether a user is a reader rather than site staff (staff can write posts).
   *
   * @param WP_User|int|null $user User; the current user when null.
   *
   * @return bool
   */
  private static function is_reader( $user = null ) {
    $user = null === $user ? wp_get_current_user() : ( $user instanceof WP_User ? $user : get_userdata( (int) $user ) );
    return $user && $user->exists() && ! user_can( $user, 'edit_posts' );
  }

  /**
   * URL of the My account page.
   *
   * @return string
   */
  /**
   * Bar at the top of every front page: All Books, Subscription, Cart, My Account and a book search.
   */
  public function site_header() {
    if ( 'true' !== self::config( 'site_header' ) || is_admin() ) {
      return;
    }
    $books = (string) get_post_type_archive_link( 'corporate_book' );
    $sub   = (int) get_option( 'corporate_book_subscription_page' );
    $links = array(
      'books'   => array( $books, __( 'All Books', '3d-corporate-book' ) ),
      'sub'     => array( $sub ? (string) get_permalink( $sub ) : '', __( 'Subscription', '3d-corporate-book' ) ),
      'cart'    => array( 'true' === self::config( 'print_enable' ) && get_option( 'corporate_book_cart_page' ) ? Corporate_Book_Shop::cart_url() : '', __( 'Cart', '3d-corporate-book' ) ),
      'account' => array( self::profile_url(), is_user_logged_in() ? __( 'My Account', '3d-corporate-book' ) : __( 'Sign in', '3d-corporate-book' ) ),
    );
    $nav = '';
    foreach ( $links as $key => $link ) {
      if ( '' !== $link[0] ) {
        $nav .= '<a class="cb-head-' . $key . '" href="' . esc_url( $link[0] ) . '">' . esc_html( $link[1] ) . ( 'cart' === $key ? ' <span class="cb-head-count" hidden></span>' : '' ) . '</a>';
      }
    }
    $query = isset( $_GET['cb_q'] ) ? sanitize_text_field( wp_unslash( $_GET['cb_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public search.
    echo '<div class="cb-site-head"><div class="cb-site-head-in"><a class="cb-site-name" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a>'
      . ( $books ? '<form class="cb-head-search" role="search" method="get" action="' . esc_url( $books ) . '"><input type="search" name="cb_q" value="' . esc_attr( $query ) . '" placeholder="' . esc_attr__( 'Search books…', '3d-corporate-book' ) . '" aria-label="' . esc_attr__( 'Search books', '3d-corporate-book' ) . '"><button type="submit">' . esc_html__( 'Search', '3d-corporate-book' ) . '</button></form>' : '' )
      . '<nav class="cb-head-nav" aria-label="' . esc_attr__( 'Corporate Book', '3d-corporate-book' ) . '">' . $nav . '</nav></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
  }

  public static function profile_url() {
    $page = (int) get_option( 'corporate_book_profile_page' );
    $url  = $page > 0 ? get_permalink( $page ) : '';
    return $url ? $url : home_url( '/' );
  }

  public function hide_toolbar( $show ) {
    return self::is_reader() ? false : $show;
  }

  public function keep_out_of_dashboard() {
    if ( wp_doing_ajax() || ! self::is_reader() ) {
      return;
    }
    wp_safe_redirect( self::profile_url() );
    exit;
  }

  public function login_redirect( $redirect, $requested, $user ) {
    if ( $user instanceof WP_User && self::is_reader( $user ) && ( '' === $requested || false !== strpos( $redirect, admin_url() ) ) ) {
      return self::profile_url();
    }
    return $redirect;
  }

  /**
   * Readers never see wp-login.php: sign up, lost password and reset happen on My account.
   * The plain sign-in form stays for site staff.
   */
  public function keep_out_of_wp_login() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which page to show.
    $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
    if ( in_array( $action, array( 'register', 'lostpassword', 'retrievepassword', 'rp', 'resetpass' ), true ) ) {
      wp_safe_redirect( 'register' === $action ? self::profile_url() : add_query_arg( 'cb_lost', '1', self::profile_url() ) );
      exit;
    }
    if ( 'login' === $action && self::is_reader() ) {
      wp_safe_redirect( self::profile_url() );
      exit;
    }
  }

  /**
   * Creates the "My account" page once, the first time an administrator opens the dashboard.
   */
  public function create_profile_page() {
    if ( get_option( 'corporate_book_profile_page' ) || ! current_user_can( 'manage_options' ) ) {
      return;
    }
    $page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => __( 'My account', '3d-corporate-book' ), 'post_name' => 'my-account', 'post_content' => '[corporate_book_profile]' ) );
    update_option( 'corporate_book_profile_page', $page ? (int) $page : -1 );
  }

  private static function field( $name, $label, $value, $type = 'text', $extra = '' ) {
    return '<label class="cb-field"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . $extra . '/></label>';
  }

  /**
   * [corporate_book_profile]: the reader's account page. Guests sign in, sign up or reset their password;
   * readers edit their details, change their password, see their subscription and payments, and subscribe.
   *
   * @return string HTML.
   */
  public function profile_page() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
      define( 'DONOTCACHEPAGE', true );
    }
    wp_enqueue_style( 'corporate-book-style' );
    $box = self::box( false ); // also loads the account script
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- these only pick which form to show.
    if ( ! is_user_logged_in() ) {
      if ( isset( $_GET['cb_reset'], $_GET['login'] ) ) {
        $form = '<form class="cb-reset" method="post" data-action="corporate_book_reset"><h3>' . esc_html__( 'Choose a new password', '3d-corporate-book' ) . '</h3>'
          . '<input type="hidden" name="key" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['cb_reset'] ) ) ) . '"/>'
          . '<input type="hidden" name="login" value="' . esc_attr( sanitize_user( wp_unslash( $_GET['login'] ) ) ) . '"/>'
          . self::field( 'pwd', __( 'New password (8 characters or more)', '3d-corporate-book' ), '', 'password', 'required minlength="8" autocomplete="new-password"' )
          . '<button type="submit">' . esc_html__( 'Save password', '3d-corporate-book' ) . '</button></form>';
      } elseif ( isset( $_GET['cb_lost'] ) ) {
        $form = '<form class="cb-lost" method="post" data-action="corporate_book_lost"><h3>' . esc_html__( 'Forgot your password?', '3d-corporate-book' ) . '</h3>'
          . '<p>' . esc_html__( 'Enter your email and we will send you a link to choose a new one.', '3d-corporate-book' ) . '</p>'
          . self::field( 'email', __( 'Email', '3d-corporate-book' ), '', 'email', 'required autocomplete="email"' )
          . '<button type="submit">' . esc_html__( 'Send link', '3d-corporate-book' ) . '</button>'
          . '<p class="cb-account-links"><a href="' . esc_url( self::profile_url() ) . '">' . esc_html__( 'Back to sign in', '3d-corporate-book' ) . '</a></p></form>';
      } else {
        return '<div class="cb-ui cb-profile cb-profile-guest"><div class="cb-lock cb-lock-static"><div class="cb-lock-box">' . $box . '</div></div></div>';
      }
      return '<div class="cb-ui cb-profile cb-profile-guest"><div class="cb-account cb-profile-card">' . $form . '<p class="cb-account-message" role="alert"></p></div></div>';
    }
    // phpcs:enable
    $user = wp_get_current_user();
    if ( ! self::is_active( $user->ID ) && self::razorpay_ready() ) {
      self::reconcile( $user->ID );
    }
    if ( self::is_active( $user->ID ) ) {
      /* translators: %s: date */
      $status = '<p class="cb-account-status">' . esc_html( sprintf( __( 'Your subscription is active until %s.', '3d-corporate-book' ), self::date( self::until( $user->ID ) ) ) ) . '</p>';
    } else {
      $status = '<p class="cb-account-status cb-inactive">' . esc_html__( 'You have no active subscription.', '3d-corporate-book' ) . '</p>';
    }
    $plans = '';
    if ( self::razorpay_ready() ) {
      foreach ( self::plans() as $index => $plan ) {
        /* translators: 1: plan name, 2: price, 3: days of access */
        $label  = sprintf( __( '%1$s: %2$s for %3$d days', '3d-corporate-book' ), $plan['name'], self::price( $plan['price'] ), $plan['days'] );
        $plans .= '<button type="button" class="cb-plan" data-plan="' . (int) $index . '">' . esc_html( $label ) . '</button>';
      }
      $plans = '<div class="cb-profile-plans"><p>' . esc_html( self::is_active( $user->ID ) ? __( 'Extend your subscription:', '3d-corporate-book' ) : __( 'Choose a plan:', '3d-corporate-book' ) ) . '</p>' . $plans . '</div>';
    } else {
      $sub   = (int) get_option( 'corporate_book_subscription_page' );
      $link  = self::config( 'subscription_url' ) ? self::config( 'subscription_url' ) : ( $sub > 0 ? get_permalink( $sub ) : '' );
      $plans = $link ? '<p><a class="cb-plan-signin" href="' . esc_url( $link ) . '">' . esc_html__( 'See subscription plans', '3d-corporate-book' ) . '</a></p>' : '';
    }
    $rows = '';
    foreach ( array_reverse( self::orders( $user->ID ), true ) as $order ) {
      $rows .= '<tr><td>' . esc_html( self::date( (int) $order['time'] ) ) . '</td><td>' . esc_html( $order['plan'] ) . '</td><td>' . esc_html( self::price( $order['amount'] / 100 ) ) . '</td><td>'
        . esc_html( $order['paid'] ? __( 'Paid', '3d-corporate-book' ) : __( 'Not paid', '3d-corporate-book' ) ) . '</td></tr>';
    }
    $orders = '<table class="cb-profile-orders"><thead><tr><th>' . esc_html__( 'Date', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Plan', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Amount', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Status', '3d-corporate-book' ) . '</th></tr></thead><tbody>'
      . ( '' !== $rows ? $rows : '<tr><td colspan="4">' . esc_html__( 'No payments yet.', '3d-corporate-book' ) . '</td></tr>' ) . '</tbody></table>';
    $message = '<p class="cb-account-message" role="alert"></p>';
    return '<div class="cb-ui cb-dash">' . self::dash_nav() . '<div class="cb-dash-main">' . self::dash_header( $user ) . '<div class="cb-profile">'
      . '<div class="cb-account cb-profile-card" id="cb-sec-subscription"><h3>' . esc_html__( 'Subscription', '3d-corporate-book' ) . '</h3>' . $status . $plans . $message . '</div>'
      . '<div class="cb-account cb-profile-card" id="cb-sec-details"><h3>' . esc_html__( 'My details', '3d-corporate-book' ) . '</h3><form class="cb-profile-details" method="post" data-action="corporate_book_profile">'
      . self::field( 'first_name', __( 'First name', '3d-corporate-book' ), $user->first_name, 'text', 'autocomplete="given-name"' )
      . self::field( 'last_name', __( 'Last name', '3d-corporate-book' ), $user->last_name, 'text', 'autocomplete="family-name"' )
      . self::field( 'email', __( 'Email', '3d-corporate-book' ), $user->user_email, 'email', 'required autocomplete="email"' )
      . self::field( 'phone', __( 'Phone', '3d-corporate-book' ), get_user_meta( $user->ID, 'cb_phone', true ), 'tel', 'autocomplete="tel"' )
      . self::field( 'company', __( 'Company', '3d-corporate-book' ), get_user_meta( $user->ID, 'cb_company', true ), 'text', 'autocomplete="organization"' )
      . self::field( 'current', __( 'Current password (only to change your email)', '3d-corporate-book' ), '', 'password', 'autocomplete="current-password"' )
      . '<button type="submit">' . esc_html__( 'Save details', '3d-corporate-book' ) . '</button></form>' . $message . '</div>'
      . '<div class="cb-account cb-profile-card" id="cb-sec-security"><h3>' . esc_html__( 'Change password', '3d-corporate-book' ) . '</h3><form class="cb-profile-password" method="post" data-action="corporate_book_password">'
      . self::field( 'current', __( 'Current password', '3d-corporate-book' ), '', 'password', 'required autocomplete="current-password"' )
      . self::field( 'pwd', __( 'New password (8 characters or more)', '3d-corporate-book' ), '', 'password', 'required minlength="8" autocomplete="new-password"' )
      . '<button type="submit">' . esc_html__( 'Change password', '3d-corporate-book' ) . '</button></form>' . $message . '</div>'
      . Corporate_Book_Shop::account_cards( $user )
      . '<div class="cb-account cb-profile-card cb-profile-wide" id="cb-sec-payments"><h3>' . esc_html__( 'Subscription payments', '3d-corporate-book' ) . '</h3>' . $orders . '</div>'
      . '<p class="cb-profile-signout"><a class="cb-signout" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Sign out', '3d-corporate-book' ) . '</a></p>'
      . '</div></div></div>';
  }

  /**
   * My account header: initials, name, email, subscription badge.
   */
  private static function dash_header( $user ) {
    $name     = trim( $user->first_name . ' ' . $user->last_name );
    $name     = '' !== $name ? $name : $user->display_name;
    $initials = strtoupper( implode( '', array_map( function ( $w ) {
      return function_exists( 'mb_substr' ) ? mb_substr( $w, 0, 1 ) : substr( $w, 0, 1 );
    }, array_slice( preg_split( '/[\s@._-]+/', $name, -1, PREG_SPLIT_NO_EMPTY ), 0, 2 ) ) ) );
    $active   = self::is_active( $user->ID );
    /* translators: %s: date */
    $badge    = $active ? sprintf( __( 'Subscribed until %s', '3d-corporate-book' ), self::date( self::until( $user->ID ) ) ) : __( 'Free reader', '3d-corporate-book' );
    /* translators: %s: date */
    $since    = sprintf( __( 'Member since %s', '3d-corporate-book' ), self::date( strtotime( $user->user_registered ) ) );
    return '<header class="cb-dash-head"><div class="cb-avatar" aria-hidden="true">' . esc_html( $initials ) . '</div><div class="cb-dash-who"><h2>' . esc_html( $name ) . '</h2><p>' . esc_html( $user->user_email ) . ' · ' . esc_html( $since ) . '</p></div>'
      . '<span class="cb-badge ' . ( $active ? 'cb-badge-on' : 'cb-badge-off' ) . '">' . esc_html( $badge ) . '</span></header>';
  }

  /**
   * My account side menu (every section stays on the page; the menu jumps to it).
   */
  private static function dash_nav() {
    $items = array(
      'subscription' => __( 'Subscription', '3d-corporate-book' ),
      'details'      => __( 'My details', '3d-corporate-book' ),
      'security'     => __( 'Password', '3d-corporate-book' ),
      'read'         => __( 'Books read', '3d-corporate-book' ),
      'saved'        => __( 'Saved books', '3d-corporate-book' ),
      'orders'       => __( 'Print orders', '3d-corporate-book' ),
      'address'      => __( 'Delivery address', '3d-corporate-book' ),
      'payments'     => __( 'Payments', '3d-corporate-book' ),
    );
    $links = '';
    foreach ( $items as $id => $label ) {
      $links .= '<a href="#cb-sec-' . esc_attr( $id ) . '">' . esc_html( $label ) . '</a>';
    }
    return '<nav class="cb-dash-nav" aria-label="' . esc_attr__( 'My account', '3d-corporate-book' ) . '">' . $links . '<a class="cb-dash-out" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Sign out', '3d-corporate-book' ) . '</a></nav>';
  }

  public function save_profile() {
    check_ajax_referer( self::NONCE );
    $user = wp_get_current_user();
    if ( ! $user->exists() ) {
      wp_send_json_error( __( 'Sign in again.', '3d-corporate-book' ), 401 );
    }
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
    $get   = function ( $key ) {
      return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
    };
    $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    // phpcs:enable
    if ( ! is_email( $email ) ) {
      wp_send_json_error( __( 'Enter a valid email address.', '3d-corporate-book' ), 400 );
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked above; passwords must reach WordPress unchanged.
    $current = isset( $_POST['current'] ) ? (string) wp_unslash( $_POST['current'] ) : '';
    if ( strtolower( $email ) !== strtolower( $user->user_email ) && ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
      wp_send_json_error( __( 'To change your email, enter your current password.', '3d-corporate-book' ), 400 );
    }
    $owner = email_exists( $email );
    if ( $owner && (int) $owner !== $user->ID ) {
      wp_send_json_error( __( 'Another account already uses this email.', '3d-corporate-book' ), 400 );
    }
    $name   = trim( $get( 'first_name' ) . ' ' . $get( 'last_name' ) );
    $result = wp_update_user( array( 'ID' => $user->ID, 'first_name' => $get( 'first_name' ), 'last_name' => $get( 'last_name' ), 'user_email' => $email, 'display_name' => '' !== $name ? $name : $user->display_name ) );
    if ( is_wp_error( $result ) ) {
      wp_send_json_error( $result->get_error_message(), 400 );
    }
    update_user_meta( $user->ID, 'cb_phone', $get( 'phone' ) );
    update_user_meta( $user->ID, 'cb_company', $get( 'company' ) );
    wp_send_json_success( array( 'message' => __( 'Your details are saved.', '3d-corporate-book' ) ) );
  }

  public function change_password() {
    check_ajax_referer( self::NONCE );
    $user = wp_get_current_user();
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked above; passwords must reach WordPress unchanged.
    $current  = isset( $_POST['current'] ) ? (string) wp_unslash( $_POST['current'] ) : '';
    $password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
    // phpcs:enable
    $key = self::throttle_key( 'password', (string) $user->ID );
    if ( ! $user->exists() || self::too_many( $key, 5 ) ) {
      wp_send_json_error( __( 'Too many attempts. Try again in 15 minutes.', '3d-corporate-book' ), 429 );
    }
    if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
      self::count_try( $key );
      wp_send_json_error( __( 'Your current password is not right.', '3d-corporate-book' ), 400 );
    }
    if ( strlen( $password ) < 8 ) {
      wp_send_json_error( __( 'Use a password of 8 characters or more.', '3d-corporate-book' ), 400 );
    }
    wp_set_password( $password, $user->ID );
    wp_set_auth_cookie( $user->ID, true, is_ssl() ); // wp_set_password signs the reader out everywhere
    wp_send_json_success();
  }

  /**
   * "Continue with Google": checks Google's signed ID token, then signs the reader in, creating a
   * reader account the first time. Staff accounts sign in on the normal WordPress screen instead.
   */
  public function google() {
    check_ajax_referer( self::NONCE );
    $key = self::throttle_key( 'google' );
    if ( '' === self::config( 'google_client_id' ) || self::too_many( $key, 20 ) ) {
      wp_send_json_error( __( 'Google sign in is not available.', '3d-corporate-book' ), 403 );
    }
    self::count_try( $key );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
    $token    = isset( $_POST['credential'] ) ? sanitize_text_field( wp_unslash( $_POST['credential'] ) ) : '';
    $response = wp_remote_get( 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $token ), array( 'timeout' => 15 ) );
    $info     = is_wp_error( $response ) ? array() : json_decode( wp_remote_retrieve_body( $response ), true );
    $valid    = is_array( $info ) && isset( $info['aud'], $info['email'], $info['exp'], $info['iss'] )
      && hash_equals( self::config( 'google_client_id' ), (string) $info['aud'] )
      && in_array( $info['iss'], array( 'accounts.google.com', 'https://accounts.google.com' ), true )
      && (int) $info['exp'] > time() && isset( $info['email_verified'] ) && in_array( $info['email_verified'], array( true, 'true' ), true );
    if ( ! $valid ) {
      wp_send_json_error( __( 'Google could not confirm your account. Try again.', '3d-corporate-book' ), 401 );
    }
    $email = sanitize_email( $info['email'] );
    $user  = get_user_by( 'email', $email );
    if ( $user && ! self::is_reader( $user ) ) {
      wp_send_json_error( __( 'This is a staff account. Sign in on the site\'s login page.', '3d-corporate-book' ), 403 );
    }
    if ( ! $user ) {
      if ( 'true' !== self::config( 'subscription_signup' ) ) {
        wp_send_json_error( __( 'Sign up is turned off.', '3d-corporate-book' ), 403 );
      }
      $user_id = wp_insert_user( array( 'user_login' => $email, 'user_email' => $email, 'user_pass' => wp_generate_password( 24 ), 'role' => self::ROLE, 'first_name' => isset( $info['given_name'] ) ? sanitize_text_field( $info['given_name'] ) : '', 'last_name' => isset( $info['family_name'] ) ? sanitize_text_field( $info['family_name'] ) : '', 'display_name' => isset( $info['name'] ) ? sanitize_text_field( $info['name'] ) : $email ) );
      if ( is_wp_error( $user_id ) ) {
        wp_send_json_error( $user_id->get_error_message(), 400 );
      }
      $user = get_userdata( $user_id );
    }
    wp_set_current_user( $user->ID );
    wp_set_auth_cookie( $user->ID, true, is_ssl() );
    wp_send_json_success();
  }

  public function lost() {
    check_ajax_referer( self::NONCE );
    $key = self::throttle_key( 'lost' );
    if ( self::too_many( $key, 5 ) ) {
      wp_send_json_error( __( 'Too many attempts. Try again in 15 minutes.', '3d-corporate-book' ), 429 );
    }
    self::count_try( $key );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
    $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $user  = is_email( $email ) ? get_user_by( 'email', $email ) : false;
    if ( $user ) {
      $reset = get_password_reset_key( $user );
      if ( ! is_wp_error( $reset ) ) {
        $link = add_query_arg( array( 'cb_reset' => $reset, 'login' => rawurlencode( $user->user_login ) ), self::profile_url() );
        /* translators: %s: site name */
        $subject = sprintf( __( '[%s] Choose a new password', '3d-corporate-book' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
        /* translators: %s: link */
        wp_mail( $user->user_email, $subject, sprintf( __( "Someone asked to reset the password of your account.\n\nTo choose a new password, open this link:\n%s\n\nIf it was not you, ignore this email.", '3d-corporate-book' ), $link ) );
      }
    }
    // The same answer either way, so the form does not reveal who has an account.
    wp_send_json_success( array( 'message' => __( 'If this email has an account, we sent it a link to choose a new password.', '3d-corporate-book' ) ) );
  }

  public function reset() {
    check_ajax_referer( self::NONCE );
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked above; passwords must reach WordPress unchanged.
    $login    = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ) ) : '';
    $reset    = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
    $password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
    // phpcs:enable
    $user = check_password_reset_key( $reset, $login );
    if ( is_wp_error( $user ) ) {
      wp_send_json_error( __( 'This link has expired. Ask for a new one.', '3d-corporate-book' ), 400 );
    }
    if ( strlen( $password ) < 8 ) {
      wp_send_json_error( __( 'Use a password of 8 characters or more.', '3d-corporate-book' ), 400 );
    }
    reset_password( $user, $password );
    wp_signon( array( 'user_login' => $user->user_login, 'user_password' => $password, 'remember' => true ), is_ssl() );
    wp_send_json_success( array( 'go' => self::profile_url() ) );
  }

  // ---- Sign in and sign up -------------------------------------------------------------------

  private static function throttle_key( $what, $who = '' ) {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    return 'cb_try_' . $what . '_' . md5( $ip . '|' . strtolower( $who ) );
  }

  private static function too_many( $key, $limit ) {
    return (int) get_transient( $key ) >= $limit;
  }

  private static function count_try( $key ) {
    set_transient( $key, (int) get_transient( $key ) + 1, 15 * MINUTE_IN_SECONDS );
  }

  public function signin() {
    check_ajax_referer( self::NONCE );
    $login = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords must reach WordPress unchanged.
    $password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
    // Five wrong passwords for one account from one address lock that pair for 15 minutes.
    $key = self::throttle_key( 'signin', $login );
    if ( self::too_many( $key, 5 ) ) {
      wp_send_json_error( __( 'Too many attempts. Try again in 15 minutes.', '3d-corporate-book' ), 429 );
    }
    $user = wp_signon( array( 'user_login' => $login, 'user_password' => $password, 'remember' => true ), is_ssl() );
    if ( is_wp_error( $user ) ) {
      self::count_try( $key );
      wp_send_json_error( __( 'Incorrect email or password.', '3d-corporate-book' ), 401 );
    }
    wp_send_json_success();
  }

  public function signup() {
    check_ajax_referer( self::NONCE );
    $key = self::throttle_key( 'signup' );
    if ( 'true' !== self::config( 'subscription_signup' ) ) {
      wp_send_json_error( __( 'Sign up is turned off.', '3d-corporate-book' ), 403 );
    }
    if ( self::too_many( $key, (int) apply_filters( 'corporate_book_signup_limit', 10 ) ) ) {
      wp_send_json_error( __( 'Too many attempts. Try again in 15 minutes.', '3d-corporate-book' ), 429 );
    }
    $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords must reach WordPress unchanged.
    $password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
    if ( ! is_email( $email ) ) {
      wp_send_json_error( __( 'Enter a valid email address.', '3d-corporate-book' ), 400 );
    }
    if ( strlen( $password ) < 8 ) {
      wp_send_json_error( __( 'Use a password of 8 characters or more.', '3d-corporate-book' ), 400 );
    }
    if ( email_exists( $email ) || username_exists( $email ) ) {
      wp_send_json_error( __( 'This email already has an account. Sign in instead.', '3d-corporate-book' ), 400 );
    }
    self::count_try( $key );
    $user_id = wp_insert_user( array( 'user_login' => $email, 'user_email' => $email, 'user_pass' => $password, 'role' => self::ROLE ) );
    if ( is_wp_error( $user_id ) ) {
      wp_send_json_error( $user_id->get_error_message(), 400 );
    }
    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id, true, is_ssl() );
    wp_send_json_success();
  }

  // ---- Razorpay ------------------------------------------------------------------------------

  /**
   * Calls Razorpay's API with the site's keys.
   *
   * @param string     $method HTTP method.
   * @param string     $path   Path after /v1/.
   * @param array|null $body   JSON body.
   *
   * @return array|WP_Error Decoded answer.
   */
  public static function api( $method, $path, $body = null ) {
    $response = wp_remote_request(
      'https://api.razorpay.com/v1/' . $path,
      array(
        'method'  => $method,
        'timeout' => 15,
        'headers' => array(
          // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
          'Authorization' => 'Basic ' . base64_encode( self::config( 'razorpay_key_id' ) . ':' . self::config( 'razorpay_key_secret' ) ),
          'Content-Type'  => 'application/json',
        ),
        'body'    => null === $body ? null : wp_json_encode( $body ),
      )
    );
    if ( is_wp_error( $response ) ) {
      return $response;
    }
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
      return new WP_Error( 'corporate_book_razorpay', isset( $data['error']['description'] ) ? $data['error']['description'] : 'Razorpay request failed (' . $code . ')' );
    }
    return $data;
  }

  /**
   * Whether a Razorpay payment is captured, capturing it first when it is only authorized.
   *
   * @param array $payment Payment from Razorpay.
   * @param int   $amount  Order amount in the smallest currency unit.
   *
   * @return bool
   */
  public static function captured( $payment, $amount ) {
    if ( ! isset( $payment['id'], $payment['status'] ) || ! preg_match( '/^pay_[A-Za-z0-9]+$/', $payment['id'] ) ) {
      return false;
    }
    if ( 'captured' === $payment['status'] ) {
      return true;
    }
    if ( 'authorized' !== $payment['status'] ) {
      return false;
    }
    $result = self::api( 'POST', 'payments/' . $payment['id'] . '/capture', array( 'amount' => $amount, 'currency' => self::config( 'subscription_currency' ) ) );
    return ! is_wp_error( $result ) && isset( $result['status'] ) && 'captured' === $result['status'];
  }

  /**
   * Marks an order paid and extends the reader's access by the plan's days.
   *
   * @param int    $user_id    User ID.
   * @param string $order_id   Razorpay order ID.
   * @param string $payment_id Razorpay payment ID.
   */
  private static function settle( $user_id, $order_id, $payment_id ) {
    $orders                      = self::orders( $user_id );
    $orders[ $order_id ]['paid'] = $payment_id;
    update_user_meta( $user_id, self::ORDERS, $orders );
    $reader = $user_id;
    $gift   = isset( $orders[ $order_id ]['gift'] ) ? (string) $orders[ $order_id ]['gift'] : '';
    if ( '' !== $gift ) {
      $friend = get_user_by( 'email', $gift );
      $reader = $friend ? $friend->ID : wp_insert_user( array( 'user_login' => $gift, 'user_email' => $gift, 'user_pass' => wp_generate_password( 24 ), 'role' => self::ROLE ) );
      $reader = is_wp_error( $reader ) ? $user_id : (int) $reader;
    }
    update_user_meta( $reader, self::UNTIL, max( time(), self::until( $reader ) ) + $orders[ $order_id ]['days'] * DAY_IN_SECONDS );
    if ( $reader !== $user_id ) {
      $giver = get_userdata( $user_id );
      /* translators: %s: site name */
      $subject = sprintf( __( '[%s] You have been given a subscription', '3d-corporate-book' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
      /* translators: 1: giver's name, 2: days, 3: date, 4: link */
      wp_mail( $gift, $subject, sprintf( __( "%1\$s gave you %2\$d days of online reading, until %3\$s.\n\nSign in (or choose your password first) here: %4\$s", '3d-corporate-book' ), $giver ? $giver->display_name : '', $orders[ $order_id ]['days'], self::date( self::until( $reader ) ), add_query_arg( 'cb_lost', '1', self::profile_url() ) ) );
    }
    $user = get_userdata( $user_id );
    if ( $user ) {
      $order = $orders[ $order_id ];
      /* translators: %s: site name */
      $subject = sprintf( __( '[%s] Payment received', '3d-corporate-book' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
      /* translators: 1: plan, 2: amount, 3: payment ID, 4: date, 5: account page URL */
      $body = sprintf( __( "Thank you for subscribing.\n\nPlan: %1\$s\nAmount: %2\$s\nPayment ID: %3\$s\nAccess until: %4\$s\n\nYour account: %5\$s", '3d-corporate-book' ), $order['plan'], self::price( $order['amount'] / 100 ), $payment_id, self::date( self::until( $user_id ) ), self::profile_url() );
      /* translators: %s: email address */
      $body .= '' !== $gift ? "\n\n" . sprintf( __( 'This was a gift for %s. We have emailed them.', '3d-corporate-book' ), $gift ) : '';
      wp_mail( $user->user_email, $subject, $body );
    }
  }

  /**
   * Finds payments that succeeded at Razorpay but whose confirmation never reached this site
   * (for example the reader closed the tab), for the reader's three newest unpaid orders of the last day.
   *
   * @param int $user_id User ID.
   */
  private static function reconcile( $user_id ) {
    $orders = self::orders( $user_id );
    $looked = 0;
    foreach ( array_reverse( $orders, true ) as $order_id => $order ) {
      $checked = isset( $order['checked'] ) ? $order['checked'] : 0;
      if ( '' !== $order['paid'] || $order['time'] < time() - DAY_IN_SECONDS || $checked > time() - 5 * MINUTE_IN_SECONDS || ++$looked > 3 ) {
        continue;
      }
      $orders[ $order_id ]['checked'] = time();
      update_user_meta( $user_id, self::ORDERS, $orders );
      $payments = self::api( 'GET', 'orders/' . $order_id . '/payments' );
      foreach ( is_wp_error( $payments ) || empty( $payments['items'] ) ? array() : $payments['items'] as $payment ) {
        if ( self::captured( $payment, $order['amount'] ) ) {
          self::settle( $user_id, $order_id, $payment['id'] );
          return;
        }
      }
    }
  }

  /**
   * Starts a Razorpay order for the chosen plan. The price comes from the settings, never from the browser.
   */
  public function order() {
    check_ajax_referer( self::NONCE );
    $plans = self::plans();
    $index = isset( $_POST['plan'] ) ? absint( $_POST['plan'] ) : -1;
    if ( ! is_user_logged_in() || ! self::razorpay_ready() || ! isset( $plans[ $index ] ) ) {
      wp_send_json_error( __( 'This plan is not available.', '3d-corporate-book' ), 400 );
    }
    $user   = wp_get_current_user();
    $plan   = $plans[ $index ];
    // Optional gift: the days go to another reader, found or created by email.
    $gift = isset( $_POST['gift_email'] ) ? sanitize_email( wp_unslash( $_POST['gift_email'] ) ) : '';
    if ( '' !== $gift && ( ! is_email( $gift ) || strtolower( $gift ) === strtolower( $user->user_email ) ) ) {
      wp_send_json_error( __( 'Enter the email address of the person you are giving it to.', '3d-corporate-book' ), 400 );
    }
    $amount = (int) round( $plan['price'] * 100 );
    $order  = self::api(
      'POST',
      'orders',
      array(
        'amount'   => $amount,
        'currency' => self::config( 'subscription_currency' ),
        'receipt'  => 'cb-' . $user->ID . '-' . time(),
        'notes'    => array( 'user_id' => (string) $user->ID, 'email' => $user->user_email, 'plan' => $plan['name'], 'gift' => $gift ),
      )
    );
    if ( is_wp_error( $order ) || empty( $order['id'] ) || ! preg_match( '/^order_[A-Za-z0-9]+$/', $order['id'] ) ) {
      // Administrators see Razorpay's reason (for example wrong keys); readers see a plain message.
      $reason = is_wp_error( $order ) && current_user_can( 'manage_options' ) ? ' ' . $order->get_error_message() : '';
      wp_send_json_error( __( 'The payment could not be started. Try again.', '3d-corporate-book' ) . $reason, 502 );
    }
    $orders                 = self::orders( $user->ID );
    $orders[ $order['id'] ] = array( 'amount' => $amount, 'days' => $plan['days'], 'plan' => $plan['name'], 'time' => time(), 'paid' => '', 'gift' => $gift );
    update_user_meta( $user->ID, self::ORDERS, array_slice( $orders, -20, null, true ) );
    wp_send_json_success(
      array(
        'key'         => self::config( 'razorpay_key_id' ),
        'order_id'    => $order['id'],
        'amount'      => $amount,
        'currency'    => self::config( 'subscription_currency' ),
        'name'        => get_bloginfo( 'name' ),
        'description' => $plan['name'],
        'prefill'     => array( 'name' => $user->display_name, 'email' => $user->user_email ),
      )
    );
  }

  /**
   * Confirms a Razorpay Checkout payment and starts the subscription.
   */
  public function verify() {
    check_ajax_referer( self::NONCE );
    $user_id    = get_current_user_id();
    $order_id   = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
    $payment_id = isset( $_POST['payment_id'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_id'] ) ) : '';
    $signature  = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';
    $orders     = self::orders( $user_id );
    $failed     = __( 'The payment could not be verified. If money was taken, contact us with your payment ID.', '3d-corporate-book' );
    // Only an order this reader started counts, and each order counts once.
    if ( ! $user_id || ! isset( $orders[ $order_id ] ) || ! preg_match( '/^pay_[A-Za-z0-9]+$/', $payment_id ) ) {
      wp_send_json_error( $failed, 400 );
    }
    $order = $orders[ $order_id ];
    if ( '' === $order['paid'] ) {
      // Razorpay signs "order_id|payment_id" with the key secret; the order ID is the one stored here, not the browser's word.
      if ( ! hash_equals( hash_hmac( 'sha256', $order_id . '|' . $payment_id, self::config( 'razorpay_key_secret' ) ), $signature ) ) {
        wp_send_json_error( $failed, 400 );
      }
      // When Razorpay answers, the payment must also be for this order and amount, and captured.
      $payment = self::api( 'GET', 'payments/' . $payment_id );
      if ( ! is_wp_error( $payment ) ) {
        $matches = isset( $payment['order_id'], $payment['amount'] ) && $payment['order_id'] === $order_id && (int) $payment['amount'] === (int) $order['amount'];
        if ( ! $matches || ! self::captured( $payment, $order['amount'] ) ) {
          wp_send_json_error( $failed, 400 );
        }
      }
      self::settle( $user_id, $order_id, $payment_id );
    }
    /* translators: %s: date */
    wp_send_json_success( array( 'until' => self::until( $user_id ), 'reload' => true, 'message' => sprintf( __( 'Payment successful. Your subscription is active until %s. A receipt is on its way to your email.', '3d-corporate-book' ), self::date( self::until( $user_id ) ) ) ) );
  }

  /**
   * Global Settings button: checks the saved Razorpay keys.
   */
  public function razorpay_test() {
    check_ajax_referer( 'corporate_book_razorpay_test' );
    if ( ! current_user_can( 'manage_options' ) ) {
      wp_send_json_error( __( 'Not allowed.', '3d-corporate-book' ), 403 );
    }
    if ( ! self::razorpay_ready() ) {
      wp_send_json_error( __( 'Save your Razorpay Key ID and Key Secret first.', '3d-corporate-book' ) );
    }
    $answer = self::api( 'GET', 'orders?count=1' );
    if ( is_wp_error( $answer ) ) {
      wp_send_json_error( __( 'Razorpay refused the keys:', '3d-corporate-book' ) . ' ' . $answer->get_error_message() );
    }
    wp_send_json_success( 0 === strpos( self::config( 'razorpay_key_id' ), 'rzp_test_' ) ? __( 'Connected (Test Mode).', '3d-corporate-book' ) : __( 'Connected (Live Mode).', '3d-corporate-book' ) );
  }

  /**
   * Whether $sign is Razorpay's HMAC-SHA256 of $body with the webhook secret.
   *
   * @param string $body   Raw request body.
   * @param string $sign   X-Razorpay-Signature header.
   * @param string $secret Webhook secret.
   *
   * @return bool
   */
  public static function signed( $body, $sign, $secret ) {
    return '' !== $secret && '' !== $sign && hash_equals( hash_hmac( 'sha256', $body, $secret ), $sign );
  }

  /**
   * Razorpay webhook: payment.captured / order.paid start access even when the reader closed the tab;
   * refund.processed / payment.refunded take the days back. Razorpay signs the raw body with the webhook secret.
   */
  public function webhook() {
    $secret = self::config( 'razorpay_webhook_secret' );
    $body   = (string) file_get_contents( 'php://input' );
    $sign   = isset( $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ) ) : '';
    if ( ! self::signed( $body, $sign, $secret ) ) {
      wp_send_json_error( 'bad signature', 401 );
    }
    $event   = json_decode( $body, true );
    $payment = isset( $event['payload']['payment']['entity'] ) ? $event['payload']['payment']['entity'] : array();
    $type    = isset( $event['event'] ) ? $event['event'] : '';
    if ( isset( $payment['notes']['print_order'] ) ) {
      Corporate_Book_Shop::razorpay_event( $type, $payment );
      wp_send_json_success( 'ok' );
    }
    $user_id = isset( $payment['notes']['user_id'] ) ? (int) $payment['notes']['user_id'] : 0;
    $orders  = $user_id ? self::orders( $user_id ) : array();
    $oid     = isset( $payment['order_id'] ) ? (string) $payment['order_id'] : '';
    if ( ! isset( $orders[ $oid ] ) ) {
      wp_send_json_success( 'ignored' ); // not one of ours; 2xx so Razorpay stops retrying
    }
    $order = $orders[ $oid ];
    if ( in_array( $type, array( 'payment.captured', 'order.paid' ), true ) && '' === $order['paid'] && isset( $payment['amount'], $payment['id'] ) && (int) $payment['amount'] === (int) $order['amount'] ) {
      self::settle( $user_id, $oid, (string) $payment['id'] );
    } elseif ( in_array( $type, array( 'refund.processed', 'payment.refunded' ), true ) && '' !== $order['paid'] && empty( $order['refunded'] ) ) {
      $orders[ $oid ]['refunded'] = time();
      update_user_meta( $user_id, self::ORDERS, $orders );
      update_user_meta( $user_id, self::UNTIL, max( 0, self::until( $user_id ) - $order['days'] * DAY_IN_SECONDS ) );
    }
    wp_send_json_success( 'ok' );
  }

  /**
   * Daily: emails readers whose subscription ends in the next 3 days, once per end date.
   */
  public function send_reminders() {
    $users = get_users( array( 'meta_query' => array( array( 'key' => self::UNTIL, 'value' => array( time(), time() + 3 * DAY_IN_SECONDS ), 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ) ), 'number' => 500 ) );
    foreach ( $users as $user ) {
      $until = self::until( $user->ID );
      if ( (int) get_user_meta( $user->ID, 'corporate_book_reminded', true ) === $until ) {
        continue;
      }
      update_user_meta( $user->ID, 'corporate_book_reminded', $until );
      /* translators: %s: site name */
      $subject = sprintf( __( '[%s] Your subscription ends soon', '3d-corporate-book' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
      /* translators: 1: date, 2: account page URL */
      wp_mail( $user->user_email, $subject, sprintf( __( "Your subscription ends on %1\$s.\n\nRenew it from your account: %2\$s", '3d-corporate-book' ), self::date( $until ), self::profile_url() ) );
    }
  }

  public function register_exporter( $exporters ) {
    $exporters['corporate-book'] = array( 'exporter_friendly_name' => __( 'Corporate Book account', '3d-corporate-book' ), 'callback' => array( $this, 'export_data' ) );
    return $exporters;
  }

  public function register_eraser( $erasers ) {
    $erasers['corporate-book'] = array( 'eraser_friendly_name' => __( 'Corporate Book account', '3d-corporate-book' ), 'callback' => array( $this, 'erase_data' ) );
    return $erasers;
  }

  public function export_data( $email ) {
    $user = get_user_by( 'email', $email );
    $data = array();
    if ( $user ) {
      $fields = array(
        array( 'name' => __( 'Phone', '3d-corporate-book' ), 'value' => get_user_meta( $user->ID, 'cb_phone', true ) ),
        array( 'name' => __( 'Company', '3d-corporate-book' ), 'value' => get_user_meta( $user->ID, 'cb_company', true ) ),
        array( 'name' => __( 'Subscription active until', '3d-corporate-book' ), 'value' => self::until( $user->ID ) ? self::date( self::until( $user->ID ) ) : '' ),
      );
      $data[] = array( 'group_id' => 'corporate-book', 'group_label' => __( 'Corporate Book account', '3d-corporate-book' ), 'item_id' => 'account-' . $user->ID, 'data' => $fields );
      $address = get_user_meta( $user->ID, Corporate_Book_Shop::ADDRESS, true );
      if ( is_array( $address ) ) {
        $data[] = array( 'group_id' => 'corporate-book-address', 'group_label' => __( 'Delivery address', '3d-corporate-book' ), 'item_id' => 'address-' . $user->ID, 'data' => array( array( 'name' => __( 'Address', '3d-corporate-book' ), 'value' => implode( ', ', array_filter( $address ) ) ) ) );
      }
      foreach ( get_posts( array( 'post_type' => Corporate_Book_Shop::ORDER, 'author' => $user->ID, 'post_status' => array_keys( Corporate_Book_Shop::statuses() ), 'numberposts' => 200 ) ) as $order ) {
        $data[] = array( 'group_id' => 'corporate-book-print-orders', 'group_label' => __( 'Print orders', '3d-corporate-book' ), 'item_id' => 'print-order-' . $order->ID, 'data' => array(
          array( 'name' => __( 'Order', '3d-corporate-book' ), 'value' => '#' . $order->ID . ' (' . $order->post_date . ')' ),
          array( 'name' => __( 'Details', '3d-corporate-book' ), 'value' => Corporate_Book_Shop::summary( $order->ID ) ),
        ) );
      }
      foreach ( self::orders( $user->ID ) as $id => $order ) {
        $data[] = array( 'group_id' => 'corporate-book-payments', 'group_label' => __( 'Corporate Book payments', '3d-corporate-book' ), 'item_id' => $id, 'data' => array(
          array( 'name' => __( 'Date', '3d-corporate-book' ), 'value' => self::date( (int) $order['time'] ) ),
          array( 'name' => __( 'Plan', '3d-corporate-book' ), 'value' => $order['plan'] ),
          array( 'name' => __( 'Amount', '3d-corporate-book' ), 'value' => self::price( $order['amount'] / 100 ) ),
          array( 'name' => __( 'Payment ID', '3d-corporate-book' ), 'value' => $order['paid'] ),
        ) );
      }
    }
    return array( 'data' => $data, 'done' => true );
  }

  public function erase_data( $email ) {
    $user = get_user_by( 'email', $email );
    if ( $user ) {
      // Payment records are kept for tax and accounting; contact details are removed.
      delete_user_meta( $user->ID, 'cb_phone' );
      delete_user_meta( $user->ID, 'cb_company' );
      delete_user_meta( $user->ID, Corporate_Book_Shop::ADDRESS );
    }
    return array( 'items_removed' => (bool) $user, 'items_retained' => (bool) $user && self::orders( $user->ID ), 'messages' => $user ? array( __( 'Corporate Book payment records were kept for accounting.', '3d-corporate-book' ) ) : array(), 'done' => true );
  }

  // ---- Administrators: see and change a reader's subscription --------------------------------

  public function users_column( $columns ) {
    $columns['corporate_book'] = __( 'Corporate Book', '3d-corporate-book' );
    return $columns;
  }

  public function users_column_value( $value, $column, $user_id ) {
    if ( 'corporate_book' !== $column ) {
      return $value;
    }
    $until = self::until( $user_id );
    if ( ! $until ) {
      return '&mdash;';
    }
    /* translators: %s: date */
    return esc_html( sprintf( $until > time() ? __( 'Subscribed until %s', '3d-corporate-book' ) : __( 'Ended %s', '3d-corporate-book' ), self::date( $until ) ) );
  }

  public function profile_field( $user ) {
    $date = self::until( $user->ID ) ? gmdate( 'Y-m-d', self::until( $user->ID ) ) : '';
    ?>
    <h2><?php esc_html_e( 'Corporate Book', '3d-corporate-book' ); ?></h2>
    <table class="form-table" role="presentation">
      <tr>
        <th><label for="corporate_book_until"><?php esc_html_e( 'Subscription active until', '3d-corporate-book' ); ?></label></th>
        <td>
          <?php if ( current_user_can( 'edit_users' ) ) : ?>
            <input type="date" name="corporate_book_until" id="corporate_book_until" value="<?php echo esc_attr( $date ); ?>"/>
            <p class="description"><?php esc_html_e( 'Clear the date to end the subscription.', '3d-corporate-book' ); ?></p>
          <?php else : ?>
            <?php echo esc_html( '' !== $date ? $date : __( 'No subscription', '3d-corporate-book' ) ); ?>
          <?php endif; ?>
        </td>
      </tr>
    </table>
    <?php
  }

  public function save_profile_field( $user_id ) {
    // WordPress has already checked the profile form's nonce; only administrators may change the date.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( ! current_user_can( 'edit_users' ) || ! isset( $_POST['corporate_book_until'] ) ) {
      return;
    }
    $date = sanitize_text_field( wp_unslash( $_POST['corporate_book_until'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $time = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? strtotime( $date . ' 23:59:59 UTC' ) : false;
    if ( $time ) {
      update_user_meta( $user_id, self::UNTIL, $time );
    } else {
      delete_user_meta( $user_id, self::UNTIL );
    }
  }
}

new Corporate_Book_Account();
