<?php

/**
 * Author : DeipGroup
 * Date: 8/11/2016
 * Time: 4:15 PM
 *
 * @package corporate_book
 *
 * @since   corporate_book 1.2
 */
class Corporate_Book_Settings {
  
  /**
   * Holds the singleton class object.
   *
   * @since 1.2.0
   *
   * @var object
   */
  public static $instance;
  
  public $hook;
  
  /**
   * Holds the base Corporate_Book class object.
   *
   * @since 1.2.0
   *
   * @var object
   */
  public $base;
  
  /**
   * Holds the base Corporate_Book class fields.
   *
   * @since 1.2.0
   *
   * @var object
   */
  public $fields;

  /**
   * Settings shown in each tab of the Global Settings page (TOP FEATURES).
   *
   * @var array
   */
  public $tab_keys = array(
      'general' => array( 'viewerType', 'webgl', 'hard', 'bg_color', 'height', 'duration', 'texture_size', 'page_mode', 'single_page_mode', 'controls_position', 'auto_sound', 'autoplay', 'autoplay_duration', 'autoplay_start' ),
      'layout' => array( 'padding_left', 'padding_right', 'zoom_ratio', 'scroll_wheel', 'stiffness' ),
      'controls' => array( 'more_controls', 'hide_controls', 'enable_search', 'share_prefix' ),
      'pdf' => array( 'enable_annotation', 'link_target', 'range_size', 'attachment_lightbox' ),
      'translate' => array( 'external_translate', 'text_toggle_sound', 'text_toggle_thumbnails', 'text_toggle_outline', 'text_previous_page', 'text_next_page', 'text_toggle_fullscreen', 'text_zoom_in', 'text_zoom_out', 'text_toggle_help', 'text_single_page_mode', 'text_double_page_mode', 'text_goto_first_page', 'text_goto_last_page', 'text_share', 'text_mail_subject', 'text_mail_body', 'text_loading', 'text_sign_in', 'text_subscribe', 'text_locked' ),
      'subscription' => array( 'subscription_enable', 'subscription_url', 'subscription_preview_percent', 'subscription_roles', 'subscription_login_shortcode' ),
      'advanced' => array( 'selectiveScriptLoading', 'enable_analytics' ),
  );

  
  /**
   * Primary class constructor.
   *
   * @since 1.2.0
   */
  public function __construct() {
    
    // Load the base class object.
    $this->base = Corporate_Book::get_instance();
    
    add_action( 'admin_menu', array( $this, 'settings_menu' ) );
    
    $this->fields = array_merge( array(), $this->base->defaults );
    
    foreach ( $this->fields as $key => $value ) {
      
      if ( isset( $value['choices'] ) && is_array( $value['choices'] ) && isset( $value['choices']['global'] ) ) {
        unset( $this->fields[ $key ]['choices']['global'] );
      }
      
    }
    
    // Load the metabox hooks and filters.
    //		add_action('add_meta_boxes', array($this, 'add_meta_boxes'), 100);
    
    // Add action to save metabox config options.
    //		add_action('save_post', array($this, 'save_meta_boxes'), 10, 2);
  }
  
  /**
   * Creates menu for the settings page
   *
   * @since 1.2
   */
  public function settings_menu() {
   
    $this->hook = add_submenu_page( 'edit.php?post_type=corporate_book', __( 'Corporate Book Global Settings', '3d-corporate-book' ), __( 'Global Settings', '3d-corporate-book' ), 'manage_options', $this->base->plugin_slug . '-settings',
        array( $this, 'settings_page' ) );
    
    //The resulting page's hook_suffix, or false if the user does not have the capability required.
    if ( $this->hook ) {
      add_action( 'load-' . $this->hook, array( $this, 'update_settings' ) );
    }
    
  }
  
  /**
   * Callback to create the settings page
   *
   * @since 1.2
   */
  public function settings_page() {
    
    $tabs = array(
        'general' => __( 'General', '3d-corporate-book' ),
        'layout' => __( 'Layout', '3d-corporate-book' ),
        'controls' => __( 'Controls', '3d-corporate-book' ),
        'pdf' => __( 'PDF', '3d-corporate-book' ),
        'translate' => __( 'Translate', '3d-corporate-book' ),
        'subscription' => __( 'Subscription', '3d-corporate-book' ),
        'advanced' => __( 'Advanced', '3d-corporate-book' )
    );
    
    //create tabs and content
    ?>

      <h2><?php echo esc_html( get_admin_page_title() ); ?></h2>
      <form id="corporate-book-settings" method="post" class="corporate-book-settings postbox">
        
        <?php
        wp_nonce_field( 'corporate_book_settings_nonce', 'corporate_book_settings_nonce' );
        submit_button( __( 'Update Settings', '3d-corporate-book' ), 'primary', 'corporate_book_settings_submit', false );
        ?>

          <div class="corporate-book-tabs">
              <ul class="corporate-book-tabs-list">
                <?php
                //create tabs
                $active_set = false;
                foreach ( (array) $tabs as $id => $title ) {
                  ?>
                    <li class="corporate-book-update-hash corporate-book-tab <?php echo( $active_set == false ? 'corporate-book-active' : '' ) ?>">
                        <a href="#corporate-book-tab-content-<?php echo esc_html($id) ?>"><?php echo esc_html($title) ?></a></li>
                  <?php $active_set = true;
                }
                ?>
              </ul>
            <?php
            
            $active_set = false;
            foreach ( (array) $tabs as $id => $title ) {
              ?>
                <div id="corporate-book-tab-content-<?php echo esc_html($id) ?>"
                     class="corporate-book-tab-content <?php echo( $active_set == false ? "corporate-book-active" : "" ) ?>">
                  
                  <?php
                  $active_set = true;
                  
                  foreach ( $this->tab_keys[ $id ] as $key ) {
                    // Text defaults are plain strings, so they get a text field labelled with the default text.
                    $setting = is_array( $this->base->defaults[ $key ] ) ? null : array( 'title' => 'std', 'type' => 'text' );
                    $this->base->create_setting( $key, $setting );
                  }
                  if ( 'subscription' === $id ) {
                    $this->subscription_role_warning();
                  }
                  echo '<div class="corporate-book-box"></div>';
                  
                  ?>
                </div>
            <?php } ?>
          </div>
      </form>
    <?php
    
  }
  
