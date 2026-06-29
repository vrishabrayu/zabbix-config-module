<?php
declare(strict_types = 1);

$tab          = $data['tab'];
$is_admin     = $data['is_admin'];
$sid          = htmlspecialchars($data['sid'], ENT_QUOTES, 'UTF-8');
$v12          = $data['v12_ready'] ?? false;
$currentUser  = $data['current_user'] ?? 'admin';

$h   = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$url = static fn(array $p = []): string =>
	'zabbix.php?' . http_build_query(array_merge(['action' => 'configmanager.view'], $p));

$vendorLabel = [
	'cisco_ios'  => 'Cisco IOS',  'cisco_nxos' => 'Cisco NX-OS',
	'fortinet'   => 'Fortinet',   'mikrotik'   => 'MikroTik',
	'juniper'    => 'Juniper',
];
$vendorClass = [
	'cisco_ios'  => 'cisco-ios',  'cisco_nxos' => 'cisco-nxos',
	'fortinet'   => 'fortinet',   'mikrotik'   => 'mikrotik',
	'juniper'    => 'juniper',
];
$scheduleOptions = $data['schedule_options'] ?? [];
$scheduleIcon = [
	'disabled'  => '—', 'hourly' => '⏱ 1h', 'every_6h' => '⏱ 6h',
	'every_12h' => '⏱ 12h', 'daily' => '📅 Daily', 'weekly' => '📅 Weekly',
];
$tabs = [
	'dashboard' => ['icon' => '📊', 'label' => 'Dashboard'],
	'devices'   => ['icon' => '🖥️',  'label' => 'Devices'],
	'history'   => ['icon' => '🗂️',  'label' => 'Backup History'],
	'push'      => ['icon' => '🚀',  'label' => 'Push Config'],
	'templates' => ['icon' => '📋',  'label' => 'Templates'],
	'pushlog'   => ['icon' => '📜',  'label' => 'Push Log'],
];
$stats      = $data['stats'];
$pushStats  = $data['push_stats'] ?? [];
?>
<div class="cm-pro">
<style><?= file_get_contents(dirname(__DIR__) . '/assets/css/configmanager.css') ?></style>

<!-- ── Tab Nav ──────────────────────────────────────────────── -->
<nav class="cm-nav">
	<?php foreach ($tabs as $key => $t): ?>
		<a href="<?= $url(['tab' => $key]) ?>"
		   class="cm-nav-item <?= $tab === $key ? 'active' : '' ?>">
			<span class="cm-nav-icon"><?= $t['icon'] ?></span>
			<?= $h($t['label']) ?>
			<?php if ($key === 'devices' && !empty($data['devices'])): ?>
				<span class="cm-nav-badge"><?= count($data['devices']) ?></span>
			<?php endif ?>
			<?php if (($key === 'push' || $key === 'templates') && !$v12): ?>
				<span style="background:#fbbf24;border-radius:3px;color:#92400e;font-size:9px;font-weight:700;padding:1px 5px;margin-left:2px">SETUP</span>
			<?php endif ?>
		</a>
	<?php endforeach ?>
</nav>

<div class="cm-content">

<?php foreach ($data['messages'] as $msg): ?>
	<div class="cm-alert <?= $h($msg['type']) ?>">
		<?= $msg['type'] === 'error' ? '⚠️' : '✅' ?> <?= $h($msg['text']) ?>
	</div>
<?php endforeach ?>

<?php if (!empty($data['setup_required'])): ?>
	<div class="cm-setup">
		<h2>⚠ Setup Required</h2>
		<p>Run the SQL schema:</p>
		<pre>mysql -u &lt;user&gt; -p &lt;db&gt; &lt; /usr/share/zabbix/modules/ConfigManager/sql/schema.sql</pre>
		<p>Then install Python requirements and create backup dir:</p>
		<pre>pip3 install netmiko
mkdir -p /opt/config-backups && chown www-data:www-data /opt/config-backups</pre>
	</div>

<?php elseif (!$v12 && in_array($tab, ['push', 'templates', 'pushlog'])): ?>
	<div class="cm-setup">
		<h2>⚠ v1.2 Migration Required</h2>
		<p>Push Config and Templates require the v1.2 database tables. Run:</p>
		<pre>mysql -u &lt;user&gt; -p &lt;db&gt; &lt; /usr/share/zabbix/modules/ConfigManager/sql/migrate_v1_2.sql</pre>
		<p>Then reload this page.</p>
	</div>

<?php else: ?>

<!-- ════════════════════════════════════════════
     DASHBOARD
     ════════════════════════════════════════════ -->
