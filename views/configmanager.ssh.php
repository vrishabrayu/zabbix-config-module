<?php
declare(strict_types = 1);

$task         = $data['task'] ?? 'list';
$devices      = $data['devices'] ?? [];
$selected     = $data['selected'] ?? null;
$sessions     = $data['sessions'] ?? [];
$wsPort       = (int)($data['ws_port'] ?? 7681);
$sid          = htmlspecialchars($data['sid'] ?? '', ENT_QUOTES, 'UTF-8');
$currentUser  = $data['current_user'] ?? 'admin';
$v13          = $data['v13_ready'] ?? false;

$h   = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$url = static fn(array $p = []): string =>
	'zabbix.php?' . http_build_query(array_merge(['action' => 'configmanager.ssh'], $p));

$vendorLabel = [
	'cisco_ios'  => 'Cisco IOS',  'cisco_nxos' => 'Cisco NX-OS',
	'fortinet'   => 'Fortinet',   'mikrotik'   => 'MikroTik',
	'juniper'    => 'Juniper',
];
$vendorIcon = [
	'cisco_ios'  => '🔵', 'cisco_nxos' => '🟢',
	'fortinet'   => '🔴', 'mikrotik'   => '🟣',
	'juniper'    => '🟡',
];
?>
<div class="ssh-pro">
<style>
/* ============================================================
   SSH Terminal – Enterprise UI
   ============================================================ */
.ssh-pro {
	--t-bg:       #0d1117;
	--t-surface:  #161b22;
	--t-border:   #30363d;
	--t-text:     #e6edf3;
	--t-text-2:   #8b949e;
	--t-green:    #3fb950;
	--t-blue:     #58a6ff;
	--t-red:      #f85149;
	--t-yellow:   #e3b341;
	--t-purple:   #bc8cff;
	--t-orange:   #ffa657;
	--t-radius:   8px;
	--t-mono:     'JetBrains Mono','Fira Code','Cascadia Code',
	              'Consolas','Courier New',monospace;

	/* Light theme for the device-list panel */
	--p-bg:       #f6f8fa;
	--p-surface:  #ffffff;
	--p-border:   #d0d7de;
	--p-text:     #1f2328;
	--p-text-2:   #656d76;
	--p-blue:     #0969da;
	--p-blue-bg:  #ddf4ff;
	--p-green:    #1a7f37;
	--p-green-bg: #d1f0c5;
	--p-shadow:   0 1px 3px rgba(0,0,0,.08);

	background: var(--p-bg);
	color: var(--p-text);
	display: flex;
	flex-direction: column;
	font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
	font-size: 13px;
	min-height: 100vh;
}

/* ── Header ─────────────────────────────────────────────── */
.ssh-header {
	align-items: center;
	background: var(--t-surface);
	border-bottom: 1px solid var(--t-border);
	display: flex;
	gap: 14px;
	justify-content: space-between;
	padding: 0 20px;
	height: 52px;
}

.ssh-brand {
	align-items: center;
	display: flex;
	gap: 10px;
}

