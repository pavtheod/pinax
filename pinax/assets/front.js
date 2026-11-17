/* Pinax: αναζήτηση και ρίγες γραμμών */
(function () {
	'use strict';

	// Κεφαλαία χωρίς τόνους: «δευτερα» βρίσκει «Δευτέρα».
	function norm(s) {
		return (s || '')
			.normalize('NFD')
			.replace(/[̀-ͯ]/g, '')
			.toLocaleUpperCase('el')
			.replace(/\s+/g, ' ')
			.trim();
	}

	var pages = [];

	function setup(page) {
		var table = page.querySelector('table.px-table');
		if (!table || !table.tBodies.length) {
			return;
		}
		var rows = Array.prototype.slice.call(table.tBodies[0].rows).filter(function (tr) {
			return !tr.classList.contains('px-empty');
		});
		var empty = table.querySelector('tr.px-empty');
		var input = page.querySelector('[data-px-search]');
		var count = page.querySelector('[data-px-count]');
		if (!input) {
			return;
		}
		var texts = rows.map(function (tr) { return norm(tr.textContent); });

		function restripe() {
			var visible = rows.filter(function (tr) { return !tr.hidden; });
			rows.forEach(function (tr) { tr.classList.remove('px-even', 'px-last'); });
			visible.forEach(function (tr, i) {
				if (i % 2 === 1) { tr.classList.add('px-even'); }
			});
			if (visible.length) { visible[visible.length - 1].classList.add('px-last'); }
			if (empty) { empty.hidden = visible.length !== 0; }
			if (count) {
				count.textContent = input.value.trim()
					? (visible.length === 1 ? '1 αποτέλεσμα' : visible.length + ' αποτελέσματα')
					: '';
			}
		}

		function filter() {
			var terms = norm(input.value).split(' ').filter(Boolean);
			rows.forEach(function (tr, i) {
				var t = texts[i];
				tr.hidden = !terms.every(function (term) { return t.indexOf(term) !== -1; });
			});
			restripe();
		}

		input.addEventListener('input', filter);
		input.addEventListener('search', filter);
		if (input.value) { filter(); }

		pages.push({ input: input, filter: filter, saved: '' });
	}

	// Ctrl+P στη σελίδα: εκτυπώνονται όλες οι γραμμές, ακόμη κι αν υπάρχει αναζήτηση.
	window.addEventListener('beforeprint', function () {
		pages.forEach(function (p) {
			if (p.input.value) {
				p.saved = p.input.value;
				p.input.value = '';
				p.filter();
			}
		});
	});
	window.addEventListener('afterprint', function () {
		pages.forEach(function (p) {
			if (p.saved) {
				p.input.value = p.saved;
				p.saved = '';
				p.filter();
			}
		});
	});

	/*
	 * Κουμπί «Εκτύπωση / Αποθήκευση PDF».
	 * Υπολογιστές και Android: η καθαρή σελίδα του πίνακα φορτώνει σε αόρατο πλαίσιο και το
	 * παράθυρο εκτύπωσης ανοίγει πάνω από την ίδια σελίδα. Ο χρήστης δεν φεύγει ποτέ από εδώ.
	 * iPhone/iPad (και όπου το πλαίσιο δεν φορτώνει): ο σύνδεσμος ανοίγει κανονικά τη σελίδα
	 * εκτύπωσης στην ίδια καρτέλα, με κουμπί «Επιστροφή στον πίνακα».
	 */
	function isIOS() {
		var ua = navigator.userAgent || '';
		return /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
	}

	var printing = false;

	function printInPlace(link) {
		var url = link.href + (link.href.indexOf('?') === -1 ? '?' : '&') + 'px_embed=1';
		var old = document.getElementById('px-print-frame');
		if (old) { old.parentNode.removeChild(old); }

		var frame = document.createElement('iframe');
		frame.id = 'px-print-frame';
		frame.setAttribute('aria-hidden', 'true');
		frame.setAttribute('tabindex', '-1');
		frame.style.cssText = 'position:fixed;right:0;bottom:0;width:1px;height:1px;border:0;opacity:0;pointer-events:none;';

		var done = false;
		var fallback = function () {
			// Το πλαίσιο δεν φόρτωσε σωστά (π.χ. το απαγορεύει ρύθμιση ασφαλείας του server):
			// ανοίγουμε τη σελίδα εκτύπωσης κανονικά.
			if (done) { return; }
			done = true;
			printing = false;
			window.location.href = link.href;
		};
		var timer = setTimeout(fallback, 10000);

		frame.onload = function () {
			if (done) { return; }
			var win, ok = false;
			try {
				win = frame.contentWindow;
				ok = !!(win && win.document && win.document.querySelector('.px-page'));
			} catch (err) {
				ok = false;
			}
			if (!ok) { clearTimeout(timer); fallback(); return; }
			done = true;
			clearTimeout(timer);
			setTimeout(function () {
				try {
					win.focus();
					win.print();
				} catch (err) {
					window.location.href = link.href;
				}
				printing = false;
			}, 150);
		};
		frame.src = url;
		document.body.appendChild(frame);
	}

	document.addEventListener('click', function (e) {
		var link = e.target.closest ? e.target.closest('a[data-px-print]') : null;
		if (!link || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) {
			return; // Ctrl/Cmd+κλικ: ανοίγει κανονικά σε νέα καρτέλα.
		}
		if (isIOS()) {
			return; // Κανονική μετάβαση στη σελίδα εκτύπωσης (με «Επιστροφή»).
		}
		e.preventDefault();
		if (printing) { return; }
		printing = true;
		printInPlace(link);
	});

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('.px-page'), setup);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
