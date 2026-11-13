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

  public function __construct() {
    foreach ( array( 'signin', 'signup' ) as $action ) {
      add_action( 'wp_ajax_nopriv_corporate_book_' . $action, array( $this, $action ) );
      add_action( 'wp_ajax_corporate_book_' . $action, array( $this, $action ) );
    }
    add_action( 'wp_ajax_corporate_book_order', array( $this, 'order' ) );
    add_action( 'wp_ajax_corporate_book_verify', array( $this, 'verify' ) );
    add_shortcode( 'corporate_book_account', array( $this, 'shortcode' ) );
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

  private static function price( $amount ) {
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
   * @return string HTML.
   */
  public static function box() {
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
    $link = '' !== $link && ! self::razorpay_ready()
      ? '<a class="cb-lock-subscribe" href="' . esc_url( $link ) . '">' . esc_html( $base->get_translate( 'text_subscribe_button' ) ) . '</a>'
      : '';
    $html = '<div class="cb-account">';

    if ( ! is_user_logged_in() ) {
      $signup = 'true' === self::config( 'subscription_signup' );
      $html  .= '' !== $link ? $link . '<div class="cb-lock-or">' . esc_html__( 'OR', '3d-corporate-book' ) . '</div>' : '';
      $html  .= '<form class="cb-signin" method="post">'
        . '<input type="text" name="log" required autocomplete="username" placeholder="' . esc_attr__( 'Email or username', '3d-corporate-book' ) . '" aria-label="' . esc_attr__( 'Email or username', '3d-corporate-book' ) . '"/>'
        . '<input type="password" name="pwd" required autocomplete="current-password" placeholder="' . esc_attr__( 'Password', '3d-corporate-book' ) . '" aria-label="' . esc_attr__( 'Password', '3d-corporate-book' ) . '"/>'
        . '<button type="submit" class="cb-lock-signin">' . esc_html( $base->get_translate( 'text_sign_in' ) ) . '</button>'
        . '<p class="cb-account-links"><a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( 'Forgot password?', '3d-corporate-book' ) . '</a>'
        . ( $signup ? ' &middot; <a href="#" class="cb-show-signup">' . esc_html__( 'Create an account', '3d-corporate-book' ) . '</a>' : '' ) . '</p></form>';
      if ( $signup ) {
        $html .= '<form class="cb-signup" method="post" hidden>'
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
      } elseif ( self::razorpay_ready() ) {
        foreach ( self::plans() as $index => $plan ) {
          /* translators: 1: plan name, 2: price, 3: days of access */
          $label = sprintf( __( '%1$s: %2$s for %3$d days', '3d-corporate-book' ), $plan['name'], self::price( $plan['price'] ), $plan['days'] );
          $html .= '<button type="button" class="cb-plan" data-plan="' . (int) $index . '">' . esc_html( $label ) . '</button>';
        }
      } else {
        $html .= $link;
      }
      /* translators: %s: reader's name */
      $html .= '<p class="cb-account-links">' . esc_html( sprintf( __( 'Signed in as %s', '3d-corporate-book' ), $user->display_name ) )
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
    return '<p class="cb-account-bar">' . esc_html( $text ) . ' &middot; <a class="cb-signout" href="' . esc_url( wp_logout_url( self::here() ) ) . '">' . esc_html__( 'Sign out', '3d-corporate-book' ) . '</a></p>';
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
    $user_id = wp_insert_user( array( 'user_login' => $email, 'user_email' => $email, 'user_pass' => $password, 'role' => get_option( 'default_role', 'subscriber' ) ) );
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
