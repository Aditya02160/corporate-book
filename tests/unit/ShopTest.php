<?php
/**
 * Print shop maths: PIN codes, courier zones from Noida, parcel weight slabs, cart quotes, costs, delivery addresses.
 */
class ShopTest extends CB_Unit_Test {
  protected function setUp(): void {
    parent::setUp();
    Corporate_Book::get_instance()->defaults += array(
      'print_enable'      => array( 'std' => 'true', 'choices' => array( 'true' => 'On', 'false' => 'Off' ) ),
      'print_zones'       => array( 'std' => "ncr | 40 | 30 | 1-2\nmetro | 65 | 50 | 2-4\nindia | 75 | 60 | 3-6\nremote | 95 | 80 | 5-9", 'type' => 'textarea' ),
      'print_ship_mode'   => array( 'std' => 'zone', 'choices' => array( 'zone' => 'Zone', 'flat' => 'Flat', 'free' => 'Free' ) ),
      'print_flat_rate'   => array( 'std' => '55', 'type' => 'number' ),
      'print_copy_weight' => array( 'std' => '200', 'type' => 'number' ),
      'print_pack_weight' => array( 'std' => '50', 'type' => 'number' ),
      'print_unit_cost'   => array( 'std' => '250', 'type' => 'number' ),
      'print_pack_cost'   => array( 'std' => '10', 'type' => 'number' ),
      'print_coupons'     => array( 'std' => '', 'type' => 'textarea' ),
      'print_free_above'  => array( 'std' => '', 'type' => 'text' ),
    );
  }

  private function book( $id, $price, $unused = '', $stock = '', $sell = 'true', $status = 'publish', $extra = array() ) {
    $GLOBALS['wp']['post_meta'][ $id ]['_corporate_book_data'] = array( 'print_sell' => $sell, 'print_price' => $price, 'print_stock' => $stock ) + $extra;
    $GLOBALS['wp']['posts'][ $id ] = array( 'title' => "Book $id", 'status' => $status );
    return $id;
  }

  #[PHPUnit\Framework\Attributes\DataProvider('pins')]
  public function test_pin_codes_are_validated( $pin, $valid ) {
    $this->assertSame( $valid, Corporate_Book_Shop::valid_pin( $pin ) );
  }

  public static function pins() {
    return array(
      'Noida'           => array( '201301', true ),
      'with space'      => array( '201 301', true ),
      'Bengaluru'       => array( '560001', true ),
      'starts with 0'   => array( '012345', false ),
      'five digits'     => array( '20130', false ),
      'letters'         => array( '2013O1', false ),
      'unused region 35'=> array( '350001', false ),
    );
  }

  #[PHPUnit\Framework\Attributes\DataProvider('zone_pins')]
  public function test_courier_zone_from_the_pin_code( $pin, $zone ) {
    $this->assertSame( $zone, Corporate_Book_Shop::zone( $pin ) );
  }

  public static function zone_pins() {
    return array(
      'Noida'      => array( '201301', 'ncr' ),
      'Ghaziabad'  => array( '201001', 'ncr' ),
      'Delhi'      => array( '110001', 'ncr' ),
      'Gurugram'   => array( '122001', 'ncr' ),
      'Faridabad'  => array( '121001', 'ncr' ),
      'Mumbai'     => array( '400001', 'metro' ),
      'Bengaluru'  => array( '560001', 'metro' ),
      'Kolkata'    => array( '700001', 'metro' ),
      'Pune'       => array( '411001', 'metro' ),
      'Lucknow'    => array( '226001', 'india' ),
      'Jaipur'     => array( '302001', 'india' ),
      'Guwahati'   => array( '781001', 'remote' ),
      'Srinagar'   => array( '190001', 'remote' ),
      'Port Blair' => array( '744101', 'remote' ),
      'Army post'  => array( '900001', 'remote' ),
      'not a PIN'  => array( '012345', null ),
    );
  }

