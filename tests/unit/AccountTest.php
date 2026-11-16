<?php
/**
 * Plans, prices, subscription dates, webhook signatures, readers versus staff, privacy tools.
 */
class AccountTest extends CB_Unit_Test {
  private function call( $method, ...$args ) {
    $m = new ReflectionMethod( 'Corporate_Book_Account', $method );
    $m->setAccessible( true );
    return $m->invoke( null, ...$args );
  }

  public function test_plans_keep_only_valid_lines() {
    $this->settings( array( 'subscription_plans' => "Monthly | 399 | 30\nBroken line\nFree | 0 | 30\nHalf | 49.5 | 7\nNo days | 10 | x\n Yearly|3999|365 " ) );
    $plans = Corporate_Book_Account::plans();
    $this->assertSame( array( 'Monthly', 'Half', 'Yearly' ), array_column( $plans, 'name' ) );
    $this->assertSame( 49.5, $plans[1]['price'] );
    $this->assertSame( 365, $plans[2]['days'] );
  }

  #[PHPUnit\Framework\Attributes\DataProvider('prices')]
  public function test_price_uses_currency_symbol( $currency, $amount, $expected ) {
    $this->settings( array( 'subscription_currency' => $currency ) );
    $this->assertSame( $expected, Corporate_Book_Account::price( $amount ) );
  }

  public static function prices() {
    return array(
      'rupees with comma' => array( 'INR', 3999, "\u{20B9}3,999" ),
      'dollars, decimals' => array( 'USD', 9.5, '$9.50' ),
      'euro trims zeros'  => array( 'EUR', 10.00, "\u{20AC}10" ),
      'no symbol'         => array( 'AED', 25, 'AED 25' ),
    );
  }

  public function test_subscription_is_active_until_its_date() {
    $this->assertFalse( Corporate_Book_Account::is_active( 5 ) );
    update_user_meta( 5, Corporate_Book_Account::UNTIL, time() + 100 );
    $this->assertTrue( Corporate_Book_Account::is_active( 5 ) );
    update_user_meta( 5, Corporate_Book_Account::UNTIL, time() - 1 );
    $this->assertFalse( Corporate_Book_Account::is_active( 5 ) );
  }

  public function test_webhook_signature() {
    $body = '{"event":"payment.captured"}';
    $sign = hash_hmac( 'sha256', $body, 'whsec' );
    $this->assertTrue( Corporate_Book_Account::signed( $body, $sign, 'whsec' ) );
    $this->assertFalse( Corporate_Book_Account::signed( $body . ' ', $sign, 'whsec' ), 'changed body' );
    $this->assertFalse( Corporate_Book_Account::signed( $body, $sign, 'other' ), 'wrong secret' );
    $this->assertFalse( Corporate_Book_Account::signed( $body, '', 'whsec' ), 'no signature' );
    $this->assertFalse( Corporate_Book_Account::signed( $body, hash_hmac( 'sha256', $body, '' ), '' ), 'no secret set' );
  }

  public function test_readers_are_users_who_cannot_write_posts() {
    $reader = new WP_User( 3, array( 'caps' => array( 'read' ) ) );
    $staff  = new WP_User( 4, array( 'caps' => array( 'read', 'edit_posts' ) ) );
    $this->assertTrue( $this->call( 'is_reader', $reader ) );
    $this->assertFalse( $this->call( 'is_reader', $staff ) );
    $this->assertFalse( $this->call( 'is_reader', new WP_User( 0 ) ), 'guest' );
  }

  public function test_readers_land_on_my_account_after_wp_login() {
    update_option( 'corporate_book_profile_page', 12 );
    $account = ( new ReflectionClass( 'Corporate_Book_Account' ) )->newInstanceWithoutConstructor();
    $reader  = new WP_User( 3, array( 'caps' => array( 'read' ) ) );
    $staff   = new WP_User( 4, array( 'caps' => array( 'edit_posts' ) ) );
    $this->assertSame( 'https://example.test/?page_id=12', $account->login_redirect( admin_url(), '', $reader ) );
    $this->assertSame( admin_url(), $account->login_redirect( admin_url(), '', $staff ) );
    $this->assertSame( 'https://example.test/book/', $account->login_redirect( 'https://example.test/book/', 'https://example.test/book/', $reader ), 'keeps a front-end return page' );
  }

  public function test_profile_url_falls_back_to_home() {
    $this->assertSame( 'https://example.test/', Corporate_Book_Account::profile_url() );
    update_option( 'corporate_book_profile_page', 12 );
    $this->assertSame( 'https://example.test/?page_id=12', Corporate_Book_Account::profile_url() );
  }

  public function test_privacy_export_and_erase() {
    $user = $this->sign_in( 6 );
    update_user_meta( 6, 'cb_phone', '+91 1' );
    update_user_meta( 6, 'cb_company', 'Acme' );
    update_user_meta( 6, Corporate_Book_Account::ORDERS, array( 'order_A' => array( 'amount' => 39900, 'days' => 30, 'plan' => 'Monthly', 'time' => 0, 'paid' => 'pay_A' ) ) );
    $account = ( new ReflectionClass( 'Corporate_Book_Account' ) )->newInstanceWithoutConstructor();
    $export  = $account->export_data( $user->user_email );
    $this->assertCount( 2, $export['data'], 'account group and one payment' );
    update_user_meta( 6, Corporate_Book_Shop::ADDRESS, array( 'name' => 'Asha', 'pin' => '201301' ) );
    $this->assertCount( 3, $account->export_data( $user->user_email )['data'], 'delivery address is exported too' );
    $this->assertSame( '+91 1', $export['data'][0]['data'][0]['value'] );
    $erase = $account->erase_data( $user->user_email );
    $this->assertTrue( $erase['items_removed'] );
    $this->assertSame( '', get_user_meta( 6, 'cb_phone', true ) );
    $this->assertSame( '', get_user_meta( 6, Corporate_Book_Shop::ADDRESS, true ), 'delivery address is erased' );
    $this->assertNotEmpty( get_user_meta( 6, Corporate_Book_Account::ORDERS, true ), 'payments kept for accounting' );
    $this->assertSame( array( 'data' => array(), 'done' => true ), $account->export_data( 'nobody@example.test' ) );
  }
}
