(function () {
	'use strict';

	var MAX_FILE_SIZE = 10485760; // 10 MB
	var ALLOWED_EXT = ['doc', 'docx', 'odt', 'pdf', 'jpg', 'jpeg', 'png'];

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	function showMessage(form, text, type) {
		var box = form.querySelector('[data-wkf-message]');
		if (!box) {
			return;
		}
		box.textContent = text || '';
		box.className = 'wkf-message' + (text ? ' wkf-message-' + type : '');
	}

	/**
	 * Serverem vykreslený kontext může být kvůli page cache zastaralý,
	 * proto se přepisuje skutečnou adresou z window.location.
	 */
	function fillContext(form) {
		var urlField = form.querySelector('[data-wkf-page-url]');
		if (urlField) {
			urlField.value = window.location.href;
		}
		var qsField = form.querySelector('[data-wkf-query-string]');
		if (qsField) {
			qsField.value = window.location.search.replace(/^\?/, '');
		}
	}

	function labelFor(form, element) {
		var id = element.getAttribute('id');
		if (id) {
			var label = form.querySelector('label[for="' + id + '"]');
			if (label) {
				return label.textContent.replace('*', '').trim();
			}
		}
		return '';
	}

	function validFile(input) {
		if (!input.files || !input.files.length) {
			return input.hasAttribute('required') ? 'Nahrajte prosím soubor.' : '';
		}
		var maxSize = parseInt(input.getAttribute('data-wkf-max'), 10) || MAX_FILE_SIZE;
		var maxFiles = parseInt(input.getAttribute('data-wkf-maxfiles'), 10) || 1;
		var allowed = (input.getAttribute('data-wkf-ext') || '').split(',').filter(Boolean);
		if (!allowed.length) {
			allowed = ALLOWED_EXT;
		} else if (allowed.indexOf('jpg') !== -1 && allowed.indexOf('jpeg') === -1) {
			allowed.push('jpeg');
		}
		if (input.files.length > maxFiles) {
			return 'Lze nahrát nejvýše ' + maxFiles + ' souborů.';
		}
		for (var i = 0; i < input.files.length; i++) {
			var file = input.files[i];
			if (file.size > maxSize) {
				return 'Soubor „' + file.name + '" je příliš velký. Maximální velikost je ' + Math.ceil(maxSize / 1048576) + ' MB.';
			}
			var ext = file.name.split('.').pop().toLowerCase();
			if (allowed.indexOf(ext) === -1) {
				return 'Nepodporovaný typ souboru „' + file.name + '". Povoleno: .' + allowed.join(', .') + '.';
			}
		}
		return '';
	}

	/** Generická validace podle atributů required a skupin checkboxů. */
	function validateForm(form, scope) {
		var root = scope || form;
		var elements = root.querySelectorAll('input[required], textarea[required], select[required]');
		for (var i = 0; i < elements.length; i++) {
			var el = elements[i];

			if (el.disabled || el.closest('.wkf-cond[hidden]')) {
				continue; // skrytá podmíněná pole se nevalidují
			}

			if (el.type === 'checkbox') {
				if (!el.checked) {
					var cbLabel = labelFor(form, el) || (el.closest('label') ? el.closest('label').textContent.replace('*', '').trim() : '');
					return cbLabel ? 'Potvrďte prosím „' + cbLabel + '".' : 'Potvrďte prosím povinný souhlas.';
				}
				continue;
			}

			if (el.type === 'file') {
				var fileError = validFile(el);
				if (fileError) {
					return fileError;
				}
				continue;
			}

			if (el.type === 'email') {
				if (!el.value || el.value.indexOf('@') === -1) {
					return 'Vyplňte prosím platnou e-mailovou adresu.';
				}
				continue;
			}

			if (el.type === 'number' && el.value !== '') {
				var num = parseFloat(String(el.value).replace(',', '.'));
				var numLabel = labelFor(form, el);
				if (isNaN(num)) {
					return 'Pole „' + numLabel + '" musí obsahovat číslo.';
				}
				if (el.min !== '' && num < parseFloat(el.min)) {
					return 'Pole „' + numLabel + '" musí být nejméně ' + el.min + '.';
				}
				if (el.max !== '' && num > parseFloat(el.max)) {
					return 'Pole „' + numLabel + '" smí být nejvýše ' + el.max + '.';
				}
			}

			if (!el.value || !el.value.trim()) {
				var label = labelFor(form, el);
				if (el.tagName === 'SELECT') {
					return label ? 'Vyberte prosím „' + label + '".' : 'Vyberte prosím hodnotu.';
				}
				return label ? 'Vyplňte prosím pole „' + label + '".' : 'Vyplňte prosím všechna povinná pole.';
			}
		}

		// Povinné skupiny checkboxů.
		var groups = root.querySelectorAll('[data-wkf-required-group]');
		for (var g = 0; g < groups.length; g++) {
			if (groups[g].closest('.wkf-cond[hidden]')) {
				continue;
			}
			if (!groups[g].querySelector('input[type="checkbox"]:checked, input[type="radio"]:checked')) {
				var legend = groups[g].querySelector('legend');
				var groupLabel = legend ? legend.textContent.replace('*', '').trim() : '';
				return groupLabel ? 'Vyberte prosím alespoň jednu položku v poli „' + groupLabel + '".' : 'Vyberte prosím alespoň jednu položku.';
			}
		}

		// Nepovinný soubor, ale pokud je vybraný, musí být validní.
		var optionalFiles = root.querySelectorAll('input[type="file"]:not([required])');
		for (var f = 0; f < optionalFiles.length; f++) {
			var optError = validFile(optionalFiles[f]);
			if (optError) {
				return optError;
			}
		}

		// Vyplněná pole: kontrola tvaru e-mailu a telefonu.
		var emails = form.querySelectorAll('input[type="email"]');
		for (var m = 0; m < emails.length; m++) {
			if (emails[m].value && emails[m].value.indexOf('@') === -1) {
				return 'E-mailová adresa nemá platný tvar.';
			}
		}
		var phones = form.querySelectorAll('input[type="tel"]');
		for (var t = 0; t < phones.length; t++) {
			var digits = phones[t].value.replace(/[\s\-().]/g, '');
			if (digits && !/^\+?\d{9,15}$/.test(digits)) {
				return 'Telefonní číslo nemá platný tvar.';
			}
		}

		return '';
	}

	function initDropzone(form) {
		form.querySelectorAll('[data-wkf-dropzone]').forEach(function (zone) {
			var input = zone.querySelector('input[type="file"]');
			var label = zone.querySelector('[data-wkf-filename]');

			function refresh() {
				if (label) {
					var names = [];
					for (var i = 0; input.files && i < input.files.length; i++) {
						names.push(input.files[i].name);
					}
					label.textContent = names.join(', ');
				}
				zone.classList.toggle('wkf-has-file', !!(input.files && input.files.length));
			}

			input.addEventListener('change', refresh);

			['dragenter', 'dragover'].forEach(function (evt) {
				zone.addEventListener(evt, function (e) {
					e.preventDefault();
					zone.classList.add('wkf-dragover');
				});
			});
			['dragleave', 'drop'].forEach(function (evt) {
				zone.addEventListener(evt, function (e) {
					e.preventDefault();
					zone.classList.remove('wkf-dragover');
				});
			});
			zone.addEventListener('drop', function (e) {
				if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
					input.files = e.dataTransfer.files;
					refresh();
				}
			});
		});
	}

	/**
	 * Našeptávání (ARES / adresy). Připojuje se na pole s data-wkf-suggest,
	 * dotazy jdou přes serverovou proxy (admin-ajax), která drží API klíče.
	 * Ovládání myší i klávesnicí (šipky, Enter, Escape).
	 */
	function initSuggest(form) {
		form.querySelectorAll('[data-wkf-suggest]').forEach(function (input) {
			var kind = input.getAttribute('data-wkf-suggest');
			var wrap = input.closest('.wkf-suggest-wrap');
			var list = wrap ? wrap.querySelector('.wkf-suggest') : null;
			if (!list) {
				return;
			}

			var timer = null;
			var lastQuery = '';
			var items = [];
			var activeIndex = -1;

			function close() {
				list.hidden = true;
				list.innerHTML = '';
				items = [];
				activeIndex = -1;
				input.setAttribute('aria-expanded', 'false');
			}

			function pick(item) {
				if (kind === 'address') {
					input.value = item.value || '';
				} else {
					input.value = item.ico || '';
					var nameField = form.querySelector('input[name="wkf_jmeno"]')
						|| form.querySelector('input[type="text"]:not([data-wkf-suggest]):not([name="wkf_antispam"])');
					if (nameField && !nameField.value.trim() && item.nazev) {
						nameField.value = item.nazev;
					}
					var addressField = form.querySelector('[name="wkf_adresa"]');
					if (addressField && !addressField.value.trim() && item.adresa) {
						addressField.value = item.adresa;
					}
				}
				close();
				input.focus();
			}

			function setActive(index) {
				var options = list.querySelectorAll('.wkf-suggest-item');
				options.forEach(function (option, i) {
					option.classList.toggle('wkf-active', i === index);
				});
				activeIndex = index;
			}

			function showLoading() {
				list.innerHTML = '';
				var loading = document.createElement('div');
				loading.className = 'wkf-suggest-loading';
				loading.textContent = 'Hledám…';
				list.appendChild(loading);
				list.hidden = false;
				input.setAttribute('aria-expanded', 'true');
			}

			function render(newItems) {
				list.innerHTML = '';
				items = newItems || [];
				activeIndex = -1;
				if (!items.length) {
					close();
					return;
				}
				items.forEach(function (item, index) {
					var option = document.createElement('div');
					option.className = 'wkf-suggest-item';
					option.setAttribute('role', 'option');

					if (kind === 'address') {
						option.textContent = item.value || '';
					} else {
						var title = document.createElement('strong');
						title.textContent = item.nazev || '';
						var meta = document.createElement('span');
						meta.className = 'wkf-suggest-meta';
						meta.textContent = 'IČO ' + (item.ico || '') + (item.adresa ? ' · ' + item.adresa : '');
						option.appendChild(title);
						option.appendChild(meta);
					}

					// pointerdown předběhne blur inputu – výběr je spolehlivý
					// i v šablonách s vlastními focus/blur handlery.
					option.addEventListener('pointerdown', function (e) {
						e.preventDefault();
						e.stopPropagation();
						pick(item);
					});
					option.addEventListener('mousedown', function (e) {
						e.preventDefault();
					});
					option.addEventListener('mouseenter', function () {
						setActive(index);
					});

					list.appendChild(option);
				});
				list.hidden = false;
				input.setAttribute('aria-expanded', 'true');
			}

			input.addEventListener('input', function () {
				var q = input.value.trim();
				if (timer) {
					clearTimeout(timer);
				}
				if (q.length < 3) {
					close();
					return;
				}
				timer = setTimeout(function () {
					lastQuery = q;
					showLoading();
					var base = window.wkfForms ? window.wkfForms.ajaxUrl : '/wp-admin/admin-ajax.php';
					var url = base + '?action=wkf_suggest&kind=' + encodeURIComponent(kind)
						+ '&q=' + encodeURIComponent(q);
					fetch(url, { credentials: 'same-origin' })
						.then(function (response) { return response.json(); })
						.then(function (json) {
							if (input.value.trim() !== lastQuery) {
								return; // mezitím se psalo dál
							}
							render(json && json.success && json.data ? json.data.items : []);
						})
						.catch(close);
				}, 250);
			});

			input.addEventListener('keydown', function (e) {
				if (list.hidden || !items.length) {
					if (e.key === 'Escape') {
						close();
					}
					return;
				}
				if (e.key === 'ArrowDown') {
					e.preventDefault();
					setActive(activeIndex < items.length - 1 ? activeIndex + 1 : 0);
				} else if (e.key === 'ArrowUp') {
					e.preventDefault();
					setActive(activeIndex > 0 ? activeIndex - 1 : items.length - 1);
				} else if (e.key === 'Enter') {
					if (activeIndex >= 0) {
						e.preventDefault();
						pick(items[activeIndex]);
					}
				} else if (e.key === 'Escape' || e.key === 'Tab') {
					close();
				}
			});

			input.addEventListener('blur', function () {
				setTimeout(close, 200);
			});
		});

		// Zavření všech otevřených nabídek kliknutím mimo formulář.
		document.addEventListener('pointerdown', function (e) {
			if (!form.contains(e.target)) {
				form.querySelectorAll('.wkf-suggest').forEach(function (list) {
					list.hidden = true;
					list.innerHTML = '';
				});
			}
		});
	}

	/** Živý součet orientační ceny z položek s cenou (data-wkf-price). */
	function initTotal(form) {
		var totalBox = form.querySelector('[data-wkf-total]');
		if (!totalBox) {
			return;
		}
		var amountEl = totalBox.querySelector('[data-wkf-total-amount]');

		function formatPrice(amount) {
			var decimals = Math.abs(amount % 1) > 0.004 ? 2 : 0;
			return amount.toFixed(decimals).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0') + ' Kč';
		}

		function qtyOf(wrap) {
			if (!wrap || wrap.hidden) {
				return 1;
			}
			var input = wrap.querySelector('.wkf-qty');
			var qty = input ? parseInt(input.value, 10) : 1;
			return Math.min(Math.max(qty || 1, 1), 999);
		}

		function recalc() {
			var total = 0;
			form.querySelectorAll('input[type="checkbox"][data-wkf-price], input[type="radio"][data-wkf-price]').forEach(function (cb) {
				var wrap = cb.closest('label') ? cb.closest('label').querySelector('.wkf-qty-wrap') : null;
				if (wrap) {
					wrap.hidden = !cb.checked; // pole × ks jen u zaškrtnutých položek
				}
				if (cb.checked) {
					total += (parseFloat(cb.getAttribute('data-wkf-price')) || 0) * qtyOf(wrap);
				}
			});
			form.querySelectorAll('select[data-wkf-priced]').forEach(function (sel) {
				var opt = sel.options[sel.selectedIndex];
				var price = opt ? parseFloat(opt.getAttribute('data-wkf-price')) || 0 : 0;
				var wrap = sel.parentElement.querySelector('.wkf-qty-select');
				if (wrap) {
					wrap.hidden = price <= 0;
				}
				total += price * qtyOf(wrap);
			});
			amountEl.textContent = formatPrice(total);
			totalBox.hidden = total <= 0;
		}

		form.addEventListener('change', recalc);
		form.addEventListener('input', function (e) {
			if (e.target.classList.contains('wkf-qty')) {
				recalc();
			}
		});
		recalc();
	}

	/**
	 * Podmíněné zobrazení: každé pole nese JSON {mode, groups[[{field,op,value}]]}.
	 * Skupiny = NEBO, pravidla ve skupině = A. Skryté řídicí pole je prázdné.
	 * Stejná logika jako na serveru (rule_matches).
	 */
	function initCond(form) {
		var conds = form.querySelectorAll('.wkf-cond');
		if (!conds.length && !form.querySelector('.wkf-step[data-wkf-step-cond]')) {
			return;
		}

		function controllerHidden(key) {
			var el = form.querySelector('[name="wkf_' + key + '"], [name="wkf_' + key + '[]"]');
			return !!(el && el.closest('.wkf-cond') && el.closest('.wkf-cond').hidden);
		}

		function controllerValues(key) {
			var values = [];
			if (controllerHidden(key)) {
				return values;
			}
			var radio = form.querySelector('input[type="radio"][name="wkf_' + key + '"]');
			var single = radio
				? form.querySelector('input[type="radio"][name="wkf_' + key + '"]:checked')
				: form.querySelector('[name="wkf_' + key + '"]');
			if (single && single.value && single.value.trim()) {
				values.push(single.value.trim());
			}
			form.querySelectorAll('[name="wkf_' + key + '[]"]:checked').forEach(function (cb) {
				values.push(cb.value);
			});
			return values;
		}

		function ruleMatches(op, values, expected) {
			var joined = values.join('\n').toLowerCase();
			var exp = String(expected || '').toLowerCase();
			var num = values.length ? parseFloat(String(values[0]).replace(',', '.')) : NaN;
			switch (op) {
				case 'empty': return values.length === 0;
				case 'not_empty': return values.length > 0;
				case 'eq': return values.indexOf(String(expected)) !== -1;
				case 'neq': return values.indexOf(String(expected)) === -1;
				case 'contains': return exp !== '' && joined.indexOf(exp) !== -1;
				case 'not_contains': return exp === '' || joined.indexOf(exp) === -1;
				case 'starts': return exp !== '' && joined.indexOf(exp) === 0;
				case 'ends': return exp !== '' && joined.slice(-exp.length) === exp;
				case 'gt': return !isNaN(num) && num > parseFloat(String(expected).replace(',', '.'));
				case 'lt': return !isNaN(num) && num < parseFloat(String(expected).replace(',', '.'));
			}
			return false;
		}

		function evaluate(box, attr) {
			var cond;
			try {
				cond = JSON.parse(box.getAttribute(attr || 'data-wkf-cond') || '{}');
			} catch (err) {
				return true;
			}
			var groups = cond.groups || [];
			var anyGroup = false;
			for (var g = 0; g < groups.length && !anyGroup; g++) {
				var all = true;
				for (var r = 0; r < groups[g].length; r++) {
					var rule = groups[g][r];
					if (!ruleMatches(rule.op, controllerValues(rule.field), rule.value)) {
						all = false;
						break;
					}
				}
				if (all) {
					anyGroup = true;
				}
			}
			return cond.mode === 'hide' ? !anyGroup : anyGroup;
		}

		function applyOnce() {
			var changed = false;
			form.querySelectorAll('.wkf-step[data-wkf-step-cond]').forEach(function (step) {
				var visible = evaluate(step, 'data-wkf-step-cond');
				var wasSkipped = step.hasAttribute('data-wkf-step-skip');
				if (visible === wasSkipped) {
					changed = true;
				}
				if (visible) {
					step.removeAttribute('data-wkf-step-skip');
				} else {
					step.setAttribute('data-wkf-step-skip', '1');
				}
				step.querySelectorAll('input, select, textarea').forEach(function (el) {
					if (!el.closest('.wkf-cond') || visible) {
						el.disabled = !visible;
					}
				});
			});
			conds.forEach(function (box) {
				var visible = evaluate(box);
				if (box.hidden !== !visible) {
					changed = true;
				}
				box.hidden = !visible;
				box.querySelectorAll('input, select, textarea').forEach(function (el) {
					el.disabled = !visible; // disabled pole se neodesílají ani nevalidují
				});
			});
			return changed;
		}

		function apply() {
			for (var pass = 0; pass < 10; pass++) {
				if (!applyOnce()) {
					break;
				}
			}
		}

		function applyAndNotify() {
			apply();
			if (form.wkfStepsRefresh) {
				form.wkfStepsRefresh();
			}
		}
		form.addEventListener('change', applyAndNotify);
		form.addEventListener('input', applyAndNotify);
		apply(); // výchozí/předvybrané hodnoty se promítnou hned po načtení
	}

	/** První neplatný prvek v rozsahu (stejné pořadí kontrol jako validateForm). */
	function firstInvalid(root) {
		var els = root.querySelectorAll('input[required], textarea[required], select[required]');
		for (var i = 0; i < els.length; i++) {
			var el = els[i];
			if (el.disabled || el.closest('.wkf-cond[hidden]')) { continue; }
			if (el.type === 'checkbox' && !el.checked) { return el; }
			if (el.type === 'file') { if (validFile(el)) { return el; } continue; }
			if (!el.value || !el.value.trim()) { return el; }
			if (el.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value.trim())) { return el; }
		}
		var groups = root.querySelectorAll('[data-wkf-required-group]');
		for (var g = 0; g < groups.length; g++) {
			if (groups[g].closest('.wkf-cond[hidden]')) { continue; }
			if (!groups[g].querySelector('input:checked')) { return groups[g].querySelector('input') || groups[g]; }
		}
		var files = root.querySelectorAll('input[type="file"]');
		for (var f = 0; f < files.length; f++) {
			if (!files[f].disabled && validFile(files[f])) { return files[f]; }
		}
		return null;
	}

	/**
	 * Vícekrokové formuláře: kroky (.wkf-step) jsou v HTML všechny viditelné,
	 * krokování je progresivní vylepšení. Skryté kroky jsou pro odečítač i
	 * klávesnici nedostupné (display:none + disabled), fokus jde na nadpis kroku,
	 * změna se hlásí přes aria-live, chyby se shrnou do seznamu s odkazy.
	 */
	function initSteps(form) {
		var steps = Array.prototype.slice.call(form.querySelectorAll('.wkf-step'));
		if (steps.length < 2) {
			return;
		}
		var head = form.querySelector('.wkf-steps-head');
		var live = form.querySelector('.wkf-step-live');
		var errorsBox = form.querySelector('.wkf-step-errors');
		var progress = form.querySelector('.wkf-progress');
		var submit = form.querySelector('.wkf-submit');
		var current = 0;

		form.classList.add('wkf-stepped');
		if (head) { head.hidden = false; }
		form.querySelectorAll('.wkf-step-nav').forEach(function (nav) { nav.hidden = false; });

		function active() {
			return steps.filter(function (s) { return !s.hasAttribute('data-wkf-step-skip'); });
		}

		function renderProgress(list, index) {
			if (!progress) { return; }
			var total = list.length;
			var n = index + 1;
			if (progress.getAttribute('data-wkf-progress') === 'bar') {
				var html = '<ol class="wkf-progress-steps" aria-label="Postup formulářem">';
				list.forEach(function (s, i) {
					html += '<li' + (i === index ? ' aria-current="step" class="is-current"' : (i < index ? ' class="is-done"' : '')) + '><span class="wkf-visually-hidden">' + (i === index ? 'Aktuální krok: ' : '') + '</span>' + (i + 1) + '. ' + s.getAttribute('data-wkf-step-title').replace(/</g, '&lt;') + '</li>';
				});
				progress.innerHTML = html + '</ol>';
			} else {
				var tpl = progress.getAttribute('data-wkf-progress-text') || 'Krok {n} z {total}';
				progress.textContent = tpl.replace('{n}', n).replace('{total}', total);
			}
		}

		function clearErrors() {
			if (errorsBox) { errorsBox.hidden = true; errorsBox.innerHTML = ''; }
			form.querySelectorAll('[aria-invalid="true"]').forEach(function (el) {
				el.removeAttribute('aria-invalid');
				el.removeAttribute('aria-describedby');
			});
			form.querySelectorAll('.wkf-field-error').forEach(function (n) { n.remove(); });
		}

		function showErrors(message, el) {
			clearErrors();
			if (el) {
				if (!el.id) { el.id = 'wkf-el-' + Math.random().toString(36).slice(2, 8); }
				var errId = el.id + '-error';
				var note = document.createElement('p');
				note.className = 'wkf-field-error';
				note.id = errId;
				note.textContent = message;
				var holder = el.closest('.wkf-field') || el.parentNode;
				holder.appendChild(note);
				el.setAttribute('aria-invalid', 'true');
				el.setAttribute('aria-describedby', errId);
			}
			if (errorsBox) {
				errorsBox.innerHTML = '<p><strong>Před pokračováním prosím opravte:</strong></p><ul><li>' + (el ? '<a href="#' + el.id + '">' : '') + message.replace(/</g, '&lt;') + (el ? '</a>' : '') + '</li></ul>';
				errorsBox.hidden = false;
				errorsBox.focus();
				errorsBox.querySelector('a') && errorsBox.querySelector('a').addEventListener('click', function (ev) {
					ev.preventDefault();
					el.focus();
					el.scrollIntoView({ block: 'center', behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
				});
			} else {
				showMessage(form, message, 'error');
			}
		}

		function prefersReducedMotion() {
			return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		}

		function show(index, announce) {
			var list = active();
			if (!list.length) { return; }
			index = Math.max(0, Math.min(index, list.length - 1));
			current = index;
			steps.forEach(function (s) {
				var isCurrent = s === list[index];
				s.classList.toggle('wkf-step-inactive', !isCurrent);
				s.setAttribute('aria-hidden', isCurrent ? 'false' : 'true');
				s.querySelectorAll('.wkf-step-nav button').forEach(function (b) { b.tabIndex = isCurrent ? 0 : -1; });
			});
			var last = index === list.length - 1;
			if (submit) { submit.hidden = !last; }
			var nextBtn = list[index].querySelector('.wkf-step-next');
			if (nextBtn) { nextBtn.hidden = last; }
			renderProgress(list, index);
			if (announce) {
				var title = list[index].getAttribute('data-wkf-step-title');
				if (live) { live.textContent = 'Krok ' + (index + 1) + ' z ' + list.length + ': ' + title; }
				var heading = list[index].querySelector('.wkf-step-title');
				if (heading) {
					heading.focus({ preventScroll: true });
					var top = form.getBoundingClientRect().top + window.pageYOffset - 20;
					if (window.pageYOffset > top) {
						window.scrollTo({ top: top, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
					}
				}
			}
		}

		function goNext() {
			var list = active();
			var scope = list[current];
			clearErrors();
			var error = validateForm(form, scope);
			if (error) {
				showErrors(error, firstInvalid(scope));
				return false;
			}
			if (current < list.length - 1) {
				show(current + 1, true);
				return false;
			}
			return true; // poslední krok – odeslat
		}

		form.addEventListener('click', function (e) {
			if (e.target.classList.contains('wkf-step-next')) {
				e.preventDefault();
				goNext();
			} else if (e.target.classList.contains('wkf-step-prev')) {
				e.preventDefault();
				clearErrors();
				show(current - 1, true);
			}
		});

		// Enter v poli nesmí odeslat formulář z prvního kroku.
		form.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.type !== 'submit' && current < active().length - 1) {
				e.preventDefault();
				goNext();
			}
		});

		// Po změně podmínek: aktuální krok mohl zmizet, přepočítat bez oznámení.
		form.wkfStepsRefresh = function () {
			var list = active();
			var cur = steps.filter(function (s) { return !s.classList.contains('wkf-step-inactive'); })[0];
			var idx = list.indexOf(cur);
			show(idx === -1 ? Math.min(current, list.length - 1) : idx, false);
		};
		form.wkfStepsIsLast = function () {
			return current >= active().length - 1;
		};
		form.wkfStepsGoNext = goNext;

		show(0, false);
	}

	/**
	 * Předvyplnění textových polí z query parametru URL nebo titulku stránky.
	 * Doplňuje se až v prohlížeči, takže funguje i na cachovaných stránkách,
	 * a nikdy nepřepíše hodnotu, kterou už pole má.
	 */
	function initPrefill(form) {
		form.querySelectorAll('input[data-wkf-prefill]').forEach(function (input) {
			if (input.value) {
				return;
			}
			var mode = input.getAttribute('data-wkf-prefill');
			if (mode === 'query') {
				var param = input.getAttribute('data-wkf-prefill-param') || 'from';
				var value = new URLSearchParams(window.location.search).get(param);
				if (value) {
					input.value = value.replace(/[+_-]/g, ' ').trim();
				}
			} else if (mode === 'title') {
				var title = document.title.split(/\s+[|–—-]\s+/)[0].trim();
				if (title) {
					input.value = title;
				}
			}
		});
	}

	function initForm(form) {
		// Každá část zvlášť: výjimka v jedné nesmí zabít odesílání formuláře.
		[initPrefill, fillContext, initDropzone, initSuggest, initTotal, initCond, initSteps].forEach(function (fn) {
			try {
				fn(form);
			} catch (err) {
				if (window.console) {
					console.error('Webklient Forms:', err);
				}
			}
		});

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			if (form.wkfStepsIsLast && !form.wkfStepsIsLast()) {
				form.wkfStepsGoNext();
				return;
			}

			showMessage(form, '', '');
			fillContext(form);

			// Kontext návštěvy (vstupní stránka, zdroj, cesta po webu).
			var journeyField = form.querySelector('[data-wkf-journey]');
			if (journeyField && window.wkfJourneyPayload) {
				journeyField.value = window.wkfJourneyPayload();
			}

			var error = validateForm(form);
			if (error) {
				showMessage(form, error, 'error');
				return;
			}

			var button = form.querySelector('.wkf-submit');
			var originalText = button ? button.textContent : '';
			if (button) {
				button.disabled = true;
				button.textContent = 'Odesílám…';
			}

			var data = new FormData(form);
			data.append('action', 'wkf_submit');
			data.append('nonce', window.wkfForms ? window.wkfForms.nonce : '');

			fetch(window.wkfForms ? window.wkfForms.ajaxUrl : '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (json) {
					if (json && json.success) {
						if (json.data && json.data.redirect) {
							window.location.href = json.data.redirect;
							return;
						}
						if (json.data && json.data.summary) {
							form.innerHTML = '<div class="wkf-message wkf-success" role="alert">'
								+ ((json.data && json.data.message) || 'Děkujeme, formulář byl úspěšně odeslán.')
								+ '</div>' + json.data.summary;
							form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
							return;
						}
						form.reset();
						fillContext(form);
						if (window.turnstile) {
							window.turnstile.reset();
						}
						form.querySelectorAll('[data-wkf-filename]').forEach(function (label) {
							label.textContent = '';
						});
						showMessage(form, '', 'success');
						var okBox = form.querySelector('[data-wkf-message]');
						if (okBox) {
							okBox.innerHTML = (json.data && json.data.message) || 'Děkujeme, formulář byl úspěšně odeslán.';
							okBox.className = 'wkf-message wkf-message-success';
						}
					} else {
						if (window.turnstile) {
							window.turnstile.reset();
						}
						showMessage(form, (json && json.data && json.data.message) || 'Odeslání se nezdařilo. Zkuste to prosím znovu.', 'error');
					}
				})
				.catch(function () {
					showMessage(form, 'Odeslání se nezdařilo. Zkontrolujte prosím připojení a zkuste to znovu.', 'error');
				})
				.finally(function () {
					if (button) {
						button.disabled = false;
						button.textContent = originalText;
					}
				});
		});
	}

	ready(function () {
		document.querySelectorAll('form[data-wkf-form]').forEach(initForm);
	});
})();
