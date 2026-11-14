<?php
/**
 * Removes Corporate Book's settings, scheduled email and reader role when the plugin is deleted.
 *
 * Kept on purpose: books, media and the protected PDFs (they are the site's content), readers'
 * accounts and payment records (needed for accounting). The two pages the plugin created go to the Trash.
 *
 * @package corporate book
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
  exit;
}

foreach ( array( 'corporate_book_subscription_page', 'corporate_book_profile_page' ) as $option ) {
  $page = (int) get_option( $option );
  if ( $page > 0 ) {
    wp_trash_post( $page );
  }
  delete_option( $option );
}
delete_option( '_corporate_book_settings' );
delete_option( 'corporate_book_flush_rules' );
delete_option( 'corporate_book_db_version' );
wp_clear_scheduled_hook( 'corporate_book_reminders' );
// Readers keep their accounts as plain subscribers.
foreach ( get_users( array( 'role' => 'cb_reader', 'fields' => 'ID' ) ) as $reader ) {
  ( new WP_User( $reader ) )->set_role( 'subscriber' );
}
remove_role( 'cb_reader' );