<?php if ($tab === 'dashboard'): ?>

	<div class="cm-kpi-grid">
		<?php $kpis = [
			['blue',   '🖥️',  'Total Devices',     $stats['total_devices']       ?? 0],
			['green',  '✅',  'Backups Today',      $stats['success_today']       ?? 0],
			['red',    '❌',  'Failed Today',       $stats['failed_today']        ?? 0],
			['orange', '🔄',  'Config Changes',     $stats['changes_today']       ?? 0],
			['purple', '🚀',  'Pushes Today',       ($pushStats['success_today']  ?? 0) + ($pushStats['failed_today'] ?? 0)],
		];
		foreach ($kpis as $k): ?>
			<div class="cm-kpi <?= $k[0] ?>">
				<div class="cm-kpi-icon"><?= $k[1] ?></div>
				<div class="cm-kpi-value" data-count="<?= $k[3] ?>"><?= $h((string)$k[3]) ?></div>
				<div class="cm-kpi-label"><?= $h($k[2]) ?></div>
			</div>
		<?php endforeach ?>
	</div>

	<div class="cm-dash-grid">
		<div class="cm-card">
			<div class="cm-card-header">
				<h3 class="cm-card-title">📋 Recent Activity</h3>
				<span style="color:var(--cm-text-3);font-size:12px">Last 15 backups</span>
			</div>
			<?php if ($data['activity']): ?>
				<div class="cm-timeline">
					<?php foreach ($data['activity'] as $ev):
						$dc = $ev['status'] === 'failed' ? 'failed' : ($ev['changed'] ? 'changed' : ($ev['status'] === 'success' ? 'success' : 'unchanged'));
						$icon = $ev['status'] === 'failed' ? '✕' : ($ev['changed'] ? '△' : '✓');
						$desc = $ev['status'] === 'failed' ? 'Backup failed' : ($ev['changed'] ? 'Config changed' : 'No changes');
					?>
					<div class="cm-timeline-item">
						<div class="cm-timeline-dot <?= $dc ?>"><?= $icon ?></div>
						<div><div class="cm-timeline-device"><?= $h($ev['device']) ?></div><div class="cm-timeline-meta"><?= $desc ?></div></div>
						<div class="cm-timeline-time"><?= $h(substr((string)$ev['ts'], 0, 16)) ?></div>
					</div>
					<?php endforeach ?>
				</div>
			<?php else: ?>
				<div class="cm-empty"><div class="cm-empty-icon">📋</div><div class="cm-empty-title">No activity yet</div></div>
			<?php endif ?>
		</div>

		<div style="display:flex;flex-direction:column;gap:16px">
			<div class="cm-card">
				<div class="cm-card-header"><h3 class="cm-card-title">🔄 Change Summary</h3></div>
				<div class="cm-card-body">
					<?php
					$ch = (int)($stats['devices_changed']   ?? 0);
					$un = (int)($stats['devices_unchanged'] ?? 0);
					$tot = max(1, $ch + $un);
					foreach ([['Changed', $ch, 'var(--cm-orange)'], ['Unchanged', $un, 'var(--cm-green)']] as $row): ?>
						<div style="margin-bottom:12px">
							<div style="display:flex;justify-content:space-between;margin-bottom:5px">
								<span style="color:<?= $row[2] ?>;font-size:13px;font-weight:600"><?= $row[0] ?></span>
								<span style="font-weight:700"><?= $row[1] ?></span>
							</div>
							<div style="background:var(--cm-border);border-radius:999px;height:7px;overflow:hidden">
								<div style="background:<?= $row[2] ?>;border-radius:999px;height:100%;width:<?= round($row[1]/$tot*100) ?>%"></div>
							</div>
						</div>
					<?php endforeach ?>
				</div>
			</div>

			<?php if ($v12): ?>
			<div class="cm-card">
				<div class="cm-card-header">
					<h3 class="cm-card-title">🚀 Push Summary</h3>
					<a href="<?= $url(['tab' => 'pushlog']) ?>" class="cm-btn cm-btn-secondary cm-btn-sm">View Log</a>
				</div>
				<div class="cm-card-body">
					<?php foreach ([
						['Successful', $pushStats['success_today']   ?? 0, 'var(--cm-green)'],
						['Failed',     $pushStats['failed_today']    ?? 0, 'var(--cm-red)'],
						['Templates',  $pushStats['templates_applied']?? 0, 'var(--cm-blue)'],
					] as $ps): ?>
						<div style="align-items:center;display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--cm-border)">
							<span style="color:var(--cm-text-2);font-size:13px"><?= $ps[0] ?></span>
							<span style="color:<?= $ps[2] ?>;font-weight:700;font-size:15px"><?= $ps[1] ?></span>
						</div>
					<?php endforeach ?>
				</div>
			</div>
			<?php endif ?>

			<div class="cm-card">
				<div class="cm-card-header">
					<h3 class="cm-card-title">⏱️ Auto-Schedule</h3>
					<a href="<?= $url(['tab' => 'devices']) ?>" class="cm-btn cm-btn-secondary cm-btn-sm">Configure</a>
				</div>
				<div class="cm-card-body" style="padding:0">
					<?php $scheduled = array_filter($data['devices'], fn($d) => $d['schedule_interval'] !== 'disabled');
					foreach (array_slice(array_values($scheduled), 0, 4) as $d): ?>
						<div style="align-items:center;border-bottom:1px solid var(--cm-border);display:flex;gap:10px;justify-content:space-between;padding:9px 16px">
							<div><div style="color:var(--cm-text);font-size:13px;font-weight:600"><?= $h($d['name']) ?></div><div style="color:var(--cm-text-3);font-size:11px">Next: <?= $h(substr($d['next_run_at'] ?? '—', 0, 16)) ?></div></div>
							<span class="cm-badge planned no-dot"><?= $h($scheduleIcon[$d['schedule_interval']] ?? '') ?></span>
						</div>
					<?php endforeach ?>
					<?php if (!$scheduled): ?><div style="padding:14px 16px;color:var(--cm-text-3);font-size:12px;text-align:center">No schedules configured.</div><?php endif ?>
				</div>
			</div>
		</div>
	</div>
<?php endif ?>
<!-- ════════════════════════════════════════════
     DEVICES
     ════════════════════════════════════════════ -->


