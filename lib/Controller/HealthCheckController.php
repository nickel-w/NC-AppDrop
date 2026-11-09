<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AppDrop\Controller;

use OCA\AppDrop\Service\HealthCheckService;
use OCA\AppDrop\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IUserSession;

class HealthCheckController extends Controller {
	use AdminAuthTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly HealthCheckService $healthCheckService,
		private readonly ITempManager $tempManager,
		private readonly PermissionService $permissionService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Validate a zip file before installation.
	 */
	#[NoAdminRequired]
	public function validate(): JSONResponse {
		$scope = $this->uploadScope();
		if ($scope === []) {
			return new JSONResponse(['success' => false, 'message' => 'Access denied.'], 403);
		}

		$file = $this->request->getUploadedFile('zipFile');
		if ($file === null || empty($file['tmp_name'])) {
			return new JSONResponse(['errors' => ['No file uploaded.'], 'warnings' => []]);
		}

		// Store to temp for analysis
		$tmpPath = $this->tempManager->getTemporaryFile('.zip');
		if ($tmpPath === false || $tmpPath === null) {
			return new JSONResponse(['errors' => ['Could not create temporary file.'], 'warnings' => []]);
		}

		if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
			if (!copy($file['tmp_name'], $tmpPath)) {
				return new JSONResponse(['errors' => ['Could not store uploaded file.'], 'warnings' => []]);
			}
		}

		try {
			$report = $this->healthCheckService->analyze($tmpPath, $scope);
			return new JSONResponse($report);
		} finally {
			if (file_exists($tmpPath)) {
				@unlink($tmpPath);
			}
		}
	}
}
