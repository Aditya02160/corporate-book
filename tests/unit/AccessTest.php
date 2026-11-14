<?php
/**
 * Who may read past the free preview, and how long the preview is.
 */
class AccessTest extends CB_Unit_Test {
  private function book( $id, $mode = null ) {
    $GLOBALS['wp']['post_meta'][ $id ]['_corporate_book_data'] = null === $mode ? array() : array( 'subscription' => $mode );
    return $id;
  }

  public function test_book_follows_global_lock_unless_it_overrides() {
    $cb = Corporate_Book::get_instance();
    $this->assertFalse( $cb->book_is_locked( $this->book( 1 ) ) );
    $this->assertTrue( $cb->book_is_locked( $this->book( 2, 'required' ) ) );
    $this->settings( array( 'subscription_enable' => 'true' ) );
    $this->assertTrue( $cb->book_is_locked( $this->book( 3 ) ) );
    $this->assertFalse( $cb->book_is_locked( $this->book( 4, 'free' ) ) );
  }

  public function test_guest_reads_only_the_preview_of_a_locked_book() {
    $this->settings( array( 'subscription_enable' => 'true' ) );
    $this->assertFalse( Corporate_Book::get_instance()->user_has_access( $this->book( 1 ) ) );
    $this->assertTrue( Corporate_Book::get_instance()->user_has_access( $this->book( 2, 'free' ) ) );
  }

  public function test_signed_in_reader_without_subscription_gets_preview() {
    $this->settings( array( 'subscription_enable' => 'true', 'subscription_roles' => 'cb_member' ) );
    $this->sign_in( 7, array( 'cb_reader' ) );
    $this->assertFalse( Corporate_Book::get_instance()->user_has_access( $this->book( 1 ) ) );
  }

  public function test_member_role_paid_days_and_editors_get_the_full_book() {
    $this->settings( array( 'subscription_enable' => 'true', 'subscription_roles' => 'cb_member' ) );
    $cb = Corporate_Book::get_instance();
    $this->sign_in( 7, array( 'cb_member' ) );
    $this->assertTrue( $cb->user_has_access( $this->book( 1 ) ) );
    $this->sign_in( 8, array( 'cb_reader' ) );
    update_user_meta( 8, Corporate_Book_Account::UNTIL, time() + DAY_IN_SECONDS );
    $this->assertTrue( $cb->user_has_access( 1 ) );
    $this->sign_in( 9, array( 'editor' ), array( 'edit_post' ) );
    $this->assertTrue( $cb->user_has_access( 1 ) );
  }

  public function test_expired_subscription_gets_preview_again() {
    $this->settings( array( 'subscription_enable' => 'true' ) );
    $this->sign_in( 8, array( 'cb_reader' ) );
    update_user_meta( 8, Corporate_Book_Account::UNTIL, time() - 60 );
    $this->assertFalse( Corporate_Book::get_instance()->user_has_access( $this->book( 1 ) ) );
  }

  public function test_filter_can_decide_who_is_subscribed() {
    $this->settings( array( 'subscription_enable' => 'true' ) );
    $this->sign_in( 8, array( 'cb_reader' ) );
    add_filter( 'corporate_book_user_is_subscribed', function () {
      return true;
    } );
    $this->assertTrue( Corporate_Book::get_instance()->user_has_access( $this->book( 1 ) ) );
  }

  #[PHPUnit\Framework\Attributes\DataProvider('preview_cases')]
  public function test_preview_page_count( $percent, $pages, $expected ) {
    $this->settings( array( 'subscription_preview_percent' => $percent ) );
    $this->assertSame( $expected, Corporate_Book::get_instance()->preview_pages( $pages ) );
  }

  public static function preview_cases() {
    return array(
      'ten percent of 20'        => array( 10, 20, 2 ),
      'rounds up'                => array( 10, 21, 3 ),
      'tiny book still 1 page'   => array( 10, 3, 1 ),
      'empty book still 1 page'  => array( 10, 0, 1 ),
      'whole book at 100 percent'=> array( 100, 40, 40 ),
    );
  }
}