<?php if ($tab === 'devices'): ?>

	<?php if ($is_admin): ?>
	<div class="cm-card cm-form-panel">
		<div class="cm-card-header"><h3 class="cm-card-title">➕ Add Device</h3></div>
		<form class="cm-form-row" method="post">
			<input type="hidden" name="sid"    value="<?= $sid ?>">
			<input type="hidden" name="action" value="configmanager.view">
			<input type="hidden" name="tab"    value="devices">
			<input type="hidden" name="task"   value="save_device">
			<?php foreach (['Device Name *' => 'name', 'IP Address *' => 'ip_address', 'Username *' => 'username'] as $lbl => $nm): ?>
			<div class="cm-form-field"><label><?= $lbl ?></label><input name="<?= $nm ?>" placeholder="<?= $nm === 'name' ? 'Router-01' : ($nm === 'ip_address' ? '192.168.1.1' : 'admin') ?>" required></div>
			<?php endforeach ?>
			<div class="cm-form-field"><label>Password *</label><input name="password" type="password" placeholder="••••••" required></div>
			<div class="cm-form-field"><label>Vendor *</label><select name="vendor"><?php foreach ($vendorLabel as $v => $vl): ?><option value="<?= $h($v) ?>"><?= $h($vl) ?></option><?php endforeach ?></select></div>
			<div class="cm-form-field"><label>Port</label><input name="port" type="number" value="22" style="min-width:70px"></div>
			<div class="cm-form-field"><label>Method</label><select name="backup_method"><option value="ssh">SSH</option><option value="telnet">Telnet</option></select></div>
			<div class="cm-form-field"><label>Auto-Backup</label><select name="schedule_interval"><?php foreach ($scheduleOptions as $v => $l): ?><option value="<?= $h($v) ?>"><?= $h($l) ?></option><?php endforeach ?></select></div>
			<div class="cm-form-field" style="justify-content:flex-end"><button type="submit" class="cm-btn cm-btn-primary">Add Device</button></div>
		</form>
	</div>
	<?php endif ?>

	<div class="cm-toolbar">
		<input id="cm-device-search" class="cm-toolbar-input" placeholder="🔍 Filter devices…" style="min-width:260px">
		<div class="cm-toolbar-right"><span style="color:var(--cm-text-3);font-size:12px"><?= count($data['devices']) ?> device<?= count($data['devices']) !== 1 ? 's' : '' ?></span></div>
	</div>

	<div class="cm-card" style="overflow:visible">
		<table class="cm-table" id="cm-device-table" style="overflow:visible">
			<thead><tr>
				<th>Device Name</th><th>IP Address</th><th>Vendor</th>
				<th>Auto-Backup</th><th>Next Run</th><th>Last Backup</th>
				<th>Status</th><th style="text-align:center">30d</th><th>Actions</th>
			</tr></thead>
			<tbody>
			<?php if ($data['devices']): ?>
				<?php foreach ($data['devices'] as $d):
					$devId = (int)$d['device_id'];
					$sched = $d['schedule_interval'] ?? 'disabled';
				?>
				<tr>
					<td><span style="color:var(--cm-text);font-weight:600"><?= $h($d['name']) ?></span><?php if (!$d['enabled']): ?><span class="cm-badge disabled no-dot" style="margin-left:6px">disabled</span><?php endif ?></td>
					<td style="font-family:var(--cm-mono);font-size:12px"><?= $h($d['ip_address']) ?>:<?= (int)$d['port'] ?></td>
					<td><span class="cm-badge <?= $h($vendorClass[$d['vendor']] ?? '') ?> no-dot"><?= $h($vendorLabel[$d['vendor']] ?? $d['vendor']) ?></span></td>
					<td><?php if ($sched !== 'disabled'): ?><span class="cm-badge planned no-dot"><?= $h($scheduleOptions[$sched] ?? $sched) ?></span><?php else: ?><span style="color:var(--cm-text-3);font-size:12px">Manual</span><?php endif ?></td>
					<td style="font-size:11px;color:var(--cm-text-3)"><?= ($sched !== 'disabled' && $d['next_run_at']) ? $h(substr($d['next_run_at'], 0, 16)) : '—' ?></td>
					<td style="font-size:12px;color:var(--cm-text-3)"><?= $d['last_backup'] ? $h(substr((string)$d['last_backup'], 0, 16)) : '—' ?></td>
					<td><?php $ls = (string)($d['last_status'] ?? ''); ?><span class="cm-badge <?= $ls === 'success' ? 'success' : ($ls === 'failed' ? 'failed' : 'unchanged') ?>"><?= $ls ?: 'never' ?></span></td>
					<td style="text-align:center;font-weight:700"><?= (int)$d['backup_count'] ?></td>
					<td>
						<div class="cm-row-actions">
							<a href="<?= $url(['tab' => 'history', 'device_id' => $devId]) ?>" class="cm-btn cm-btn-secondary cm-btn-sm">History</a>
							<?php if ($is_admin): ?>
								<form method="post" style="margin:0"><input type="hidden" name="sid" value="<?= $sid ?>"><input type="hidden" name="action" value="configmanager.view"><input type="hidden" name="tab" value="devices"><input type="hidden" name="task" value="backup_now"><input type="hidden" name="device_id" value="<?= $devId ?>"><button type="submit" class="cm-btn cm-btn-ghost cm-btn-sm">📡 Backup</button></form>
								<?php if ($v12): ?>
								<a href="<?= $url(['tab' => 'push', 'device_id' => $devId]) ?>" class="cm-btn cm-btn-sm" style="background:var(--cm-purple-light);border:1px solid rgba(124,58,237,.2);color:var(--cm-purple)">🚀 Push</a>
								<?php endif ?>
								<button class="cm-btn cm-btn-secondary cm-btn-sm" onclick="cmOpenEditDialog(<?= $devId ?>, <?= $h(json_encode(['name'=>$d['name'],'ip_address'=>$d['ip_address'],'vendor'=>$d['vendor'],'username'=>$d['username'],'port'=>$d['port'],'backup_method'=>$d['backup_method'],'enabled'=>$d['enabled'],'schedule_interval'=>$sched])) ?>)">✏️ Edit</button>
								<form method="post" data-cm-confirm="Delete device <?= $h($d['name']) ?>?" style="margin:0"><input type="hidden" name="sid" value="<?= $sid ?>"><input type="hidden" name="action" value="configmanager.view"><input type="hidden" name="tab" value="devices"><input type="hidden" name="task" value="delete_device"><input type="hidden" name="device_id" value="<?= $devId ?>"><button type="submit" class="cm-btn cm-btn-danger cm-btn-sm">Delete</button></form>
							<?php endif ?>
						</div>
					</td>
				</tr>
				<?php endforeach ?>
			<?php else: ?>
				<tr><td colspan="9"><div class="cm-empty"><div class="cm-empty-icon">🖥️</div><div class="cm-empty-title">No devices added yet</div></div></td></tr>
			<?php endif ?>
			</tbody>
		</table>
	</div>

	<!-- Edit Device Modal -->
	<dialog id="cm-edit-dialog" class="cm-dialog">
		<div class="cm-dialog-header"><h3>✏️ Edit Device</h3><form method="dialog"><button class="cm-dialog-close">✕</button></form></div>
		<div class="cm-dialog-body">
			<form method="post" id="cm-edit-form">
				<input type="hidden" name="sid" value="<?= $sid ?>"><input type="hidden" name="action" value="configmanager.view"><input type="hidden" name="tab" value="devices"><input type="hidden" name="task" value="save_device"><input type="hidden" name="device_id" id="edit-device-id">
				<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
					<?php foreach ([['Device Name *','name','text',''],['IP Address *','ip_address','text',''],['Username *','username','text',''],['Password','password','password','blank = keep']] as $ef): ?>
					<div class="cm-field-row" style="display:flex;flex-direction:column;gap:4px"><label style="color:var(--cm-text-3);font-size:10px;font-weight:700;text-transform:uppercase"><?= $ef[0] ?><?= $ef[3] ? ' <span style="font-weight:400;text-transform:none">('.$ef[3].')</span>' : '' ?></label><input name="<?= $ef[1] ?>" id="edit-<?= $ef[1] ?>" type="<?= $ef[2] ?>" style="background:var(--cm-surface-2);border:1px solid var(--cm-border);border-radius:var(--cm-radius-sm);color:var(--cm-text);font-family:inherit;font-size:13px;height:34px;padding:0 10px;width:100%"></div>
					<?php endforeach ?>
					<div class="cm-field-row" style="display:flex;flex-direction:column;gap:4px"><label style="color:var(--cm-text-3);font-size:10px;font-weight:700;text-transform:uppercase">Vendor</label><select name="vendor" id="edit-vendor" style="background:var(--cm-surface-2);border:1px solid var(--cm-border);border-radius:var(--cm-radius-sm);color:var(--cm-text);font-family:inherit;font-size:13px;height:34px;padding:0 10px;width:100%"><?php foreach ($vendorLabel as $v => $vl): ?><option value="<?= $h($v) ?>"><?= $h($vl) ?></option><?php endforeach ?></select></div>
					<div class="cm-field-row" style="display:flex;flex-direction:column;gap:4px"><label style="color:var(--cm-text-3);font-size:10px;font-weight:700;text-transform:uppercase">Port</label><input name="port" id="edit-port" type="number" style="background:var(--cm-surface-2);border:1px solid var(--cm-border);border-radius:var(--cm-radius-sm);color:var(--cm-text);font-family:inherit;font-size:13px;height:34px;padding:0 10px;width:100%"></div>
					<div class="cm-field-row" style="display:flex;flex-direction:column;gap:4px"><label style="color:var(--cm-text-3);font-size:10px;font-weight:700;text-transform:uppercase">Method</label><select name="backup_method" id="edit-method" style="background:var(--cm-surface-2);border:1px solid var(--cm-border);border-radius:var(--cm-radius-sm);color:var(--cm-text);font-family:inherit;font-size:13px;height:34px;padding:0 10px;width:100%"><option value="ssh">SSH</option><option value="telnet">Telnet</option></select></div>
					<div class="cm-field-row" style="display:flex;flex-direction:column;gap:4px"><label style="color:var(--cm-text-3);font-size:10px;font-weight:700;text-transform:uppercase">Status</label><select name="enabled" id="edit-enabled" style="background:var(--cm-surface-2);border:1px solid var(--cm-border);border-radius:var(--cm-radius-sm);color:var(--cm-text);font-family:inherit;font-size:13px;height:34px;padding:0 10px;width:100%"><option value="1">Enabled</option><option value="0">Disabled</option></select></div>
				</div>
				<div style="background:var(--cm-blue-light);border:1px solid rgba(37,99,235,.2);border-radius:var(--cm-radius-sm);margin-top:14px;padding:12px 14px">
					<div style="color:var(--cm-blue);font-size:13px;font-weight:700;margin-bottom:8px">⏱️ Auto-Backup Schedule</div>
					<select name="schedule_interval" id="edit-schedule" style="background:var(--cm-surface);border:1px solid rgba(37,99,235,.3);border-radius:var(--cm-radius-sm);color:var(--cm-text);font-family:inherit;font-size:13px;height:34px;padding:0 10px;width:100%"><?php foreach ($scheduleOptions as $v => $l): ?><option value="<?= $h($v) ?>"><?= $h($l) ?></option><?php endforeach ?></select>
				</div>
				<div style="border-top:1px solid var(--cm-border);display:flex;gap:10px;justify-content:flex-end;margin-top:16px;padding-top:14px">
					<form method="dialog" style="margin:0"><button class="cm-btn cm-btn-secondary">Cancel</button></form>
					<button type="submit" class="cm-btn cm-btn-primary">💾 Save Changes</button>
				</div>
			</form>
		</div>
	</dialog>
