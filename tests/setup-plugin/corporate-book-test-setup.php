<?php
/**
 * Plugin Name: Corporate Book Test Setup
 * Description: Test-only, mounted by tests/smoke.cjs. Creates a sample book and a page that embeds it.
 */
add_action(
	'init',
	function () {
		if ( ! get_role( 'cb_member' ) ) {
			add_role( 'cb_member', 'Corporate Book Member', array( 'read' => true ) );
		}
		foreach ( array( 'member' => 'cb_member', 'reader' => 'subscriber', 'writer' => 'contributor' ) as $login => $role ) {
			if ( ! username_exists( $login ) ) {
				wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'password', 'user_email' => $login . '@example.com', 'role' => $role ) );
			}
		}
		update_option( 'users_can_register', 1 );
		if ( get_page_by_path( 'book-test' ) ) {
			return;
		}
		$book = wp_insert_post( array( 'post_type' => 'corporate_book', 'post_status' => 'publish', 'post_title' => 'Test Book' ) );
		$preview = wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'Preview', 'post_status' => 'inherit' ), 'cb-test/preview.pdf' );
		update_post_meta( $book, '_corporate_book_data', array( 'source_type' => 'pdf', 'pdf_source' => home_url( '/wp-content/uploads/cb-test/sample.pdf' ), 'preview_pdf' => $preview ) );
		$bulk_pdf = wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'Bulk', 'post_status' => 'inherit', 'post_author' => 1 ), 'cb-test/bulk.pdf' );
		$bulk     = wp_insert_post( array( 'post_type' => 'corporate_book', 'post_status' => 'publish', 'post_title' => 'Bulk Book', 'post_author' => 1 ) );
		update_post_meta( $bulk, '_corporate_book_data', array( 'source_type' => 'pdf', 'pdf_source' => wp_get_attachment_url( $bulk_pdf ) ) );
		wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'Other', 'post_status' => 'inherit', 'post_author' => 1 ), 'cb-test/other.pdf' );
		wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'extras-test',
				'post_title'   => 'Extras Test',
				'post_content' => "[corporate_book id=\"$book\" type=\"link\"]Read book[/corporate_book]\n[corporate_book id=\"$book\" type=\"custom\"]<strong class=\"cb-custom-inner\">Open</strong>[/corporate_book]\n[corporate_book books=\"all\" shelf=\"true\"][/corporate_book]",
			)
		);
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
