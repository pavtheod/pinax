<?php
/**
 * Αποθήκευση πινάκων στις ρυθμίσεις (options) του WordPress.
 *
 *   pinax_index         => array( id => όνομα )
 *   pinax_table_{id}    => array( name, settings, data, source, updated )
 *   pinax_draft_{id}    => προεπισκόπηση που δεν έχει δημοσιευτεί
 *   pinax_global        => γενικές ρυθμίσεις (χρώμα επικεφαλίδας)
 *
 * @package Pinax
 */

defined( 'ABSPATH' ) || exit;

class Pinax_Store {

	const DEFAULT_HEAD = '#285d87';

	public static function index() {
		$idx = get_option( 'pinax_index', array() );
		return is_array( $idx ) ? $idx : array();
	}

	public static function exists( $id ) {
		return array_key_exists( $id, self::index() );
	}

	public static function default_settings() {
		return array(
			'title'    => '',
			'subtitle' => '',
			'note'     => '',
			'search'   => 1,
			'print'    => 1,
			'updated'  => 1,
			'width'    => 'auto', // auto | narrow | medium | full | wider
			'hide_title'    => 0,
			'hide_subtitle' => 0,
		);
	}

	public static function get( $id ) {
		if ( ! self::exists( $id ) ) {
			return null;
		}
		$t = get_option( 'pinax_table_' . $id );
		if ( ! is_array( $t ) ) {
			$t = array();
		}
		$t             = wp_parse_args(
			$t,
			array(
				'name'     => self::index()[ $id ],
				'settings' => array(),
				'data'     => null,
				'source'   => '',
				'updated'  => 0,
			)
		);
		$settings = (array) $t['settings'];
		if ( ! isset( $settings['width'] ) && ! empty( $settings['wide'] ) ) {
			$settings['width'] = 'wider'; // Από την έκδοση 1.2: το παλιό «Φαρδύτερος πίνακας».
		}
		$t['settings'] = wp_parse_args( $settings, self::default_settings() );
		$t['id']       = $id;
		return $t;
	}

	/**
	 * Αποθηκεύει πίνακα. Επιστρέφει false αν η βάση δεδομένων δεν τον δέχτηκε.
	 */
	public static function save( $id, $table ) {
		unset( $table['id'] );
		$table['_t'] = wp_generate_password( 12, false );
		if ( ! self::write( 'pinax_table_' . $id, $table ) ) {
			return false;
		}
		$idx        = self::index();
		$idx[ $id ] = $table['name'];
		asort( $idx, SORT_NATURAL | SORT_FLAG_CASE );
		update_option( 'pinax_index', $idx );
		return true;
	}

