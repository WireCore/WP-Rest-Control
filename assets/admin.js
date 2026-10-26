/**
 * Routes tab: loads the route list via REST, then search, filters,
 * expand/collapse, quick actions, the "Advanced" dialog, the live effective
 * rule and serializing all rules into one JSON field.
 */
(function () {
	const form = document.getElementById('wirecore-dr-routes');
	if (!form) {
		return;
	}
	const i18n = window.wirecoreDr;
	const body = document.getElementById('wirecore-dr-routes-body');
	const dialog = document.getElementById('wirecore-dr-advanced');
	const ALLOW = ['ip_allow', 'users_allow', 'roles_allow'];
	const DENY = ['ip_deny', 'roles_deny'];
	// WireLayer badge variant per rule
	const VARIANTS = {
		public: 'success',
		auth: 'warn',
		admin: 'warn',
		blocked: 'danger',
	};
	let search, source, filter, expand, namespaces;
	let current = null;

	function select(scope, key, group) {
		return form.querySelector(
			'select[data-scope="' +
				scope +
				'"][data-key="' +
				CSS.escape(key) +
				'"][data-group="' +
				group +
				'"]'
		);
	}

	function advancedButton(target) {
		return form.querySelector(
			'.wirecore-dr-advanced-open[data-target="' +
				CSS.escape(target) +
				'"]'
		);
	}

	function accessOf(target) {
		const button = advancedButton(target);
		return button ? JSON.parse(button.dataset.access || '{}') : {};
	}

	function count(access, lists) {
		return lists.reduce(function (sum, list) {
			return sum + (access[list] || []).length;
		}, 0);
	}

	// Route before namespace before automatic allowance before default, like
	// WireCore_DR_Guard::rule()
	function effective(row, group) {
		const own = select('route', row.dataset.route, group);
		if (own.disabled) {
			return null;
		}
		if (own.value !== 'inherit') {
			return { rule: own.value };
		}
		const ns = select('namespace', row.dataset.namespace, group);
		if (ns && ns.value !== 'inherit') {
			return { rule: ns.value };
		}
		if (i18n.autoAllow.includes(row.dataset.namespace)) {
			return { rule: 'public', auto: true };
		}
		return { rule: select('default', '', group).value };
	}

	function badge(parent, variant, text) {
		const el = document.createElement('span');
		el.className = 'wc-badge wc-badge-' + variant;
		el.textContent = text;
		parent.appendChild(el);
	}

	function renderSummary(button) {
		const access = JSON.parse(button.dataset.access || '{}');
		const box = button.parentNode.querySelector(
			'.wirecore-dr-access-summary'
		);
		box.textContent = '';
		ALLOW.concat(DENY).forEach(function (list) {
			const values = access[list] || [];
			if (!values.length) {
				return;
			}
			const text =
				list.indexOf('roles_') === 0
					? values
							.map(function (role) {
								return i18n.roles[role] || role;
							})
							.join(', ')
					: values.length;
			badge(
				box,
				DENY.includes(list) ? 'danger' : 'info',
				i18n.summary[list] + ': ' + text
			);
		});
	}

	function update() {
		form.querySelectorAll('.wirecore-dr-route').forEach(function (row) {
			const cell = row.querySelector('.wirecore-dr-effective');
			cell.textContent = '';
			let open = false;
			['read', 'write'].forEach(function (group) {
				const result = effective(row, group);
				if (result === null) {
					return;
				}
				open = open || result.rule === 'public';
				badge(
					cell,
					VARIANTS[result.rule],
					i18n[group] +
						': ' +
						i18n.labels[result.rule] +
						(result.auto ? ' (' + i18n.auto + ')' : '')
				);
			});
			// Exceptions of the route and of its namespace both apply
			const own = accessOf(row.dataset.route);
			const ns = accessOf(row.dataset.namespace);
			const allow = count(own, ALLOW) + count(ns, ALLOW);
			const deny = count(own, DENY) + count(ns, DENY);
			if (allow) {
				badge(cell, 'info', i18n.summary.allow + ': ' + allow);
			}
			if (deny) {
				badge(cell, 'danger', i18n.summary.deny + ': ' + deny);
			}
			row.dataset.open = open ? '1' : '';
			row.dataset.override =
				count(own, ALLOW.concat(DENY)) ||
				Array.from(row.querySelectorAll('select')).some(function (el) {
					return !el.disabled && el.value !== 'inherit';
				})
					? '1'
					: '';
		});
		applyFilter();
	}

	function toggle(ns, open) {
		ns.querySelector('.wirecore-dr-toggle').setAttribute(
			'aria-expanded',
			open
		);
		ns.querySelector('.wirecore-dr-routes').hidden = !open;
	}

	function applyFilter() {
		const term = search.value.trim().toLowerCase();
		const active =
			term !== '' || source.value !== '' || filter.value !== '';
		namespaces.forEach(function (ns) {
			const nsMatch =
				term !== '' &&
				ns.dataset.namespace.toLowerCase().includes(term);
			let visible = 0;
			ns.querySelectorAll('.wirecore-dr-route').forEach(function (row) {
				const show =
					(term === '' ||
						nsMatch ||
						row.dataset.route.toLowerCase().includes(term)) &&
					(source.value === '' ||
						row.dataset.source === source.value) &&
					(filter.value === '' ||
						(filter.value === 'override' && row.dataset.override) ||
						(filter.value === 'public' && row.dataset.open) ||
						(filter.value === 'restricted' && !row.dataset.open));
				row.hidden = !show;
				visible += show ? 1 : 0;
			});
			ns.hidden = active && visible === 0;
			if (active) {
				toggle(ns, visible > 0);
			}
		});
	}

	function openDialog(button) {
		current = button;
		const access = JSON.parse(button.dataset.access || '{}');
		dialog.querySelector('.wirecore-dr-advanced-target').textContent =
			button.dataset.target || i18n.noNamespace;
		dialog.querySelectorAll('[data-list]').forEach(function (el) {
			const values = access[el.dataset.list] || [];
			if (el.type === 'checkbox') {
				el.checked = values.includes(el.value);
			} else {
				el.value = values.join(el.tagName === 'TEXTAREA' ? '\n' : ', ');
			}
		});
		dialog.showModal();
	}

	function applyDialog() {
		const access = {};
		dialog.querySelectorAll('[data-list]').forEach(function (el) {
			const list = el.dataset.list;
			const values =
				el.type === 'checkbox'
					? el.checked
						? [el.value]
						: []
					: el.value.split(/[\s,;]+/).filter(Boolean);
			if (values.length) {
				access[list] = (access[list] || []).concat(values);
			}
		});
		current.dataset.access = JSON.stringify(access);
		renderSummary(current);
		update();
		dialog.close();
		current.focus();
	}

	dialog.addEventListener('click', function (event) {
		const action = event.target.dataset.dialog;
		if (action === 'apply') {
			applyDialog();
		} else if (action === 'cancel') {
			dialog.close();
		}
	});

	const presets = {
		guests: function () {
			select('default', '', 'read').value = 'auth';
			select('default', '', 'write').value = 'auth';
		},
		all: function () {
			select('default', '', 'read').value = 'blocked';
			select('default', '', 'write').value = 'blocked';
		},
		// Only the routes that list users; the block editor needs /users/me
		// for non-admins as well
		users: function () {
			['/wp/v2/users', '/wp/v2/users/(?P<id>[\\d]+)'].forEach(
				function (route) {
					['read', 'write'].forEach(function (group) {
						const el = select('route', route, group);
						if (el && !el.disabled) {
							el.value = 'admin';
						}
					});
				}
			);
		},
		reset: function () {
			form.querySelectorAll(
				'select[data-scope="namespace"], select[data-scope="route"]'
			).forEach(function (el) {
				el.value = 'inherit';
			});
			form.querySelectorAll('.wirecore-dr-advanced-open').forEach(
				function (el) {
					el.dataset.access = '{}';
					renderSummary(el);
				}
			);
		},
	};

	function init() {
		search = document.getElementById('wirecore-dr-search');
		source = document.getElementById('wirecore-dr-source');
		filter = document.getElementById('wirecore-dr-filter');
		expand = document.getElementById('wirecore-dr-expand');
		namespaces = Array.from(
			form.querySelectorAll('.wirecore-dr-namespace')
		);

		form.querySelectorAll('[data-preset]').forEach(function (button) {
			button.addEventListener('click', function () {
				presets[button.dataset.preset]();
				update();
			});
		});

		namespaces.forEach(function (ns) {
			ns.querySelector('.wirecore-dr-toggle').addEventListener(
				'click',
				function () {
					toggle(ns, this.getAttribute('aria-expanded') !== 'true');
				}
			);
		});

		expand.addEventListener('click', function () {
			const open = expand.dataset.open !== '1';
			expand.dataset.open = open ? '1' : '';
			expand.textContent = open ? i18n.collapse : i18n.expand;
			namespaces.forEach(function (ns) {
				toggle(ns, open);
			});
		});

		body.addEventListener('click', function (event) {
			const button = event.target.closest('.wirecore-dr-advanced-open');
			if (button) {
				openDialog(button);
			}
		});

		form.addEventListener('change', function (event) {
			if (event.target.matches('select[data-scope]')) {
				update();
			}
		});
		search.addEventListener('input', applyFilter);
		source.addEventListener('change', applyFilter);
		filter.addEventListener('change', applyFilter);

		form.querySelectorAll('.wirecore-dr-advanced-open').forEach(
			renderSummary
		);
		update();
		form.querySelector('[type="submit"]').disabled = false;
	}

	form.addEventListener('submit', function () {
		const rules = { default: {}, namespaces: {}, routes: {}, access: {} };
		form.querySelectorAll('select[data-scope]').forEach(function (el) {
			const scope = el.dataset.scope;
			const value = el.disabled ? 'inherit' : el.value;
			if (scope === 'default') {
				rules.default[el.dataset.group] = value;
				return;
			}
			const list = rules[scope === 'route' ? 'routes' : 'namespaces'];
			list[el.dataset.key] = list[el.dataset.key] || {};
			list[el.dataset.key][el.dataset.group] = value;
		});
		form.querySelectorAll('.wirecore-dr-advanced-open').forEach(
			function (el) {
				const access = JSON.parse(el.dataset.access || '{}');
				if (Object.keys(access).length) {
					rules.access[el.dataset.target] = access;
				}
			}
		);
		form.elements.rules_json.value = JSON.stringify(rules);
	});

	fetch(i18n.url, {
		credentials: 'same-origin',
		// Accept: application/json, otherwise WordPress ignores _locale=user
		headers: { 'X-WP-Nonce': i18n.nonce, Accept: 'application/json' },
	})
		.then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) {
					throw new Error(data.message || response.statusText);
				}
				return data;
			});
		})
		.then(function (data) {
			body.innerHTML = data[i18n.field];
			init();
		})
		.catch(function (error) {
			body.innerHTML = '';
			const alert = document.createElement('div');
			alert.className = 'wc-alert wc-alert-danger';
			alert.setAttribute('role', 'alert');
			const content = alert.appendChild(document.createElement('div'));
			content.className = 'wc-alert-content';
			const text = content.appendChild(document.createElement('div'));
			text.className = 'wc-alert-description';
			text.textContent = i18n.loadError + ' ' + error.message;
			body.appendChild(alert);
		});
})();
