<?php
/**
 * A book's own page: the edition layout inside the theme's header and footer.
 *
 * @package corporate book
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

get_header();
echo '<main class="cb-edition-page" style="max-width:1200px;margin:0 auto;padding:24px 16px">' . Corporate_Book_Post_Type::edition_page( get_queried_object_id() ) . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
get_footer();
