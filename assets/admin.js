/**
 * Otium Yachtfolio Sync — admin behaviour.
 *
 * Vanilla ES2019, no jQuery. Every request is a POST carrying the localized
 * nonce; API-provided strings are inserted with textContent only.
 */
(function () {
	'use strict';

	var cfg = window.oyYf || {};
	var i18n = cfg.i18n || {};

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce || '');
		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];
			if (Array.isArray(value)) {
				value.forEach(function (item) {
					body.append(key + '[]', item);
				});
			} else if (value !== undefined && value !== null) {
				body.append(key, value);
			}
		});

		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			return response.json().catch(function () {
				throw new Error(i18n.failed || 'Request failed.');
			});
		});
	}

	function notice(text, type) {
		var wrap = document.querySelector('.wrap');
		if (!wrap) {
			return;
		}
		var el = document.createElement('div');
		el.className = 'notice notice-' + (type || 'success') + ' is-dismissible';
		var p = document.createElement('p');
		p.textContent = text;
		el.appendChild(p);
		wrap.insertBefore(el, wrap.firstChild ? wrap.firstChild.nextSibling : null);
		window.setTimeout(function () {
			el.remove();
		}, 8000);
	}

	/* ---------------- modal ---------------- */

	function modal(title) {
		var existing = document.getElementById('oy-yf-modal');
		if (existing) {
			existing.remove();
		}

		var overlay = document.createElement('div');
		overlay.className = 'oy-modal';
		overlay.id = 'oy-yf-modal';

		var box = document.createElement('div');
		box.className = 'oy-modal__box';

		var head = document.createElement('div');
		head.className = 'oy-modal__head';
		var h2 = document.createElement('h2');
		h2.textContent = title;
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'oy-btn oy-btn--ghost';
		close.textContent = i18n.close || 'Close';
		close.addEventListener('click', function () {
			overlay.remove();
		});
		head.appendChild(h2);
		head.appendChild(close);

		var body = document.createElement('div');
		body.className = 'oy-modal__body';

		box.appendChild(head);
		box.appendChild(body);
		overlay.appendChild(box);
		document.body.appendChild(overlay);

		overlay.addEventListener('click', function (event) {
			if (event.target === overlay) {
				overlay.remove();
			}
		});

		return body;
	}

	function renderDiff(rows) {
		var body = modal(i18n.diffTitle || 'Dry run diff');

		var changed = rows.filter(function (row) {
			return row.changed;
		});

		if (!rows.length || !changed.length) {
			var empty = document.createElement('p');
			empty.textContent = i18n.noChanges || 'No field would change.';
			body.appendChild(empty);
		}

		var table = document.createElement('table');
		table.className = 'oy-diff';

		var thead = document.createElement('thead');
		var headRow = document.createElement('tr');
		[i18n.colField || 'Field', i18n.colOwner || 'Owner', i18n.colOld || 'Current', i18n.colNew || 'From feed', i18n.colNote || 'Note'].forEach(function (label) {
			var th = document.createElement('th');
			th.textContent = label;
			headRow.appendChild(th);
		});
		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = document.createElement('tbody');
		rows.forEach(function (row) {
			var tr = document.createElement('tr');
			if (row.skipped) {
				tr.className = 'oy-diff--skipped';
			} else if (!row.changed) {
				tr.className = 'oy-diff--unchanged';
			}

			var cells = [
				{ text: row.key, cls: '' },
				{ text: row.owner, cls: '' },
				{ text: row.old, cls: 'oy-diff__old' },
				{ text: row.new, cls: 'oy-diff__new' },
				{ text: row.reason || (row.skipped ? (i18n.skipped || 'skipped') : ''), cls: '' }
			];

			cells.forEach(function (cell) {
				var td = document.createElement('td');
				td.textContent = cell.text === null || cell.text === undefined ? '' : String(cell.text);
				if (cell.cls) {
					td.className = cell.cls;
				}
				tr.appendChild(td);
			});

			tbody.appendChild(tr);
		});
		table.appendChild(tbody);

		var toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'oy-btn oy-btn--sm';
		toggle.textContent = 'Show unchanged (' + (rows.length - changed.length) + ')';
		toggle.addEventListener('click', function () {
			table.classList.toggle('is-showing-all');
		});

		body.appendChild(toggle);
		body.appendChild(table);
	}

	function renderJson(title, json) {
		var body = modal(title);
		var pre = document.createElement('pre');
		pre.textContent = json;
		body.appendChild(pre);
	}

	/* ---------------- row actions ---------------- */

	/**
	 * Moves a switch to its other state: the graphic, the accessible state and
	 * the wording all change together, so the control never shows "Skipped"
	 * next to a track that has already slid to on.
	 */
	function flipSwitch(button) {
		var on = button.getAttribute('aria-checked') !== 'true';
		button.setAttribute('aria-checked', on ? 'true' : 'false');

		var label = button.querySelector('.oy-switch__label');
		var next = on ? button.getAttribute('data-label-on') : button.getAttribute('data-label-off');
		if (label && next) {
			label.textContent = next;
		}
	}

	function runAction(button, action, yachtId) {
		// A switch is built from child elements. Overwriting textContent would
		// delete the track and thumb and never put them back, so only plain
		// text controls get the "Working…" swap; the rest flip a class and,
		// for a switch, aria-checked so the graphic moves immediately.
		var isSwitch = button.getAttribute('role') === 'switch';
		var plainText = !isSwitch && button.children.length === 0;
		var label = plainText ? button.textContent : null;

		button.classList.add('oy-busy');
		if (plainText) {
			button.textContent = i18n.working || 'Working…';
		}
		if (isSwitch) {
			flipSwitch(button);
		}

		post(action, { yacht: yachtId }).then(function (response) {
			var data = response && response.data ? response.data : {};

			if (action === 'oy_yf_dry_run') {
				renderDiff(Array.isArray(data.diff) ? data.diff : []);
			} else if (action === 'oy_yf_show_json') {
				renderJson((i18n.jsonTitle || 'Stored payload') + ' — ' + (data.title || ''), data.json || '');
			} else if (data.message) {
				notice(data.message, response.success ? 'success' : 'error');
			}

			if (response.success && ['oy_yf_sync_one', 'oy_yf_publish', 'oy_yf_unpublish', 'oy_yf_toggle_visible', 'oy_yf_toggle_selected', 'oy_yf_unlink'].indexOf(action) !== -1) {
				window.setTimeout(function () {
					window.location.reload();
				}, 900);
			}
		}).catch(function (error) {
			notice(error.message || i18n.failed || 'Request failed.', 'error');
			// The optimistic flip was wrong: put it back.
			if (isSwitch) {
				flipSwitch(button);
			}
		}).finally(function () {
			button.classList.remove('oy-busy');
			if (plainText) {
				button.textContent = label;
			}
		});
	}

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest('.oy-yf-action, .oy-yf-toggle');
		if (!trigger) {
			return;
		}
		event.preventDefault();

		var action = trigger.getAttribute('data-action');
		var yachtId = trigger.getAttribute('data-yacht');
		if (!action || !yachtId) {
			return;
		}

		runAction(trigger, action, yachtId);
	});

	/* ---------------- bulk actions ---------------- */

	var form = document.getElementById('oy-yf-yachts-form');
	if (form) {
		form.addEventListener('submit', function (event) {
			var selectTop = document.getElementById('bulk-action-selector-top');
			var selectBottom = document.getElementById('bulk-action-selector-bottom');
			var operation = '';

			[selectTop, selectBottom].forEach(function (select) {
				if (select && select.value && select.value !== '-1') {
					operation = select.value;
				}
			});

			if (!operation) {
				return; // plain filter/search submit
			}

			event.preventDefault();

			var ids = Array.prototype.slice
				.call(form.querySelectorAll('input[name="yf_ids[]"]:checked'))
				.map(function (input) {
					return input.value;
				});

			if (!ids.length) {
				notice(i18n.pickRows || 'Select at least one yacht.', 'error');
				return;
			}

			var submit = form.querySelector('#doaction, #doaction2');
			if (submit) {
				submit.classList.add('oy-busy');
			}

			post('oy_yf_bulk', { operation: operation, yacht_ids: ids }).then(function (response) {
				var data = response && response.data ? response.data : {};
				notice(data.message || '', response.success ? 'success' : 'error');
				window.setTimeout(function () {
					window.location.reload();
				}, 1200);
			}).catch(function (error) {
				notice(error.message || i18n.failed || 'Request failed.', 'error');
			}).finally(function () {
				if (submit) {
					submit.classList.remove('oy-busy');
				}
			});
		});
	}
})();
