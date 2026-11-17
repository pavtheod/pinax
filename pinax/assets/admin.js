/* Pinax: διαχείριση */
(function () {
	'use strict';
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.px-copy');
		if (!btn) { return; }
		var text = btn.getAttribute('data-copy');
		var done = function () {
			var old = btn.textContent;
			btn.textContent = 'Αντιγράφηκε ✓';
			setTimeout(function () { btn.textContent = old; }, 1500);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done);
		} else {
			var ta = document.createElement('textarea');
			ta.value = text;
			document.body.appendChild(ta);
			ta.select();
			try { document.execCommand('copy'); done(); } catch (err) {}
			document.body.removeChild(ta);
		}
	});
	document.addEventListener('submit', function (e) {
		var form = e.target.closest('.px-delete-form');
		if (form && !window.confirm(form.getAttribute('data-confirm'))) {
			e.preventDefault();
		}
	});
})();

/* Έλεγχος μεγέθους αρχείου πριν το ανέβασμα (για να μη φτάσει στον server αρχείο που θα απορριφθεί). */
document.addEventListener('submit', function (e) {
	var form = e.target.closest('.px-upload-form');
	if (!form) { return; }
	var input = form.querySelector('input[type="file"]');
	var max = parseInt(form.getAttribute('data-max'), 10) || 0;
	if (input && input.files && input.files[0] && max && input.files[0].size > max) {
		e.preventDefault();
		window.alert('Το αρχείο είναι μεγαλύτερο από το όριο (' + form.getAttribute('data-max-text') + ').');
		return;
	}
	var btn = form.querySelector('button[type="submit"]');
	if (btn) { btn.disabled = true; btn.textContent = 'Ανέβασμα…'; }
});
