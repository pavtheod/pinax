<?php
/**
 * Plugin Name:       Pinax: Πίνακες από Excel & Word
 * Description:       Ανεβάζετε έναν πίνακα από Excel ή Word και εμφανίζεται στο site με καθαρό και ενιαίο στυλ, μαζί με δυνατότητα αναζήτησης και εκτύπωσης. Κάθε πίνακας διαθέτει το δικό του shortcode, το οποίο μπορείτε να τοποθετήσετε σε οποιαδήποτε σελίδα θέλετε.
 * Plugin URI:        https://github.com/pavtheod/pinax
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Pavlos Theodorou
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pinax
 * Update URI:        https://github.com/pavtheod/pinax
 *
 * @package Pinax
 */

/*
 * Pinax: πίνακες από Excel/Word στο WordPress.
 *
 * Αυτό το πρόγραμμα είναι ελεύθερο λογισμικό: μπορείτε να το αναδιανείμετε ή/και να το
 * τροποποιήσετε σύμφωνα με τους όρους της GNU General Public License, έκδοση 2 ή
 * (κατ' επιλογή σας) οποιασδήποτε νεότερης έκδοσης. Δείτε το αρχείο LICENSE.
 */

defined( 'ABSPATH' ) || exit;

define( 'PINAX_VERSION', '1.2.0' );
define( 'PINAX_FILE', __FILE__ );
define( 'PINAX_URL', plugin_dir_url( __FILE__ ) );
define( 'PINAX_DIR', plugin_dir_path( __FILE__ ) );
define( 'PINAX_CAP', 'edit_pages' ); // Διαχειριστές και Συντάκτες.

require_once PINAX_DIR . 'includes/class-pinax-reader.php';
require_once PINAX_DIR . 'includes/class-pinax-color.php';
require_once PINAX_DIR . 'includes/class-pinax-xlsx.php';
require_once PINAX_DIR . 'includes/class-pinax-docx.php';
require_once PINAX_DIR . 'includes/class-pinax-csv.php';
require_once PINAX_DIR . 'includes/class-pinax-analyzer.php';
require_once PINAX_DIR . 'includes/class-pinax-store.php';
require_once PINAX_DIR . 'includes/class-pinax-render.php';
require_once PINAX_DIR . 'includes/class-pinax-updater.php';

Pinax_Render::init();
Pinax_Updater::init(); // Και εκτός διαχείρισης: ο έλεγχος ενημερώσεων τρέχει και από το wp-cron.

if ( is_admin() ) {
	require_once PINAX_DIR . 'includes/class-pinax-admin.php';
	Pinax_Admin::init();
}
