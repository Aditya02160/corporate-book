<?php
/**
 * Unit test bootstrap: small in-memory stand-ins for the WordPress functions the tested code calls,
 * so plugin logic runs without a WordPress install. State lives in $GLOBALS['wp'] and is reset per test.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

function cb_reset_wp() {
  $GLOBALS['wp'] = array( 'posts' => array(), 'options' => array(), 'post_meta' => array(), 'user_meta' => array(), 'user' => null, 'caps' => array(), 'filters' => array(), 'users' => array(), 'mail' => array() );
}
cb_reset_wp();

class WP_User {
  public $ID = 0, $caps = array(), $roles = array(), $user_email = '', $user_login = '', $user_pass = '', $display_name = '', $first_name = '', $last_name = '';
  public function __construct( $id = 0, $props = array() ) {
    $this->ID = (int) $id;
    foreach ( $props as $k => $v ) {
      $this->$k = $v;
    }
  }
  public function exists() {
    return $this->ID > 0;
  }
}
class WP_Error {
  public function get_error_message() {
    return 'error';
  }
}

// Hooks.
function add_action() {}
function add_shortcode() {}
function register_activation_hook() {}
function add_filter( $tag, $cb ) {
  $GLOBALS['wp']['filters'][ $tag ][] = $cb;
}
function apply_filters( $tag, $value, ...$args ) {
  foreach ( isset( $GLOBALS['wp']['filters'][ $tag ] ) ? $GLOBALS['wp']['filters'][ $tag ] : array() as $cb ) {
    $value = $cb( $value, ...$args );
  }
  return $value;
}
function wp_next_scheduled() {
  return true;
}
function get_role() {
  return true;
}
function is_multisite() {
  return false;
}
function is_admin() {
  return false;
}
function wp_doing_ajax() {
  return false;
}

// Options and meta.
function get_option( $key, $default = false ) {
  return array_key_exists( $key, $GLOBALS['wp']['options'] ) ? $GLOBALS['wp']['options'][ $key ] : $default;
}
function update_option( $key, $value ) {
  $GLOBALS['wp']['options'][ $key ] = $value;
  return true;
}
function get_post_meta( $id, $key, $single = false ) {
  return isset( $GLOBALS['wp']['post_meta'][ $id ][ $key ] ) ? $GLOBALS['wp']['post_meta'][ $id ][ $key ] : '';
}
function get_posts() {
  return array();
}
function get_the_title( $id ) {
  return isset( $GLOBALS['wp']['posts'][ $id ] ) ? $GLOBALS['wp']['posts'][ $id ]['title'] : '';
}
function get_post_status( $id ) {
  return isset( $GLOBALS['wp']['posts'][ $id ] ) ? $GLOBALS['wp']['posts'][ $id ]['status'] : false;
}
function get_post_type( $id = null ) {
  return isset( $GLOBALS['wp']['posts'][ $id ] ) ? 'corporate_book' : false;
}
function sanitize_email( $e ) {
  return trim( (string) $e );
}
function is_email( $e ) {
  return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL );
}
function get_user_meta( $id, $key, $single = false ) {
  return isset( $GLOBALS['wp']['user_meta'][ $id ][ $key ] ) ? $GLOBALS['wp']['user_meta'][ $id ][ $key ] : '';
}
function update_user_meta( $id, $key, $value ) {
  $GLOBALS['wp']['user_meta'][ $id ][ $key ] = $value;
  return true;
}
function delete_user_meta( $id, $key ) {
  unset( $GLOBALS['wp']['user_meta'][ $id ][ $key ] );
  return true;
}

// Users.
function wp_get_current_user() {
  return $GLOBALS['wp']['user'] ? $GLOBALS['wp']['user'] : new WP_User();
}
function is_user_logged_in() {
  return wp_get_current_user()->exists();
}
function get_current_user_id() {
  return wp_get_current_user()->ID;
}
function current_user_can( $cap ) {
  return in_array( $cap, $GLOBALS['wp']['caps'], true );
}
function user_can( $user, $cap ) {
  return in_array( $cap, isset( $user->caps ) ? $user->caps : array(), true );
}
function get_userdata( $id ) {
  return isset( $GLOBALS['wp']['users'][ $id ] ) ? $GLOBALS['wp']['users'][ $id ] : false;
}
function get_user_by( $field, $value ) {
  foreach ( $GLOBALS['wp']['users'] as $user ) {
    if ( 'email' === $field && $user->user_email === $value ) {
      return $user;
    }
  }
  return false;
}

// Text, escaping, URLs.
function __( $text ) {
  return $text;
}
function _n( $single, $plural, $n ) {
  return 1 === $n ? $single : $plural;
}
function esc_html( $t ) {
  return htmlspecialchars( (string) $t, ENT_QUOTES );
}
function esc_attr( $t ) {
  return esc_html( $t );
}
function esc_url_raw( $url ) {
  return preg_match( '#^(https?:)?//#i', trim( $url ) ) || '/' === substr( trim( $url ), 0, 1 ) ? trim( $url ) : '';
}
function sanitize_key( $key ) {
  return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}
function sanitize_text_field( $t ) {
  return trim( wp_strip_all_tags( (string) $t ) );
}
function sanitize_textarea_field( $t ) {
  return wp_strip_all_tags( (string) $t );
}
function wp_strip_all_tags( $t ) {
  return strip_tags( $t );
}
function home_url( $path = '' ) {
  return 'https://example.test' . $path;
}
function admin_url( $path = '' ) {
  return 'https://example.test/wp-admin/' . $path;
}
function get_permalink( $id ) {
  return 'https://example.test/?page_id=' . (int) $id;
}
function date_i18n( $format, $time ) {
  return gmdate( 'Y-m-d', $time );
}
function wp_date( $format, $time ) {
  return gmdate( 'Y-m-d', $time );
}
function wp_mail( $to, $subject, $body ) {
  $GLOBALS['wp']['mail'][] = compact( 'to', 'subject', 'body' );
  return true;
}
function get_bloginfo() {
  return 'Test Site';
}
function wp_specialchars_decode( $t ) {
  return $t;
}

function maybe_unserialize( $v ) {
  return $v;
}

/**
 * Base class for the unit tests: fresh WordPress state and plugin settings per test.
 */
