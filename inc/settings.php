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
      'general' => array( 'site_header', 'viewerType', 'webgl', 'realistic', 'hard', 'desk_scene', 'bg_color', 'bg_image', 'height', 'duration', 'texture_size', 'page_mode', 'single_page_mode', 'controls_position', 'auto_sound', 'autoplay', 'autoplay_duration', 'autoplay_start', 'shelf_image', 'post_urls' ),
      'layout' => array( 'paper_curl', 'paper_curl_amount', 'desk_tilt', 'desk_tilt_back', 'desk_tilt_side', 'padding_left', 'padding_right', 'zoom_ratio', 'scroll_wheel', 'stiffness', 'tilt_up', 'tilt_left', 'page_rotation', 'spiral', 'calendar_mode' ),
      'controls' => array( 'more_controls', 'hide_controls', 'enable_search', 'share_prefix' ),
      'pdf' => array( 'enable_annotation', 'link_target', 'range_size', 'attachment_lightbox' ),
      'translate' => array( 'external_translate', 'text_toggle_sound', 'text_toggle_thumbnails', 'text_toggle_outline', 'text_previous_page', 'text_next_page', 'text_toggle_fullscreen', 'text_zoom_in', 'text_zoom_out', 'text_toggle_help', 'text_single_page_mode', 'text_double_page_mode', 'text_goto_first_page', 'text_goto_last_page', 'text_share', 'text_mail_subject', 'text_mail_body', 'text_loading', 'text_sign_in', 'text_subscribe', 'text_locked', 'text_subscribe_button', 'text_preview' ),
      'print' => array( 'print_enable', 'print_unit_cost', 'print_pack_cost', 'print_copy_weight', 'print_pack_weight', 'print_ship_mode', 'print_zones', 'print_flat_rate', 'print_free_above', 'print_cod', 'print_terms_url', 'print_coupons', 'print_bundle_days', 'print_email', 'print_low_stock', 'print_return_days', 'invoice_business', 'invoice_gstin', 'invoice_address', 'invoice_prefix', 'shiprocket_email', 'shiprocket_password', 'shiprocket_pickup' ),
      'subscription' => array( 'subscription_enable', 'subscription_free_until', 'subscription_url', 'subscription_preview_percent', 'subscription_roles', 'subscription_login_shortcode', 'google_client_id', 'subscription_signup', 'signup_otp', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'smtp_from_name', 'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret', 'subscription_currency', 'subscription_plans' ),
      'advanced' => array( 'selectiveScriptLoading', 'enable_analytics', 'delete_data' ),
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
    add_action( 'admin_enqueue_scripts', array( $this, 'settings_scripts' ) );
    if ( $this->hook ) {
      add_action( 'load-' . $this->hook, array( $this, 'update_settings' ) );
    }
    
  }
  
  /**
   * Callback to create the settings page
   *
   * @since 1.2
   */
  /**
   * Loads the Media Library and the image picker on the Global Settings page.
   *
   * @param string $hook Current admin page.
   */
  /**
   * Settings shown in simple mode, with plain-language help. Everything else appears under "Show all settings".
   *
   * @return string[] Key => help text.
   */
  private function essentials() {
    return array(
      'viewerType'                   => __( 'How the book looks: Flipbook (pages turn like a real book), Vertical Reader (scroll down like a PDF) or Slider.', '3d-corporate-book' ),
      'webgl'                        => __( '3D looks most realistic. 2D is lighter for old phones and computers.', '3d-corporate-book' ),
      'realistic'                    => __( 'On: hard cover, page thickness, the cover opens by itself and page corners lift under the mouse (3D only).', '3d-corporate-book' ),
      'paper_curl'                   => __( 'Pages bow at the spine and curl as they turn, the strongest "real paper" effect (3D only).', '3d-corporate-book' ),
      'paper_curl_amount'            => __( '5 is almost flat, 60 is very soft paper.', '3d-corporate-book' ),
      'desk_tilt'                    => __( 'The book leans back and to the side in perspective, like it is lying on a table (3D only). Off: straight on.', '3d-corporate-book' ),
      'desk_tilt_back'               => __( 'How far the book leans back, 0 to 30 degrees.', '3d-corporate-book' ),
      'desk_tilt_side'               => __( 'How far it turns sideways, -20 (left) to 20 (right) degrees.', '3d-corporate-book' ),
      'desk_scene'                   => __( 'On: the book lies on a wooden desk with a lamp, coffee, pen and glasses. Readers can switch the lamp on and off. Off: the colour or image below is used.', '3d-corporate-book' ),
      'bg_color'                     => __( 'Colour behind the book (when Reading Desk is off).', '3d-corporate-book' ),
      'bg_image'                     => __( 'Optional picture behind the book. It replaces the colour.', '3d-corporate-book' ),
      'post_urls'                    => __( 'On: every book gets its own web page, and /corporate-book/ lists all books.', '3d-corporate-book' ),
      'enable_search'                => __( 'Adds a search button so readers can find words in the PDF.', '3d-corporate-book' ),
      'subscription_enable'          => __( 'On: readers see only the free preview until they subscribe. Off: every book is free to read.', '3d-corporate-book' ),
      'subscription_free_until'      => __( 'Optional promotion: every book is free to read in full until the end of this day. Empty = no promotion.', '3d-corporate-book' ),
      'subscription_preview_percent' => __( 'How much of each book is free. 10 means the first 10% of pages.', '3d-corporate-book' ),
      'subscription_signup'          => __( 'On: readers can create an account themselves.', '3d-corporate-book' ),
      'google_client_id'             => __( 'Optional. Adds a "Continue with Google" button. Leave empty if you do not use it.', '3d-corporate-book' ),
      'razorpay_key_id'              => __( 'From Razorpay Dashboard > Account & Settings > API Keys. Start with a Test Mode key (rzp_test_...).', '3d-corporate-book' ),
      'razorpay_key_secret'          => __( 'Shown next to the Key ID in Razorpay. Kept on your server only.', '3d-corporate-book' ),
      'razorpay_webhook_secret'      => __( 'Recommended. Makes payments count even if the reader closes the page. See the setup steps in the readme.', '3d-corporate-book' ),
      'subscription_currency'        => __( 'Currency of your prices.', '3d-corporate-book' ),
      'print_enable'                 => __( 'On: readers can buy printed copies of books that have a print price.', '3d-corporate-book' ),
      'print_unit_cost'              => __( 'What one copy costs you to print or buy (for example 250). Used only for the profit figures.', '3d-corporate-book' ),
      'print_pack_cost'              => __( 'Box or mailer, bubble wrap and tape for one order (for example 10). Used only for the profit figures.', '3d-corporate-book' ),
      'print_copy_weight'            => __( 'Weight of one copy in grams (for example 200).', '3d-corporate-book' ),
      'print_pack_weight'            => __( 'Weight of the box or mailer in grams (for example 50). Couriers charge per 500 g slab.', '3d-corporate-book' ),
      'print_ship_mode'              => __( 'By zone: readers pay the courier rate for their area. Flat: one average rate. Free: you pay the courier.', '3d-corporate-book' ),
      'print_zones'                  => __( 'Courier rates from Noida, including GST. Edit them in the table.', '3d-corporate-book' ),
      'print_flat_rate'              => __( 'Average courier cost per 500 g, used for the flat rate (for example 55).', '3d-corporate-book' ),
      'print_free_above'             => __( 'Optional. Orders with books worth at least this amount ship free.', '3d-corporate-book' ),
      'print_terms_url'              => __( 'Your Terms of Use page address, linked under the cart total next to the Privacy Policy.', '3d-corporate-book' ),
      'print_cod'                    => __( 'On: readers may pay the courier in cash instead of paying online.', '3d-corporate-book' ),
      'print_email'                  => __( 'Where new order emails go. Empty = site admin email.', '3d-corporate-book' ),
      'print_coupons'                => __( 'Optional discount codes, one per line: CODE | 10% or CODE | 100, optionally | last day (YYYY-MM-DD).', '3d-corporate-book' ),
      'print_bundle_days'            => __( 'Free online reading days given with each print order. 0 = none.', '3d-corporate-book' ),
      'invoice_business'             => __( 'Name on invoices. Empty = site name.', '3d-corporate-book' ),
      'invoice_gstin'                => __( 'Your GST number, shown on invoices if filled in.', '3d-corporate-book' ),
      'subscription_plans'           => __( 'One plan per line: Name | Price | Days. Example: Monthly | 399 | 30', '3d-corporate-book' ),
    );
  }

  /**
   * Section headings in the long tabs, keyed by the first setting of each section.
   *
   * @return string[]
   */
  private function sections() {
    return array(
      'viewerType'                   => __( 'Display', '3d-corporate-book' ),
      'desk_scene'                   => __( 'Background', '3d-corporate-book' ),
      'height'                       => __( 'Size and pages', '3d-corporate-book' ),
      'controls_position'            => __( 'Controls and sound', '3d-corporate-book' ),
      'autoplay'                     => __( 'Autoplay', '3d-corporate-book' ),
      'shelf_image'                  => __( 'Book shelf and book pages', '3d-corporate-book' ),
      'paper_curl'                   => __( 'Book look', '3d-corporate-book' ),
      'padding_left'                 => __( 'Spacing, zoom and turning', '3d-corporate-book' ),
      'external_translate'           => __( 'Translation plugin', '3d-corporate-book' ),
      'text_toggle_sound'            => __( 'Toolbar buttons', '3d-corporate-book' ),
      'text_share'                   => __( 'Sharing', '3d-corporate-book' ),
      'text_loading'                 => __( 'Messages', '3d-corporate-book' ),
      'text_sign_in'                 => __( 'Lock panel and cover card', '3d-corporate-book' ),
      'subscription_enable'          => __( 'Lock', '3d-corporate-book' ),
      'subscription_login_shortcode' => __( 'Sign in', '3d-corporate-book' ),
      'razorpay_key_id'              => __( 'Razorpay', '3d-corporate-book' ),
      'subscription_currency'        => __( 'Plans', '3d-corporate-book' ),
      'print_enable'                 => __( 'Selling', '3d-corporate-book' ),
      'print_copy_weight'            => __( 'Parcel', '3d-corporate-book' ),
      'print_ship_mode'              => __( 'Shipping charge', '3d-corporate-book' ),
      'print_cod'                    => __( 'Orders', '3d-corporate-book' ),
      'invoice_business'             => __( 'Invoices', '3d-corporate-book' ),
      'shiprocket_email'             => __( 'Shiprocket (optional)', '3d-corporate-book' ),
    );
  }

  public function settings_scripts( $hook ) {
    if ( $hook !== $this->hook ) {
      return;
    }
    wp_enqueue_media();
    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_script( 'corporate-book-admin', plugins_url( '../assets/js/corporate-book-admin.js', __FILE__ ), array( 'jquery', 'wp-color-picker' ), $this->base->version, true );
    wp_add_inline_style( 'wp-color-picker', '.cb-settings-section{margin:28px 0 4px;padding:0 0 8px;border-bottom:2px solid #0093d0;color:#0b1f5c;font-size:15px;text-transform:uppercase;letter-spacing:.04em}.cb-settings-section:first-child{margin-top:8px}.cb-simple-root:not(.cb-show-all) .cb-advanced{display:none!important}.cb-simple-bar{display:flex;gap:12px;align-items:center;justify-content:space-between;margin:0;padding:12px 16px;background:#f0f6fc;border-bottom:1px solid #c5d9ed;font-size:13px}.cb-simple-bar label{font-weight:600;white-space:nowrap}.cb-simple-bar input{margin-right:6px}.corporate-book-settings .cb-simple-bar{padding-right:200px}' );
  }

  public function settings_page() {
    
    $tabs = array(
        'general' => __( 'General', '3d-corporate-book' ),
        'layout' => __( 'Layout', '3d-corporate-book' ),
        'controls' => __( 'Controls', '3d-corporate-book' ),
        'pdf' => __( 'PDF', '3d-corporate-book' ),
        'translate' => __( 'Translate', '3d-corporate-book' ),
        'subscription' => __( 'Subscription', '3d-corporate-book' ),
        'print' => __( 'Shipping & Selling', '3d-corporate-book' ),
        'advanced' => __( 'Advanced', '3d-corporate-book' )
    );
    
    //create tabs and content
    ?>

      <h2><?php echo esc_html( get_admin_page_title() ); ?></h2>
      <form id="corporate-book-settings" method="post" class="corporate-book-settings postbox cb-simple-root">
        <div class="cb-simple-bar"><span><?php esc_html_e( 'Showing the main settings. Everything else already has a good default.', '3d-corporate-book' ); ?></span>
          <label><input type="checkbox" id="cb-show-all"/><?php esc_html_e( 'Show all settings', '3d-corporate-book' ); ?></label></div>
        
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
                    <li class="corporate-book-update-hash corporate-book-tab <?php echo( $active_set == false ? 'corporate-book-active' : '' ) ?><?php echo array_intersect( $this->tab_keys[ $id ], array_keys( $this->essentials() ) ) ? '' : ' cb-advanced'; ?>">
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
                  
                  $essentials = $this->essentials();
                  $keys       = $this->tab_keys[ $id ];
                  foreach ( $keys as $index => $key ) {
                    if ( isset( $this->sections()[ $key ] ) ) {
                      // A heading is advanced when no setting up to the next heading is shown in simple mode.
                      $simple = false;
                      for ( $next = $index; $next < count( $keys ) && ( $next === $index || ! isset( $this->sections()[ $keys[ $next ] ] ) ); $next++ ) {
                        $simple = $simple || isset( $essentials[ $keys[ $next ] ] );
                      }
                      echo '<h3 class="cb-settings-section' . ( $simple ? '' : ' cb-advanced' ) . '">' . esc_html( $this->sections()[ $key ] ) . '</h3>';
                    }
                    if ( isset( $essentials[ $key ] ) && is_array( $this->base->defaults[ $key ] ) ) {
                      $this->base->defaults[ $key ]['desc'] = $essentials[ $key ];
                    }
                    if ( ! isset( $essentials[ $key ] ) ) {
                      echo '<style>.cb-simple-root:not(.cb-show-all) #corporate_book_' . esc_attr( $key ) . '_box{display:none}</style>';
                    }
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
    self::simple_mode_script();
    self::shipping_script();
    
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
    if ( 'subscription_url' === $key || ( is_array( $setting ) && isset( $setting['type'] ) && in_array( $setting['type'], array( 'url', 'upload' ), true ) ) ) {
      return esc_url_raw( $value );
    }
    if ( 'subscription_roles' === $key ) {
      // The reader role is given to everyone who signs up, so it can never mean "paid".
      return implode( ',', array_diff( array_filter( array_map( 'sanitize_key', explode( ',', $value ) ) ), array( 'cb_reader' ) ) );
    }
    if ( is_array( $setting ) && isset( $setting['type'] ) && 'date' === $setting['type'] ) {
      return preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $value ) ) ? trim( $value ) : '';
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
    if ( 'subscription_currency' === $key ) {
      $currency = strtoupper( substr( preg_replace( '/[^a-zA-Z]/', '', $value ), 0, 3 ) );
      return 3 === strlen( $currency ) ? $currency : 'INR';
    }
    if ( is_array( $setting ) && isset( $setting['type'] ) && 'textarea' === $setting['type'] ) {
      return sanitize_textarea_field( $value );
    }
    return sanitize_text_field( $value );
  }

  /**
   * Warns when the default WordPress role "subscriber" counts as a paid subscription.
   */
  public function subscription_role_warning() {
    $roles = explode( ',', (string) $this->base->get_config( 'subscription_roles' ) );
    if ( in_array( 'subscriber', $roles, true ) ) {
      echo '<div class="notice notice-warning inline cb-role-warning"><p>' . esc_html__( 'Warning: WordPress gives the "subscriber" role to every new account, so everyone who signs up would read the full books. List only the role your membership plugin gives paying members. The Corporate Book Reader role is never accepted here.', '3d-corporate-book' ) . '</p></div>';
    }
    // Plan lines that are not "Name | Price | Days" are ignored by the site, so say which ones.
    $bad = array();
    foreach ( preg_split( '/\r\n|\r|\n/', (string) $this->base->get_config( 'subscription_plans' ) ) as $number => $line ) {
      $parts = array_map( 'trim', explode( '|', $line ) );
      if ( '' !== trim( $line ) && ! ( 3 === count( $parts ) && '' !== $parts[0] && is_numeric( $parts[1] ) && $parts[1] > 0 && ctype_digit( $parts[2] ) && $parts[2] > 0 ) ) {
        $bad[] = sprintf( /* translators: 1: line number, 2: line */ __( 'line %1$d: "%2$s"', '3d-corporate-book' ), $number + 1, $line );
      }
    }
    if ( $bad ) {
      echo '<div class="notice notice-error inline cb-plan-errors"><p>' . esc_html__( 'These Subscription Plans lines are ignored because they are not "Name | Price | Days":', '3d-corporate-book' ) . ' ' . esc_html( implode( '; ', $bad ) ) . '</p></div>';
    }
    echo '<p><button type="button" class="button" id="corporate_book_razorpay_test" data-nonce="' . esc_attr( wp_create_nonce( 'corporate_book_razorpay_test' ) ) . '">' . esc_html__( 'Test Razorpay connection', '3d-corporate-book' ) . '</button> <span id="corporate_book_razorpay_result" role="status"></span></p>';
    echo "<script>document.getElementById('corporate_book_razorpay_test').addEventListener('click',function(){var b=this,r=document.getElementById('corporate_book_razorpay_result');r.textContent='…';var f=new FormData();f.append('action','corporate_book_razorpay_test');f.append('_ajax_nonce',b.dataset.nonce);fetch(ajaxurl,{method:'POST',body:f,credentials:'same-origin'}).then(function(x){return x.json();}).then(function(a){r.textContent=a.data;r.style.color=a.success?'#1a7f37':'#b32d2e';}).catch(function(){r.textContent='Failed';});});</script>";
    echo '<p><button type="button" class="button" id="corporate_book_test_email" data-nonce="' . esc_attr( wp_create_nonce( 'corporate_book_test_email' ) ) . '">' . esc_html__( 'Send test email', '3d-corporate-book' ) . '</button> <span id="corporate_book_test_email_result"></span> <span class="description">' . esc_html__( 'Save your email settings first. The test goes to your own address.', '3d-corporate-book' ) . '</span></p>';
    echo "<script>document.getElementById('corporate_book_test_email').addEventListener('click',function(){var b=this,r=document.getElementById('corporate_book_test_email_result');r.textContent='…';var f=new FormData();f.append('action','corporate_book_test_email');f.append('_ajax_nonce',b.getAttribute('data-nonce'));fetch(ajaxurl,{method:'POST',body:f,credentials:'same-origin'}).then(function(x){return x.json();}).then(function(d){r.textContent=d.data;r.style.color=d.success?'#16794a':'#b42318';},function(){r.textContent='Failed';});});</script>";
  }

  /**
   * "Show all settings" / "Show more options" switch; the choice is remembered in this browser.
   */
  /**
   * Shipping & Selling tab: the courier-rate table (it writes the rates text field) and a live profit calculator.
   */
  public static function shipping_script() {
    $labels = array(
      'ncr'    => __( 'Delhi NCR (Delhi, Noida, Ghaziabad, Gurugram, Faridabad)', '3d-corporate-book' ),
      'metro'  => __( 'Metro cities (Mumbai, Bengaluru, Chennai, Kolkata, Hyderabad, Pune, Ahmedabad)', '3d-corporate-book' ),
      'india'  => __( 'Rest of India', '3d-corporate-book' ),
      'remote' => __( 'North-East, J&K, Ladakh, islands, army post', '3d-corporate-book' ),
    );
    $text = array(
      'zone'   => __( 'Zone', '3d-corporate-book' ),
      'first'  => __( 'First 500 g (₹)', '3d-corporate-book' ),
      'extra'  => __( 'Each extra 500 g (₹)', '3d-corporate-book' ),
      'days'   => __( 'Delivery days', '3d-corporate-book' ),
      'calc'   => __( 'Profit per order', '3d-corporate-book' ),
      'price'  => __( 'Sale price per copy (₹)', '3d-corporate-book' ),
      'copies' => __( 'Copies', '3d-corporate-book' ),
      'to'     => __( 'Delivered to', '3d-corporate-book' ),
      'sale'   => __( 'Reader pays', '3d-corporate-book' ),
      'books'  => __( 'Books', '3d-corporate-book' ),
      'ship'   => __( 'Shipping charged', '3d-corporate-book' ),
      'cost'   => __( 'Product cost', '3d-corporate-book' ),
      'pack'   => __( 'Packaging', '3d-corporate-book' ),
      'courier'=> __( 'Courier (paid by you)', '3d-corporate-book' ),
      'profit' => __( 'Your profit', '3d-corporate-book' ),
      'margin' => __( 'margin', '3d-corporate-book' ),
      'even'   => __( 'Lowest price without a loss:', '3d-corporate-book' ),
      'weight' => __( 'Parcel weight', '3d-corporate-book' ),
      'slabs'  => __( 'slab(s) of 500 g', '3d-corporate-book' ),
    );
    echo '<style>.cb-zone-table{border-collapse:collapse;margin:6px 0 4px;max-width:760px;width:100%}.cb-zone-table th,.cb-zone-table td{padding:6px 8px;border-bottom:1px solid #e2e4e7;text-align:left;font-size:13px}.cb-zone-table input{width:90px}.cb-calc{max-width:760px;margin:18px 0;padding:16px 18px;border:1px solid #c5d9ed;border-radius:10px;background:#f6fbff}.cb-calc h4{margin:0 0 10px;font-size:15px;color:#0b1f5c}.cb-calc-in{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:12px}.cb-calc-in label{display:flex;flex-direction:column;font-size:12px;font-weight:600;gap:4px}.cb-calc-in input,.cb-calc-in select{min-width:120px}.cb-calc table{width:100%;max-width:460px;border-collapse:collapse}.cb-calc td{padding:4px 0;font-size:13px}.cb-calc td:last-child{text-align:right;font-variant-numeric:tabular-nums}.cb-calc .cb-calc-total td{border-top:2px solid #0b1f5c;font-weight:700;font-size:15px;padding-top:8px}.cb-calc .cb-loss td{color:#b32d2e}.cb-calc-note{margin:8px 0 0;font-size:12px;color:#50575e}</style>';
    echo '<script>(function(){var L=' . wp_json_encode( $labels ) . ',T=' . wp_json_encode( $text ) . ';var area=document.getElementById("corporate_book_print_zones");if(!area){return;}'
      . 'function num(id,d){var e=document.getElementById("corporate_book_"+id);var v=e?parseFloat(e.value):NaN;return isNaN(v)?d:v;}'
      . 'function rates(){var r={ncr:[40,30,"1-2"],metro:[65,50,"2-4"],india:[75,60,"3-6"],remote:[95,80,"5-9"]};area.value.split(/\r?\n/).forEach(function(l){var p=l.split("|").map(function(x){return x.trim();});if(p.length>=3&&r[p[0]]&&!isNaN(parseFloat(p[1]))&&!isNaN(parseFloat(p[2]))){r[p[0]]=[parseFloat(p[1]),parseFloat(p[2]),p[3]||""];}});return r;}'
      . 'var t=document.createElement("table");t.className="cb-zone-table";var h=t.insertRow();[T.zone,T.first,T.extra,T.days].forEach(function(x){var th=document.createElement("th");th.textContent=x;h.appendChild(th);});var r=rates();'
      . 'Object.keys(L).forEach(function(k){var row=t.insertRow();row.insertCell().textContent=L[k];[0,1,2].forEach(function(i){var c=row.insertCell(),inp=document.createElement("input");inp.type=i<2?"number":"text";if(i<2){inp.min="0";inp.step="any";}inp.value=r[k][i];inp.setAttribute("data-zone",k);inp.setAttribute("data-i",i);inp.setAttribute("aria-label",L[k]+" "+[T.first,T.extra,T.days][i]);c.appendChild(inp);});});'
      . 'function save(){var r=rates();t.querySelectorAll("input").forEach(function(i){r[i.getAttribute("data-zone")][+i.getAttribute("data-i")]=i.value.trim();});area.value=Object.keys(L).map(function(k){return k+" | "+r[k][0]+" | "+r[k][1]+" | "+r[k][2];}).join("\n");calc();}'
      . 't.addEventListener("input",save);area.style.display="none";area.parentNode.insertBefore(t,area);'
      . 'var box=document.createElement("div");box.className="cb-calc";box.innerHTML="<h4></h4><div class=\"cb-calc-in\"><label><span></span><input type=\"number\" min=\"0\" step=\"any\" value=\"499\" data-k=\"price\"></label><label><span></span><input type=\"number\" min=\"1\" max=\"10\" value=\"1\" data-k=\"copies\"></label><label><span></span><select data-k=\"zone\"></select></label></div><table></table><p class=\"cb-calc-note\"></p>";'
      . 'box.querySelector("h4").textContent=T.calc;var sp=box.querySelectorAll(".cb-calc-in span");sp[0].textContent=T.price;sp[1].textContent=T.copies;sp[2].textContent=T.to;var sel=box.querySelector("select");Object.keys(L).forEach(function(k){var o=document.createElement("option");o.value=k;o.textContent=L[k];sel.appendChild(o);});sel.value="metro";'
      . 't.parentNode.insertBefore(box,t.nextSibling);'
      . 'function rs(v){return "\u20b9"+(Math.round(v*100)/100).toLocaleString("en-IN");}'
      . 'function calc(){var q=function(k){return box.querySelector("[data-k="+k+"]");};var price=parseFloat(q("price").value)||0,n=Math.max(1,parseInt(q("copies").value,10)||1),z=q("zone").value,r=rates()[z];'
      . 'var g=n*num("print_copy_weight",200)+num("print_pack_weight",50),sl=Math.max(1,Math.ceil(g/500)),courier=r[0]+(sl-1)*r[1];var mode=(document.getElementById("corporate_book_print_ship_mode")||{}).value||"zone";'
      . 'var ship=mode==="free"?0:(mode==="flat"?num("print_flat_rate",55)*sl:courier);var free=num("print_free_above",0);if(free>0&&price*n>=free){ship=0;}'
      . 'var cost=num("print_unit_cost",250)*n,pack=num("print_pack_cost",10),profit=price*n+ship-cost-pack-courier;'
      . 'var rows=[[T.books+" ("+n+" \u00d7 "+rs(price)+")",price*n],[T.ship,ship],["\u2212 "+T.cost,-cost],["\u2212 "+T.pack,-pack],["\u2212 "+T.courier,-courier]];var tb=box.querySelector("table");tb.textContent="";'
      . 'rows.forEach(function(x){var row=tb.insertRow();row.insertCell().textContent=x[0];row.insertCell().textContent=(x[1]<0?"\u2212 ":"")+rs(Math.abs(x[1]));});'
      . 'var tr=tb.insertRow();tr.className="cb-calc-total"+(profit<0?" cb-loss":"");tr.insertCell().textContent=T.profit+(price>0?" ("+Math.round(100*profit/(price*n+ship))+"% "+T.margin+")":"");tr.insertCell().textContent=(profit<0?"\u2212 ":"")+rs(Math.abs(profit));'
      . 'var even=Math.max(0,(cost+pack+courier-ship)/n);box.querySelector(".cb-calc-note").textContent=T.weight+": "+g+" g, "+sl+" "+T.slabs+". "+T.even+" "+rs(Math.ceil(even))+".";}'
      . 'box.addEventListener("input",calc);document.addEventListener("input",function(e){if(e.target&&/^corporate_book_print_/.test(e.target.id||"")){calc();}});document.addEventListener("change",function(e){if(e.target&&e.target.id==="corporate_book_print_ship_mode"){calc();}});calc();})();</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed script; labels are JSON-encoded.
  }

  public static function simple_mode_script() {
    echo '<script>(function(){var box=document.getElementById(\'cb-show-all\');if(!box)return;var root=box.closest(\'.cb-simple-root\');function apply(){root.classList.toggle(\'cb-show-all\',box.checked);try{localStorage.setItem(\'cbShowAll\',box.checked?\'1\':\'\');}catch(e){}var active=root.querySelector(\'.corporate-book-tabs-list li.corporate-book-active\');if(active&&getComputedStyle(active).display===\'none\'){root.querySelector(\'.corporate-book-tabs-list li a\').click();}}try{box.checked=localStorage.getItem(\'cbShowAll\')===\'1\';}catch(e){}box.addEventListener(\'change\',apply);apply();})();</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed script.
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
    // Book Post URLs change the rewrite rules; post-type.php refreshes them on the next page load.
    update_option( 'corporate_book_flush_rules', 1 );

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
