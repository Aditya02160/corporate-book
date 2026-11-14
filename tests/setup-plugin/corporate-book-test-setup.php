<?php
/**
 * Plugin Name: Corporate Book Test Setup
 * Description: Test-only, mounted by tests/smoke.cjs. Creates a sample book and a page that embeds it.
 */
// Small chunks, so every generated upload in the tests arrives in several pieces.
add_filter(
	'corporate_book_upload_chunk_size',
	function () {
		return 700;
	}
);

// Stand-in for Razorpay's API: orders carry their amount in the id, and "pay_X" is the captured payment of "order_X".
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		// Fake Shiprocket: a token, then an order number for any order.
		if ( 0 === strpos( $url, 'https://apiv2.shiprocket.in/v1/external/' ) ) {
			$data = false !== strpos( $url, 'auth/login' ) ? array( 'token' => 'SRTOKEN' ) : array( 'order_id' => 987654, 'shipment_id' => 123, 'awb_code' => 'SR123AWB' );
			return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
		}
		// Fake Razorpay refunds.
		if ( preg_match( '#^https://api\.razorpay\.com/v1/payments/pay_\w+/refund$#', $url ) ) {
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'id' => 'rfnd_TEST1', 'status' => 'processed' ) ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
		}
		$api = 'https://api.razorpay.com/v1/';
		if ( 0 !== strpos( $url, $api ) ) {
			return $pre;
		}
		$path   = substr( $url, strlen( $api ) );
		$answer = function ( $data, $code = 200 ) {
			return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
		};
		if ( 'orders' === $path ) {
			$body = json_decode( $args['body'], true );
			return $answer( array( 'id' => 'order_TEST' . $body['amount'] . 'A' . wp_rand( 100000, 999999 ), 'amount' => $body['amount'], 'currency' => $body['currency'], 'status' => 'created' ) );
		}
		if ( preg_match( '#^payments/pay_(TEST(\d+)A\d+)$#', $path, $m ) ) {
			return $answer( array( 'id' => 'pay_' . $m[1], 'order_id' => 'order_' . $m[1], 'amount' => (int) $m[2], 'currency' => 'INR', 'status' => 'captured' ) );
		}
		if ( preg_match( '#^orders/order_TEST\w+/payments$#', $path ) ) {
			return $answer( array( 'entity' => 'collection', 'count' => 0, 'items' => array() ) );
		}
		return $answer( array( 'error' => array( 'description' => 'not found' ) ), 404 );
	},
	10,
	3
);

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
		update_post_meta( $bulk, '_corporate_book_data', array( 'source_type' => 'pdf', 'pdf_source' => wp_get_attachment_url( $bulk_pdf ), 'pdf_thumb' => home_url( '/wp-content/uploads/cb-test/cover.png' ) ) );
		wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'Other', 'post_status' => 'inherit', 'post_author' => 1 ), 'cb-test/other.pdf' );
		wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'library-test', 'post_title' => 'Library Test', 'post_content' => '[corporate_book_library]' ) );
		wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'account-test', 'post_title' => 'Account Test', 'post_content' => '[corporate_book_account]' ) );
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

// Emails are written to the mounted fixtures folder so the smoke test can read them.
add_filter(
	'pre_wp_mail',
	function ( $result, $atts ) {
		file_put_contents( WP_CONTENT_DIR . '/uploads/cb-test/mail.txt', $atts['subject'] . "\n" . $atts['message'] );
		return true;
	},
	10,
	2
);
