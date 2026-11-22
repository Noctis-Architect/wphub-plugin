/* Hubpulse admin: settings toggles and the AI Writer. No dependencies. */
(function () {
	'use strict';

	var cfg = window.wphsAdmin || {};
	var t = cfg.i18n || {};
	var root = document.querySelector('.wphs');

	if (!root) {
		return;
	}

	function $(sel, ctx) {
		return (ctx || document).querySelector(sel);
	}

	function $all(sel, ctx) {
		return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
	}

	function el(tag, attrs, text) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			node.setAttribute(key, attrs[key]);
		});
		if (text !== undefined && text !== null) {
			node.textContent = String(text);
		}
		return node;
	}

	/* ───────── messages ───────── */

	var live = $('.wphs-live-msg');

	function say(message, type) {
		if (!live) {
			return;
		}
		live.hidden = !message;
		live.className = 'wphs-live-msg wphs-notice ' + (type === 'error' ? 'is-error' : type === 'success' ? 'is-success' : 'is-info');
		live.textContent = message || '';
		if (message) {
			live.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		}
	}

	/* ───────── ajax ───────── */

	function call(action, data) {
		var body = new FormData();
		body.append('action', 'wphs_' + action);
		body.append('nonce', cfg.nonce || '');
		Object.keys(data || {}).forEach(function (key) {
			body.append(key, data[key]);
		});

		return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) {
				return res.json().catch(function () {
					return { success: false, data: { message: t.failed } };
				});
			})
			.then(function (json) {
				if (!json || !json.success) {
					var msg = json && json.data && json.data.message ? json.data.message : t.failed;
					var err = new Error(msg);
					err.data = json && json.data;
					throw err;
				}
				return json.data || {};
			});
	}

	function busy(button, on) {
		if (!button) {
			return;
		}
		if (on) {
			button.dataset.label = button.textContent;
			button.textContent = t.working || '…';
			button.disabled = true;
			button.classList.add('is-busy');
		} else {
			button.textContent = button.dataset.label || button.textContent;
			button.disabled = false;
			button.classList.remove('is-busy');
		}
	}

	function formData(form) {
		var out = {};
		$all('input, select, textarea', form).forEach(function (input) {
			if (!input.name) {
				return;
			}
			if (input.type === 'checkbox') {
				out[input.name] = input.checked ? '1' : '0';
			} else {
				out[input.name] = input.value;
			}
		});
		return out;
	}

	/* ───────── settings page ───────── */

	$all('[data-wphs-confirm]').forEach(function (form) {
		form.addEventListener('submit', function (event) {
			if (!window.confirm(form.getAttribute('data-wphs-confirm'))) {
				event.preventDefault();
			}
		});
	});

	/* ───────── provider presets ───────── */

	var providerSelect = $('[data-wphs-provider]');

	function syncProviderFields() {
		var isWp = providerSelect.value === 'wordpress';
		var form = providerSelect.form;
		$all('[data-wphs-http]', form).forEach(function (row) {
			row.hidden = isWp;
			$all('input', row).forEach(function (input) {
				if (input.name === 'base_url' || input.name === 'model') {
					input.required = !isWp;
				}
			});
		});
		$all('[data-wphs-wp-only]', form).forEach(function (row) {
			row.hidden = !isWp;
		});
		var signup = $('[data-wphs-signup]', form);
		if (signup) {
			$('[data-label-key]', signup).hidden = isWp;
			$('[data-label-wp]', signup).hidden = !isWp;
		}
	}

	if (providerSelect) {
		syncProviderFields();
		providerSelect.addEventListener('change', function () {
			syncProviderFields();
			var preset = (cfg.presets || {})[providerSelect.value];
			var form = providerSelect.form;
			var signup = $('[data-wphs-signup]', form);
			if (!preset) {
				return;
			}
			if (signup) {
				signup.hidden = !preset.signup;
				signup.href = preset.signup || '#';
			}
			if (providerSelect.value === 'wordpress') {
				return;
			}
			if (preset.base || providerSelect.value === 'custom') {
				form.elements.base_url.value = preset.base;
			}
			if (preset.model) {
				form.elements.model.value = preset.model;
			}
			$('#wphs-models').innerHTML = '';
		});
	}

	/* ───────── console articles ───────── */

	var list = $('[data-wphs-articles]');
	var pollTimer = null;

	function statusLabel(status) {
		return (t.status && t.status[status]) || status;
	}

	function renderArticles(items) {
		if (!list) {
			return;
		}
		list.innerHTML = '';
		if (!items.length) {
			list.appendChild(el('p', { class: 'wphs-muted' }, t.empty));
			return;
		}
		var canPublish = list.getAttribute('data-can-publish') === '1';
		var pending = false;

		items.forEach(function (item) {
			var row = el('div', { class: 'wphs-article' });
			var main = el('div', { class: 'wphs-article-main' });
			main.appendChild(el('strong', {}, item.title));
			var meta = el('span', { class: 'wphs-muted' });
			var pill = el('em', { class: 'wphs-pill is-' + item.status }, statusLabel(item.status));
			meta.appendChild(pill);
			if (item.status === 'generating' && item.progress) {
				meta.appendChild(document.createTextNode(' ' + item.progress));
			} else if (item.word_count) {
				meta.appendChild(document.createTextNode(' ' + item.word_count + ' ' + t.words));
			}
			if (item.error) {
				meta.appendChild(el('span', { class: 'wphs-article-error' }, ' ' + item.error));
			}
			main.appendChild(meta);
			row.appendChild(main);

			var actions = el('div', { class: 'wphs-article-actions' });
			if (item.status === 'queued' || item.status === 'generating') {
				pending = true;
			}
			if (['ready', 'published', 'publish_failed'].indexOf(item.status) !== -1) {
				var preview = el('button', { type: 'button', class: 'wphs-link-btn' }, t.preview);
				preview.addEventListener('click', function () {
					openPreview(item.id);
				});
				actions.appendChild(preview);
			}
			if (item.status === 'ready' || item.status === 'publish_failed') {
				actions.appendChild(publishButton(item.id, 'draft', t.sendDraft));
				if (canPublish) {
					actions.appendChild(publishButton(item.id, 'publish', t.publish));
				}
			}
			if (item.wp_link) {
				actions.appendChild(el('a', { href: item.wp_link, target: '_blank', rel: 'noopener' }, t.view));
			}
			row.appendChild(actions);
			list.appendChild(row);
		});

		clearTimeout(pollTimer);
		if (pending) {
			pollTimer = setTimeout(loadArticles, 8000);
		}
	}

	function publishButton(id, status, label) {
		var button = el('button', { type: 'button', class: 'wphs-btn ' + (status === 'publish' ? 'is-primary' : 'is-secondary') + ' is-small' }, label);
		button.addEventListener('click', function () {
			busy(button, true);
			call('console_publish', { id: id, status: status })
				.then(function () {
					say('', '');
					loadArticles();
				})
				.catch(function (err) {
					busy(button, false);
					say(err.message, 'error');
				});
		});
		return button;
	}

	function loadArticles() {
		if (!list) {
			return Promise.resolve();
		}
		return call('console_articles')
			.then(function (data) {
				renderArticles(data.items || []);
			})
			.catch(function (err) {
				list.innerHTML = '';
				list.appendChild(el('p', { class: 'wphs-article-error' }, err.message));
			});
	}

	function loadStatus() {
		var box = $('[data-wphs-quota]');
		if (!box) {
			return;
		}
		call('console_status')
			.then(function (data) {
				var q = (data.quota || {}).articles || {};
				var limit = Number(q.limit || 0);
				var remaining = Number(q.remaining || 0);
				$('[data-q="articles"]', box).textContent = remaining + ' ' + (t.of || '/') + ' ' + limit;
				$('[data-q="bar"]', box).style.width = (limit ? Math.round((remaining / limit) * 100) : 0) + '%';
				$('[data-q="plan"]', box).textContent = (data.quota || {}).plan || '—';
				var beta = ((data.quota || {}).beta || {}).active;
				var betaTag = $('[data-q="beta"]', box);
				if (betaTag) {
					betaTag.hidden = !beta;
				}
				var conn = data.connection || {};
				$('[data-q="conn"]', box).textContent = (t.conn && t.conn[conn.status]) || conn.status || '—';
				if (data.studio_url) {
					$('[data-q="studio"]', box).href = data.studio_url;
				}
				box.classList.toggle('is-empty', limit > 0 && remaining <= 0);
			})
			.catch(function (err) {
				say(err.message, 'error');
			});
	}

	/* ───────── preview modal ───────── */

	var modal = $('[data-wphs-modal]');

	function closeModal() {
		if (modal) {
			modal.hidden = true;
			$('[data-modal-body]', modal).innerHTML = '';
		}
	}

	function openPreview(id) {
		if (!modal) {
			return;
		}
		say(t.working, 'info');
		call('console_article', { id: id })
			.then(function (data) {
				say('', '');
				var art = data.article || {};
				$('[data-modal-title]', modal).textContent = art.title || '';
				var meta = $('[data-modal-meta]', modal);
				meta.innerHTML = '';
				[art.meta_title, art.meta_description, art.focus_keyword].forEach(function (value) {
					if (value) {
						meta.appendChild(el('span', {}, value));
					}
				});
				// Rendered in a sandboxed frame: no scripts, no access to this admin page.
				var frame = el('iframe', { sandbox: 'allow-same-origin', title: art.title || '' });
				frame.srcdoc = '<!doctype html><meta charset="utf-8"><base target="_blank"><style>body{font:15px/1.8 Vazirmatn,Tahoma,sans-serif;margin:16px;color:#0f172a}img{max-width:100%;height:auto}table{border-collapse:collapse}td,th{border:1px solid #ddd;padding:6px}</style><body dir="' + (document.dir || 'ltr') + '">' + (art.content_html || '') + '</body>';
				var body = $('[data-modal-body]', modal);
				body.innerHTML = '';
				body.appendChild(frame);
				var foot = $('[data-modal-foot]', modal);
				foot.innerHTML = '';
				if (art.status === 'ready' || art.status === 'publish_failed') {
					foot.appendChild(publishButton(art.id, 'draft', t.sendDraft));
				}
				modal.hidden = false;
			})
			.catch(function (err) {
				say(err.message, 'error');
			});
	}

	if (modal) {
		modal.addEventListener('click', function (event) {
			if (event.target === modal) {
				closeModal();
			}
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				closeModal();
			}
		});
	}

	/* ───────── manual list ───────── */

	function prependManual(post) {
		var box = $('[data-wphs-manual-list]');
		if (!box || !post) {
			return;
		}
		var empty = $('[data-wphs-empty]', box);
		if (empty) {
			empty.remove();
		}
		var row = el('div', { class: 'wphs-article is-new' });
		var main = el('div', { class: 'wphs-article-main' });
		main.appendChild(el('strong', {}, post.title));
		main.appendChild(el('span', { class: 'wphs-muted' }, post.status + ' · ' + post.date));
		row.appendChild(main);
		var actions = el('div', { class: 'wphs-article-actions' });
		actions.appendChild(el('a', { href: post.preview, target: '_blank', rel: 'noopener' }, t.preview));
		actions.appendChild(el('a', { href: post.edit }, t.edit));
		row.appendChild(actions);
		box.insertBefore(row, box.firstChild);
	}

	/* ───────── actions ───────── */

	var clicks = {
		console_link: function (button) {
			busy(button, true);
			call('console_link')
				.then(function () {
					window.location.reload();
				})
				.catch(function (err) {
					busy(button, false);
					say(err.message, 'error');
				});
		},
		console_unlink: function (button) {
			if (!window.confirm(t.confirmOff)) {
				return;
			}
			busy(button, true);
			call('console_unlink').then(function () {
				window.location.reload();
			}).catch(function (err) {
				busy(button, false);
				say(err.message, 'error');
			});
		},
		console_articles: function () {
			loadArticles();
			loadStatus();
		},
		reset_prompt: function (button) {
			var area = button.form && button.form.elements.system_prompt;
			if (area && window.confirm(t.confirmReset)) {
				area.value = cfg.prompt || '';
			}
		},
		modal_close: closeModal,
		ai_test: function (button) {
			var form = button.form;
			var out = $('[data-wphs-test]', form);
			var data = formData(form);
			out.className = 'wphs-test-result';
			out.textContent = t.testing;
			busy(button, true);
			call('ai_test', {
				provider: data.provider,
				base_url: data.base_url,
				api_key: data.api_key,
				model: data.model
			})
				.then(function (res) {
					out.className = 'wphs-test-result is-ok';
					out.textContent = res.message + fillModels(res.models);
				})
				.catch(function (err) {
					out.className = 'wphs-test-result is-bad';
					out.textContent = err.message + fillModels(err.data && err.data.models);
				})
				.then(function () {
					busy(button, false);
				});
		}
	};

	function fillModels(models) {
		var datalist = $('#wphs-models');
		if (!datalist || !models || !models.length) {
			return '';
		}
		datalist.innerHTML = '';
		models.forEach(function (id) {
			datalist.appendChild(el('option', { value: id }));
		});
		return ' — ' + models.length + ' ' + t.models;
	}

	var forms = {
		site_key: function (form, button) {
			busy(button, true);
			call('site_key', formData(form))
				.then(function () {
					window.location.reload();
				})
				.catch(function (err) {
					busy(button, false);
					say(err.message, 'error');
				});
		},
		console_create: function (form, button) {
			busy(button, true);
			call('console_create', formData(form))
				.then(function () {
					say(t.queued, 'success');
					form.reset();
					loadArticles();
					loadStatus();
				})
				.catch(function (err) {
					say(err.message, 'error');
				})
				.then(function () {
					busy(button, false);
				});
		},
		ai_generate: function (form, button) {
			busy(button, true);
			say(t.writing, 'info');
			call('ai_generate', formData(form))
				.then(function (data) {
					say('', '');
					form.reset();
					prependManual(data.post);
					if (data.post && data.post.edit) {
						say((data.post.title || '') + ' — ' + data.post.status, 'success');
					}
				})
				.catch(function (err) {
					say(err.message, 'error');
				})
				.then(function () {
					busy(button, false);
				});
		}
	};

	document.addEventListener('click', function (event) {
		var target = event.target.closest('[data-wphs-click]');
		if (!target) {
			return;
		}
		var handler = clicks[target.getAttribute('data-wphs-click')];
		if (handler) {
			event.preventDefault();
			handler(target);
		}
	});

	$all('form[data-wphs-action]').forEach(function (form) {
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			var handler = forms[form.getAttribute('data-wphs-action')];
			if (handler) {
				handler(form, $('button[type="submit"]', form));
			}
		});
	});

	if (list) {
		loadArticles();
	}
	loadStatus();
})();
