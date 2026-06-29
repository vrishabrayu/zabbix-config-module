<?php
declare(strict_types = 1);

use Modules\ConfigManager\Includes\ConfigManager\Repository;

require_once __DIR__ . '/../includes/ConfigManager/Repository.php';

class CControllerConfigManager extends CController {

	private Repository $repo;
	private array      $messages = [];

	protected function init(): void {
		$this->disableCsrfValidation();
		$this->repo = new Repository();
	}

	protected function checkInput(): bool {
		return $this->validateInput([
			'tab'               => 'string',
			'task'              => 'string',
			'device_id'         => 'int32',
			'backup_id'         => 'int32',
			'backup_id2'        => 'int32',
			'template_id'       => 'int32',
			'push_id'           => 'int32',
			'name'              => 'string',
			'ip_address'        => 'string',
			'vendor'            => 'string',
			'username'          => 'string',
			'password'          => 'string',
			'port'              => 'int32',
			'backup_method'     => 'string',
			'enabled'           => 'int32',
			'schedule_interval' => 'string',
			'category'          => 'string',
			'description'       => 'string',
			'template_content'  => 'string',
			'commands'          => 'string',
			'dry_run'           => 'int32',
			'device_ids'        => 'string',   // JSON array for bulk push
		]);
	}

	protected function checkPermissions(): bool { return true; }

	protected function doAction(): void {
		if (!$this->repo->tablesExist()) {
			$this->renderSetup(); return;
		}

		// Run scheduled backups on every load
		$this->runScheduledBackups();

		$tab  = (string)$this->getInput('tab',  'dashboard');
		$task = (string)$this->getInput('task', '');

		if ($task !== '') {
			try {
				$redirect = $this->handleTask($task, $tab);
				if ($redirect) { $this->redirect($tab); return; }
			} catch (\Throwable $e) {
				$this->messages[] = ['type' => 'error', 'text' => $e->getMessage()];
			}
		}

		$deviceId   = (int)$this->getInput('device_id',   0);
		$backupId   = (int)$this->getInput('backup_id',   0);
		$backupId2  = (int)$this->getInput('backup_id2',  0);
		$templateId = (int)$this->getInput('template_id', 0);

		$data = [
			'tab'              => $tab,
			'messages'         => $this->messages,
			'setup_required'   => false,
			'v12_ready'        => $this->repo->v12TablesExist(),
			'sid'              => CWebUser::$data['sessionid'] ?? '',
			'is_admin'         => $this->isAdmin(),
			'current_user'     => CWebUser::$data['alias']    ?? 'admin',
			'devices'          => $this->repo->getDevices(),
			'stats'            => $this->repo->getDashboardStats(),
			'activity'         => $this->repo->getRecentActivity(),
			'selected_device'  => $deviceId   > 0 ? $this->repo->getDevice($deviceId)     : null,
			'backups'          => $deviceId   > 0 ? $this->repo->getBackupsForDevice($deviceId) : [],
			'templates'        => $this->repo->getTemplates(),
			'template_cats'    => $this->repo->getTemplateCategories(),
			'selected_template'=> $templateId > 0 ? $this->repo->getTemplate($templateId) : null,
			'push_history'     => $this->repo->getPushHistory(0, 50),
			'push_stats'       => $this->repo->getPushStats(),
			'schedule_options' => Repository::SCHEDULE_OPTIONS,
			'view_config'      => null,
			'diff_data'        => null,
			'diff_backup_old'  => null,
			'diff_backup_new'  => null,
			'push_result'      => null,
		];

		if ($task === 'view_config' && $backupId > 0) {
			$bk = $this->repo->getBackup($backupId);
			if ($bk && is_readable($bk['filepath'])) {
				$data['view_config'] = [
					'backup'  => $bk,
					'content' => file_get_contents($bk['filepath']),
				];
			}
		}

		if ($task === 'view_diff' && $backupId > 0 && $backupId2 > 0) {
			$bk1 = $this->repo->getBackup($backupId);
			$bk2 = $this->repo->getBackup($backupId2);
			if ($bk1 && $bk2 && is_readable($bk1['filepath']) && is_readable($bk2['filepath'])) {
				$data['diff_backup_old'] = $bk1;
				$data['diff_backup_new'] = $bk2;
				$data['diff_data'] = $this->computeDiff(
					$this->stripNoise(file_get_contents($bk1['filepath'])),
					$this->stripNoise(file_get_contents($bk2['filepath']))
				);
			}
		}

		if (isset($_SESSION['push_result'])) {
			$data['push_result'] = $_SESSION['push_result'];
			unset($_SESSION['push_result']);
		}

		$response = new CControllerResponseData($data);
		$response->setTitle('Config Manager');
		$this->setResponse($response);
	}

