<?php

/**
 * Corporate Book Metaboxes
 *
 * creates, displays and saves metaboxes and their values
 *
 * @since   1.0.0
 *
 * @package corporateBook
 * @author  Deepak Ghimire
 */
class Corporate_Book_Meta_boxes {

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
   * Holds the base Corporate_Book class fields.
   *
   * @since 1.0.0
   *
   * @var object
   */
  public $fields;

  /**
   * Primary class constructor.
   *
   * @since 1.0.0
   */
  public function __construct() {

    // Load the base class object.
    $this->base = Corporate_Book::get_instance();

    $this->fields = $this->base->defaults;

    // Load metabox assets.
    add_action( 'admin_enqueue_scripts', array( $this, 'meta_box_styles_scripts' ) );

    // Load the metabox hooks and filters.
    add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 100 );

    // Add action to save metabox config options.
    add_action( 'save_post_corporate_book', array( $this, 'save_meta_boxes' ), 10, 2 );
  }

  /**
   * Loads styles and scripts for our metaboxes.
   *
   * @return null Bail out if not on the proper screen.
   * @since 1.0.0
   *
   */
  public function meta_box_styles_scripts() {

    global $id, $post;

    if ( isset( get_current_screen()->post_type )
         && $this->base->plugin_slug !== get_current_screen()->post_type ) {
      return;
    }

    // Load necessary metabox styles.
    wp_register_style( $this->base->plugin_slug . '-metabox-style', plugins_url( '../assets/css/metaboxes.css', __FILE__ ), array(), $this->base->version );
    wp_enqueue_style( $this->base->plugin_slug . '-metabox-style' );
    wp_enqueue_style( 'wp-color-picker' );

    // Load necessary metabox scripts.
    wp_register_script( $this->base->plugin_slug . '-metabox-script', plugins_url( '../assets/js/metaboxes.js', __FILE__ ),
      array( 'jquery-ui-sortable', 'jquery-ui-draggable', 'jquery-ui-resizable', 'wp-color-picker' ), $this->base->version, true );
    wp_enqueue_script( $this->base->plugin_slug . '-metabox-script' );
    // "Create preview" builds the subscription preview PDF in the browser with pdf-lib.
    wp_register_script( $this->base->plugin_slug . '-pdf-lib', plugins_url( '../assets/js/libs/pdf-lib.min.js', __FILE__ ), array(), '1.17.1', true );
    wp_enqueue_script( $this->base->plugin_slug . '-preview', plugins_url( '../assets/js/corporate-book-preview.js', __FILE__ ),
      array( 'jquery', 'wp-api-fetch', $this->base->plugin_slug . '-pdf-lib' ), $this->base->version, true );

    if(isset( $post->ID ) || isset($id)){
//       Set the post_id for localization.
      $post_id = isset( $post->ID ) ? $post->ID : (int) $id;
      wp_enqueue_media( array( 'post' => $post_id ) );
    }

  }

  /**
   * Adds metaboxes for handling settings
   *
   * @since 1.0.0
   */
  public function add_meta_boxes() {
    add_meta_box( 'corporate_book_post_meta_box', __( 'Corporate Book Settings', '3d-corporate-book' ), array( $this, 'create_meta_boxes' ), 'corporate_book', 'normal', 'high' );

    add_meta_box( 'corporate_book_post_meta_box_shortcode', __( 'Shortcode', '3d-corporate-book' ), array( $this, 'create_meta_boxes_shortcode' ), 'corporate_book', 'side', 'high' );

  }

  /**
   * Creates metaboxes for shortcode display
   *
   * @param object $post The current post object.
   *
   * @since 1.2.4
   *
   */
  public function create_meta_boxes_shortcode( $post ) {
    global $current_screen;

    if ( $current_screen->post_type == 'corporate_book' ) {
      if ( $current_screen->action == 'add' ) {
        echo "Save Post to generate shortcode.";
      }
      else {
        ?>
          <strong>Embedded:</strong>
          <br>
          [corporate_book id="<?php echo esc_html($post->ID); ?>"][/corporate_book]
          <hr>
          <strong>Thumb - Lightbox(Popup):</strong>
          <br>
          [corporate_book id="<?php echo esc_html($post->ID); ?>" type="thumb"][/corporate_book]
          <hr>
	    <?php
      }
    }
  }



  /**
   * Creates metaboxes for handling settings
   *
   * @param object $post The current post object.
   *
   * @since 1.0.0
   *
   */
  public function create_meta_boxes( $post ) {

    // Keep security first.
    wp_nonce_field( $this->base->plugin_slug, $this->base->plugin_slug );

    $tabs = array(
        'source'  => __( 'Source', '3d-corporate-book' ),
        'layout'   => __( 'General/Layout', '3d-corporate-book' ),
        'flipbook' => __( 'Corporate Book', '3d-corporate-book' ),
        'outline' => __( 'Outline', '3d-corporate-book' )
    );

    if ( $error = get_transient( "my_save_post_errors_{$post->ID}" ) ) { ?>
      <div class="info hidden">
      <p><?php echo esc_attr( $error ); ?></p>
      </div><?php

      delete_transient( "my_save_post_errors_{$post->ID}" );
    }

    //create tabs and content
    ?>
    <div class="corporate-book-tabs">
      <ul class="corporate-book-tabs-list">
        <?php
        //create tabs
        $active_set = false;
        foreach ( (array) $tabs as $id => $title ) {
          ?>
          <li class="corporate-book-update-hash corporate-book-tab <?php echo( $active_set == false ? 'corporate-book-active' : '' ) ?>">
            <a href="#corporate-book-tab-content-<?php echo esc_attr( $id ) ?>"><?php echo esc_attr( $title ) ?></a></li>
          <?php $active_set = true;
        }
        ?>
      </ul>
      <?php

      $active_set = false;
      foreach ( (array) $tabs as $id => $title ) {
        ?>
        <div id="corporate-book-tab-content-<?php echo esc_attr( $id ) ?>"
                class="corporate-book-tab-content <?php echo( $active_set == false ? "corporate-book-active" : "" ) ?>">

          <?php
          $active_set = true;

          //create content for tab
          $function = $id . "_tab";
          call_user_func( array( $this, $function ), $post );

          ?>
        </div>
      <?php } ?>
    </div>
    <?php

  }

  /**
   * Creates the UI for Source tab
   *
   * @param object $post The current post object.
   *
   * @since 1.0.0
   *
   */
  /**
   * Hidden preview PDF field with the "Create preview" button (subscription lock).
   *
   * @param object $post The current post object.
   */
  private function preview_field( $post ) {
    $data    = get_post_meta( $post->ID, '_corporate_book_data', true );
    $preview = is_array( $data ) && ! empty( $data['preview_pdf'] ) ? (int) $data['preview_pdf'] : 0;
    $url     = $preview && current_user_can( 'edit_post', $preview ) ? wp_get_attachment_url( $preview ) : '';
    $percent = (int) $this->base->get_config( 'subscription_preview_percent' );
    ?>
    <div id="corporate_book_preview_pdf_box" class="df-box" data-condition="corporate_book_source_type:is(pdf)">
      <div class="df-label"><label for="corporate_book_create_preview"><?php esc_html_e( 'Free Preview PDF', '3d-corporate-book' ); ?></label></div>
      <div class="df-option">
        <input type="hidden" name="_corporate_book[preview_pdf]" id="corporate_book_preview_pdf" value="<?php echo esc_attr( $preview ); ?>"/>
        <button type="button" class="button" id="corporate_book_create_preview" data-percent="<?php echo esc_attr( $percent ); ?>" data-endpoint="<?php echo esc_url( Corporate_Book_Protected_PDF::url( $post->ID ) ); ?>" data-protected="<?php echo esc_url( wp_get_upload_dir()['baseurl'] . '/' . Corporate_Book_Protected_PDF::DIR . '/' ); ?>"><?php esc_html_e( 'Create preview', '3d-corporate-book' ); ?></button>
        <span id="corporate_book_preview_status"><?php if ( $url ) { echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html__( 'Current preview', '3d-corporate-book' ) . '</a>'; } ?></span>
      </div>
      <?php if ( $this->base->book_is_locked( $post->ID ) && is_array( $data ) && ! empty( $data['pdf_source'] ) && ! Corporate_Book_Protected_PDF::is_protected( $post->ID ) ) : ?>
        <p class="cb-unprotected notice notice-warning inline"><?php esc_html_e( 'This PDF is not protected: anyone with its link can download it. Use a PDF from your Media Library, then update the book.', '3d-corporate-book' ); ?></p>
      <?php endif; ?>
      <div class="df-desc"><?php echo esc_html( sprintf( /* translators: %d: preview percent */ __( 'Readers without a subscription get the first %d%% of pages. Create the preview again after changing the PDF.', '3d-corporate-book' ), $percent ) ); ?></div>
    </div>
    <?php
  }

  public function source_tab( $post ) {

    $this->create_normal_setting( 'source_type', $post );
    $this->create_normal_setting( 'pdf_source', $post );
    $this->create_normal_setting( 'pdf_thumb', $post );
    $this->create_normal_setting( 'subscription', $post );
    $this->preview_field( $post );

    ?>

    <!--Pages for the book-->
      <div id="corporate_book_pages_box" class="df-box hide-on-fail " data-condition="corporate_book_source_type:is(image)" data-operator="and">

      <label for="corporate_book_pages" class="corporate-book-label">
        <?php echo esc_html_e( 'Custom Pages', '3d-corporate-book' ); ?>
      </label>

      <div class="corporate-book-desc">
        <?php echo esc_html_e( 'Add or remove pages as per your requirement. Plus reorder them in the order needed.', '3d-corporate-book' ); ?>
      </div>
      <div class="corporate-book-option corporate-book-page-list">
        <a href="javascript:void(0);" class="corporate-book-page-list-add button button-primary"
                title="Add New Page">
          <?php echo esc_html_e( 'Add New Page', '3d-corporate-book' ); ?>
        </a>
        <ul id="corporate_book_page_list">
          <?php
          $page_list = $this->get_config( 'pages', $post );
          $index = 0;
          foreach ( (array) $page_list as $page ) {

            /* build the arguments*/
            $title = isset( $page['title'] ) ? $page['title'] : '';
            $url = isset( $page['url'] ) ? $page['url'] : '';
            $content = isset( $page['content'] ) ? $page['content'] : '';

            if ( $url != '' ) {
              ?>
              <li class="corporate-book-page-item">
                <img class="corporate-book-page-thumb" src="<?php echo esc_attr( $url ); ?>" alt=""/>

                <div class="corporate-book-page-options">

                  <label for="corporate-book-page-<?php echo esc_attr( $index ); ?>-title">
                    <?php echo esc_html_e( 'Title', '3d-corporate-book' ); ?>
                  </label>
                  <input type="text"
                          name="_corporate_book[pages][<?php echo esc_attr( $index ); ?>][url]"
                          id="corporate-book-page-<?php echo esc_attr( $index ); ?>-url"
                          value="<?php echo esc_attr( $url ); ?>"
                          class="widefat">

                  <label for="corporate-book-page-<?php echo esc_attr( $index ); ?>-content">
                    <?php echo esc_html_e( 'Content', '3d-corporate-book' ); ?>
                  </label>
                  <textarea rows="10" cols="40"
                          name="_corporate_book[pages][<?php echo esc_attr( $index ); ?>][content]"
                          id="corporate-book-page-<?php echo esc_attr( $index ); ?>-content">
										<?php echo esc_textarea( $content ); ?>
									</textarea>
                  <?php
                  if ( isset( $page['hotspots'] ) ) {
                    $spotindex = 0;
                    foreach (
                        (array) $page['hotspots'] as $spot
                    ) {
                      ?>
                      <input class="corporate-book-hotspot-input"
                              name="_corporate_book[pages][<?php echo esc_attr( $index ); ?>][hotspots][<?php echo esc_attr( $spotindex ); ?>]"
                              value="<?php echo esc_html( $spot ); ?>">
                      <?php
                      $spotindex ++;
                    }
                  }
                  ?>
                </div>
              </li>
              <?php
            }
            $index ++;
          } ?>
        </ul>
      </div>
    </div>

    <!--Clear-fix-->
    <div class="corporate-book-box"></div>

    <?php

  }

  /**
   * Sanitizes an array value even if not existent
   *
   * @param object $arr     The array to lookup
   * @param mixed  $key     The key to look into array
   * @param mixed  $default Default value in-case value is not found in array
   *
   * @return mixed appropriate value if exists else default value
   * @since 1.0.0
   *
   */
  private function val( $arr, $key, $default = '' ) {
    return isset( $arr[ $key ] ) ? $arr[ $key ] : $default;
  }

  private function create_global_setting( $key, $post, $global_key ) {
    $this->base->create_setting( $key, null, $this->get_config( $key, $post, $global_key ), $global_key, $this->global_config( $key ) );

  }

  private function create_normal_setting( $key, $post ) {
    $this->base->create_setting( $key, null, $this->get_config( $key, $post ) );

  }

  /**
   * Creates the UI for layout tab
   *
   * @param object $post The current post object.
   *
   * @since 1.0.0
   *
   */
  public function layout_tab( $post ) {
    $this->create_global_setting( 'viewerType', $post, 'global' );
    $this->create_global_setting( 'texture_size', $post, 'global' );
    $this->base->create_separator( 'Layout' );
    $this->create_global_setting( 'height', $post, '' );
    $this->create_global_setting( 'bg_color', $post, '' );
    $this->create_global_setting( 'bg_image', $post, '' );
    $this->base->create_separator( 'Controls' );
    $this->create_global_setting( 'auto_sound', $post, 'global' );
    $this->create_global_setting( 'enable_download', $post, 'global' );
    $this->create_global_setting( 'controls_position', $post, 'global' );
    ?>

    <!--Clear-fix-->
    <div class="corporate-book-box"></div>
    <?php

  }

  public function flipbook_tab( $post ) {
    $this->create_global_setting( 'webgl', $post, 'global' );
    $this->create_global_setting( 'hard', $post, 'global' );
    $this->create_global_setting( 'duration', $post, '' );
    $this->create_global_setting( 'page_mode', $post, 'global' );
    $this->create_global_setting( 'single_page_mode', $post, 'global' );
    $this->create_normal_setting( 'page_size', $post );
    $this->create_global_setting( 'direction', $post, 'global' );

    $this->base->create_separator( 'Misc' );
    $this->create_global_setting( 'autoplay', $post, 'global' );
    $this->create_global_setting( 'autoplay_duration', $post, '' );
    $this->create_global_setting( 'autoplay_start', $post, 'global' );
    ?><!--Clear-fix-->
      <div class="corporate-book-box"></div>
    <?php
  }


  /**
   * Creates the UI for outline tab
   *
   * @param object $post The current post object.
   *
   * @since 1.0.0
   *
   */
  public function outline_tab( $post ) {

    $this->create_normal_setting( 'auto_outline', $post );
    $this->create_normal_setting( 'auto_thumbnail', $post );
    $this->create_normal_setting( 'overwrite_outline', $post );
    ?>

    <!--Outline/Bookmark-->
    <div id="corporate_book_outline_box" class="corporate-book-box corporate-book-js-code">

      <div class="corporate-book-desc">
        <p>
          <?php
          /* translators: Format of Outline - Outline Name : (destination as blank or link to url or page number) */
          echo  esc_html_e( 'Create a tree structure bookmark/outline of your book for easy access:', '3d-corporate-book' ) .
              '<br><code>Outline Name : (destination as blank or link to url or page number)</code>' ; ?>
        </p>
      </div>

      <div class="corporate-book-option corporate-book-textarea-simple">
          <script class="df-post-script" type="application/javascript">window.corporate_book_flipbook_post_outline = <?php echo json_encode( $this->get_config( 'outline', $post ) ); ?></script>
      </div>
    </div>

    <!--Clear-fix-->
    <div class="corporate-book-box"></div>
    <?php
  }

  /**
   * Helper method for retrieving config values.
   *
   * @param string $key  The config key to retrieve.
   * @param object $post The current post object.
   *
   * @param null   $_default
   *
   * @return string Key value on success, empty string on failure.
   * @since 1.0.0
   *
   */
  public function get_config( $key, $post, $_default = null ) {

    $values = get_post_meta( $post->ID, '_corporate_book_data', true );
    $value = isset( $values[ $key ] ) ? $values[ $key ] : '';

    $default = $_default === null ? isset( $this->fields[ $key ] ) ? is_array( $this->fields[ $key ] ) ? isset( $this->fields[ $key ]['std'] ) ? $this->fields[ $key ]['std'] : ''
        : $this->fields[ $key ] : '' : $_default;

    /* set standard value */
    if ( $default !== null ) {
      $value = $this->filter_std_value( $value, $default );
    }

    return $value;

  }

  /**
   * Helper function to filter standard option values.
   *
   * @param mixed $value Saved string or array value
   * @param mixed $std   Standard string or array value
   *
   * @return    mixed     String or array
   *
   * @access    public
   * @since     1.0.0
   */
  public function filter_std_value( $value = '', $std = '' ) {

    $std = maybe_unserialize( $std );

    if ( is_array( $value ) && is_array( $std ) ) {

      foreach ( $value as $k => $v ) {

        if ( '' === $value[ $k ] && isset( $std[ $k ] ) ) {

          $value[ $k ] = $std[ $k ];

        }

      }

    } else {
      if ( '' === $value && $std !== null ) {

        $value = $std;

      }
    }

    return $value;

  }

  /**
   * Saves values from Corporate Book metaboxes.
   *
   * @param int    $post_id The current post ID.
   * @param object $post    The current post object.
   *
   * @since 1.0.0
   *
   */
  public function save_meta_boxes( $post_id, $post ) {

    // Bail out if we fail a security check.
    if ( !isset( $_POST['corporate_book'] )
         || !wp_verify_nonce(  isset($_POST['corporate_book']) ? sanitize_key(wp_unslash($_POST['corporate_book'])) : null, 'corporate_book' )
         || !isset( $_POST['_corporate_book'] ) ) {
      set_transient( "my_save_post_errors_{$post_id}", "Security Check Failed", 10 );

      return;
    }

    // Bail out if running an autosave, ajax, cron or revision.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
      set_transient( "my_save_post_errors_{$post_id}", "Autosave", 10 );

      return;
    }
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
      set_transient( "my_save_post_errors_{$post_id}", "Ajax", 10 );

      return;
    }
    /*    if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
          set_transient("my_save_post_errors_{$post_id}", "Cron", 10);
          return;
        }*/
    if ( wp_is_post_revision( $post_id ) ) {
      set_transient( "my_save_post_errors_{$post_id}", "revision", 10 );

      return;
    }

    // Bail if this is not the correct post type.
    if ( isset( $post->post_type )
         && $this->base->plugin_slug !== $post->post_type ) {
      set_transient( "my_save_post_errors_{$post_id}", "Incorrect Post Type", 10 );

      return;
    }

    // Bail out if user is not authorized
    if ( !current_user_can( 'edit_post', $post_id ) ) {
      set_transient( "my_save_post_errors_{$post_id}", "UnAuthorized User", 10 );

      return;
    }

    // Sanitize all user inputs.

    $sanitized_data = array();

    //Source Tab
    $sanitized_data['source_type'] = isset($_POST['_corporate_book']['source_type']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['source_type']) ) : null;
    $sanitized_data['pdf_source'] = isset($_POST['_corporate_book']['pdf_source']) ? esc_url_raw( wp_unslash($_POST['_corporate_book']['pdf_source']) ) : null;
    $sanitized_data['pdf_thumb'] = isset($_POST['_corporate_book']['pdf_thumb']) ? esc_url_raw( wp_unslash($_POST['_corporate_book']['pdf_thumb']) ) : null;
    $subscription = isset( $_POST['_corporate_book']['subscription'] ) ? sanitize_key( wp_unslash( $_POST['_corporate_book']['subscription'] ) ) : 'default';
    $sanitized_data['subscription'] = in_array( $subscription, array( 'default', 'required', 'free' ), true ) ? $subscription : 'default';
    $sanitized_data['preview_pdf'] = isset( $_POST['_corporate_book']['preview_pdf'] ) ? absint( wp_unslash( $_POST['_corporate_book']['preview_pdf'] ) ) : 0;
    // A book may only use files its editor may use: not another book's protected PDF, not someone else's preview.
    $old = get_post_meta( $post_id, '_corporate_book_data', true );
    $old = is_array( $old ) ? $old : array();
    if ( Corporate_Book_Protected_PDF::in_protected_dir( $sanitized_data['pdf_source'] ) && ( ! isset( $old['pdf_source'] ) || $old['pdf_source'] !== $sanitized_data['pdf_source'] ) ) {
      $sanitized_data['pdf_source'] = isset( $old['pdf_source'] ) ? $old['pdf_source'] : '';
    }
    $preview = $sanitized_data['preview_pdf'];
    if ( $preview && ( 'application/pdf' !== get_post_mime_type( $preview ) || ! current_user_can( 'edit_post', $preview ) ) ) {
      $sanitized_data['preview_pdf'] = isset( $old['preview_pdf'] ) ? (int) $old['preview_pdf'] : 0;
    }

    $page_list = array();
    $pages = isset($_POST['_corporate_book']['pages']) ? wp_unslash($_POST['_corporate_book']['pages']) : null;
    if ( is_array( $pages ) ) {
      foreach ( (array) $pages as $page_key => $page_value ) {
        $page = array();
        $page['url'] = isset( $page_value['url'] ) ? esc_url_raw( $page_value['url'] ) : '';
        $page['hotspots'] = array();
        if ( isset( $page_value['hotspots'] ) ) {
          foreach ( (array) $page_value['hotspots'] as $spot_key => $spot_value ) {
            array_push( $page['hotspots'], sanitize_text_field( $spot_value ) );
          }
        }
        array_push( $page_list, $page );
      }
    }
    $sanitized_data['pages'] = $page_list;

    //Layout tab
    $sanitized_data['viewerType'] = isset($_POST['_corporate_book']['viewerType']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['viewerType']) ) : null;
    $sanitized_data['webgl'] = isset($_POST['_corporate_book']['webgl']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['webgl']) ) : null;
    $sanitized_data['hard'] = isset($_POST['_corporate_book']['hard']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['hard']) ) : null;
    $sanitized_data['bg_color'] = isset($_POST['_corporate_book']['bg_color']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['bg_color']) ) : null;
    $sanitized_data['bg_image'] = isset($_POST['_corporate_book']['bg_image']) ? esc_url_raw( wp_unslash($_POST['_corporate_book']['bg_image']) ) : null;
    $sanitized_data['duration'] = isset($_POST['_corporate_book']['duration']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['duration']) ) : null;
    $sanitized_data['height'] = isset($_POST['_corporate_book']['height']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['height']) ) : null;
    $sanitized_data['texture_size'] = isset($_POST['_corporate_book']['texture_size']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['texture_size']) ) : null;
    $sanitized_data['auto_sound'] = isset($_POST['_corporate_book']['auto_sound']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['auto_sound']) ) : null;
    $sanitized_data['enable_download'] = isset($_POST['_corporate_book']['enable_download']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['enable_download']) ) : null;
    $sanitized_data['page_mode'] = isset($_POST['_corporate_book']['page_mode']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['page_mode']) ) : null;
    $sanitized_data['single_page_mode'] = isset($_POST['_corporate_book']['single_page_mode']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['single_page_mode']) ) : null;
    $sanitized_data['controls_position'] = isset($_POST['_corporate_book']['controls_position']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['controls_position']) ) : null;
    $sanitized_data['direction'] = isset($_POST['_corporate_book']['direction']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['direction']) ) : null;
