<?php
/**
 * Κοινά εργαλεία ανάγνωσης αρχείων (ασφαλές άνοιγμα zip/XML, καθαρισμός κειμένου).
 *
 * Όλοι οι αναγνώστες (Excel, Word, CSV) επιστρέφουν την ίδια δομή «φύλλου»:
 *   array(
 *     'grid'   => array( γραμμή => array( στήλη => κελί ) ),   // 0-based
 *     'merges' => array( array( r1, c1, r2, c2 ) ),           // 0-based, συμπεριλαμβανόμενα
 *   )
 * όπου κελί = array( 'v' => κείμενο, 'f' => 'RRGGBB' ή '', 'b' => 0|1, 'a' => ''|left|center|right ).
 *
 * @package Pinax
 */

defined( 'ABSPATH' ) || exit;

class Pinax_Reader {

	const MAX_ROWS        = 2000;
	const MAX_COLS        = 60;
	const MAX_ENTRY_BYTES = 31457280;  // 30 MB ανά αρχείο μέσα στο zip.
	const MAX_TOTAL_BYTES = 62914560;  // 60 MB συνολικά μετά την αποσυμπίεση.
	const MAX_ENTRIES     = 3000;
	const MAX_XML_BYTES   = 12582912;  // 12 MB: μεγαλύτερο XML φύλλου/εγγράφου δεν διαβάζεται (μνήμη).
	const MAX_CELLS       = 20000;     // Πάνω από τόσα γεμάτα κελιά δεν είναι πίνακας για ιστοσελίδα.

	/** Μήνυμα όταν το αρχείο είναι υπερβολικά μεγάλο για πίνακα ιστοσελίδας. */
	const TOO_BIG = 'Ο πίνακας είναι πολύ μεγάλος για να εμφανιστεί σε ιστοσελίδα (όριο: περίπου 20.000 γεμάτα κελιά). Αν το αρχείο έχει κι άλλα δεδομένα, κρατήστε μόνο τον πίνακα που θέλετε να δημοσιεύσετε.';

	/**
	 * Διαβάζει ένα αρχείο ανάλογα με την κατάληξη.
	 *
	 * @return array|WP_Error array( 'sheets' => [φύλλα], 'labels' => [ονόματα για επιλογή] )
	 */
	public static function read( $path, $ext ) {
		switch ( $ext ) {
			case 'xlsx':
				$sheet = Pinax_Xlsx::read( $path );
				return is_wp_error( $sheet ) ? $sheet : array(
					'sheets' => array( $sheet ),
					'labels' => array( 'Φύλλο: ' . ( isset( $sheet['name'] ) ? $sheet['name'] : '1' ) ),
				);
			case 'docx':
				return Pinax_Docx::read( $path );
			case 'csv':
				$sheet = Pinax_Csv::read( $path );
				return is_wp_error( $sheet ) ? $sheet : array(
					'sheets' => array( $sheet ),
					'labels' => array( 'CSV' ),
				);
		}
		return new WP_Error( 'pinax_ext', 'Υποστηρίζονται αρχεία Excel (.xlsx), Word (.docx) και CSV.' );
	}

