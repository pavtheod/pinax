<?php
/**
 * Ανάγνωση Excel (.xlsx): τιμές όπως εμφανίζονται στο Excel, χρώματα, έντονα, στοίχιση, συγχωνεύσεις.
 *
 * @package Pinax
 */

defined( 'ABSPATH' ) || exit;

class Pinax_Xlsx {

	/** Προεπιλεγμένη παλέτα «indexed» χρωμάτων του Excel (0–63). */
	private static $indexed = array(
		'000000', 'FFFFFF', 'FF0000', '00FF00', '0000FF', 'FFFF00', 'FF00FF', '00FFFF',
		'000000', 'FFFFFF', 'FF0000', '00FF00', '0000FF', 'FFFF00', 'FF00FF', '00FFFF',
		'800000', '008000', '000080', '808000', '800080', '008080', 'C0C0C0', '808080',
		'9999FF', '993366', 'FFFFCC', 'CCFFFF', '660066', 'FF8080', '0066CC', 'CCCCFF',
		'000080', 'FF00FF', 'FFFF00', '00FFFF', '800080', '800000', '008080', '0000FF',
		'00CCFF', 'CCFFFF', 'CCFFCC', 'FFFF99', '99CCFF', 'FF99CC', 'CC99FF', 'FFCC99',
		'3366FF', '33CCCC', '99CC00', 'FFCC00', 'FF9900', 'FF6600', '666699', '969696',
		'003366', '339966', '003300', '333300', '993300', '993366', '333399', '333333',
	);

	/** Ενσωματωμένες μορφές αριθμών του Excel που χρειαζόμαστε. */
	private static $builtin = array(
		0  => 'General',
		1  => '0',
		2  => '0.00',
		3  => '#,##0',
		4  => '#,##0.00',
		9  => '0%',
		10 => '0.00%',
		11 => '0.00E+00',
		12 => '# ?/?',
		13 => '# ??/??',
		14 => 'dd/mm/yyyy',
		15 => 'd-mmm-yy',
		16 => 'd-mmm',
		17 => 'mmm-yy',
		18 => 'h:mm AM/PM',
		19 => 'h:mm:ss AM/PM',
		20 => 'h:mm',
		21 => 'h:mm:ss',
		22 => 'dd/mm/yyyy h:mm',
		37 => '#,##0 ;(#,##0)',
		38 => '#,##0 ;[Red](#,##0)',
		39 => '#,##0.00;(#,##0.00)',
		40 => '#,##0.00;[Red](#,##0.00)',
		45 => 'mm:ss',
		46 => '[h]:mm:ss',
		47 => 'mmss.0',
		48 => '##0.0E+0',
		49 => '@',
	);

	/**
	 * @return array|WP_Error Φύλλο (δείτε Pinax_Reader).
	 */
	public static function read( $path ) {
		$zip = Pinax_Reader::open_zip( $path, 'xl/workbook.xml' );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}

		$shared = self::shared_strings( $zip );
		if ( is_wp_error( $shared ) ) {
			$zip->close();
			return $shared;
		}
		$theme  = self::theme_colors( $zip );
		$styles = self::styles( $zip, $theme );
		$wb     = self::workbook_info( $zip );
		$xml    = '' !== $wb['path'] ? Pinax_Reader::zip_xml( $zip, $wb['path'] ) : false;
		$zip->close();
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$doc = Pinax_Reader::load_xml( $xml );
		unset( $xml );
		if ( ! $doc ) {
			return new WP_Error( 'pinax_sheet', 'Δεν βρέθηκε ή δεν διαβάζεται το φύλλο του Excel.' );
		}
		self::$date1904 = $wb['date1904'];

		// Κρυφές στήλες (π.χ. βοηθητικές) δεν εμφανίζονται.
		$hidden_cols = array();
		foreach ( self::find( $doc->documentElement, 'col' ) as $col ) {
			if ( in_array( $col->getAttribute( 'hidden' ), array( '1', 'true' ), true ) ) {
				$min = max( 1, (int) $col->getAttribute( 'min' ) );
				$max = min( Pinax_Reader::MAX_COLS, (int) $col->getAttribute( 'max' ) );
				for ( $i = $min; $i <= $max; $i++ ) {
					$hidden_cols[ $i - 1 ] = true;
				}
			}
		}

