<?php
/**
 * Διαχείριση: λίστα πινάκων, ανέβασμα, προεπισκόπηση, δημοσίευση, ρυθμίσεις.
 *
 * @package Pinax
 */

defined( 'ABSPATH' ) || exit;

class Pinax_Admin {

	const SLUG     = 'pinax';
	const MAX_SIZE = 10485760; // 10 MB

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'create', 'upload', 'reanalyze', 'publish', 'discard', 'save', 'delete', 'global' ) as $action ) {
			add_action( 'admin_post_pinax_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( PINAX_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'requirements_notice' ) );
	}

	public static function menu() {
		add_menu_page( 'Pinax: Πίνακες από Excel & Word', 'Pinax', PINAX_CAP, self::SLUG, array( __CLASS__, 'page' ), 'dashicons-editor-table', 21 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Ρυθμίσεις</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'pinax', PINAX_URL . 'assets/style.css', array(), PINAX_VERSION );
		wp_enqueue_style( 'pinax-admin', PINAX_URL . 'assets/admin.css', array(), PINAX_VERSION );
		wp_enqueue_script( 'pinax', PINAX_URL . 'assets/front.js', array(), PINAX_VERSION, true );
		wp_enqueue_script( 'pinax-admin', PINAX_URL . 'assets/admin.js', array(), PINAX_VERSION, true );
	}

	/** Μέγιστο μέγεθος ανεβάσματος: το μικρότερο από το όριο του server και τα 10 MB. */
	private static function max_upload() {
		return (int) min( wp_max_upload_size(), self::MAX_SIZE );
	}

	/** Ελλείψεις του server που εμποδίζουν το plugin. */
	public static function missing_requirements() {
		$missing = array();
		if ( ! class_exists( 'ZipArchive' ) ) {
			$missing[] = 'ZipArchive (zip)';
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			$missing[] = 'DOM (xml)';
		}
		if ( ! function_exists( 'mb_strtolower' ) ) {
			$missing[] = 'mbstring';
		}
		return $missing;
	}

	public static function requirements_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'toplevel_page_' . self::SLUG ), true ) || ! current_user_can( PINAX_CAP ) ) {
			return;
		}
		$missing = self::missing_requirements();
		if ( $missing ) {
			echo '<div class="notice notice-error"><p><strong>Pinax:</strong> ο server δεν έχει τις επεκτάσεις PHP: ' . esc_html( implode( ', ', $missing ) ) . '. Χωρίς αυτές δεν μπορούν να διαβαστούν αρχεία. Ζητήστε από τον πάροχο φιλοξενίας να τις ενεργοποιήσει.</p></div>';
		}
	}

	private static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/* ------------------------------------------------------------------ */
	/* Σελίδες                                                            */
	/* ------------------------------------------------------------------ */

	public static function page() {
		if ( ! current_user_can( PINAX_CAP ) ) {
			return;
		}
		$id = isset( $_GET['table'] ) ? Pinax_Store::clean_id( wp_unslash( $_GET['table'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		echo '<div class="wrap px-admin">';
		self::notices();
		if ( '' !== $id && Pinax_Store::exists( $id ) ) {
			self::table_page( $id );
		} else {
			self::list_page();
		}
		echo '</div>';
	}

	private static function notices() {
		$key = 'pinax_error_' . get_current_user_id();
		$err = get_transient( $key );
		if ( $err ) {
			delete_transient( $key );
			echo '<div class="notice notice-error"><p>' . esc_html( $err ) . '</p></div>';
		}
		$msgs = array(
			'created'   => 'Ο πίνακας δημιουργήθηκε. Ανεβάστε τώρα το αρχείο του.',
			'published' => 'Δημοσιεύτηκε! Η σελίδα δείχνει πλέον τον νέο πίνακα. (Αν το site έχει plugin cache, αδειάστε την.)',
			'discarded' => 'Η προεπισκόπηση ακυρώθηκε. Η σελίδα δεν άλλαξε.',
			'saved'     => 'Οι ρυθμίσεις αποθηκεύτηκαν.',
			'deleted'   => 'Ο πίνακας διαγράφηκε.',
		);
		$msg = isset( $_GET['pinax_msg'] ) ? sanitize_key( wp_unslash( $_GET['pinax_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $msgs[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msgs[ $msg ] ) . '</p></div>';
		}
	}

	private static function list_page() {
		$index = Pinax_Store::index();
		$g     = Pinax_Store::global_settings();
		?>
		<h1 class="wp-heading-inline">Pinax <span class="px-subtitle">Πίνακες από Excel &amp; Word</span></h1>
		<p class="px-lead">Ανεβάζετε έναν πίνακα από Excel ή Word και εμφανίζεται στο site με καθαρό και ενιαίο στυλ, μαζί με δυνατότητα αναζήτησης και εκτύπωσης. Κάθε πίνακας διαθέτει το δικό του shortcode, το οποίο μπορείτε να τοποθετήσετε σε οποιαδήποτε σελίδα θέλετε.</p>

		<div class="px-card">
			<h2>Νέος πίνακας</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="px-inline-form">
				<?php wp_nonce_field( 'pinax_create' ); ?>
				<input type="hidden" name="action" value="pinax_create">
				<label for="pinax_new_name" class="px-sr">Όνομα πίνακα</label>
				<input type="text" id="pinax_new_name" name="name" class="regular-text" placeholder="π.χ. Ωράριο λειτουργίας" required maxlength="120">
				<button type="submit" class="button button-primary">Δημιουργία</button>
			</form>
		</div>

		<div class="px-card">
			<h2>Οι πίνακές σας</h2>
			<?php if ( ! $index ) : ?>
				<p>Δεν υπάρχει ακόμη κανένας πίνακας. Δημιουργήστε τον πρώτο παραπάνω.</p>
			<?php else : ?>
				<table class="widefat striped px-list">
					<thead>
						<tr>
							<th>Όνομα</th>
							<th>Shortcode (για τη σελίδα)</th>
							<th>Γραμμές</th>
							<th>Κατάσταση</th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $index as $id => $name ) :
							$t     = Pinax_Store::get( $id );
							$draft = Pinax_Store::get_draft( $id );
							$link  = self::url( array( 'table' => $id ) );
							?>
							<tr>
								<td><strong><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $name ); ?></a></strong></td>
								<td><?php self::shortcode_box( $id ); ?></td>
								<td><?php echo $t && ! empty( $t['data']['rows'] ) ? (int) count( $t['data']['rows'] ) : '–'; ?></td>
								<td>
									<?php if ( $t && ! empty( $t['data']['rows'] ) ) : ?>
										<span class="px-badge px-badge-ok">Δημοσιευμένος</span>
										<span class="px-muted"><?php echo esc_html( wp_date( 'd/m/Y', (int) $t['updated'] ) ); ?></span>
									<?php else : ?>
										<span class="px-badge">Χωρίς δεδομένα</span>
									<?php endif; ?>
									<?php if ( $draft ) : ?>
										<span class="px-badge px-badge-wait">Προεπισκόπηση σε αναμονή</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="px-card">
			<h2>Γενικές ρυθμίσεις</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="px-inline-form">
				<?php wp_nonce_field( 'pinax_global' ); ?>
				<input type="hidden" name="action" value="pinax_global">
				<label for="pinax_head">Χρώμα επικεφαλίδας όλων των πινάκων:</label>
				<input type="color" id="pinax_head" name="head" value="<?php echo esc_attr( Pinax_Color::sanitize( $g['head'], Pinax_Store::DEFAULT_HEAD ) ); ?>">
				<button type="submit" class="button">Αποθήκευση</button>
				<button type="submit" name="reset" value="1" class="button-link">Επαναφορά</button>
			</form>
		</div>
		<?php
	}

	private static function shortcode_box( $id ) {
		$code = '[pinakas id="' . $id . '"]';
		?>
		<span class="px-code">
			<code><?php echo esc_html( $code ); ?></code>
			<button type="button" class="button button-small px-copy" data-copy="<?php echo esc_attr( $code ); ?>">Αντιγραφή</button>
		</span>
		<?php
	}

	private static function table_page( $id ) {
		$t     = Pinax_Store::get( $id );
		$draft = Pinax_Store::get_draft( $id );
		$s     = $t['settings'];
		$data  = $t['data'];
		$post  = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">← Όλοι οι πίνακες</a></p>
		<h1><?php echo esc_html( $t['name'] ); ?></h1>
		<p>Shortcode για τη σελίδα: <?php self::shortcode_box( $id ); ?></p>

		<?php if ( $draft && ! empty( $draft['result'] ) ) : ?>
			<?php $res = $draft['result']; ?>
			<div class="px-card px-card-preview">
				<h2>Βήμα 2: Ελέγξτε και δημοσιεύστε</h2>
				<p>
					Αρχείο: <strong><?php echo esc_html( $draft['source'] ); ?></strong> ·
					<strong><?php echo (int) count( $res['rows'] ); ?> γραμμές</strong>, <?php echo (int) $res['ncols']; ?> στήλες.
					<?php if ( $data && ! empty( $data['rows'] ) ) : ?>
						<span class="px-muted">(Τώρα στη σελίδα: <?php echo (int) count( $data['rows'] ); ?> γραμμές.)</span>
					<?php endif; ?>
				</p>

				<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" class="px-reanalyze">
					<?php wp_nonce_field( 'pinax_reanalyze_' . $id ); ?>
					<input type="hidden" name="action" value="pinax_reanalyze">
					<input type="hidden" name="table" value="<?php echo esc_attr( $id ); ?>">
					<?php if ( count( $draft['labels'] ) > 1 ) : ?>
						<p>
							<label for="pinax_sheet"><strong>Πίνακας του εγγράφου:</strong></label><br>
							<select id="pinax_sheet" name="sheet">
								<?php foreach ( $draft['labels'] as $i => $label ) : ?>
									<option value="<?php echo (int) $i; ?>" <?php selected( (int) $draft['sheet'], $i ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endif; ?>
					<p>
						<label for="pinax_header"><strong>Γραμμή επικεφαλίδων:</strong></label><br>
						<select id="pinax_header" name="header">
							<option value="auto" <?php selected( 'auto', (string) $draft['header'] ); ?>>
								<?php echo esc_html( $res['auto_row'] >= 0 ? sprintf( 'Αυτόματα (γραμμή %d)', $res['auto_row'] + 1 ) : 'Αυτόματα' ); ?>
							</option>
							<?php foreach ( $res['candidates'] as $r => $text ) : ?>
								<option value="<?php echo (int) $r; ?>" <?php selected( (string) $r, (string) $draft['header'] ); ?>><?php echo esc_html( sprintf( 'Γραμμή %d: %s', $r + 1, $text ) ); ?></option>
							<?php endforeach; ?>
							<option value="none" <?php selected( 'none', (string) $draft['header'] ); ?>>Χωρίς επικεφαλίδα</option>
						</select>
						<button type="submit" class="button">Εφαρμογή</button>
					</p>
				</form>

				<?php foreach ( $res['warnings'] as $w ) : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( $w ); ?></p></div>
				<?php endforeach; ?>
				<?php if ( ! empty( $res['skipped'] ) ) : ?>
					<details class="px-skipped">
						<summary>Γραμμές του αρχείου που δεν μπήκαν στον πίνακα (<?php echo (int) count( $res['skipped'] ); ?>)</summary>
						<ul>
							<?php foreach ( $res['skipped'] as $sk ) : ?>
								<li>Γραμμή <?php echo (int) $sk['line']; ?>: <?php echo esc_html( $sk['text'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>

				<div class="px-actions">
					<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>">
						<?php wp_nonce_field( 'pinax_publish_' . $id ); ?>
						<input type="hidden" name="action" value="pinax_publish">
						<input type="hidden" name="table" value="<?php echo esc_attr( $id ); ?>">
						<button type="submit" class="button button-primary button-hero">✓ Δημοσίευση στη σελίδα</button>
					</form>
					<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>">
						<?php wp_nonce_field( 'pinax_discard_' . $id ); ?>
						<input type="hidden" name="action" value="pinax_discard">
						<input type="hidden" name="table" value="<?php echo esc_attr( $id ); ?>">
						<button type="submit" class="button button-hero">Ακύρωση</button>
					</form>
				</div>

				<h3>Προεπισκόπηση</h3>
				<div class="px-preview-box">
					<?php echo Pinax_Render::render( $res, $s, array( 'preview' => true, 'updated' => time() ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			</div>
		<?php endif; ?>

		<div class="px-card">
			<h2><?php echo ( $draft || ! empty( $data['rows'] ) ) ? 'Ανέβασμα νέου αρχείου' : 'Βήμα 1: Ανεβάστε το αρχείο'; ?></h2>
			<?php $max = self::max_upload(); ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( add_query_arg( array( 'action' => 'pinax_upload', 'table' => $id ), admin_url( 'admin-post.php' ) ) ); ?>" class="px-inline-form px-upload-form" data-max="<?php echo (int) $max; ?>" data-max-text="<?php echo esc_attr( size_format( $max ) ); ?>">
				<?php wp_nonce_field( 'pinax_upload_' . $id ); ?>
				<input type="hidden" name="action" value="pinax_upload">
				<input type="hidden" name="table" value="<?php echo esc_attr( $id ); ?>">
				<input type="file" name="pinax_file" accept=".xlsx,.docx,.csv" required>
				<button type="submit" class="button button-primary">Ανέβασμα και προεπισκόπηση</button>
			</form>
			<p class="description">
				Μέγιστο μέγεθος αρχείου: <strong><?php echo esc_html( size_format( $max ) ); ?></strong>.<br>
				Excel (.xlsx): διαβάζεται το πρώτο φύλλο. Word (.docx): διαβάζονται οι πίνακες του εγγράφου και, αν είναι πολλοί, διαλέγετε ποιον θέλετε. Δεκτά και αρχεία CSV.<br>
				Οι γραμμές τίτλου πάνω από τον πίνακα γίνονται αυτόματα τίτλος και υπότιτλος. Τα χρώματα των κελιών περνούν στο site σε πιο απαλή απόχρωση (το γκρι αγνοείται).<br>
				Η σελίδα <strong>δεν αλλάζει</strong> μέχρι να πατήσετε «Δημοσίευση».
			</p>
		</div>

		<div class="px-card">
			<h2>Τι δείχνει τώρα η σελίδα</h2>
			<?php if ( ! empty( $data['rows'] ) ) : ?>
				<p>
					<strong><?php echo (int) count( $data['rows'] ); ?> γραμμές</strong> ·
					από το αρχείο <strong><?php echo esc_html( $t['source'] ); ?></strong> ·
					δημοσιεύτηκε <?php echo esc_html( wp_date( 'd/m/Y H:i', (int) $t['updated'] ) ); ?>
				</p>
			<?php else : ?>
				<p>Δεν έχει δημοσιευτεί ακόμη.</p>
			<?php endif; ?>
		</div>

		<div class="px-card">
			<h2>Ρυθμίσεις πίνακα</h2>
			<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>">
				<?php wp_nonce_field( 'pinax_save_' . $id ); ?>
				<input type="hidden" name="action" value="pinax_save">
				<input type="hidden" name="table" value="<?php echo esc_attr( $id ); ?>">
				<?php
				$file_title = $draft && ! empty( $draft['result'] ) ? $draft['result']['title'] : ( $data ? $data['title'] : '' );
				$file_sub   = $draft && ! empty( $draft['result'] ) ? $draft['result']['subtitle'] : ( $data ? $data['subtitle'] : '' );
				?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pinax_name">Όνομα (μόνο για τη Διαχείριση)</label></th>
						<td><input type="text" id="pinax_name" name="name" class="regular-text" value="<?php echo esc_attr( $t['name'] ); ?>" required maxlength="120"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pinax_title">Τίτλος</label></th>
						<td>
							<input type="text" id="pinax_title" name="title" class="large-text" value="<?php echo esc_attr( $s['title'] ); ?>" placeholder="<?php echo esc_attr( $file_title ); ?>">
							<p class="description"><?php echo '' !== $file_title ? esc_html( sprintf( 'Αφήστε κενό για να χρησιμοποιηθεί ο τίτλος του αρχείου: «%s».', $file_title ) ) : 'Αφήστε κενό για να μην εμφανίζεται τίτλος.'; ?></p>
							<label class="px-hide"><input type="checkbox" name="hide_title" value="1" <?php checked( ! empty( $s['hide_title'] ) ); ?>> Να μην εμφανίζεται τίτλος</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pinax_subtitle">Υπότιτλος</label></th>
						<td>
							<input type="text" id="pinax_subtitle" name="subtitle" class="large-text" value="<?php echo esc_attr( $s['subtitle'] ); ?>" placeholder="<?php echo esc_attr( $file_sub ); ?>">
							<p class="description"><?php echo '' !== $file_sub ? esc_html( sprintf( 'Αφήστε κενό για να χρησιμοποιηθεί ο υπότιτλος του αρχείου: «%s».', $file_sub ) ) : 'Προαιρετικός, π.χ. «Χειμερινό ωράριο 2026–2027».'; ?></p>
							<label class="px-hide"><input type="checkbox" name="hide_subtitle" value="1" <?php checked( ! empty( $s['hide_subtitle'] ) ); ?>> Να μην εμφανίζεται υπότιτλος</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pinax_note">Σημείωση κάτω από τον πίνακα</label></th>
						<td><textarea id="pinax_note" name="note" class="large-text" rows="3"><?php echo esc_textarea( $s['note'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row">Εμφάνιση</th>
						<td>
							<label><input type="checkbox" name="search" value="1" <?php checked( ! empty( $s['search'] ) ); ?>> Πεδίο αναζήτησης <span class="px-muted">(εμφανίζεται μόνο όταν ο πίνακας έχει πάνω από <?php echo (int) Pinax_Render::SEARCH_MIN_ROWS; ?> γραμμές)</span></label><br>
							<label><input type="checkbox" name="print" value="1" <?php checked( ! empty( $s['print'] ) ); ?>> Κουμπί «Εκτύπωση / Αποθήκευση PDF»</label><br>
							<label><input type="checkbox" name="updated" value="1" <?php checked( ! empty( $s['updated'] ) ); ?>> «Τελευταία ενημέρωση: …»</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pinax_width">Πλάτος πίνακα</label></th>
						<td>
							<select id="pinax_width" name="width">
								<?php
								$width_options = array(
									'auto'   => 'Αυτόματο (προτείνεται)',
									'narrow' => 'Στενό',
									'medium' => 'Μεσαίο',
									'full'   => 'Πλήρες πλάτος',
									'wider'  => 'Φαρδύτερο από τη στήλη της σελίδας',
								);
								foreach ( $width_options as $value => $label ) {
									printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $s['width'], $value, false ), esc_html( $label ) );
								}
								?>
							</select>
							<p class="description">«Αυτόματο»: οι μικροί πίνακες (π.χ. ένα ωράριο) δεν απλώνονται σε όλη τη σελίδα. Στο Word χρησιμοποιείται το πλάτος που έχει ο πίνακας στο αρχείο. Ο πίνακας στοιχίζεται αριστερά. Το «Φαρδύτερο» δουλεύει στα θέματα που υποστηρίζουν «φαρδιά στοίχιση».</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Αποθήκευση ρυθμίσεων' ); ?>
			</form>
		</div>

		<div class="px-card px-card-danger">
			<h2>Διαγραφή πίνακα</h2>
			<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" class="px-delete-form" data-confirm="<?php echo esc_attr( sprintf( 'Να διαγραφεί οριστικά ο πίνακας «%s»; Οι σελίδες που έχουν το shortcode του θα σταματήσουν να τον δείχνουν.', $t['name'] ) ); ?>">
				<?php wp_nonce_field( 'pinax_delete_' . $id ); ?>
				<input type="hidden" name="action" value="pinax_delete">
				<input type="hidden" name="table" value="<?php echo esc_attr( $id ); ?>">
				<button type="submit" class="button button-link-delete">Διαγραφή αυτού του πίνακα</button>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Ενέργειες                                                          */
	/* ------------------------------------------------------------------ */

	/** Έλεγχος δικαιώματος και nonce. Επιστρέφει το id του πίνακα (αν ζητηθεί). */
	private static function guard( $action, $needs_table = true ) {
		if ( ! current_user_can( PINAX_CAP ) ) {
			wp_die( 'Δεν έχετε δικαίωμα γι’ αυτή την ενέργεια.', '', array( 'response' => 403 ) );
		}
		if ( ! $needs_table ) {
			check_admin_referer( $action );
			return '';
		}
		$id = self::request_table();
		check_admin_referer( $action . '_' . $id );
		if ( '' === $id || ! Pinax_Store::exists( $id ) ) {
			wp_die( 'Ο πίνακας δεν βρέθηκε.', '', array( 'response' => 404 ) );
		}
		return $id;
	}

	private static function request_table() {
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( isset( $_POST['table'] ) ) {
			return Pinax_Store::clean_id( wp_unslash( $_POST['table'] ) );
		}
		return isset( $_GET['table'] ) ? Pinax_Store::clean_id( wp_unslash( $_GET['table'] ) ) : '';
		// phpcs:enable
	}

	private static function back( $id = '', $msg = '' ) {
		$args = array();
		if ( '' !== $id ) {
			$args['table'] = $id;
		}
		if ( '' !== $msg ) {
			$args['pinax_msg'] = $msg;
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	private static function fail( $id, $message ) {
		set_transient( 'pinax_error_' . get_current_user_id(), $message, 300 );
		self::back( $id );
	}

	public static function handle_create() {
		self::guard( 'pinax_create', false );
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $name ) {
			self::fail( '', 'Δώστε ένα όνομα για τον πίνακα.' );
		}
		$id = Pinax_Store::create( mb_substr( $name, 0, 120 ) );
		self::back( $id, 'created' );
	}

	public static function handle_upload() {
		// Αν το αρχείο ξεπερνά το όριο του server (post_max_size), η PHP πετά όλη τη φόρμα:
		// δείχνουμε καθαρό μήνυμα αντί για λευκή σελίδα.
		if ( empty( $_POST ) && ! empty( $_SERVER['CONTENT_LENGTH'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! current_user_can( PINAX_CAP ) ) {
				wp_die( 'Δεν έχετε δικαίωμα γι’ αυτή την ενέργεια.', '', array( 'response' => 403 ) );
			}
			$id = self::request_table();
			self::fail( Pinax_Store::exists( $id ) ? $id : '', sprintf( 'Το αρχείο είναι μεγαλύτερο από το όριο του server (%s). Μικρύνετε το αρχείο (π.χ. αφαιρέστε εικόνες) ή ζητήστε από τον πάροχο να αυξήσει το όριο ανεβάσματος.', size_format( self::max_upload() ) ) );
		}
		$id = self::guard( 'pinax_upload' );
		wp_raise_memory_limit( 'admin' );
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( empty( $_FILES['pinax_file'] ) || ! isset( $_FILES['pinax_file']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['pinax_file']['error'] ) {
			$code = isset( $_FILES['pinax_file']['error'] ) ? (int) $_FILES['pinax_file']['error'] : 0;
			self::fail( $id, in_array( $code, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'Το αρχείο είναι πολύ μεγάλο για τον server.' : 'Δεν επιλέχθηκε αρχείο ή το ανέβασμα απέτυχε.' );
		}
		$file = $_FILES['pinax_file'];
		// phpcs:enable
		$name = sanitize_file_name( wp_unslash( $file['name'] ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'xlsx', 'docx', 'csv' ), true ) ) {
			$hint = in_array( $ext, array( 'xls', 'doc' ), true )
				? ' Το παλιό .' . $ext . ' δεν υποστηρίζεται: ανοίξτε το και κάντε «Αποθήκευση ως» .' . $ext . 'x.'
				: '';
			self::fail( $id, 'Επιλέξτε αρχείο Excel (.xlsx), Word (.docx) ή CSV.' . $hint );
		}
		if ( (int) $file['size'] > self::MAX_SIZE ) {
			self::fail( $id, 'Το αρχείο είναι πολύ μεγάλο (όριο 10 MB).' );
		}
		if ( self::missing_requirements() ) {
			self::fail( $id, 'Ο server δεν έχει τις επεκτάσεις PHP που χρειάζονται (' . implode( ', ', self::missing_requirements() ) . ').' );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::fail( $id, 'Μη έγκυρο αρχείο.' );
		}

		$read = Pinax_Reader::read( $file['tmp_name'], $ext );
		if ( is_wp_error( $read ) ) {
			self::fail( $id, $read->get_error_message() );
		}
		$sheet = isset( $read['default'] ) ? (int) $read['default'] : 0;
		$res   = Pinax_Analyzer::analyze( $read['sheets'][ $sheet ], 'auto' );
		if ( is_wp_error( $res ) ) {
			// Σε Word με πολλούς πίνακες, δοκιμάζουμε τους υπόλοιπους.
			foreach ( $read['sheets'] as $i => $sh ) {
				$try = Pinax_Analyzer::analyze( $sh, 'auto' );
				if ( ! is_wp_error( $try ) ) {
					$sheet = $i;
					$res   = $try;
					break;
				}
			}
			if ( is_wp_error( $res ) ) {
				self::fail( $id, $res->get_error_message() );
			}
		}

		$saved = Pinax_Store::save_draft(
			$id,
			array(
				'source' => $name,
				'sheets' => $read['sheets'],
				'labels' => $read['labels'],
				'sheet'  => $sheet,
				'header' => 'auto',
				'result' => $res,
			)
		);
		if ( ! $saved ) {
			self::fail( $id, 'Το αρχείο διαβάστηκε, αλλά η βάση δεδομένων του site δεν δέχτηκε την προεπισκόπηση (πιθανώς είναι πολύ μεγάλη). Δοκιμάστε με μικρότερο πίνακα.' );
		}
		self::back( $id );
	}

	public static function handle_reanalyze() {
		$id    = self::guard( 'pinax_reanalyze' );
		wp_raise_memory_limit( 'admin' );
		$draft = Pinax_Store::get_draft( $id );
		if ( ! $draft ) {
			self::fail( $id, 'Δεν υπάρχει προεπισκόπηση. Ανεβάστε ξανά το αρχείο.' );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$sheet  = isset( $_POST['sheet'] ) ? (int) $_POST['sheet'] : (int) $draft['sheet'];
		$header = isset( $_POST['header'] ) ? sanitize_key( wp_unslash( $_POST['header'] ) ) : 'auto';
		// phpcs:enable
		if ( ! isset( $draft['sheets'][ $sheet ] ) ) {
			$sheet = 0;
		}
		if ( $sheet !== (int) $draft['sheet'] ) {
			$header = 'auto'; // Άλλος πίνακας: ξεκινάμε από αυτόματη επικεφαλίδα.
		}
		if ( 'auto' !== $header && 'none' !== $header ) {
			$header = (int) $header;
		}
		$res = Pinax_Analyzer::analyze( $draft['sheets'][ $sheet ], $header );
		if ( is_wp_error( $res ) ) {
			self::fail( $id, $res->get_error_message() );
		}
		$draft['sheet']  = $sheet;
		$draft['header'] = $header;
		$draft['result'] = $res;
		if ( ! Pinax_Store::save_draft( $id, $draft ) ) {
			self::fail( $id, 'Η αλλαγή δεν αποθηκεύτηκε στη βάση δεδομένων. Δοκιμάστε ξανά.' );
		}
		self::back( $id );
	}

	public static function handle_publish() {
		$id    = self::guard( 'pinax_publish' );
		$draft = Pinax_Store::get_draft( $id );
		if ( ! $draft || empty( $draft['result'] ) ) {
			self::fail( $id, 'Δεν υπάρχει προεπισκόπηση για δημοσίευση.' );
		}
		$t   = Pinax_Store::get( $id );
		$res = $draft['result'];
		unset( $res['candidates'], $res['skipped'], $res['warnings'], $res['auto_row'] );
		$t['data']    = $res;
		$t['source']  = $draft['source'];
		$t['updated'] = time();
		if ( ! Pinax_Store::save( $id, $t ) ) {
			self::fail( $id, 'Η δημοσίευση απέτυχε: η βάση δεδομένων του site δεν δέχτηκε τον πίνακα. Η σελίδα δεν άλλαξε.' );
		}
		Pinax_Store::delete_draft( $id );
		self::back( $id, 'published' );
	}

	public static function handle_discard() {
		$id = self::guard( 'pinax_discard' );
		Pinax_Store::delete_draft( $id );
		self::back( $id, 'discarded' );
	}

	public static function handle_save() {
		$id = self::guard( 'pinax_save' );
		$t  = Pinax_Store::get( $id );
		// phpcs:disable WordPress.Security.NonceVerification
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' !== $name ) {
			$t['name'] = mb_substr( $name, 0, 120 );
		}
		$t['settings'] = array(
			'title'    => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'subtitle' => isset( $_POST['subtitle'] ) ? sanitize_text_field( wp_unslash( $_POST['subtitle'] ) ) : '',
			'note'     => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
			'search'   => empty( $_POST['search'] ) ? 0 : 1,
			'print'    => empty( $_POST['print'] ) ? 0 : 1,
			'updated'  => empty( $_POST['updated'] ) ? 0 : 1,
			'width'    => ( isset( $_POST['width'] ) && in_array( wp_unslash( $_POST['width'] ), array( 'auto', 'narrow', 'medium', 'full', 'wider' ), true ) ) ? sanitize_key( wp_unslash( $_POST['width'] ) ) : 'auto',
			'hide_title'    => empty( $_POST['hide_title'] ) ? 0 : 1,
			'hide_subtitle' => empty( $_POST['hide_subtitle'] ) ? 0 : 1,
		);
		// phpcs:enable
		if ( ! Pinax_Store::save( $id, $t ) ) {
			self::fail( $id, 'Οι ρυθμίσεις δεν αποθηκεύτηκαν. Δοκιμάστε ξανά.' );
		}
		self::back( $id, 'saved' );
	}

	public static function handle_delete() {
		$id = self::guard( 'pinax_delete' );
		Pinax_Store::delete( $id );
		self::back( '', 'deleted' );
	}

	public static function handle_global() {
		self::guard( 'pinax_global', false );
		// phpcs:disable WordPress.Security.NonceVerification
		$head = ! empty( $_POST['reset'] ) ? Pinax_Store::DEFAULT_HEAD : ( isset( $_POST['head'] ) ? sanitize_hex_color( wp_unslash( $_POST['head'] ) ) : '' );
		// phpcs:enable
		update_option( 'pinax_global', array( 'head' => $head ? $head : Pinax_Store::DEFAULT_HEAD ) );
		self::back( '', 'saved' );
	}
}