.ssh-brand-icon {
	align-items: center;
	background: linear-gradient(135deg, #238636, #2ea043);
	border-radius: 6px;
	color: #fff;
	display: flex;
	font-size: 16px;
	height: 32px;
	justify-content: center;
	width: 32px;
}

.ssh-brand-text h1 {
	color: var(--t-text);
	font-size: 15px;
	font-weight: 700;
	margin: 0;
}

.ssh-brand-text p {
	color: var(--t-text-2);
	font-size: 11px;
	margin: 0;
}

.ssh-header-right {
	align-items: center;
	display: flex;
	gap: 10px;
}

.ssh-ws-status {
	align-items: center;
	background: rgba(255,255,255,.05);
	border: 1px solid var(--t-border);
	border-radius: 6px;
	color: var(--t-text-2);
	display: flex;
	font-size: 11px;
	gap: 6px;
	padding: 4px 10px;
}

.ssh-ws-dot {
	background: var(--t-text-2);
	border-radius: 50%;
	display: inline-block;
	height: 7px;
	transition: background .3s;
	width: 7px;
}

.ssh-ws-dot.ok     { background: var(--t-green); box-shadow: 0 0 6px var(--t-green); }
.ssh-ws-dot.error  { background: var(--t-red); }
.ssh-ws-dot.connecting { background: var(--t-yellow); animation: ssh-pulse 1s infinite; }

@keyframes ssh-pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

/* ── Layout ─────────────────────────────────────────────── */
.ssh-layout {
	display: flex;
	flex: 1;
	min-height: 0;
}

/* ── Device sidebar ─────────────────────────────────────── */
.ssh-sidebar {
	background: var(--p-surface);
	border-right: 1px solid var(--p-border);
	display: flex;
	flex-direction: column;
	min-width: 280px;
	width: 280px;
	overflow: hidden;
}

.ssh-sidebar-header {
	background: var(--p-bg);
	border-bottom: 1px solid var(--p-border);
	padding: 12px 14px;
}

.ssh-sidebar-header h2 {
	color: var(--p-text);
	font-size: 12px;
	font-weight: 700;
	letter-spacing: .4px;
	margin: 0 0 8px;
	text-transform: uppercase;
}

.ssh-search {
	background: var(--p-surface);
	border: 1px solid var(--p-border);
	border-radius: 6px;
	color: var(--p-text);
	font-size: 12px;
	height: 30px;
	padding: 0 10px;
	transition: border-color .15s;
	width: 100%;
}

.ssh-search:focus { border-color: var(--p-blue); outline: none; }

.ssh-device-list {
	flex: 1;
	overflow-y: auto;
	padding: 6px 0;
}

.ssh-device-item {
	align-items: center;
	border-left: 3px solid transparent;
	cursor: pointer;
	display: flex;
	gap: 10px;
	padding: 9px 14px;
	transition: background .1s, border-color .1s;
}

.ssh-device-item:hover { background: rgba(9,105,218,.06); }
.ssh-device-item.active {
	background: var(--p-blue-bg);
	border-left-color: var(--p-blue);
}

.ssh-device-icon {
	align-items: center;
	background: var(--p-bg);
	border: 1px solid var(--p-border);
	border-radius: 6px;
	display: flex;
	flex-shrink: 0;
	font-size: 16px;
	height: 32px;
	justify-content: center;
	width: 32px;
}

.ssh-device-item.active .ssh-device-icon {
	background: var(--p-blue-bg);
	border-color: rgba(9,105,218,.3);
}

.ssh-device-info { flex: 1; min-width: 0; }

.ssh-device-name {
	color: var(--p-text);
	font-size: 13px;
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ssh-device-meta {
	color: var(--p-text-2);
	font-family: var(--t-mono);
	font-size: 10px;
	margin-top: 1px;
}

.ssh-device-status {
	align-items: center;
	display: flex;
	flex-direction: column;
	gap: 3px;
}

.ssh-status-dot {
	background: #d1d9e0;
	border-radius: 50%;
	height: 7px;
	width: 7px;
}

.ssh-status-dot.online  { background: var(--p-green); }
.ssh-status-dot.session { background: var(--p-blue); box-shadow: 0 0 6px var(--p-blue); }

.ssh-connect-btn {
	align-items: center;
	background: var(--p-blue);
	border: 0;
	border-radius: 5px;
	color: #fff;
	cursor: pointer;
	display: flex;
	font-size: 11px;
	font-weight: 700;
	gap: 4px;
	height: 26px;
	padding: 0 10px;
	transition: filter .15s;
	white-space: nowrap;
}

.ssh-connect-btn:hover { filter: brightness(1.1); }

.ssh-connect-btn.connecting {
	background: var(--t-yellow);
	color: #000;
}

.ssh-sidebar-footer {
	background: var(--p-bg);
	border-top: 1px solid var(--p-border);
	padding: 10px 14px;
}

.ssh-sidebar-footer p {
	color: var(--p-text-2);
	font-size: 11px;
	margin: 0;
}

/* ── Main area ──────────────────────────────────────────── */
.ssh-main {
	display: flex;
	flex: 1;
	flex-direction: column;
	min-width: 0;
	overflow: hidden;
}

/* ── Welcome screen ─────────────────────────────────────── */
.ssh-welcome {
	align-items: center;
	display: flex;
	flex: 1;
	flex-direction: column;
	justify-content: center;
	gap: 16px;
	padding: 40px;
	text-align: center;
}

.ssh-welcome-icon { font-size: 64px; line-height: 1; opacity: .6; }
.ssh-welcome-title { color: var(--p-text); font-size: 22px; font-weight: 700; }
.ssh-welcome-sub   { color: var(--p-text-2); font-size: 14px; max-width: 400px; line-height: 1.6; }

.ssh-feature-grid {
	display: grid;
	gap: 12px;
	grid-template-columns: repeat(3, 1fr);
	margin-top: 8px;
	max-width: 600px;
}

.ssh-feature {
	background: var(--p-surface);
	border: 1px solid var(--p-border);
	border-radius: 8px;
	padding: 14px;
	text-align: left;
}

.ssh-feature-icon { font-size: 20px; margin-bottom: 6px; }
.ssh-feature-title { color: var(--p-text); font-size: 12px; font-weight: 700; }
.ssh-feature-desc  { color: var(--p-text-2); font-size: 11px; margin-top: 3px; line-height: 1.5; }

/* ── Terminal area ──────────────────────────────────────── */
.ssh-terminal-wrap {
	background: var(--t-bg);
	display: flex;
	flex: 1;
	flex-direction: column;
	min-height: 0;
	position: relative;
}

.ssh-terminal-topbar {
	align-items: center;
	background: var(--t-surface);
	border-bottom: 1px solid var(--t-border);
	display: flex;
	flex-shrink: 0;
	gap: 12px;
	padding: 0 16px;
	height: 40px;
}

.ssh-tab {
	align-items: center;
	background: var(--t-bg);
	border: 1px solid var(--t-border);
	border-bottom: 0;
	border-radius: 6px 6px 0 0;
	color: var(--t-text);
	display: inline-flex;
	font-size: 12px;
	font-weight: 600;
	gap: 8px;
	padding: 6px 14px;
	position: relative;
}

.ssh-tab-dot {
	background: var(--t-green);
	border-radius: 50%;
	display: inline-block;
	height: 7px;
	width: 7px;
}

.ssh-tab-close {
	background: none;
	border: 0;
	color: var(--t-text-2);
	cursor: pointer;
	font-size: 14px;
	line-height: 1;
	margin-left: 4px;
	padding: 0;
}

.ssh-tab-close:hover { color: var(--t-red); }

.ssh-terminal-actions {
	display: flex;
	gap: 6px;
	margin-left: auto;
}

.ssh-action-btn {
	align-items: center;
	background: rgba(255,255,255,.05);
	border: 1px solid var(--t-border);
	border-radius: 5px;
	color: var(--t-text-2);
	cursor: pointer;
	display: inline-flex;
	font-size: 11px;
	font-weight: 600;
	gap: 4px;
	height: 26px;
	padding: 0 10px;
	transition: all .15s;
}

.ssh-action-btn:hover {
	background: rgba(255,255,255,.1);
	color: var(--t-text);
}

.ssh-terminal-container {
	flex: 1;
	overflow: hidden;
	padding: 6px;
	position: relative;
}

#ssh-terminal {
	height: 100%;
	width: 100%;
}

/* Xterm.js overrides */
.xterm { padding: 8px 12px; }
.xterm-viewport { background: var(--t-bg) !important; }

/* ── Connecting overlay ──────────────────────────────────── */
.ssh-overlay {
	align-items: center;
	background: rgba(13,17,23,.9);
	bottom: 0;
	display: flex;
	flex-direction: column;
	gap: 14px;
	justify-content: center;
	left: 0;
	position: absolute;
	right: 0;
	top: 0;
	z-index: 100;
}

.ssh-overlay.hidden { display: none; }

.ssh-spinner {
	animation: ssh-spin 1s linear infinite;
	border: 3px solid rgba(255,255,255,.1);
	border-top-color: var(--t-green);
	border-radius: 50%;
	height: 36px;
	width: 36px;
}

@keyframes ssh-spin { to { transform: rotate(360deg); } }

.ssh-overlay-text {
	color: var(--t-text);
	font-family: var(--t-mono);
	font-size: 13px;
}

/* ── Session history table ──────────────────────────────── */
.ssh-history {
	background: var(--p-surface);
	border: 1px solid var(--p-border);
	border-radius: 8px;
	overflow: hidden;
}

.ssh-history-header {
	background: var(--p-bg);
	border-bottom: 1px solid var(--p-border);
	font-size: 12px;
	font-weight: 700;
	letter-spacing: .4px;
	padding: 10px 14px;
	text-transform: uppercase;
}

.ssh-history table {
	border-collapse: collapse;
	width: 100%;
}

.ssh-history thead th {
	background: var(--p-bg);
	border-bottom: 1px solid var(--p-border);
	color: var(--p-text-2);
	font-size: 11px;
	font-weight: 700;
	letter-spacing: .4px;
	padding: 8px 14px;
	text-align: left;
	text-transform: uppercase;
}

.ssh-history tbody tr {
	border-bottom: 1px solid var(--p-border);
	transition: background .1s;
}

.ssh-history tbody tr:hover { background: var(--p-bg); }
.ssh-history tbody tr:last-child { border-bottom: 0; }
.ssh-history tbody td { color: var(--p-text); font-size: 12px; padding: 9px 14px; }
.ssh-history .mono { font-family: var(--t-mono); color: var(--p-text-2); }

/* ── Setup notice ───────────────────────────────────────── */
.ssh-setup {
	background: #fff8f0;
	border: 1px solid #f59e0b;
	border-left: 4px solid #f59e0b;
	border-radius: 8px;
	max-width: 720px;
	padding: 20px 24px;
}

.ssh-setup h2 { color: #b45309; font-size: 16px; margin: 0 0 10px; }
.ssh-setup p  { color: #374151; line-height: 1.7; margin: 0 0 8px; }
.ssh-setup pre {
	background: #1e293b;
	border-radius: 6px;
	color: #86efac;
	font-family: var(--t-mono);
	font-size: 12px;
	overflow-x: auto;
	padding: 12px 16px;
}
</style>

<!-- ── Header ───────────────────────────────────────────────── -->
<div class="ssh-header">
	<div class="ssh-brand">
		<div class="ssh-brand-icon">⚡</div>
		<div class="ssh-brand-text">
			<h1>SSH Terminal</h1>
			<p>Web-based SSH · Zabbix Integrated</p>
		</div>
	</div>
	<div class="ssh-header-right">
		<div class="ssh-ws-status" id="ssh-ws-status">
			<span class="ssh-ws-dot" id="ssh-ws-dot"></span>
			<span id="ssh-ws-label">WebSocket: checking…</span>
		</div>
		<a href="<?= $url() ?>" style="color:var(--t-text-2);font-size:12px;text-decoration:none">
			🖥 <?= count($devices) ?> device<?= count($devices) !== 1 ? 's' : '' ?>
		</a>
	</div>
</div>

<?php if (!$v13): ?>
<!-- Setup notice when tables missing -->
<div style="padding:20px">
<div class="ssh-setup">
	<h2>⚠ SSH Terminal Setup Required</h2>
	<p>Run the v1.3 migration to create SSH tables:</p>
	<pre>mysql -u &lt;user&gt; -p &lt;db&gt; &lt; /usr/share/zabbix/modules/ConfigManager/sql/migrate_v1_3.sql</pre>
	<p>Then install the WebSocket server dependencies:</p>
	<pre>pip3 install websockets paramiko cryptography mysql-connector-python</pre>
	<p>Start the WebSocket bridge:</p>
	<pre>python3 /usr/share/zabbix/modules/ConfigManager/scripts/ssh_ws_server.py \
  --db-host mysql-server --db-name zabbix \
  --db-user zabbix --db-pass iqlab@2025 \
  --port 7681</pre>
	<p>Then reload this page.</p>
</div>
</div>
<?php else: ?>

<!-- ── Main layout ──────────────────────────────────────────── -->
<div class="ssh-layout">

	<!-- Device sidebar -->
	<div class="ssh-sidebar">
		<div class="ssh-sidebar-header">
			<h2>🖥 Devices</h2>
			<input class="ssh-search" id="ssh-device-search" placeholder="🔍 Filter devices…" type="text">
		</div>
		<div class="ssh-device-list" id="ssh-device-list">
			<?php foreach ($devices as $d):
				$devId  = (int)$d['device_id'];
				$isSel  = $selected && (int)$selected['device_id'] === $devId;
				$icon   = $vendorIcon[$d['vendor']] ?? '🔵';
				$ls     = (string)($d['last_status'] ?? '');
			?>
			<div class="ssh-device-item <?= $isSel ? 'active' : '' ?>"
			     data-device-id="<?= $devId ?>"
			     data-device-name="<?= $h($d['name']) ?>"
			     data-device-ip="<?= $h($d['ip_address']) ?>"
			     data-vendor="<?= $h($vendorLabel[$d['vendor']] ?? $d['vendor']) ?>"
			     onclick="sshSelectDevice(<?= $devId ?>, <?= $h(json_encode([
			         'name'       => $d['name'],
			         'ip_address' => $d['ip_address'],
			         'vendor'     => $vendorLabel[$d['vendor']] ?? $d['vendor'],
			         'port'       => $d['port'],
			     ])) ?>)">
				<div class="ssh-device-icon"><?= $icon ?></div>
				<div class="ssh-device-info">
					<div class="ssh-device-name"><?= $h($d['name']) ?></div>
					<div class="ssh-device-meta"><?= $h($d['ip_address']) ?>:<?= (int)$d['port'] ?></div>
				</div>
				<div class="ssh-device-status">
					<span class="ssh-status-dot <?= $ls === 'success' ? 'online' : '' ?>"></span>
				</div>
			</div>
			<?php endforeach ?>
			<?php if (!$devices): ?>
			<div style="color:var(--p-text-2);font-size:12px;padding:20px;text-align:center">
				No devices configured.<br>
				<a href="<?= htmlspecialchars('zabbix.php?action=configmanager.view&tab=devices', ENT_QUOTES) ?>" style="color:var(--p-blue)">Add devices →</a>
			</div>
			<?php endif ?>
		</div>
		<div class="ssh-sidebar-footer">
			<p>Click a device then Connect to open terminal</p>
		</div>
	</div>

	<!-- Main terminal area -->
	<div class="ssh-main">

		<?php if (!$selected): ?>
		<!-- Welcome screen -->
		<div class="ssh-welcome">
			<div class="ssh-welcome-icon">⚡</div>
			<div class="ssh-welcome-title">SSH Terminal</div>
			<div class="ssh-welcome-sub">Select a device from the sidebar to open an interactive SSH session directly inside Zabbix.</div>

			<div class="ssh-feature-grid">
				<div class="ssh-feature">
					<div class="ssh-feature-icon">🔐</div>
					<div class="ssh-feature-title">Secure Sessions</div>
					<div class="ssh-feature-desc">Token-authenticated WebSocket. Credentials never exposed to the browser.</div>
				</div>
				<div class="ssh-feature">
					<div class="ssh-feature-icon">📟</div>
					<div class="ssh-feature-title">Full Terminal</div>
					<div class="ssh-feature-desc">xterm.js with 256-color support, copy/paste, and resize.</div>
				</div>
				<div class="ssh-feature">
					<div class="ssh-feature-icon">📋</div>
					<div class="ssh-feature-title">Session Audit</div>
					<div class="ssh-feature-desc">All sessions logged with user, device, start/end time.</div>
				</div>
			</div>

			<?php if ($sessions): ?>
			<div style="width:100%;max-width:700px">
				<div class="ssh-history">
					<div class="ssh-history-header">🕐 Recent SSH Sessions</div>
					<table>
						<thead><tr><th>Device</th><th>User</th><th>Started</th><th>Duration</th></tr></thead>
						<tbody>
						<?php foreach (array_slice($sessions, 0, 6) as $s): ?>
						<tr>
							<td><strong><?= $h($s['device_name']) ?></strong><br><span class="mono"><?= $h($s['ip_address']) ?></span></td>
							<td><?= $h($s['zabbix_user']) ?></td>
							<td class="mono"><?= $h(substr((string)$s['started_at'], 0, 16)) ?></td>
							<td><?php
								$dur = (int)$s['duration_sec'];
								if ($dur >= 3600) {
									echo floor($dur/3600).'h '.floor(($dur%3600)/60).'m';
								} elseif ($dur >= 60) {
									echo floor($dur/60).'m '.($dur%60).'s';
								} elseif ($dur > 0) {
									echo $dur.'s';
								} else {
									echo $s['ended_at'] ? '—' : '<span style="color:var(--p-blue)">Active</span>';
								}
							?></td>
						</tr>
						<?php endforeach ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif ?>
		</div>

		<?php else: $dev = $selected; ?>
		<!-- Terminal for selected device -->
		<div class="ssh-terminal-wrap" id="ssh-terminal-wrap">

			<!-- Tab bar -->
			<div class="ssh-terminal-topbar">
				<div class="ssh-tab">
					<span class="ssh-tab-dot" id="ssh-tab-dot"></span>
					<span id="ssh-tab-label"><?= $h($dev['name']) ?> — <?= $h($dev['ip_address']) ?></span>
					<button class="ssh-tab-close" onclick="sshDisconnect()" title="Disconnect">✕</button>
				</div>
				<div class="ssh-terminal-actions">
					<button class="ssh-action-btn" onclick="sshClearTerminal()" title="Clear screen">🗑 Clear</button>
					<button class="ssh-action-btn" onclick="sshSendCommand('show version')" title="Show version">ℹ Version</button>
					<button class="ssh-action-btn" onclick="sshSendCommand('show ip interface brief')" title="Interface brief">🌐 Interfaces</button>
					<button class="ssh-action-btn" onclick="sshToggleFullscreen()" title="Fullscreen">⛶ Full</button>
					<a href="<?= $url() ?>"
					   class="ssh-action-btn" style="text-decoration:none">← Back</a>
				</div>
			</div>

			<!-- Connecting overlay -->
			<div class="ssh-overlay" id="ssh-overlay">
				<div class="ssh-spinner"></div>
				<div class="ssh-overlay-text" id="ssh-overlay-text">
					Connecting to <?= $h($dev['name']) ?> (<?= $h($dev['ip_address']) ?>)…
				</div>
			</div>

			<!-- xterm.js container -->
			<div class="ssh-terminal-container">
				<div id="ssh-terminal"></div>
			</div>
		</div>
		<?php endif ?>

	</div><!-- /.ssh-main -->
