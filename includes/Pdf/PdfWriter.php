<?php
/**
 * A small, self-contained PDF writer.
 *
 * Deliberately not a general HTML-to-PDF engine. The Mission Report is a
 * fixed-layout form, and placing text and rules at coordinates reproduces that
 * far more faithfully than reflowing HTML — while keeping the plugin free of a
 * multi-megabyte vendored dependency.
 *
 * Scope: the 14 standard PDF fonts (no embedding needed, so no font files ship
 * with the plugin), text with word wrap, lines, rectangles and page breaks.
 * That is everything the report layout uses.
 *
 * Coordinates are given from the TOP-left of the page in points, because that
 * is how the source form's geometry is expressed. They are converted to PDF's
 * bottom-left origin on the way out.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Pdf;

defined( 'ABSPATH' ) || exit;

final class PdfWriter {

	public const FONT_REGULAR = 'F1';
	public const FONT_BOLD    = 'F2';

	/** US Letter, matching the source form's 612x792pt media box. */
	private float $pageWidth;
	private float $pageHeight;

	/** @var string[] Finished page content streams. */
	private array $pages = [];

	private string $buffer = '';

	private string $font = self::FONT_REGULAR;

	private float $fontSize = 10.0;

	/** @var array<string, array<int, int>> Glyph widths per font, in 1/1000 em. */
	private static array $widths = [];

	public function __construct( float $width = 612.0, float $height = 792.0 ) {
		$this->pageWidth  = $width;
		$this->pageHeight = $height;

		self::loadWidths();
		$this->addPage();
	}

	public function pageWidth(): float {
		return $this->pageWidth;
	}

	public function pageHeight(): float {
		return $this->pageHeight;
	}

	public function pageCount(): int {
		return count( $this->pages ) + ( '' === $this->buffer ? 0 : 1 );
	}

	public function addPage(): void {
		if ( '' !== $this->buffer ) {
			$this->pages[] = $this->buffer;
		}

		$this->buffer = '';
	}

	public function setFont( string $font, float $size ): void {
		$this->font     = $font;
		$this->fontSize = $size;
	}

	public function currentFontSize(): float {
		return $this->fontSize;
	}

	/**
	 * Fill colour for text and filled shapes. Components are 0–255.
	 */
	public function setFillColor( int $r, int $g, int $b ): void {
		$this->buffer .= sprintf( "%.3F %.3F %.3F rg\n", $r / 255, $g / 255, $b / 255 );
	}

	public function setDrawColor( int $r, int $g, int $b ): void {
		$this->buffer .= sprintf( "%.3F %.3F %.3F RG\n", $r / 255, $g / 255, $b / 255 );
	}

	public function setLineWidth( float $width ): void {
		$this->buffer .= sprintf( "%.2F w\n", $width );
	}

	/**
	 * Draw a single line of text. $y is the text baseline, from the page top.
	 */
	public function text( float $x, float $y, string $text ): void {
		if ( '' === $text ) {
			return;
		}

		$this->buffer .= sprintf(
			"BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
			$this->font,
			$this->fontSize,
			$x,
			$this->pageHeight - $y,
			self::escape( $text )
		);
	}

	/**
	 * Text right-aligned to $x.
	 */
	public function textRight( float $x, float $y, string $text ): void {
		$this->text( $x - $this->widthOf( $text ), $y, $text );
	}

	/**
	 * Text centred on $x.
	 */
	public function textCenter( float $x, float $y, string $text ): void {
		$this->text( $x - ( $this->widthOf( $text ) / 2 ), $y, $text );
	}

	/**
	 * Word-wrapped text inside a column.
	 *
	 * @return float The y position after the final line.
	 */
	public function textWrapped( float $x, float $y, float $width, string $text, ?float $leading = null ): float {
		$leading ??= $this->fontSize * 1.28;

		foreach ( $this->wrap( $text, $width ) as $line ) {
			$this->text( $x, $y, $line );
			$y += $leading;
		}

		return $y;
	}

	/**
	 * Split text into lines that fit within $width.
	 *
	 * @return string[]
	 */
	public function wrap( string $text, float $width ): array {
		$lines = [];

		// Honour explicit newlines, then wrap each paragraph.
		foreach ( preg_split( '/\R/', $text ) ?: [] as $paragraph ) {
			$paragraph = trim( $paragraph );

			if ( '' === $paragraph ) {
				$lines[] = '';
				continue;
			}

			$current = '';

			foreach ( explode( ' ', $paragraph ) as $word ) {
				$candidate = '' === $current ? $word : $current . ' ' . $word;

				if ( $this->widthOf( $candidate ) <= $width || '' === $current ) {
					$current = $candidate;
					continue;
				}

				$lines[] = $current;
				$current = $word;
			}

			if ( '' !== $current ) {
				$lines[] = $current;
			}
		}

		return $lines;
	}

	/**
	 * Width of a string at the current font and size, in points.
	 */
	public function widthOf( string $text ): float {
		$table = self::$widths[ $this->font ] ?? self::$widths[ self::FONT_REGULAR ];
		$total = 0;
		$text  = self::toWinAnsi( $text );
		$len   = strlen( $text );

		for ( $i = 0; $i < $len; $i++ ) {
			$code   = ord( $text[ $i ] );
			$total += $table[ $code ] ?? 556;
		}

		return $total * $this->fontSize / 1000;
	}

	public function line( float $x1, float $y1, float $x2, float $y2 ): void {
		$this->buffer .= sprintf(
			"%.2F %.2F m %.2F %.2F l S\n",
			$x1,
			$this->pageHeight - $y1,
			$x2,
			$this->pageHeight - $y2
		);
	}

	/**
	 * @param string $style 'S' stroke, 'F' fill, 'B' fill then stroke.
	 */
	public function rect( float $x, float $y, float $width, float $height, string $style = 'S' ): void {
		$op = match ( $style ) {
			'F'     => 'f',
			'B'     => 'B',
			default => 'S',
		};

		$this->buffer .= sprintf(
			"%.2F %.2F %.2F %.2F re %s\n",
			$x,
			$this->pageHeight - $y - $height,
			$width,
			$height,
			$op
		);
	}

	/**
	 * Assemble the finished document.
	 */
	public function output(): string {
		$this->addPage();

		$objects   = [];
		$page_count = count( $this->pages );

		// 1 catalog, 2 pages, then per page: page object + content stream,
		// then the two fonts.
		$first_page_obj = 3;
		$font_regular   = $first_page_obj + ( $page_count * 2 );
		$font_bold      = $font_regular + 1;

		$kids = [];

		for ( $i = 0; $i < $page_count; $i++ ) {
			$kids[] = ( $first_page_obj + ( $i * 2 ) ) . ' 0 R';
		}

		$objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
		$objects[2] = sprintf(
			'<< /Type /Pages /Kids [%s] /Count %d >>',
			implode( ' ', $kids ),
			$page_count
		);

		foreach ( $this->pages as $i => $content ) {
			$page_obj    = $first_page_obj + ( $i * 2 );
			$content_obj = $page_obj + 1;

			$objects[ $page_obj ] = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /%s %d 0 R /%s %d 0 R >> >> /Contents %d 0 R >>',
				$this->pageWidth,
				$this->pageHeight,
				self::FONT_REGULAR,
				$font_regular,
				self::FONT_BOLD,
				$font_bold,
				$content_obj
			);

			$objects[ $content_obj ] = sprintf(
				"<< /Length %d >>\nstream\n%s\nendstream",
				strlen( $content ),
				$content
			);
		}

		$objects[ $font_regular ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[ $font_bold ]    = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		ksort( $objects );

		$pdf     = "%PDF-1.4\n";
		$offsets = [];

		foreach ( $objects as $number => $body ) {
			$offsets[ $number ] = strlen( $pdf );
			$pdf               .= "{$number} 0 obj\n{$body}\nendobj\n";
		}

		$xref_offset = strlen( $pdf );
		$max         = max( array_keys( $objects ) );

		$pdf .= "xref\n0 " . ( $max + 1 ) . "\n";
		$pdf .= "0000000000 65535 f \n";

		for ( $i = 1; $i <= $max; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] ?? 0 );
		}

		$pdf .= sprintf(
			"trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF",
			$max + 1,
			$xref_offset
		);

		return $pdf;
	}

	/**
	 * Escape the three characters that are special inside a PDF string.
	 */
	private static function escape( string $text ): string {
		$text = self::toWinAnsi( $text );

		return str_replace( [ '\\', '(', ')', "\r" ], [ '\\\\', '\\(', '\\)', '' ], $text );
	}

	/**
	 * The standard fonts are declared with WinAnsiEncoding, so text has to be
	 * converted out of UTF-8. Characters with no WinAnsi equivalent become '?'
	 * rather than corrupting the stream.
	 */
	private static function toWinAnsi( string $text ): string {
		// Normalise the typographic characters the form's own wording uses, so
		// they survive as their ASCII equivalents rather than becoming '?'.
		$text = strtr(
			$text,
			[
				'—' => '-',
				'–' => '-',
				'’' => "'",
				'‘' => "'",
				'“' => '"',
				'”' => '"',
				'…' => '...',
				'×' => 'x',
				'≤' => '<=',
				'≥' => '>=',
			]
		);

		if ( function_exists( 'mb_convert_encoding' ) ) {
			$converted = @mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' );

			if ( is_string( $converted ) ) {
				return $converted;
			}
		}

		return (string) preg_replace( '/[^\x20-\x7E]/', '?', $text );
	}

	/**
	 * Glyph widths for Helvetica and Helvetica-Bold.
	 *
	 * Only the printable ASCII range is tabulated; anything outside it falls
	 * back to an average width. The report's content is English, so this keeps
	 * wrapping accurate without shipping a full AFM.
	 */
	private static function loadWidths(): void {
		if ( self::$widths ) {
			return;
		}

		$regular = '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584';
		$bold    = '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584';

		self::$widths[ self::FONT_REGULAR ] = self::expandWidths( $regular );
		self::$widths[ self::FONT_BOLD ]    = self::expandWidths( $bold );
	}

	/**
	 * @return array<int, int> Character code => width.
	 */
	private static function expandWidths( string $csv ): array {
		$widths = [];
		$code   = 32;

		foreach ( explode( ',', $csv ) as $width ) {
			$widths[ $code ] = (int) $width;
			++$code;
		}

		return $widths;
	}
}
