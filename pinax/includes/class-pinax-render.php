<?php
/**
 * Εμφάνιση πίνακα στη σελίδα και καθαρή σελίδα εκτύπωσης.
 *
 * @package Pinax
 */

defined( 'ABSPATH' ) || exit;

class Pinax_Render {

	/** Πάνω από τόσες γραμμές εμφανίζεται η αναζήτηση (αν είναι ενεργή). */
	const SEARCH_MIN_ROWS = 10;

	public static function init() {
		add_shortcode( 'pinakas', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'print_view' ) );
	}

	public static function register_assets() {
		wp_register_style( 'pinax', PINAX_URL . 'assets/style.css', array(), PINAX_VERSION );
		wp_register_script( 'pinax', PINAX_URL . 'assets/front.js', array(), PINAX_VERSION, true );
		// Στυλ στο <head> όταν η σελίδα έχει το shortcode (για να μην «αναβοσβήνει»).
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && has_shortcode( $post->post_content, 'pinakas' ) ) {
				wp_enqueue_style( 'pinax' );
			}
		}
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => '' ), $atts, 'pinakas' );
		$id   = Pinax_Store::clean_id( $atts['id'] );
		$t    = '' !== $id ? Pinax_Store::get( $id ) : null;

		if ( ! $t || empty( $t['data']['rows'] ) ) {
			if ( current_user_can( PINAX_CAP ) ) {
				$msg = $t
					? sprintf( 'Ο πίνακας «%s» δεν έχει δημοσιευτεί ακόμη. Ανεβάστε αρχείο από τη Διαχείριση → Pinax.', $t['name'] )
					: sprintf( 'Δεν υπάρχει πίνακας με id «%s». Ελέγξτε το shortcode στη Διαχείριση → Pinax.', $id );
				return '<p class="px-admin-hint"><em>' . esc_html( $msg ) . '</em></p>';
			}
			return '';
		}

		if ( ! wp_style_is( 'pinax', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'pinax' );
		wp_enqueue_script( 'pinax' );

		return self::render( $t['data'], $t['settings'], array( 'print_url' => self::print_url( $id ), 'updated' => $t['updated'] ) );
	}

	/**
	 * HTML του πίνακα.
	 *
	 * @param array $data Ανάλυση (Pinax_Analyzer).
	 * @param array $s    Ρυθμίσεις πίνακα.
	 * @param array $opt  print_url, updated, preview.
	 */
	public static function render( $data, $s, $opt = array() ) {
		$opt     = wp_parse_args( $opt, array( 'print_url' => '', 'updated' => 0, 'preview' => false, 'printing' => false ) );
		$g       = Pinax_Store::global_settings();
		$head_bg = Pinax_Color::sanitize( $g['head'], Pinax_Store::DEFAULT_HEAD );
		$title   = ! empty( $s['hide_title'] ) ? '' : ( '' !== trim( $s['title'] ) ? $s['title'] : $data['title'] );
		$sub     = ! empty( $s['hide_subtitle'] ) ? '' : ( '' !== trim( $s['subtitle'] ) ? $s['subtitle'] : $data['subtitle'] );
		$ncols   = max( 1, (int) $data['ncols'] );
		$rows    = $data['rows'];
		$search  = ! empty( $s['search'] ) && count( $rows ) > self::SEARCH_MIN_ROWS && ! $opt['printing'];
		$cols    = $data['cols'];

		$mode    = isset( $s['width'] ) ? $s['width'] : 'auto';
		$classes = 'px-page';
		if ( 'wider' === $mode && ! $opt['preview'] ) {
			$classes .= ' alignwide';
		}
		if ( count( $rows ) > 20 ) {
			$classes .= ' px-many'; // Στην εκτύπωση: μικρότερα γράμματα ώστε να χωράει σε μία σελίδα.
		}
		// Στο κινητό: οι πίνακες με πολλές στήλες σύρονται πλάγια, οι μικροί χωράνε κανονικά.
		$min_w = $ncols >= 5 ? $ncols * 105 : 0;
		$style = sprintf( '--px-head:%s;--px-minw:%dpx', $head_bg, $min_w );
		$max_w = self::max_width( $data, $mode );
		if ( $max_w > 0 ) {
			$style .= sprintf( ';--px-maxw:%dpx', $max_w );
			if ( isset( $s['align'] ) && in_array( $s['align'], array( 'center', 'right' ), true ) ) {
				$classes .= ' px-align-' . $s['align']; // Μετακινεί τον πίνακα (όχι τον τίτλο) όταν είναι στενότερος από τη στήλη.
			}
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" style="<?php echo esc_attr( $style ); ?>">
			<?php if ( '' !== trim( $title ) || '' !== trim( $sub ) ) : ?>
				<div class="px-title">
					<div class="px-mark" aria-hidden="true"></div>
					<div>
						<?php if ( '' !== trim( $title ) ) : ?>
							<h2><?php echo esc_html( $title ); ?></h2>
						<?php endif; ?>
						<?php if ( '' !== trim( $sub ) ) : ?>
							<p><?php echo esc_html( $sub ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $search ) : ?>
				<div class="px-toolbar">
					<label class="px-search">
						<span class="px-sr">Αναζήτηση στον πίνακα</span>
						<svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M20 20l-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
						<input type="search" placeholder="Αναζήτηση…" autocomplete="off" data-px-search>
					</label>
					<span class="px-count" data-px-count aria-live="polite"></span>
				</div>
			<?php endif; ?>

			<div class="px-scroll" tabindex="0">
				<table class="px-table">
					<?php if ( ! empty( $data['head'] ) ) : ?>
						<thead>
							<tr>
								<?php foreach ( $data['head'] as $cell ) : ?>
									<?php echo self::cell( 'th', $cell, $cols, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php endforeach; ?>
							</tr>
						</thead>
					<?php endif; ?>
					<tbody>
						<?php
						$last = count( $rows ) - 1;
						foreach ( $rows as $i => $row ) :
							$tr = array();
							if ( 1 === $i % 2 ) {
								$tr[] = 'px-even';
							}
							if ( $i === $last ) {
								$tr[] = 'px-last';
							}
							?>
							<tr<?php echo $tr ? ' class="' . esc_attr( implode( ' ', $tr ) ) . '"' : ''; ?>>
								<?php foreach ( $row as $cell ) : ?>
									<?php echo self::cell( 'td', $cell, $cols, false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
						<?php if ( $search ) : ?>
							<tr class="px-empty" hidden><td colspan="<?php echo (int) $ncols; ?>">Δεν βρέθηκε κανένα αποτέλεσμα.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( '' !== trim( $s['note'] ) ) : ?>
				<div class="px-note">
					<span class="px-info" aria-hidden="true">i</span>
					<span><?php echo nl2br( esc_html( $s['note'] ) ); ?></span>
				</div>
			<?php endif; ?>

			<?php
			$show_print   = ! empty( $s['print'] ) && '' !== $opt['print_url'] && ! $opt['preview'] && ! $opt['printing'];
			$show_updated = ! empty( $s['updated'] ) && ! empty( $opt['updated'] );
			if ( $show_print || $show_updated ) :
				?>
				<div class="px-footer">
					<?php if ( $show_print ) : ?>
						<a class="px-print" href="<?php echo esc_url( $opt['print_url'] ); ?>" rel="nofollow" data-px-print>
							<svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18"><path d="M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2M7 14h10v7H7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
							Εκτύπωση / Αποθήκευση PDF
						</a>
					<?php endif; ?>
					<?php if ( $show_updated ) : ?>
						<span class="px-updated">Τελευταία ενημέρωση: <?php echo esc_html( wp_date( 'd/m/Y', (int) $opt['updated'] ) ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return trim( ob_get_clean() );
	}

	/** Ένα κελί (th/td) με τάξεις και χρώματα. */
	/**
	 * Μέγιστο πλάτος του πίνακα σε px (0 = όλο το πλάτος της στήλης της σελίδας).
	 *
	 * «Αυτόματο»: το πλάτος που έχει ο πίνακας στο Word, αν υπάρχει, και τουλάχιστον όσο
	 * χρειάζεται το περιεχόμενο. Πίνακες που βγαίνουν φαρδιοί μένουν σε πλήρες πλάτος.
	 *
	 * @param array  $data Ανάλυση (Pinax_Analyzer).
	 * @param string $mode auto | narrow | medium | full | wider.
	 * @return int
	 */
	public static function max_width( $data, $mode ) {
		switch ( $mode ) {
			case 'narrow':
				return 520;
			case 'medium':
				return 780;
			case 'full':
			case 'wider':
				return 0;
		}
		$w = max( (int) ( isset( $data['src_w'] ) ? $data['src_w'] : 0 ), self::estimate_width( $data ) );
		$w = max( 360, $w );
		return $w >= 900 ? 0 : $w;
	}

	/** Πλάτος που χρειάζεται το περιεχόμενο: το μακρύτερο κείμενο κάθε στήλης (μέχρι 32 χαρακτήρες). */
	private static function estimate_width( $data ) {
		$ncols = max( 1, (int) $data['ncols'] );
		$max   = array_fill( 0, $ncols, 0 );
		$lines = array_merge( empty( $data['head'] ) ? array() : array( $data['head'] ), $data['rows'] );
		foreach ( $lines as $cells ) {
			$i = 0;
			foreach ( $cells as $cell ) {
				$span = ! empty( $cell['cs'] ) ? (int) $cell['cs'] : 1;
				if ( 1 === $span && $i < $ncols ) {
					$max[ $i ] = max( $max[ $i ], min( 32, mb_strlen( trim( (string) $cell['v'] ) ) ) );
				}
				$i += $span;
			}
		}
		$total = 0;
		foreach ( $max as $chars ) {
			$total += max( 4, $chars ) * 10 + 36;
		}
		return $total;
	}

	private static function cell( $tag, $cell, $cols, $is_head ) {
		$attrs = '';
		$class = array();
		$css   = array();
		$col   = isset( $cell['c'], $cols[ $cell['c'] ] ) ? $cols[ $cell['c'] ] : array( 'a' => '', 'f' => '' );

		if ( ! empty( $cell['cs'] ) && $cell['cs'] > 1 ) {
			$attrs .= ' colspan="' . (int) $cell['cs'] . '"';
		}
		if ( $is_head ) {
			$attrs .= ' scope="col"';
			// Επικεφαλίδα: χρώμα της στήλης (αν οι γραμμές της είναι χρωματισμένες στο Excel), αλλιώς του κελιού, αλλιώς το βασικό.
			$fill = ( empty( $cell['cs'] ) && '' !== $col['f'] ) ? $col['f'] : ( isset( $cell['f'] ) ? $cell['f'] : '' );
			$pal  = $fill ? Pinax_Color::palette( $fill ) : null;
			if ( $pal ) {
				$css[] = 'background-color:#' . $pal['head'];
			}
		} else {
			$align = isset( $cell['a'] ) ? $cell['a'] : ( empty( $cell['cs'] ) ? $col['a'] : '' );
			if ( ! empty( $cell['w'] ) ) {
				$class[] = 'px-wide';
				if ( ! isset( $cell['a'] ) ) {
					$align = 'c';
				}
			}
			if ( 'c' === $align ) {
				$class[] = 'px-c';
			} elseif ( 'r' === $align ) {
				$class[] = 'px-r';
			}
			if ( ! empty( $cell['b'] ) ) {
				$class[] = 'px-b';
			}
			$pal = ! empty( $cell['f'] ) ? Pinax_Color::palette( $cell['f'] ) : null;
			if ( $pal ) {
				$class[] = 'px-fill';
				$css[]   = 'background-color:#' . $pal['bg'];
				$css[]   = 'color:#' . $pal['fg'];
			}
		}
		if ( $class ) {
			$attrs .= ' class="' . esc_attr( implode( ' ', $class ) ) . '"';
		}
		if ( $css ) {
			$attrs .= ' style="' . esc_attr( implode( ';', $css ) ) . '"';
		}
		return '<' . $tag . $attrs . '>' . esc_html( $cell['v'] ) . '</' . $tag . '>';
	}

	public static function print_url( $id ) {
		$base = is_singular() ? get_permalink() : home_url( '/' );
		return add_query_arg( 'pinakas_print', $id, $base );
	}

	/** Καθαρή σελίδα εκτύπωσης: ?pinakas_print=id */
	public static function print_view() {
		if ( empty( $_GET['pinakas_print'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$id = Pinax_Store::clean_id( wp_unslash( $_GET['pinakas_print'] ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$t  = Pinax_Store::get( $id );
		if ( ! $t || empty( $t['data']['rows'] ) ) {
			return;
		}
		$title = '' !== trim( $t['settings']['title'] ) ? $t['settings']['title'] : ( '' !== $t['data']['title'] ? $t['data']['title'] : $t['name'] );

		// Μέσα σε αόρατο πλαίσιο (εκτύπωση πάνω από την ίδια σελίδα): χωρίς κουμπιά και χωρίς αυτόματη εκτύπωση.
		$embed = ! empty( $_GET['px_embed'] ); // phpcs:ignore WordPress.Security.NonceVerification

		// Σύνδεσμος επιστροφής στη σελίδα του πίνακα (ίδιο site, χωρίς τις παραμέτρους εκτύπωσης).
		$back = isset( $_SERVER['REQUEST_URI'] ) ? remove_query_arg( array( 'pinakas_print', 'px_embed' ), wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $back || '/' !== substr( $back, 0, 1 ) || '//' === substr( $back, 0, 2 ) ) {
			$back = home_url( '/' );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		?><!doctype html>
<html lang="el">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( add_query_arg( 'ver', PINAX_VERSION, PINAX_URL . 'assets/style.css' ) ); ?>">
</head>
<body class="px-print-view<?php echo $embed ? ' px-embed' : ''; ?>">
<?php if ( ! $embed ) : ?>
<div class="px-printbar">
	<a class="px-back" href="<?php echo esc_url( $back ); ?>">← Επιστροφή στον πίνακα</a>
	<button type="button" onclick="window.print()">Εκτύπωση / Αποθήκευση PDF</button>
</div>
<?php endif; ?>
<?php echo self::render( $t['data'], $t['settings'], array( 'updated' => $t['updated'], 'printing' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
<?php if ( ! $embed ) : ?>
<script>window.addEventListener('load',function(){setTimeout(function(){window.print();},300);});</script>
<?php endif; ?>
</body>
</html>
		<?php
		exit;
	}
}