		$grid        = array();
		$merges      = array();
		$row_no      = 0;
		$hidden_rows = 0;
		$hidden_data = array(); // κρυφές στήλες που είχαν κείμενο
		$fill_only   = array(); // κενά κελιά με χρώμα
		$filled      = 0;

		foreach ( self::find( $doc->documentElement, 'sheetData' ) as $sheet_data ) {
			foreach ( self::kids( $sheet_data, 'row' ) as $row ) {
				$r      = $row->hasAttribute( 'r' ) ? (int) $row->getAttribute( 'r' ) - 1 : $row_no;
				$row_no = $r + 1;
				if ( $r >= Pinax_Reader::MAX_ROWS ) {
					break;
				}
				$row_hidden = in_array( $row->getAttribute( 'hidden' ), array( '1', 'true' ), true );
				$col_no     = 0;
				$row_has    = false;
				foreach ( self::kids( $row, 'c' ) as $c ) {
					$col    = $c->hasAttribute( 'r' ) ? self::col_index( $c->getAttribute( 'r' ) ) : $col_no;
					$col_no = $col + 1;
					if ( $col >= Pinax_Reader::MAX_COLS ) {
						continue;
					}
					$style = $styles[ (int) $c->getAttribute( 's' ) ] ?? array( 'fmt' => 'General', 'fill' => '', 'b' => 0, 'a' => '' );
					$value = Pinax_Reader::clean( self::cell_value( $c, $shared, $style['fmt'] ) );
					if ( '' === $value ) {
						// Κενό κελί: κρατάμε μόνο το χρώμα του (αν έχει), σε ελαφριά μορφή.
						if ( '' !== $style['fill'] && ! $row_hidden && ! isset( $hidden_cols[ $col ] ) ) {
							$fill_only[ $r ][ $col ] = $style['fill'];
						}
						continue;
					}
					if ( $row_hidden || isset( $hidden_cols[ $col ] ) ) {
						if ( '' !== $value ) {
							if ( $row_hidden ) {
								$row_has = true;
							} else {
								$hidden_data[ $col ] = true;
							}
						}
						continue;
					}
					if ( '' !== $value && ++$filled > Pinax_Reader::MAX_CELLS ) {
						return new WP_Error( 'pinax_big', Pinax_Reader::TOO_BIG );
					}
					// Κρυφές γραμμές αφήνουν κενό, που η ανάλυση αγνοεί.
					$grid[ $r ][ $col ] = Pinax_Reader::cell( $value, $style['fill'], $style['b'], $style['a'] );
				}
				if ( $row_hidden && $row_has ) {
					++$hidden_rows;
				}
			}
		}

		foreach ( self::find( $doc->documentElement, 'mergeCell' ) as $m ) {
			if ( preg_match( '/^\$?([A-Z]+)\$?(\d+):\$?([A-Z]+)\$?(\d+)$/i', $m->getAttribute( 'ref' ), $mm ) ) {
				$r1 = (int) $mm[2] - 1;
				$r2 = (int) $mm[4] - 1;
				$c1 = self::col_index( $mm[1] );
				$c2 = self::col_index( $mm[3] );
				if ( $r1 < Pinax_Reader::MAX_ROWS && $c1 < Pinax_Reader::MAX_COLS ) {
					$merges[] = array( $r1, $c1, min( $r2, Pinax_Reader::MAX_ROWS - 1 ), min( $c2, Pinax_Reader::MAX_COLS - 1 ) );
				}
			}
		}

		// Κενά χρωματισμένα κελιά μόνο μέσα στα όρια του πίνακα (όχι ολόκληρες χρωματισμένες γραμμές/στήλες).
		$max_r = $grid ? max( array_keys( $grid ) ) : -1;
		$max_c = -1;
		foreach ( $grid as $cells_row ) {
			$max_c = max( $max_c, max( array_keys( $cells_row ) ) );
		}
		foreach ( $fill_only as $r => $cols_fill ) {
			if ( $r > $max_r ) {
				continue;
			}
			foreach ( $cols_fill as $c => $fill ) {
				if ( $c <= $max_c && ! isset( $grid[ $r ][ $c ] ) ) {
					$grid[ $r ][ $c ] = Pinax_Reader::cell( '', $fill );
				}
			}
		}
		unset( $fill_only );

