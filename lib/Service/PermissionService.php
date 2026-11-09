<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AppDrop\Service;

use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;

class PermissionService {
	private const APP_ID = 'appdrop';
	private const KEY_ALLOWED_USERS = 'allowed_users';
	private const KEY_ALLOWED_GROUPS = 'allowed_groups';
	private const KEY_UPDATE_GRANTS = 'update_grants';
	private const APPID_PATTERN = '/^[a-z0-9_]{3,64}$/';

	public function __construct(
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
	) {
	}

	public function canUpload(?IUser $user): bool {
		if ($user === null) {
			return false;
		}

		$uid = $user->getUID();

		// Admin always has access
		if ($this->groupManager->isInGroup($uid, 'admin')) {
			return true;
		}

		// Check allowed users
		if (in_array($uid, $this->getAllowedUsers(), true)) {
			return true;
		}

		// Check allowed groups
		foreach ($this->getAllowedGroups() as $groupId) {
			if ($this->groupManager->isInGroup($uid, $groupId)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the user may open AppDrop at all: full upload permission, or
	 * permission to update at least one specific app.
	 */
	public function canAccess(?IUser $user): bool {
		return $this->canUpload($user) || $this->getUpdatableApps($user) !== [];
	}

	/**
	 * App IDs the user was granted update permission for (directly or via a
	 * group). Does not include the unrestricted upload permission.
	 *
	 * @return string[]
	 */
	public function getUpdatableApps(?IUser $user): array {
		if ($user === null) {
			return [];
		}

		$uid = $user->getUID();
		$apps = [];
		$groupCache = [];
		foreach ($this->getUpdateGrants() as $appId => $grant) {
			if (in_array($uid, $grant['users'], true)) {
				$apps[] = $appId;
				continue;
			}
			foreach ($grant['groups'] as $groupId) {
				$groupCache[$groupId] ??= $this->groupManager->isInGroup($uid, $groupId);
				if ($groupCache[$groupId]) {
					$apps[] = $appId;
					break;
				}
			}
		}

		sort($apps);
		return $apps;
	}

	public function canUpdateApp(?IUser $user, string $appId): bool {
		return in_array($appId, $this->getUpdatableApps($user), true);
	}

	/**
	 * @return array<string, array{users: string[], groups: string[]}>
	 */
	public function getUpdateGrants(): array {
		$json = $this->config->getAppValue(self::APP_ID, self::KEY_UPDATE_GRANTS, '{}');
		$data = json_decode($json, true);
		if (!is_array($data)) {
			return [];
		}

		$grants = [];
		foreach ($data as $appId => $grant) {
			if (!is_string($appId) || !is_array($grant)) {
				continue;
			}
			$users = array_values(array_filter((array)($grant['users'] ?? []), 'is_string'));
			$groups = array_values(array_filter((array)($grant['groups'] ?? []), 'is_string'));
			if ($users !== [] || $groups !== []) {
				$grants[$appId] = ['users' => $users, 'groups' => $groups];
			}
		}
		ksort($grants);
		return $grants;
	}

	/**
	 * @param 'user'|'group' $type
	 */
	public function addUpdateGrant(string $appId, string $type, string $id): void {
		$this->assertGrantArgs($appId, $type, $id);
		$key = $type . 's';
		$grants = $this->getUpdateGrants();
		$grant = $grants[$appId] ?? ['users' => [], 'groups' => []];
		if (!in_array($id, $grant[$key], true)) {
			$grant[$key][] = $id;
		}
		$grants[$appId] = $grant;
		$this->saveUpdateGrants($grants);
	}

	/**
	 * @param 'user'|'group' $type
	 */
	public function removeUpdateGrant(string $appId, string $type, string $id): void {
		$this->assertGrantArgs($appId, $type, $id);
		$key = $type . 's';
		$grants = $this->getUpdateGrants();
		if (!isset($grants[$appId])) {
			return;
		}
		$grants[$appId][$key] = array_values(array_filter($grants[$appId][$key], fn (string $v) => $v !== $id));
		if ($grants[$appId]['users'] === [] && $grants[$appId]['groups'] === []) {
			unset($grants[$appId]);
		}
		$this->saveUpdateGrants($grants);
	}

	private function assertGrantArgs(string $appId, string $type, string $id): void {
		if (preg_match(self::APPID_PATTERN, $appId) !== 1) {
			throw new \InvalidArgumentException("Invalid app ID '{$appId}'.");
		}
		if ($type !== 'user' && $type !== 'group') {
			throw new \InvalidArgumentException("Invalid type '{$type}', expected 'user' or 'group'.");
		}
		if ($id === '') {
			throw new \InvalidArgumentException('Missing user or group ID.');
		}
	}

	/**
	 * @param array<string, array{users: string[], groups: string[]}> $grants
	 */
	private function saveUpdateGrants(array $grants): void {
		$this->config->setAppValue(self::APP_ID, self::KEY_UPDATE_GRANTS, json_encode((object)$grants));
	}

	/**
	 * @return string[]
	 */
	public function getAllowedUsers(): array {
		$json = $this->config->getAppValue(self::APP_ID, self::KEY_ALLOWED_USERS, '[]');
		$users = json_decode($json, true);
		return is_array($users) ? $users : [];
	}

	public function addUser(string $userId): void {
		$users = $this->getAllowedUsers();
		if (!in_array($userId, $users, true)) {
			$users[] = $userId;
			$this->config->setAppValue(self::APP_ID, self::KEY_ALLOWED_USERS, json_encode(array_values($users)));
		}
	}

	public function removeUser(string $userId): void {
		$users = $this->getAllowedUsers();
		$users = array_filter($users, fn (string $u) => $u !== $userId);
		$this->config->setAppValue(self::APP_ID, self::KEY_ALLOWED_USERS, json_encode(array_values($users)));
	}

	/**
	 * @return string[]
	 */
	public function getAllowedGroups(): array {
		$json = $this->config->getAppValue(self::APP_ID, self::KEY_ALLOWED_GROUPS, '[]');
		$groups = json_decode($json, true);
		return is_array($groups) ? $groups : [];
	}

	public function addGroup(string $groupId): void {
		$groups = $this->getAllowedGroups();
		if (!in_array($groupId, $groups, true)) {
			$groups[] = $groupId;
			$this->config->setAppValue(self::APP_ID, self::KEY_ALLOWED_GROUPS, json_encode(array_values($groups)));
		}
	}

	public function removeGroup(string $groupId): void {
		$groups = $this->getAllowedGroups();
		$groups = array_filter($groups, fn (string $g) => $g !== $groupId);
		$this->config->setAppValue(self::APP_ID, self::KEY_ALLOWED_GROUPS, json_encode(array_values($groups)));
	}
}
