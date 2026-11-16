<?php
/**
 * Print shop tools for readers and administrators: printable invoices and packing slips, CSV export of
 * orders, sending orders to Shiprocket, and the Sales dashboard (print, subscriptions and reading).
 *
 * @package corporate book
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

class Corporate_Book_Tools {

  const SHIPROCKET = 'https://apiv2.shiprocket.in/v1/external/';

  public function __construct() {
    add_action( 'template_redirect', array( $this, 'document' ) );
    add_action( 'admin_post_corporate_book_export_orders', array( $this, 'export' ) );
    add_action( 'admin_post_corporate_book_shiprocket', array( $this, 'shiprocket' ) );
    add_action( 'manage_posts_extra_tablenav', array( $this, 'export_button' ) );
    add_action( 'admin_menu', array( $this, 'menu' ) );
    add_action( 'admin_notices', array( $this, 'notice' ) );
    add_action( 'cb_order_manage_box', array( $this, 'order_buttons' ) );
  }

  private static function config( $key ) {
    return (string) Corporate_Book::get_instance()->get_config( $key );
  }

  private static function can_manage() {
    return current_user_can( 'edit_others_posts' );
  }

  // ---- Invoice and packing slip --------------------------------------------------------------

  public function document() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page; access is checked below.
    if ( ! isset( $_GET['cb_doc'], $_GET['cb_order'] ) ) {
      return;
    }
    $type  = 'slip' === $_GET['cb_doc'] ? 'slip' : 'invoice';
    $order = absint( $_GET['cb_order'] );
    // phpcs:enable
    $post = get_post( $order );
    $mine = $post && (int) $post->post_author === get_current_user_id() && is_user_logged_in();
    if ( ! $post || Corporate_Book_Shop::ORDER !== $post->post_type || ! ( self::can_manage() || ( 'invoice' === $type && $mine ) ) || ( 'invoice' === $type && ! get_post_meta( $order, '_cb_invoice', true ) ) ) {
      wp_die( esc_html__( 'This document is not available.', '3d-corporate-book' ), '', array( 'response' => 403 ) );
    }
    nocache_headers();
    echo self::render( $order, $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping in render().
    exit;
  }

  /**
   * Full HTML page of an invoice or packing slip. "Print / Save as PDF" uses the browser's PDF printer.
   *
   * @param int    $order Order ID.
   * @param string $type  invoice or slip.
   *
   * @return string
   */
  public static function render( $order, $type ) {
    $address  = (array) get_post_meta( $order, '_cb_address', true ) + array( 'name' => '', 'phone' => '', 'email' => '', 'line1' => '', 'line2' => '', 'landmark' => '', 'city' => '', 'state' => '', 'pin' => '' );
    $totals   = (array) get_post_meta( $order, '_cb_totals', true ) + array( 'subtotal' => 0, 'discount' => 0, 'coupon' => '', 'shipping' => 0, 'total' => 0 );
    $business = '' !== self::config( 'invoice_business' ) ? self::config( 'invoice_business' ) : get_bloginfo( 'name' );
    $slip     = 'slip' === $type;
    $gift     = get_post_meta( $order, '_cb_gift', true );
    $rows     = '';
    foreach ( (array) get_post_meta( $order, '_cb_items', true ) as $item ) {
      $rows .= '<tr><td>' . esc_html( $item['title'] ) . '</td><td class="n">' . (int) $item['qty'] . '</td>'
        . ( $slip ? '' : '<td class="n">' . esc_html( Corporate_Book_Shop::money( $item['price'] ) ) . '</td><td class="n">' . esc_html( Corporate_Book_Shop::money( $item['price'] * $item['qty'] ) ) . '</td>' ) . '</tr>';
    }
    $sum = '';
    if ( ! $slip ) {
      $line = function ( $label, $value ) {
        return '<tr><td colspan="3">' . esc_html( $label ) . '</td><td class="n">' . esc_html( $value ) . '</td></tr>';
      };
      $sum = $line( __( 'Books', '3d-corporate-book' ), Corporate_Book_Shop::money( $totals['subtotal'] ) )
        . ( $totals['discount'] ? $line( __( 'Coupon', '3d-corporate-book' ) . ' ' . $totals['coupon'], '- ' . Corporate_Book_Shop::money( $totals['discount'] ) ) : '' )
        . $line( __( 'Shipping', '3d-corporate-book' ), Corporate_Book_Shop::money( $totals['shipping'] ) )
        . '<tr class="t"><td colspan="3">' . esc_html__( 'Total', '3d-corporate-book' ) . '</td><td class="n">' . esc_html( Corporate_Book_Shop::money( $totals['total'] ) ) . '</td></tr>';
    }
    $to = esc_html( $address['name'] ) . '<br>' . esc_html( $address['line1'] ) . ( $address['line2'] ? '<br>' . esc_html( $address['line2'] ) : '' ) . ( $address['landmark'] ? '<br>' . esc_html( $address['landmark'] ) : '' )
      . '<br>' . esc_html( $address['city'] . ', ' . $address['state'] . ' ' . $address['pin'] ) . '<br>+91 ' . esc_html( $address['phone'] );
    $title = $slip ? __( 'Packing slip', '3d-corporate-book' ) : __( 'Invoice', '3d-corporate-book' );
    $meta  = ( $slip ? '' : '<strong>' . esc_html__( 'Invoice no.', '3d-corporate-book' ) . '</strong> ' . esc_html( get_post_meta( $order, '_cb_invoice', true ) ) . '<br>' )
      . '<strong>' . esc_html__( 'Order', '3d-corporate-book' ) . '</strong> #' . (int) $order . '<br><strong>' . esc_html__( 'Date', '3d-corporate-book' ) . '</strong> ' . esc_html( get_the_date( '', $order ) )
      . ( $slip ? '' : '<br><strong>' . esc_html__( 'Payment', '3d-corporate-book' ) . '</strong> ' . esc_html( 'cod' === get_post_meta( $order, '_cb_payment', true ) ? __( 'Cash on delivery', '3d-corporate-book' ) : __( 'Paid online', '3d-corporate-book' ) ) );
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title . ' #' . $order ) . '</title><style>'
      . 'body{font:14px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1d2340;max-width:760px;margin:30px auto;padding:0 20px}h1{font-family:Georgia,serif;color:#0b1f5c;margin:0}header{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid #0b1f5c;padding-bottom:14px;margin-bottom:18px}.cols{display:flex;justify-content:space-between;gap:20px;margin-bottom:18px}table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid #e3e6ef;text-align:left}.n{text-align:right}.t td{font-weight:700;font-size:16px;border-top:2px solid #0b1f5c}.gift{border:1px dashed #c9a227;padding:10px 14px;border-radius:8px;margin:16px 0}footer{color:#5a6280;font-size:12px;margin-top:24px}button{background:#0b1f5c;color:#fff;border:0;border-radius:999px;padding:10px 20px;cursor:pointer}@media print{button{display:none}body{margin:0}}'
      . '</style></head><body><p><button onclick="window.print()">' . esc_html__( 'Print / Save as PDF', '3d-corporate-book' ) . '</button></p>'
      . '<header><div><h1>' . esc_html( $title ) . '</h1><div>' . esc_html( $business ) . '<br>' . nl2br( esc_html( self::config( 'invoice_address' ) ) ) . ( '' !== self::config( 'invoice_gstin' ) && ! $slip ? '<br>GSTIN: ' . esc_html( self::config( 'invoice_gstin' ) ) : '' ) . '</div></div><div>' . $meta . '</div></header>'
      . '<div class="cols"><div><strong>' . esc_html__( 'Deliver to', '3d-corporate-book' ) . '</strong><br>' . $to . '</div>' . ( $slip ? '' : '<div><strong>' . esc_html__( 'Email', '3d-corporate-book' ) . '</strong><br>' . esc_html( $address['email'] ) . '</div>' ) . '</div>'
      . ( $gift ? '<div class="gift"><strong>' . esc_html__( 'Gift', '3d-corporate-book' ) . '</strong>' . ( '' !== trim( $gift ) ? '<br>' . nl2br( esc_html( trim( $gift ) ) ) : '' ) . '</div>' : '' )
      . '<table><thead><tr><th>' . esc_html__( 'Book', '3d-corporate-book' ) . '</th><th class="n">' . esc_html__( 'Copies', '3d-corporate-book' ) . '</th>' . ( $slip ? '' : '<th class="n">' . esc_html__( 'Price', '3d-corporate-book' ) . '</th><th class="n">' . esc_html__( 'Amount', '3d-corporate-book' ) . '</th>' ) . '</tr></thead><tbody>' . $rows . $sum . '</tbody></table>'
      . ( $slip ? '' : '<footer>' . esc_html__( 'Printed books are exempt from GST (HSN 4901). Prices include all charges.', '3d-corporate-book' ) . '</footer>' )
      . '</body></html>';
  }

  // ---- Order screen and list buttons ---------------------------------------------------------

  public function order_buttons( $post ) {
    $links = '<a class="button" target="_blank" rel="noopener" href="' . esc_url( Corporate_Book_Shop::document_url( $post->ID, 'slip' ) ) . '">' . esc_html__( 'Packing slip', '3d-corporate-book' ) . '</a>';
    if ( get_post_meta( $post->ID, '_cb_invoice', true ) ) {
      $links .= ' <a class="button" target="_blank" rel="noopener" href="' . esc_url( Corporate_Book_Shop::document_url( $post->ID, 'invoice' ) ) . '">' . esc_html__( 'Invoice', '3d-corporate-book' ) . '</a>';
    }
    if ( '' !== self::config( 'shiprocket_email' ) && in_array( $post->post_status, array( 'cb-paid', 'cb-cod' ), true ) ) {
      $sent   = get_post_meta( $post->ID, '_cb_shiprocket', true );
      $links .= '<br><br><a class="button button-secondary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=corporate_book_shiprocket&order=' . (int) $post->ID ), 'cb_shiprocket_' . $post->ID ) ) . '">' . esc_html( $sent ? __( 'Send to Shiprocket again', '3d-corporate-book' ) : __( 'Send to Shiprocket', '3d-corporate-book' ) ) . '</a>'
        . ( $sent ? '<br><small>' . esc_html__( 'Shiprocket order', '3d-corporate-book' ) . ' ' . esc_html( $sent ) . '</small>' : '' );
    }
    echo '<p class="cb-order-docs">' . $links . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
  }

  public function export_button( $which ) {
    global $typenow;
    if ( 'top' === $which && Corporate_Book_Shop::ORDER === $typenow && self::can_manage() ) {
      echo '<div class="alignleft actions"><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=corporate_book_export_orders' ), 'cb_export' ) ) . '">' . esc_html__( 'Export CSV', '3d-corporate-book' ) . '</a></div>';
    }
  }

  // ---- CSV export ----------------------------------------------------------------------------

  /**
   * One CSV row per order. Cells that start with = + - @ get a leading quote so spreadsheets do not run them as formulas.
   *
   * @param int $order Order ID.
   *
   * @return array
   */
  public static function csv_row( $order ) {
    $a      = (array) get_post_meta( $order, '_cb_address', true ) + array( 'name' => '', 'phone' => '', 'email' => '', 'line1' => '', 'line2' => '', 'landmark' => '', 'city' => '', 'state' => '', 'pin' => '' );
    $t      = (array) get_post_meta( $order, '_cb_totals', true ) + array( 'subtotal' => 0, 'discount' => 0, 'shipping' => 0, 'total' => 0 );
    $books  = array();
    $copies = 0;
    foreach ( (array) get_post_meta( $order, '_cb_items', true ) as $item ) {
      $books[] = $item['qty'] . ' x ' . $item['title'];
      $copies += (int) $item['qty'];
    }
    $status = get_post_status( $order );
    $row    = array(
      $order, get_the_date( 'Y-m-d H:i', $order ), isset( Corporate_Book_Shop::statuses()[ $status ] ) ? Corporate_Book_Shop::statuses()[ $status ] : $status, get_post_meta( $order, '_cb_invoice', true ),
      $a['name'], $a['phone'], $a['email'], trim( $a['line1'] . ', ' . $a['line2'] . ', ' . $a['landmark'], ', ' ), $a['city'], $a['state'], $a['pin'],
      implode( '; ', $books ), $copies, $t['subtotal'] / 100, $t['discount'] / 100, $t['shipping'] / 100, $t['total'] / 100,
      'cod' === get_post_meta( $order, '_cb_payment', true ) ? 'COD' : 'Razorpay', get_post_meta( $order, '_cb_courier', true ), get_post_meta( $order, '_cb_tracking', true ),
    );
    return array_map(
      function ( $cell ) {
        $cell = (string) $cell;
        return preg_match( '/^[=+\-@\t\r]/', $cell ) && ! is_numeric( $cell ) ? "'" . $cell : $cell;
      },
      $row
    );
  }

  public function export() {
    check_admin_referer( 'cb_export' );
    if ( ! self::can_manage() ) {
      wp_die( esc_html__( 'Not allowed.', '3d-corporate-book' ), '', array( 'response' => 403 ) );
    }
    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=print-orders-' . gmdate( 'Y-m-d' ) . '.csv' );
    $out = fopen( 'php://output', 'w' );
    fwrite( $out, "\xEF\xBB\xBF" ); // Excel reads UTF-8 (₹, Hindi names) only with this mark.
    fputcsv( $out, array( 'Order', 'Date', 'Status', 'Invoice', 'Name', 'Phone', 'Email', 'Address', 'City', 'State', 'PIN', 'Books', 'Copies', 'Books amount', 'Discount', 'Shipping', 'Total', 'Payment', 'Courier', 'Tracking' ) );
    $statuses = array_diff( array_keys( Corporate_Book_Shop::statuses() ), array( 'cb-pending' ) );
    foreach ( get_posts( array( 'post_type' => Corporate_Book_Shop::ORDER, 'post_status' => $statuses, 'numberposts' => 5000, 'fields' => 'ids' ) ) as $order ) {
      fputcsv( $out, self::csv_row( $order ) );
    }
    fclose( $out );
    exit;
  }

  // ---- Shiprocket ----------------------------------------------------------------------------

  private static function shiprocket_call( $path, $body, $token = '' ) {
    $response = wp_remote_post(
      self::SHIPROCKET . $path,
      array(
        'timeout' => 30,
        'headers' => array_filter( array( 'Content-Type' => 'application/json', 'Authorization' => $token ? 'Bearer ' . $token : '' ) ),
        'body'    => wp_json_encode( $body ),
      )
    );
    if ( is_wp_error( $response ) ) {
      return $response;
    }
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
      return new WP_Error( 'shiprocket', is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $code );
    }
    return $data;
  }

  /**
   * Shiprocket order fields for one of our orders.
   *
   * @param int $order Order ID.
   *
   * @return array
   */
  public static function shiprocket_order( $order ) {
    $a      = (array) get_post_meta( $order, '_cb_address', true ) + array( 'name' => '', 'phone' => '', 'email' => '', 'line1' => '', 'line2' => '', 'landmark' => '', 'city' => '', 'state' => '', 'pin' => '' );
    $t      = (array) get_post_meta( $order, '_cb_totals', true ) + array( 'subtotal' => 0, 'discount' => 0, 'shipping' => 0, 'total' => 0 );
    $items  = array();
    $copies = 0;
    foreach ( (array) get_post_meta( $order, '_cb_items', true ) as $item ) {
      $items[] = array( 'name' => $item['title'], 'sku' => 'book-' . (int) $item['id'], 'units' => (int) $item['qty'], 'selling_price' => $item['price'] / 100 );
      $copies += (int) $item['qty'];
    }
    // shortcut: one parcel size for every order (A5 books, 400 g a copy); filter corporate_book_shiprocket_order to change it.
    return apply_filters(
      'corporate_book_shiprocket_order',
      array(
        'order_id'               => 'CB' . (int) $order,
        'order_date'             => get_the_date( 'Y-m-d H:i', $order ),
        'pickup_location'        => self::config( 'shiprocket_pickup' ),
        'billing_customer_name'  => $a['name'],
        'billing_last_name'      => '',
        'billing_address'        => $a['line1'],
        'billing_address_2'      => trim( $a['line2'] . ' ' . $a['landmark'] ),
        'billing_city'           => $a['city'],
        'billing_pincode'        => $a['pin'],
        'billing_state'          => $a['state'],
        'billing_country'        => 'India',
        'billing_email'          => $a['email'],
        'billing_phone'          => $a['phone'],
        'shipping_is_billing'    => true,
        'order_items'            => $items,
        'payment_method'         => 'cod' === get_post_meta( $order, '_cb_payment', true ) ? 'COD' : 'Prepaid',
        'shipping_charges'       => $t['shipping'] / 100,
        'total_discount'         => $t['discount'] / 100,
        'sub_total'              => $t['subtotal'] / 100,
        'length'                 => 25,
        'breadth'                => 18,
        'height'                 => max( 2, 2 * $copies ),
        'weight'                 => ! empty( $t['grams'] ) ? round( $t['grams'] / 1000, 3 ) : 0.25 * max( 1, $copies ),
      ),
      $order
    );
  }

  public function shiprocket() {
    $order = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
    check_admin_referer( 'cb_shiprocket_' . $order );
    if ( ! current_user_can( 'edit_post', $order ) || Corporate_Book_Shop::ORDER !== get_post_type( $order ) ) {
      wp_die( esc_html__( 'Not allowed.', '3d-corporate-book' ), '', array( 'response' => 403 ) );
    }
    $token = get_transient( 'cb_shiprocket_token' );
    if ( ! $token ) {
      $login = self::shiprocket_call( 'auth/login', array( 'email' => self::config( 'shiprocket_email' ), 'password' => self::config( 'shiprocket_password' ) ) );
      $token = ! is_wp_error( $login ) && ! empty( $login['token'] ) ? (string) $login['token'] : '';
      if ( $token ) {
        set_transient( 'cb_shiprocket_token', $token, 9 * DAY_IN_SECONDS ); // tokens last 10 days
      }
    }
    $result = $token ? self::shiprocket_call( 'orders/create/adhoc', self::shiprocket_order( $order ), $token ) : new WP_Error( 'shiprocket', __( 'Shiprocket refused the API email or password.', '3d-corporate-book' ) );
    if ( is_wp_error( $result ) || empty( $result['order_id'] ) ) {
      $message = is_wp_error( $result ) ? $result->get_error_message() : __( 'No order number came back.', '3d-corporate-book' );
      set_transient( 'cb_tools_notice_' . get_current_user_id(), array( 'error', __( 'Shiprocket:', '3d-corporate-book' ) . ' ' . $message ), 60 );
    } else {
      update_post_meta( $order, '_cb_shiprocket', sanitize_text_field( (string) $result['order_id'] ) );
      update_post_meta( $order, '_cb_courier', 'Shiprocket' );
      if ( ! empty( $result['awb_code'] ) ) {
        update_post_meta( $order, '_cb_tracking', sanitize_text_field( (string) $result['awb_code'] ) );
        update_post_meta( $order, '_cb_tracking_url', 'https://shiprocket.co/tracking/' . rawurlencode( (string) $result['awb_code'] ) );
      }
      set_transient( 'cb_tools_notice_' . get_current_user_id(), array( 'success', __( 'Sent to Shiprocket. Choose the courier and print the label in Shiprocket, then mark the order Shipped here.', '3d-corporate-book' ) ), 60 );
    }
    wp_safe_redirect( admin_url( 'post.php?post=' . $order . '&action=edit' ) );
    exit;
  }

  public function notice() {
    $notice = get_transient( 'cb_tools_notice_' . get_current_user_id() );
    if ( $notice ) {
      delete_transient( 'cb_tools_notice_' . get_current_user_id() );
      echo '<div class="notice notice-' . esc_attr( $notice[0] ) . ' is-dismissible"><p>' . esc_html( $notice[1] ) . '</p></div>';
    }
  }

  // ---- Sales dashboard -----------------------------------------------------------------------

  public function menu() {
    add_submenu_page( 'edit.php?post_type=corporate_book', __( 'Sales', '3d-corporate-book' ), __( 'Sales', '3d-corporate-book' ), 'edit_others_posts', 'corporate_book-sales', array( $this, 'dashboard' ) );
  }

  /**
   * Sales numbers for the last 12 months.
   *
   * @return array
   */
  public static function stats() {
    $months = array();
    for ( $i = 11; $i >= 0; $i-- ) {
      $months[ gmdate( 'Y-m', strtotime( "first day of -$i month" ) ) ] = array( 'print' => 0, 'orders' => 0, 'subs' => 0 );
    }
    $books   = array();
    $revenue = 0;
    $count   = 0;
    $copies  = 0;
    $profit  = 0;
    $costed  = 0;
    foreach ( get_posts( array( 'post_type' => Corporate_Book_Shop::ORDER, 'post_status' => array( 'cb-paid', 'cb-cod', 'cb-shipped', 'cb-delivered' ), 'numberposts' => 5000 ) ) as $post ) {
      $t     = (array) get_post_meta( $post->ID, '_cb_totals', true ) + array( 'total' => 0 );
      $month = substr( $post->post_date, 0, 7 );
      if ( isset( $months[ $month ] ) ) {
        $months[ $month ]['print']  += $t['total'];
        $months[ $month ]['orders'] += 1;
      }
      $revenue += $t['total'];
      $count++;
      if ( isset( $t['cost'] ) ) {
        $profit += $t['total'] - (int) $t['cost'];
        $costed++;
      }
      foreach ( (array) get_post_meta( $post->ID, '_cb_items', true ) as $item ) {
        $books[ $item['title'] ] = ( isset( $books[ $item['title'] ] ) ? $books[ $item['title'] ] : 0 ) + (int) $item['qty'];
        $copies                 += (int) $item['qty'];
      }
    }
    arsort( $books );
    $subs = 0;
    foreach ( get_users( array( 'meta_key' => Corporate_Book_Account::ORDERS, 'fields' => 'ID', 'number' => 5000 ) ) as $user_id ) {
      foreach ( (array) get_user_meta( $user_id, Corporate_Book_Account::ORDERS, true ) as $order ) {
        if ( ! empty( $order['paid'] ) && empty( $order['refunded'] ) ) {
          $month = gmdate( 'Y-m', (int) $order['time'] );
          if ( isset( $months[ $month ] ) ) {
            $months[ $month ]['subs'] += (int) $order['amount'];
          }
          $subs += (int) $order['amount'];
        }
      }
    }
    $active = count( get_users( array( 'meta_key' => Corporate_Book_Account::UNTIL, 'meta_value' => time(), 'meta_compare' => '>', 'meta_type' => 'NUMERIC', 'fields' => 'ID', 'number' => 100000 ) ) );
    return array(
      'months'  => $months,
      'books'   => array_slice( $books, 0, 10, true ),
      'print'   => $revenue,
      'orders'  => $count,
      'copies'  => $copies,
      'profit'  => $profit,
      'costed'  => $costed,
      'subs'    => $subs,
      'active'  => $active,
      'readers' => count( get_users( array( 'role' => 'cb_reader', 'fields' => 'ID', 'number' => 100000 ) ) ),
    );
  }

  public function dashboard() {
    $s     = self::stats();
    $money = function ( $paise ) {
      return esc_html( Corporate_Book_Shop::money( $paise ) );
    };
    $top   = max( 1, max( array_map( function ( $m ) {
      return $m['print'] + $m['subs'];
    }, $s['months'] ) ) );
    $bars  = '';
    foreach ( $s['months'] as $month => $m ) {
      $bars .= '<tr><td>' . esc_html( date_i18n( 'M Y', strtotime( $month . '-01' ) ) ) . '</td><td><div class="cb-bar"><span class="cb-bar-print" style="width:' . esc_attr( round( 100 * $m['print'] / $top, 1 ) ) . '%"></span><span class="cb-bar-subs" style="width:' . esc_attr( round( 100 * $m['subs'] / $top, 1 ) ) . '%"></span></div></td><td>' . $money( $m['print'] ) . '</td><td>' . (int) $m['orders'] . '</td><td>' . $money( $m['subs'] ) . '</td></tr>';
    }
    $best = '';
    foreach ( $s['books'] as $title => $qty ) {
      $best .= '<tr><td>' . esc_html( $title ) . '</td><td>' . (int) $qty . '</td></tr>';
    }
    $card = function ( $label, $value ) {
      return '<div class="cb-stat"><div class="cb-stat-value">' . $value . '</div><div class="cb-stat-label">' . esc_html( $label ) . '</div></div>';
    };
    echo '<div class="wrap cb-sales"><h1>' . esc_html__( 'Sales', '3d-corporate-book' ) . '</h1><style>'
      . '.cb-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin:16px 0 24px}.cb-stat{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px}.cb-stat-value{font-size:24px;font-weight:700;color:#0b1f5c}.cb-stat-label{color:#50575e}.cb-bar{display:flex;height:14px;background:#f0f0f1;border-radius:7px;overflow:hidden;min-width:200px}.cb-bar-print{background:#0b1f5c}.cb-bar-subs{background:#c9a227}.cb-sales table{max-width:900px}.cb-key span{display:inline-block;width:12px;height:12px;border-radius:3px;margin:0 4px 0 12px;vertical-align:middle}'
      . '</style><div class="cb-stats">'
      . $card( __( 'Print sales', '3d-corporate-book' ), $money( $s['print'] ) )
      . $card( __( 'Print orders', '3d-corporate-book' ), (int) $s['orders'] )
      . $card( __( 'Copies sold', '3d-corporate-book' ), (int) $s['copies'] )
      . $card( sprintf( /* translators: %d: number of orders */ _n( 'Estimated print profit (%d order)', 'Estimated print profit (%d orders)', $s['costed'], '3d-corporate-book' ), $s['costed'] ), $money( $s['profit'] ) )
      . $card( __( 'Subscription sales', '3d-corporate-book' ), $money( $s['subs'] ) )
      . $card( __( 'Active subscribers', '3d-corporate-book' ), (int) $s['active'] )
      . $card( __( 'Reader accounts', '3d-corporate-book' ), (int) $s['readers'] )
      . '</div><h2>' . esc_html__( 'Last 12 months', '3d-corporate-book' ) . '</h2><p class="cb-key"><span style="background:#0b1f5c"></span>' . esc_html__( 'Print', '3d-corporate-book' ) . '<span style="background:#c9a227"></span>' . esc_html__( 'Subscriptions', '3d-corporate-book' ) . '</p>'
      . '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Month', '3d-corporate-book' ) . '</th><th></th><th>' . esc_html__( 'Print sales', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Orders', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Subscriptions', '3d-corporate-book' ) . '</th></tr></thead><tbody>' . $bars . '</tbody></table>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
      . '<h2>' . esc_html__( 'Best-selling print books', '3d-corporate-book' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Book', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Copies', '3d-corporate-book' ) . '</th></tr></thead><tbody>' . ( $best ? $best : '<tr><td colspan="2">' . esc_html__( 'No print sales yet.', '3d-corporate-book' ) . '</td></tr>' ) . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
    do_action( 'corporate_book_sales_dashboard' );
    echo '</div>';
  }
}

new Corporate_Book_Tools();
