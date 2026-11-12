<?php
/**
 * Protected full PDFs for books behind the subscription lock.
 *
 * Saving a locked book moves its PDF into uploads/corporate-book-protected/ under a random
 * name. Readers load it through ?corporate_book_pdf=<book id>, which streams the file (with
 * HTTP Range support for partial loading) only after Corporate_Book::user_has_access().
 *
 * @package corporate book
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

class Corporate_Book_Protected_PDF {

  const DIR   = 'corporate-book-protected';
  const QUERY = 'corporate_book_pdf';

  public function __construct() {
    add_action( 'init', array( $this, 'serve' ), 20 );
    // After save_meta_boxes() (save_post, priority 10) has stored the book settings.
    add_action( 'save_post', array( $this, 'protect' ), 20 );
  }

  /**
   * Link that streams a book's full PDF to readers with access.
   *
   * @param int $book_id Book post ID.
   *
   * @return string
   */
  public static function url( $book_id ) {
    return add_query_arg( self::QUERY, (int) $book_id, home_url( '/' ) );
  }

  /**
   * Local path of a book's PDF inside the uploads folder, or '' for external or missing files.
   *
   * @param int $book_id Book post ID.
   *
   * @return string
   */
  public static function local_path( $book_id ) {
    $data    = get_post_meta( $book_id, '_corporate_book_data', true );
    $url     = is_array( $data ) && ! empty( $data['pdf_source'] ) ? set_url_scheme( $data['pdf_source'] ) : '';
    $uploads = wp_get_upload_dir();
    $baseurl = set_url_scheme( $uploads['baseurl'] ) . '/';
    if ( '' === $url || 0 !== strpos( $url, $baseurl ) ) {
      return '';
    }
    $relative = rawurldecode( strtok( substr( $url, strlen( $baseurl ) ), '?#' ) );
    $path     = realpath( $uploads['basedir'] . '/' . $relative );
    $root     = realpath( $uploads['basedir'] );
    // Only PDF files inside the uploads folder.
    if ( ! $path || ! $root || 0 !== strpos( $path, $root . DIRECTORY_SEPARATOR ) || ! is_file( $path )
         || 'pdf' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
      return '';
    }
    return $path;
  }

  /**
   * Streams the full PDF of ?corporate_book_pdf=<id> to readers with access; 403 for everyone else.
   */
  public function serve() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only download; access is checked below.
    if ( ! isset( $_GET[ self::QUERY ] ) ) {
      return;
    }
    $book_id = absint( wp_unslash( $_GET[ self::QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $book    = get_post( $book_id );
    $base    = Corporate_Book::get_instance();
    nocache_headers();
    if ( ! $book || 'corporate_book' !== $book->post_type
         || ( 'publish' !== $book->post_status && ! current_user_can( 'read_post', $book_id ) )
         || ! $base->user_has_access( $book_id ) ) {
      status_header( 403 );
      exit;
    }
    $path = self::local_path( $book_id );
    if ( '' === $path ) {
      status_header( 404 );
      exit;
    }
    $this->stream( $path );
    exit;
  }

  /**
   * Sends a file, honouring a single "Range: bytes=start-end" request header.
   *
   * @param string $path File path.
   */
  private function stream( $path ) {
    $size  = filesize( $path );
    $start = 0;
    $end   = $size - 1;
    $range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
    while ( ob_get_level() ) {
      ob_end_clean();
    }
    if ( preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $m ) && ( '' !== $m[1] || '' !== $m[2] ) ) {
      if ( '' === $m[1] ) {
        $start = max( 0, $size - (int) $m[2] );
      } else {
        $start = (int) $m[1];
        $end   = '' === $m[2] ? $end : min( $end, (int) $m[2] );
      }
      if ( $start > $end ) {
        status_header( 416 );
        header( 'Content-Range: bytes */' . $size );
        return;
      }
      status_header( 206 );
      header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
    } else {
      status_header( 200 );
    }
    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: inline' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'Accept-Ranges: bytes' );
    header( 'Content-Length: ' . ( $end - $start + 1 ) );
    $file = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    fseek( $file, $start );
    for ( $left = $end - $start + 1; $left > 0 && ! feof( $file ); ) {
      $chunk = fread( $file, min( 8192, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
      echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF bytes.
      $left -= strlen( $chunk );
    }
    fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
  }

  /**
   * Moves a locked book's PDF out of the public uploads into the protected folder.
   *
   * @param int $post_id Saved post ID.
   */
  public function protect( $post_id ) {
    if ( wp_is_post_revision( $post_id ) || 'corporate_book' !== get_post_type( $post_id )
         || ! Corporate_Book::get_instance()->book_is_locked( $post_id ) ) {
      return;
    }
    $path    = self::local_path( $post_id );
    $uploads = wp_get_upload_dir();
    $dir     = $uploads['basedir'] . '/' . self::DIR;
    if ( '' === $path || ! $this->prepare_dir( $dir ) || 0 === strpos( $path, realpath( $dir ) . DIRECTORY_SEPARATOR ) ) {
      return;
    }
    $data       = get_post_meta( $post_id, '_corporate_book_data', true );
    $attachment = attachment_url_to_postid( $data['pdf_source'] );
    // A random prefix keeps the file unguessable on servers that ignore .htaccess (nginx).
    $name   = wp_generate_password( 20, false ) . '-' . sanitize_file_name( basename( $path ) );
    $target = $dir . '/' . $name;
    if ( ! rename( $path, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
      return;
    }
    if ( $attachment ) {
      update_attached_file( $attachment, $target );
      // Private attachments are hidden from the public media REST API and attachment pages.
      wp_update_post( array( 'ID' => $attachment, 'post_status' => 'private' ) );
    }
    $data['pdf_source'] = $uploads['baseurl'] . '/' . self::DIR . '/' . $name;
    update_post_meta( $post_id, '_corporate_book_data', $data );
  }

  /**
   * Creates the protected folder with deny rules for Apache.
   *
   * @param string $dir Folder path.
   *
   * @return bool Whether the folder exists.
   */
  private function prepare_dir( $dir ) {
    if ( ! wp_mkdir_p( $dir ) ) {
      return false;
    }
    if ( ! file_exists( $dir . '/.htaccess' ) ) {
      // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
      file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
    }
    if ( ! file_exists( $dir . '/index.php' ) ) {
      file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    }
    return true;
  }
}

new Corporate_Book_Protected_PDF();
