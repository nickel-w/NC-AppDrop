<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AppDrop\Controller;

use OCA\AppDrop\Service\AppInstallException;
use OCA\AppDrop\Service\AppInstallService;
use OCA\AppDrop\Service\PermissionService;
use OCA\AppDrop\Service\UploadHistoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class AdminController extends Controller {
	use AdminAuthTrait;

	private const APP_ID = 'appdrop';

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly AppInstallService $installService,
		private readonly UploadHistoryService $historyService,
		private readonly PermissionService $permissionService,
		private readonly IConfig $config,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$scope = $this->uploadScope();
		if ($scope === []) {
			return new TemplateResponse(
				$this->appName,
				'admin/index',
				['error' => 'Access denied: you do not have permission to use this app.'],
				TemplateResponse::RENDER_AS_GUEST,
			);
		}

		$maxSizeMB = (int)$this->config->getAppValue(self::APP_ID, 'max_upload_size_mb', '20');
		$autoEnableDefault = $this->config->getAppValue(self::APP_ID, 'auto_enable_default', '1') === '1';

		return new TemplateResponse($this->appName, 'admin/index', [
			'isAdmin' => $this->isAdmin(),
			'canUploadAll' => $scope === null,
			'updatableApps' => $scope ?? [],
			'maxSizeMB' => $maxSizeMB,
			'autoEnableDefault' => $autoEnableDefault,
		], TemplateResponse::RENDER_AS_USER);
	}

	/**
	 * Receives the zip upload, validates and installs/updates the app.
	 */
	#[NoAdminRequired]
	public function install(): JSONResponse {
		$scope = $this->uploadScope();
		if ($scope === []) {
			return new JSONResponse(['success' => false, 'message' => 'Access denied.'], 403);
		}

		$file = $this->request->getUploadedFile('zipFile');
		if ($file === null || empty($file['tmp_name'])) {
			return new JSONResponse(['success' => false, 'message' => 'No file uploaded.']);
		}

		$autoEnable = $this->request->getParam('autoEnable', '1') === '1';

		try {
			$result = $scope === null
				? $this->installService->install($file, $autoEnable)
				: $this->installService->install($file, false, $scope);

			$user = $this->userSession->getUser();
			$userId = $user !== null ? $user->getUID() : 'unknown';
			$this->historyService->record(
				$result['appId'],
				$result['version'],
				$file['name'] ?? 'unknown.zip',
				'success',
				$result['message'],
				$userId,
			);

			return new JSONResponse($result);
		} catch (AppInstallException $e) {
			$this->recordFailure($file, $e->getMessage());
			return new JSONResponse(['success' => false, 'message' => $e->getMessage()]);
		} catch (\Throwable $e) {
			$this->recordFailure($file, 'Unexpected error: ' . $e->getMessage());
			return new JSONResponse(['success' => false, 'message' => 'Unexpected error: ' . $e->getMessage()]);
		}
	}

	private function recordFailure(array $file, string $message): void {
		try {
			$user = $this->userSession->getUser();
			$userId = $user !== null ? $user->getUID() : 'unknown';
			$this->historyService->record(
				'unknown',
				'',
				$file['name'] ?? 'unknown.zip',
				'error',
				$message,
				$userId,
			);
		} catch (\Throwable) {
			// Don't let history recording failure mask the original error
		}
	}
}
