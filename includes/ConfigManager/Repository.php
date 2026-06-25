<?php
declare(strict_types = 1);

namespace Modules\ConfigManager\Includes\ConfigManager;

final class Repository {

	private const ENCRYPT_ALGO    = 'AES-256-CBC';
	private const ENCRYPT_KEY_LEN = 32;
	private const ENCRYPT_IV_LEN  = 16;

	public const SCHEDULE_OPTIONS = [
		'disabled'   => 'Disabled',
		'hourly'     => 'Every Hour',
		'every_6h'   => 'Every 6 Hours',
		'every_12h'  => 'Every 12 Hours',
		'daily'      => 'Every Day',
		'weekly'     => 'Every Week',
	];

	private const SCHEDULE_SECONDS = [
		'disabled'   => 0,
		'hourly'     => 3600,
		'every_6h'   => 21600,
		'every_12h'  => 43200,
		'daily'      => 86400,
		'weekly'     => 604800,
	];

	// ── Encryption ───────────────────────────────────────────────────
	private function encKey(): string {
		$raw = defined('ZBX_SECRET_KEY') ? ZBX_SECRET_KEY : 'configmanager_default_key_change_me';
		return substr(hash('sha256', $raw, true), 0, self::ENCRYPT_KEY_LEN);
	}

	public function encryptPassword(string $plain): string {
		$iv  = random_bytes(self::ENCRYPT_IV_LEN);
		$enc = openssl_encrypt($plain, self::ENCRYPT_ALGO, $this->encKey(), OPENSSL_RAW_DATA, $iv);
		return base64_encode($iv . $enc);
	}

	public function decryptPassword(string $stored): string {
		$raw = base64_decode($stored, true);
		if ($raw === false || strlen($raw) <= self::ENCRYPT_IV_LEN) return '';
		$iv  = substr($raw, 0, self::ENCRYPT_IV_LEN);
		$enc = substr($raw, self::ENCRYPT_IV_LEN);
		return (string) openssl_decrypt($enc, self::ENCRYPT_ALGO, $this->encKey(), OPENSSL_RAW_DATA, $iv);
	}

	// ── Safe DB helpers ──────────────────────────────────────────────
	private function safeQuery(string $sql): mixed {
		set_error_handler(static function (int $errno, string $errstr): bool {
			throw new \RuntimeException($errstr, $errno);
		}, E_ALL);
		try {
			$result = DBselect($sql);
		} finally {
			restore_error_handler();
		}
		return $result;
	}

	private function safeExec(string $sql): bool {
		set_error_handler(static function (int $errno, string $errstr): bool {
			throw new \RuntimeException($errstr, $errno);
		}, E_ALL);
		try {
			$result = DBexecute($sql);
		} finally {
			restore_error_handler();
		}
		return (bool) $result;
	}

	private function q(string $v): string {
		return function_exists('zbx_dbstr') ? zbx_dbstr($v) : "'" . addslashes($v) . "'";
	}

