<?php
/**
 * Saves the preview PDF and the thumbnail that the book editor builds in the browser.
 *
 * The file arrives in small chunks through admin-ajax.php, so creating it does not depend on
 * the REST API or on the server's upload size limit.
 *
 * @package corporate book
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

class Corporate_Book_Upload {

  const NONCE = 'corporate_book_upload';

  public function __construct() {
    add_action( 'wp_ajax_corporate_book_upload', array( $this, 'receive' ) );
  }

  /**
   * Bytes per chunk: small enough for the strictest common server limit (a 1 MB request body).
   *
   * @return int
   */
  public static function chunk_size() {
    return max( 1, (int) apply_filters( 'corporate_book_upload_chunk_size', 512 * KB_IN_BYTES ) );
  }

  /**
   * Receives one chunk; the last chunk turns the file into a Media Library attachment.
   */
  public function receive() {
    check_ajax_referer( self::NONCE );
    $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post_id ) ) {
      wp_send_json_error( __( 'You are not allowed to upload files for this book.', '3d-corporate-book' ), 403 );
    }
    $kind   = isset( $_POST['kind'] ) && 'thumb' === $_POST['kind'] ? 'thumb' : 'preview';
    $token  = isset( $_POST['token'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
    $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- an uploaded file, checked with is_uploaded_file() below.
    $chunk = isset( $_FILES['file'] ) ? $_FILES['file'] : null;
    if ( 32 !== strlen( $token ) || ! $chunk || UPLOAD_ERR_OK !== $chunk['error'] || ! is_uploaded_file( $chunk['tmp_name'] ) ) {
      wp_send_json_error( __( 'The file did not arrive. Check the upload limits of your server.', '3d-corporate-book' ), 400 );
    }
    $part = trailingslashit( get_temp_dir() ) . 'corporate-book-' . get_current_user_id() . '-' . $token . '.part';
    $have = 0 === $offset || ! file_exists( $part ) ? 0 : filesize( $part );
    // Chunks must arrive in order, and the whole file has a size cap.
    if ( $have !== $offset || $offset + $chunk['size'] > (int) apply_filters( 'corporate_book_upload_max_size', 512 * MB_IN_BYTES ) ) {
      wp_send_json_error( __( 'The upload was interrupted. Try again.', '3d-corporate-book' ), 400 );
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions -- appending binary chunks to a temporary file.
    if ( false === file_put_contents( $part, file_get_contents( $chunk['tmp_name'] ), 0 === $offset ? 0 : FILE_APPEND ) ) {
      wp_send_json_error( __( 'The server could not store the file.', '3d-corporate-book' ), 500 );
    }
    if ( empty( $_POST['last'] ) ) {
      wp_send_json_success( array( 'received' => $offset + $chunk['size'] ) );
    }

    // The file must really be what it claims: a PNG image or a PDF.
    $head = (string) file_get_contents( $part, false, null, 0, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    if ( 'thumb' === $kind ? "\x89PNG\r\n\x1a\n" !== $head : 0 !== strpos( $head, '%PDF-' ) ) {
      wp_delete_file( $part );
      wp_send_json_error( __( 'The file is not a valid PDF or PNG.', '3d-corporate-book' ), 400 );
    }
    $base = isset( $_POST['name'] ) ? sanitize_file_name( pathinfo( sanitize_text_field( wp_unslash( $_POST['name'] ) ), PATHINFO_FILENAME ) ) : '';
    $name = ( '' === $base ? 'book' : $base ) . ( 'thumb' === $kind ? '.png' : '.pdf' );
    $type = 'thumb' === $kind ? 'image/png' : 'application/pdf';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    // wp_handle_sideload() takes the file by reference, so it must be a variable.
    $sideload = array( 'name' => $name, 'type' => $type, 'tmp_name' => $part, 'error' => 0, 'size' => filesize( $part ) );
    $file     = wp_handle_sideload( $sideload, array( 'test_form' => false, 'mimes' => array( 'pdf' => 'application/pdf', 'png' => 'image/png' ) ) );
    if ( isset( $file['error'] ) ) {
      wp_delete_file( $part );
      wp_send_json_error( $file['error'], 400 );
    }
    $id = wp_insert_attachment( array( 'post_mime_type' => $type, 'post_title' => pathinfo( $name, PATHINFO_FILENAME ), 'post_status' => 'inherit' ), $file['file'], $post_id );
    if ( ! $id || is_wp_error( $id ) ) {
      wp_send_json_error( __( 'The server could not store the file.', '3d-corporate-book' ), 500 );
    }
    // Thumbnails get their image sizes. PDFs skip WordPress's own PDF preview images, which are slow and fail on some hosts.
    if ( 'thumb' === $kind ) {
      require_once ABSPATH . 'wp-admin/includes/image.php';
      wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['file'] ) );
    }
    wp_send_json_success( array( 'id' => $id, 'url' => wp_get_attachment_url( $id ) ) );
  }
}

new Corporate_Book_Upload();