  public function test_one_copy_in_a_mailer_is_one_500_g_slab() {
    $this->book( 1, '499' );
    $q = Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 1 ) ), '560001' );
    $this->assertSame( 250, $q['grams'], '200 g copy + 50 g mailer' );
    $this->assertSame( 6500, $q['shipping'], 'metro, first 500 g' );
    $this->assertSame( 25000 + 1000 + 6500, $q['cost'], 'cost: 250 copy + 10 box + 65 courier' );
  }

  public function test_quote_adds_book_prices_and_courier_rate_per_500_g() {
    $this->book( 1, '499' );
    $this->book( 2, '250.50' );
    $items = array( array( 'id' => 1, 'qty' => 2 ), array( 'id' => 2, 'qty' => 1 ) );
    $local = Corporate_Book_Shop::quote( $items, '201301' );
    $this->assertSame( array(), $local['errors'] );
    $this->assertSame( 124850, $local['subtotal'], 'paise: 2 x 499 + 250.50' );
    $this->assertSame( 650, $local['grams'], '3 x 200 g + 50 g box' );
    $this->assertSame( 7000, $local['shipping'], 'Delhi NCR: 40 for the first 500 g + 30 for the next' );
    $this->assertSame( 131850, $local['total'] );
    $this->assertSame( 3 * 25000 + 1000 + 7000, $local['cost'], 'three copies, the box and the courier' );
    $this->assertSame( 11500, Corporate_Book_Shop::quote( $items, '560001' )['shipping'], 'metro: 65 + 50' );
    $this->assertSame( 13500, Corporate_Book_Shop::quote( $items, '226001' )['shipping'], 'rest of India: 75 + 60' );
    $this->assertSame( 17500, Corporate_Book_Shop::quote( $items, '781001' )['shipping'], 'north-east: 95 + 80' );
  }

  public function test_a_book_can_have_its_own_weight_and_cost() {
    $this->book( 1, '499', '', '', 'true', 'publish', array( 'print_weight' => '600', 'print_cost' => '300' ) );
    $q = Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 1 ) ), '201301' );
    $this->assertSame( 650, $q['grams'] );
    $this->assertSame( 7000, $q['shipping'], '650 g is two slabs' );
    $this->assertSame( 30000 + 1000 + 7000, $q['cost'] );
  }

  public function test_flat_and_free_shipping_modes() {
    $this->book( 1, '499' );
    $items = array( array( 'id' => 1, 'qty' => 3 ) );
    $this->settings( array( 'print_ship_mode' => 'flat' ) );
    $this->assertSame( 11000, Corporate_Book_Shop::quote( $items, '781001' )['shipping'], 'flat 55 x 2 slabs, anywhere' );
    $this->settings( array( 'print_ship_mode' => 'free' ) );
    $free = Corporate_Book_Shop::quote( $items, '560001' );
    $this->assertSame( 0, $free['shipping'] );
    $this->assertSame( 11500, $free['courier'], 'the seller still pays the courier' );
    $this->assertSame( 3 * 25000 + 1000 + 11500, $free['cost'] );
  }

  public function test_quote_without_pin_gives_book_prices_only() {
    $this->book( 1, '499' );
    $q = Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 1 ) ), '' );
    $this->assertSame( 49900, $q['subtotal'] );
    $this->assertNull( $q['shipping'] );
    $this->assertNull( $q['cost'] );
  }

  public function test_free_shipping_above_an_amount() {
    $this->settings( array( 'print_free_above' => '1000' ) );
    $this->book( 1, '499' );
    $this->assertSame( 0, Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 3 ) ), '560001' )['shipping'] );
    $this->assertSame( 6500, Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 2 ) ), '560001' )['shipping'], '998 is below 1000' );
  }

  public function test_quote_refuses_books_not_for_sale_and_limits_quantity_and_stock() {
    $this->book( 1, '499', '', '2' );
    $this->book( 2, '300', '', '', 'false' );
    $this->book( 3, '300', '', '', 'true', 'draft' );
    $this->book( 4, '0' );
    $q = Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 5 ), array( 'id' => 2, 'qty' => 1 ), array( 'id' => 3, 'qty' => 1 ), array( 'id' => 4, 'qty' => 1 ), array( 'id' => 99, 'qty' => 1 ) ), '201301' );
    $this->assertCount( 1, $q['lines'] );
    $this->assertSame( 2, $q['lines'][0]['qty'], 'only 2 in stock' );
    $this->assertNotEmpty( $q['errors'] );
    $q = Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 0 ), array( 'id' => 1, 'qty' => '-3' ) ), '201301' );
    $this->assertSame( 2, $q['lines'][0]['qty'], 'each line at least 1 copy; lines of the same book are merged' );
    $this->book( 5, '100' );
    $this->assertSame( 10, Corporate_Book_Shop::quote( array( array( 'id' => 5, 'qty' => 50 ) ), '' )['lines'][0]['qty'], 'at most 10 copies' );
    $this->book( 6, '100', '', '0' );
    $this->assertSame( array(), Corporate_Book_Shop::quote( array( array( 'id' => 6, 'qty' => 1 ) ), '' )['lines'], 'sold out' );
  }

  public function test_delivery_estimate_comes_from_the_zone() {
    $this->book( 1, '499' );
    $this->assertSame( '1-2', Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 1 ) ), '201301' )['days'] );
    $this->assertSame( '2-4', Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 1 ) ), '400001' )['days'], 'Mumbai' );
    $this->settings( array( 'print_zones' => "ncr | 30 | 20\nbad line" ) );
    $q = Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 1 ) ), '201301' );
    $this->assertSame( '', $q['days'], 'a rate without days gives no estimate' );
    $this->assertSame( 3000, $q['shipping'], 'edited rate is used' );
    $this->assertSame( 6500, Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 1 ) ), '560001' )['shipping'], 'zones missing from the text keep their default rate' );
  }

  public function test_coupons_take_percent_or_amount_off_the_books() {
    $this->settings( array( 'print_coupons' => "WELCOME10 | 10%\nFLAT100 | 100\nOLD | 50% | 2020-01-01\nBIG | 5000" ) );
    $this->book( 1, '499' );
    $items = array( array( 'id' => 1, 'qty' => 2 ) );
    $q     = Corporate_Book_Shop::quote( $items, '201301', 'welcome10' );
    $this->assertSame( 9980, $q['discount'] );
    $this->assertSame( 'WELCOME10', $q['coupon'] );
    $this->assertSame( 99800 - 9980 + 4000, $q['total'], '2 copies = 450 g = one slab of 40' );
    $this->assertSame( 10000, Corporate_Book_Shop::quote( $items, '201301', 'FLAT100' )['discount'] );
    $this->assertSame( 99800, Corporate_Book_Shop::quote( $items, '201301', 'BIG' )['discount'], 'never more than the books cost' );
    $old = Corporate_Book_Shop::quote( $items, '201301', 'OLD' );
    $this->assertSame( 0, $old['discount'] );
    $this->assertNotEmpty( $old['errors'], 'expired coupon is refused' );
    $this->assertNotEmpty( Corporate_Book_Shop::quote( $items, '201301', 'NOPE' )['errors'] );
    $this->assertSame( array(), Corporate_Book_Shop::quote( $items, '201301', '' )['errors'] );
  }

  public function test_free_shipping_counts_the_price_after_the_coupon() {
    $this->settings( array( 'print_free_above' => '900', 'print_coupons' => 'HALF | 50%' ) );
    $this->book( 1, '499' );
    $items = array( array( 'id' => 1, 'qty' => 2 ) );
    $this->assertSame( 0, Corporate_Book_Shop::quote( $items, '201301' )['shipping'] );
    $this->assertSame( 4000, Corporate_Book_Shop::quote( $items, '201301', 'HALF' )['shipping'] );
  }

  public function test_print_sales_can_be_switched_off() {
    $this->settings( array( 'print_enable' => 'false' ) );
    $this->book( 1, '499', '40' );
    $this->assertNull( Corporate_Book_Shop::book_info( 1 ) );
  }

  public function test_address_is_validated_and_cleaned() {
    $good = array( 'name' => ' Asha Rao ', 'phone' => '+91 98765-43210', 'email' => 'asha@example.test', 'line1' => 'Flat 4B, Lotus Tower', 'line2' => 'Sector 62', 'landmark' => '', 'city' => 'Noida', 'state' => 'Uttar Pradesh', 'pin' => '201 309' );
    list( $clean, $errors ) = Corporate_Book_Shop::validate_address( $good );
    $this->assertSame( array(), $errors );
    $this->assertSame( '9876543210', $clean['phone'] );
    $this->assertSame( '201309', $clean['pin'] );
    $this->assertSame( 'Asha Rao', $clean['name'] );
    list( , $errors ) = Corporate_Book_Shop::validate_address( array( 'name' => '', 'phone' => '12345', 'email' => 'nope', 'line1' => '', 'city' => '', 'state' => 'Atlantis', 'pin' => '000000' ) );
    $this->assertEqualsCanonicalizing( array( 'name', 'phone', 'email', 'line1', 'city', 'state', 'pin' ), array_keys( $errors ) );
  }
}
