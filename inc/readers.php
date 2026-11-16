<?php
/**
 * Corporate Books > Readers: every reader account with contact details, subscription, spending,
 * print orders and last sign-in, with totals, filters, search and CSV export.
 *
 * @package corporate book
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

class Corporate_Book_Readers {

  const LAST_LOGIN = 'cb_last_login';
  const PER_PAGE   = 25;

  public function __construct() {
    // Every sign-in sets the login cookie (password, sign-up, Google, password reset), so count it there.
    add_action( 'set_logged_in_cookie', array( $this, 'remember_login' ), 10, 4 );
    add_action( 'admin_menu', array( $this, 'menu' ), 20 );
    add_action( 'admin_post_corporate_book_export_readers', array( $this, 'export' ) );
    add_action( 'admin_head', array( $this, 'order_screen_css' ) );
  }

  public function remember_login( $cookie, $expire, $expiration, $user_id ) {
    update_user_meta( (int) $user_id, self::LAST_LOGIN, time() );
  }

  public function menu() {
    add_submenu_page( 'edit.php?post_type=corporate_book', __( 'Readers', '3d-corporate-book' ), __( 'Readers', '3d-corporate-book' ), 'list_users', 'corporate_book-readers', array( $this, 'page' ) );
  }

  /**
   * Reader accounts: everyone who cannot write posts (staff are left out).
   *
   * @param array $args Extra WP_User_Query arguments.
   *
   * @return WP_User_Query
   */
  public static function query( $args = array() ) {
    return new WP_User_Query(
      $args + array(
        'role__not_in' => array( 'administrator', 'editor', 'author', 'contributor' ),
        'orderby'      => 'registered',
        'order'        => 'DESC',
      )
    );
  }

  /**
   * One reader's numbers.
   *
   * @param WP_User $user Reader.
   *
   * @return array
   */
  public static function row( $user ) {
    $subs  = 0;
    $plans = 0;
    foreach ( (array) get_user_meta( $user->ID, Corporate_Book_Account::ORDERS, true ) as $order ) {
      if ( ! empty( $order['paid'] ) && empty( $order['refunded'] ) ) {
        $subs += (int) $order['amount'];
        $plans++;
      }
    }
    $print  = 0;
    $orders = 0;
    foreach ( get_posts( array( 'post_type' => Corporate_Book_Shop::ORDER, 'author' => $user->ID, 'post_status' => array( 'cb-paid', 'cb-cod', 'cb-shipped', 'cb-delivered' ), 'numberposts' => 200, 'fields' => 'ids' ) ) as $id ) {
      $t      = (array) get_post_meta( $id, '_cb_totals', true ) + array( 'total' => 0 );
      $print += (int) $t['total'];
      $orders++;
    }
    $until   = Corporate_Book_Account::until( $user->ID );
    $address = get_user_meta( $user->ID, Corporate_Book_Shop::ADDRESS, true );
    return array(
      'name'    => trim( $user->first_name . ' ' . $user->last_name ) ? trim( $user->first_name . ' ' . $user->last_name ) : $user->display_name,
      'email'   => $user->user_email,
      'phone'   => (string) get_user_meta( $user->ID, 'cb_phone', true ),
      'company' => (string) get_user_meta( $user->ID, 'cb_company', true ),
      'city'    => is_array( $address ) && ! empty( $address['city'] ) ? $address['city'] . ( ! empty( $address['state'] ) ? ', ' . $address['state'] : '' ) : '',
      'joined'  => strtotime( $user->user_registered ),
      'login'   => (int) get_user_meta( $user->ID, self::LAST_LOGIN, true ),
      'until'   => $until,
      'status'  => $until > time() ? 'active' : ( $until ? 'expired' : 'free' ),
      'plans'   => $plans,
      'subs'    => $subs,
      'orders'  => $orders,
      'print'   => $print,
      'saved'   => count( Corporate_Book_Reader::wishlist( $user->ID ) ),
    );
  }

  private static function status_args( $status ) {
    if ( 'active' === $status ) {
      return array( 'meta_query' => array( array( 'key' => Corporate_Book_Account::UNTIL, 'value' => time(), 'compare' => '>', 'type' => 'NUMERIC' ) ) );
    }
    if ( 'expired' === $status ) {
      return array( 'meta_query' => array( array( 'key' => Corporate_Book_Account::UNTIL, 'value' => array( 1, time() ), 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ) ) );
    }
    if ( 'free' === $status ) {
      return array( 'meta_query' => array( array( 'key' => Corporate_Book_Account::UNTIL, 'compare' => 'NOT EXISTS' ) ) );
    }
    return array();
  }

  private static function date( $time ) {
    return $time ? date_i18n( get_option( 'date_format' ), $time ) : '—';
  }

  public function page() {
    if ( ! current_user_can( 'list_users' ) ) {
      wp_die( esc_html__( 'Not allowed.', '3d-corporate-book' ) );
    }
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
    $status = isset( $_GET['cb_status'] ) ? sanitize_key( $_GET['cb_status'] ) : 'all';
    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
    // phpcs:enable
    $count = function ( $s ) {
      return (int) self::query( self::status_args( $s ) + array( 'fields' => 'ID', 'number' => 1, 'count_total' => true ) )->get_total();
    };
    $total   = $count( 'all' );
    $active  = $count( 'active' );
    $expired = $count( 'expired' );
    $new     = (int) self::query( array( 'fields' => 'ID', 'number' => 1, 'count_total' => true, 'date_query' => array( array( 'after' => '30 days ago' ) ) ) )->get_total();
    $recent  = (int) self::query( array( 'fields' => 'ID', 'number' => 1, 'count_total' => true, 'meta_query' => array( array( 'key' => self::LAST_LOGIN, 'value' => time() - 7 * DAY_IN_SECONDS, 'compare' => '>', 'type' => 'NUMERIC' ) ) ) )->get_total();
    $args    = self::status_args( $status ) + array( 'number' => self::PER_PAGE, 'paged' => $paged, 'count_total' => true );
    if ( '' !== $search ) {
      $args['search']         = '*' . $search . '*';
      $args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
    }
    $q     = self::query( $args );
    $base  = admin_url( 'edit.php?post_type=corporate_book&page=corporate_book-readers' );
    $money = function ( $paise ) {
      return esc_html( Corporate_Book_Shop::money( $paise ) );
    };
    $rows = '';
    foreach ( $q->get_results() as $user ) {
      $r      = self::row( $user );
      $label  = array( 'active' => __( 'Active', '3d-corporate-book' ), 'expired' => __( 'Expired', '3d-corporate-book' ), 'free' => __( 'Free reader', '3d-corporate-book' ) );
      $letter = strtoupper( substr( preg_replace( '/[^A-Za-z0-9]/', '', $r['name'] ), 0, 1 ) );
      $rows  .= '<tr>'
        . '<td><div class="cbr-who"><span class="cbr-avatar">' . esc_html( $letter ? $letter : '?' ) . '</span><div><strong>' . esc_html( $r['name'] ) . '</strong><br><a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a>'
        . ( $r['phone'] ? '<br><a href="tel:+91' . esc_attr( $r['phone'] ) . '">+91 ' . esc_html( $r['phone'] ) . '</a>' : '' ) . '</div></div></td>'
        . '<td>' . esc_html( $r['company'] ? $r['company'] : '—' ) . ( $r['city'] ? '<br><small>' . esc_html( $r['city'] ) . '</small>' : '' ) . '</td>'
        . '<td><span class="cbr-badge cbr-' . esc_attr( $r['status'] ) . '">' . esc_html( $label[ $r['status'] ] ) . '</span>' . ( $r['until'] ? '<br><small>' . esc_html( ( 'active' === $r['status'] ? __( 'until', '3d-corporate-book' ) : __( 'ended', '3d-corporate-book' ) ) . ' ' . self::date( $r['until'] ) ) . '</small>' : '' ) . '</td>'
        . '<td>' . $money( $r['subs'] ) . '<br><small>' . esc_html( sprintf( /* translators: %d: number */ _n( '%d plan', '%d plans', $r['plans'], '3d-corporate-book' ), $r['plans'] ) ) . '</small></td>'
        . '<td>' . $money( $r['print'] ) . '<br><small><a href="' . esc_url( admin_url( 'edit.php?post_type=cb_order&author=' . $user->ID ) ) . '">' . esc_html( sprintf( /* translators: %d: number */ _n( '%d order', '%d orders', $r['orders'], '3d-corporate-book' ), $r['orders'] ) ) . '</a></small></td>'
        . '<td>' . (int) $r['saved'] . '</td>'
        . '<td>' . esc_html( self::date( $r['joined'] ) ) . '</td>'
        . '<td>' . esc_html( $r['login'] ? human_time_diff( $r['login'] ) . ' ' . __( 'ago', '3d-corporate-book' ) : '—' ) . '</td>'
        . '<td><a class="button button-small" href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html__( 'Edit', '3d-corporate-book' ) . '</a></td>'
        . '</tr>';
    }
    $tab = function ( $key, $label, $n ) use ( $status, $base ) {
      return '<a class="cbr-tab' . ( $key === $status ? ' cbr-current' : '' ) . '" href="' . esc_url( add_query_arg( 'cb_status', $key, $base ) ) . '">' . esc_html( $label ) . ' <span>' . (int) $n . '</span></a>';
    };
    $card = function ( $label, $value, $hint = '' ) {
      return '<div class="cbr-card"><div class="cbr-value">' . $value . '</div><div class="cbr-label">' . esc_html( $label ) . '</div>' . ( $hint ? '<div class="cbr-hint">' . esc_html( $hint ) . '</div>' : '' ) . '</div>';
    };
    $pages = max( 1, (int) ceil( $q->get_total() / self::PER_PAGE ) );
    $nav   = $pages > 1 ? paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) : '';
    echo '<div class="wrap cbr"><style>' . self::css() . '</style>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed CSS.
      . '<div class="cbr-top"><div><h1>' . esc_html__( 'Readers', '3d-corporate-book' ) . '</h1><p class="cbr-sub">' . esc_html__( 'Everyone who signed up to read, with their subscription and orders.', '3d-corporate-book' ) . '</p></div>'
      . '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=corporate_book_export_readers' ), 'cb_export_readers' ) ) . '">' . esc_html__( 'Export CSV', '3d-corporate-book' ) . '</a></div>'
      . '<div class="cbr-cards">'
      . $card( __( 'Reader accounts', '3d-corporate-book' ), (int) $total )
      . $card( __( 'Active subscribers', '3d-corporate-book' ), (int) $active, $total ? round( 100 * $active / $total ) . '% ' . __( 'of readers', '3d-corporate-book' ) : '' )
      . $card( __( 'Expired subscriptions', '3d-corporate-book' ), (int) $expired )
      . $card( __( 'New in the last 30 days', '3d-corporate-book' ), (int) $new )
      . $card( __( 'Signed in this week', '3d-corporate-book' ), (int) $recent )
      . '</div>'
      . '<div class="cbr-bar"><nav class="cbr-tabs">' . $tab( 'all', __( 'All', '3d-corporate-book' ), $total ) . $tab( 'active', __( 'Active', '3d-corporate-book' ), $active ) . $tab( 'expired', __( 'Expired', '3d-corporate-book' ), $expired ) . $tab( 'free', __( 'Never subscribed', '3d-corporate-book' ), max( 0, $total - $active - $expired ) ) . '</nav>'
      . '<form method="get"><input type="hidden" name="post_type" value="corporate_book"/><input type="hidden" name="page" value="corporate_book-readers"/><input type="hidden" name="cb_status" value="' . esc_attr( $status ) . '"/><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search name or email', '3d-corporate-book' ) . '"/> <button class="button">' . esc_html__( 'Search', '3d-corporate-book' ) . '</button></form></div>'
      . '<div class="cbr-table"><table class="widefat"><thead><tr><th>' . esc_html__( 'Reader', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Company / city', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Subscription', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Subscriptions paid', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Print orders', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Saved', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Joined', '3d-corporate-book' ) . '</th><th>' . esc_html__( 'Last sign-in', '3d-corporate-book' ) . '</th><th></th></tr></thead><tbody>'
      . ( $rows ? $rows : '<tr><td colspan="9" class="cbr-empty">' . esc_html__( 'No readers match.', '3d-corporate-book' ) . '</td></tr>' ) . '</tbody></table></div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
      . ( $nav ? '<div class="tablenav"><div class="tablenav-pages">' . $nav . '</div></div>' : '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links escapes.
  }

  /**
   * Print Orders list and order screen: coloured status badges and roomier rows.
   */
  public function order_screen_css() {
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->id, array( 'edit-cb_order', 'cb_order', 'corporate_book_page_corporate_book-sales' ), true ) ) {
      return;
    }
    echo '<style>.cb-order-status{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;background:#eef0f6;color:#13235c}.cb-status-cb-paid,.cb-status-cb-cod{background:#e7efff;color:#1f4fd1}.cb-status-cb-shipped{background:#fff4e0;color:#9a5b00}.cb-status-cb-delivered{background:#e7f6ee;color:#16794a}.cb-status-cb-cancelled,.cb-status-cb-refunded{background:#fdecec;color:#b42318}.post-type-cb_order .wp-list-table td{padding-top:12px;padding-bottom:12px}.post-type-cb_order .wp-list-table .column-title strong a{font-size:14px}#cb_order_details .widefat{border-radius:8px}.cb-sales .cb-stat{border-radius:12px;border-color:#e2e4ea}.cb-sales .cb-stat-value{font-size:28px;color:#13235c}.cb-sales table.widefat{border-radius:12px;overflow:hidden;border-color:#e2e4ea}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed CSS.
  }

  public static function css() {
    return '.cbr{max-width:1400px}.cbr-top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin:10px 0 18px}.cbr-top h1{font-size:26px;font-weight:700;margin:0;padding:0}.cbr-sub{margin:4px 0 0;color:#646970}'
      . '.cbr-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:20px}.cbr-card{background:#fff;border:1px solid #e2e4ea;border-radius:12px;padding:18px 20px;box-shadow:0 1px 2px rgba(0,0,0,.03)}.cbr-value{font-size:28px;font-weight:700;color:#13235c;line-height:1.1}.cbr-label{color:#50575e;margin-top:6px;font-weight:500}.cbr-hint{color:#16794a;font-size:12px;margin-top:4px}'
      . '.cbr-bar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}.cbr-tabs{display:flex;gap:6px;flex-wrap:wrap}.cbr-tab{padding:7px 14px;border-radius:999px;background:#fff;border:1px solid #dcdcde;text-decoration:none;color:#2c3338;font-weight:500}.cbr-tab span{color:#8c8f94;margin-left:4px}.cbr-tab.cbr-current{background:#13235c;border-color:#13235c;color:#fff}.cbr-tab.cbr-current span{color:#c9d2f2}.cbr-bar input[type=search]{min-width:240px;border-radius:8px}'
      . '.cbr-table{background:#fff;border:1px solid #e2e4ea;border-radius:12px;overflow:hidden}.cbr-table table{border:0}.cbr-table th{background:#f6f7fb;font-weight:600;color:#50575e;padding:12px}.cbr-table td{padding:14px 12px;vertical-align:top;border-top:1px solid #f0f0f1}.cbr-table small{color:#646970}'
      . '.cbr-who{display:flex;gap:12px;align-items:flex-start}.cbr-avatar{flex:none;width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:#e8ecfa;color:#13235c;font-weight:700}'
      . '.cbr-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600}.cbr-active{background:#e7f6ee;color:#16794a}.cbr-expired{background:#fff4e0;color:#9a5b00}.cbr-free{background:#eef0f6;color:#50575e}.cbr-empty{text-align:center;color:#646970;padding:30px!important}';
  }

  public function export() {
    check_admin_referer( 'cb_export_readers' );
    if ( ! current_user_can( 'list_users' ) ) {
      wp_die( esc_html__( 'Not allowed.', '3d-corporate-book' ), '', array( 'response' => 403 ) );
    }
    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=readers-' . gmdate( 'Y-m-d' ) . '.csv' );
    $out = fopen( 'php://output', 'w' );
    fwrite( $out, "\xEF\xBB\xBF" );
    fputcsv( $out, array( 'Name', 'Email', 'Phone', 'Company', 'City', 'Joined', 'Last sign-in', 'Subscription', 'Until', 'Plans paid', 'Subscriptions amount', 'Print orders', 'Print amount', 'Saved books' ) );
    foreach ( self::query( array( 'number' => 10000 ) )->get_results() as $user ) {
      $r   = self::row( $user );
      $row = array( $r['name'], $r['email'], $r['phone'], $r['company'], $r['city'], gmdate( 'Y-m-d', $r['joined'] ), $r['login'] ? gmdate( 'Y-m-d H:i', $r['login'] ) : '', $r['status'], $r['until'] ? gmdate( 'Y-m-d', $r['until'] ) : '', $r['plans'], $r['subs'] / 100, $r['orders'], $r['print'] / 100, $r['saved'] );
      // Cells starting with = + - @ would run as spreadsheet formulas.
      fputcsv( $out, array_map( function ( $c ) {
        $c = (string) $c;
        return preg_match( '/^[=+\-@]/', $c ) && ! is_numeric( $c ) ? "'" . $c : $c;
      }, $row ) );
    }
    fclose( $out );
    exit;
  }
}

new Corporate_Book_Readers();