	/**
	 * Γράφει μια ρύθμιση και ελέγχει ότι γράφτηκε πραγματικά.
	 * Αν η βάση απορρίψει χαρακτήρες (π.χ. emoji σε παλιές βάσεις utf8), ξαναδοκιμάζει χωρίς αυτούς.
	 */
	private static function write( $name, $value ) {
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( 1 === $attempt ) {
				$value = self::strip_4byte( $value );
			}
			update_option( $name, $value, false );
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$back = get_option( $name );
			if ( is_array( $back ) && isset( $back['_t'] ) && $back['_t'] === $value['_t'] ) {
				return true;
			}
		}
		return false;
	}

	private static function strip_4byte( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'strip_4byte' ), $value );
		}
		return is_string( $value ) ? preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '', $value ) : $value;
	}

	public static function create( $name, $id = '' ) {
		$name = trim( $name );
		$id   = self::unique_id( '' !== $id ? $id : $name );
		self::save(
			$id,
			array(
				'name'     => $name,
				'settings' => self::default_settings(),
				'data'     => null,
				'source'   => '',
				'updated'  => 0,
			)
		);
		return $id;
	}

	public static function delete( $id ) {
		$idx = self::index();
		unset( $idx[ $id ] );
		update_option( 'pinax_index', $idx );
		delete_option( 'pinax_table_' . $id );
		delete_option( 'pinax_draft_' . $id );
	}

	public static function get_draft( $id ) {
		$d = get_option( 'pinax_draft_' . $id );
		if ( ! is_array( $d ) ) {
			return null;
		}
		// Τα αρχικά δεδομένα του αρχείου αποθηκεύονται συμπιεσμένα.
		if ( isset( $d['packed'] ) ) {
			$raw = base64_decode( $d['packed'], true );
			$raw = ( false !== $raw && function_exists( 'gzuncompress' ) ) ? @gzuncompress( $raw ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$sh  = false !== $raw ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
			$d['sheets'] = is_array( $sh ) ? $sh : array();
			unset( $d['packed'] );
		}
		return $d;
	}

	/**
	 * Αποθηκεύει την προεπισκόπηση. Επιστρέφει false αν η βάση δεδομένων δεν τη δέχτηκε.
	 */
	public static function save_draft( $id, $draft ) {
		if ( function_exists( 'gzcompress' ) && isset( $draft['sheets'] ) ) {
			$draft['packed'] = base64_encode( gzcompress( serialize( $draft['sheets'] ), 6 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			unset( $draft['sheets'] );
		}
		$draft['_t'] = wp_generate_password( 12, false );
		return self::write( 'pinax_draft_' . $id, $draft );
	}

	public static function delete_draft( $id ) {
		delete_option( 'pinax_draft_' . $id );
	}

	public static function global_settings() {
		$g = get_option( 'pinax_global', array() );
		return wp_parse_args( is_array( $g ) ? $g : array(), array( 'head' => self::DEFAULT_HEAD ) );
	}

	/* ------------------------------------------------------------------ */
	/* Αναγνωριστικά (id) πινάκων                                         */
	/* ------------------------------------------------------------------ */

	/** Ελληνικά → λατινικά, για το id του shortcode. */
	public static function slugify( $text ) {
		$map  = array(
			'ου' => 'ou', 'ού' => 'ou', 'ΟΥ' => 'ou', 'Ου' => 'ou', 'αι' => 'ai', 'ει' => 'ei', 'οι' => 'oi',
			'μπ' => 'b', 'ντ' => 'nt', 'γκ' => 'gk', 'γγ' => 'ng', 'αυ' => 'av', 'ευ' => 'ev',
			'θ' => 'th', 'χ' => 'ch', 'ψ' => 'ps', 'ξ' => 'x',
			'α' => 'a', 'ά' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'έ' => 'e', 'ζ' => 'z',
			'η' => 'i', 'ή' => 'i', 'ι' => 'i', 'ί' => 'i', 'ϊ' => 'i', 'ΐ' => 'i', 'κ' => 'k', 'λ' => 'l',
			'μ' => 'm', 'ν' => 'n', 'ο' => 'o', 'ό' => 'o', 'π' => 'p', 'ρ' => 'r', 'σ' => 's', 'ς' => 's',
			'τ' => 't', 'υ' => 'y', 'ύ' => 'y', 'ϋ' => 'y', 'ΰ' => 'y', 'φ' => 'f', 'ω' => 'o', 'ώ' => 'o',
		);
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
		$text = strtr( $text, $map );
		$text = remove_accents( $text );
		$text = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $text ) );
		$text = trim( substr( $text, 0, 40 ), '-' );
		return '' !== $text ? $text : 'pinakas';
	}

	public static function unique_id( $text ) {
		$base = self::slugify( $text );
		$id   = $base;
		$n    = 2;
		while ( self::exists( $id ) ) {
			$id = $base . '-' . $n++;
		}
		return $id;
	}

	/** Επιτρεπτό id (για ό,τι έρχεται από URL/shortcode). */
	public static function clean_id( $id ) {
		return substr( preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $id ) ), 0, 48 );
	}
}
