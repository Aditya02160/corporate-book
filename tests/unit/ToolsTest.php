<?php
/**
 * CSV export rows and Shiprocket order fields.
 */
class ToolsTest extends CB_Unit_Test {
  private function order( $id, $address, $payment = 'razorpay' ) {
    $GLOBALS['wp']['posts'][ $id ]                     = array( 'title' => "Order #$id", 'status' => 'cb-paid' );
    $GLOBALS['wp']['post_meta'][ $id ]['_cb_address']  = $address + array( 'name' => 'Asha Rao', 'phone' => '9876543210', 'email' => 'asha@example.test', 'line1' => 'Flat 4B', 'line2' => 'Sector 62', 'landmark' => '', 'city' => 'Noida', 'state' => 'Uttar Pradesh', 'pin' => '201309' );
    $GLOBALS['wp']['post_meta'][ $id ]['_cb_items']    = array( array( 'id' => 5, 'title' => 'Annual Report', 'qty' => 2, 'price' => 49900 ) );
    $GLOBALS['wp']['post_meta'][ $id ]['_cb_totals']   = array( 'subtotal' => 99800, 'discount' => 9980, 'coupon' => 'WELCOME10', 'shipping' => 8000, 'total' => 97820 );
    $GLOBALS['wp']['post_meta'][ $id ]['_cb_payment']  = $payment;
    return $id;
  }

  public function test_csv_row_has_customer_books_and_money_in_rupees() {
    $row = Corporate_Book_Tools::csv_row( $this->order( 7, array() ) );
    $this->assertSame( 'Asha Rao', $row[4] );
    $this->assertSame( '201309', $row[10] );
    $this->assertSame( '2 x Annual Report', $row[11] );
    $this->assertSame( '2', $row[12] );
    $this->assertSame( '978.2', $row[16] );
    $this->assertSame( 'Razorpay', $row[17] );
  }

  public function test_csv_cells_cannot_run_as_spreadsheet_formulas() {
    $row = Corporate_Book_Tools::csv_row( $this->order( 8, array( 'name' => '=HYPERLINK("http://evil")', 'city' => '+cmd', 'line1' => '@SUM(1)' ) ) );
    $this->assertSame( "'=HYPERLINK(\"http://evil\")", $row[4] );
    $this->assertSame( "'+cmd", $row[8] );
    $this->assertStringStartsWith( "'@", $row[7] );
  }

  public function test_shiprocket_order_fields() {
    $o = Corporate_Book_Tools::shiprocket_order( $this->order( 9, array(), 'cod' ) );
    $this->assertSame( 'CB9', $o['order_id'] );
    $this->assertSame( 'COD', $o['payment_method'] );
    $this->assertSame( '201309', $o['billing_pincode'] );
    $this->assertSame( 2, $o['order_items'][0]['units'] );
    $this->assertSame( 499, $o['order_items'][0]['selling_price'] );
    $this->assertSame( 998, $o['sub_total'] );
    $this->assertSame( 80, $o['shipping_charges'] );
    $this->assertEqualsWithDelta( 0.8, $o['weight'], 0.001 );
  }
}