abstract class CB_Unit_Test extends PHPUnit\Framework\TestCase {
  protected function setUp(): void {
    cb_reset_wp();
    Corporate_Book::get_instance()->defaults = array(
      'subscription_enable'          => array( 'std' => 'false', 'choices' => array( 'true' => 'On', 'false' => 'Off' ) ),
      'subscription_preview_percent' => array( 'std' => '10', 'type' => 'number' ),
      'subscription_roles'           => array( 'std' => '', 'type' => 'text' ),
      'subscription_url'             => array( 'std' => '', 'type' => 'url' ),
      'subscription_currency'        => array( 'std' => 'INR', 'choices' => array( 'INR' => 'INR', 'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'AED' => 'AED' ) ),
      'subscription_plans'           => array( 'std' => '', 'type' => 'textarea' ),
      'duration'                     => array( 'std' => 800, 'type' => 'number' ),
    );
  }

  protected function settings( array $values ) {
    update_option( '_corporate_book_settings', $values );
  }

  protected function sign_in( $id, array $roles = array(), array $caps = array() ) {
    $user                     = new WP_User( $id, array( 'roles' => $roles, 'user_email' => "u$id@example.test", 'caps' => $caps ) );
    $GLOBALS['wp']['user']    = $user;
    $GLOBALS['wp']['caps']    = $caps;
    $GLOBALS['wp']['users'][ $id ] = $user;
    return $user;
  }
}

require dirname( __DIR__, 2 ) . '/3d-corporate-book.php';
Corporate_Book::get_instance()->defaults = array(); // filled per test; WordPress builds it on init
require dirname( __DIR__, 2 ) . '/inc/account.php';
require dirname( __DIR__, 2 ) . '/inc/settings.php';
require dirname( __DIR__, 2 ) . '/inc/shop.php';
