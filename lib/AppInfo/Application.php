<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AppDrop\AppInfo;

use OCA\AppDrop\Service\PermissionService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Application bootstrap for appdrop.
 *
 * Navigation is registered only when the current user has upload permission.
 * We avoid using a closure with INavigationManager::add() because NC 32's
 * NavigationManager crashes if the closure returns null or an empty array
 * (it tries to access $entry['id'] unconditionally). Instead, we check
 * permissions at boot() time and skip registration entirely if denied.
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'appdrop';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
		$server = $context->getServerContainer();

		try {
			$user = $server->get(IUserSession::class)->getUser();
			if ($user === null) {
				return;
			}

			$permissionService = $server->get(PermissionService::class);
			if (!$permissionService->canAccess($user)) {
				return;
			}
		} catch (\Throwable) {
			return;
		}

		$urlGenerator = $server->get(IURLGenerator::class);

		$server->get(INavigationManager::class)->add([
			'id' => self::APP_ID,
			'name' => 'AppDrop',
			'href' => $urlGenerator->linkToRoute('appdrop.admin.index'),
			'icon' => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),
			'order' => 90,
		]);
	}
}