<?php endif ?>
<!-- ════════════════════════════════════════════
     BACKUP HISTORY
     ════════════════════════════════════════════ -->


<?php if ($tab === 'history'): ?>
	<?php if ($data['devices']): ?>
	<div style="align-items:center;background:var(--cm-surface);border:1px solid var(--cm-border);border-radius:var(--cm-radius-sm);box-shadow:var(--cm-shadow-sm);display:flex;flex-wrap:wrap;gap:8px;padding:10px 14px">
		<span style="color:var(--cm-text-3);font-size:11px;font-weight:700;text-transform:uppercase">Device</span>
		<?php foreach ($data['devices'] as $d): $sel = (int)($data['selected_device']['device_id'] ?? 0) === (int)$d['device_id']; ?>
			<a href="<?= $url(['tab' => 'history', 'device_id' => (int)$d['device_id']]) ?>"
			   style="background:<?= $sel ? 'var(--cm-blue)' : 'var(--cm-surface-2)' ?>;border:1px solid <?= $sel ? 'var(--cm-blue)' : 'var(--cm-border)' ?>;border-radius:var(--cm-radius-sm);color:<?= $sel ? '#fff' : 'var(--cm-text-2)' ?>;font-size:12px;font-weight:600;padding:4px 12px;text-decoration:none;transition:all .15s">
				<?= $h($d['name']) ?>
			</a>
		<?php endforeach ?>
	</div>
	<?php endif ?>

	<?php if ($data['view_config']): ?>
		<?php $vc = $data['view_config']; $bk = $vc['backup']; ?>
		<div class="cm-card"><div class="cm-card-header"><h3 class="cm-card-title">📄 <?= $h($bk['filename']) ?></h3><a href="<?= $url(['tab' => 'history', 'device_id' => (int)$bk['device_id']]) ?>" class="cm-btn cm-btn-secondary cm-btn-sm">← Back</a></div></div>
		<div class="cm-config-wrap">
			<div class="cm-config-toolbar">
				<input id="cm-config-search" class="cm-config-search" placeholder="🔍 Search…">
				<span style="color:#64748b;font-size:12px"><?= $h($bk['filename']) ?> · <?= number_format((int)$bk['file_size']) ?> B</span>
				<span id="cm-config-counter" class="cm-config-counter"></span>
			</div>
			<pre class="cm-config-pre"><?php
			foreach (explode("\n", $vc['content']) as $i => $line):
			?><div class="cm-config-line"><span class="cm-config-ln"><?= $i+1 ?></span><span class="cm-config-text"><?= $h($line) ?></span></div><?php
			endforeach; ?></pre>
		</div>

	<?php elseif ($data['diff_data'] !== null): ?>
		<?php $old=$data['diff_backup_old'];$new=$data['diff_backup_new'];$diff=$data['diff_data'];
		$added=count(array_filter($diff,fn($l)=>$l['type']==='add'));
		$removed=count(array_filter($diff,fn($l)=>$l['type']==='remove')); ?>
		<div class="cm-card"><div class="cm-card-header"><h3 class="cm-card-title">🔀 Config Diff</h3><a href="<?= $url(['tab'=>'history','device_id'=>(int)$old['device_id']]) ?>" class="cm-btn cm-btn-secondary cm-btn-sm">← Back</a></div></div>
		<div class="cm-diff-wrap">
			<div class="cm-diff-header">
				<div class="cm-diff-header-side"><span class="cm-diff-header-label">OLD</span><span class="cm-diff-header-file"><?= $h($old['filename']) ?></span></div>
				<div class="cm-diff-header-side"><span class="cm-diff-header-label">NEW</span><span class="cm-diff-header-file"><?= $h($new['filename']) ?></span></div>
			</div>
			<div class="cm-diff-toolbar">
				<input id="cm-diff-search" class="cm-diff-search" placeholder="🔍 Search in diff…">
				<div class="cm-diff-stats"><span class="add">+<?= $added ?></span><span class="remove">-<?= $removed ?></span><span id="cm-diff-counter" style="color:#64748b;font-size:12px"></span></div>
			</div>
			<div class="cm-diff-body">
				<div class="cm-diff-side"><?php foreach($diff as $line): if($line['type']==='add')continue; ?>
					<div class="cm-diff-row <?= $h($line['type']) ?>"><span class="cm-diff-ln"><?= $line['old_line']!==null?(int)$line['old_line']:'' ?></span><span class="cm-diff-sign"><?= $line['type']==='remove'?'-':' ' ?></span><span class="cm-diff-text"><?= $h($line['text']) ?></span></div>
				<?php endforeach ?></div>
				<div class="cm-diff-side"><?php foreach($diff as $line): if($line['type']==='remove')continue; ?>
					<div class="cm-diff-row <?= $h($line['type']) ?>"><span class="cm-diff-ln"><?= $line['new_line']!==null?(int)$line['new_line']:'' ?></span><span class="cm-diff-sign"><?= $line['type']==='add'?'+':' ' ?></span><span class="cm-diff-text"><?= $h($line['text']) ?></span></div>
				<?php endforeach ?></div>
			</div>
		</div>

	<?php else: ?>
		<?php if ($data['selected_device']): $dev=$data['selected_device']; ?>
		<div class="cm-card">
			<div class="cm-card-header">
				<h3 class="cm-card-title">🗂️ <?= $h($dev['name']) ?> <span class="cm-badge <?= $h($vendorClass[$dev['vendor']]??'') ?> no-dot" style="margin-left:4px"><?= $h($vendorLabel[$dev['vendor']]??$dev['vendor']) ?></span></h3>
				<div style="display:flex;align-items:center;gap:10px">
					<span style="color:var(--cm-text-3);font-size:12px;font-family:var(--cm-mono)"><?= $h($dev['ip_address']) ?></span>
					<?php if ($is_admin): ?>
						<?php if ($v12): ?><a href="<?= $url(['tab'=>'push','device_id'=>(int)$dev['device_id']]) ?>" class="cm-btn cm-btn-sm" style="background:var(--cm-purple-light);border:1px solid rgba(124,58,237,.2);color:var(--cm-purple)">🚀 Push Config</a><?php endif ?>
						<form method="post" style="margin:0"><input type="hidden" name="sid" value="<?= $sid ?>"><input type="hidden" name="action" value="configmanager.view"><input type="hidden" name="tab" value="history"><input type="hidden" name="task" value="backup_now"><input type="hidden" name="device_id" value="<?= (int)$dev['device_id'] ?>"><button type="submit" class="cm-btn cm-btn-primary cm-btn-sm">📡 Backup Now</button></form>
					<?php endif ?>
				</div>
			</div>
		</div>
		<div class="cm-toolbar"><input id="cm-backup-search" class="cm-toolbar-input" placeholder="🔍 Filter backups…" style="min-width:240px"><div class="cm-toolbar-right"><span style="color:var(--cm-text-3);font-size:12px"><?= count($data['backups']) ?> backup<?= count($data['backups'])!==1?'s':'' ?></span></div></div>
		<div class="cm-table-wrap">
			<table class="cm-table" id="cm-backup-table">
				<thead><tr><th>Date / Time</th><th>Filename</th><th>Status</th><th>Change</th><th style="text-align:right">Size</th><th>SHA256</th><th>Actions</th></tr></thead>
				<tbody>
				<?php if ($data['backups']): ?>
					<?php $bl=$data['backups'];
					foreach($bl as $i=>$bk):
						$cs=isset($bk['changed'])?($bk['changed']?'changed':'unchanged'):'';
						$prev=$i+1<count($bl)?$bl[$i+1]:null;
					?>
					<tr>
						<td style="font-family:var(--cm-mono);font-size:12px"><?= $h(substr((string)$bk['backed_up_at'],0,16)) ?></td>
						<td style="font-family:var(--cm-mono);font-size:12px;font-weight:600"><?= $h($bk['filename']) ?></td>
						<td><span class="cm-badge <?= $h($bk['status']) ?>"><?= $h($bk['status']) ?></span></td>
						<td><?php if($cs): ?><span class="cm-badge <?= $h($cs) ?>"><?= $cs==='changed'?'+'.(int)($bk['lines_added']??0).' -'.(int)($bk['lines_removed']??0):'no changes' ?></span><?php else: ?><span style="color:var(--cm-text-3)">—</span><?php endif ?></td>
						<td style="text-align:right;color:var(--cm-text-3);font-size:12px"><?= number_format((int)$bk['file_size']) ?> B</td>
						<td style="font-family:var(--cm-mono);font-size:11px;color:var(--cm-text-3)"><?= $bk['sha256']?$h(substr($bk['sha256'],0,10)).'…':'—' ?></td>
						<td>
							<div class="cm-row-actions">
								<?php if($bk['status']==='success'): ?>
									<a href="<?= $url(['tab'=>'history','device_id'=>(int)$dev['device_id'],'task'=>'view_config','backup_id'=>(int)$bk['backup_id']]) ?>" class="cm-btn cm-btn-secondary cm-btn-sm">📄 View</a>
									<?php if($prev&&$prev['status']==='success'): ?><a href="<?= $url(['tab'=>'history','device_id'=>(int)$dev['device_id'],'task'=>'view_diff','backup_id'=>(int)$prev['backup_id'],'backup_id2'=>(int)$bk['backup_id']]) ?>" class="cm-btn cm-btn-ghost cm-btn-sm">🔀 Diff</a><?php endif ?>
									<?php if($is_admin&&$v12): ?>
										<form method="post" data-cm-confirm="Restore <?= $h($bk['filename']) ?> to <?= $h($dev['name']) ?>? Current config will be backed up first." style="margin:0">
											<input type="hidden" name="sid" value="<?= $sid ?>"><input type="hidden" name="action" value="configmanager.view"><input type="hidden" name="tab" value="history"><input type="hidden" name="task" value="restore_backup"><input type="hidden" name="backup_id" value="<?= (int)$bk['backup_id'] ?>"><input type="hidden" name="device_id" value="<?= (int)$dev['device_id'] ?>">
											<button type="submit" class="cm-btn cm-btn-sm" style="background:var(--cm-orange-light);border:1px solid rgba(234,88,12,.2);color:var(--cm-orange)">↩ Restore</button>
										</form>
									<?php endif ?>
								<?php else: ?><span style="color:var(--cm-text-3);font-size:11px" title="<?= $h($bk['error_message']??'') ?>">Failed ⓘ</span><?php endif ?>
							</div>
						</td>
					</tr>
					<?php endforeach ?>
				<?php else: ?><tr><td colspan="7"><div class="cm-empty"><div class="cm-empty-icon">📡</div><div class="cm-empty-title">No backups yet</div></div></td></tr><?php endif ?>
				</tbody>
			</table>
		</div>
		<?php else: ?><div class="cm-card"><div class="cm-empty"><div class="cm-empty-icon">🗂️</div><div class="cm-empty-title">Select a device above</div></div></div><?php endif ?>
	<?php endif ?>
