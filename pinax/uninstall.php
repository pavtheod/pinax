<?php
/**
 * Καθαρισμός όταν διαγράφεται το plugin από τη Διαχείριση.
 *
 * @package Pinax
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$pinax_index = get_option( 'pinax_index', array() );
if ( is_array( $pinax_index ) ) {
	foreach ( array_keys( $pinax_index ) as $pinax_id ) {
		delete_option( 'pinax_table_' . $pinax_id );
		delete_option( 'pinax_draft_' . $pinax_id );
	}
}
delete_option( 'pinax_index' );
delete_option( 'pinax_global' );
delete_site_transient( 'pinax_latest_release' );
