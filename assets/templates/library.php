<?php
/**
 * Book list page: the Corporate Book library inside the theme's header and footer.
 *
 * @package corporate book
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

get_header();
echo do_shortcode( '[corporate_book_library]' );
get_footer();
