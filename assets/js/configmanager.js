/* ============================================================
   Config Manager – Enterprise JS
   ============================================================ */
(function () {
'use strict';

/* ── Confirm on delete forms ─────────────────────────────── */
document.addEventListener('submit', function (e) {
	var msg = e.target.getAttribute('data-cm-confirm');
	if (msg && !window.confirm(msg)) e.preventDefault();
});

/* ── Edit panel toggle ───────────────────────────────────── */
document.addEventListener('click', function (e) {
	if (e.target.closest('[data-edit-toggle]')) {
		var wrap  = e.target.closest('[data-edit-wrap]');
		var panel = wrap && wrap.querySelector('[data-edit-panel]');
		if (!panel) return;
		var open = panel.classList.contains('open');
		document.querySelectorAll('[data-edit-panel].open').forEach(function (p) {
			p.classList.remove('open');
		});
		if (!open) panel.classList.add('open');
		e.stopPropagation();
		return;
	}
	if (e.target.closest('[data-edit-close]')) {
		var p = e.target.closest('[data-edit-panel]');
		if (p) p.classList.remove('open');
		return;
	}
	if (!e.target.closest('[data-edit-wrap]')) {
		document.querySelectorAll('[data-edit-panel].open').forEach(function (p) {
			p.classList.remove('open');
		});
	}
});

/* ── Count-up KPI values ─────────────────────────────────── */
document.querySelectorAll('.cm-kpi-value[data-count]').forEach(function (el) {
	var raw = parseFloat(el.getAttribute('data-count'));
	if (isNaN(raw) || raw === 0) return;
	var dur = 900, start = null;
	function step(ts) {
		if (!start) start = ts;
		var p = Math.min((ts - start) / dur, 1);
		var e = 1 - Math.pow(1 - p, 3);
		el.textContent = Math.round(e * raw).toLocaleString();
		if (p < 1) requestAnimationFrame(step);
		else el.textContent = Math.round(raw).toLocaleString();
	}
	requestAnimationFrame(step);
});

/* ── Config viewer search ────────────────────────────────── */
var configSearch = document.getElementById('cm-config-search');
var configPre    = document.querySelector('.cm-config-pre');
var configCounter= document.getElementById('cm-config-counter');

if (configSearch && configPre) {
	configSearch.addEventListener('input', function () {
		var q     = configSearch.value.toLowerCase().trim();
		var lines = configPre.querySelectorAll('.cm-config-line');
		var count = 0;
		lines.forEach(function (line) {
			var text = (line.querySelector('.cm-config-text') || line).textContent.toLowerCase();
			if (q && text.includes(q)) {
				line.classList.add('highlight');
				count++;
			} else {
				line.classList.remove('highlight');
			}
		});
		if (configCounter) {
			configCounter.textContent = q ? count + ' match' + (count !== 1 ? 'es' : '') : '';
		}
		if (count > 0) {
			var first = configPre.querySelector('.cm-config-line.highlight');
			if (first) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
		}
	});
}

/* ── Diff viewer search ──────────────────────────────────── */
var diffSearch  = document.getElementById('cm-diff-search');
var diffBody    = document.querySelector('.cm-diff-body');
var diffCounter = document.getElementById('cm-diff-counter');

if (diffSearch && diffBody) {
	diffSearch.addEventListener('input', function () {
		var q     = diffSearch.value.toLowerCase().trim();
		var rows  = diffBody.querySelectorAll('.cm-diff-row');
		var count = 0;
		rows.forEach(function (row) {
			var text = (row.querySelector('.cm-diff-text') || row).textContent.toLowerCase();
			if (q && text.includes(q)) {
				row.classList.add('cm-diff-highlight');
				count++;
			} else {
				row.classList.remove('cm-diff-highlight');
			}
		});
		if (diffCounter) {
			diffCounter.textContent = q ? count + ' match' + (count !== 1 ? 'es' : '') : '';
		}
		if (count > 0) {
			var first = diffBody.querySelector('.cm-diff-row.cm-diff-highlight');
			if (first) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
		}
	});
}

/* ── Device table search ─────────────────────────────────── */
var devSearch = document.getElementById('cm-device-search');
if (devSearch) {
	devSearch.addEventListener('input', function () {
		var q = devSearch.value.toLowerCase();
		document.querySelectorAll('#cm-device-table tbody tr').forEach(function (tr) {
			tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
		});
	});
}

/* ── Backup table search ─────────────────────────────────── */
var backupSearch = document.getElementById('cm-backup-search');
if (backupSearch) {
	backupSearch.addEventListener('input', function () {
		var q = backupSearch.value.toLowerCase();
		document.querySelectorAll('#cm-backup-table tbody tr').forEach(function (tr) {
			tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
		});
	});
}

/* ── Syntax highlighting for config viewer ───────────────── */
function highlightConfig() {
	document.querySelectorAll('.cm-config-text').forEach(function (el) {
		var text = el.textContent;
		// Comments
		if (/^\s*[!#]/.test(text)) {
			el.classList.add('cm-kw-comment');
			return;
		}
		// "no " commands
		if (/^\s*no\s/.test(text)) {
			el.innerHTML = text.replace(/^(\s*)(no)(\s)/, '$1<span class="cm-kw-no">$2</span>$3');
			return;
		}
		// IP addresses
		text = text.replace(/\b(\d{1,3}\.){3}\d{1,3}\b/g, '<span class="cm-kw-ip">$&</span>');
		// Quoted strings
		text = text.replace(/"([^"]*)"/g, '<span class="cm-kw-str">"$1"</span>');
		el.innerHTML = text;
	});
}
highlightConfig();

/* ── Auto-dismiss alerts ─────────────────────────────────── */
document.querySelectorAll('.cm-alert.success').forEach(function (el) {
	setTimeout(function () {
		el.style.transition = 'opacity .5s';
		el.style.opacity = '0';
		setTimeout(function () { el.remove(); }, 500);
	}, 4000);
});

})();