	// ── Task dispatcher ───────────────────────────────────────────────

	/** Returns true if the caller should redirect after task. */
	private function handleTask(string $task, string $tab): bool {
		switch ($task) {

			// ── Device CRUD ─────────────────────────────────────────
			case 'save_device':
				$this->repo->saveDevice([
					'device_id'         => (int)$this->getInput('device_id', 0),
					'name'              => $this->getInput('name', ''),
					'ip_address'        => $this->getInput('ip_address', ''),
					'vendor'            => $this->getInput('vendor', 'cisco_ios'),
					'username'          => $this->getInput('username', ''),
					'password'          => $this->getInput('password', ''),
					'port'              => (int)$this->getInput('port', 22),
					'backup_method'     => $this->getInput('backup_method', 'ssh'),
					'enabled'           => (int)$this->getInput('enabled', 1),
					'schedule_interval' => $this->getInput('schedule_interval', 'disabled'),
				]);
				$this->messages[] = ['type' => 'success', 'text' => 'Device saved.'];
				return true;

			case 'delete_device':
				$this->repo->deleteDevice((int)$this->getInput('device_id', 0));
				return true;

			// ── Backup ──────────────────────────────────────────────
			case 'backup_now':
				$dev = $this->repo->getDevice((int)$this->getInput('device_id', 0));
				if (!$dev) throw new \RuntimeException('Device not found.');
				$this->runBackup((int)$dev['device_id'], $dev);
				return true;

			// ── Template CRUD ────────────────────────────────────────
			case 'save_template':
				$this->repo->saveTemplate([
					'template_id'      => (int)$this->getInput('template_id', 0),
					'name'             => $this->getInput('name', ''),
					'category'         => $this->getInput('category', 'General'),
					'description'      => $this->getInput('description', ''),
					'template_content' => $this->getInput('template_content', ''),
				]);
				$this->messages[] = ['type' => 'success', 'text' => 'Template saved.'];
				return true;

			case 'delete_template':
				$this->repo->deleteTemplate((int)$this->getInput('template_id', 0));
				return true;

			// ── Push config (single device) ──────────────────────────
			case 'push_config':
				$this->doPush($tab);
				return false;   // result stored in session, re-render same tab

			// ── Bulk push ────────────────────────────────────────────
			case 'bulk_push':
				$this->doBulkPush();
				return false;

			// ── Restore backup ───────────────────────────────────────
			case 'restore_backup':
				$this->doRestore((int)$this->getInput('backup_id', 0));
				return true;
		}
		return false;
	}

	// ── Push single device ────────────────────────────────────────────