	// ── Table check ──────────────────────────────────────────────────
	public function tablesExist(): bool {
		try {
			$this->safeQuery('SELECT 1 FROM config_devices LIMIT 1');
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	// ── Timezone-safe time helpers ───────────────────────────────────
	// All timestamps use MySQL's NOW() so PHP tz never matters.
	// next_run_at = NOW() + INTERVAL N SECOND computed fully in MySQL.

	private function nextRunSql(string $interval): string {
		$secs = self::SCHEDULE_SECONDS[$interval] ?? 0;
		if ($secs === 0) return 'NULL';
		return "DATE_ADD(NOW(), INTERVAL {$secs} SECOND)";
	}

	// ── Get MySQL NOW() as a PHP string (for display) ────────────────
	public function getMysqlNow(): string {
		try {
			$r = DBfetch($this->safeQuery('SELECT NOW() AS n'));
			return (string)($r['n'] ?? date('Y-m-d H:i:s'));
		} catch (\Throwable $e) {
			return date('Y-m-d H:i:s');
		}
	}

	// ════════════════════════════════════════════════════════════════
	// DEVICES
	// ════════════════════════════════════════════════════════════════

	public function getDevices(): array {
		try {
			$res  = $this->safeQuery("
				SELECT d.*,
					MAX(b.backed_up_at)  AS last_backup,
					MAX(b.status)        AS last_status,
					SUM(CASE WHEN b.backed_up_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS backup_count,
					SUM(CASE WHEN ch.changed=1 AND DATE(ch.detected_at)=CURDATE() THEN 1 ELSE 0 END) AS changed_today
				FROM config_devices d
				LEFT JOIN config_backups b  ON b.device_id = d.device_id
				LEFT JOIN config_changes ch ON ch.device_id = d.device_id
				GROUP BY d.device_id
				ORDER BY d.name");
			$rows = [];
			while ($r = DBfetch($res)) $rows[] = $r;
			return $rows;
		} catch (\Throwable $e) { return []; }
	}

	public function getDevice(int $id): ?array {
		try {
			$r = DBfetch($this->safeQuery(
				'SELECT * FROM config_devices WHERE device_id=' . (int)$id));
			return $r ?: null;
		} catch (\Throwable $e) { return null; }
	}

	/** Return devices whose next_run_at is due — fully in MySQL time */
	public function getDueDevices(): array {
		try {
			$res  = $this->safeQuery(
				"SELECT * FROM config_devices
				 WHERE enabled = 1
				   AND schedule_interval != 'disabled'
				   AND next_run_at IS NOT NULL
				   AND next_run_at <= NOW()
				 ORDER BY next_run_at ASC");
			$rows = [];
			while ($r = DBfetch($res)) $rows[] = $r;
			return $rows;
		} catch (\Throwable $e) { return []; }
	}

	public function saveDevice(array $d): int {
		$name     = $this->q(trim($d['name']              ?? ''));
		$ip       = $this->q(trim($d['ip_address']        ?? ''));
		$vendor   = $this->q($d['vendor']                 ?? 'cisco_ios');
		$user     = $this->q(trim($d['username']          ?? ''));
		$port     = max(1, min(65535, (int)($d['port']    ?? 22)));
		$method   = $this->q(in_array(
			$d['backup_method'] ?? '', ['ssh','telnet'])
			? $d['backup_method'] : 'ssh');
		$enabled  = (int)(bool)($d['enabled']             ?? 1);
		$schedVal = array_key_exists($d['schedule_interval'] ?? '', self::SCHEDULE_OPTIONS)
			? ($d['schedule_interval'] ?? 'disabled') : 'disabled';
		$schedule = $this->q($schedVal);

		// next_run_at computed entirely in MySQL — no PHP time() involved
		$nextRunExpr = $this->nextRunSql($schedVal);

		$rawPwd = trim($d['password'] ?? '');
		$id     = (int)($d['device_id'] ?? 0);

		if ($id > 0) {
			$pwdSql = '';
			if ($rawPwd !== '') {
				$enc    = $this->q($this->encryptPassword($rawPwd));
				$pwdSql = ", password=$enc";
			}
			$this->safeExec("
				UPDATE config_devices
				SET name=$name, ip_address=$ip, vendor=$vendor, username=$user,
				    port=$port, backup_method=$method, enabled=$enabled,
				    schedule_interval=$schedule,
				    next_run_at=$nextRunExpr
				    $pwdSql
				WHERE device_id=$id");
			return $id;
		}

		$enc = $this->q($this->encryptPassword($rawPwd));
		$this->safeExec("
			INSERT INTO config_devices
			    (name, ip_address, vendor, username, password, port, backup_method,
			     enabled, schedule_interval, next_run_at)
			VALUES ($name, $ip, $vendor, $user, $enc, $port, $method,
			        $enabled, $schedule, $nextRunExpr)");
		$row = DBfetch($this->safeQuery('SELECT LAST_INSERT_ID() AS id'));
		return (int)($row['id'] ?? 0);
	}

	/** Advance next_run_at by one interval — computed in MySQL */
	public function updateNextRun(int $deviceId, string $interval): void {
		$secs = self::SCHEDULE_SECONDS[$interval] ?? 0;
		if ($secs === 0) {
			$this->safeExec(
				"UPDATE config_devices SET next_run_at=NULL WHERE device_id=$deviceId");
		} else {
			// Add one interval from NOW() so clock drift never accumulates
			$this->safeExec(
				"UPDATE config_devices
				 SET next_run_at = DATE_ADD(NOW(), INTERVAL {$secs} SECOND)
				 WHERE device_id=$deviceId");
		}
	}

	public function deleteDevice(int $id): void {
		$this->safeExec('DELETE FROM config_devices WHERE device_id=' . (int)$id);
	}

	// ════════════════════════════════════════════════════════════════
	// BACKUPS
	// ════════════════════════════════════════════════════════════════

	public function getBackupsForDevice(int $deviceId, int $limit = 50): array {
		try {
			$res  = $this->safeQuery(
				"SELECT b.*, ch.changed, ch.lines_added, ch.lines_removed
				 FROM config_backups b
				 LEFT JOIN config_changes ch ON ch.backup_id_new = b.backup_id
				 WHERE b.device_id=" . (int)$deviceId . "
				 ORDER BY b.backed_up_at DESC
				 LIMIT " . (int)$limit);
			$rows = [];
			while ($r = DBfetch($res)) $rows[] = $r;
			return $rows;
		} catch (\Throwable $e) { return []; }
	}

	public function getBackup(int $id): ?array {
		try {
			$r = DBfetch($this->safeQuery(
				'SELECT * FROM config_backups WHERE backup_id=' . (int)$id));
			return $r ?: null;
		} catch (\Throwable $e) { return null; }
	}

	public function createBackupRecord(int $deviceId, string $filename, string $filepath): int {
		$fn = $this->q($filename);
		$fp = $this->q($filepath);
		$this->safeExec(
			"INSERT INTO config_backups (device_id, filename, filepath, status)
			 VALUES ($deviceId, $fn, $fp, 'running')");
		$row = DBfetch($this->safeQuery('SELECT LAST_INSERT_ID() AS id'));
		return (int)($row['id'] ?? 0);
	}

	public function updateBackupRecord(
		int $id, string $status, int $size, string $sha256, string $error = ''
	): void {
		$st  = $this->q($status);
		$sha = $this->q($sha256);
		$err = $this->q($error);
		$this->safeExec(
			"UPDATE config_backups
			 SET status=$st, file_size=$size, sha256=$sha, error_message=$err
			 WHERE backup_id=$id");
	}

	public function getPreviousBackup(int $deviceId, int $excludeId): ?array {
		try {
			$r = DBfetch($this->safeQuery(
				"SELECT * FROM config_backups
				 WHERE device_id=$deviceId
				   AND backup_id != $excludeId
				   AND status='success'
				 ORDER BY backed_up_at DESC LIMIT 1"));
			return $r ?: null;
		} catch (\Throwable $e) { return null; }
	}

	public function recordChange(
		int $deviceId, ?int $oldId, int $newId,
		bool $changed, int $added, int $removed
	): void {
		$oldSql = $oldId !== null ? (int)$oldId : 'NULL';
		$ch     = (int)$changed;
		$this->safeExec(
			"INSERT INTO config_changes
			     (device_id, backup_id_old, backup_id_new, changed, lines_added, lines_removed)
			 VALUES ($deviceId, $oldSql, $newId, $ch, $added, $removed)");
	}

	// ════════════════════════════════════════════════════════════════
	// DASHBOARD
	// ════════════════════════════════════════════════════════════════

	public function getDashboardStats(): array {
		$stats = [
			'total_devices'     => 0,
			'success_today'     => 0,
			'failed_today'      => 0,
			'changes_today'     => 0,
			'devices_changed'   => 0,
			'devices_unchanged' => 0,
			'last_backup'       => null,
			'scheduled_devices' => 0,
			'mysql_now'         => '',
		];
		try {
			$r = DBfetch($this->safeQuery(
				'SELECT COUNT(*) AS c FROM config_devices WHERE enabled=1'));
			$stats['total_devices'] = (int)($r['c'] ?? 0);

			$r = DBfetch($this->safeQuery(
				"SELECT SUM(status='success') AS s,
				        SUM(status='failed')  AS f,
				        MAX(backed_up_at)     AS last
				 FROM config_backups
				 WHERE DATE(backed_up_at) = CURDATE()"));
			if ($r) {
				$stats['success_today'] = (int)($r['s']    ?? 0);
				$stats['failed_today']  = (int)($r['f']    ?? 0);
				$stats['last_backup']   = $r['last'];
			}

			$r = DBfetch($this->safeQuery(
				"SELECT COUNT(*) AS c FROM config_changes
				 WHERE changed=1 AND DATE(detected_at)=CURDATE()"));
			$stats['changes_today'] = (int)($r['c'] ?? 0);

			$r = DBfetch($this->safeQuery(
				"SELECT SUM(changed=1) AS ch, SUM(changed=0) AS unch
				 FROM config_changes WHERE DATE(detected_at)=CURDATE()"));
			if ($r) {
				$stats['devices_changed']   = (int)($r['ch']   ?? 0);
				$stats['devices_unchanged'] = (int)($r['unch'] ?? 0);
			}

			$r = DBfetch($this->safeQuery(
				"SELECT COUNT(*) AS c FROM config_devices
				 WHERE enabled=1 AND schedule_interval != 'disabled'"));
			$stats['scheduled_devices'] = (int)($r['c'] ?? 0);

			// Return MySQL server time for display — removes any PHP/MySQL tz confusion
			$r = DBfetch($this->safeQuery(
				"SELECT NOW() AS now,
				        @@global.time_zone AS tz,
				        @@session.time_zone AS sess_tz"));
			if ($r) {
				$stats['mysql_now'] = (string)($r['now'] ?? '');
				$stats['mysql_tz']  = trim(($r['tz'] ?? '') . ' / ' . ($r['sess_tz'] ?? ''));
			}
		} catch (\Throwable $e) {}
		return $stats;
	}

	public function getRecentActivity(int $limit = 15): array {
		try {
			$res  = $this->safeQuery(
				"SELECT b.backed_up_at AS ts, d.name AS device,
				        b.status, ch.changed
				 FROM config_backups b
				 INNER JOIN config_devices d  ON d.device_id = b.device_id
				 LEFT  JOIN config_changes ch ON ch.backup_id_new = b.backup_id
				 ORDER BY b.backed_up_at DESC
				 LIMIT " . (int)$limit);
			$rows = [];
			while ($r = DBfetch($res)) $rows[] = $r;
			return $rows;
		} catch (\Throwable $e) { return []; }
	}

	// ════════════════════════════════════════════════════════════════
	// v1.2 — TABLE CHECK
	// ════════════════════════════════════════════════════════════════

	public function v12TablesExist(): bool {
		try {
			$this->safeQuery('SELECT 1 FROM config_templates LIMIT 1');
			$this->safeQuery('SELECT 1 FROM config_push_history LIMIT 1');
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	// ════════════════════════════════════════════════════════════════
	// TEMPLATES
	// ════════════════════════════════════════════════════════════════

	public function getTemplates(string $category = ''): array {
		try {
			$where = $category !== '' ? 'WHERE category=' . $this->q($category) : '';
			$res   = $this->safeQuery(
				"SELECT * FROM config_templates $where ORDER BY category, name");
			$rows  = [];
			while ($r = DBfetch($res)) $rows[] = $r;
			return $rows;
		} catch (\Throwable $e) { return []; }
	}

	public function getTemplate(int $id): ?array {
		try {
			$r = DBfetch($this->safeQuery(
				'SELECT * FROM config_templates WHERE template_id=' . (int)$id));
			return $r ?: null;
		} catch (\Throwable $e) { return null; }
	}

	public function saveTemplate(array $d): int {
		$name    = $this->q(trim($d['name']             ?? ''));
		$cat     = $this->q(trim($d['category']         ?? 'General'));
		$desc    = $this->q(trim($d['description']      ?? ''));
		$content = $this->q(trim($d['template_content'] ?? ''));
		$id      = (int)($d['template_id'] ?? 0);

		if ($id > 0) {
			$this->safeExec(
				"UPDATE config_templates
				 SET name=$name, category=$cat, description=$desc,
				     template_content=$content
				 WHERE template_id=$id");
			return $id;
		}

		$this->safeExec(
			"INSERT INTO config_templates (name, category, description, template_content)
			 VALUES ($name, $cat, $desc, $content)");
		$row = DBfetch($this->safeQuery('SELECT LAST_INSERT_ID() AS id'));
		return (int)($row['id'] ?? 0);
	}

	public function deleteTemplate(int $id): void {
		$this->safeExec(
			'DELETE FROM config_templates WHERE template_id=' . (int)$id);
	}

	public function getTemplateCategories(): array {
		try {
			$res  = $this->safeQuery(
				'SELECT DISTINCT category FROM config_templates ORDER BY category');
			$cats = [];
			while ($r = DBfetch($res)) $cats[] = $r['category'];
			return $cats;
		} catch (\Throwable $e) { return []; }
	}

	// ════════════════════════════════════════════════════════════════
	// PUSH HISTORY
	// ════════════════════════════════════════════════════════════════

	public function recordPush(array $d): int {
		$deviceId   = (int)($d['device_id']      ?? 0);
		$user       = $this->q(trim($d['zabbix_user'] ?? 'system'));
		$type       = $this->q($d['push_type']    ?? 'manual');
		$tplId      = isset($d['template_id']) && $d['template_id']
			? (int)$d['template_id'] : 'NULL';
		$commands   = $this->q($d['commands']     ?? '');
		$status     = $this->q($d['status']       ?? 'success');
		$output     = $this->q($d['output']       ?? '');
		$elapsed    = number_format((float)($d['execution_time'] ?? 0), 3, '.', '');
		$preBackup  = isset($d['pre_backup_id']) && $d['pre_backup_id']
			? (int)$d['pre_backup_id'] : 'NULL';
		$dryRun     = (int)(bool)($d['dry_run'] ?? false);

		$this->safeExec(
			"INSERT INTO config_push_history
			     (device_id, zabbix_user, push_type, template_id, commands,
			      status, output, execution_time, pre_backup_id, dry_run)
			 VALUES ($deviceId, $user, $type, $tplId, $commands,
			         $status, $output, $elapsed, $preBackup, $dryRun)");
		$row = DBfetch($this->safeQuery('SELECT LAST_INSERT_ID() AS id'));
		return (int)($row['id'] ?? 0);
	}

	public function getPushHistory(int $deviceId = 0, int $limit = 50): array {
		try {
			$where = $deviceId > 0 ? "WHERE ph.device_id=$deviceId" : '';
			$res   = $this->safeQuery(
				"SELECT ph.*, d.name AS device_name, d.ip_address,
				        t.name AS template_name
				 FROM config_push_history ph
				 INNER JOIN config_devices d ON d.device_id = ph.device_id
				 LEFT  JOIN config_templates t ON t.template_id = ph.template_id
				 $where
				 ORDER BY ph.pushed_at DESC
				 LIMIT " . (int)$limit);
			$rows  = [];
			while ($r = DBfetch($res)) $rows[] = $r;
			return $rows;
		} catch (\Throwable $e) { return []; }
	}

	public function getPushStats(): array {
		$stats = ['total_pushes' => 0, 'success_today' => 0,
		          'failed_today' => 0, 'templates_applied' => 0];
		try {
			$r = DBfetch($this->safeQuery(
				'SELECT COUNT(*) AS c FROM config_push_history'));
			$stats['total_pushes'] = (int)($r['c'] ?? 0);

			$r = DBfetch($this->safeQuery(
				"SELECT SUM(status='success') AS s, SUM(status='failed') AS f
				 FROM config_push_history WHERE DATE(pushed_at)=CURDATE()"));
			if ($r) {
				$stats['success_today'] = (int)($r['s'] ?? 0);
				$stats['failed_today']  = (int)($r['f'] ?? 0);
			}

			$r = DBfetch($this->safeQuery(
				"SELECT COUNT(*) AS c FROM config_push_history
				 WHERE push_type='template' AND DATE(pushed_at)=CURDATE()"));
			$stats['templates_applied'] = (int)($r['c'] ?? 0);
		} catch (\Throwable $e) {}
		return $stats;
	}
}
