<?php
/**
 * Ανάγνωση πινάκων από έγγραφο Word (.docx).
 *
 * Διαβάζονται μόνο οι πίνακες του κυρίως κειμένου (όχι κεφαλίδα/υποσέλιδο).
 * Πίνακες μέσα σε κελιά αγνοούνται ως ξεχωριστοί πίνακες.
 *
 * @package Pinax
 */

defined( 'ABSPATH' ) || exit;

class Pinax_Docx {

	const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * @return array|WP_Error array( 'sheets' => [...], 'labels' => [...] )
	 */
	public static function read( $path ) {
		$zip = Pinax_Reader::open_zip( $path, 'word/document.xml' );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}
		$xml = Pinax_Reader::zip_xml( $zip, 'word/document.xml' );
		$zip->close();
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$doc = Pinax_Reader::load_xml( $xml );
		if ( ! $doc ) {
			return new WP_Error( 'pinax_docx', 'Το έγγραφο Word δεν διαβάζεται.' );
		}

		$body = null;
		foreach ( $doc->documentElement->childNodes as $ch ) {
			if ( XML_ELEMENT_NODE === $ch->nodeType && 'body' === $ch->localName ) {
				$body = $ch;
			}
		}
		if ( ! $body ) {
			return new WP_Error( 'pinax_docx', 'Το έγγραφο Word δεν έχει περιεχόμενο.' );
		}

		// Πίνακες του κυρίως κειμένου (και μέσα σε <w:sdt> ενότητες περιεχομένου).
		$tables = array();
		self::collect_tables( $body, $tables );

		$sheets = array();
		$labels = array();
		$cells  = 0;
		foreach ( $tables as $i => $tbl ) {
			$sheet = self::read_table( $tbl );
			foreach ( $sheet['grid'] as $row ) {
				$cells += count( $row );
			}
			if ( $cells > Pinax_Reader::MAX_CELLS ) {
				return new WP_Error( 'pinax_big', Pinax_Reader::TOO_BIG );
			}
			if ( ! $sheet['grid'] ) {
				continue;
			}
			$sheets[] = $sheet;
			$labels[] = self::label( count( $sheets ), $sheet );
		}

		if ( ! $sheets ) {
			return new WP_Error( 'pinax_notable', 'Το έγγραφο Word δεν έχει πίνακα. (Αν ο «πίνακας» είναι φτιαγμένος με κενά ή tab, μετατρέψτε τον σε πίνακα: Εισαγωγή → Πίνακας → Μετατροπή κειμένου σε πίνακα.)' );
		}
		// Προεπιλογή: ο μεγαλύτερος πίνακας (συχνά ο πρώτος είναι η κεφαλίδα με το λογότυπο).
		$best      = 0;
		$best_size = -1;
		foreach ( $sheets as $i => $sheet ) {
			$size = 0;
			foreach ( $sheet['grid'] as $row ) {
				foreach ( $row as $cell ) {
					if ( '' !== $cell['v'] ) {
						++$size;
					}
				}
			}
			if ( $size > $best_size ) {
				$best      = $i;
				$best_size = $size;
			}
		}

