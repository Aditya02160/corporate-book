<?php
/**
 * Print shop maths: PIN codes, distance from Noida, shipping bands, cart quotes, delivery addresses.
 */
class ShopTest extends CB_Unit_Test {
  protected function setUp(): void {
    parent::setUp();
    Corporate_Book::get_instance()->defaults += array(
      'print_enable'     => array( 'std' => 'true', 'choices' => array( 'true' => 'On', 'false' => 'Off' ) ),
      'print_bands'      => array( 'std' => "50 | 0\n300 | 30\n800 | 60\n1500 | 90\n99999 | 120", 'type' => 'textarea' ),
      'print_free_above' => array( 'std' => '', 'type' => 'text' ),
    );
  }

  private function book( $id, $price, $shipping, $stock = '', $sell = 'true', $status = 'publish' ) {
    $GLOBALS['wp']['post_meta'][ $id ]['_corporate_book_data'] = array( 'print_sell' => $sell, 'print_price' => $price, 'print_shipping' => $shipping, 'print_stock' => $stock );
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

  #[PHPUnit\Framework\Attributes\DataProvider('distances')]
  public function test_distance_from_noida_by_pin( $pin, $min, $max ) {
    $km = Corporate_Book_Shop::distance_km( $pin );
    $this->assertGreaterThanOrEqual( $min, $km, $pin );
    $this->assertLessThanOrEqual( $max, $km, $pin );
  }

  public static function distances() {
    return array(
      'Noida'       => array( '201301', 0, 20 ),
      'Delhi'       => array( '110001', 5, 40 ),
      'Gurugram'    => array( '122001', 20, 60 ),
      'Lucknow'     => array( '226001', 380, 480 ),
      'Mumbai'      => array( '400001', 1100, 1250 ),
      'Bengaluru'   => array( '560001', 1650, 1800 ),
      'Guwahati'    => array( '781001', 1350, 1550 ),
      'Port Blair'  => array( '744101', 2300, 2700 ),
      'Army post'   => array( '900001', 9999, 9999 ),
    );
  }

  public function test_bands_pick_the_extra_charge_for_a_distance() {
    $this->assertSame( 0, Corporate_Book_Shop::band_extra( 10 ) );
    $this->assertSame( 30, Corporate_Book_Shop::band_extra( 51 ) );
    $this->assertSame( 90, Corporate_Book_Shop::band_extra( 1200 ) );
    $this->assertSame( 120, Corporate_Book_Shop::band_extra( 9999 ) );
    $this->settings( array( 'print_bands' => "100 | 10\nbad line\n500 | 25.5" ) );
    $this->assertSame( 25.5, Corporate_Book_Shop::band_extra( 300 ) );
    $this->assertSame( 25.5, Corporate_Book_Shop::band_extra( 5000 ), 'beyond the last band uses the last band' );
  }

  public function test_quote_adds_book_price_and_distance_shipping_per_copy() {
    $this->book( 1, '499', '40' );
    $this->book( 2, '250.50', '30' );
    $local = Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 2 ), array( 'id' => 2, 'qty' => 1 ) ), '201301' );
    $this->assertSame( array(), $local['errors'] );
    $this->assertSame( 124850, $local['subtotal'], 'paise: 2 x 499 + 250.50' );
    $this->assertSame( 11000, $local['shipping'], '2 x 40 + 30, no distance extra near Noida' );
    $this->assertSame( 135850, $local['total'] );
    $far = Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 2 ), array( 'id' => 2, 'qty' => 1 ) ), '560001' );
    $this->assertSame( 11000 + 3 * 12000, $far['shipping'], 'Bengaluru adds 120 per copy' );
  }

  public function test_quote_without_pin_gives_book_prices_only() {
    $this->book( 1, '499', '40' );
    $q = Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 1 ) ), '' );
    $this->assertSame( 49900, $q['subtotal'] );
    $this->assertNull( $q['shipping'] );
  }

  public function test_free_shipping_above_an_amount() {
    $this->settings( array( 'print_free_above' => '1000' ) );
    $this->book( 1, '499', '40' );
    $this->assertSame( 0, Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 3 ) ), '560001' )['shipping'] );
    $this->assertSame( 32000, Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 2 ) ), '560001' )['shipping'], '998 is below 1000' );
  }

  public function test_quote_refuses_books_not_for_sale_and_limits_quantity_and_stock() {
    $this->book( 1, '499', '40', '2' );
    $this->book( 2, '300', '20', '', 'false' );
    $this->book( 3, '300', '20', '', 'true', 'draft' );
    $this->book( 4, '0', '20' );
    $q = Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 5 ), array( 'id' => 2, 'qty' => 1 ), array( 'id' => 3, 'qty' => 1 ), array( 'id' => 4, 'qty' => 1 ), array( 'id' => 99, 'qty' => 1 ) ), '201301' );
    $this->assertCount( 1, $q['lines'] );
    $this->assertSame( 2, $q['lines'][0]['qty'], 'only 2 in stock' );
    $this->assertNotEmpty( $q['errors'] );
    $q = Corporate_Book_Shop::quote( array( array( 'id' => 1, 'qty' => 0 ), array( 'id' => 1, 'qty' => '-3' ) ), '201301' );
    $this->assertSame( 2, $q['lines'][0]['qty'], 'each line at least 1 copy; lines of the same book are merged' );
    $this->book( 5, '100', '10' );
    $this->assertSame( 10, Corporate_Book_Shop::quote( array( array( 'id' => 5, 'qty' => 50 ) ), '' )['lines'][0]['qty'], 'at most 10 copies' );
    $this->book( 6, '100', '10', '0' );
    $this->assertSame( array(), Corporate_Book_Shop::quote( array( array( 'id' => 6, 'qty' => 1 ) ), '' )['lines'], 'sold out' );
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
