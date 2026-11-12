<?php
/**
 * Plugin Name: Corporate Book Test Setup
 * Description: Test-only, mounted by tests/smoke.cjs. Creates a sample book and a page that embeds it.
 */
add_action(
	'init',
	function () {
		if ( get_page_by_path( 'book-test' ) ) {
			return;
		}
		$book = wp_insert_post( array( 'post_type' => 'corporate_book', 'post_status' => 'publish', 'post_title' => 'Test Book' ) );
		update_post_meta( $book, '_corporate_book_data', array( 'source_type' => 'pdf', 'pdf_source' => home_url( '/wp-content/uploads/cb-test/sample.pdf' ) ) );
		wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'book-test',
				'post_title'   => 'Book Test',
				'post_content' => "[corporate_book id=\"$book\"][/corporate_book]\n[corporate_book id=\"$book\" type=\"thumb\"][/corporate_book]\n[corporate_book id=\"999999\"][/corporate_book]",
			)
		);
	},
	20
);
