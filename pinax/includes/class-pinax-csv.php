<?php
/**
 * Ανάγνωση CSV (UTF-8 ή Windows-1253, με ; ή , ή tab).
 *
 * @package Pinax
 */

defined( 'ABSPATH' ) || exit;

class Pinax_Csv {

	/**
	 * @return array|WP_Error Φύλλο (δείτε Pinax_Reader).
	 */
	public static function read( $path ) {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $raw || '' === trim( $raw, " \n\r\t\v\x00" ) ) {
			return new WP_Error( 'pinax_csv', 'Το CSV είναι κενό ή δεν διαβάζεται.' );
		}
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$conv = function_exists( 'iconv' ) ? @iconv( 'CP1253', 'UTF-8//IGNORE', $raw ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$raw  = ( false !== $conv && '' !== $conv ) ? $conv : mb_convert_encoding( $raw, 'UTF-8', 'ISO-8859-7' );
		}

		$first  = strtok( $raw, "\n" );
		$counts = array(
			';'  => substr_count( $first, ';' ),
			','  => substr_count( $first, ',' ),
			"\t" => substr_count( $first, "\t" ),
		);
		arsort( $counts );
		$delim = key( $counts );

		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $fh, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rewind( $fh );

		$grid = array();
		$r    = 0;
		$filled = 0;
		while ( $r < Pinax_Reader::MAX_ROWS && false !== ( $line = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) ) { // phpcs:ignore
			foreach ( $line as $i => $v ) {
				if ( $i >= Pinax_Reader::MAX_COLS ) {
					break;
				}
				$v = Pinax_Reader::clean( (string) $v );
				if ( '' === $v ) {
					continue;
				}
				if ( ++$filled > Pinax_Reader::MAX_CELLS ) {
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					return new WP_Error( 'pinax_big', Pinax_Reader::TOO_BIG );
				}
				$grid[ $r ][ $i ] = Pinax_Reader::cell( $v );
			}
			++$r;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return array(
			'grid'   => $grid,
			'merges' => array(),
		);
	}
}
