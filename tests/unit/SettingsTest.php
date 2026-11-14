<?php
/**
 * Global Settings values are cleaned before they are saved.
 */
class SettingsTest extends CB_Unit_Test {
  private function clean( $key, $value ) {
    $settings       = ( new ReflectionClass( 'Corporate_Book_Settings' ) )->newInstanceWithoutConstructor();
    $settings->base = Corporate_Book::get_instance();
    return $settings->sanitize_setting( $key, $value );
  }

  public function test_subscribe_link_rejects_scripts() {
    $this->assertSame( '', $this->clean( 'subscription_url', 'javascript:alert(1)' ) );
    $this->assertSame( 'https://example.test/subscribe', $this->clean( 'subscription_url', ' https://example.test/subscribe ' ) );
  }

  public function test_roles_are_cleaned_and_reader_role_is_refused() {
    $this->assertSame( 'cb_member,badrole', $this->clean( 'subscription_roles', 'cb_member, Bad Role!, cb_reader' ) );
    $this->assertSame( '', $this->clean( 'subscription_roles', 'cb_reader' ) );
  }

  #[PHPUnit\Framework\Attributes\DataProvider('percents')]
  public function test_preview_percent_is_clamped( $in, $out ) {
    $this->assertSame( $out, $this->clean( 'subscription_preview_percent', $in ) );
  }

  public static function percents() {
    return array( 'zero' => array( '0', 1 ), 'over' => array( '250', 100 ), 'fraction up' => array( '9.2', 10 ), 'text' => array( 'abc', 10 ) );
  }

  public function test_choices_only_accept_listed_values() {
    $this->assertSame( 'true', $this->clean( 'subscription_enable', 'true' ) );
    $this->assertSame( 'false', $this->clean( 'subscription_enable', 'maybe' ), 'unknown value falls back to default' );
    $this->assertSame( 'false', $this->clean( 'subscription_enable', 'global' ), 'global is only for single books' );
    $this->assertSame( 'USD', $this->clean( 'subscription_currency', 'USD' ) );
    $this->assertSame( 'INR', $this->clean( 'subscription_currency', 'XYZ' ) );
  }

  public function test_numbers_and_text() {
    $this->assertSame( 1200, $this->clean( 'duration', '1200' ) );
    $this->assertSame( 800, $this->clean( 'duration', 'fast' ) );
    $this->assertSame( "Monthly | 399 | 30\nYearly | 3999 | 365", $this->clean( 'subscription_plans', "Monthly | 399 | 30\nYearly | 3999 | 365<script>" ) );
  }
}