<?php endif ?>
<!-- ════════════════════════════════════════════
     PUSH CONFIG
     ════════════════════════════════════════════ -->


<?php if ($tab === 'push' && $v12): ?>

	<!-- Push result output -->
	<?php if ($pr = $data['push_result']): ?>
	<div class="cm-card">
		<div class="cm-card-header">
			<h3 class="cm-card-title"><?= ($pr['success']??false) ? '✅' : '❌' ?> Push <?= !empty($pr['bulk']) ? 'Bulk' : '' ?> Result <?= !empty($pr['dry_run']) ? '(Dry Run)' : '' ?></h3>
			<?php if (!empty($pr['elapsed'])): ?><span style="color:var(--cm-text-3);font-size:12px">⏱ <?= round($pr['elapsed'],2) ?>s</span><?php endif ?>
		</div>
		<?php if (!empty($pr['bulk'])): ?>
			<div style="padding:0">
				<?php foreach ($pr['results'] as $r): ?>
				<div style="align-items:center;border-bottom:1px solid var(--cm-border);display:flex;gap:12px;padding:10px 16px">
					<span class="cm-badge <?= $r['success']?'success':'failed' ?>"><?= $r['success']?'success':'failed' ?></span>
					<span style="font-weight:600"><?= $h($r['name']) ?></span>
					<span style="color:var(--cm-text-3);font-size:11px;font-family:var(--cm-mono)"><?= $h($r['ip']??'') ?></span>
					<?php if(!$r['success']): ?><span style="color:var(--cm-red);font-size:12px"><?= $h($r['error']) ?></span><?php endif ?>
					<?php if($r['elapsed']??0): ?><span style="color:var(--cm-text-3);font-size:11px;margin-left:auto"><?= round($r['elapsed'],2) ?>s</span><?php endif ?>
				</div>
				<?php endforeach ?>
			</div>
		<?php elseif (!empty($pr['output'])): ?>
			<div class="cm-config-wrap" style="margin:0;border-radius:0">
				<pre class="cm-config-pre" style="max-height:280px"><?php
				foreach(explode("\n",$pr['output']) as $i=>$line):
				?><div class="cm-config-line"><span class="cm-config-ln"><?= $i+1 ?></span><span class="cm-config-text"><?= $h($line) ?></span></div><?php
				endforeach; ?></pre>
			</div>
		<?php endif ?>
	</div>
	<?php endif ?>

	<div style="display:grid;grid-template-columns:1fr 320px;gap:16px;align-items:start">

		<!-- Main push form -->
		<div class="cm-card">
			<div class="cm-card-header"><h3 class="cm-card-title">🚀 Push Configuration</h3></div>
			<div class="cm-card-body">
				<form method="post" enctype="multipart/form-data" id="cm-push-form">
					<input type="hidden" name="sid"    value="<?= $sid ?>">
					<input type="hidden" name="action" value="configmanager.view">
					<input type="hidden" name="tab"    value="push">
					<input type="hidden" name="task"   value="push_config">
					<input type="hidden" name="device_ids" id="push-device-ids" value="[]">

					<!-- Device selector -->
					<div style="margin-bottom:14px">
						<label style="color:var(--cm-text-2);font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;display:block;margin-bottom:6px">Target Device *</label>
						<select name="device_id" id="push-device-id" style="background:var(--cm-surface-2);border:1px solid var(--cm-border);border-radius:var(--cm-radius-sm);color:var(--cm-text);font-family:inherit;font-size:13px;height:36px;padding:0 10px;width:100%">
							<option value="0">— Select device —</option>
							<?php foreach($data['devices'] as $d): ?>
								<option value="<?= (int)$d['device_id'] ?>" <?= (int)($data['selected_device']['device_id']??0)===(int)$d['device_id']?'selected':'' ?>><?= $h($d['name']) ?> (<?= $h($d['ip_address']) ?>)</option>
							<?php endforeach ?>
						</select>
					</div>

					<!-- Template selector -->
					<?php if ($data['templates']): ?>
					<div style="margin-bottom:14px">
						<label style="color:var(--cm-text-2);font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;display:block;margin-bottom:6px">Load Template (optional)</label>
						<select id="push-template-sel" style="background:var(--cm-surface-2);border:1px solid var(--cm-border);border-radius:var(--cm-radius-sm);color:var(--cm-text);font-family:inherit;font-size:13px;height:36px;padding:0 10px;width:100%" onchange="cmLoadTemplate(this.value)">
							<option value="">— Manual input —</option>
							<?php foreach($data['templates'] as $t): ?>
								<option value="<?= $h($t['template_id']) ?>" data-content="<?= $h($t['template_content']) ?>"><?= $h('[' . $t['category'] . '] ' . $t['name']) ?></option>
							<?php endforeach ?>
						</select>
						<input type="hidden" name="template_id" id="push-template-id" value="0">
					</div>
					<?php endif ?>

					<!-- Commands textarea -->
					<div style="margin-bottom:14px">
						<div style="align-items:center;display:flex;justify-content:space-between;margin-bottom:6px">
							<label style="color:var(--cm-text-2);font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase">Commands</label>
							<span style="color:var(--cm-text-3);font-size:11px">One command per line · Lines starting with # are ignored</span>
						</div>
						<textarea name="commands" id="push-commands" rows="10"
						          placeholder="interface GigabitEthernet0/1&#10;description Uplink&#10;no shutdown&#10;exit&#10;&#10;ntp server 1.1.1.1"
						          style="background:var(--cm-surface-2);border:1px solid var(--cm-border);border-radius:var(--cm-radius-sm);color:var(--cm-text);font-family:var(--cm-mono);font-size:12px;line-height:1.6;padding:10px 12px;resize:vertical;width:100%"></textarea>
					</div>

					<!-- File upload -->
					<div style="background:var(--cm-surface-2);border:1px dashed var(--cm-border-2);border-radius:var(--cm-radius-sm);margin-bottom:14px;padding:12px 14px">
						<label style="color:var(--cm-text-2);font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;display:block;margin-bottom:6px">Or Upload Config File (.txt, .cfg — max 5 MB)</label>
						<input type="file" name="config_file" accept=".txt,.cfg,.conf" style="color:var(--cm-text);font-size:12px">
					</div>

					<!-- Options row -->
					<div style="align-items:center;display:flex;gap:20px;margin-bottom:16px;flex-wrap:wrap">
						<label style="align-items:center;cursor:pointer;display:flex;gap:8px;font-size:13px;font-weight:500">
							<input type="checkbox" name="dry_run" value="1" id="push-dry-run" style="accent-color:var(--cm-blue);width:16px;height:16px">
							<span>🔍 Dry Run (preview only — no changes sent)</span>
						</label>
						<label style="align-items:center;cursor:pointer;display:flex;gap:8px;font-size:13px;font-weight:500">
							<input type="checkbox" id="push-bulk-toggle" style="accent-color:var(--cm-purple);width:16px;height:16px">
							<span>📡 Bulk Push (select multiple devices)</span>
						</label>
					</div>

					<!-- Bulk device checkboxes (hidden until bulk toggle checked) -->
					<div id="push-bulk-devices" style="display:none;background:var(--cm-surface-2);border:1px solid var(--cm-border);border-radius:var(--cm-radius-sm);margin-bottom:14px;padding:12px 14px">
						<div style="color:var(--cm-text-2);font-size:12px;font-weight:700;margin-bottom:10px">Select target devices:</div>
						<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px">
							<?php foreach($data['devices'] as $d): ?>
							<label style="align-items:center;background:var(--cm-surface);border:1px solid var(--cm-border);border-radius:var(--cm-radius-sm);cursor:pointer;display:flex;gap:8px;padding:8px 12px;transition:border-color .15s" onmouseover="this.style.borderColor='var(--cm-blue)'" onmouseout="this.style.borderColor='var(--cm-border)'">
								<input type="checkbox" class="push-bulk-cb" value="<?= (int)$d['device_id'] ?>" style="accent-color:var(--cm-blue)">
								<div><div style="font-weight:600;font-size:13px"><?= $h($d['name']) ?></div><div style="color:var(--cm-text-3);font-size:11px;font-family:var(--cm-mono)"><?= $h($d['ip_address']) ?></div></div>
							</label>
							<?php endforeach ?>
						</div>
					</div>

					<div style="display:flex;gap:10px">
						<button type="submit" class="cm-btn cm-btn-primary" style="height:38px;font-size:13px;padding:0 24px">🚀 Push Configuration</button>
						<button type="button" class="cm-btn cm-btn-secondary" onclick="document.getElementById('push-commands').value='';document.getElementById('push-template-sel')&&(document.getElementById('push-template-sel').value='');document.getElementById('push-template-id').value='0'">Clear</button>
					</div>
				</form>
			</div>
		</div>

		<!-- Quick-apply sidebar -->
		<div style="display:flex;flex-direction:column;gap:16px">
			<div class="cm-card">
				<div class="cm-card-header"><h3 class="cm-card-title">📋 Quick Templates</h3><a href="<?= $url(['tab'=>'templates']) ?>" class="cm-btn cm-btn-secondary cm-btn-sm">Manage</a></div>
				<div style="padding:0">
					<?php if ($data['templates']): ?>
						<?php foreach (array_slice($data['templates'], 0, 8) as $t): ?>
						<div style="align-items:center;border-bottom:1px solid var(--cm-border);display:flex;gap:10px;justify-content:space-between;padding:9px 14px">
							<div><div style="font-size:13px;font-weight:600"><?= $h($t['name']) ?></div><div style="color:var(--cm-text-3);font-size:11px"><?= $h($t['category']) ?></div></div>
							<button class="cm-btn cm-btn-ghost cm-btn-sm" onclick="cmLoadTemplateById(<?= (int)$t['template_id'] ?>, <?= $h(json_encode($t['template_content'])) ?>)">Use</button>
						</div>
						<?php endforeach ?>
					<?php else: ?>
						<div style="padding:16px;color:var(--cm-text-3);font-size:12px;text-align:center">No templates yet.<br><a href="<?= $url(['tab'=>'templates']) ?>" style="color:var(--cm-blue)">Create one →</a></div>
					<?php endif ?>
				</div>
			</div>

			<div class="cm-card">
				<div class="cm-card-header"><h3 class="cm-card-title">ℹ️ Push Info</h3></div>
				<div class="cm-card-body" style="font-size:12px;color:var(--cm-text-2);line-height:1.7">
					<p style="margin:0 0 8px">• A backup is taken automatically <strong>before</strong> every push.</p>
					<p style="margin:0 0 8px">• <strong>Dry Run</strong> shows commands without sending them to the device.</p>
					<p style="margin:0 0 8px">• <strong>Bulk Push</strong> sends the same commands to multiple devices.</p>
					<p style="margin:0">• All pushes are logged in the Push Log tab.</p>
				</div>
			</div>
		</div>
	</div>
