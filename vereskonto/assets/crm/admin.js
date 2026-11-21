/*
 * Vereskonto CRM — small progressive enhancements. Every screen works
 * without this file; it only hides irrelevant fields and asks before
 * destructive actions.
 */
(function () {
	'use strict';

	var t = window.vklCrm || {};

	// Ask before destructive links (delete contact / entry / tag).
	document.addEventListener('click', function (e) {
		var a = e.target.closest('[data-confirm]');
		if (!a) { return; }
		var key = a.getAttribute('data-confirm');
		var msg = { delete: t.confirmDelete, activity: t.confirmAct, tag: t.confirmTag }[key];
		if (msg && !window.confirm(msg)) { e.preventDefault(); }
	});

	// Bulk actions: show only the value picker the chosen action needs,
	// and confirm before applying.
	var bulk = document.getElementById('bulk-action-selector-top');
	var values = document.querySelector('.vkl-crm-bulk-values');
	function syncBulk() {
		if (!bulk || !values) { return; }
		values.querySelectorAll('[data-for]').forEach(function (el) {
			var shown = el.getAttribute('data-for').split(' ').indexOf(bulk.value) !== -1;
			el.classList.toggle('is-shown', shown);
		});
	}
	if (bulk) {
		bulk.addEventListener('change', syncBulk);
		syncBulk();
		var form = bulk.closest('form');
		if (form) {
			form.addEventListener('submit', function (e) {
				var action = (e.submitter && e.submitter.id === 'doaction') ? bulk.value : null;
				if (action && action !== '-1' && !window.confirm(t.confirmBulk)) { e.preventDefault(); }
			});
		}
	}

	// Composer: note / call / meeting tabs.
	document.querySelectorAll('[data-vkl-composer]').forEach(function (box) {
		var input = box.querySelector('input[name=type]');
		var text = box.querySelector('textarea[name=body]');
		var tabs = box.querySelectorAll('[role=tab]');
		function show(type) {
			input.value = type;
			tabs.forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-type') === type ? 'true' : 'false'); });
			box.querySelectorAll('[data-show]').forEach(function (el) {
				el.classList.toggle('is-shown', el.getAttribute('data-show').split(' ').indexOf(type) !== -1);
			});
			if (text) {
				text.placeholder = text.getAttribute('data-ph-' + type) || '';
				text.required = type === 'note';
			}
		}
		tabs.forEach(function (b) {
			b.addEventListener('click', function () { show(b.getAttribute('data-type')); });
		});
		show('note');
	});
})();