		$notes = array();
		if ( $hidden_rows ) {
			$notes[] = sprintf( 'Παραλείφθηκαν %d κρυφές γραμμές του Excel.', $hidden_rows );
		}
		if ( $hidden_data ) {
			$notes[] = sprintf( 'Παραλείφθηκαν %d κρυφές στήλες του Excel.', count( $hidden_data ) );
		}

		return array(
			'grid'   => $grid,
			'merges' => $merges,
			'name'   => $wb['name'],
			'notes'  => $notes,
		);
	}

	/** Αν το βιβλίο εργασίας μετρά ημερομηνίες από το 1904 (παλιά Excel για Mac). */
	private static $date1904 = false;

	/* ------------------------------------------------------------------ */
	/* Βοηθητικά XML                                                      */
	/* ------------------------------------------------------------------ */

	/** Άμεσα παιδιά με το συγκεκριμένο τοπικό όνομα. */
	private static function kids( $node, $name ) {
		$out = array();
		if ( $node ) {
			foreach ( $node->childNodes as $ch ) {
				if ( XML_ELEMENT_NODE === $ch->nodeType && $ch->localName === $name ) {
					$out[] = $ch;
				}
			}
		}
		return $out;
	}

	private static function kid( $node, $name ) {
		$k = self::kids( $node, $name );
		return $k ? $k[0] : null;
	}

	/** Όλοι οι απόγονοι με το συγκεκριμένο τοπικό όνομα (ανεξαρτήτως namespace). */
	private static function find( $node, $name ) {
		return $node ? iterator_to_array( $node->getElementsByTagNameNS( '*', $name ) ) : array();
	}

	private static function col_index( $ref ) {
		$letters = preg_replace( '/[^A-Z]/', '', strtoupper( $ref ) );
		$n       = 0;
		$len     = strlen( $letters );
		for ( $i = 0; $i < $len; $i++ ) {
			$n = $n * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return max( 0, $n - 1 );
	}

	/** Κείμενο ενός <si> ή <is> (απλό ή με μορφοποιημένα τμήματα, χωρίς φωνητικά). */
	private static function rich_text( $node ) {
		$out = '';
		foreach ( $node->childNodes as $ch ) {
			if ( XML_ELEMENT_NODE !== $ch->nodeType ) {
				continue;
			}
			if ( 't' === $ch->localName ) {
				$out .= $ch->textContent;
			} elseif ( 'r' === $ch->localName ) {
				foreach ( self::kids( $ch, 't' ) as $t ) {
					$out .= $t->textContent;
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Δομή βιβλίου εργασίας                                              */
	/* ------------------------------------------------------------------ */

	private static function shared_strings( $zip ) {
		$out = array();
		$xml = Pinax_Reader::zip_xml( $zip, 'xl/sharedStrings.xml' );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}
		$doc = Pinax_Reader::load_xml( $xml );
		if ( $doc ) {
			foreach ( self::kids( $doc->documentElement, 'si' ) as $si ) {
				$out[] = self::rich_text( $si );
			}
		}
		return $out;
	}

	/**
	 * Το πρώτο ορατό φύλλο (διαδρομή, όνομα) και αν οι ημερομηνίες μετρούν από το 1904.
	 */
	private static function workbook_info( $zip ) {
		$info = array(
			'path'     => '',
			'name'     => '',
			'date1904' => false,
		);
		$wb   = Pinax_Reader::load_xml( $zip->getFromName( 'xl/workbook.xml' ) );
		$rels = Pinax_Reader::load_xml( $zip->getFromName( 'xl/_rels/workbook.xml.rels' ) );
		if ( $wb ) {
			foreach ( self::find( $wb->documentElement, 'workbookPr' ) as $pr ) {
				$info['date1904'] = in_array( $pr->getAttribute( 'date1904' ), array( '1', 'true' ), true );
			}
		}
		if ( $wb && $rels ) {
			$targets = array();
			foreach ( self::find( $rels->documentElement, 'Relationship' ) as $rel ) {
				$targets[ $rel->getAttribute( 'Id' ) ] = $rel->getAttribute( 'Target' );
			}
			foreach ( self::find( $wb->documentElement, 'sheet' ) as $sheet ) {
				if ( in_array( $sheet->getAttribute( 'state' ), array( 'hidden', 'veryHidden' ), true ) ) {
					continue;
				}
				$rid = '';
				foreach ( $sheet->attributes as $attr ) {
					if ( 'id' === $attr->localName && $attr->namespaceURI ) {
						$rid = $attr->value;
					}
				}
				if ( isset( $targets[ $rid ] ) ) {
					$target       = $targets[ $rid ];
					$target       = ( 0 === strpos( $target, '/' ) ) ? ltrim( $target, '/' ) : 'xl/' . $target;
					$info['path'] = str_replace( '/./', '/', $target );
					$info['name'] = $sheet->getAttribute( 'name' );
					return $info;
				}
			}
		}
		if ( false !== $zip->locateName( 'xl/worksheets/sheet1.xml' ) ) {
			$info['path'] = 'xl/worksheets/sheet1.xml';
		}
		return $info;
	}

	/** Χρώματα του θέματος με τη σειρά που τα αριθμεί το Excel. */
	private static function theme_colors( $zip ) {
		$defaults = array( 'FFFFFF', '000000', 'E7E6E6', '44546A', '4472C4', 'ED7D31', 'A5A5A5', 'FFC000', '5B9BD5', '70AD47', '0563C1', '954F72' );
		$doc      = Pinax_Reader::load_xml( $zip->getFromName( 'xl/theme/theme1.xml' ) );
		if ( ! $doc ) {
			return $defaults;
		}
		$scheme = self::find( $doc->documentElement, 'clrScheme' );
		if ( ! $scheme ) {
			return $defaults;
		}
		$by_name = array();
		foreach ( $scheme[0]->childNodes as $el ) {
			if ( XML_ELEMENT_NODE !== $el->nodeType ) {
				continue;
			}
			$val = '';
			foreach ( $el->childNodes as $c ) {
				if ( XML_ELEMENT_NODE !== $c->nodeType ) {
					continue;
				}
				if ( 'srgbClr' === $c->localName ) {
					$val = $c->getAttribute( 'val' );
				} elseif ( 'sysClr' === $c->localName ) {
					$val = $c->getAttribute( 'lastClr' );
				}
			}
			$by_name[ $el->localName ] = Pinax_Reader::hex( $val );
		}
		$order = array( 'lt1', 'dk1', 'lt2', 'dk2', 'accent1', 'accent2', 'accent3', 'accent4', 'accent5', 'accent6', 'hlink', 'folHlink' );
		$out   = array();
		foreach ( $order as $i => $name ) {
			$out[ $i ] = ! empty( $by_name[ $name ] ) ? $by_name[ $name ] : $defaults[ $i ];
		}
		return $out;
	}

	/** Μορφή κάθε στυλ κελιού (cellXfs): μορφή αριθμού, γέμισμα, έντονα, στοίχιση. */
	private static function styles( $zip, $theme ) {
		$doc = Pinax_Reader::load_xml( Pinax_Reader::zip_xml( $zip, 'xl/styles.xml' ) );
		if ( ! $doc ) {
			return array();
		}
		$root = $doc->documentElement;

		$fmts = self::$builtin;
		foreach ( self::find( $root, 'numFmt' ) as $nf ) {
			$fmts[ (int) $nf->getAttribute( 'numFmtId' ) ] = $nf->getAttribute( 'formatCode' );
		}

		$fonts = array();
		$fnode = self::kid( $root, 'fonts' );
		foreach ( self::kids( $fnode, 'font' ) as $font ) {
			$b       = self::kid( $font, 'b' );
			$fonts[] = ( $b && ! in_array( $b->getAttribute( 'val' ), array( '0', 'false' ), true ) ) ? 1 : 0;
		}

		$fills = array();
		$flnode = self::kid( $root, 'fills' );
		foreach ( self::kids( $flnode, 'fill' ) as $fill ) {
			$color = '';
			$pf    = self::kid( $fill, 'patternFill' );
			if ( $pf ) {
				$type = $pf->getAttribute( 'patternType' );
				if ( '' !== $type && 'none' !== $type ) {
					$fg    = self::kid( $pf, 'fgColor' );
					$color = $fg ? self::color( $fg, $theme ) : '';
					if ( '' === $color && 'solid' !== $type ) {
						$bg    = self::kid( $pf, 'bgColor' );
						$color = $bg ? self::color( $bg, $theme ) : '';
					}
				}
			}
			$fills[] = $color;
		}

		$out = array();
		$xfs = self::kid( $root, 'cellXfs' );
		foreach ( self::kids( $xfs, 'xf' ) as $xf ) {
			$align = '';
			$al    = self::kid( $xf, 'alignment' );
			if ( $al ) {
				$h = $al->getAttribute( 'horizontal' );
				if ( in_array( $h, array( 'center', 'centerContinuous' ), true ) ) {
					$align = 'center';
				} elseif ( 'right' === $h ) {
					$align = 'right';
				} elseif ( 'left' === $h ) {
					$align = 'left';
				}
			}
			$fmt_id = (int) $xf->getAttribute( 'numFmtId' );
			$out[]  = array(
				'fmt'  => isset( $fmts[ $fmt_id ] ) ? $fmts[ $fmt_id ] : 'General',
				'fill' => $fills[ (int) $xf->getAttribute( 'fillId' ) ] ?? '',
				'b'    => $fonts[ (int) $xf->getAttribute( 'fontId' ) ] ?? 0,
				'a'    => $align,
			);
		}
		return $out;
	}

	/** Χρώμα από <fgColor rgb|theme|indexed tint>. */
	private static function color( $el, $theme ) {
		$hex = '';
		if ( $el->hasAttribute( 'rgb' ) ) {
			$hex = Pinax_Reader::hex( $el->getAttribute( 'rgb' ) );
		} elseif ( $el->hasAttribute( 'theme' ) ) {
			$hex = $theme[ (int) $el->getAttribute( 'theme' ) ] ?? '';
		} elseif ( $el->hasAttribute( 'indexed' ) ) {
			$hex = self::$indexed[ (int) $el->getAttribute( 'indexed' ) ] ?? ''; // 64 = «αυτόματο» → κενό.
		}
		if ( '' !== $hex && $el->hasAttribute( 'tint' ) ) {
			$hex = Pinax_Color::tint( $hex, (float) $el->getAttribute( 'tint' ) );
		}
		return $hex;
	}

	/* ------------------------------------------------------------------ */
	/* Τιμές κελιών                                                       */
	/* ------------------------------------------------------------------ */

	private static function cell_value( $c, $shared, $fmt ) {
		$type = $c->getAttribute( 't' );
		$v    = self::kid( $c, 'v' );
		$raw  = $v ? $v->textContent : '';

		switch ( $type ) {
			case 's':
				return $shared[ (int) $raw ] ?? '';
			case 'inlineStr':
				$is = self::kid( $c, 'is' );
				return $is ? self::rich_text( $is ) : '';
			case 'str':
				return $raw;
			case 'b':
				return '1' === $raw ? 'ΑΛΗΘΕΣ' : 'ΨΕΥΔΕΣ';
			case 'e':
				return '';
			case 'd':
				$ts = strtotime( $raw );
				return $ts ? gmdate( 'd/m/Y', $ts ) : $raw;
		}
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return $raw;
		}
		return self::format_number( (float) $raw, $fmt );
	}

	/**
	 * Μορφοποιεί αριθμό όπως το Excel (απλοποιημένα, με ελληνικές συμβάσεις).
	 */
	public static function format_number( $num, $code ) {
		$code = (string) $code;
		if ( '' === $code || 'General' === $code || '@' === $code ) {
			return self::general( $num );
		}
		// Πρώτο τμήμα (θετικοί αριθμοί). Για αρνητικούς, το δεύτερο αν υπάρχει.
		$sections = self::split_sections( $code );
		$section  = $sections[0];
		$negative = $num < 0;
		if ( $negative && isset( $sections[1] ) && '' !== trim( $sections[1] ) ) {
			$section = $sections[1];
			$num     = abs( $num );
			$negative = false; // Το τμήμα αρνητικών έχει δική του σήμανση (π.χ. παρενθέσεις).
		}

		// Αφαιρούμε κείμενο σε εισαγωγικά, χαρακτήρες διαφυγής και [χρώματα/locale] για τον έλεγχο.
		$plain = preg_replace( '/"[^"]*"|\\\\.|_.|\*./', '', $section );
		$plain = preg_replace( '/\[(?!h\]|hh\]|m\]|mm\]|s\]|ss\])[^\]]*\]/i', '', $plain );
		$lower = strtolower( $plain );

		$has_date = (bool) preg_match( '/[dy]/', $lower ) || (bool) preg_match( '/mmm/', $lower );
		$has_time = (bool) preg_match( '/[hs]/', $lower );

		if ( $has_date || $has_time ) {
			return self::format_datetime( $num, $has_date, $has_time, false !== strpos( $lower, 's' ), (bool) preg_match( '/\[h+\]/', $lower ), false !== strpos( $lower, 'hh' ) );
		}

		if ( false !== strpos( $plain, '%' ) ) {
			$dec = self::decimals( $plain );
			return self::number( $num * 100, $dec, false ) . '%';
		}

		if ( preg_match( '/[0#?]/', $plain ) ) {
			$dec      = self::decimals( $plain );
			$grouping = false !== strpos( $plain, ',' ) && preg_match( '/[0#?],[0#?]/', $plain );
			$out      = self::number( $num, $dec, $grouping );
			// Κείμενο σε εισαγωγικά (π.χ. 0" €") διατηρείται.
			if ( preg_match_all( '/"([^"]*)"/', $section, $m ) ) {
				$prefix = strpos( $section, '"' ) < strcspn( $section, '0#?' ) ? $m[1][0] : '';
				$suffix = $prefix ? implode( '', array_slice( $m[1], 1 ) ) : implode( '', $m[1] );
				$out    = $prefix . $out . $suffix;
			}
			if ( false !== strpos( $section, '€' ) && false === strpos( $out, '€' ) ) {
				$out .= ' €';
			}
			return $out;
		}

		return self::general( $num );
	}

	private static function split_sections( $code ) {
		$out = array();
		$buf = '';
		$q   = false;
		$len = strlen( $code );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $code[ $i ];
			if ( '"' === $ch ) {
				$q = ! $q;
			}
			if ( ';' === $ch && ! $q ) {
				$out[] = $buf;
				$buf   = '';
				continue;
			}
			$buf .= $ch;
		}
		$out[] = $buf;
		return $out;
	}

	private static function decimals( $plain ) {
		if ( preg_match( '/\.([0#?]+)/', $plain, $m ) ) {
			return strlen( $m[1] );
		}
		return 0;
	}

	private static function number( $num, $dec, $grouping ) {
		return number_format( $num, $dec, ',', $grouping ? '.' : '' );
	}

	/** «Γενική» μορφή: ακέραιοι ως έχουν, δεκαδικοί με κόμμα, χωρίς θόρυβο κινητής υποδιαστολής. */
	private static function general( $num ) {
		if ( floor( $num ) === $num && abs( $num ) < 1e15 ) {
			return (string) (int) $num;
		}
		$s = rtrim( rtrim( sprintf( '%.10F', round( $num, 10 ) ), '0' ), '.' );
		return str_replace( '.', ',', $s );
	}

	private static function format_datetime( $num, $has_date, $has_time, $seconds, $elapsed, $two_digit_hour = false ) {
		if ( $elapsed ) {
			$total = (int) round( $num * 86400 );
			$h     = intdiv( $total, 3600 );
			$m     = intdiv( $total % 3600, 60 );
			$s     = $total % 60;
			return $seconds ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%d:%02d', $h, $m );
		}
		if ( self::$date1904 ) {
			$num += 1462; // Διαφορά συστήματος 1904 από 1900.
		}
		$days = (int) floor( $num );
		$secs = (int) round( ( $num - $days ) * 86400 );
		if ( $secs >= 86400 ) {
			++$days;
			$secs -= 86400;
		}
		// Το Excel μετρά από 30/12/1899 (με το γνωστό «λάθος» του 1900, που δεν επηρεάζει ημερομηνίες μετά τον Μάρτιο 1900).
		$ts   = ( $days - 25569 ) * 86400 + $secs;
		$hour = $two_digit_hour ? 'H' : 'G';
		$time = $seconds ? $hour . ':i:s' : $hour . ':i';
		if ( $has_date && $has_time ) {
			return gmdate( 'd/m/Y ' . $time, $ts );
		}
		if ( $has_date ) {
			return gmdate( 'd/m/Y', $ts );
		}
		return gmdate( $time, $ts );
	}
}