</div><!-- /.ssh-layout -->

<?php endif // v13 ?>
</div><!-- /.ssh-pro -->

<!-- Load xterm.js from CDN -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.min.css">
<script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-web-links@0.9.0/lib/xterm-addon-web-links.min.js"></script>

<script>
(function () {
'use strict';

/* ── Config ─────────────────────────────────────────────── */
var WS_PORT     = <?= (int)$wsPort ?>;
var DEVICE_ID   = <?= $selected ? (int)$selected['device_id'] : 'null' ?>;
var DEVICE_NAME = <?= $selected ? json_encode($selected['name']) : 'null' ?>;
var DEVICE_IP   = <?= $selected ? json_encode($selected['ip_address']) : 'null' ?>;
var TOKEN_URL   = 'zabbix.php?action=configmanager.ssh&task=token&device_id=';
var WS_URL      = 'ws://' + window.location.hostname + ':' + WS_PORT;

/* ── Device sidebar search ──────────────────────────────── */
var searchInput = document.getElementById('ssh-device-search');
if (searchInput) {
	searchInput.addEventListener('input', function () {
		var q = this.value.toLowerCase();
		document.querySelectorAll('.ssh-device-item').forEach(function (el) {
			var text = (el.getAttribute('data-device-name') + ' ' +
			            el.getAttribute('data-device-ip')).toLowerCase();
			el.style.display = text.includes(q) ? '' : 'none';
		});
	});
}

/* ── WS connectivity check ──────────────────────────────── */
function checkWS() {
	var dot   = document.getElementById('ssh-ws-dot');
	var label = document.getElementById('ssh-ws-label');
	if (!dot || !label) return;

	dot.className = 'ssh-ws-dot connecting';
	label.textContent = 'WebSocket: connecting…';

	var ws = new WebSocket(WS_URL);
	ws.onopen = function () {
		dot.className = 'ssh-ws-dot ok';
		label.textContent = 'WebSocket: ready (port ' + WS_PORT + ')';
		ws.close();
	};
	ws.onerror = function () {
		dot.className = 'ssh-ws-dot error';
		label.textContent = 'WebSocket: offline — start ssh_ws_server.py';
	};
}
checkWS();
setInterval(checkWS, 30000);

/* ── Device selection (sidebar) ─────────────────────────── */
window.sshSelectDevice = function(deviceId, info) {
	// Navigate to terminal page for this device
	window.location.href = 'zabbix.php?action=configmanager.ssh&task=open&device_id=' + deviceId;
};

/* ══════════════════════════════════════════════════════════
   TERMINAL — only runs when a device is selected
   ══════════════════════════════════════════════════════════ */
if (!DEVICE_ID) return;

var term, fitAddon, ws;
var connected = false;

/* ── Init xterm.js ──────────────────────────────────────── */
function initTerminal() {
	term = new Terminal({
		cursorBlink:      true,
		cursorStyle:      'block',
		fontFamily:       "'JetBrains Mono','Fira Code','Cascadia Code','Consolas',monospace",
		fontSize:         13,
		lineHeight:       1.4,
		theme: {
			background:    '#0d1117',
			foreground:    '#e6edf3',
			cursor:        '#58a6ff',
			cursorAccent:  '#0d1117',
			black:         '#484f58',
			red:           '#ff7b72',
			green:         '#3fb950',
			yellow:        '#d29922',
			blue:          '#58a6ff',
			magenta:       '#bc8cff',
			cyan:          '#39c5cf',
			white:         '#b1bac4',
			brightBlack:   '#6e7681',
			brightRed:     '#ffa198',
			brightGreen:   '#56d364',
			brightYellow:  '#e3b341',
			brightBlue:    '#79c0ff',
			brightMagenta: '#d2a8ff',
			brightCyan:    '#56d4dd',
			brightWhite:   '#f0f6fc',
		},
		scrollback:       5000,
		allowTransparency:false,
		convertEol:       true,
	});

	fitAddon = new FitAddon.FitAddon();
	term.loadAddon(fitAddon);

	var linkAddon = new WebLinksAddon.WebLinksAddon();
	term.loadAddon(linkAddon);

	term.open(document.getElementById('ssh-terminal'));
	fitAddon.fit();

	// Resize observer
	var ro = new ResizeObserver(function () {
		try { fitAddon.fit(); } catch (e) {}
		if (ws && ws.readyState === WebSocket.OPEN && connected) {
			ws.send(JSON.stringify({
				type: 'resize',
				cols: term.cols,
				rows: term.rows
			}));
		}
	});
	ro.observe(document.getElementById('ssh-terminal-wrap'));

	// User input → WebSocket
	term.onData(function (data) {
		if (ws && ws.readyState === WebSocket.OPEN && connected) {
			ws.send(JSON.stringify({ type: 'input', data: data }));
		}
	});

	// Show welcome banner
	term.writeln('\x1b[36m╔══════════════════════════════════════════════════════╗\x1b[0m');
	term.writeln('\x1b[36m║         Config Manager SSH Terminal v1.3             ║\x1b[0m');
	term.writeln('\x1b[36m╚══════════════════════════════════════════════════════╝\x1b[0m');
	term.writeln('');
	term.writeln('\x1b[33m  Device : \x1b[0m' + DEVICE_NAME + ' (' + DEVICE_IP + ')');
	term.writeln('\x1b[33m  Bridge : \x1b[0m' + WS_URL);
	term.writeln('');
}

/* ── Get one-time token from PHP ────────────────────────── */
function getToken(deviceId, callback) {
	fetch(TOKEN_URL + deviceId + '&sid=<?= $sid ?>')
		.then(function (r) { return r.json(); })
		.then(function (data) {
			if (data.error) {
				term.writeln('\x1b[31m[ERROR] ' + data.error + '\x1b[0m');
			} else {
				callback(data.token);
			}
		})
		.catch(function (e) {
			term.writeln('\x1b[31m[ERROR] Could not get auth token: ' + e + '\x1b[0m');
			hideOverlay();
		});
}

/* ── Connect WebSocket ──────────────────────────────────── */
function connectSSH(token) {
	setOverlay('Authenticating…');

	ws = new WebSocket(WS_URL);

	ws.onopen = function () {
		setOverlay('Establishing SSH connection…');
		ws.send(JSON.stringify({
			type:  'connect',
			token: token,
			cols:  term.cols,
			rows:  term.rows
		}));
		updateTabStatus('connecting');
	};

	ws.onmessage = function (evt) {
		var msg;
		try { msg = JSON.parse(evt.data); }
		catch (e) { return; }

		if (msg.type === 'output') {
			term.write(msg.data);
		} else if (msg.type === 'connected') {
			connected = true;
			hideOverlay();
			updateTabStatus('connected');
		} else if (msg.type === 'error') {
			term.writeln('\r\n\x1b[31m[ERROR] ' + msg.message + '\x1b[0m');
			hideOverlay();
			updateTabStatus('error');
		} else if (msg.type === 'closed') {
			connected = false;
			term.writeln('\r\n\x1b[33m[Session closed]\x1b[0m');
			updateTabStatus('closed');
			hideOverlay();
		}
	};

	ws.onclose = function () {
		if (connected) {
			connected = false;
			term.writeln('\r\n\x1b[33m[WebSocket disconnected]\x1b[0m');
		}
		updateTabStatus('closed');
		hideOverlay();
	};

	ws.onerror = function () {
		term.writeln('\r\n\x1b[31m[WebSocket error — is ssh_ws_server.py running on port ' + WS_PORT + '?]\x1b[0m');
		hideOverlay();
		updateTabStatus('error');
	};
}

/* ── UI helpers ─────────────────────────────────────────── */
function setOverlay(text) {
	var ov = document.getElementById('ssh-overlay');
	var tx = document.getElementById('ssh-overlay-text');
	if (ov) ov.classList.remove('hidden');
	if (tx) tx.textContent = text;
}

function hideOverlay() {
	var ov = document.getElementById('ssh-overlay');
	if (ov) ov.classList.add('hidden');
}

function updateTabStatus(state) {
	var dot   = document.getElementById('ssh-tab-dot');
	var label = document.getElementById('ssh-tab-label');
	if (!dot) return;
	var colors = {
		connecting: '#e3b341',
		connected:  '#3fb950',
		error:      '#f85149',
		closed:     '#8b949e'
	};
	dot.style.background = colors[state] || '#8b949e';
	if (label && state === 'connected') {
		label.textContent = DEVICE_NAME + ' — ' + DEVICE_IP + ' ✓';
	}
}

/* ── Public actions ─────────────────────────────────────── */
window.sshDisconnect = function () {
	if (ws) ws.close();
	window.location.href = '<?= $url() ?>';
};

window.sshClearTerminal = function () {
	if (term) term.clear();
};

window.sshSendCommand = function (cmd) {
	if (ws && ws.readyState === WebSocket.OPEN && connected) {
		ws.send(JSON.stringify({ type: 'input', data: cmd + '\r' }));
		term.focus();
	}
};

window.sshToggleFullscreen = function () {
	var el = document.getElementById('ssh-terminal-wrap');
	if (!el) return;
	if (!document.fullscreenElement) {
		el.requestFullscreen && el.requestFullscreen();
	} else {
		document.exitFullscreen && document.exitFullscreen();
	}
	setTimeout(function () {
		try { fitAddon && fitAddon.fit(); } catch (e) {}
	}, 300);
};

/* ── Boot sequence ──────────────────────────────────────── */
initTerminal();
setOverlay('Requesting auth token…');

getToken(DEVICE_ID, function (token) {
	setOverlay('Connecting to ' + DEVICE_NAME + ' (' + DEVICE_IP + ')…');
	connectSSH(token);
});

})();
</script>
