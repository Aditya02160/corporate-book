<?php
/**
 * Free preview from any page: builds a copy of a book's PDF with every page, where only the reader's
 * window (for example pages 10-14) is real and every other page says "Subscribe to read this page".
 * Page numbers, thumbnails and the table of contents keep matching the real book, and the full PDF is
 * never sent to readers without access.
 *
 * Reads classic and compressed (PDF 1.5+) cross-reference tables and object streams. Encrypted or
 * damaged PDFs cannot be read; callers then fall back to the "first pages" preview.
 *
 * @package corporate book
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

class Corporate_Book_Window {

  private $pdf;
  private $offsets  = array(); // object number => byte offset
  private $packed   = array(); // object number => array( object stream number, index )
  private $streams  = array(); // decoded object streams
  private $trailer  = array();

  public function __construct( $bytes ) {
    $this->pdf = $bytes;
    $start     = strrpos( $bytes, 'startxref' );
    if ( false === $start || ! preg_match( '/startxref\s+(\d+)/', $bytes, $m, 0, $start ) ) {
      throw new Exception( 'no startxref' );
    }
    $seen = array();
    for ( $at = (int) $m[1]; null !== $at && ! isset( $seen[ $at ] ); ) {
      $seen[ $at ] = true;
      $at          = $this->xref( $at );
    }
    if ( isset( $this->trailer['Encrypt'] ) || empty( $this->trailer['Root'] ) ) {
      throw new Exception( 'encrypted or no root' );
    }
  }

  // ---- Reading -------------------------------------------------------------------------------

  /**
   * Reads one cross-reference section; returns the offset of the previous one, or null.
   */
  private function xref( $at ) {
    if ( 'xref' === substr( $this->pdf, $at, 4 ) ) {
      $end  = strpos( $this->pdf, 'trailer', $at );
      $body = substr( $this->pdf, $at + 4, $end - $at - 4 );
      preg_match_all( '/(\d+)\s+(\d+)\s*[\r\n]+((?:\d{10}\s+\d{5}\s+[nf]\s*)*)/', $body, $sections, PREG_SET_ORDER );
      foreach ( $sections as $s ) {
        preg_match_all( '/(\d{10})\s+(\d{5})\s+([nf])/', $s[3], $rows, PREG_SET_ORDER );
        foreach ( $rows as $i => $row ) {
          $num = (int) $s[1] + $i;
          if ( 'n' === $row[3] && ! isset( $this->offsets[ $num ] ) && ! isset( $this->packed[ $num ] ) ) {
            $this->offsets[ $num ] = (int) $row[1];
          }
        }
      }
      $dict = self::dict( substr( $this->pdf, $end + 7, 4096 ) );
      $this->trailer += $dict;
      if ( isset( $dict['XRefStm'] ) ) {
        $this->xref( (int) $dict['XRefStm'] );
      }
      return isset( $dict['Prev'] ) ? (int) $dict['Prev'] : null;
    }
    // Cross-reference stream (PDF 1.5+).
    list( $dict, $data ) = $this->parse_object( $at );
    $dict = self::dict( $dict );
    $this->trailer += $dict;
    $rows  = $this->decode( $dict, $data );
    $w     = array_map( 'intval', self::array_items( $dict['W'] ) );
    $index = isset( $dict['Index'] ) ? array_map( 'intval', self::array_items( $dict['Index'] ) ) : array( 0, (int) $dict['Size'] );
    $size  = array_sum( $w );
    $pos   = 0;
    for ( $k = 0; $k + 1 < count( $index ); $k += 2 ) {
      for ( $n = 0; $n < $index[ $k + 1 ]; $n++, $pos += $size ) {
        $f = array();
        $o = $pos;
        foreach ( $w as $width ) {
          $v = 0;
          for ( $b = 0; $b < $width; $b++ ) {
            $v = ( $v << 8 ) | ord( $rows[ $o++ ] );
          }
          $f[] = $v;
        }
        $type = $w[0] ? $f[0] : 1;
        $num  = $index[ $k ] + $n;
        if ( isset( $this->offsets[ $num ] ) || isset( $this->packed[ $num ] ) ) {
          continue;
        }
        if ( 1 === $type ) {
          $this->offsets[ $num ] = $f[1];
        } elseif ( 2 === $type ) {
          $this->packed[ $num ] = array( $f[1], $f[2] );
        }
      }
    }
    return isset( $dict['Prev'] ) ? (int) $dict['Prev'] : null;
  }

  /**
   * The object at a byte offset: array( dictionary or value text, stream bytes or null ).
   */
  private function parse_object( $at ) {
    if ( ! preg_match( '/\G\s*\d+\s+\d+\s+obj\s*/', $this->pdf, $m, 0, $at ) ) {
      throw new Exception( 'bad object at ' . $at );
    }
    $start = $at + strlen( $m[0] );
    $end   = self::value_end( $this->pdf, $start );
    $value = substr( $this->pdf, $start, $end - $start );
    if ( preg_match( '/\G\s*stream\r?\n/', $this->pdf, $s, 0, $end ) ) {
      $dict   = self::dict( $value );
      $length = $dict['Length'];
      if ( preg_match( '/^(\d+)\s+\d+\s+R$/', $length, $r ) ) {
        $length = trim( $this->get( (int) $r[1] ) );
      }
      return array( $value, substr( $this->pdf, $end + strlen( $s[0] ), (int) $length ) );
    }
    return array( $value, null );
  }

  /**
   * The object with this number: array( value text, stream bytes or null ).
   */
  public function raw( $num ) {
    if ( isset( $this->offsets[ $num ] ) ) {
      return $this->parse_object( $this->offsets[ $num ] );
    }
    if ( isset( $this->packed[ $num ] ) ) {
      list( $stream, $index ) = $this->packed[ $num ];
      if ( ! isset( $this->streams[ $stream ] ) ) {
        list( $dict, $data ) = $this->raw( $stream );
        $dict = self::dict( $dict );
        $data = $this->decode( $dict, $data );
        $head = preg_split( '/\s+/', trim( substr( $data, 0, (int) $dict['First'] ) ) );
        $this->streams[ $stream ] = array( $head, substr( $data, (int) $dict['First'] ) );
      }
      list( $head, $body ) = $this->streams[ $stream ];
      $from = (int) $head[ 2 * $index + 1 ];
      $to   = isset( $head[ 2 * $index + 3 ] ) ? (int) $head[ 2 * $index + 3 ] : strlen( $body );
      return array( trim( substr( $body, $from, $to - $from ) ), null );
    }
    return array( 'null', null );
  }

  private function get( $num ) {
    return $this->raw( $num )[0];
  }

  /**
   * Follows a reference: the value text of "12 0 R", or the text itself.
   */
  private function resolve( $value ) {
    return preg_match( '/^(\d+)\s+\d+\s+R$/', trim( $value ), $r ) ? $this->get( (int) $r[1] ) : $value;
  }

  private function decode( $dict, $data ) {
    $filter = isset( $dict['Filter'] ) ? $dict['Filter'] : '';
    if ( false !== strpos( $filter, 'FlateDecode' ) ) {
      $out  = @gzuncompress( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some files have raw deflate; try that next.
      $data = false !== $out ? $out : (string) @gzinflate( substr( $data, 2 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    } elseif ( '' !== $filter ) {
      throw new Exception( 'filter ' . $filter );
    }
    $parms = isset( $dict['DecodeParms'] ) ? self::dict( $dict['DecodeParms'] ) : array();
    if ( isset( $parms['Predictor'] ) && (int) $parms['Predictor'] >= 10 ) {
      $cols = isset( $parms['Columns'] ) ? (int) $parms['Columns'] : 1;
      $out  = '';
      $prev = str_repeat( "\0", $cols );
      foreach ( str_split( $data, $cols + 1 ) as $row ) {
        $type = ord( $row[0] );
        $row  = substr( $row, 1 );
        $cur  = '';
        for ( $i = 0; $i < strlen( $row ); $i++ ) {
          $left = $i > 0 ? ord( $cur[ $i - 1 ] ) : 0;
          $up   = ord( $prev[ $i ] );
          $diag = $i > 0 ? ord( $prev[ $i - 1 ] ) : 0;
          $x    = ord( $row[ $i ] );
          if ( 1 === $type ) {
            $x += $left;
          } elseif ( 2 === $type ) {
            $x += $up;
          } elseif ( 3 === $type ) {
            $x += ( $left + $up ) >> 1;
          } elseif ( 4 === $type ) {
            $p  = $left + $up - $diag;
            $pa = abs( $p - $left );
            $pb = abs( $p - $up );
            $pc = abs( $p - $diag );
            $x += ( $pa <= $pb && $pa <= $pc ) ? $left : ( $pb <= $pc ? $up : $diag );
          }
          $cur .= chr( $x & 255 );
        }
        $out .= $cur;
        $prev = str_pad( $cur, $cols, "\0" );
      }
      $data = $out;
    }
    return $data;
  }

  // ---- PDF syntax helpers --------------------------------------------------------------------

  /**
   * Where the PDF value starting at $at ends (after a dictionary, array, string or plain token).
   */
  public static function value_end( $s, $at ) {
    $len = strlen( $s );
    while ( $at < $len && ctype_space( $s[ $at ] ) ) {
      $at++;
    }
    if ( $at >= $len ) {
      return $len;
    }
    $c = $s[ $at ];
    if ( '(' === $c ) {
      return self::string_end( $s, $at );
    }
    if ( '[' === $c || '<<' === substr( $s, $at, 2 ) ) {
      // Dictionary or array: count nesting, skipping strings, hex strings and comments.
      $depth = 0;
      for ( $i = $at; $i < $len; $i++ ) {
        $c = $s[ $i ];
        if ( '(' === $c ) {
          $i = self::string_end( $s, $i ) - 1;
        } elseif ( '%' === $c ) {
          $i = strcspn( $s, "\r\n", $i ) + $i;
        } elseif ( '<' === $c && '<' === ( $s[ $i + 1 ] ?? '' ) ) {
          $depth++;
          $i++;
        } elseif ( '>' === $c && '>' === ( $s[ $i + 1 ] ?? '' ) ) {
          $depth--;
          $i++;
          if ( 0 === $depth ) {
            return $i + 1;
          }
        } elseif ( '<' === $c ) {
          $i = (int) strpos( $s, '>', $i );
        } elseif ( '[' === $c ) {
          $depth++;
        } elseif ( ']' === $c ) {
          $depth--;
          if ( 0 === $depth ) {
            return $i + 1;
          }
        }
      }
      return $len;
    }
    if ( '<' === $c ) {
      return (int) strpos( $s, '>', $at ) + 1;
    }
    if ( preg_match( '/\G\d+\s+\d+\s+R\b/', $s, $m, 0, $at ) ) {
      return $at + strlen( $m[0] );
    }
    // Name, number, true, false, null: up to the next space or delimiter.
    $i = $at + 1;
    while ( $i < $len && ! ctype_space( $s[ $i ] ) && false === strpos( '/[]<>()%', $s[ $i ] ) ) {
      $i++;
    }
    return $i;
  }

  private static function string_end( $s, $at ) {
    $len = strlen( $s );
    for ( $p = 0, $i = $at; $i < $len; $i++ ) {
      if ( '\\' === $s[ $i ] ) {
        $i++;
      } elseif ( '(' === $s[ $i ] ) {
        $p++;
      } elseif ( ')' === $s[ $i ] && 0 === --$p ) {
        return $i + 1;
      }
    }
    return $len;
  }

  /**
   * Top-level entries of a dictionary text, in order: key => value text.
   */
  public static function dict( $text ) {
    $text = trim( $text );
    $out  = array();
    if ( '<<' !== substr( $text, 0, 2 ) ) {
      return $out;
    }
    $text = substr( $text, 0, self::value_end( $text, 0 ) );
    $i   = 2;
    $end = strlen( $text ) - 2;
    while ( $i < $end ) {
      if ( ! preg_match( '/\G\s*\/([^\s\/\[\]<>()]+)/', $text, $m, 0, $i ) ) {
        break;
      }
      $i            += strlen( $m[0] );
      $v_end         = self::value_end( $text, $i );
      $out[ $m[1] ]  = trim( substr( $text, $i, $v_end - $i ) );
      $i             = $v_end;
    }
    return $out;
  }

  public static function array_items( $text ) {
    $text  = trim( $text );
    $items = array();
    for ( $i = 1, $end = strlen( $text ) - 1; $i < $end; ) {
      while ( $i < $end && ctype_space( $text[ $i ] ) ) {
        $i++;
      }
      if ( $i >= $end ) {
        break;
      }
      $v_end   = self::value_end( $text, $i );
      $items[] = trim( substr( $text, $i, $v_end - $i ) );
      $i       = max( $v_end, $i + 1 );
    }
    return $items;
  }

  private static function build_dict( $entries ) {
    $out = '<<';
    foreach ( $entries as $k => $v ) {
      $out .= '/' . $k . ' ' . $v . ' ';
    }
    return $out . '>>';
  }

  // ---- Pages ---------------------------------------------------------------------------------

  /**
   * Leaf pages in order: array( object number, inherited Resources/MediaBox/CropBox/Rotate ).
   */
  public function pages() {
    $catalog = self::dict( $this->resolve( $this->trailer['Root'] ) );
    $list    = array();
    $this->walk( $catalog['Pages'], array(), $list, 0 );
    return $list;
  }

  private function walk( $ref, $inherited, &$list, $depth ) {
    if ( $depth > 50 || ! preg_match( '/^(\d+)\s+\d+\s+R$/', trim( $ref ), $r ) ) {
      return;
    }
    $node = self::dict( $this->get( (int) $r[1] ) );
    foreach ( array( 'Resources', 'MediaBox', 'CropBox', 'Rotate' ) as $key ) {
      if ( isset( $node[ $key ] ) ) {
        $inherited[ $key ] = $node[ $key ];
      }
    }
    if ( isset( $node['Kids'] ) ) {
      foreach ( self::array_items( $this->resolve( $node['Kids'] ) ) as $kid ) {
        $this->walk( $kid, $inherited, $list, $depth + 1 );
      }
    } else {
      $list[] = array( (int) $r[1], $inherited );
    }
  }

  public function page_count() {
    return count( $this->pages() );
  }

  // ---- Writing -------------------------------------------------------------------------------

  /**
   * The preview copy: pages $start to $start + $count - 1 are real, every other page is a placeholder.
   *
   * @param int    $start First real page (1-based).
   * @param int    $count Number of real pages.
   * @param string $note  Text on the placeholder pages (plain ASCII).
   *
   * @return string PDF bytes.
   */
  public function build( $start, $count, $note ) {
    $pages  = $this->pages();
    $leaves = array();
    foreach ( $pages as $p ) {
      $leaves[ $p[0] ] = true;
    }
    $window = array();
    foreach ( $pages as $i => $p ) {
      if ( $i + 1 >= $start && $i + 1 < $start + $count ) {
        $window[ $p[0] ] = true;
      }
    }
    $out  = array( 1 => '', 2 => '', 3 => '<</Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding>>' );
    $map  = array();
    $next = 4;
    // Copies an object and everything it refers to; references to pages outside the window become null.
    $copy = function ( $num ) use ( &$copy, &$out, &$map, &$next, $leaves, $window ) {
      if ( isset( $leaves[ $num ] ) && ! isset( $window[ $num ] ) ) {
        return null;
      }
      if ( isset( $map[ $num ] ) ) {
        return $map[ $num ];
      }
      $new          = $next++;
      $map[ $num ]  = $new;
      $out[ $new ]  = '';
      list( $value, $data ) = $this->raw( $num );
      $value = $this->renumber( $value, $copy );
      if ( null !== $data ) {
        $entries           = self::dict( $value );
        $entries['Length'] = strlen( $data );
        $value             = self::build_dict( $entries ) . "\nstream\n" . $data . "\nendstream";
      }
      $out[ $new ] = $value;
      return $new;
    };
    $kids = array();
    foreach ( $pages as $i => $p ) {
      list( $num, $inherited ) = $p;
      if ( isset( $window[ $num ] ) ) {
        $page = self::dict( $this->get( $num ) ) + $inherited;
        unset( $page['Parent'] );
        $new          = $next++;
        $map[ $num ]  = $new;
        $out[ $new ]  = '';
        $entries      = array();
        foreach ( $page as $k => $v ) {
          $entries[ $k ] = $this->renumber( $v, $copy );
        }
        $entries['Parent'] = '2 0 R';
        $out[ $new ]       = self::build_dict( $entries );
        $kids[]            = $new . ' 0 R';
        continue;
      }
      $box = isset( $inherited['MediaBox'] ) ? $this->resolve( $inherited['MediaBox'] ) : '[0 0 612 792]';
      $own = self::dict( $this->get( $num ) );
      $box = isset( $own['MediaBox'] ) ? $this->resolve( $own['MediaBox'] ) : $box;
      $dim = array_map( 'floatval', self::array_items( $box ) ) + array( 0, 0, 612, 792 );
      $x   = max( 20, ( $dim[0] + $dim[2] ) / 2 - strlen( $note ) * 4.5 );
      $y   = ( $dim[1] + $dim[3] ) / 2;
      $txt = 'BT /F1 16 Tf 0.35 g ' . round( $x, 1 ) . ' ' . round( $y, 1 ) . ' Td (' . addcslashes( $note, '()\\' ) . ') Tj ET';
      $c           = $next++;
      $out[ $c ]   = '<</Length ' . strlen( $txt ) . ">>\nstream\n" . $txt . "\nendstream";
      $p           = $next++;
      $out[ $p ]   = '<</Type /Page /Parent 2 0 R /MediaBox ' . $box . ' /Resources <</Font <</F1 3 0 R>>>> /Contents ' . $c . ' 0 R>>';
      $kids[]      = $p . ' 0 R';
    }
    $out[1] = '<</Type /Catalog /Pages 2 0 R>>';
    $out[2] = '<</Type /Pages /Count ' . count( $kids ) . ' /Kids [' . implode( ' ', $kids ) . ']>>';
    ksort( $out );
    $pdf     = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
    $offsets = array();
    foreach ( $out as $num => $value ) {
      $offsets[ $num ] = strlen( $pdf );
      $pdf            .= $num . " 0 obj\n" . $value . "\nendobj\n";
    }
    $xref = strlen( $pdf );
    $pdf .= 'xref' . "\n0 " . ( count( $out ) + 1 ) . "\n0000000000 65535 f \n";
    foreach ( $offsets as $offset ) {
      $pdf .= sprintf( "%010d 00000 n \n", $offset );
    }
    return $pdf . 'trailer' . "\n<</Size " . ( count( $out ) + 1 ) . " /Root 1 0 R>>\nstartxref\n" . $xref . "\n%%EOF\n";
  }

  /**
   * Rewrites "n g R" references in a value (never inside strings), copying each referenced object.
   */
  private function renumber( $value, $copy ) {
    $parts = preg_split( '/(\((?:\\\\.|[^\\\\()])*\))/s', $value, -1, PREG_SPLIT_DELIM_CAPTURE );
    foreach ( $parts as $i => $part ) {
      if ( 1 === $i % 2 ) {
        continue;
      }
      $parts[ $i ] = preg_replace_callback(
        '/(?<![\w.])(\d+)\s+(\d+)\s+R\b/',
        function ( $m ) use ( $copy ) {
          $new = $copy( (int) $m[1] );
          return null === $new ? 'null' : $new . ' 0 R';
        },
        $part
      );
    }
    return implode( '', $parts );
  }

  // ---- Files ---------------------------------------------------------------------------------

  /**
   * Number of pages of a PDF file, remembered per file version; 0 when it cannot be read.
   */
  public static function count_pages( $path ) {
    $key    = 'cb_pages_' . md5( $path . '|' . @filemtime( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    $cached = get_transient( $key );
    if ( false !== $cached ) {
      return (int) $cached;
    }
    try {
      $count = ( new self( (string) file_get_contents( $path ) ) )->page_count(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    } catch ( Throwable $e ) {
      $count = 0;
    }
    set_transient( $key, $count, MONTH_IN_SECONDS );
    return $count;
  }

  /**
   * Path of the cached preview copy for a window, built when missing; '' when the PDF cannot be read.
   */
  public static function file( $path, $start, $count, $note ) {
    $dir = trailingslashit( dirname( $path ) ) . 'cb-windows';
    if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
      return '';
    }
    if ( ! file_exists( $dir . '/index.php' ) ) {
      file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    }
    $file = $dir . '/' . md5( $path . '|' . @filemtime( $path ) . '|' . $start . '|' . $count . '|' . $note ) . '.pdf'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    if ( ! is_file( $file ) ) {
      try {
        $pdf = ( new self( (string) file_get_contents( $path ) ) )->build( $start, $count, $note ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
      } catch ( Throwable $e ) {
        return '';
      }
      file_put_contents( $file, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    }
    return $file;
  }
}
