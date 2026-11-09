<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AppDrop\Controller;

use OCA\AppDrop\Service\PermissionService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Shared authentication checks for all controllers.
 *
 * Controllers using this trait must have $userSession and $groupManager properties.
 * Controllers that need upload permission checks must also have $permissionService.
 */
trait AdminAuthTrait {
	private readonly IUserSession $userSession;
	private readonly IGroupManager $groupManager;

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->groupManager->isInGroup($user->getUID(), 'admin');
	}

	private function denyIfNotAdmin(): ?JSONResponse {
		if (!$this->isAdmin()) {
			return new JSONResponse(['success' => false, 'message' => 'Access denied.'], 403);
		}
		return null;
	}

	private function canUpload(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}
		if ($this->isAdmin()) {
			return true;
		}
		if (!isset($this->permissionService) || !($this->permissionService instanceof PermissionService)) {
			return false;
		}
		return $this->permissionService->canUpload($user);
	}

	/**
	 * What the current user may upload.
	 *
	 * @return string[]|null null = unrestricted (install and update any app);
	 *                       otherwise the app IDs the user may update ([] = no access)
	 */
	private function uploadScope(): ?array {
		if ($this->canUpload()) {
			return null;
		}
		if (!isset($this->permissionService) || !($this->permissionService instanceof PermissionService)) {
			return [];
		}
		return $this->permissionService->getUpdatableApps($this->userSession->getUser());
	}

	private function denyIfCannotUpload(): ?JSONResponse {
		if (!$this->canUpload()) {
			return new JSONResponse(['success' => false, 'message' => 'Access denied.'], 403);
		}
		return null;
	}
}
