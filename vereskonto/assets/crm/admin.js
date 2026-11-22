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
		var msg = { delete: t.confirmDelete, activity: t.confirmAct, tag: t.confirmTag, deal: t.confirmDeal, task: t.confirmTask, pipeline: t.confirmPipe, stage: t.confirmStage }[key];
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

	// Deals board: drag a card to another column. Dropping on a "lost"
	// column asks for the reason first. The server decides; on failure
	// the card goes back where it was.
	document.querySelectorAll('[data-vkl-board]').forEach(function (board) {
		var dragged = null, origin = null, dialog = document.querySelector('[data-vkl-lost]');

		var fmt = {};
		try { fmt = JSON.parse(board.getAttribute('data-money') || '{}'); } catch (e) { fmt = {}; }
		function price(v) {
			var d = fmt.decimals || 0;
			var parts = Math.abs(v).toFixed(d).split('.');
			parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, fmt.thousand || ',');
			var num = parts.join(fmt.dec || '.');
			return (fmt.format || '%1$s%2$s').replace('%1$s', fmt.symbol || '').replace('%2$s', num);
		}
		// Same digits and money format the server printed (no locale guessing).
		function recount(col) {
			var cards = col.querySelectorAll('[data-deal]'), sum = 0;
			cards.forEach(function (c) { sum += parseFloat(c.getAttribute('data-value')) || 0; });
			col.querySelector('[data-count]').textContent = String(cards.length);
			col.querySelector('[data-sum]').textContent = price(sum);
		}
		function send(card, col, reason) {
			var body = new URLSearchParams({
				action: 'vkl_crm_deal_move', nonce: board.getAttribute('data-nonce'),
				deal: card.getAttribute('data-deal'), stage: col.getAttribute('data-stage'), reason: reason || ''
			});
			card.classList.add('is-saving');
			return fetch(board.getAttribute('data-ajax'), { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					card.classList.remove('is-saving');
					if (!res || !res.success) { throw new Error(res && res.data && res.data.message || ''); }
					recount(col); recount(origin);
				})
				.catch(function (err) {
					card.classList.remove('is-saving');
					origin.querySelector('[data-dropzone]').prepend(card);
					recount(col); recount(origin);
					window.alert((err && err.message) || t.moveFailed);
				});
		}
		function askReason() {
			return new Promise(function (resolve) {
				if (!dialog || typeof dialog.showModal !== 'function') { resolve(window.prompt('?') || ''); return; }
				dialog.returnValue = '';
				dialog.showModal();
				dialog.addEventListener('close', function onClose() {
					dialog.removeEventListener('close', onClose);
					if (dialog.returnValue !== 'ok') { resolve(null); return; }
					var picked = dialog.querySelector('input[name=reason]:checked');
					var val = picked ? picked.value : '';
					if (val === '__other') { val = dialog.querySelector('input[name=other]').value; }
					resolve(val);
				});
			});
		}

		board.addEventListener('dragstart', function (e) {
			var card = e.target.closest('[data-deal][draggable=true]');
			if (!card) { return; }
			dragged = card; origin = card.closest('[data-stage]');
			card.classList.add('is-dragging');
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData('text/plain', card.getAttribute('data-deal'));
		});
		board.addEventListener('dragend', function () {
			if (dragged) { dragged.classList.remove('is-dragging'); }
			board.querySelectorAll('.is-over').forEach(function (c) { c.classList.remove('is-over'); });
		});
		board.addEventListener('dragover', function (e) {
			var col = e.target.closest('[data-stage]');
			if (!col || !dragged) { return; }
			e.preventDefault();
			board.querySelectorAll('.is-over').forEach(function (c) { if (c !== col) { c.classList.remove('is-over'); } });
			col.classList.add('is-over');
		});
		board.addEventListener('drop', function (e) {
			var col = e.target.closest('[data-stage]');
			if (!col || !dragged || col === origin) { return; }
			e.preventDefault();
			col.classList.remove('is-over');
			var card = dragged;
			var go = function (reason) {
				if (reason === null) { return; }
				col.querySelector('[data-dropzone]').prepend(card);
				send(card, col, reason);
			};
			if (col.getAttribute('data-kind') === 'lost') { askReason().then(go); } else { go(''); }
		});
	});

	// Period picker: the date fields only matter for a custom range.
	document.querySelectorAll('.vkl-crm-period').forEach(function (form) {
		var sel = form.querySelector('select[name=period]'), box = form.querySelector('[data-show-custom]');
		function sync() { if (box) { box.hidden = sel.value !== 'custom'; } }
		sel.addEventListener('change', sync); sync();
	});

	// Contact picker on the deal form (search as you type).
	document.querySelectorAll('[data-vkl-lookup]').forEach(function (box) {
		var input = box.querySelector('input[type=search]');
		var hidden = box.querySelector('input[type=hidden]');
		var list = box.querySelector('ul');
		var timer = null, active = -1;

		function close() { list.hidden = true; active = -1; }
		function pick(li) {
			hidden.value = li.getAttribute('data-id');
			input.value = li.getAttribute('data-label');
			close();
		}
		function render(items) {
			list.innerHTML = '';
			if (!items.length) {
				var none = document.createElement('li');
				none.className = 'is-none'; none.textContent = t.noMatch;
				list.appendChild(none);
			}
			items.forEach(function (it) {
				var li = document.createElement('li');
				li.setAttribute('role', 'option');
				li.setAttribute('data-id', it.id);
				li.setAttribute('data-label', it.label);
				var strong = document.createElement('strong'); strong.textContent = it.label;
				var small = document.createElement('small'); small.textContent = it.hint || '';
				li.appendChild(strong); li.appendChild(small);
				li.addEventListener('mousedown', function (ev) { ev.preventDefault(); pick(li); });
				list.appendChild(li);
			});
			list.hidden = false;
		}
		input.addEventListener('input', function () {
			hidden.value = '';
			clearTimeout(timer);
			var q = input.value.trim();
			if (q.length < 2) { close(); return; }
			timer = setTimeout(function () {
				var url = box.getAttribute('data-ajax') + '?' + new URLSearchParams({ action: 'vkl_crm_contact_lookup', nonce: box.getAttribute('data-nonce'), q: q });
				fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
					render(res && res.success ? res.data : []);
				}).catch(close);
			}, 250);
		});
		input.addEventListener('keydown', function (e) {
			var items = list.querySelectorAll('li[data-id]');
			if (list.hidden || !items.length) { return; }
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
				items.forEach(function (li, i) { li.classList.toggle('is-on', i === active); });
			} else if (e.key === 'Enter' && active >= 0) {
				e.preventDefault(); pick(items[active]);
			} else if (e.key === 'Escape') { close(); }
		});
		input.addEventListener('blur', function () { setTimeout(close, 150); });
	});
})();