  /**
   * Update settings
   *
   * @return null Invalid nonce / no need to save
   * @since 1.2.0.1
   *
   */
  /**
   * Sanitizes one Global Settings value: choices must be listed, numbers numeric, the rest plain text.
   *
   * @param string $key   Setting key.
   * @param string $value Unslashed submitted value.
   *
   * @return mixed Clean value, or the default when invalid.
   */
  public function sanitize_setting( $key, $value ) {
    $setting = $this->base->defaults[ $key ];
    if ( 'subscription_url' === $key ) {
      return esc_url_raw( $value );
    }
    if ( 'subscription_roles' === $key ) {
      return implode( ',', array_filter( array_map( 'sanitize_key', explode( ',', $value ) ) ) );
    }
    if ( 'subscription_preview_percent' === $key ) {
      return is_numeric( $value ) ? max( 1, min( 100, (int) ceil( $value ) ) ) : 10;
    }
    if ( is_array( $setting ) && isset( $setting['choices'] ) ) {
      return ( 'global' !== $value && array_key_exists( $value, $setting['choices'] ) ) ? $value : $this->base->get_default( $key );
    }
    if ( is_array( $setting ) && isset( $setting['type'] ) && 'number' === $setting['type'] ) {
      return is_numeric( $value ) ? 0 + $value : $this->base->get_default( $key );
    }
    return sanitize_text_field( $value );
  }

  /**
   * Warns when the default WordPress role "subscriber" counts as a paid subscription.
   */
  public function subscription_role_warning() {
    $roles = explode( ',', (string) $this->base->get_config( 'subscription_roles' ) );
    if ( in_array( 'subscriber', $roles, true ) ) {
      echo '<div class="notice notice-warning inline cb-role-warning"><p>' . esc_html__( 'Warning: WordPress gives the "subscriber" role to every new account, so everyone who signs up would read the full book.', '3d-corporate-book' ) . '</p></div>';
    }
  }

  public function update_settings() {
    
    // Check form was submitted
    if ( !isset( $_POST['corporate_book_settings_submit'] ) ) {
      return;
    }
    
    // Check nonce is valid
    if ( !wp_verify_nonce( isset($_POST['corporate_book_settings_nonce']) ? sanitize_key(wp_unslash($_POST['corporate_book_settings_nonce'])) : null, 'corporate_book_settings_nonce' ) ) {
      return;
    }
    
    // Sanitize all user inputs.
    
    $sanitized_data = array();
    $input = isset( $_POST['_corporate_book'] ) && is_array( $_POST['_corporate_book'] ) ? wp_unslash( $_POST['_corporate_book'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per key below.
    foreach ( $this->tab_keys as $keys ) {
      foreach ( $keys as $key ) {
        if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) ) {
          $sanitized_data[ $key ] = $this->sanitize_setting( $key, $input[ $key ] );
        }
      }
    }
    
    $settings = is_multisite() ? get_blog_option( null, '_corporate_book_settings', array() ) : get_option( '_corporate_book_settings', array() );
    if ( empty( $settings ) || !is_array($settings)) {
      $settings = array();
    }
    $settings = array_merge( $settings, $sanitized_data );
    
    if ( is_multisite() ) {
      // Update options
      update_blog_option( null, '_corporate_book_settings', $settings );
    } else {
      // Update options
      update_option( '_corporate_book_settings', $settings );
    }
    // Show confirmation
    // Turning the lock on protects the PDFs of books saved before it.
    if ( 'true' === $this->base->get_config( 'subscription_enable' ) ) {
      Corporate_Book_Protected_PDF::protect_all();
    }

    add_action( 'admin_notices', array( $this, 'updated_settings' ) );
    
  }
  
  /**
   * display a saved notice
   *
   * @since 1.2.0.1
   */
  public function updated_settings() {
    ?>
      <div class="updated">
          <p><?php esc_html_e( 'Settings updated.', '3d-corporate-book' ); ?></p>
      </div>
    <?php
    
  }
  
  /**
   * Returns the singleton instance of the class.
   *
   * @return object Corporate_Book_Settings object.
   * @since 1.2.0
   *
   */
  public static function get_instance() {
    
    if ( !isset( self::$instance )
        && !( self::$instance instanceof Corporate_Book_Settings ) ) {
      self::$instance = new Corporate_Book_Settings();
    }
    
    return self::$instance;
    
  }
}

// Load the Corporate_Book_Settings class.
$corporate_book_settings = Corporate_Book_Settings::get_instance();