<?php endif ?>
<!-- ════════════════════════════════════════════
     TEMPLATES
     ════════════════════════════════════════════ -->


<?php if ($tab === 'templates' && $v12): ?>

	<?php if ($is_admin): ?>
	<div class="cm-card cm-form-panel">
		<div class="cm-card-header"><h3 class="cm-card-title">➕ <?= $data['selected_template'] ? 'Edit Template' : 'Add Template' ?></h3><?php if ($data['selected_template']): ?><a href="<?= $url(['tab'=>'templates']) ?>" class="cm-btn cm-btn-secondary cm-btn-sm">+ New</a><?php endif ?></div>
		<form method="post">
			<input type="hidden" name="sid"         value="<?= $sid ?>">
			<input type="hidden" name="action"      value="configmanager.view">
			<input type="hidden" name="tab"         value="templates">
			<input type="hidden" name="task"        value="save_template">
			<input type="hidden" name="template_id" value="<?= (int)($data['selected_template']['template_id'] ?? 0) ?>">
			<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;padding:16px 20px 0">
				<div class="cm-form-field"><label>Template Name *</label><input name="name" value="<?= $h($data['selected_template']['name']??'') ?>" placeholder="SNMP Config" required></div>
				<div class="cm-form-field">
					<label>Category</label>
					<select name="category">
						<?php foreach(['General','SNMP','NTP','Syslog','VLAN','Banner','AAA','Security','Routing','Interface'] as $cat): ?>
							<option value="<?= $h($cat) ?>" <?= ($data['selected_template']['category']??'General')===$cat?'selected':'' ?>><?= $h($cat) ?></option>
						<?php endforeach ?>
					</select>
				</div>
				<div class="cm-form-field wide"><label>Description</label><input name="description" value="<?= $h($data['selected_template']['description']??'') ?>" placeholder="Brief description"></div>
			</div>
			<div style="padding:12px 20px">
				<div class="cm-form-field" style="width:100%">
					<label>Template Content *</label>
					<textarea name="template_content" rows="8" required
					          placeholder="snmp-server community public RO&#10;snmp-server location Datacenter&#10;snmp-server contact noc@company.com"
					          style="background:var(--cm-surface);border:1px solid var(--cm-border);border-radius:var(--cm-radius-sm);color:var(--cm-text);font-family:var(--cm-mono);font-size:12px;line-height:1.6;padding:10px 12px;resize:vertical;width:100%"><?= $h($data['selected_template']['template_content']??'') ?></textarea>
				</div>
			</div>
			<div style="padding:0 20px 16px;display:flex;gap:10px">
				<button type="submit" class="cm-btn cm-btn-primary">💾 Save Template</button>
				<?php if ($data['selected_template']): ?><a href="<?= $url(['tab'=>'templates']) ?>" class="cm-btn cm-btn-secondary">Cancel</a><?php endif ?>
			</div>
		</form>
	</div>
	<?php endif ?>

	<!-- Template groups by category -->
	<?php
	$byCategory = [];
	foreach ($data['templates'] as $t) $byCategory[$t['category']][] = $t;
	ksort($byCategory);
	?>

	<?php if ($byCategory): ?>
		<?php foreach ($byCategory as $cat => $catTemplates): ?>
		<div class="cm-card">
			<div class="cm-card-header"><h3 class="cm-card-title">📁 <?= $h($cat) ?> <span style="color:var(--cm-text-3);font-size:12px;font-weight:400">(<?= count($catTemplates) ?>)</span></h3></div>
			<table class="cm-table">
				<thead><tr><th>Name</th><th>Description</th><th style="text-align:right">Lines</th><th>Created</th><th>Actions</th></tr></thead>
				<tbody>
				<?php foreach($catTemplates as $t): ?>
				<tr>
					<td style="font-weight:600"><?= $h($t['name']) ?></td>
					<td style="color:var(--cm-text-3);font-size:12px"><?= $h($t['description']??'') ?: '—' ?></td>
					<td style="text-align:right;color:var(--cm-text-3);font-size:12px"><?= substr_count($t['template_content'],"\n")+1 ?></td>
					<td style="color:var(--cm-text-3);font-size:12px"><?= $h(substr($t['created_at'],0,10)) ?></td>
					<td>
						<div class="cm-row-actions">
							<a href="<?= $url(['tab'=>'push']) ?>" class="cm-btn cm-btn-ghost cm-btn-sm" onclick="sessionStorage.setItem('cm_tpl_id','<?= (int)$t['template_id'] ?>');sessionStorage.setItem('cm_tpl_content',<?= json_encode($t['template_content']) ?>)">🚀 Push</a>
							<?php if ($is_admin): ?>
								<a href="<?= $url(['tab'=>'templates','template_id'=>(int)$t['template_id']]) ?>" class="cm-btn cm-btn-secondary cm-btn-sm">✏️ Edit</a>
								<form method="post" data-cm-confirm="Delete template <?= $h($t['name']) ?>?" style="margin:0"><input type="hidden" name="sid" value="<?= $sid ?>"><input type="hidden" name="action" value="configmanager.view"><input type="hidden" name="tab" value="templates"><input type="hidden" name="task" value="delete_template"><input type="hidden" name="template_id" value="<?= (int)$t['template_id'] ?>"><button type="submit" class="cm-btn cm-btn-danger cm-btn-sm">Delete</button></form>
							<?php endif ?>
						</div>
					</td>
				</tr>
				<?php endforeach ?>
				</tbody>
			</table>
		</div>
		<?php endforeach ?>
	<?php else: ?>
		<div class="cm-card"><div class="cm-empty"><div class="cm-empty-icon">📋</div><div class="cm-empty-title">No templates yet</div><div class="cm-empty-sub">Add a template above — SNMP, NTP, Syslog, VLAN, AAA etc.</div></div></div>
	<?php endif ?>
