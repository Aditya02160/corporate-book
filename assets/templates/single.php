<?php
$corporate_book_header_footer = get_query_var( 'header-footer' );
$hide_header_footer = $corporate_book_header_footer == "false";

if ( $hide_header_footer ) {
  wp_head();
} else {
  get_header();
}

do_action( "before_corporate_book_single_content" );

do_action( "corporate_book_single_content" );

do_action( "after_corporate_book_single_content" );

if ( $hide_header_footer ) {
  wp_footer();
} else {
  get_footer();
}

