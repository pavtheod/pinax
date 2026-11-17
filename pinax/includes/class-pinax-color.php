<?php
/**
 * Χρώματα: απόχρωση (tint) του Excel και μετατροπή σε «καθαρές» αποχρώσεις για το site.
 *
 * @package Pinax
 */

defined( 'ABSPATH' ) || exit;

class Pinax_Color {

	public static function to_hls( $hex ) {
		$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 4, 2 ) ) / 255;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		if ( $max === $min ) {
			return array( 0.0, $l, 0.0 );
		}
		$d = $max - $min;
		$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
		if ( $max === $r ) {
			$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
		} elseif ( $max === $g ) {
			$h = ( $b - $r ) / $d + 2;
		} else {
			$h = ( $r - $g ) / $d + 4;
		}
		return array( $h / 6, $l, $s );
	}

	public static function from_hls( $h, $l, $s ) {
		if ( 0.0 === (float) $s ) {
			$v = (int) round( $l * 255 );
			return sprintf( '%02X%02X%02X', $v, $v, $v );
		}
		$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
		$p = 2 * $l - $q;
		$f = function ( $t ) use ( $p, $q ) {
			if ( $t < 0 ) {
				$t += 1;
			}
			if ( $t > 1 ) {
				$t -= 1;
			}
			if ( $t < 1 / 6 ) {
				return $p + ( $q - $p ) * 6 * $t;
			}
			if ( $t < 1 / 2 ) {
				return $q;
			}
			if ( $t < 2 / 3 ) {
				return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
			}
			return $p;
		};
		return sprintf( '%02X%02X%02X', round( $f( $h + 1 / 3 ) * 255 ), round( $f( $h ) * 255 ), round( $f( $h - 1 / 3 ) * 255 ) );
	}

	/** Η «απόχρωση» (tint) του Excel: θετική = πιο ανοιχτό, αρνητική = πιο σκούρο. */
	public static function tint( $hex, $tint ) {
		list( $h, $l, $s ) = self::to_hls( $hex );
		$l = $tint < 0 ? $l * ( 1 + $tint ) : $l * ( 1 - $tint ) + $tint;
		return self::from_hls( $h, max( 0, min( 1, $l ) ), $s );
	}

	/**
	 * Από ένα χρώμα του Excel/Word, «καθαρές» αποχρώσεις για το site.
	 * Γκρι, λευκό και μαύρο αγνοούνται (επιστρέφει null), ώστε να μένει το βασικό στυλ.
	 *
	 * @return array|null array( 'bg' => φόντο κελιού, 'fg' => κείμενο, 'head' => επικεφαλίδα )
	 */
	public static function palette( $hex ) {
		$hex = Pinax_Reader::hex( $hex );
		if ( '' === $hex ) {
			return null;
		}
		list( $h, $l, $s ) = self::to_hls( $hex );
		if ( $s < 0.12 || $l > 0.975 || $l < 0.06 ) {
			return null;
		}
		return array(
			'bg'   => self::from_hls( $h, 0.94, min( 0.78, max( 0.45, $s ) ) ),
			'fg'   => self::from_hls( $h, 0.22, min( 0.6, max( 0.35, $s ) ) ),
			'head' => self::from_hls( $h, 0.54, min( 0.52, max( 0.35, $s * 0.8 ) ) ),
		);
	}

	/** Επικυρώνει χρώμα της μορφής #rrggbb. */
	public static function sanitize( $c, $fallback ) {
		$c = sanitize_hex_color( $c );
		return $c ? $c : $fallback;
	}
}