<?php endif ?>
<!-- ════════════════════════════════════════════
     PUSH LOG
     ════════════════════════════════════════════ -->


<?php if ($tab === 'pushlog' && $v12): ?>

	<div class="cm-toolbar">
		<input id="cm-pushlog-search" class="cm-toolbar-input" placeholder="🔍 Filter push log…" style="min-width:260px">
		<div class="cm-toolbar-right"><span style="color:var(--cm-text-3);font-size:12px"><?= count($data['push_history']) ?> entries</span></div>
	</div>

	<div class="cm-table-wrap">
		<table class="cm-table" id="cm-pushlog-table">
			<thead><tr><th>Date / Time</th><th>Device</th><th>User</th><th>Type</th><th>Template</th><th>Status</th><th style="text-align:right">Time</th><th>Actions</th></tr></thead>
			<tbody>
			<?php if ($data['push_history']): ?>
				<?php foreach ($data['push_history'] as $ph): ?>
				<tr>
					<td style="font-family:var(--cm-mono);font-size:12px"><?= $h(substr((string)$ph['pushed_at'],0,16)) ?></td>
					<td style="font-weight:600"><?= $h($ph['device_name']) ?><span style="color:var(--cm-text-3);font-size:11px;display:block;font-family:var(--cm-mono)"><?= $h($ph['ip_address']) ?></span></td>
					<td style="color:var(--cm-text-2)"><?= $h($ph['zabbix_user']) ?></td>
					<td>
						<?php $typeColor=['manual'=>'var(--cm-blue)','template'=>'var(--cm-purple)','file'=>'var(--cm-yellow)','restore'=>'var(--cm-orange)','bulk'=>'var(--cm-green)'];
						$typeIcon=['manual'=>'✏️','template'=>'📋','file'=>'📁','restore'=>'↩','bulk'=>'📡']; ?>
						<span style="align-items:center;background:var(--cm-surface-2);border:1px solid var(--cm-border);border-radius:var(--cm-radius-sm);color:<?= $typeColor[$ph['push_type']]??'var(--cm-text-2)' ?>;display:inline-flex;font-size:11px;font-weight:700;gap:4px;padding:3px 8px">
							<?= $typeIcon[$ph['push_type']] ?? '' ?> <?= $h(ucfirst($ph['push_type'])) ?>
						</span>
						<?php if ($ph['dry_run']): ?><span class="cm-badge unchanged no-dot" style="margin-left:4px">dry run</span><?php endif ?>
					</td>
					<td style="color:var(--cm-text-3);font-size:12px"><?= $ph['template_name'] ? $h($ph['template_name']) : '—' ?></td>
					<td><span class="cm-badge <?= $ph['status']==='success'?'success':($ph['status']==='dry_run'?'unchanged':'failed') ?>"><?= $h($ph['status']) ?></span></td>
					<td style="text-align:right;color:var(--cm-text-3);font-size:12px"><?= number_format((float)$ph['execution_time'],2) ?>s</td>
					<td>
						<?php if (!empty($ph['output'])): ?>
							<button class="cm-btn cm-btn-secondary cm-btn-sm" onclick="cmShowPushOutput(<?= $h(json_encode(substr($ph['output'],0,5000))) ?>, <?= $h(json_encode($ph['device_name'])) ?>)">📄 Output</button>
						<?php endif ?>
					</td>
				</tr>
				<?php endforeach ?>
			<?php else: ?><tr><td colspan="8"><div class="cm-empty"><div class="cm-empty-icon">📜</div><div class="cm-empty-title">No push history yet</div></div></td></tr><?php endif ?>
			</tbody>
		</table>
	</div>

	<!-- Push output modal -->
	<dialog id="cm-output-dialog" class="cm-dialog" style="max-width:700px">
		<div class="cm-dialog-header"><h3 id="cm-output-title">Push Output</h3><form method="dialog"><button class="cm-dialog-close">✕</button></form></div>
		<div class="cm-dialog-body" style="padding:0">
			<div class="cm-config-wrap" style="border-radius:0">
				<pre class="cm-config-pre" id="cm-output-pre" style="max-height:460px"></pre>
			</div>
		</div>
	</dialog>