		return array(
			'sheets'  => $sheets,
			'labels'  => $labels,
			'default' => $best,
		);
	}

	private static function collect_tables( $node, &$out ) {
		foreach ( $node->childNodes as $ch ) {
			if ( XML_ELEMENT_NODE !== $ch->nodeType ) {
				continue;
			}
			if ( 'tbl' === $ch->localName ) {
				$out[] = $ch;
			} elseif ( in_array( $ch->localName, array( 'sdt', 'sdtContent', 'customXml' ), true ) ) {
				self::collect_tables( $ch, $out );
			}
		}
	}

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

	private static function attr( $el, $name ) {
		if ( ! $el ) {
			return '';
		}
		$v = $el->getAttributeNS( self::NS, $name );
		return '' !== $v ? $v : $el->getAttribute( 'w:' . $name );
	}

	/** Γραμμές του πίνακα (και μέσα σε <w:sdt>). */
	private static function rows( $tbl ) {
		$out = array();
		foreach ( $tbl->childNodes as $ch ) {
			if ( XML_ELEMENT_NODE !== $ch->nodeType ) {
				continue;
			}
			if ( 'tr' === $ch->localName ) {
				$out[] = $ch;
			} elseif ( in_array( $ch->localName, array( 'sdt', 'sdtContent', 'customXml' ), true ) ) {
				$out = array_merge( $out, self::rows( $ch ) );
			}
		}
		return $out;
	}

	/** Κελιά μιας γραμμής (και μέσα σε <w:sdt>). */
	private static function cells( $tr ) {
		$out = array();
		foreach ( $tr->childNodes as $ch ) {
			if ( XML_ELEMENT_NODE !== $ch->nodeType ) {
				continue;
			}
			if ( 'tc' === $ch->localName ) {
				$out[] = $ch;
			} elseif ( in_array( $ch->localName, array( 'sdt', 'sdtContent', 'customXml' ), true ) ) {
				$out = array_merge( $out, self::cells( $ch ) );
			}
		}
		return $out;
	}

	private static function read_table( $tbl ) {
		$grid    = array();
		$merges  = array();
		$v_open  = array(); // στήλη => array( γραμμή έναρξης, πλάτος )
		$r       = 0;

		foreach ( self::rows( $tbl ) as $tr ) {
			if ( $r >= Pinax_Reader::MAX_ROWS ) {
				break;
			}
			$c    = 0;
			$trpr = self::kid( $tr, 'trPr' );
			$gb   = self::kid( $trpr, 'gridBefore' );
			if ( $gb ) {
				$c += max( 0, (int) self::attr( $gb, 'val' ) );
			}
			$seen_cols = array();

			foreach ( self::cells( $tr ) as $tc ) {
				if ( $c >= Pinax_Reader::MAX_COLS ) {
					break;
				}
				$tcpr = self::kid( $tc, 'tcPr' );
				$gs   = self::kid( $tcpr, 'gridSpan' );
				$span = $gs ? max( 1, (int) self::attr( $gs, 'val' ) ) : 1;
				$vm   = self::kid( $tcpr, 'vMerge' );
				$vval = $vm ? self::attr( $vm, 'val' ) : null;

				if ( $vm && 'restart' !== $vval ) {
					// Συνέχεια κάθετης συγχώνευσης από πάνω.
					if ( isset( $v_open[ $c ] ) ) {
						$seen_cols[ $c ] = true;
					}
					$c += $span;
					continue;
				}

				// Κλείνει τυχόν ανοιχτή κάθετη συγχώνευση σε αυτή τη στήλη.
				self::close_vmerge( $v_open, $merges, $c, $r );

				$shd  = self::kid( $tcpr, 'shd' );
				$fill = $shd ? Pinax_Reader::hex( self::attr( $shd, 'fill' ) ) : '';
				list( $text, $bold, $align ) = self::cell_content( $tc );

				$grid[ $r ][ $c ] = Pinax_Reader::cell( Pinax_Reader::clean( $text ), $fill, $bold, $align );

				if ( 'restart' === $vval ) {
					$v_open[ $c ]    = array( $r, $span );
					$seen_cols[ $c ] = true;
				} elseif ( $span > 1 ) {
					$merges[] = array( $r, $c, $r, $c + $span - 1 );
				}
				$c += $span;
			}

			// Κάθετες συγχωνεύσεις που δεν συνεχίστηκαν σε αυτή τη γραμμή κλείνουν.
			foreach ( array_keys( $v_open ) as $col ) {
				if ( empty( $seen_cols[ $col ] ) ) {
					self::close_vmerge( $v_open, $merges, $col, $r );
				}
			}
			++$r;
		}
		foreach ( array_keys( $v_open ) as $col ) {
			self::close_vmerge( $v_open, $merges, $col, $r );
		}

		return array(
			'grid'   => $grid,
			'merges' => $merges,
			'width'  => self::table_width( $tbl ),
		);
	}

	/**
	 * Το πλάτος που έχει ο πίνακας στο Word, σε εικονοστοιχεία (0 όταν δεν ορίζεται ρητά,
	 * π.χ. «αυτόματο» ή ποσοστό). 1 dxa = 1/1440 της ίντσας, άρα px = dxa / 15.
	 */
	private static function table_width( $tbl ) {
		$pr = self::kid( $tbl, 'tblPr' );
		$w  = $pr ? self::kid( $pr, 'tblW' ) : null;
		if ( ! $w || 'dxa' !== self::attr( $w, 'type' ) ) {
			return 0;
		}
		$px = (int) round( (int) self::attr( $w, 'w' ) / 15 );
		return ( $px >= 100 && $px <= 4000 ) ? $px : 0;
	}

	private static function close_vmerge( &$v_open, &$merges, $col, $current_row ) {
		if ( ! isset( $v_open[ $col ] ) ) {
			return;
		}
		list( $start, $span ) = $v_open[ $col ];
		$end = $current_row - 1;
		if ( $end > $start || $span > 1 ) {
			$merges[] = array( $start, $col, max( $start, $end ), $col + $span - 1 );
		}
		unset( $v_open[ $col ] );
	}

	/**
	 * Κείμενο, έντονα, στοίχιση ενός κελιού. Αγνοεί πίνακες μέσα στο κελί.
	 *
	 * @return array( text, bold, align )
	 */
	private static function cell_content( $tc ) {
		$paras      = array();
		$runs_total = 0;
		$runs_bold  = 0;
		$align      = '';

		foreach ( $tc->childNodes as $p ) {
			if ( XML_ELEMENT_NODE !== $p->nodeType || 'p' !== $p->localName ) {
				continue;
			}
			if ( '' === $align ) {
				$jc = self::kid( self::kid( $p, 'pPr' ), 'jc' );
				$jv = $jc ? self::attr( $jc, 'val' ) : '';
				if ( 'center' === $jv ) {
					$align = 'center';
				} elseif ( in_array( $jv, array( 'right', 'end' ), true ) ) {
					$align = 'right';
				} elseif ( in_array( $jv, array( 'left', 'start', 'both' ), true ) ) {
					$align = 'left';
				}
			}
			$text = '';
			foreach ( $p->getElementsByTagNameNS( '*', 'r' ) as $run ) {
				// Παραλείπουμε κείμενο διαγραμμένο σε «Παρακολούθηση αλλαγών» και κείμενο
				// μέσα σε πλαίσια κειμένου/σχήματα (το Word το αποθηκεύει και δεύτερη φορά).
				$skip = false;
				for ( $a = $run->parentNode; $a && $a !== $p; $a = $a->parentNode ) {
					if ( in_array( $a->localName, array( 'del', 'moveFrom', 'txbxContent', 'AlternateContent', 'Fallback', 'pict', 'drawing' ), true ) ) {
						$skip = true;
						break;
					}
				}
				if ( $skip ) {
					continue;
				}
				$run_text = '';
				foreach ( $run->childNodes as $el ) {
					if ( XML_ELEMENT_NODE !== $el->nodeType ) {
						continue;
					}
					if ( 't' === $el->localName ) {
						$run_text .= $el->textContent;
					} elseif ( in_array( $el->localName, array( 'tab', 'br', 'cr' ), true ) ) {
						$run_text .= ' ';
					}
				}
				if ( '' !== trim( $run_text ) ) {
					++$runs_total;
					$b = self::kid( self::kid( $run, 'rPr' ), 'b' );
					if ( $b && ! in_array( self::attr( $b, 'val' ), array( '0', 'false', 'off' ), true ) ) {
						++$runs_bold;
					}
				}
				$text .= $run_text;
			}
			if ( '' !== trim( $text ) ) {
				$paras[] = $text;
			}
		}
		return array( implode( ' ', $paras ), ( $runs_total > 0 && $runs_bold === $runs_total ) ? 1 : 0, $align );
	}

	private static function label( $n, $sheet ) {
		$rows  = count( $sheet['grid'] );
		$cols  = 0;
		$texts = array();
		foreach ( $sheet['grid'] as $row ) {
			$cols = max( $cols, max( array_keys( $row ) ) + 1 );
			foreach ( $row as $cell ) {
				if ( '' !== $cell['v'] && count( $texts ) < 3 ) {
					$texts[] = $cell['v'];
				}
			}
		}
		$preview = implode( ' · ', $texts );
		if ( mb_strlen( $preview ) > 70 ) {
			$preview = mb_substr( $preview, 0, 68 ) . '…';
		}
		return sprintf( 'Πίνακας %d (%d γραμμές × %d στήλες): %s', $n, $rows, $cols, $preview );
	}
}
