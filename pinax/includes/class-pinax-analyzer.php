<?php
/**
 * Από ένα «φύλλο» (Excel/Word/CSV) φτιάχνει τον πίνακα που θα εμφανιστεί:
 * τίτλος, υπότιτλος, επικεφαλίδα, γραμμές, στοίχιση και χρώματα στηλών.
 *
 * @package Pinax
 */

defined( 'ABSPATH' ) || exit;

class Pinax_Analyzer {

	/**
	 * @param array      $sheet      Φύλλο.
	 * @param string|int $header_opt 'auto', 'none' ή αριθμός γραμμής (0-based).
	 * @return array|WP_Error
	 */
	public static function analyze( $sheet, $header_opt = 'auto' ) {
		$grid = isset( $sheet['grid'] ) ? $sheet['grid'] : array();
		if ( ! $grid ) {
			return new WP_Error( 'pinax_empty', 'Το αρχείο δεν έχει δεδομένα.' );
		}

		// 1. Πυκνός πίνακας κελιών, μόνο μέχρι το τελευταίο κελί με κείμενο
		//    (χρωματισμένα κενά κελιά πιο πέρα δεν μετράνε).
		$max_r = -1;
		$max_c = -1;
		foreach ( $grid as $r => $row ) {
			foreach ( $row as $c => $cell ) {
				if ( '' !== $cell['v'] ) {
					$max_r = max( $max_r, $r );
					$max_c = max( $max_c, $c );
				}
			}
		}
		if ( $max_r < 0 ) {
			return new WP_Error( 'pinax_empty', 'Ο πίνακας του αρχείου είναι κενός.' );
		}
		$max_r = min( $max_r, Pinax_Reader::MAX_ROWS - 1 );
		$max_c = min( $max_c, Pinax_Reader::MAX_COLS - 1 );

		$cells   = array();
		$span    = array();
		$covered = array();
		$empty   = Pinax_Reader::cell(); // Κοινό αντικείμενο για τα κενά κελιά (λιγότερη μνήμη).
		for ( $r = 0; $r <= $max_r; $r++ ) {
			for ( $c = 0; $c <= $max_c; $c++ ) {
				$cells[ $r ][ $c ]   = isset( $grid[ $r ][ $c ] ) ? $grid[ $r ][ $c ] : $empty;
				$span[ $r ][ $c ]    = 1;
				$covered[ $r ][ $c ] = false;
			}
		}

		// 2. Συγχωνεύσεις: οριζόντια → ενωμένο κελί, κάθετα → η τιμή επαναλαμβάνεται σε κάθε γραμμή.
		foreach ( isset( $sheet['merges'] ) ? $sheet['merges'] : array() as $m ) {
			list( $r1, $c1, $r2, $c2 ) = array_map( 'intval', $m );
			if ( $r1 > $max_r || $c1 > $max_c ) {
				continue;
			}
			$r2  = min( $r2, $max_r );
			$c2  = min( $c2, $max_c );
			$top = $cells[ $r1 ][ $c1 ];
			for ( $r = $r1; $r <= $r2; $r++ ) {
				if ( $r > $r1 ) {
					$cells[ $r ][ $c1 ] = $top;
				}
				$span[ $r ][ $c1 ] = $c2 - $c1 + 1;
				for ( $c = $c1 + 1; $c <= $c2; $c++ ) {
					$covered[ $r ][ $c ] = true;
				}
			}
		}

		// 3. Πόσα γεμάτα κελιά έχει κάθε γραμμή.
		$filled    = array();
		$only_span = array(); // για γραμμές με ένα κελί: πόσες στήλες καλύπτει
		for ( $r = 0; $r <= $max_r; $r++ ) {
			$n = 0;
			$s = 1;
			for ( $c = 0; $c <= $max_c; $c++ ) {
				if ( ! $covered[ $r ][ $c ] && '' !== $cells[ $r ][ $c ]['v'] ) {
					++$n;
					$s = $span[ $r ][ $c ];
				}
			}
			$filled[ $r ]    = $n;
			$only_span[ $r ] = $s;
		}

		// 4. Γραμμή επικεφαλίδων.
		$first_multi = -1;
		for ( $r = 0; $r <= $max_r; $r++ ) {
			if ( $filled[ $r ] >= 2 ) {
				$first_multi = $r;
				break;
			}
		}

		$header = -1;
		if ( 'none' === $header_opt ) {
			$start = $first_multi >= 0 ? $first_multi : 0;
		} elseif ( is_numeric( $header_opt ) && (int) $header_opt >= 0 && (int) $header_opt <= $max_r && $filled[ (int) $header_opt ] > 0 ) {
			$header = (int) $header_opt;
			$start  = $header;
		} else {
			$header = $first_multi;
			$start  = $first_multi >= 0 ? $first_multi : 0;
		}

		// 5. Γραμμές τίτλου: γραμμές με ένα μόνο κείμενο πάνω από τον πίνακα.
		$titles  = array();
		$skipped = array();
		for ( $r = 0; $r < $start; $r++ ) {
			if ( 1 === $filled[ $r ] ) {
				foreach ( $cells[ $r ] as $c => $cell ) {
					if ( ! $covered[ $r ][ $c ] && '' !== $cell['v'] ) {
						$titles[] = $cell['v'];
					}
				}
			} elseif ( $filled[ $r ] > 1 ) {
				$skipped[] = self::skip_entry( $r, $cells[ $r ], $covered[ $r ] );
			}
		}
		// Η τελευταία γραμμή τίτλου (η πιο κοντινή στον πίνακα) είναι συνήθως το όνομα του πίνακα.
		$title    = $titles ? array_pop( $titles ) : '';
		$subtitle = implode( ' · ', $titles );

		// 6. Γραμμές σώματος.
		$body_from = $header >= 0 ? $header + 1 : $start;
		$body_rows = array();
		for ( $r = $body_from; $r <= $max_r; $r++ ) {
			if ( $filled[ $r ] > 0 ) {
				$body_rows[] = $r;
			}
		}
		// Στο τέλος: γραμμές με ένα μόνο, μη συγχωνευμένο κείμενο (π.χ. «Ο Υπεύθυνος») παραλείπονται.
		while ( $first_multi >= 0 && $body_rows ) { // Μόνο σε πίνακες με πολλές στήλες.
			$r = end( $body_rows );
			if ( 1 === $filled[ $r ] && $only_span[ $r ] < 2 && count( $body_rows ) > 1 ) {
				array_pop( $body_rows );
				array_unshift( $skipped, self::skip_entry( $r, $cells[ $r ], $covered[ $r ] ) );
				continue;
			}
			break;
		}
		usort(
			$skipped,
			function ( $a, $b ) {
				return $a['line'] - $b['line'];
			}
		);

		if ( ! $body_rows ) {
			return new WP_Error( 'pinax_rows', 'Βρέθηκε επικεφαλίδα, αλλά καμία γραμμή δεδομένων κάτω από αυτήν. Δοκιμάστε άλλη γραμμή επικεφαλίδων.' );
		}

		// 7. Στήλες που κρατάμε: όσες έχουν κάποιο κείμενο.
		$used_rows = $body_rows;
		if ( $header >= 0 ) {
			array_unshift( $used_rows, $header );
		}
		$keep = array();
		for ( $c = 0; $c <= $max_c; $c++ ) {
			$keep[ $c ] = false;
			foreach ( $used_rows as $r ) {
				if ( ! $covered[ $r ][ $c ] && '' !== $cells[ $r ][ $c ]['v'] ) {
					$keep[ $c ] = true;
					break;
				}
			}
		}
		$col_index = array();
		$ncols     = 0;
		for ( $c = 0; $c <= $max_c; $c++ ) {
			if ( $keep[ $c ] ) {
				$col_index[ $c ] = $ncols++;
			}
		}

		$build = function ( $r ) use ( $cells, $span, $covered, $keep, $max_c, $col_index ) {
			$out = array();
			for ( $c = 0; $c <= $max_c; $c++ ) {
				if ( $covered[ $r ][ $c ] ) {
					continue;
				}
				$k   = 0;
				$end = min( $max_c, $c + $span[ $r ][ $c ] - 1 );
				for ( $x = $c; $x <= $end; $x++ ) {
					if ( $keep[ $x ] ) {
						++$k;
					}
				}
				if ( 0 === $k ) {
					continue;
				}
				$src  = $cells[ $r ][ $c ];
				$cell = array( 'v' => $src['v'] );
				if ( '' !== $src['f'] && Pinax_Color::palette( $src['f'] ) ) {
					$cell['f'] = $src['f'];
				}
				if ( ! empty( $src['b'] ) ) {
					$cell['b'] = 1;
				}
				if ( '' !== $src['a'] ) {
					$cell['a'] = substr( $src['a'], 0, 1 );
				}
				if ( $k > 1 ) {
					$cell['cs'] = $k;
				}
				// Η πρώτη στήλη που καλύπτει (για στοίχιση/χρώμα στήλης).
				for ( $x = $c; $x <= $end; $x++ ) {
					if ( $keep[ $x ] ) {
						$cell['_c'] = $col_index[ $x ];
						break;
					}
				}
				$out[] = $cell;
			}
			return $out;
		};

		$head = $header >= 0 ? $build( $header ) : array();
		$rows = array();
		foreach ( $body_rows as $r ) {
			$row = $build( $r );
			if ( 1 === count( $row ) && $ncols > 1 && isset( $row[0]['cs'] ) && $row[0]['cs'] === $ncols ) {
				$row[0]['w'] = 1; // Γραμμή σε όλο το πλάτος (π.χ. «Διάλειμμα»).
			}
			$rows[] = $row;
		}

		// Αν όλα τα κελιά είναι έντονα (συνηθισμένο σε Word), τα έντονα δεν τονίζουν τίποτα: αφαιρούνται.
		$text_cells = 0;
		$bold_cells = 0;
		foreach ( $rows as $row ) {
			foreach ( $row as $cell ) {
				if ( '' !== $cell['v'] ) {
					++$text_cells;
					if ( ! empty( $cell['b'] ) ) {
						++$bold_cells;
					}
				}
			}
		}
		if ( $text_cells > 0 && $bold_cells === $text_cells ) {
			foreach ( $rows as &$row ) {
				foreach ( $row as &$cell ) {
					unset( $cell['b'] );
				}
				unset( $cell );
			}
			unset( $row );
		}

		// 8. Στοίχιση και χρώμα κάθε στήλης.
		$cols = array();
		for ( $i = 0; $i < $ncols; $i++ ) {
			$cols[ $i ] = array(
				'a' => '',
				'f' => '',
			);
		}
		$vals  = array();
		$fills = array();
		$count = array();
		foreach ( $rows as $row ) {
			foreach ( $row as $cell ) {
				if ( isset( $cell['cs'] ) ) {
					continue;
				}
				$i = $cell['_c'];
				$count[ $i ] = ( $count[ $i ] ?? 0 ) + 1;
				if ( '' !== $cell['v'] ) {
					$vals[ $i ][] = $cell['v'];
				}
				if ( ! empty( $cell['f'] ) ) {
					$fills[ $i ][ 'x' . $cell['f'] ] = ( $fills[ $i ][ 'x' . $cell['f'] ] ?? 0 ) + 1;
				}
			}
		}
		for ( $i = 0; $i < $ncols; $i++ ) {
			if ( ! empty( $vals[ $i ] ) ) {
				$short = true;
				foreach ( $vals[ $i ] as $v ) {
					$len = mb_strlen( $v );
					if ( $len > 6 && ! ( $len <= 12 && preg_match( '/^[\d\s:.,\/\-–]+$/u', $v ) ) ) {
						$short = false;
						break;
					}
				}
				if ( $short ) {
					$cols[ $i ]['a'] = 'c';
				}
			}
			if ( ! empty( $fills[ $i ] ) ) {
				arsort( $fills[ $i ] );
				$top = key( $fills[ $i ] );
				if ( reset( $fills[ $i ] ) * 2 >= $count[ $i ] ) {
					$cols[ $i ]['f'] = substr( (string) $top, 1 );
				}
			}
		}

		// Καθαρισμός βοηθητικών πεδίων.
		$strip = function ( $row ) {
			foreach ( $row as &$cell ) {
				$cell['c'] = $cell['_c'];
				unset( $cell['_c'] );
			}
			return $row;
		};
		$head = $strip( $head );
		$rows = array_map( $strip, $rows );

		// Επιλογές για τη γραμμή επικεφαλίδων (στην προεπισκόπηση).
		$candidates = array();
		for ( $r = 0; $r <= min( $max_r, 14 ); $r++ ) {
			if ( 0 === $filled[ $r ] ) {
				continue;
			}
			$texts = array();
			foreach ( $cells[ $r ] as $c => $cell ) {
				if ( ! $covered[ $r ][ $c ] && '' !== $cell['v'] && count( $texts ) < 4 ) {
					$texts[] = $cell['v'];
				}
			}
			$t = implode( ' | ', $texts );
			if ( mb_strlen( $t ) > 60 ) {
				$t = mb_substr( $t, 0, 58 ) . '…';
			}
			$candidates[ $r ] = $t;
		}

		$warnings = isset( $sheet['notes'] ) ? (array) $sheet['notes'] : array();
		if ( $header < 0 && 'none' !== $header_opt && $first_multi >= 0 ) {
			$warnings[] = 'Δεν βρέθηκε γραμμή επικεφαλίδων, οπότε ο πίνακας θα εμφανιστεί χωρίς επικεφαλίδα.';
		}

		return array(
			'title'      => $title,
			'subtitle'   => $subtitle,
			'head'       => $head,
			'rows'       => $rows,
			'cols'       => $cols,
			'ncols'      => $ncols,
			'skipped'    => $skipped,
			'warnings'   => $warnings,
			'header_row' => $header,
			'auto_row'   => $first_multi,
			'candidates' => $candidates,
			'src_w'      => isset( $sheet['width'] ) ? (int) $sheet['width'] : 0,
		);
	}

	private static function skip_entry( $r, $row_cells, $row_covered ) {
		$texts = array();
		foreach ( $row_cells as $c => $cell ) {
			if ( ! $row_covered[ $c ] && '' !== $cell['v'] ) {
				$texts[] = $cell['v'];
			}
		}
		return array(
			'line' => $r + 1,
			'text' => implode( ' · ', $texts ),
		);
	}
}