	private function doPush(string $tab): void {
		$deviceId   = (int)$this->getInput('device_id',   0);
		$templateId = (int)$this->getInput('template_id', 0);
		$rawCmds    = trim($this->getInput('commands', ''));
		$dryRun     = (bool)(int)$this->getInput('dry_run', 0);
		$user       = CWebUser::$data['alias'] ?? 'admin';

		$device = $this->repo->getDevice($deviceId);
		if (!$device) throw new \RuntimeException('Device not found.');

		// Resolve commands
		if ($templateId > 0) {
			$tpl     = $this->repo->getTemplate($templateId);
			$rawCmds = $tpl ? $tpl['template_content'] : $rawCmds;
			$pushType = 'template';
		} elseif ($this->hasUploadedFile()) {
			$rawCmds  = $this->readUploadedFile();
			$pushType = 'file';
		} else {
			$pushType = 'manual';
		}

		if (empty($rawCmds)) throw new \RuntimeException('No commands to push.');

		// Auto backup before push
		$preBackupId = null;
		if (!$dryRun) {
			try {
				$preBackupId = $this->runBackup($deviceId, $device, true);
			} catch (\Throwable $e) {
				$this->messages[] = ['type' => 'error',
					'text' => 'Pre-push backup failed: ' . $e->getMessage()];
			}
		}

		$commands = array_values(array_filter(
			array_map('trim', explode("\n", $rawCmds)),
			fn($l) => $l !== '' && !str_starts_with($l, '#')
		));

		$result = $this->execPushScript($device, $commands, $dryRun);

		$this->repo->recordPush([
			'device_id'      => $deviceId,
			'zabbix_user'    => $user,
			'push_type'      => $dryRun ? 'manual' : $pushType,
			'template_id'    => $templateId ?: null,
			'commands'       => implode("\n", $commands),
			'status'         => $dryRun ? 'dry_run' : ($result['success'] ? 'success' : 'failed'),
			'output'         => $result['output'] ?? $result['error'] ?? '',
			'execution_time' => $result['execution_time'] ?? 0,
			'pre_backup_id'  => $preBackupId,
			'dry_run'        => (int)$dryRun,
		]);

		if (!$result['success']) {
			$this->messages[] = ['type' => 'error',
				'text' => 'Push failed: ' . ($result['error'] ?? 'Unknown error')];
		} else {
			$verb = $dryRun ? 'Dry-run preview for' : 'Configuration pushed to';
			$this->messages[] = ['type' => 'success',
				'text' => "$verb {$device['name']} in " . round($result['execution_time'], 2) . 's'];
		}

		$_SESSION['push_result'] = [
			'device'   => $device['name'],
			'commands' => $commands,
			'output'   => $result['output']  ?? '',
			'error'    => $result['error']   ?? '',
			'success'  => $result['success'],
			'dry_run'  => $dryRun,
			'elapsed'  => $result['execution_time'] ?? 0,
		];
	}

	// ── Bulk push ─────────────────────────────────────────────────────

	private function doBulkPush(): void {
		$rawIds   = $this->getInput('device_ids', '[]');
		$ids      = json_decode($rawIds, true) ?: [];
		$rawCmds  = trim($this->getInput('commands', ''));
		$dryRun   = (bool)(int)$this->getInput('dry_run', 0);
		$tplId    = (int)$this->getInput('template_id', 0);
		$user     = CWebUser::$data['alias'] ?? 'admin';

		if ($tplId > 0) {
			$tpl     = $this->repo->getTemplate($tplId);
			$rawCmds = $tpl ? $tpl['template_content'] : $rawCmds;
		} elseif ($this->hasUploadedFile()) {
			$rawCmds = $this->readUploadedFile();
		}

		if (empty($rawCmds)) throw new \RuntimeException('No commands to push.');
		if (empty($ids))     throw new \RuntimeException('No devices selected.');

		$commands = array_values(array_filter(
			array_map('trim', explode("\n", $rawCmds)),
			fn($l) => $l !== '' && !str_starts_with($l, '#')
		));

		$results = [];
		foreach ($ids as $deviceId) {
			$deviceId = (int)$deviceId;
			$device   = $this->repo->getDevice($deviceId);
			if (!$device) { $results[] = ['name' => "#$deviceId", 'success' => false, 'error' => 'Not found']; continue; }

			$preBackupId = null;
			if (!$dryRun) {
				try { $preBackupId = $this->runBackup($deviceId, $device, true); }
				catch (\Throwable $e) {}
			}

			$result = $this->execPushScript($device, $commands, $dryRun);
			$this->repo->recordPush([
				'device_id'      => $deviceId,
				'zabbix_user'    => $user,
				'push_type'      => 'bulk',
				'template_id'    => $tplId ?: null,
				'commands'       => implode("\n", $commands),
				'status'         => $dryRun ? 'dry_run' : ($result['success'] ? 'success' : 'failed'),
				'output'         => $result['output'] ?? $result['error'] ?? '',
				'execution_time' => $result['execution_time'] ?? 0,
				'pre_backup_id'  => $preBackupId,
				'dry_run'        => (int)$dryRun,
			]);
			$results[] = [
				'name'    => $device['name'],
				'ip'      => $device['ip_address'],
				'success' => $result['success'],
				'elapsed' => $result['execution_time'] ?? 0,
				'error'   => $result['error'] ?? '',
			];
		}

		$ok  = count(array_filter($results, fn($r) => $r['success']));
		$fail= count($results) - $ok;
		$this->messages[] = ['type' => $fail === 0 ? 'success' : 'error',
			'text' => "Bulk push: $ok succeeded, $fail failed across " . count($ids) . ' devices.'];

		$_SESSION['push_result'] = [
			'bulk'     => true,
			'results'  => $results,
			'commands' => $commands,
			'dry_run'  => $dryRun,
		];
	}

