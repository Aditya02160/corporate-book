<?php
/**
 * Free preview from any page: the preview copy keeps every page, but only the window is real.
 */
class WindowTest extends CB_Unit_Test {

  /**
   * A PDF with $n pages ("Page 1".."Page n"). $compact writes "<</Type/Page...>>" without spaces; $packed puts
   * the dictionaries in a compressed object stream with a cross-reference stream ($predictor adds PNG "Up" rows).
   */
  private static function make( $n, $compact = false, $packed = false, $predictor = false ) {
    $sp   = $compact ? '' : ' ';
    $objs = array( 1 => "<</Type{$sp}/Catalog{$sp}/Pages 2 0 R>>", 3 => '<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>' );
    $kids = array();
    for ( $i = 1; $i <= $n; $i++ ) {
      $page = 2 + 2 * $i;
      $text = "BT /F1 24 Tf 72 700 Td (Page $i) Tj ET";
      // Page 4 links to page 9: that page must not leak into a window that does not include it.
      $annots        = 4 === $i ? '/Annots [<</Type/Annot/Subtype/Link/Rect[0 0 10 10]/Dest[' . ( 2 + 2 * 9 ) . ' 0 R/Fit]>>]' : '';
      $objs[ $page ] = "<</Type{$sp}/Page{$sp}/Parent 2 0 R{$sp}/Contents " . ( $page + 1 ) . " 0 R$annots>>";
      $objs[ $page + 1 ] = "<</Length " . strlen( $text ) . ">>\nstream\n$text\nendstream";
      $kids[] = "$page 0 R";
    }
    // MediaBox and Resources are inherited from the page tree.
    $objs[2] = "<</Type{$sp}/Pages{$sp}/Kids[" . implode( ' ', $kids ) . "]{$sp}/Count $n{$sp}/MediaBox[0 0 400 600]{$sp}/Resources<</Font<</F1 3 0 R>>>>>>";
    ksort( $objs );
    $pdf  = "%PDF-1.5\n";
    $offs = array();
    if ( ! $packed ) {
      foreach ( $objs as $num => $body ) {
        $offs[ $num ] = strlen( $pdf );
        $pdf         .= "$num 0 obj\n$body\nendobj\n";
      }
      $x    = strlen( $pdf );
      $pdf .= 'xref' . "\n0 " . ( max( array_keys( $objs ) ) + 1 ) . "\n0000000000 65535 f \n";
      for ( $k = 1; $k <= max( array_keys( $objs ) ); $k++ ) {
        $pdf .= sprintf( "%010d 00000 n \n", $offs[ $k ] );
      }
      return $pdf . "trailer\n<</Size " . ( max( array_keys( $objs ) ) + 1 ) . "/Root 1 0 R>>\nstartxref\n$x\n%%EOF";
    }
    // Dictionaries go into object stream number $os; streams stay plain objects.
    $os     = max( array_keys( $objs ) ) + 1;
    $xs     = $os + 1;
    $inside = array();
    foreach ( $objs as $num => $body ) {
      if ( false === strpos( $body, 'stream' ) ) {
        $inside[ $num ] = $body;
      } else {
        $offs[ $num ] = strlen( $pdf );
        $pdf         .= "$num 0 obj\n$body\nendobj\n";
      }
    }
    $head = '';
    $data = '';
    $idx  = array();
    foreach ( array_keys( $inside ) as $k => $num ) {
      $head       .= $num . ' ' . strlen( $data ) . ' ';
      $data       .= $inside[ $num ] . "\n";
      $idx[ $num ] = $k;
    }
    $body         = gzcompress( $head . $data );
    $offs[ $os ]  = strlen( $pdf );
    $pdf         .= "$os 0 obj\n<</Type/ObjStm/N " . count( $inside ) . '/First ' . strlen( $head ) . '/Filter/FlateDecode/Length ' . strlen( $body ) . ">>\nstream\n$body\nendstream\nendobj\n";
    $offs[ $xs ]  = strlen( $pdf );
    $rows         = array( array( 0, 0, 0 ) );
    for ( $k = 1; $k <= $xs; $k++ ) {
      $rows[] = isset( $inside[ $k ] ) ? array( 2, $os, $idx[ $k ] ) : array( 1, $offs[ $k ], 0 );
    }
    $raw  = '';
    $prev = str_repeat( "\0", 5 );
    foreach ( $rows as $r ) {
      $row = chr( $r[0] ) . pack( 'N', $r[1] ) . '';
      $row = chr( $r[0] ) . substr( pack( 'N', $r[1] ), 1 ) . chr( $r[2] );
      if ( $predictor ) {
        $up = '';
        for ( $b = 0; $b < 5; $b++ ) {
          $up .= chr( ( ord( $row[ $b ] ) - ord( $prev[ $b ] ) + 256 ) % 256 );
        }
        $prev = $row;
        $raw .= chr( 2 ) . $up;
      } else {
        $raw .= $row;
      }
    }
    $stream = gzcompress( $raw );
    $parms  = $predictor ? '/DecodeParms<</Predictor 12/Columns 5>>' : '';
    $pdf   .= "$xs 0 obj\n<</Type/XRef/Size " . ( $xs + 1 ) . "/W[1 3 1]/Root 1 0 R/Filter/FlateDecode$parms/Length " . strlen( $stream ) . ">>\nstream\n$stream\nendstream\nendobj\n";
    return $pdf . "startxref\n" . $offs[ $xs ] . "\n%%EOF";
  }

