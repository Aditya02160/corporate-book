<?php
/**
 * Print copies shop: readers buy printed books with a cart, a delivery address in India and Razorpay
 * (or cash on delivery). Shipping is the courier rate from Noida for the PIN code's zone and the parcel's 500 g slabs.
 * Orders are private "cb_order" posts that administrators manage under Corporate Books > Print Orders.
 *
 * Prices, shipping, stock and totals are always worked out here; the browser only sends book IDs and quantities.
 *
 * @package corporate book
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

class Corporate_Book_Shop {

  const ORDER    = 'cb_order';
  const NONCE    = 'corporate_book_shop';
  const ADDRESS  = 'corporate_book_address';
  const MAX_QTY  = 10;

  /**
   * Reference points of Indian PIN code regions: two-digit prefixes, plus three-digit prefixes where a
   * region is large or the place matters (Delhi NCR, Uttarakhand, Goa, Sikkim, islands, north-east).
   * Used to tell real PIN code regions from unused ones.
   */
  private static $points = array(
    '11' => array( 28.6139, 77.2090 ), '12' => array( 28.8955, 76.6066 ), '13' => array( 29.6857, 76.9905 ),
    '14' => array( 30.9010, 75.8573 ), '15' => array( 30.2110, 74.9455 ), '16' => array( 30.7333, 76.7794 ),
    '17' => array( 31.1048, 77.1734 ), '18' => array( 32.7266, 74.8570 ), '19' => array( 34.0837, 74.7973 ),
    '20' => array( 27.8974, 78.0880 ), '21' => array( 25.4358, 81.8463 ), '22' => array( 26.8467, 80.9462 ),
    '23' => array( 25.1337, 82.5644 ), '24' => array( 28.3670, 79.4304 ), '25' => array( 28.9845, 77.7064 ),
    '26' => array( 27.9462, 80.7787 ), '27' => array( 26.7606, 83.3732 ), '28' => array( 27.1767, 78.0081 ),
    '30' => array( 26.9124, 75.7873 ), '31' => array( 25.3407, 74.6313 ), '32' => array( 25.2138, 75.8648 ),
    '33' => array( 28.0229, 73.3119 ), '34' => array( 26.2389, 73.0243 ), '36' => array( 22.3039, 70.8022 ),
    '37' => array( 23.2420, 69.6669 ), '38' => array( 23.0225, 72.5714 ), '39' => array( 21.7051, 72.9959 ),
    '40' => array( 19.0760, 72.8777 ), '41' => array( 18.5204, 73.8567 ), '42' => array( 19.9975, 73.7898 ),
    '43' => array( 19.8762, 75.3433 ), '44' => array( 21.1458, 79.0882 ), '45' => array( 22.7196, 75.8577 ),
    '46' => array( 23.2599, 77.4126 ), '47' => array( 26.2183, 78.1828 ), '48' => array( 23.1815, 79.9864 ),
    '49' => array( 21.2514, 81.6296 ), '50' => array( 17.3850, 78.4867 ), '51' => array( 15.8281, 78.0373 ),
    '52' => array( 16.5062, 80.6480 ), '53' => array( 17.6868, 83.2185 ), '56' => array( 12.9716, 77.5946 ),
    '57' => array( 13.0072, 76.0962 ), '58' => array( 15.3647, 75.1240 ), '59' => array( 15.8497, 74.4977 ),
    '60' => array( 13.0827, 80.2707 ), '61' => array( 10.7870, 79.1378 ), '62' => array( 9.9252, 78.1198 ),
    '63' => array( 11.6643, 78.1460 ), '64' => array( 11.0168, 76.9558 ), '67' => array( 11.2588, 75.7804 ),
    '68' => array( 9.9312, 76.2673 ), '69' => array( 8.5241, 76.9366 ), '70' => array( 22.5726, 88.3639 ),
    '71' => array( 23.2324, 87.8615 ), '72' => array( 22.3460, 87.2320 ), '73' => array( 26.7271, 88.3953 ),
    '74' => array( 23.4058, 88.4906 ), '75' => array( 20.2961, 85.8245 ), '76' => array( 19.3150, 84.7941 ),
    '77' => array( 21.4669, 83.9812 ), '78' => array( 26.1445, 91.7362 ), '79' => array( 25.5788, 91.8933 ),
    '80' => array( 25.5941, 85.1376 ), '81' => array( 25.2425, 86.9842 ), '82' => array( 23.9966, 85.3691 ),
    '83' => array( 23.3441, 85.3096 ), '84' => array( 26.1209, 85.3647 ), '85' => array( 25.7771, 87.4753 ),
    // Three-digit prefixes.
    '110' => array( 28.6139, 77.2090 ), '121' => array( 28.4089, 77.3178 ), '122' => array( 28.4595, 77.0266 ),
    '201' => array( 28.5700, 77.3500 ), '194' => array( 34.1526, 77.5771 ), '208' => array( 26.4499, 80.3319 ),
    '248' => array( 30.3165, 78.0322 ), '263' => array( 29.2183, 79.5130 ), '403' => array( 15.4909, 73.8278 ),
    '737' => array( 27.3389, 88.6065 ), '744' => array( 11.6234, 92.7265 ), '790' => array( 27.0844, 93.6053 ),
    '791' => array( 27.0844, 93.6053 ), '792' => array( 27.0844, 93.6053 ), '795' => array( 24.8170, 93.9368 ),
    '796' => array( 23.7271, 92.7176 ), '797' => array( 25.6751, 94.1086 ), '798' => array( 25.6751, 94.1086 ),
    '799' => array( 23.8315, 91.2868 ), '605' => array( 11.9416, 79.8083 ),
  );

  public static $states = array(
    'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh',
    'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha',
    'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
    'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir',
    'Ladakh', 'Lakshadweep', 'Puducherry',
  );

  public function __construct() {
    add_action( 'init', array( $this, 'register' ) );
    add_action( 'admin_init', array( $this, 'create_cart_page' ) );
    add_shortcode( 'corporate_book_cart', array( $this, 'cart_page' ) );
    add_action( 'wp_ajax_nopriv_corporate_book_cart_quote', array( $this, 'ajax_quote' ) );
    add_action( 'wp_ajax_corporate_book_cart_quote', array( $this, 'ajax_quote' ) );
    add_action( 'wp_ajax_corporate_book_checkout', array( $this, 'ajax_checkout' ) );
    add_action( 'wp_ajax_corporate_book_print_verify', array( $this, 'ajax_verify' ) );
    add_action( 'wp_ajax_corporate_book_save_address', array( $this, 'ajax_save_address' ) );
    add_action( 'wp_ajax_corporate_book_cancel_order', array( $this, 'ajax_cancel' ) );
    add_action( 'wp_enqueue_scripts', array( $this, 'register_script' ), 20 );
    add_action( 'transition_post_status', array( $this, 'status_changed' ), 10, 3 );
    add_filter( 'wp_insert_post_data', array( $this, 'save_order_form' ), 10, 2 );
    add_action( 'add_meta_boxes_' . self::ORDER, array( $this, 'order_boxes' ) );
    add_filter( 'manage_' . self::ORDER . '_posts_columns', array( $this, 'columns' ) );
    add_action( 'manage_' . self::ORDER . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
    add_action( 'corporate_book_reminders', array( $this, 'clean_unpaid' ) );
    // Quick Edit and bulk Edit only know WordPress's own statuses and would hide orders from every list.
    add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
    add_filter( 'bulk_actions-edit-' . self::ORDER, array( $this, 'bulk_actions' ) );
  }

  public function row_actions( $actions, $post ) {
    if ( self::ORDER === $post->post_type ) {
      unset( $actions['inline hide-if-no-js'] );
    }
    return $actions;
  }

  public function bulk_actions( $actions ) {
    unset( $actions['edit'] );
    return $actions;
  }

  // ---- Settings and pure helpers -------------------------------------------------------------

  private static function config( $key ) {
    return (string) Corporate_Book::get_instance()->get_config( $key );
  }

  /**
   * Order statuses: key => label.
   *
   * @return string[]
   */
  public static function statuses() {
    return array(
      'cb-pending'   => __( 'Pending payment', '3d-corporate-book' ),
      'cb-paid'      => __( 'Paid', '3d-corporate-book' ),
      'cb-cod'       => __( 'Cash on delivery', '3d-corporate-book' ),
      'cb-shipped'   => __( 'Shipped', '3d-corporate-book' ),
      'cb-delivered' => __( 'Delivered', '3d-corporate-book' ),
      'cb-cancelled' => __( 'Cancelled', '3d-corporate-book' ),
      'cb-refunded'  => __( 'Refunded', '3d-corporate-book' ),
    );
  }

  /**
   * PIN code without spaces, or ''.
   *
   * @param string $pin PIN code as typed.
   *
   * @return string
   */
  public static function clean_pin( $pin ) {
    return preg_replace( '/\s+/', '', (string) $pin );
  }

  public static function valid_pin( $pin ) {
    $pin = self::clean_pin( $pin );
    return (bool) preg_match( '/^[1-9][0-9]{5}$/', $pin ) && ( null !== self::pin_point( $pin ) || '9' === $pin[0] );
  }

  /**
   * Reference point of a PIN code's region, or null.
   *
   * @param string $pin PIN code.
   *
   * @return float[]|null Latitude and longitude.
   */
  public static function pin_point( $pin ) {
    $pin = self::clean_pin( $pin );
    if ( isset( self::$points[ substr( $pin, 0, 3 ) ] ) ) {
      return self::$points[ substr( $pin, 0, 3 ) ];
    }
    return isset( self::$points[ substr( $pin, 0, 2 ) ] ) ? self::$points[ substr( $pin, 0, 2 ) ] : null;
  }

  /** Indian couriers charge per 500 g slab. */
  const SLAB = 500;

  /**
   * Courier zone from Noida for a PIN code: ncr, metro, india or remote; null when the PIN is not valid.
   *
   * @param string $pin PIN code.
   *
   * @return string|null
   */
  public static function zone( $pin ) {
    $pin = self::clean_pin( $pin );
    if ( ! self::valid_pin( $pin ) ) {
      return null;
    }
    $p3 = substr( $pin, 0, 3 );
    // Delhi, Noida and Ghaziabad, Faridabad, Gurugram.
    if ( in_array( $p3, array( '110', '201', '121', '122' ), true ) ) {
      return 'ncr';
    }
    // Mumbai, Bengaluru, Chennai, Kolkata, Hyderabad, Pune, Ahmedabad.
    if ( in_array( $p3, array( '400', '560', '600', '700', '500', '411', '380' ), true ) ) {
      return 'metro';
    }
    // North-East, Jammu & Kashmir and Ladakh, Andaman & Nicobar, Lakshadweep, army post.
    if ( in_array( substr( $pin, 0, 2 ), array( '78', '79', '18', '19' ), true ) || '744' === $p3 || '6825' === substr( $pin, 0, 4 ) || '9' === $pin[0] ) {
      return 'remote';
    }
    return 'india';
  }

  /**
   * Courier rates from Global Settings > Shipping & Selling: zone => array( first 500 g, each extra 500 g, delivery days ).
   *
   * @return array[]
   */
  public static function zones() {
    $rates = array( 'ncr' => array( 40, 30, '1-2' ), 'metro' => array( 65, 50, '2-4' ), 'india' => array( 75, 60, '3-6' ), 'remote' => array( 95, 80, '5-9' ) );
    foreach ( preg_split( '/\r\n|\r|\n/', (string) self::config( 'print_zones' ) ) as $line ) {
      $parts = array_map( 'trim', explode( '|', $line ) );
      if ( count( $parts ) >= 3 && isset( $rates[ $parts[0] ] ) && is_numeric( $parts[1] ) && is_numeric( $parts[2] ) && $parts[1] >= 0 && $parts[2] >= 0 ) {
        $rates[ $parts[0] ] = array( 0 + $parts[1], 0 + $parts[2], isset( $parts[3] ) ? sanitize_text_field( $parts[3] ) : '' );
      }
    }
    return $rates;
  }

  public static function zone_label( $zone ) {
    $labels = array(
      'ncr'    => __( 'Delhi NCR', '3d-corporate-book' ),
      'metro'  => __( 'Metro city', '3d-corporate-book' ),
      'india'  => __( 'Rest of India', '3d-corporate-book' ),
      'remote' => __( 'Remote area', '3d-corporate-book' ),
    );
    return isset( $labels[ $zone ] ) ? $labels[ $zone ] : '';
  }

  /**
   * Parcel weight in grams: every copy (its own weight or the default) plus the box or mailer; 0 for no books.
   *
   * @param array[] $lines Quote lines.
   *
   * @return int
   */
  public static function grams( $lines ) {
    $grams = 0;
    foreach ( $lines as $line ) {
      $grams += $line['qty'] * ( null !== $line['weight'] ? $line['weight'] : max( 0, (int) self::config( 'print_copy_weight' ) ) );
    }
    return $grams ? $grams + max( 0, (int) self::config( 'print_pack_weight' ) ) : 0;
  }

  public static function slabs( $grams ) {
    return max( 1, (int) ceil( $grams / self::SLAB ) );
  }

  /** What the courier charges for a parcel, in paise: the first slab, then each extra 500 g. */
  public static function courier( $zone, $grams ) {
    $rate = self::zones()[ $zone ];
    return self::paise( $rate[0] ) + ( self::slabs( $grams ) - 1 ) * self::paise( $rate[1] );
  }

  /**
   * A coupon from Global Settings > Print ("CODE | 10%" or "CODE | 100", optional "| YYYY-MM-DD" last day).
   *
   * @param string $code Code as typed.
   *
   * @return array|null array( code, percent or null, amount in paise ), or null when unknown or expired.
   */
  public static function coupon( $code ) {
    $code = strtoupper( trim( (string) $code ) );
    foreach ( preg_split( '/\r\n|\r|\n/', self::config( 'print_coupons' ) ) as $line ) {
      $parts = array_map( 'trim', explode( '|', $line ) );
      if ( count( $parts ) < 2 || strtoupper( $parts[0] ) !== $code || '' === $code ) {
        continue;
      }
      if ( isset( $parts[2] ) && '' !== $parts[2] && gmdate( 'Y-m-d' ) > $parts[2] ) {
        return null;
      }
      $value = rtrim( $parts[1], '%' );
      if ( ! is_numeric( $value ) || $value <= 0 ) {
        return null;
      }
      return '%' === substr( $parts[1], -1 ) ? array( $code, min( 100, (float) $value ), 0 ) : array( $code, null, self::paise( $value ) );
    }
    return null;
  }

  private static function paise( $rupees ) {
    return (int) round( (float) $rupees * 100 );
  }

  /**
   * A book's print sale details, or null when it is not for sale in print.
   *
   * @param int $book_id Book ID.
   *
   * @return array|null id, title, price and shipping in paise, stock (null = unlimited), thumb.
   */
  public static function book_info( $book_id ) {
    $book_id = (int) $book_id;
    if ( 'true' !== self::config( 'print_enable' ) || 'corporate_book' !== get_post_type( $book_id ) || 'publish' !== get_post_status( $book_id ) ) {
      return null;
    }
    $data = get_post_meta( $book_id, '_corporate_book_data', true );
    if ( ! is_array( $data ) || empty( $data['print_sell'] ) || 'true' !== $data['print_sell'] || ! isset( $data['print_price'] ) || ! is_numeric( $data['print_price'] ) || $data['print_price'] <= 0 ) {
      return null;
    }
    $stock = isset( $data['print_stock'] ) && '' !== $data['print_stock'] && is_numeric( $data['print_stock'] ) ? max( 0, (int) $data['print_stock'] ) : null;
    return array(
      'id'       => $book_id,
      'title'    => get_the_title( $book_id ),
      'price'    => self::paise( $data['print_price'] ),
      'weight'   => isset( $data['print_weight'] ) && is_numeric( $data['print_weight'] ) && $data['print_weight'] > 0 ? (int) $data['print_weight'] : null,
      'cost'     => isset( $data['print_cost'] ) && is_numeric( $data['print_cost'] ) ? max( 0, self::paise( $data['print_cost'] ) ) : null,
      'stock'    => $stock,
      'thumb'    => isset( $data['pdf_thumb'] ) ? (string) $data['pdf_thumb'] : '',
    );
  }

  /**
   * Prices a cart for a delivery PIN code. Same book lines are merged; quantities are kept between 1 and 10
   * and within stock; books that are not for sale are dropped with a message.
   *
   * @param array  $items Each array( 'id' => book ID, 'qty' => quantity ).
   * @param string $pin   Delivery PIN code; '' prices the books without shipping.
   *
   * @return array lines, subtotal, shipping (null without PIN), total, distance, errors. Money in paise.
   */
  public static function quote( $items, $pin, $coupon = '' ) {
    $wanted = array();
    foreach ( (array) $items as $item ) {
      $id = isset( $item['id'] ) ? (int) $item['id'] : 0;
      if ( $id > 0 ) {
        $wanted[ $id ] = ( isset( $wanted[ $id ] ) ? $wanted[ $id ] : 0 ) + max( 1, (int) ( isset( $item['qty'] ) ? $item['qty'] : 1 ) );
      }
    }
    $lines  = array();
    $errors = array();
    foreach ( $wanted as $id => $qty ) {
      $book = self::book_info( $id );
      if ( ! $book ) {
        $errors[] = __( 'A book in your cart is no longer for sale in print and was removed.', '3d-corporate-book' );
        continue;
      }
      $max = null === $book['stock'] ? self::MAX_QTY : min( self::MAX_QTY, $book['stock'] );
      if ( $max < 1 ) {
        /* translators: %s: book title */
        $errors[] = sprintf( __( '"%s" is sold out.', '3d-corporate-book' ), $book['title'] );
        continue;
      }
      if ( $qty > $max ) {
        /* translators: 1: book title, 2: copies */
        $errors[] = sprintf( __( 'Only %2$d copies of "%1$s" can be ordered.', '3d-corporate-book' ), $book['title'], $max );
        $qty      = $max;
      }
      $lines[] = $book + array( 'qty' => $qty, 'max' => $max );
    }
    $subtotal = 0;
    foreach ( $lines as $line ) {
      $subtotal += $line['price'] * $line['qty'];
    }
    $discount = 0;
    $code     = '';
    if ( '' !== trim( (string) $coupon ) ) {
      $found = self::coupon( $coupon );
      if ( $found ) {
        $code     = $found[0];
        $discount = min( $subtotal, null === $found[1] ? $found[2] : (int) round( $subtotal * $found[1] / 100 ) );
      } else {
        $errors[] = __( 'This coupon code is not valid or has expired.', '3d-corporate-book' );
      }
    }
    $zone     = '' === self::clean_pin( $pin ) ? null : self::zone( $pin );
    $grams    = self::grams( $lines );
    $shipping = null;
    $courier  = null;
    $cost     = null;
    $waived   = 0;
    $days     = '';
    if ( null !== $zone ) {
      $days    = self::zones()[ $zone ][2];
      $courier = $lines ? self::courier( $zone, $grams ) : 0;
      // What the reader pays: the courier rate, a flat average per 500 g, or nothing (built into the price).
      $mode     = self::config( 'print_ship_mode' );
      $shipping = 'free' === $mode ? 0 : ( 'flat' === $mode && $lines ? self::paise( self::config( 'print_flat_rate' ) ) * self::slabs( $grams ) : $courier );
      $free     = self::config( 'print_free_above' );
      if ( is_numeric( $free ) && $free > 0 && $subtotal - $discount >= self::paise( $free ) ) {
        $waived   = $shipping;
        $shipping = 0;
      }
      // The seller's cost of this order: copies, the box and the courier.
      $cost = $courier + ( $lines ? self::paise( self::config( 'print_pack_cost' ) ) : 0 );
      foreach ( $lines as $line ) {
        $cost += $line['qty'] * ( null !== $line['cost'] ? $line['cost'] : self::paise( self::config( 'print_unit_cost' ) ) );
      }
    }
    return array(
      'lines'    => $lines,
      'subtotal' => $subtotal,
      'discount' => $discount,
      'coupon'   => $code,
      'shipping' => $shipping,
      'total'    => $subtotal - $discount + (int) $shipping,
      'zone'     => $zone,
      'grams'    => $grams,
      'courier'  => $courier,
      'waived'   => $waived,
      'cost'     => $cost,
      'days'     => $days,
      'errors'   => $errors,
    );
  }

  /**
   * Cleans a delivery address and lists what is missing or wrong.
   *
   * @param array $input Submitted fields.
   *
   * @return array array( clean address, errors keyed by field ).
   */
  public static function validate_address( $input ) {
    $get   = function ( $key ) use ( $input ) {
      return isset( $input[ $key ] ) ? trim( sanitize_text_field( (string) $input[ $key ] ) ) : '';
    };
    $phone = preg_replace( '/\D/', '', $get( 'phone' ) );
    if ( 12 === strlen( $phone ) && '91' === substr( $phone, 0, 2 ) ) {
      $phone = substr( $phone, 2 );
    } elseif ( 11 === strlen( $phone ) && '0' === $phone[0] ) {
      $phone = substr( $phone, 1 );
    }
    $clean  = array(
      'name'     => $get( 'name' ),
      'phone'    => $phone,
      'email'    => sanitize_email( $get( 'email' ) ),
      'line1'    => $get( 'line1' ),
      'line2'    => $get( 'line2' ),
      'landmark' => $get( 'landmark' ),
      'city'     => $get( 'city' ),
      'state'    => $get( 'state' ),
      'pin'      => self::clean_pin( $get( 'pin' ) ),
    );
    $errors = array();
    if ( '' === $clean['name'] ) {
      $errors['name'] = __( 'Enter the full name of the person receiving the book.', '3d-corporate-book' );
    }
    if ( ! preg_match( '/^[6-9][0-9]{9}$/', $clean['phone'] ) ) {
      $errors['phone'] = __( 'Enter a 10-digit Indian mobile number.', '3d-corporate-book' );
    }
    if ( ! is_email( $clean['email'] ) ) {
      $errors['email'] = __( 'Enter a valid email address.', '3d-corporate-book' );
    }
    if ( '' === $clean['line1'] ) {
      $errors['line1'] = __( 'Enter the house or flat number and building.', '3d-corporate-book' );
    }
    if ( '' === $clean['city'] ) {
      $errors['city'] = __( 'Enter the town or city.', '3d-corporate-book' );
    }
    if ( ! in_array( $clean['state'], self::$states, true ) ) {
      $errors['state'] = __( 'Choose the state or union territory.', '3d-corporate-book' );
    }
    if ( ! self::valid_pin( $clean['pin'] ) ) {
      $errors['pin'] = __( 'Enter a valid 6-digit PIN code.', '3d-corporate-book' );
    }
    return array( $clean, $errors );
  }

  public static function money( $paise ) {
    return Corporate_Book_Account::price( $paise / 100 );
  }

  // ---- WordPress setup -----------------------------------------------------------------------

  public function register() {
    register_post_type(
      self::ORDER,
      array(
        'labels'          => array(
          'name'          => __( 'Print Orders', '3d-corporate-book' ),
          'singular_name' => __( 'Print Order', '3d-corporate-book' ),
          'edit_item'     => __( 'Print Order', '3d-corporate-book' ),
          'search_items'  => __( 'Search orders', '3d-corporate-book' ),
          'not_found'     => __( 'No print orders yet.', '3d-corporate-book' ),
          'menu_name'     => __( 'Print Orders', '3d-corporate-book' ),
        ),
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => 'edit.php?post_type=corporate_book',
        'supports'        => false,
        'capability_type' => 'post',
        // Orders hold customers' addresses and phones: editors and administrators only, never authors.
        'capabilities'    => array( 'create_posts' => 'do_not_allow', 'edit_posts' => 'edit_others_posts', 'delete_posts' => 'delete_others_posts' ),
        'map_meta_cap'    => true,
      )
    );
    foreach ( self::statuses() as $status => $label ) {
      register_post_status(
        $status,
        array(
          'label'                     => $label,
          'public'                    => false,
          'internal'                  => false,
          'protected'                 => true,
          'show_in_admin_all_list'    => 'cb-pending' !== $status,
          'show_in_admin_status_list' => true,
          /* translators: %s: number of orders */
          'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', '3d-corporate-book' ),
        )
      );
    }
  }

  public function create_cart_page() {
    if ( get_option( 'corporate_book_cart_page' ) || ! current_user_can( 'manage_options' ) ) {
      return;
    }
    $page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => __( 'Cart', '3d-corporate-book' ), 'post_name' => 'cart', 'post_content' => '[corporate_book_cart]' ) );
    update_option( 'corporate_book_cart_page', $page ? (int) $page : -1 );
  }

  /**
   * Printable invoice or packing slip of an order.
   *
   * @param int    $order Order ID.
   * @param string $type  invoice or slip.
   *
   * @return string
   */
  public static function document_url( $order, $type ) {
    return add_query_arg( array( 'cb_doc' => $type, 'cb_order' => (int) $order ), home_url( '/' ) );
  }

  /**
   * Terms of Use page: the address in Global Settings > Shipping & Selling, or a published page called
   * terms-of-use, terms-and-conditions or terms; '' when there is none.
   */
  public static function terms_url() {
    $url = (string) self::config( 'print_terms_url' );
    if ( '' !== $url ) {
      return $url;
    }
    foreach ( array( 'terms-of-use', 'terms-and-conditions', 'terms' ) as $slug ) {
      $page = get_page_by_path( $slug );
      if ( $page && 'publish' === $page->post_status ) {
        return (string) get_permalink( $page );
      }
    }
    return '';
  }

  /** "By placing the order, you agree to our Terms of Use and Privacy Policy." with links; '' without either page. */
  public static function agree_html() {
    $links = array();
    if ( '' !== self::terms_url() ) {
      $links[] = '<a href="' . esc_url( self::terms_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Terms of Use', '3d-corporate-book' ) . '</a>';
    }
    if ( function_exists( 'get_privacy_policy_url' ) && '' !== get_privacy_policy_url() ) {
      $links[] = '<a href="' . esc_url( get_privacy_policy_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy Policy', '3d-corporate-book' ) . '</a>';
    }
    if ( ! $links ) {
      return '';
    }
    /* translators: %s: "Terms of Use and Privacy Policy" links */
    return sprintf( esc_html__( 'By placing the order, you agree to our %s.', '3d-corporate-book' ), implode( ' ' . esc_html__( 'and', '3d-corporate-book' ) . ' ', $links ) );
  }

  public static function cart_url() {
    $page = (int) get_option( 'corporate_book_cart_page' );
    $url  = $page > 0 ? get_permalink( $page ) : '';
    return $url ? $url : home_url( '/' );
  }

  public function register_script() {
    $base = Corporate_Book::get_instance();
    // Page styles for My account, Cart and Subscription: loaded in the page head on every theme.
    wp_enqueue_style( 'corporate-book-pages', plugins_url( 'assets/css/corporate-book-pages.css', $base->file ), array(), $base->version );
    wp_register_script( 'corporate-book-shop', plugins_url( 'assets/js/corporate-book-shop.js', $base->file ), array(), $base->version, true );
    wp_localize_script(
      'corporate-book-shop',
      'corporateBookShop',
      array(
        'url'      => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( self::NONCE ),
        'cart'     => self::cart_url(),
        'failed'   => __( 'Something went wrong. Try again.', '3d-corporate-book' ),
        'added'    => __( 'Added to cart', '3d-corporate-book' ),
        'view'     => __( 'View cart', '3d-corporate-book' ),
        /* translators: %d: number of copies */
        'inCart'   => __( '%d in your cart', '3d-corporate-book' ),
        'empty'    => __( 'Your cart is empty.', '3d-corporate-book' ),
        'remove'   => __( 'Remove', '3d-corporate-book' ),
        'cartName' => __( 'Cart', '3d-corporate-book' ),
        'paying'   => __( 'Waiting for the payment…', '3d-corporate-book' ),
        'cancel'   => __( 'Payment cancelled. Nothing was charged; you can try again.', '3d-corporate-book' ),
        'books'         => __( 'Books', '3d-corporate-book' ),
        'shippingLabel' => __( 'Shipping', '3d-corporate-book' ),
        'enterPin'      => __( 'Enter your PIN code', '3d-corporate-book' ),
        'totalLabel'    => __( 'Total Amount', '3d-corporate-book' ),
        'priceDetails'  => __( 'Price Details', '3d-corporate-book' ),
        'priceOne'      => __( 'Price (1 copy)', '3d-corporate-book' ),
        /* translators: %d: number of copies */
        'priceMany'     => __( 'Price (%d copies)', '3d-corporate-book' ),
        'couponLabel'   => __( 'Coupon discount', '3d-corporate-book' ),
        'freeLabel'     => __( 'FREE', '3d-corporate-book' ),
        /* translators: %s: amount */
        'youSave'       => __( 'You\'ll save %s on this order', '3d-corporate-book' ),
        'trust'         => __( 'Safe and secure payments. Cancel any time before shipping for a full refund.', '3d-corporate-book' ),
        'agree'         => self::agree_html(),
        'myAccount'     => __( 'See my orders', '3d-corporate-book' ),
        'account'       => Corporate_Book_Account::profile_url(),
      )
    );
    // Small script: on every page it shows the floating "Cart (n)" link once something is in the cart.
    if ( 'true' === self::config( 'print_enable' ) ) {
      wp_enqueue_script( 'corporate-book-shop' );
      wp_register_style( 'corporate-book-cart-pill', false, array(), $base->version );
      wp_enqueue_style( 'corporate-book-cart-pill' );
      wp_add_inline_style( 'corporate-book-cart-pill', '.cb-cart-pill{position:fixed;right:18px;bottom:18px;z-index:9999;background:#0b1f5c;color:#fff;border-radius:999px;padding:12px 20px;font-weight:700;text-decoration:none;box-shadow:0 8px 24px rgba(0,0,0,.3)}.cb-cart-pill[hidden]{display:none}.cb-toast{position:fixed;left:50%;bottom:24px;transform:translate(-50%,40px);opacity:0;z-index:10000;background:#1d2340;color:#fff;padding:12px 18px;border-radius:10px;transition:.3s;pointer-events:none}.cb-toast.cb-show{transform:translate(-50%,0);opacity:1;pointer-events:auto}.cb-toast a{color:#ffd76a}' );
    }
  }

  private static function enqueue() {
    wp_enqueue_style( 'corporate-book-style' );
    wp_enqueue_script( 'corporate-book-shop' );
  }

  /**
   * "Buy print copy" button for a book, or '' when it is not for sale in print.
   *
   * @param int $book_id Book ID.
   *
   * @return string HTML.
   */
  public static function buy_button( $book_id, $label = '' ) {
    $book = self::book_info( $book_id );
    if ( ! $book || ( null !== $book['stock'] && $book['stock'] < 1 ) ) {
      return '';
    }
    self::enqueue();
    /* translators: %s: price */
    $full  = sprintf( __( 'Buy print copy · %s', '3d-corporate-book' ), self::money( $book['price'] ) );
    // A short label (cover cards) keeps the full text for screen readers.
    return '<button type="button" class="cb-add-cart" data-book="' . (int) $book['id'] . '"' . ( '' !== $label ? ' aria-label="' . esc_attr( $full ) . '"' : '' ) . '>' . esc_html( '' !== $label ? $label : $full ) . '</button>';
  }

  /**
   * Print section of the Subscription page: every book for sale in print, with Add to cart.
   *
   * @return string HTML.
   */
  public static function print_section() {
    if ( 'true' !== self::config( 'print_enable' ) ) {
      return '';
    }
    $cards = '';
    foreach ( get_posts( array( 'post_type' => 'corporate_book', 'post_status' => 'publish', 'numberposts' => 200, 'fields' => 'ids' ) ) as $id ) {
      $book = self::book_info( $id );
      if ( ! $book ) {
        continue;
      }
      $sold   = null !== $book['stock'] && $book['stock'] < 1;
      $cover  = $book['thumb'] ? '<img src="' . esc_url( $book['thumb'] ) . '" alt="" loading="lazy"/>' : '<span class="cb-print-nocover"></span>';
      $action = $sold ? '<span class="cb-sold-out">' . esc_html__( 'Sold out', '3d-corporate-book' ) . '</span>' . Corporate_Book_Reader::notify_button( $id ) : '<button type="button" class="cb-add-cart" data-book="' . (int) $id . '">' . esc_html__( 'Add to cart', '3d-corporate-book' ) . '</button>';
      $action .= Corporate_Book_Reader::save_button( $id );
      $days    = (int) self::config( 'print_bundle_days' );
      /* translators: %d: days */
      $action .= $days > 0 ? '<div class="cb-bundle-note">' . esc_html( sprintf( _n( 'Includes %d day of online reading', 'Includes %d days of online reading', $days, '3d-corporate-book' ), $days ) ) . '</div>' : '';
      $cards .= '<div class="cb-print-card">' . $cover . '<h3>' . esc_html( $book['title'] ) . '</h3><div class="cb-plan-price">' . esc_html( self::money( $book['price'] ) ) . '</div><div class="cb-plan-days">' . esc_html__( 'Printed copy, delivered in India', '3d-corporate-book' ) . '</div>' . $action . '</div>';
    }
    if ( '' === $cards ) {
      return '';
    }
    self::enqueue();
    return '<section class="cb-print-section"><h2>' . esc_html__( 'Print copies', '3d-corporate-book' ) . '</h2><p>' . esc_html__( 'Prefer paper? Order printed books, one or many, delivered to your door. Shipping depends on the distance from Noida.', '3d-corporate-book' ) . ' <a href="' . esc_url( self::cart_url() ) . '">' . esc_html__( 'View cart', '3d-corporate-book' ) . '</a></p><div class="cb-print-grid">' . $cards . '</div></section>';
  }

  private static function address_fields( $address, $prefix = 'cb' ) {
    $address = wp_parse_args( (array) $address, array( 'name' => '', 'phone' => '', 'email' => '', 'line1' => '', 'line2' => '', 'landmark' => '', 'city' => '', 'state' => '', 'pin' => '' ) );
    $field   = function ( $name, $label, $type = 'text', $extra = '' ) use ( $address ) {
      return '<label class="cb-field cb-field-' . esc_attr( $name ) . '"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $address[ $name ] ) . '" ' . $extra . '/><em class="cb-field-error" data-for="' . esc_attr( $name ) . '"></em></label>';
    };
    $options = '<option value="">' . esc_html__( 'Choose…', '3d-corporate-book' ) . '</option>';
    foreach ( self::$states as $state ) {
      $options .= '<option ' . selected( $address['state'], $state, false ) . '>' . esc_html( $state ) . '</option>';
    }
    return $field( 'name', __( 'Full name', '3d-corporate-book' ), 'text', 'required autocomplete="name"' )
      . $field( 'phone', __( 'Mobile number', '3d-corporate-book' ), 'tel', 'required autocomplete="tel" placeholder="98765 43210"' )
      . $field( 'email', __( 'Email', '3d-corporate-book' ), 'email', 'required autocomplete="email"' )
      . $field( 'line1', __( 'House / flat, building', '3d-corporate-book' ), 'text', 'required autocomplete="address-line1"' )
      . $field( 'line2', __( 'Street, area, sector', '3d-corporate-book' ), 'text', 'autocomplete="address-line2"' )
      . $field( 'landmark', __( 'Landmark (optional)', '3d-corporate-book' ) )
      . $field( 'city', __( 'Town / city', '3d-corporate-book' ), 'text', 'required autocomplete="address-level2"' )
      . '<label class="cb-field cb-field-state"><span>' . esc_html__( 'State / union territory', '3d-corporate-book' ) . '</span><select name="state" required autocomplete="address-level1">' . $options . '</select><em class="cb-field-error" data-for="state"></em></label>'
      . $field( 'pin', __( 'PIN code', '3d-corporate-book' ), 'text', 'required inputmode="numeric" maxlength="7" autocomplete="postal-code" placeholder="201301"' );
  }

  /**
   * Saved delivery address, falling back to the account's name and email.
   *
   * @param WP_User $user User.
   *
   * @return array
   */
  public static function saved_address( $user ) {
    $saved = get_user_meta( $user->ID, self::ADDRESS, true );
    $saved = is_array( $saved ) ? $saved : array();
    return $saved + array( 'name' => trim( $user->first_name . ' ' . $user->last_name ), 'email' => $user->user_email, 'phone' => get_user_meta( $user->ID, 'cb_phone', true ) );
  }

  /**
   * [corporate_book_cart]: the cart, the delivery address, the shipping quote and payment.
   *
   * @return string HTML.
   */
  public function cart_page() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
      define( 'DONOTCACHEPAGE', true );
    }
    if ( 'true' !== self::config( 'print_enable' ) ) {
      return '<p>' . esc_html__( 'Print copies are not on sale at the moment.', '3d-corporate-book' ) . '</p>';
    }
    self::enqueue();
    $cart = '<div class="cb-cart-items" aria-live="polite"><p class="cb-cart-loading">' . esc_html__( 'Loading your cart…', '3d-corporate-book' ) . '</p></div>';
    if ( ! is_user_logged_in() ) {
      return '<div class="cb-ui cb-cart cb-cart-guest">' . $cart . '<div class="cb-cart-signin"><h3>' . esc_html__( 'Sign in to place your order', '3d-corporate-book' ) . '</h3><p>' . esc_html__( 'Your cart is kept while you sign in or create an account.', '3d-corporate-book' ) . '</p><div class="cb-lock cb-lock-static"><div class="cb-lock-box">' . Corporate_Book_Account::box( false ) . '</div></div></div></div>';
    }
    $cod = 'true' === self::config( 'print_cod' );
    $pay = '<fieldset class="cb-pay"><legend>' . esc_html__( 'Payment', '3d-corporate-book' ) . '</legend>'
      . '<label><input type="radio" name="payment" value="razorpay" checked/> ' . esc_html__( 'Pay online now (UPI, cards, net banking)', '3d-corporate-book' ) . '</label>'
      . ( $cod ? '<label><input type="radio" name="payment" value="cod"/> ' . esc_html__( 'Cash on delivery', '3d-corporate-book' ) . '</label>' : '' ) . '</fieldset>';
    return '<div class="cb-ui cb-cart">' . $cart
      . '<form class="cb-checkout" novalidate><h3>' . esc_html__( 'Delivery address', '3d-corporate-book' ) . '</h3><div class="cb-address">' . self::address_fields( self::saved_address( wp_get_current_user() ) ) . '</div>'
      . '<div class="cb-coupon"><label class="cb-field"><span>' . esc_html__( 'Coupon code (optional)', '3d-corporate-book' ) . '</span><input type="text" name="coupon" autocomplete="off"/></label><button type="button" class="cb-apply-coupon">' . esc_html__( 'Apply', '3d-corporate-book' ) . '</button></div>'
      . '<label class="cb-gift-toggle"><input type="checkbox" name="gift" value="1"/> ' . esc_html__( 'This is a gift: deliver to the address above, without prices on the packing slip', '3d-corporate-book' ) . '</label>'
      . '<label class="cb-field cb-gift-message" hidden><span>' . esc_html__( 'Gift message (optional, printed on the packing slip)', '3d-corporate-book' ) . '</span><textarea name="gift_message" maxlength="300" rows="2"></textarea></label>'
      . '<div class="cb-cart-totals"></div>' . $pay
      . '<button type="submit" class="cb-place-order">' . esc_html__( 'Place order', '3d-corporate-book' ) . '</button><p class="cb-cart-message" role="alert"></p>'
      . '<p class="cb-cart-note">' . esc_html__( 'Books ship from Noida, Uttar Pradesh. Delivery within India only.', '3d-corporate-book' ) . '</p></form></div>';
  }

  // ---- Requests ------------------------------------------------------------------------------

  private static function posted_items() {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers check the nonce.
    $raw   = isset( $_POST['items'] ) ? json_decode( wp_unslash( (string) $_POST['items'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and cast below.
    $items = array();
    foreach ( is_array( $raw ) ? array_slice( $raw, 0, 50 ) : array() as $item ) {
      if ( is_array( $item ) && isset( $item['id'] ) ) {
        $items[] = array( 'id' => absint( $item['id'] ), 'qty' => isset( $item['qty'] ) ? (int) $item['qty'] : 1 );
      }
    }
    return $items;
  }

  /**
   * Quote as the browser shows it: formatted money, quantities and limits.
   *
   * @param array $quote From quote().
   *
   * @return array
   */
  private static function present( $quote ) {
    $lines = array();
    foreach ( $quote['lines'] as $line ) {
      $lines[] = array(
        'id'    => $line['id'],
        'title' => $line['title'],
        'thumb' => $line['thumb'],
        'qty'   => $line['qty'],
        'max'   => $line['max'],
        'price' => self::money( $line['price'] ),
        'total' => self::money( $line['price'] * $line['qty'] ),
      );
    }
    $distance = null === $quote['zone'] ? '' : self::zone_label( $quote['zone'] ) . ( $quote['grams'] ? ' · ' . number_format_i18n( $quote['grams'] ) . ' g' : '' );
    return array(
      'lines'    => $lines,
      'discount' => $quote['discount'] ? '− ' . self::money( $quote['discount'] ) : null,
      'coupon'   => $quote['coupon'],
      /* translators: %s: days, for example 3-5 */
      'days'     => '' !== $quote['days'] ? sprintf( __( 'Delivery in about %s days', '3d-corporate-book' ), $quote['days'] ) : '',
      'subtotal' => self::money( $quote['subtotal'] ),
      'shipping' => null === $quote['shipping'] ? null : ( 0 === $quote['shipping'] ? __( 'Free', '3d-corporate-book' ) : self::money( $quote['shipping'] ) ),
      'total'    => self::money( $quote['total'] ),
      'distance' => $distance,
      // Price Details box: the shipping the reader did not pay, and everything they saved.
      'shippingWas' => $quote['waived'] ? self::money( $quote['waived'] ) : '',
      'saved'       => $quote['discount'] + $quote['waived'] > 0 ? self::money( $quote['discount'] + $quote['waived'] ) : '',
      'copies'      => (int) array_sum( wp_list_pluck( $quote['lines'], 'qty' ) ),
      'errors'   => $quote['errors'],
    );
  }

  public function ajax_quote() {
    check_ajax_referer( self::NONCE );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
    $pin = isset( $_POST['pin'] ) ? sanitize_text_field( wp_unslash( $_POST['pin'] ) ) : '';
    if ( '' !== self::clean_pin( $pin ) && ! self::valid_pin( $pin ) ) {
      $pin = '';
    }
    wp_send_json_success( self::present( self::quote( self::posted_items(), $pin, self::posted_coupon() ) ) );
  }

  private static function posted_coupon() {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers check the nonce.
    return isset( $_POST['coupon'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon'] ) ) : '';
  }

  public function ajax_save_address() {
    check_ajax_referer( self::NONCE );
    if ( ! is_user_logged_in() ) {
      wp_send_json_error( __( 'Sign in again.', '3d-corporate-book' ), 401 );
    }
    list( $address, $errors ) = self::validate_address( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked above; cleaned in validate_address().
    if ( $errors ) {
      wp_send_json_error( array( 'fields' => $errors ), 400 );
    }
    update_user_meta( get_current_user_id(), self::ADDRESS, $address );
    wp_send_json_success( array( 'message' => __( 'Your delivery address is saved.', '3d-corporate-book' ) ) );
  }

  public function ajax_checkout() {
    check_ajax_referer( self::NONCE );
    $user = wp_get_current_user();
    if ( ! $user->exists() ) {
      wp_send_json_error( __( 'Sign in to place your order.', '3d-corporate-book' ), 401 );
    }
    list( $address, $errors ) = self::validate_address( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked above; cleaned in validate_address().
    if ( $errors ) {
      wp_send_json_error( array( 'message' => __( 'Check the delivery address.', '3d-corporate-book' ), 'fields' => $errors ), 400 );
    }
    $quote = self::quote( self::posted_items(), $address['pin'], self::posted_coupon() );
    if ( ! $quote['lines'] || $quote['errors'] ) {
      wp_send_json_error( array( 'message' => $quote['lines'] ? implode( ' ', $quote['errors'] ) : __( 'Your cart is empty.', '3d-corporate-book' ), 'quote' => self::present( $quote ) ), 400 );
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
    $method = isset( $_POST['payment'] ) && 'cod' === $_POST['payment'] && 'true' === self::config( 'print_cod' ) ? 'cod' : 'razorpay';
    if ( 'razorpay' === $method && ! Corporate_Book_Account::razorpay_ready() ) {
      wp_send_json_error( array( 'message' => __( 'Online payment is not set up yet. Please try again later.', '3d-corporate-book' ) ), 503 );
    }
    // At most 10 orders an hour per reader, so cash-on-delivery orders cannot be used to flood the shop.
    $tries = (int) get_transient( 'cb_checkout_' . $user->ID );
    if ( $tries >= 10 ) {
      wp_send_json_error( array( 'message' => __( 'Too many orders in a short time. Please try again in an hour.', '3d-corporate-book' ) ), 429 );
    }
    set_transient( 'cb_checkout_' . $user->ID, $tries + 1, HOUR_IN_SECONDS );
    update_user_meta( $user->ID, self::ADDRESS, $address );
    $titles = implode( ', ', wp_list_pluck( $quote['lines'], 'title' ) );
    // The content is never shown; it makes the admin order search find customers by name, phone, email, city, PIN or book.
    $order = wp_insert_post( array( 'post_type' => self::ORDER, 'post_status' => 'cb-pending', 'post_author' => $user->ID, 'post_title' => __( 'Print order', '3d-corporate-book' ), 'post_content' => implode( ' ', array( $address['name'], $address['phone'], $address['email'], $address['city'], $address['pin'], $titles ) ) ) );
    if ( ! $order || is_wp_error( $order ) ) {
      wp_send_json_error( array( 'message' => __( 'The order could not be saved. Try again.', '3d-corporate-book' ) ), 500 );
    }
    $items = array();
    foreach ( $quote['lines'] as $line ) {
      $items[] = array( 'id' => $line['id'], 'title' => $line['title'], 'qty' => $line['qty'], 'price' => $line['price'] );
    }
    update_post_meta( $order, '_cb_items', $items );
    update_post_meta( $order, '_cb_totals', array( 'subtotal' => $quote['subtotal'], 'discount' => $quote['discount'], 'coupon' => $quote['coupon'], 'shipping' => (int) $quote['shipping'], 'total' => $quote['total'], 'zone' => $quote['zone'], 'grams' => $quote['grams'], 'cost' => (int) $quote['cost'], 'days' => $quote['days'] ) );
    update_post_meta( $order, '_cb_address', $address );
    update_post_meta( $order, '_cb_payment', $method );
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
    if ( ! empty( $_POST['gift'] ) && 'false' !== $_POST['gift'] ) {
      update_post_meta( $order, '_cb_gift', mb_substr( sanitize_textarea_field( wp_unslash( isset( $_POST['gift_message'] ) ? (string) $_POST['gift_message'] : '' ) ), 0, 300 ) ?: ' ' );
    }
    // phpcs:enable
    wp_update_post( array( 'ID' => $order, 'post_title' => sprintf( /* translators: %d: order number */ __( 'Order #%d', '3d-corporate-book' ), $order ) ) );
    if ( 'cod' === $method ) {
      wp_update_post( array( 'ID' => $order, 'post_status' => 'cb-cod' ) );
      wp_send_json_success( array( 'done' => true, 'order' => $order, 'message' => self::thanks( $order ) ) );
    }
    $rzp = Corporate_Book_Account::api(
      'POST',
      'orders',
      array(
        'amount'   => $quote['total'],
        'currency' => self::config( 'subscription_currency' ),
        'receipt'  => 'cb-print-' . $order,
        'notes'    => array( 'print_order' => (string) $order, 'user_id' => (string) $user->ID ),
      )
    );
    if ( is_wp_error( $rzp ) || empty( $rzp['id'] ) || ! preg_match( '/^order_[A-Za-z0-9]+$/', $rzp['id'] ) ) {
      wp_send_json_error( array( 'message' => __( 'The payment could not be started. Try again.', '3d-corporate-book' ) ), 502 );
    }
    update_post_meta( $order, '_cb_rzp_order', $rzp['id'] );
    wp_send_json_success(
      array(
        'order'       => $order,
        'key'         => self::config( 'razorpay_key_id' ),
        'order_id'    => $rzp['id'],
        'amount'      => $quote['total'],
        'currency'    => self::config( 'subscription_currency' ),
        'name'        => get_bloginfo( 'name' ),
        /* translators: %d: order number */
        'description' => sprintf( __( 'Print order #%d', '3d-corporate-book' ), $order ),
        'prefill'     => array( 'name' => $address['name'], 'email' => $address['email'], 'contact' => $address['phone'] ),
      )
    );
  }

  public function ajax_verify() {
    check_ajax_referer( self::NONCE );
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
    $order      = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0;
    $payment_id = isset( $_POST['payment_id'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_id'] ) ) : '';
    $signature  = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';
    // phpcs:enable
    $failed = __( 'The payment could not be verified. If money was taken, contact us with your order number.', '3d-corporate-book' );
    $post   = get_post( $order );
    if ( ! $post || self::ORDER !== $post->post_type || (int) $post->post_author !== get_current_user_id() || ! preg_match( '/^pay_[A-Za-z0-9]+$/', $payment_id ) ) {
      wp_send_json_error( $failed, 400 );
    }
    if ( 'cb-pending' === $post->post_status ) {
      $rzp_order = (string) get_post_meta( $order, '_cb_rzp_order', true );
      $secret    = Corporate_Book::get_instance()->get_config( 'razorpay_key_secret' );
      if ( '' === $rzp_order || ! hash_equals( hash_hmac( 'sha256', $rzp_order . '|' . $payment_id, $secret ), $signature ) ) {
        wp_send_json_error( $failed, 400 );
      }
      $totals  = get_post_meta( $order, '_cb_totals', true );
      $payment = Corporate_Book_Account::api( 'GET', 'payments/' . $payment_id );
      if ( ! is_wp_error( $payment ) && ( ! isset( $payment['order_id'], $payment['amount'] ) || $payment['order_id'] !== $rzp_order || (int) $payment['amount'] !== (int) $totals['total'] || ! Corporate_Book_Account::captured( $payment, $totals['total'] ) ) ) {
        wp_send_json_error( $failed, 400 );
      }
      self::mark_paid( $order, $payment_id );
    }
    wp_send_json_success( array( 'done' => true, 'order' => $order, 'message' => self::thanks( $order ) ) );
  }

  /**
   * Reader cancels an order that has not shipped. Online payments are refunded through Razorpay;
   * if the refund fails the order is cancelled and the shop is asked to refund by hand.
   */
  public function ajax_cancel() {
    check_ajax_referer( self::NONCE );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
    $order = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0;
    $post  = get_post( $order );
    if ( ! $post || self::ORDER !== $post->post_type || (int) $post->post_author !== get_current_user_id() || ! in_array( $post->post_status, array( 'cb-paid', 'cb-cod' ), true ) ) {
      wp_send_json_error( __( 'This order can no longer be cancelled. Please contact us.', '3d-corporate-book' ), 400 );
    }
    if ( 'cb-cod' === $post->post_status ) {
      wp_update_post( array( 'ID' => $order, 'post_status' => 'cb-cancelled' ) );
      wp_send_json_success( array( 'message' => __( 'Your order is cancelled.', '3d-corporate-book' ) ) );
    }
    $totals = (array) get_post_meta( $order, '_cb_totals', true ) + array( 'total' => 0 );
    $refund = Corporate_Book_Account::api( 'POST', 'payments/' . get_post_meta( $order, '_cb_rzp_payment', true ) . '/refund', array( 'amount' => (int) $totals['total'] ) );
    if ( ! is_wp_error( $refund ) && ! empty( $refund['id'] ) ) {
      update_post_meta( $order, '_cb_refund', sanitize_text_field( $refund['id'] ) );
      wp_update_post( array( 'ID' => $order, 'post_status' => 'cb-refunded' ) );
      wp_send_json_success( array( 'message' => __( 'Your order is cancelled and the payment is being refunded (usually 5-7 working days).', '3d-corporate-book' ) ) );
    }
    wp_update_post( array( 'ID' => $order, 'post_status' => 'cb-cancelled' ) );
    $to = is_email( self::config( 'print_email' ) ) ? self::config( 'print_email' ) : get_option( 'admin_email' );
    /* translators: %d: order number */
    wp_mail( $to, sprintf( __( 'Refund needed: order #%d was cancelled', '3d-corporate-book' ), $order ), __( 'The automatic Razorpay refund failed. Please refund this payment in the Razorpay Dashboard.', '3d-corporate-book' ) . "\n\n" . self::summary( $order ) );
    wp_send_json_success( array( 'message' => __( 'Your order is cancelled. We will refund your payment shortly.', '3d-corporate-book' ) ) );
  }

  private static function thanks( $order ) {
    /* translators: %d: order number */
    return sprintf( __( 'Thank you! Your order #%d is placed. We have emailed you the details; you can follow it on My account.', '3d-corporate-book' ), $order );
  }

  /**
   * Marks a pending order paid (from the browser check or the Razorpay webhook).
   *
   * @param int    $order      Order ID.
   * @param string $payment_id Razorpay payment ID.
   */
  public static function mark_paid( $order, $payment_id ) {
    if ( 'cb-pending' !== get_post_status( $order ) ) {
      return;
    }
    update_post_meta( $order, '_cb_rzp_payment', $payment_id );
    wp_update_post( array( 'ID' => $order, 'post_status' => 'cb-paid' ) );
  }

  /**
   * Razorpay webhook events for print orders (called by Corporate_Book_Account::webhook()).
   *
   * @param string $type    Event name.
   * @param array  $payment Payment entity.
   */
  public static function razorpay_event( $type, $payment ) {
    $order = isset( $payment['notes']['print_order'] ) ? (int) $payment['notes']['print_order'] : 0;
    if ( ! $order || self::ORDER !== get_post_type( $order ) || (string) get_post_meta( $order, '_cb_rzp_order', true ) !== ( isset( $payment['order_id'] ) ? (string) $payment['order_id'] : '' ) ) {
      return;
    }
    $totals = get_post_meta( $order, '_cb_totals', true );
    if ( in_array( $type, array( 'payment.captured', 'order.paid' ), true ) && isset( $payment['amount'], $payment['id'] ) && (int) $payment['amount'] === (int) $totals['total'] ) {
      self::mark_paid( $order, (string) $payment['id'] );
    } elseif ( in_array( $type, array( 'refund.processed', 'payment.refunded' ), true ) && in_array( get_post_status( $order ), array( 'cb-paid', 'cb-shipped', 'cb-delivered' ), true ) ) {
      wp_update_post( array( 'ID' => $order, 'post_status' => 'cb-refunded' ) );
    }
  }

  // ---- Status changes: stock and emails ------------------------------------------------------

  private static function change_stock( $order, $direction ) {
    foreach ( (array) get_post_meta( $order, '_cb_items', true ) as $item ) {
      $data = get_post_meta( $item['id'], '_corporate_book_data', true );
      if ( is_array( $data ) && isset( $data['print_stock'] ) && '' !== $data['print_stock'] && is_numeric( $data['print_stock'] ) ) {
        $before              = (int) $data['print_stock'];
        $data['print_stock'] = (string) max( 0, $before + $direction * (int) $item['qty'] );
        update_post_meta( $item['id'], '_corporate_book_data', $data );
        $limit = (int) self::config( 'print_low_stock' );
        if ( $direction < 0 && $limit > 0 && (int) $data['print_stock'] <= $limit && $before > $limit ) {
          $to = is_email( self::config( 'print_email' ) ) ? self::config( 'print_email' ) : get_option( 'admin_email' );
          /* translators: 1: book title, 2: copies left */
          wp_mail( $to, sprintf( __( 'Low stock: "%1$s" has %2$d copies left', '3d-corporate-book' ), get_the_title( $item['id'] ), $data['print_stock'] ), get_edit_post_link( $item['id'], 'raw' ) );
        }
      }
    }
  }

  public function status_changed( $new, $old, $post ) {
    if ( self::ORDER !== $post->post_type || $new === $old ) {
      return;
    }
    $order = $post->ID;
    $taken = (bool) get_post_meta( $order, '_cb_stock_taken', true );
    if ( in_array( $new, array( 'cb-paid', 'cb-cod' ), true ) && ! $taken ) {
      self::change_stock( $order, -1 );
      update_post_meta( $order, '_cb_stock_taken', 1 );
    } elseif ( in_array( $new, array( 'cb-cancelled', 'cb-refunded', 'trash' ), true ) && $taken ) {
      self::change_stock( $order, 1 );
      delete_post_meta( $order, '_cb_stock_taken' );
    }
    // "Order placed" emails only when the order is new, not when a cash order is later marked Paid.
    $placed = in_array( $new, array( 'cb-paid', 'cb-cod' ), true ) && in_array( $old, array( 'cb-pending', 'new', 'auto-draft' ), true );
    if ( in_array( $new, array( 'cb-paid', 'cb-cod' ), true ) && ! get_post_meta( $order, '_cb_invoice', true ) ) {
      // Running invoice numbers, given once.
      $next = max( 1, (int) get_option( 'corporate_book_invoice_next', 1 ) );
      update_option( 'corporate_book_invoice_next', $next + 1 );
      update_post_meta( $order, '_cb_invoice', self::config( 'invoice_prefix' ) . str_pad( (string) $next, 5, '0', STR_PAD_LEFT ) );
    }
    // Online reading included with print: from payment, or from delivery for cash on delivery.
    $days = (int) self::config( 'print_bundle_days' );
    if ( $days > 0 && ( 'cb-paid' === $new || ( 'cb-delivered' === $new && 'cod' === get_post_meta( $order, '_cb_payment', true ) ) ) && ! get_post_meta( $order, '_cb_bundle', true ) ) {
      update_post_meta( $order, '_cb_bundle', 1 );
      $reader = (int) $post->post_author;
      update_user_meta( $reader, Corporate_Book_Account::UNTIL, max( time(), Corporate_Book_Account::until( $reader ) ) + $days * DAY_IN_SECONDS );
    }
    if ( $placed || in_array( $new, array( 'cb-shipped', 'cb-delivered', 'cb-cancelled', 'cb-refunded' ), true ) ) {
      self::email_customer( $order, $new );
    }
    if ( $placed ) {
      self::email_shop( $order );
    }
  }

  /**
   * Plain-text summary of an order for emails.
   *
   * @param int $order Order ID.
   *
   * @return string
   */
  public static function summary( $order ) {
    $lines   = array();
    foreach ( (array) get_post_meta( $order, '_cb_items', true ) as $item ) {
      $lines[] = sprintf( '%d x %s  %s', $item['qty'], $item['title'], self::money( $item['price'] * $item['qty'] ) );
    }
    $totals  = (array) get_post_meta( $order, '_cb_totals', true ) + array( 'subtotal' => 0, 'shipping' => 0, 'total' => 0 );
    $address = (array) get_post_meta( $order, '_cb_address', true ) + array( 'name' => '', 'phone' => '', 'line1' => '', 'line2' => '', 'landmark' => '', 'city' => '', 'state' => '', 'pin' => '' );
    $place   = implode( ', ', array_filter( array( $address['line1'], $address['line2'], $address['landmark'], $address['city'], $address['state'] . ' ' . $address['pin'] ) ) );
    $gift = trim( (string) get_post_meta( $order, '_cb_gift', true ) );
    return implode( "\n", $lines ) . "\n\n"
      . __( 'Books', '3d-corporate-book' ) . ': ' . self::money( $totals['subtotal'] ) . "\n"
      . ( ! empty( $totals['discount'] ) ? __( 'Coupon', '3d-corporate-book' ) . ' ' . $totals['coupon'] . ': -' . self::money( $totals['discount'] ) . "\n" : '' )
      . __( 'Shipping', '3d-corporate-book' ) . ': ' . self::money( $totals['shipping'] ) . "\n"
      . ( get_post_meta( $order, '_cb_gift', true ) ? __( 'Gift', '3d-corporate-book' ) . ( '' !== $gift ? ': "' . $gift . '"' : '' ) . "\n" : '' )
      . __( 'Total', '3d-corporate-book' ) . ': ' . self::money( $totals['total'] ) . "\n\n"
      . __( 'Deliver to', '3d-corporate-book' ) . ': ' . $address['name'] . ', ' . $place . ' (' . $address['phone'] . ')';
  }

  private static function email_customer( $order, $status ) {
    $address = (array) get_post_meta( $order, '_cb_address', true );
    if ( empty( $address['email'] ) ) {
      return;
    }
    $site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    $label   = self::statuses()[ $status ];
    $intro   = array(
      'cb-paid'      => __( 'Thank you for your order. We will pack and ship it soon.', '3d-corporate-book' ),
      'cb-cod'       => __( 'Thank you for your order. Please keep the amount ready for the courier.', '3d-corporate-book' ),
      'cb-shipped'   => __( 'Your books are on their way.', '3d-corporate-book' ),
      'cb-delivered' => __( 'Your books have been delivered. Happy reading!', '3d-corporate-book' ),
      'cb-cancelled' => __( 'Your order has been cancelled.', '3d-corporate-book' ),
      'cb-refunded'  => __( 'Your payment has been refunded.', '3d-corporate-book' ),
    );
    $tracking = '';
    if ( 'cb-shipped' === $status ) {
      $tracking = "\n\n" . __( 'Courier', '3d-corporate-book' ) . ': ' . get_post_meta( $order, '_cb_courier', true ) . "\n" . __( 'Tracking number', '3d-corporate-book' ) . ': ' . get_post_meta( $order, '_cb_tracking', true )
        . ( get_post_meta( $order, '_cb_tracking_url', true ) ? "\n" . __( 'Track it here', '3d-corporate-book' ) . ': ' . get_post_meta( $order, '_cb_tracking_url', true ) : '' );
    }
    /* translators: 1: site name, 2: order number, 3: status */
    $subject = sprintf( __( '[%1$s] Order #%2$d: %3$s', '3d-corporate-book' ), $site, $order, $label );
    wp_mail( $address['email'], $subject, $intro[ $status ] . $tracking . "\n\n" . self::summary( $order ) . "\n\n" . __( 'Your orders', '3d-corporate-book' ) . ': ' . Corporate_Book_Account::profile_url() );
  }

  private static function email_shop( $order ) {
    $to = self::config( 'print_email' );
    $to = is_email( $to ) ? $to : get_option( 'admin_email' );
    $payment = 'cod' === get_post_meta( $order, '_cb_payment', true ) ? __( 'Cash on delivery', '3d-corporate-book' ) : __( 'Paid online', '3d-corporate-book' );
    /* translators: 1: order number, 2: payment method */
    wp_mail( $to, sprintf( __( 'New print order #%1$d (%2$s)', '3d-corporate-book' ), $order, $payment ), self::summary( $order ) . "\n\n" . admin_url( 'post.php?post=' . (int) $order . '&action=edit' ) );
  }

  /**
   * Daily: removes Razorpay orders that were started but never paid, after two days.
   */
  public function clean_unpaid() {
    foreach ( get_posts( array( 'post_type' => self::ORDER, 'post_status' => 'cb-pending', 'numberposts' => 100, 'fields' => 'ids', 'date_query' => array( array( 'before' => '2 days ago' ) ) ) ) as $order ) {
      wp_delete_post( $order, true );
    }
  }

  // ---- Admin: Print Orders -------------------------------------------------------------------

  public function columns( $columns ) {
    return array(
      'cb' => isset( $columns['cb'] ) ? $columns['cb'] : '',
      'title'       => __( 'Order', '3d-corporate-book' ),
      'cb_customer' => __( 'Customer', '3d-corporate-book' ),
      'cb_books'    => __( 'Books', '3d-corporate-book' ),
      'cb_total'    => __( 'Total', '3d-corporate-book' ),
      'cb_status'   => __( 'Status', '3d-corporate-book' ),
      'date'        => __( 'Date', '3d-corporate-book' ),
    );
  }

  public function column( $column, $order ) {
    $address = (array) get_post_meta( $order, '_cb_address', true ) + array( 'name' => '', 'phone' => '', 'city' => '', 'pin' => '' );
    if ( 'cb_customer' === $column ) {
      echo esc_html( $address['name'] ) . '<br><small>' . esc_html( $address['phone'] . ' · ' . $address['city'] . ' ' . $address['pin'] ) . '</small>';
    } elseif ( 'cb_books' === $column ) {
      $books = array();
      foreach ( (array) get_post_meta( $order, '_cb_items', true ) as $item ) {
        $books[] = (int) $item['qty'] . ' × ' . $item['title'];
      }
      echo esc_html( implode( ', ', $books ) );
    } elseif ( 'cb_total' === $column ) {
      $totals = (array) get_post_meta( $order, '_cb_totals', true ) + array( 'total' => 0 );
      echo esc_html( self::money( $totals['total'] ) ) . '<br><small>' . esc_html( 'cod' === get_post_meta( $order, '_cb_payment', true ) ? __( 'Cash on delivery', '3d-corporate-book' ) : __( 'Razorpay', '3d-corporate-book' ) ) . '</small>';
    } elseif ( 'cb_status' === $column ) {
      $status = get_post_status( $order );
      echo '<span class="cb-order-status cb-status-' . esc_attr( $status ) . '">' . esc_html( isset( self::statuses()[ $status ] ) ? self::statuses()[ $status ] : $status ) . '</span>';
    }
  }

  public function order_boxes() {
    remove_meta_box( 'submitdiv', self::ORDER, 'side' );
    add_meta_box( 'cb_order_details', __( 'Order details', '3d-corporate-book' ), array( $this, 'details_box' ), self::ORDER, 'normal', 'high' );
    add_meta_box( 'cb_order_manage', __( 'Status and shipping', '3d-corporate-book' ), array( $this, 'manage_box' ), self::ORDER, 'side', 'high' );
  }

  public function details_box( $post ) {
    $order   = $post->ID;
    $address = (array) get_post_meta( $order, '_cb_address', true ) + array( 'name' => '', 'phone' => '', 'email' => '', 'line1' => '', 'line2' => '', 'landmark' => '', 'city' => '', 'state' => '', 'pin' => '' );
    $totals  = (array) get_post_meta( $order, '_cb_totals', true ) + array( 'subtotal' => 0, 'shipping' => 0, 'total' => 0, 'zone' => '', 'grams' => 0, 'cost' => null );
    echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Book', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Copies', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Price', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Amount', '3d-corporate-book' ) . '</th></tr></thead><tbody>';
    foreach ( (array) get_post_meta( $order, '_cb_items', true ) as $item ) {
      echo '<tr><td><a href="' . esc_url( get_edit_post_link( $item['id'] ) ) . '">' . esc_html( $item['title'] ) . '</a></td><td>' . (int) $item['qty'] . '</td><td>' . esc_html( self::money( $item['price'] ) ) . '</td><td>' . esc_html( self::money( $item['price'] * $item['qty'] ) ) . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><th colspan="3">' . esc_html__( 'Books', '3d-corporate-book' ) . '</th><th>' . esc_html( self::money( $totals['subtotal'] ) ) . '</th></tr>'
      . '<tr><th colspan="3">' . esc_html__( 'Shipping', '3d-corporate-book' ) . ( '' !== (string) $totals['zone'] ? ' (' . esc_html( self::zone_label( $totals['zone'] ) . ( $totals['grams'] ? ', ' . number_format_i18n( $totals['grams'] ) . ' g' : '' ) ) . ')' : '' ) . '</th><th>' . esc_html( self::money( $totals['shipping'] ) ) . '</th></tr>'
      . '<tr><th colspan="3">' . esc_html__( 'Total', '3d-corporate-book' ) . '</th><th>' . esc_html( self::money( $totals['total'] ) ) . '</th></tr>'
      . ( null !== $totals['cost'] ? '<tr><td colspan="3">' . esc_html__( 'Estimated profit (after copies, box and courier)', '3d-corporate-book' ) . '</td><td>' . esc_html( self::money( $totals['total'] - $totals['cost'] ) ) . '</td></tr>' : '' )
      . '</tfoot></table>';
    $user = get_userdata( $post->post_author );
    echo '<h3>' . esc_html__( 'Deliver to', '3d-corporate-book' ) . '</h3><p class="cb-order-address">' . esc_html( $address['name'] ) . '<br>' . esc_html( $address['line1'] ) . ( $address['line2'] ? '<br>' . esc_html( $address['line2'] ) : '' ) . ( $address['landmark'] ? '<br>' . esc_html__( 'Near', '3d-corporate-book' ) . ' ' . esc_html( $address['landmark'] ) : '' )
      . '<br>' . esc_html( $address['city'] . ', ' . $address['state'] . ' ' . $address['pin'] ) . '<br>' . esc_html__( 'Mobile', '3d-corporate-book' ) . ': <a href="tel:+91' . esc_attr( $address['phone'] ) . '">+91 ' . esc_html( $address['phone'] ) . '</a><br>' . esc_html__( 'Email', '3d-corporate-book' ) . ': <a href="mailto:' . esc_attr( $address['email'] ) . '">' . esc_html( $address['email'] ) . '</a></p>';
    echo '<p>' . esc_html__( 'Reader account', '3d-corporate-book' ) . ': ' . ( $user ? '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->display_name ) . '</a>' : '-' ) . '<br>'
      . esc_html__( 'Payment', '3d-corporate-book' ) . ': ' . esc_html( 'cod' === get_post_meta( $order, '_cb_payment', true ) ? __( 'Cash on delivery', '3d-corporate-book' ) : __( 'Razorpay', '3d-corporate-book' ) . ' ' . get_post_meta( $order, '_cb_rzp_order', true ) . ' ' . get_post_meta( $order, '_cb_rzp_payment', true ) ) . '</p>';
  }

  public function manage_box( $post ) {
    wp_nonce_field( 'cb_order_save', 'cb_order_nonce' );
    $options = '';
    foreach ( self::statuses() as $status => $label ) {
      $options .= '<option value="' . esc_attr( $status ) . '" ' . selected( $post->post_status, $status, false ) . '>' . esc_html( $label ) . '</option>';
    }
    $field = function ( $name, $label, $type = 'text' ) use ( $post ) {
      return '<p><label for="cb_order_' . esc_attr( $name ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><input type="' . esc_attr( $type ) . '" class="widefat" id="cb_order_' . esc_attr( $name ) . '" name="cb_order[' . esc_attr( $name ) . ']" value="' . esc_attr( get_post_meta( $post->ID, '_cb_' . $name, true ) ) . '"/></p>';
    };
    echo '<p><label for="cb_order_status"><strong>' . esc_html__( 'Status', '3d-corporate-book' ) . '</strong></label><br><select class="widefat" id="cb_order_status" name="cb_order[status]">' . $options . '</select></p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- options escaped above.
      . $field( 'courier', __( 'Courier', '3d-corporate-book' ) ) . $field( 'tracking', __( 'Tracking number', '3d-corporate-book' ) ) . $field( 'tracking_url', __( 'Tracking link', '3d-corporate-book' ), 'url' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fields escaped in the closure.
      . '<p><label for="cb_order_note"><strong>' . esc_html__( 'Private note', '3d-corporate-book' ) . '</strong></label><br><textarea class="widefat" rows="3" id="cb_order_note" name="cb_order[note]">' . esc_textarea( get_post_meta( $post->ID, '_cb_note', true ) ) . '</textarea></p>'
      . '<p class="description">' . esc_html__( 'The customer gets an email when the status changes to Shipped (with the tracking details), Delivered, Cancelled or Refunded. Cancelling or refunding puts the copies back in stock.', '3d-corporate-book' ) . '</p>'
      . '<p><button type="submit" name="save" class="button button-primary button-large" value="update">' . esc_html__( 'Save order', '3d-corporate-book' ) . '</button></p>';
    do_action( 'cb_order_manage_box', $post );
  }

  /**
   * Saves the order screen: shipping details first, then the status, so the "shipped" email has the tracking number.
   *
   * @param array $data    Post fields about to be saved.
   * @param array $postarr Submitted fields.
   *
   * @return array
   */
  public function save_order_form( $data, $postarr ) {
    if ( self::ORDER !== $data['post_type'] || empty( $postarr['ID'] ) || ! isset( $_POST['cb_order_nonce'], $_POST['cb_order'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['cb_order_nonce'] ) ), 'cb_order_save' ) || ! current_user_can( 'edit_post', $postarr['ID'] ) ) {
      return $data;
    }
    $form = wp_unslash( (array) $_POST['cb_order'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is cleaned below.
    update_post_meta( $postarr['ID'], '_cb_courier', isset( $form['courier'] ) ? sanitize_text_field( $form['courier'] ) : '' );
    update_post_meta( $postarr['ID'], '_cb_tracking', isset( $form['tracking'] ) ? sanitize_text_field( $form['tracking'] ) : '' );
    update_post_meta( $postarr['ID'], '_cb_tracking_url', isset( $form['tracking_url'] ) ? esc_url_raw( $form['tracking_url'] ) : '' );
    update_post_meta( $postarr['ID'], '_cb_note', isset( $form['note'] ) ? sanitize_textarea_field( $form['note'] ) : '' );
    if ( isset( $form['status'] ) && isset( self::statuses()[ $form['status'] ] ) ) {
      $data['post_status'] = $form['status'];
    }
    return $data;
  }

  // ---- My account ----------------------------------------------------------------------------

  /**
   * "My orders" and "Delivery address" cards for the My account page.
   *
   * @param WP_User $user Reader.
   *
   * @return string HTML.
   */
  public static function account_cards( $user ) {
    if ( 'true' !== self::config( 'print_enable' ) ) {
      return '';
    }
    self::enqueue();
    $rows = '';
    foreach ( get_posts( array( 'post_type' => self::ORDER, 'author' => $user->ID, 'post_status' => array_keys( self::statuses() ), 'numberposts' => 50 ) ) as $post ) {
      if ( 'cb-pending' === $post->post_status ) {
        continue;
      }
      $books = array();
      foreach ( (array) get_post_meta( $post->ID, '_cb_items', true ) as $item ) {
        $books[] = (int) $item['qty'] . ' × ' . $item['title'];
      }
      $totals   = (array) get_post_meta( $post->ID, '_cb_totals', true ) + array( 'total' => 0 );
      $tracking = get_post_meta( $post->ID, '_cb_tracking', true );
      $url      = get_post_meta( $post->ID, '_cb_tracking_url', true );
      $track    = $tracking ? esc_html( get_post_meta( $post->ID, '_cb_courier', true ) . ' ' . $tracking ) : '';
      $track    = $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . ( $track ? $track : esc_html__( 'Track', '3d-corporate-book' ) ) . '</a>' : $track;
      $actions  = get_post_meta( $post->ID, '_cb_invoice', true ) ? '<a href="' . esc_url( self::document_url( $post->ID, 'invoice' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Invoice', '3d-corporate-book' ) . '</a>' : '';
      $actions .= in_array( $post->post_status, array( 'cb-paid', 'cb-cod' ), true ) ? ' <button type="button" class="cb-cancel-order" data-order="' . (int) $post->ID . '" data-confirm="' . esc_attr__( 'Cancel this order? Online payments are refunded.', '3d-corporate-book' ) . '">' . esc_html__( 'Cancel order', '3d-corporate-book' ) . '</button>' : '';
      $rows    .= '<tr><td>#' . (int) $post->ID . '<br><small>' . esc_html( get_the_date( '', $post ) ) . '</small></td><td>' . esc_html( implode( ', ', $books ) ) . '</td><td>' . esc_html( self::money( $totals['total'] ) ) . '</td><td><span class="cb-order-status cb-status-' . esc_attr( $post->post_status ) . '">' . esc_html( self::statuses()[ $post->post_status ] ) . '</span>' . ( $track ? '<br><small>' . $track . '</small>' : '' ) . ( $actions ? '<div class="cb-order-actions">' . $actions . '</div>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $track and $actions escaped above.
    }
    $orders = '<table class="cb-profile-orders cb-print-orders"><thead><tr><th>' . esc_html__( 'Order', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Books', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Total', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Status', '3d-corporate-book' ) . '</th></tr></thead><tbody>'
      . ( '' !== $rows ? $rows : '<tr><td colspan="4">' . esc_html__( 'No print orders yet.', '3d-corporate-book' ) . ' <a href="' . esc_url( self::cart_url() ) . '">' . esc_html__( 'Go to cart', '3d-corporate-book' ) . '</a></td></tr>' ) . '</tbody></table>';
    return Corporate_Book_Reader::history_card( $user ) . Corporate_Book_Reader::wishlist_card( $user ) . '<div class="cb-account cb-profile-card cb-profile-wide" id="cb-sec-orders"><h3>' . esc_html__( 'My print orders', '3d-corporate-book' ) . '</h3>' . $orders . '</div>'
      . '<div class="cb-account cb-profile-card cb-profile-wide" id="cb-sec-address"><h3>' . esc_html__( 'Delivery address', '3d-corporate-book' ) . '</h3><form class="cb-address-form cb-address" novalidate>' . self::address_fields( self::saved_address( $user ) ) . '<button type="submit">' . esc_html__( 'Save address', '3d-corporate-book' ) . '</button></form><p class="cb-account-message" role="alert"></p></div>';
  }
}

new Corporate_Book_Shop();