	// ── Restore ───────────────────────────────────────────────────────

	private function doRestore(int $backupId): void {
		$bk = $this->repo->getBackup($backupId);
		if (!$bk) throw new \RuntimeException('Backup not found.');
		if (!is_readable($bk['filepath'])) throw new \RuntimeException('Backup file not readable.');

		$device   = $this->repo->getDevice((int)$bk['device_id']);
		if (!$device) throw new \RuntimeException('Device not found.');

		$content  = $this->stripNoise(file_get_contents($bk['filepath']));
		$commands = array_values(array_filter(
			array_map('trim', explode("\n", $content)),
			fn($l) => $l !== '' && !str_starts_with($l, '!')
				&& !str_starts_with($l, '#')
		));

		// Backup current config first
		$preBackupId = null;
		try { $preBackupId = $this->runBackup((int)$device['device_id'], $device, true); }
		catch (\Throwable $e) {}

		$result = $this->execPushScript($device, $commands, false);

		$this->repo->recordPush([
			'device_id'      => (int)$device['device_id'],
			'zabbix_user'    => CWebUser::$data['alias'] ?? 'admin',
			'push_type'      => 'restore',
			'template_id'    => null,
			'commands'       => implode("\n", $commands),
			'status'         => $result['success'] ? 'success' : 'failed',
			'output'         => $result['output'] ?? $result['error'] ?? '',
			'execution_time' => $result['execution_time'] ?? 0,
			'pre_backup_id'  => $preBackupId,
			'dry_run'        => 0,
		]);

		if (!$result['success']) {
			throw new \RuntimeException('Restore failed: ' . ($result['error'] ?? 'Unknown'));
		}
		$this->messages[] = ['type' => 'success',
			'text' => "Backup {$bk['filename']} restored to {$device['name']}."];
	}

	// ── Push script executor ──────────────────────────────────────────

	private function execPushScript(array $device, array $commands, bool $dryRun): array {
		$payload = json_encode([
			'host'        => $device['ip_address'],
			'vendor'      => $device['vendor'],
			'username'    => $device['username'],
			'password'    => $this->repo->decryptPassword($device['password']),
			'port'        => (int)$device['port'],
			'commands'    => $commands,
			'save_config' => true,
			'dry_run'     => $dryRun,
			'timeout'     => 30,
		]);

		$script = escapeshellarg(dirname(__DIR__) . '/scripts/push_config.py');
		$cmd    = 'python3 ' . $script . ' 2>/dev/null';

		$pipes  = [];
		$proc   = proc_open($cmd, [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		], $pipes);

		if (!is_resource($proc)) {
			return ['success' => false, 'output' => '', 'execution_time' => 0,
			        'error' => 'Could not start push_config.py'];
		}

		fwrite($pipes[0], $payload);
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($proc);

		$result = json_decode($stdout, true);
		if (!is_array($result)) {
			return ['success' => false, 'output' => $stdout, 'execution_time' => 0,
			        'error' => 'Invalid JSON from push_config.py'];
		}
		return $result;
	}

	// ── File upload helpers ───────────────────────────────────────────

	private function hasUploadedFile(): bool {
		return isset($_FILES['config_file'])
			&& $_FILES['config_file']['error'] === UPLOAD_ERR_OK;
	}

