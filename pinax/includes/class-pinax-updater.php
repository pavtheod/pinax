<?php
/**
 * Ενημερώσεις του plugin από τα Releases του GitHub.
 *
 * Το WordPress ρωτά (μερικές φορές τη μέρα) αν υπάρχει νεότερη έκδοση. Εδώ απαντάμε κοιτάζοντας
 * το τελευταίο release του αποθετηρίου. Το πακέτο που κατεβαίνει είναι το pinax.zip που
 * επισυνάπτεται στο release. Δεν στέλνεται καμία πληροφορία για το site πέρα από το ότι γίνεται
 * ένα απλό αίτημα ανάγνωσης στο GitHub.
 *
 * @package Pinax
 */

defined( 'ABSPATH' ) || exit;

class Pinax_Updater {

	const REPO      = 'pavtheod/pinax';
	const ASSET     = 'pinax.zip';
	const TRANSIENT = 'pinax_latest_release';

	public static function init() {
		// Το hostname προέρχεται από την κεφαλίδα «Update URI» του plugin (https://github.com/...).
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'offer_update' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_pinax_check_update', array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Τελευταίο release, με προσωρινή αποθήκευση: 6 ώρες όταν πήγε καλά, 30 λεπτά όταν απέτυχε.
	 *
	 * @param bool $force Αγνόηση της προσωρινής αποθήκευσης.
	 * @return array
	 */
	private static function latest( $force = false ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$result = self::fetch();
		set_site_transient( self::TRANSIENT, $result, empty( $result['error'] ) ? 6 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Ρωτά το GitHub. Επιστρέφει ['version','package','notes'] ή ['error' => κωδικός].
	 *
	 * @return array
	 */
	private static function fetch() {
		$response = wp_remote_get(
			apply_filters( 'pinax_updater_api_url', 'https://api.github.com/repos/' . self::REPO . '/releases/latest' ),
			array(
				'timeout' => 8,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Pinax/' . PINAX_VERSION . '; ' . home_url(),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'network' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return array( 'error' => 'norelease' );
		}
		if ( 200 !== $code ) {
			return array( 'error' => 'http' );
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) || empty( $json['tag_name'] ) || ! is_string( $json['tag_name'] ) ) {
			return array( 'error' => 'format' );
		}
		$version = ltrim( $json['tag_name'], 'vV' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return array( 'error' => 'format' );
		}

		// Το πακέτο πρέπει να είναι το pinax.zip του ίδιου αποθετηρίου και τίποτα άλλο.
		$package = '';
		$prefix  = 'https://github.com/' . self::REPO . '/releases/download/';
		if ( ! empty( $json['assets'] ) && is_array( $json['assets'] ) ) {
			foreach ( $json['assets'] as $asset ) {
				if ( is_array( $asset ) && isset( $asset['name'], $asset['browser_download_url'] )
					&& self::ASSET === $asset['name'] && 0 === strpos( (string) $asset['browser_download_url'], $prefix ) ) {
					$package = (string) $asset['browser_download_url'];
					break;
				}
			}
		}
		if ( '' === $package ) {
			return array( 'error' => 'noasset' );
		}

		return array(
			'version' => $version,
			'package' => $package,
			'notes'   => isset( $json['body'] ) && is_string( $json['body'] ) ? $json['body'] : '',
		);
	}

	/**
	 * Απάντηση προς το WordPress: υπάρχει νεότερη έκδοση;
	 *
	 * @param mixed  $update      Η τρέχουσα τιμή του φίλτρου.
	 * @param array  $plugin_data Κεφαλίδες του plugin.
	 * @param string $plugin_file Το αρχείο του plugin (φάκελος/αρχείο).
	 * @return mixed
	 */
	public static function offer_update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( PINAX_FILE ) !== $plugin_file ) {
			return $update;
		}
		$latest = self::latest();
		if ( empty( $latest['version'] ) || empty( $latest['package'] ) ) {
			return false;
		}
		return array(
			'slug'         => 'pinax',
			'version'      => $latest['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $latest['package'],
			'requires_php' => '7.4',
		);
	}

	/**
	 * Το παράθυρο «Προβολή λεπτομερειών» της ενημέρωσης.
	 *
	 * @param mixed  $result Η τρέχουσα τιμή του φίλτρου.
	 * @param string $action Η ενέργεια.
	 * @param object $args   Παράμετροι.
	 * @return mixed
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || 'pinax' !== $args->slug ) {
			return $result;
		}
		$latest = self::latest();
		if ( empty( $latest['version'] ) ) {
			return $result;
		}
		$notes = '' !== trim( $latest['notes'] ) ? wpautop( esc_html( $latest['notes'] ) ) : '<p>—</p>';
		return (object) array(
			'name'          => 'Pinax',
			'slug'          => 'pinax',
			'version'       => $latest['version'],
			'author'        => 'Pavlos Theodorou',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'download_link' => $latest['package'],
			'sections'      => array(
				'description' => '<p>Πίνακες από Excel &amp; Word στο WordPress.</p>',
				'changelog'   => $notes,
			),
		);
	}

	/**
	 * Σύνδεσμος «Έλεγχος για ενημερώσεις» δίπλα στην έκδοση, στη λίστα των Προσθέτων.
	 *
	 * @param array  $meta Οι υπάρχοντες σύνδεσμοι.
	 * @param string $file Το αρχείο του plugin.
	 * @return array
	 */
	public static function row_meta( $meta, $file ) {
		if ( plugin_basename( PINAX_FILE ) === $file && current_user_can( 'update_plugins' ) ) {
			$url    = wp_nonce_url( admin_url( 'admin-post.php?action=pinax_check_update' ), 'pinax_check_update' );
			$meta[] = '<a href="' . esc_url( $url ) . '">Έλεγχος για ενημερώσεις</a>';
		}
		return $meta;
	}

	/** Ο έλεγχος που ζητά ο χρήστης: ξεχνά ό,τι είχε κρατηθεί και ρωτά το GitHub αμέσως. */
	public static function handle_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pinax_check_update' );
		self::latest( true );
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true ); // Ξαναχτίζει και τη λίστα ενημερώσεων του WordPress.
		}
		wp_safe_redirect( admin_url( 'plugins.php?pinax_checked=1' ) );
		exit;
	}

	/** Το μήνυμα μετά τον έλεγχο. */
	public static function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// Απλή σημαία εμφάνισης μηνύματος, δεν αλλάζει τίποτα.
		if ( ! $screen || 'plugins' !== $screen->id || ! isset( $_GET['pinax_checked'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$latest = self::latest();
		if ( ! empty( $latest['error'] ) ) {
			$messages = array(
				'network'   => 'Δεν ήταν δυνατή η επικοινωνία με το GitHub. Δοκιμάστε ξανά αργότερα.',
				'norelease' => 'Δεν υπάρχει ακόμη δημοσιευμένη έκδοση στο GitHub.',
				'noasset'   => 'Η τελευταία έκδοση στο GitHub δεν περιέχει το αρχείο pinax.zip.',
			);
			$text     = isset( $messages[ $latest['error'] ] ) ? $messages[ $latest['error'] ] : 'Το GitHub δεν απάντησε σωστά. Δοκιμάστε ξανά αργότερα.';
			echo '<div class="notice notice-warning is-dismissible"><p><strong>Pinax:</strong> ' . esc_html( $text ) . '</p></div>';
			return;
		}
		if ( version_compare( $latest['version'], PINAX_VERSION, '>' ) ) {
			$text  = sprintf( 'Βρέθηκε νέα έκδοση του Pinax (%s). Μπορείτε να την ενημερώσετε από τη λίστα των Προσθέτων.', $latest['version'] );
			$class = 'notice-info';
		} else {
			$text  = sprintf( 'Το Pinax είναι ενημερωμένο (έκδοση %s).', PINAX_VERSION );
			$class = 'notice-success';
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p><strong>Pinax:</strong> ' . esc_html( $text ) . '</p></div>';
	}
}
