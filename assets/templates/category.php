<?php
//Since the custom post is set to "exclude_from_search" WordPress normal archive search won't display any posts.

get_header();
do_action( "before_corporate_book_category_content" );

do_action( "corporate_book_category_content" );

do_action( "after_corporate_book_category_content" );
get_footer();