//    $sanitized_data['force_fit'] = sanitize_text_field( $_POST['_corporate_book']['force_fit'] );
    $sanitized_data['autoplay'] = isset($_POST['_corporate_book']['autoplay']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['autoplay']) ) : null;
    $sanitized_data['autoplay_duration'] = isset($_POST['_corporate_book']['autoplay_duration']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['autoplay_duration']) ) : null;
    $sanitized_data['autoplay_start'] = isset($_POST['_corporate_book']['autoplay_start']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['autoplay_start']) ) : null;
    $sanitized_data['page_size'] = isset($_POST['_corporate_book']['page_size']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['page_size']) ) : null;

    //Outline/sidemenu tab
    $sanitized_data['auto_outline'] = isset($_POST['_corporate_book']['auto_outline']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['auto_outline']) ) : null;
    $sanitized_data['auto_thumbnail'] = isset($_POST['_corporate_book']['auto_thumbnail']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['auto_thumbnail']) ) : null;
    $sanitized_data['overwrite_outline'] = isset($_POST['_corporate_book']['overwrite_outline']) ? sanitize_text_field( wp_unslash($_POST['_corporate_book']['overwrite_outline']) ) : null;

    $sanitized_data['outline'] = isset( $_POST['_corporate_book']['outline'] ) ? $this->array_outline_sanitize( wp_unslash($_POST['_corporate_book']['outline'] )) : array();
    $sanitized_data['outline'] = $this->array_val($sanitized_data['outline'],'items');

    $settings = get_post_meta( $post_id, '_corporate_book_data', true );
    if ( empty( $settings ) || !is_array($settings)) {
      $settings = array();
    }
    $settings = array_merge( $settings, $sanitized_data );

    //These values are from postObject
    if ( isset( $post->post_type ) && 'corporate_book' == $post->post_type ) {
      if ( empty( $settings['title'] ) ) {
        $settings['title'] = trim( wp_strip_all_tags( $post->post_title ) );
      }

      if ( empty( $settings['slug'] ) ) {
        $settings['slug'] = sanitize_text_field( $post->post_name );
      }
    }

    // Get publish/draft status from Post
    $settings['status'] = $post->post_status;

    // Update the post meta.
    update_post_meta( $post_id, '_corporate_book_data', $settings );

  }

  /**
   * Sanitizes and returns values of an array. The values should be text only
   *
   * @param array Array to be sanitized
   *
   * @return array sanitized array
   * @since 1.0.0
   *
   */
  private function array_text_sanitize( $arr = array() ) {

    if ( is_null( $arr ) ) {
      return array();
    }

    foreach ( (array) $arr as $k => $val ) {
      if ( is_array( $val ) ) {
        $arr[ $k ] = $this->array_text_sanitize( $val );
      } else {
        $arr[ $k ] = sanitize_text_field( $val );
      }
    }

    return $arr;

  }
  
  /**
   * Sanitizes and returns values of an outline array. The values should be text, number and urls only
   *
   * @param array $arr Array to be sanitized
   *
   * @return array sanitized array
   * @since 2.3.53
   *
   */
  private function array_outline_sanitize( $arr = array() ) {
    
    if ( is_null( $arr ) ) {
      return array();
    }
    foreach ( (array) $arr as $k => $val ) {
      if ( is_array( $val ) ) {
        $arr[ $k ] = $this->array_outline_sanitize( $val );
      } else if ( $k == "title" ) {
        $arr[ $k ] = sanitize_text_field( $val );
      } else if ( $k == "dest" ) {
        if ( is_numeric( $arr[ $k ] ) ) {
          $arr[ $k ] = sanitize_text_field( $val );
        } else {
          $arr[ $k ] = sanitize_url( $val );
        }
      }else{
        return "";
      }
    }
    
    return $arr;
    
  }
  
  /**
   * Removes index of array and returns only values array
   *
   * @param array Array to be de-ordered
   * @param string $scan key index that needs to be de-ordered
   *
   * @return array sanitized array
   * @since 1.0.0
   *
   */
  private function array_val( $arr = array(), $scan = '' ) {

    if ( is_null( $arr ) ) {
      return array();
    }

    $_arr = array_values( $arr );
    if ( $_arr != null && $scan !== '' ) {
      foreach ( $_arr as &$val ) {
        if ( is_array( $val ) ) {
          if ( isset( $val[ $scan ] ) ) {
            $val[ $scan ] = $this->array_val( $val[ $scan ], $scan );
          }
        }
      }
    }

    return $_arr;

  }

  /**
   * Helper method for retrieving global check values.
   *
   * @param string $key  The config key to retrieve.
   * @param object $post The current post object.
   *
   * @return string Key value on success, empty string on failure.
   * @since 1.0.0
   *
   */
  public function global_config( $key ) {

    $global_value = $this->base->get_config( $key );
    $value = isset( $this->fields[ $key ] ) ? is_array( $this->fields[ $key ] ) ? isset( $this->fields[ $key ]['choices'][ $global_value ] ) ? $this->fields[ $key ]['choices'][ $global_value ]
        : $global_value : $global_value : $global_value;

    return $value;

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
         && !( self::$instance instanceof Corporate_Book_Meta_Boxes ) ) {
      self::$instance = new Corporate_Book_Meta_Boxes();
    }

    return self::$instance;

  }
}

// Load the Corporate_Book_Metaboxes class.
$corporate_book_meta_boxes = Corporate_Book_Meta_Boxes::get_instance();

