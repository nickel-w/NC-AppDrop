<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AppDrop\Controller;

use OCA\AppDrop\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

class PermissionController extends Controller {
	use AdminAuthTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly PermissionService $permissionService,
		private readonly IUserManager $userManager,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get current permissions (allowed users and groups).
	 */
	#[NoCSRFRequired]
	public function get(): JSONResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}

		return new JSONResponse([
			'success' => true,
			'users' => $this->permissionService->getAllowedUsers(),
			'groups' => $this->permissionService->getAllowedGroups(),
		]);
	}

	public function addUser(): JSONResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}

		$params = json_decode(file_get_contents('php://input'), true) ?? [];
		$userId = trim($params['userId'] ?? '');
		if ($userId === '') {
			return new JSONResponse(['success' => false, 'message' => 'Missing userId parameter.'], 400);
		}

		$this->permissionService->addUser($userId);
		return new JSONResponse(['success' => true, 'message' => "User '{$userId}' added."]);
	}

	public function removeUser(): JSONResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}

		$params = json_decode(file_get_contents('php://input'), true) ?? [];
		$userId = trim($params['userId'] ?? '');
		if ($userId === '') {
			return new JSONResponse(['success' => false, 'message' => 'Missing userId parameter.'], 400);
		}

		$this->permissionService->removeUser($userId);
		return new JSONResponse(['success' => true, 'message' => "User '{$userId}' removed."]);
	}

	public function addGroup(): JSONResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}

		$params = json_decode(file_get_contents('php://input'), true) ?? [];
		$groupId = trim($params['groupId'] ?? '');
		if ($groupId === '') {
			return new JSONResponse(['success' => false, 'message' => 'Missing groupId parameter.'], 400);
		}

		$this->permissionService->addGroup($groupId);
		return new JSONResponse(['success' => true, 'message' => "Group '{$groupId}' added."]);
	}

	public function removeGroup(): JSONResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}

		$params = json_decode(file_get_contents('php://input'), true) ?? [];
		$groupId = trim($params['groupId'] ?? '');
		if ($groupId === '') {
			return new JSONResponse(['success' => false, 'message' => 'Missing groupId parameter.'], 400);
		}

		$this->permissionService->removeGroup($groupId);
		return new JSONResponse(['success' => true, 'message' => "Group '{$groupId}' removed."]);
	}

	/**
	 * Per-app update permissions: { appId: { users: [], groups: [] } }.
	 */
	#[NoCSRFRequired]
	public function getUpdateGrants(): JSONResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}

		return new JSONResponse([
			'success' => true,
			'grants' => (object)$this->permissionService->getUpdateGrants(),
		]);
	}

	/**
	 * Allow a user or group to update one specific app.
	 * Body: { appId, type: "user"|"group", id }
	 */
	public function addUpdateGrant(): JSONResponse {
		return $this->changeUpdateGrant(true);
	}

	public function removeUpdateGrant(): JSONResponse {
		return $this->changeUpdateGrant(false);
	}

	private function changeUpdateGrant(bool $add): JSONResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}

		$params = json_decode(file_get_contents('php://input') ?: '', true);
		$params = is_array($params) ? $params : [];
		$appId = trim((string)($params['appId'] ?? ''));
		$type = trim((string)($params['type'] ?? ''));
		$id = trim((string)($params['id'] ?? ''));

		if ($add && $type === 'user' && $id !== '' && !$this->userManager->userExists($id)) {
			return new JSONResponse(['success' => false, 'message' => "User '{$id}' does not exist."], 400);
		}
		if ($add && $type === 'group' && $id !== '' && !$this->groupManager->groupExists($id)) {
			return new JSONResponse(['success' => false, 'message' => "Group '{$id}' does not exist."], 400);
		}

		try {
			if ($add) {
				$this->permissionService->addUpdateGrant($appId, $type, $id);
			} else {
				$this->permissionService->removeUpdateGrant($appId, $type, $id);
			}
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 400);
		}

		$verb = $add ? 'may now update' : 'may no longer update';
		return new JSONResponse(['success' => true, 'message' => ucfirst($type) . " '{$id}' {$verb} '{$appId}'."]);
	}

	/**
	 * Search users for autocomplete.
	 */
	#[NoCSRFRequired]
	public function searchUsers(): JSONResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}

		$term = trim($this->request->getParam('term', ''));
		if ($term === '') {
			return new JSONResponse(['success' => true, 'results' => []]);
		}

		$results = [];
		$users = $this->userManager->searchDisplayName($term, 20);
		foreach ($users as $user) {
			$results[] = [
				'id' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
			];
		}

		return new JSONResponse(['success' => true, 'results' => $results]);
	}

	/**
	 * Search groups for autocomplete.
	 */
	#[NoCSRFRequired]
	public function searchGroups(): JSONResponse {
		$denied = $this->denyIfNotAdmin();
		if ($denied !== null) {
			return $denied;
		}

		$term = trim($this->request->getParam('term', ''));
		if ($term === '') {
			return new JSONResponse(['success' => true, 'results' => []]);
		}

		$results = [];
		$groups = $this->groupManager->search($term, 20);
		foreach ($groups as $group) {
			$results[] = [
				'id' => $group->getGID(),
				'displayName' => $group->getDisplayName(),
			];
		}

		return new JSONResponse(['success' => true, 'results' => $results]);
	}
}
