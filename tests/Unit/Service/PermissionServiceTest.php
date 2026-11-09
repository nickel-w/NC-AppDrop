<?php

declare(strict_types=1);

namespace OCA\AppDrop\Tests\Unit\Service;

use OCA\AppDrop\Service\PermissionService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PermissionServiceTest extends TestCase {
	private IConfig&MockObject $config;
	private IGroupManager&MockObject $groupManager;
	private PermissionService $service;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->service = new PermissionService($this->config, $this->groupManager);
	}

	// =========================================================================
	// canUpload()
	// =========================================================================

	public function testCanUploadReturnsFalseForNullUser(): void {
		$this->assertFalse($this->service->canUpload(null));
	}

	public function testCanUploadReturnsTrueForAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->groupManager->method('isInGroup')
			->with('admin', 'admin')
			->willReturn(true);

		$this->assertTrue($this->service->canUpload($user));
	}

	public function testCanUploadReturnsTrueForAllowedUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->config->method('getAppValue')
			->willReturnMap([
				['appdrop', 'allowed_users', '[]', '["bob"]'],
				['appdrop', 'allowed_groups', '[]', '[]'],
			]);

		$this->assertTrue($this->service->canUpload($user));
	}

	public function testCanUploadReturnsTrueForAllowedGroup(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('carol');
		$this->groupManager->method('isInGroup')
			->willReturnMap([
				['carol', 'admin', false],
				['carol', 'editors', true],
			]);
		$this->config->method('getAppValue')
			->willReturnMap([
				['appdrop', 'allowed_users', '[]', '[]'],
				['appdrop', 'allowed_groups', '[]', '["editors"]'],
			]);

		$this->assertTrue($this->service->canUpload($user));
	}

	public function testCanUploadReturnsFalseForUnauthorizedUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('nobody');
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->config->method('getAppValue')
			->willReturnMap([
				['appdrop', 'allowed_users', '[]', '[]'],
				['appdrop', 'allowed_groups', '[]', '[]'],
			]);

		$this->assertFalse($this->service->canUpload($user));
	}

	// =========================================================================
	// getAllowedUsers() / addUser() / removeUser()
	// =========================================================================

	public function testGetAllowedUsersReturnsEmptyArrayByDefault(): void {
		$this->config->method('getAppValue')
			->with('appdrop', 'allowed_users', '[]')
			->willReturn('[]');

		$this->assertSame([], $this->service->getAllowedUsers());
	}

	public function testGetAllowedUsersReturnsListFromConfig(): void {
		$this->config->method('getAppValue')
			->with('appdrop', 'allowed_users', '[]')
			->willReturn('["alice","bob"]');

		$this->assertSame(['alice', 'bob'], $this->service->getAllowedUsers());
	}

	public function testGetAllowedUsersHandlesCorruptJson(): void {
		$this->config->method('getAppValue')
			->with('appdrop', 'allowed_users', '[]')
			->willReturn('not-json');

		$this->assertSame([], $this->service->getAllowedUsers());
	}

	public function testAddUserPersistsToConfig(): void {
		$this->config->method('getAppValue')
			->with('appdrop', 'allowed_users', '[]')
			->willReturn('["alice"]');

		$this->config->expects($this->once())
			->method('setAppValue')
			->with('appdrop', 'allowed_users', '["alice","bob"]');

		$this->service->addUser('bob');
	}

	public function testAddUserDoesNotDuplicate(): void {
		$this->config->method('getAppValue')
			->with('appdrop', 'allowed_users', '[]')
			->willReturn('["alice"]');

		$this->config->expects($this->never())
			->method('setAppValue');

		$this->service->addUser('alice');
	}

	public function testRemoveUserPersistsToConfig(): void {
		$this->config->method('getAppValue')
			->with('appdrop', 'allowed_users', '[]')
			->willReturn('["alice","bob"]');

		$this->config->expects($this->once())
			->method('setAppValue')
			->with('appdrop', 'allowed_users', '["alice"]');

		$this->service->removeUser('bob');
	}

	// =========================================================================
	// getAllowedGroups() / addGroup() / removeGroup()
	// =========================================================================

	public function testGetAllowedGroupsReturnsEmptyArrayByDefault(): void {
		$this->config->method('getAppValue')
			->with('appdrop', 'allowed_groups', '[]')
			->willReturn('[]');

		$this->assertSame([], $this->service->getAllowedGroups());
	}

	public function testAddGroupPersistsToConfig(): void {
		$this->config->method('getAppValue')
			->with('appdrop', 'allowed_groups', '[]')
			->willReturn('[]');

		$this->config->expects($this->once())
			->method('setAppValue')
			->with('appdrop', 'allowed_groups', '["editors"]');

		$this->service->addGroup('editors');
	}

	public function testAddGroupDoesNotDuplicate(): void {
		$this->config->method('getAppValue')
			->with('appdrop', 'allowed_groups', '[]')
			->willReturn('["editors"]');

		$this->config->expects($this->never())
			->method('setAppValue');

		$this->service->addGroup('editors');
	}

	public function testRemoveGroupPersistsToConfig(): void {
		$this->config->method('getAppValue')
			->with('appdrop', 'allowed_groups', '[]')
			->willReturn('["editors","viewers"]');

		$this->config->expects($this->once())
			->method('setAppValue')
			->with('appdrop', 'allowed_groups', '["viewers"]');

		$this->service->removeGroup('editors');
	}

	// =========================================================================
	// Per-app update grants
	// =========================================================================

	private function withGrants(string $json): void {
		$this->config->method('getAppValue')->willReturnMap([
			['appdrop', 'allowed_users', '[]', '[]'],
			['appdrop', 'allowed_groups', '[]', '[]'],
			['appdrop', 'update_grants', '{}', $json],
		]);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	public function testUpdatableAppsViaUserAndGroup(): void {
		$this->withGrants('{"zeta":{"users":["dave"],"groups":[]},"alpha":{"users":[],"groups":["editors"]},"other":{"users":["erin"],"groups":["ops"]}}');
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid) => $uid === 'dave' && $gid === 'editors',
		);

		$this->assertSame(['alpha', 'zeta'], $this->service->getUpdatableApps($this->user('dave')));
		$this->assertTrue($this->service->canUpdateApp($this->user('dave'), 'zeta'));
		$this->assertFalse($this->service->canUpdateApp($this->user('dave'), 'other'));
		$this->assertTrue($this->service->canAccess($this->user('dave')));
		$this->assertFalse($this->service->canUpload($this->user('dave')), 'Update grants must not grant full upload permission');
	}

	public function testNoGrantsMeansNoAccess(): void {
		$this->withGrants('{}');
		$this->groupManager->method('isInGroup')->willReturn(false);

		$this->assertSame([], $this->service->getUpdatableApps($this->user('nobody')));
		$this->assertFalse($this->service->canAccess($this->user('nobody')));
		$this->assertSame([], $this->service->getUpdatableApps(null));
	}

	public function testCorruptGrantsAreIgnored(): void {
		$this->withGrants('not json');
		$this->assertSame([], $this->service->getUpdateGrants());
	}

	public function testAddUpdateGrantStoresEntry(): void {
		$this->withGrants('{"alpha":{"users":["dave"],"groups":[]}}');
		$this->config->expects($this->once())->method('setAppValue')->with(
			'appdrop',
			'update_grants',
			'{"alpha":{"users":["dave"],"groups":["editors"]}}',
		);

		$this->service->addUpdateGrant('alpha', 'group', 'editors');
	}

	public function testRemovingLastEntryDropsTheApp(): void {
		$this->withGrants('{"alpha":{"users":["dave"],"groups":[]}}');
		$this->config->expects($this->once())->method('setAppValue')->with('appdrop', 'update_grants', '{}');

		$this->service->removeUpdateGrant('alpha', 'user', 'dave');
	}

	public function testAddUpdateGrantRejectsInvalidInput(): void {
		$this->withGrants('{}');
		$this->config->expects($this->never())->method('setAppValue');

		foreach ([['Bad-App', 'user', 'x'], ['alpha', 'admin', 'x'], ['alpha', 'user', '']] as [$app, $type, $id]) {
			try {
				$this->service->addUpdateGrant($app, $type, $id);
				$this->fail("Expected rejection for {$app}/{$type}/{$id}");
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}
}
