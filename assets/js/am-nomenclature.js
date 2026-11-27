/* Archivio Micologico — Nomenclatura (admin).
 * Checks each scheda against the Catalogue of Life (ChecklistBank, Species Fungorum for fungi)
 * from the editor's browser and stores the result through admin-ajax. ES2017, no dependencies. */
(function () {
	'use strict';
	var cfg = window.AM_NOM;
	var table = document.querySelector('.am-nom-table');
	if (!cfg || !table) { return; }

	var API = 'https://api.checklistbank.org/dataset/3LR/';
	var t = cfg.i18n;
	var progress = document.querySelector('.am-nom-progress');
	var stopBtn = document.querySelector('[data-nom-stop]');
	var running = false;
	var stopRequested = false;

	function get(path) {
		return fetch(API + path, { headers: { Accept: 'application/json' } }).then(function (r) {
			if (r.status === 404) { return null; }
			if (!r.ok) { throw new Error('HTTP ' + r.status); }
			return r.json();
		});
	}

	function sleep(ms) { return new Promise(function (res) { setTimeout(res, ms); }); }

	function recordId(link) {
		var m = /RecordID=(\d+)/.exec(link || '');
		return m ? m[1] : '';
	}

	function squash(s) { return String(s || '').replace(/[\s.]/g, '').toLowerCase(); }

	/** Returns the data to store for one name. */
	async function lookup(name, rank) {
		var m = await get('match/nameusage?q=' + encodeURIComponent(name) + '&rank=' + encodeURIComponent(rank));
		if (!m || !m.usage) { return { status: 'notfound' }; }
		if (m.type === 'ambiguous') { return { status: 'ambiguous', name: m.usage.name || '' }; }
		if (!m.match || m.usage.rank !== rank || ['none', 'higherrank', 'insufficient'].indexOf(m.type) !== -1) {
			return { status: 'notfound' };
		}
		var u = m.usage;
		var isSyn = /synonym|misapplied/.test(u.status || '');
		var acc = isSyn ? (u.classification || [])[0] : u;
		if (!acc || !acc.id) { return { status: 'ambiguous', name: u.name || '' }; }

		var src = await get('nameusage/' + encodeURIComponent(acc.id) + '/source');
		var accIf = src && src.sourceDatasetKey === 2073 && /^\d+$/.test(src.sourceId || '') ? src.sourceId : '';
		var syn = await get('taxon/' + encodeURIComponent(acc.id) + '/synonyms') || {};
		var list = [].concat(syn.homotypic || [], syn.heterotypic || []);
		var synonyms = [];
		var ownIf = isSyn ? '' : accIf;
		list.forEach(function (s) {
			var n = s && s.name ? s.name : null;
			if (!n || !n.scientificName) { return; }
			synonyms.push([n.scientificName, n.authorship || '']);
			if (isSyn && !ownIf && n.scientificName === u.name) { ownIf = recordId(n.link); }
		});
		return {
			status: isSyn ? 'synonym' : 'accepted',
			name: u.name || '',
			authorship: u.authorship || '',
			if_id: ownIf,
			col_id: u.id || '',
			current: isSyn ? (acc.name || '') : '',
			current_authorship: isSyn ? (acc.authorship || '') : '',
			current_if_id: isSyn ? accIf : '',
			current_col_id: isSyn ? (acc.id || '') : '',
			synonyms: synonyms
		};
	}

	function save(postId, data) {
		var body = new URLSearchParams();
		body.set('action', 'am_nom_save');
		body.set('nonce', cfg.nonce);
		body.set('post_id', postId);
		body.set('data', JSON.stringify(data));
		return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
	}

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text) { e.textContent = text; }
		return e;
	}

	function paint(row, d) {
		var cell = row.querySelector('.am-nom-result');
		cell.textContent = '';
		var badge = el('span', 'am-status am-status--nom-' + d.status, t[d.status] || d.status);
		cell.appendChild(badge);
		if (d.status === 'synonym' && d.current) {
			cell.appendChild(document.createTextNode(' → '));
			cell.appendChild(el('em', '', d.current));
		}
		if (d.if_id) {
			var a = el('a', '', 'IF ' + d.if_id);
			a.href = 'https://www.indexfungorum.org/Names/NamesRecord.asp?RecordID=' + d.if_id;
			a.target = '_blank';
			a.rel = 'noopener';
			cell.appendChild(document.createTextNode(' '));
			cell.appendChild(a);
		}
		var author = row.querySelector('.am-nom-author');
		author.textContent = d.authorship || '';
		var own = row.getAttribute('data-author') || '';
		if (d.authorship && own && squash(d.authorship) !== squash(own)) {
			author.appendChild(el('span', 'am-nom-diff', ' (' + t.author_diff + ': ' + own + ')'));
		}
		row.querySelector('.am-nom-syn').textContent = d.synonyms ? String(d.synonyms.length) : '';
		row.setAttribute('data-status', d.status);
	}

	function recount() {
		var counts = {};
		table.querySelectorAll('[data-nom-row]').forEach(function (r) {
			var s = r.getAttribute('data-status');
			counts[s] = (counts[s] || 0) + 1;
		});
		document.querySelectorAll('[data-count]').forEach(function (c) {
			c.textContent = String(counts[c.getAttribute('data-count')] || 0);
		});
	}

	async function check(row) {
		var btn = row.querySelector('[data-nom-one]');
		btn.disabled = true;
		row.querySelector('.am-nom-result').textContent = t.checking;
		try {
			var d = await lookup(row.getAttribute('data-name'), row.getAttribute('data-rank') || 'species');
			var res = await save(row.getAttribute('data-nom-row'), d);
			if (!res || !res.success) { throw new Error((res && res.data && res.data.message) || 'save'); }
			paint(row, res.data.data);
		} catch (e) {
			row.querySelector('.am-nom-result').textContent = t.error;
			btn.disabled = false;
			throw e;
		}
		btn.disabled = false;
	}

	async function run(rows) {
		if (running) { return; }
		running = true;
		stopRequested = false;
		stopBtn.hidden = false;
		var errors = 0;
		for (var i = 0; i < rows.length && !stopRequested; i++) {
			progress.textContent = t.progress.replace('%1$d', String(i)).replace('%2$d', String(rows.length));
			try { await check(rows[i]); errors = 0; } catch (e) {
				if (++errors >= 3) { break; } // the service is down: stop instead of hammering it
			}
			recount();
			await sleep(250);
		}
		progress.textContent = errors >= 3 ? t.error : t.done;
		stopBtn.hidden = true;
		running = false;
	}

	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-nom-run],[data-nom-one],[data-nom-stop],.am-nom-filter');
		if (!b) { return; }
		e.preventDefault();
		if (b.hasAttribute('data-nom-stop')) { stopRequested = true; return; }
		if (b.hasAttribute('data-nom-one')) {
			var row = b.closest('[data-nom-row]');
			check(row).then(recount, recount);
			return;
		}
		if (b.hasAttribute('data-nom-run')) {
			var all = Array.prototype.slice.call(table.querySelectorAll('[data-nom-row]'));
			run(b.getAttribute('data-nom-run') === 'all' ? all : all.filter(function (r) { return r.getAttribute('data-status') === 'unchecked'; }));
			return;
		}
		var f = b.getAttribute('data-filter');
		table.querySelectorAll('[data-nom-row]').forEach(function (r) {
			r.hidden = !!f && r.getAttribute('data-status') !== f;
		});
	});
})();