<?php endif ?>

<?php endif // !setup_required ?>
</div><!-- /.cm-content -->

<script>
<?= file_get_contents(dirname(__DIR__) . '/assets/js/configmanager.js') ?>

/* ── Edit Device Dialog ───────────────────────────────────── */
var schedHints = {
	'disabled':'No automatic backups.','hourly':'Every hour.',
	'every_6h':'Every 6 hours.','every_12h':'Every 12 hours.',
	'daily':'Once per day.','weekly':'Once per week.'
};
function cmOpenEditDialog(deviceId, d) {
	var dlg = document.getElementById('cm-edit-dialog'); if (!dlg) return;
	document.getElementById('edit-device-id').value  = deviceId;
	document.getElementById('edit-name').value        = d.name       || '';
	document.getElementById('edit-ip_address').value  = d.ip_address  || '';
	document.getElementById('edit-username').value    = d.username    || '';
	document.getElementById('edit-password').value    = '';
	document.getElementById('edit-port').value        = d.port        || 22;
	['vendor','backup_method','enabled','schedule'].forEach(function(k) {
		var sel = document.getElementById('edit-' + k); if (!sel) return;
		var val = k === 'schedule' ? d.schedule_interval : d[k];
		for (var i = 0; i < sel.options.length; i++) sel.options[i].selected = sel.options[i].value == val;
	});
	if (typeof dlg.showModal === 'function') dlg.showModal();
}

/* ── Push Config helpers ─────────────────────────────────── */
function cmLoadTemplate(tplId) {
	var sel = document.getElementById('push-template-sel');
	document.getElementById('push-template-id').value = tplId || '0';
	if (!tplId || !sel) return;
	var opt = sel.options[sel.selectedIndex];
	if (opt && opt.getAttribute('data-content')) {
		document.getElementById('push-commands').value = opt.getAttribute('data-content');
	}
}
function cmLoadTemplateById(id, content) {
	document.getElementById('push-template-id').value = id;
	document.getElementById('push-commands').value = content;
	var sel = document.getElementById('push-template-sel');
	if (sel) { for (var i=0;i<sel.options.length;i++) sel.options[i].selected = sel.options[i].value == id; }
}

/* Bulk push toggle */
var bulkToggle = document.getElementById('push-bulk-toggle');
var bulkDevices = document.getElementById('push-bulk-devices');
var pushForm = document.getElementById('cm-push-form');
if (bulkToggle && bulkDevices && pushForm) {
	bulkToggle.addEventListener('change', function() {
		bulkDevices.style.display = this.checked ? '' : 'none';
		pushForm.querySelector('[name="task"]').value = this.checked ? 'bulk_push' : 'push_config';
	});
	document.querySelectorAll('.push-bulk-cb').forEach(function(cb) {
		cb.addEventListener('change', function() {
			var ids = Array.from(document.querySelectorAll('.push-bulk-cb:checked')).map(function(c){return parseInt(c.value);});
			document.getElementById('push-device-ids').value = JSON.stringify(ids);
		});
	});
}

/* Load template from session (from Templates page "Push" button) */
(function() {
	var tplId = sessionStorage.getItem('cm_tpl_id');
	var tplContent = sessionStorage.getItem('cm_tpl_content');
	if (tplId && tplContent && document.getElementById('push-commands')) {
		cmLoadTemplateById(tplId, tplContent);
		sessionStorage.removeItem('cm_tpl_id');
		sessionStorage.removeItem('cm_tpl_content');
	}
})();

/* ── Push Log output dialog ──────────────────────────────── */
function cmShowPushOutput(output, deviceName) {
	var dlg = document.getElementById('cm-output-dialog'); if (!dlg) return;
	document.getElementById('cm-output-title').textContent = '📄 Output — ' + deviceName;
	var pre = document.getElementById('cm-output-pre');
	pre.innerHTML = '';
	output.split('\n').forEach(function(line, i) {
		var div = document.createElement('div');
		div.className = 'cm-config-line';
		div.innerHTML = '<span class="cm-config-ln">' + (i+1) + '</span><span class="cm-config-text">' + line.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</span>';
		pre.appendChild(div);
	});
	if (typeof dlg.showModal === 'function') dlg.showModal();
}

/* ── Push Log search ─────────────────────────────────────── */
var plSearch = document.getElementById('cm-pushlog-search');
if (plSearch) {
	plSearch.addEventListener('input', function() {
		var q = this.value.toLowerCase();
		document.querySelectorAll('#cm-pushlog-table tbody tr').forEach(function(tr) {
			tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
		});
	});
}

/* cm-nav-badge style */
document.querySelectorAll('.cm-nav-badge').forEach(function(el) {
	el.style.cssText = 'background:var(--cm-blue);border-radius:999px;color:#fff;font-size:10px;font-weight:700;line-height:1;min-width:18px;padding:3px 6px;text-align:center';
});
</script>
</div><!-- /.cm-pro -->