  /** Text of each page of a PDF. */
  private static function texts( $pdf ) {
    $w   = new Corporate_Book_Window( $pdf );
    $out = array();
    foreach ( $w->pages() as $p ) {
      $page = Corporate_Book_Window::dict( $w->raw( $p[0] )[0] );
      preg_match( '/^(\d+)/', $page['Contents'], $m );
      $out[] = $w->raw( (int) $m[1] )[1];
    }
    return $out;
  }

  #[PHPUnit\Framework\Attributes\DataProvider('kinds')]
  public function test_window_keeps_every_page_but_only_the_window_is_real( $compact, $packed, $predictor ) {
    $source = self::make( 10, $compact, $packed, $predictor );
    $this->assertSame( 10, ( new Corporate_Book_Window( $source ) )->page_count() );
    $copy  = ( new Corporate_Book_Window( $source ) )->build( 4, 2, 'Subscribe to read this page' );
    $texts = self::texts( $copy );
    $this->assertCount( 10, $texts, 'same number of pages as the book' );
    $this->assertStringContainsString( '(Page 4)', $texts[3] );
    $this->assertStringContainsString( '(Page 5)', $texts[4] );
    foreach ( array( 0, 2, 5, 9 ) as $i ) {
      $this->assertStringContainsString( 'Subscribe to read this page', $texts[ $i ] );
    }
    $this->assertStringNotContainsString( '(Page 9)', $copy, 'a link to a page outside the window does not bring that page along' );
    $this->assertStringNotContainsString( '(Page 1)', $copy );
    $this->assertMatchesRegularExpression( '/MediaBox\s*\[0 0 400 600\]/', $copy, 'inherited page size is kept' );
  }

  public static function kinds() {
    return array(
      'classic'                  => array( false, false, false ),
      'compact syntax'           => array( true, false, false ),
      'object and xref streams'  => array( true, true, false ),
      'xref stream + predictor'  => array( false, true, true ),
    );
  }

  public function test_window_at_the_end_and_beyond() {
    $source = self::make( 6 );
    $texts  = self::texts( ( new Corporate_Book_Window( $source ) )->build( 6, 3, 'Locked' ) );
    $this->assertCount( 6, $texts );
    $this->assertStringContainsString( '(Page 6)', $texts[5] );
    $this->assertStringContainsString( 'Locked', $texts[4] );
  }

  public function test_unreadable_files_are_refused() {
    $this->expectException( Exception::class );
    new Corporate_Book_Window( 'not a pdf' );
  }

  public function test_encrypted_files_are_refused() {
    $this->expectException( Exception::class );
    new Corporate_Book_Window( str_replace( '/Root 1 0 R>>', '/Root 1 0 R/Encrypt 99 0 R>>', self::make( 2 ) ) );
  }
}
