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
    foreach ( array( 'lost', 'reset' ) as $action ) {
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
    add_action( 'wp_ajax_corporate_book_order', array( $this, 'order' ) );
    add_action( 'wp_ajax_corporate_book_verify', array( $this, 'verify' ) );
    add_shortcode( 'corporate_book_account', array( $this, 'shortcode' ) );
    add_shortcode( 'corporate_book_subscription', array( $this, 'subscription_page' ) );
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
    return 'INR' === self::config( 'subscription_currency' ) ? "\u{20B9}" . $number : self::config( 'subscription_currency' ) . ' ' . $number;
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
      if ( $active ) {
        $action = '';
      } elseif ( is_user_logged_in() && self::razorpay_ready() ) {
        $action = '<button type="button" class="cb-plan" data-plan="' . (int) $index . '">' . esc_html( $base->get_translate( 'text_subscribe_button' ) ) . '</button>';
      } elseif ( ! is_user_logged_in() ) {
        $action = '<a class="cb-plan-signin" href="#cb-plans-account">' . esc_html__( 'Sign in to subscribe', '3d-corporate-book' ) . '</a>';
      } else {
        $action = '';
      }
      $cards .= '<div class="cb-plan-card"><h3>' . esc_html( $plan['name'] ) . '</h3><div class="cb-plan-price">' . esc_html( self::price( $plan['price'] ) ) . '</div><div class="cb-plan-days">' . esc_html( $days ) . '</div>' . $action . '</div>';
    }
    return '<div class="cb-account cb-plans-page"><div class="cb-plans">' . $cards . '</div><div id="cb-plans-account" class="cb-lock cb-lock-static"><div class="cb-lock-box">' . $box . '</div></div></div>';
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
        return '<div class="cb-profile cb-profile-guest"><div class="cb-lock cb-lock-static"><div class="cb-lock-box">' . $box . '</div></div></div>';
      }
      return '<div class="cb-profile cb-profile-guest"><div class="cb-account cb-profile-card">' . $form . '<p class="cb-account-message" role="alert"></p></div></div>';
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
    return '<div class="cb-profile">'
      . '<div class="cb-account cb-profile-card"><h3>' . esc_html__( 'Subscription', '3d-corporate-book' ) . '</h3>' . $status . $plans . $message . '</div>'
      . '<div class="cb-account cb-profile-card"><h3>' . esc_html__( 'My details', '3d-corporate-book' ) . '</h3><form class="cb-profile-details" method="post" data-action="corporate_book_profile">'
      . self::field( 'first_name', __( 'First name', '3d-corporate-book' ), $user->first_name, 'text', 'autocomplete="given-name"' )
      . self::field( 'last_name', __( 'Last name', '3d-corporate-book' ), $user->last_name, 'text', 'autocomplete="family-name"' )
      . self::field( 'email', __( 'Email', '3d-corporate-book' ), $user->user_email, 'email', 'required autocomplete="email"' )
      . self::field( 'phone', __( 'Phone', '3d-corporate-book' ), get_user_meta( $user->ID, 'cb_phone', true ), 'tel', 'autocomplete="tel"' )
      . self::field( 'company', __( 'Company', '3d-corporate-book' ), get_user_meta( $user->ID, 'cb_company', true ), 'text', 'autocomplete="organization"' )
      . '<button type="submit">' . esc_html__( 'Save details', '3d-corporate-book' ) . '</button></form>' . $message . '</div>'
      . '<div class="cb-account cb-profile-card"><h3>' . esc_html__( 'Change password', '3d-corporate-book' ) . '</h3><form class="cb-profile-password" method="post" data-action="corporate_book_password">'
      . self::field( 'current', __( 'Current password', '3d-corporate-book' ), '', 'password', 'required autocomplete="current-password"' )
      . self::field( 'pwd', __( 'New password (8 characters or more)', '3d-corporate-book' ), '', 'password', 'required minlength="8" autocomplete="new-password"' )
      . '<button type="submit">' . esc_html__( 'Change password', '3d-corporate-book' ) . '</button></form>' . $message . '</div>'
      . '<div class="cb-account cb-profile-card cb-profile-wide"><h3>' . esc_html__( 'Payment history', '3d-corporate-book' ) . '</h3>' . $orders . '</div>'
      . '<p class="cb-profile-signout"><a class="cb-signout" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Sign out', '3d-corporate-book' ) . '</a></p>'
      . '</div>';
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
  private static function api( $method, $path, $body = null ) {
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
  private static function captured( $payment, $amount ) {
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
    update_user_meta( $user_id, self::UNTIL, max( time(), self::until( $user_id ) ) + $orders[ $order_id ]['days'] * DAY_IN_SECONDS );
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
    $amount = (int) round( $plan['price'] * 100 );
    $order  = self::api(
      'POST',
      'orders',
      array(
        'amount'   => $amount,
        'currency' => self::config( 'subscription_currency' ),
        'receipt'  => 'cb-' . $user->ID . '-' . time(),
        'notes'    => array( 'user_id' => (string) $user->ID, 'email' => $user->user_email, 'plan' => $plan['name'] ),
      )
    );
    if ( is_wp_error( $order ) || empty( $order['id'] ) || ! preg_match( '/^order_[A-Za-z0-9]+$/', $order['id'] ) ) {
      // Administrators see Razorpay's reason (for example wrong keys); readers see a plain message.
      $reason = is_wp_error( $order ) && current_user_can( 'manage_options' ) ? ' ' . $order->get_error_message() : '';
      wp_send_json_error( __( 'The payment could not be started. Try again.', '3d-corporate-book' ) . $reason, 502 );
    }
    $orders                 = self::orders( $user->ID );
    $orders[ $order['id'] ] = array( 'amount' => $amount, 'days' => $plan['days'], 'plan' => $plan['name'], 'time' => time(), 'paid' => '' );
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
    wp_send_json_success( array( 'until' => self::until( $user_id ) ) );
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
