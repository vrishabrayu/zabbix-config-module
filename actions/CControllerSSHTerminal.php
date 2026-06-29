<?php
declare(strict_types = 1);

use Modules\ConfigManager\Includes\ConfigManager\Repository;

require_once __DIR__ . '/../includes/ConfigManager/Repository.php';

/**
 * SSH Terminal controller.
 * Handles:
 *   task=list   — device list page (default)
 *   task=open   — full-screen terminal page for a device
 *   task=token  — AJAX: generate one-time token for WebSocket auth
 */
class CControllerSSHTerminal extends CController {

	private Repository $repo;

	protected function init(): void {
		$this->disableCsrfValidation();
		$this->repo = new Repository();
	}

	protected function checkInput(): bool {
		return $this->validateInput([
			'task'      => 'string',
			'device_id' => 'int32',
			'ws_port'   => 'int32',
		]);
	}

	protected function checkPermissions(): bool {
		return true;
	}

	protected function doAction(): void {
		$task = (string)$this->getInput('task', 'list');

		// AJAX token request — return JSON, no view
		if ($task === 'token') {
			$this->handleTokenRequest();
			return;
		}

		$deviceId  = (int)$this->getInput('device_id', 0);
		$wsPort    = (int)$this->getInput('ws_port', 7681);
		$v13Ready  = $this->repo->v13TablesExist();

		$data = [
			'task'         => $task,
			'devices'      => $v13Ready ? $this->repo->getDevices() : [],
			'selected'     => ($v13Ready && $deviceId > 0) ? $this->repo->getDevice($deviceId) : null,
			'sessions'     => $v13Ready ? $this->repo->getSSHSessions(10) : [],
			'ws_port'      => $wsPort,
			'sid'          => CWebUser::$data['sessionid'] ?? '',
			'current_user' => CWebUser::$data['alias']    ?? 'admin',
			'v13_ready'    => $v13Ready,
			'messages'     => [],
		];

		$response = new CControllerResponseData($data);
		$response->setTitle('SSH Terminal');
		$this->setResponse($response);
	}

	private function handleTokenRequest(): void {
		$deviceId = (int)$this->getInput('device_id', 0);

		if ($deviceId <= 0) {
			$data = ['error' => 'device_id required'];
		} elseif (!$this->repo->v13TablesExist()) {
			$data = ['error' => 'SSH tables not installed'];
		} else {
			$device = $this->repo->getDevice($deviceId);
			if (!$device) {
				$data = ['error' => 'Device not found'];
			} else {
				$user  = CWebUser::$data['alias'] ?? 'admin';
				$token = $this->repo->createSSHToken($deviceId, $user);
				$data  = [
					'token'       => $token,
					'device_id'   => $deviceId,
					'device_name' => $device['name'],
					'ip'          => $device['ip_address'],
				];
			}
		}

		echo json_encode($data);
		exit;
	}
}