	private function readUploadedFile(): string {
		$file = $_FILES['config_file'];
		if ($file['size'] > 5 * 1024 * 1024) {
			throw new \RuntimeException('Uploaded file exceeds 5 MB limit.');
		}
		$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
		if (!in_array($ext, ['txt', 'cfg', 'conf', 'text'], true)) {
			throw new \RuntimeException('Only .txt, .cfg, .conf files are allowed.');
		}
		$content = file_get_contents($file['tmp_name']);
		if ($content === false) throw new \RuntimeException('Could not read uploaded file.');
		return $content;
	}

	// ── Backup runner ─────────────────────────────────────────────────

	/** Returns backup_id on success, throws on failure. */
	private function runBackup(int $deviceId, array $device, bool $silent = false): int {
		$ts       = date('Y-m-d_Hi');
		$dirName  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $device['name']);
		$dir      = '/opt/config-backups/' . $dirName;
		$filename = $ts . '.cfg';
		$filepath = $dir . '/' . $filename;

		$backupId = $this->repo->createBackupRecord($deviceId, $filename, $filepath);

		$python = escapeshellcmd('python3');
		$script = escapeshellarg(dirname(__DIR__) . '/scripts/backup.py');
		$ip     = escapeshellarg($device['ip_address']);
		$vendor = escapeshellarg($device['vendor']);
		$user   = escapeshellarg($device['username']);
		$pass   = escapeshellarg($this->repo->decryptPassword($device['password']));
		$port   = (int)$device['port'];
		$out    = escapeshellarg($filepath);
		$cmd    = "$python $script --ip $ip --vendor $vendor --user $user --pass $pass --port $port --out $out 2>&1";

		exec($cmd, $lines, $exitCode);
		$output = implode("\n", $lines);

		if ($exitCode === 0 && file_exists($filepath)) {
			$content = file_get_contents($filepath);
			$size    = strlen($content);
			$sha256  = hash('sha256', $content);
			@copy($filepath, $dir . '/latest.cfg');

			$prev = $this->repo->getPreviousBackup($deviceId, $backupId);
			if ($prev && file_exists($prev['filepath'])) {
				$prevContent = $this->stripNoise(file_get_contents($prev['filepath']));
				$newContent  = $this->stripNoise($content);
				$changed     = $prevContent !== $newContent;
				$diff        = $this->computeDiff($prevContent, $newContent);
				$added       = count(array_filter($diff, fn($l) => $l['type'] === 'add'));
				$removed     = count(array_filter($diff, fn($l) => $l['type'] === 'remove'));
				$this->repo->recordChange($deviceId, (int)$prev['backup_id'], $backupId, $changed, $added, $removed);
			} else {
				$this->repo->recordChange($deviceId, null, $backupId, false, 0, 0);
			}

			$this->repo->updateBackupRecord($backupId, 'success', $size, $sha256);
			if (!$silent) $this->messages[] = ['type' => 'success',
				'text' => "Backup completed for {$device['name']}."];
			return $backupId;
		}