	/**
	 * Ανοίγει ένα zip (xlsx/docx) με ελέγχους μεγέθους.
	 *
	 * @return ZipArchive|WP_Error
	 */
	public static function open_zip( $path, $required_entry ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'pinax_zip', 'Ο server δεν έχει την επέκταση ZipArchive της PHP, οπότε δεν μπορούν να διαβαστούν αρχεία Excel/Word. Ζητήστε από τον πάροχο φιλοξενίας να την ενεργοποιήσει ή ανεβάστε το αρχείο ως CSV.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'pinax_open', 'Το αρχείο δεν ανοίγει. Μήπως είναι παλιό .xls/.doc; Ανοίξτε το και κάντε «Αποθήκευση ως» .xlsx ή .docx.' );
		}
		if ( $zip->numFiles > self::MAX_ENTRIES ) {
			$zip->close();
			return new WP_Error( 'pinax_big', 'Το αρχείο έχει ασυνήθιστη δομή και δεν διαβάζεται.' );
		}
		$total = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$st = $zip->statIndex( $i );
			if ( ! $st ) {
				continue;
			}
			if ( $st['size'] > self::MAX_ENTRY_BYTES ) {
				$zip->close();
				return new WP_Error( 'pinax_big', 'Το αρχείο είναι υπερβολικά μεγάλο όταν αποσυμπιεστεί.' );
			}
			$total += $st['size'];
		}
		if ( $total > self::MAX_TOTAL_BYTES ) {
			$zip->close();
			return new WP_Error( 'pinax_big', 'Το αρχείο είναι υπερβολικά μεγάλο όταν αποσυμπιεστεί.' );
		}
		if ( false === $zip->locateName( $required_entry ) ) {
			$zip->close();
			return new WP_Error( 'pinax_type', 'Το αρχείο δεν είναι έγκυρο έγγραφο Excel/Word (ίσως έχει λάθος κατάληξη).' );
		}
		return $zip;
	}

	/**
	 * Διαβάζει ένα αρχείο XML από το zip, αν δεν είναι υπερβολικά μεγάλο.
	 *
	 * @return string|false|WP_Error  false αν δεν υπάρχει.
	 */
	public static function zip_xml( $zip, $name ) {
		$st = $zip->statName( $name );
		if ( false === $st ) {
			return false;
		}
		if ( $st['size'] > self::MAX_XML_BYTES ) {
			return new WP_Error( 'pinax_big', self::TOO_BIG );
		}
		return $zip->getFromName( $name );
	}

	/**
	 * Φορτώνει XML με ασφάλεια (χωρίς DOCTYPE / εξωτερικές οντότητες).
	 *
	 * @return DOMDocument|null
	 */
	public static function load_xml( $xml ) {
		if ( ! is_string( $xml ) || '' === $xml ) {
			return null;
		}
		// Τα έγγραφα Office δεν έχουν ποτέ DOCTYPE. Αν υπάρχει, το αρχείο απορρίπτεται.
		if ( false !== stripos( substr( $xml, 0, 4096 ), '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$ok   = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $ok ? $doc : null;
	}

	/** Καθαρίζει το κείμενο ενός κελιού. */
	public static function clean( $v ) {
		$v = (string) $v;
		$v = str_replace( array( "\r\n", "\r", "\n", "\t", "\xC2\xA0" ), ' ', $v );
		// Ενώνει τόνους που είναι αποθηκευμένοι ως ξεχωριστός χαρακτήρας.
		if ( class_exists( 'Normalizer' ) ) {
			$n = Normalizer::normalize( $v, Normalizer::FORM_C );
			if ( false !== $n ) {
				$v = $n;
			}
		}
		if ( ! preg_match( '//u', $v ) ) {
			// Μη έγκυρο UTF-8: κρατάμε ό,τι διαβάζεται.
			$v = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $v, 'UTF-8', 'UTF-8' ) : wp_check_invalid_utf8( $v, true );
		}
		$v = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v );
		$v = preg_replace( '/\s+/u', ' ', (string) $v );
		return trim( (string) $v );
	}

	/** Νέο κενό κελί. */
	public static function cell( $v = '', $fill = '', $bold = 0, $align = '' ) {
		return array(
			'v' => $v,
			'f' => $fill,
			'b' => $bold ? 1 : 0,
			'a' => $align,
		);
	}

	/** Κανονικοποιεί χρώμα σε RRGGBB (κεφαλαία) ή ''. */
	public static function hex( $c ) {
		$c = strtoupper( ltrim( (string) $c, '#' ) );
		if ( 8 === strlen( $c ) ) {
			$c = substr( $c, 2 );
		}
		return preg_match( '/^[0-9A-F]{6}$/', $c ) ? $c : '';
	}
}