		$this->repo->updateBackupRecord($backupId, 'failed', 0, '', $output);
		if (!$silent) throw new \RuntimeException("Backup failed for {$device['name']}: $output");
		return $backupId;
	}

	// ── Scheduled backups ─────────────────────────────────────────────

	private function runScheduledBackups(): void {
		try {
			foreach ($this->repo->getDueDevices() as $device) {
				try {
					$this->runBackup((int)$device['device_id'], $device, true);
					$this->repo->updateNextRun((int)$device['device_id'], $device['schedule_interval']);
				} catch (\Throwable $e) {
					error_log('ConfigManager scheduler: ' . $device['name'] . ': ' . $e->getMessage());
				}
			}
		} catch (\Throwable $e) {}
	}

	// ── Noise stripper ────────────────────────────────────────────────

	private function stripNoise(string $text): string {
		$patterns = [
			'Building configuration',
			'Current configuration',
			'Load for five',
			'Time source is',
			'! Last configuration change',
			'! NVRAM config last updated',
			'# generated by',
			'# by RouterOS',
			'# Config Manager',
			'# Device :',
			'# Vendor :',
			'# Time   :',
			'# ===',
		];
		$lines = explode("\n", $text);
		$out   = [];
		foreach ($lines as $line) {
			$t = trim($line);
			$skip = false;
			foreach ($patterns as $p) {
				if (str_starts_with($t, $p) || str_contains($t, $p)) { $skip = true; break; }
			}
			if (!$skip) $out[] = $line;
		}
		return implode("\n", $out);
	}

	// ── LCS Diff engine ───────────────────────────────────────────────

	private function computeDiff(string $old, string $new): array {
		$oldLines = explode("\n", $old);
		$newLines = explode("\n", $new);
		$m = min(count($oldLines), 2000);
		$n = min(count($newLines), 2000);
		$oldLines = array_slice($oldLines, 0, $m);
		$newLines = array_slice($newLines, 0, $n);

		$lcs = [];
		for ($i = 0; $i <= $m; $i++) $lcs[$i] = array_fill(0, $n + 1, 0);
		for ($i = 1; $i <= $m; $i++)
			for ($j = 1; $j <= $n; $j++)
				$lcs[$i][$j] = $oldLines[$i-1] === $newLines[$j-1]
					? $lcs[$i-1][$j-1] + 1
					: max($lcs[$i-1][$j], $lcs[$i][$j-1]);

		$i = $m; $j = $n; $tmp = [];
		while ($i > 0 || $j > 0) {
			if ($i > 0 && $j > 0 && $oldLines[$i-1] === $newLines[$j-1]) {
				$tmp[] = ['type' => 'equal', 'old_line' => $i, 'new_line' => $j, 'text' => $oldLines[$i-1]];
				$i--; $j--;
			} elseif ($j > 0 && ($i === 0 || $lcs[$i][$j-1] >= $lcs[$i-1][$j])) {
				$tmp[] = ['type' => 'add',    'old_line' => null, 'new_line' => $j, 'text' => $newLines[$j-1]];
				$j--;
			} else {
				$tmp[] = ['type' => 'remove', 'old_line' => $i, 'new_line' => null, 'text' => $oldLines[$i-1]];
				$i--;
			}
		}
		return array_reverse($tmp);
	}

	// ── Helpers ───────────────────────────────────────────────────────

	private function isAdmin(): bool {
		return ((int)(CWebUser::$data['type'] ?? 0))
			>= (defined('USER_TYPE_ZABBIX_ADMIN') ? USER_TYPE_ZABBIX_ADMIN : 2);
	}

	private function redirect(string $tab): void {
		$deviceId = (int)$this->getInput('device_id', 0);
		if (class_exists('CUrl')) {
			$url = (new CUrl('zabbix.php'))
				->setArgument('action', 'configmanager.view')
				->setArgument('tab', $tab);
			if ($deviceId > 0) $url->setArgument('device_id', $deviceId);
			$this->setResponse(new CControllerResponseRedirect($url));
		} else {
			$u = 'zabbix.php?action=configmanager.view&tab=' . rawurlencode($tab);
			if ($deviceId > 0) $u .= '&device_id=' . $deviceId;
			$this->setResponse(new CControllerResponseRedirect($u));
		}
	}

	private function renderSetup(): void {
		$data = ['setup_required' => true, 'v12_ready' => false, 'tab' => 'dashboard',
		         'messages' => [], 'sid' => '', 'is_admin' => $this->isAdmin(),
		         'current_user' => '', 'devices' => [], 'stats' => [], 'activity' => [],
		         'selected_device' => null, 'backups' => [], 'templates' => [],
		         'template_cats' => [], 'selected_template' => null,
		         'push_history' => [], 'push_stats' => [], 'schedule_options' => Repository::SCHEDULE_OPTIONS,
		         'view_config' => null, 'diff_data' => null,
		         'diff_backup_old' => null, 'diff_backup_new' => null, 'push_result' => null];
		$r = new CControllerResponseData($data);
		$r->setTitle('Config Manager – Setup');
		$this->setResponse($r);
	}
}
