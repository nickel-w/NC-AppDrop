<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AppDrop\Service;

/**
 * Decides whether a user with per-app update permission may apply a package.
 *
 * Such users may only replace an app that is already installed, that they
 * were granted, with a strictly newer version whose requirements (Nextcloud,
 * PHP, PHP extensions) this server meets. Installing new apps is never allowed.
 */
class UpdateEligibilityService {
	public function __construct(
		private readonly AppPathResolver $pathResolver,
		private readonly RequirementChecker $requirements,
	) {
	}

	/**
	 * @param \SimpleXMLElement $xml info.xml of the uploaded package
	 * @param string[] $allowedAppIds apps the user may update
	 * @return list<array{label: string, detail: string}> empty when the update is allowed
	 */
	public function problems(\SimpleXMLElement $xml, array $allowedAppIds): array {
		$appId = trim((string)($xml->id ?? ''));
		$version = trim((string)($xml->version ?? ''));

		if ($appId === '' || !in_array($appId, $allowedAppIds, true)) {
			$allowed = $allowedAppIds === [] ? 'none' : implode(', ', $allowedAppIds);
			return [[
				'label' => 'Update permission',
				'detail' => "You are not allowed to update '{$appId}'. Apps you may update: {$allowed}.",
			]];
		}

		$installedVersion = $this->getInstalledVersion($appId);
		if ($installedVersion === null) {
			return [[
				'label' => 'Update permission',
				'detail' => "'{$appId}' is not installed. You may only update installed apps, not install new ones.",
			]];
		}

		$problems = [];
		if ($version === '' || version_compare($version, $installedVersion, '<=')) {
			$problems[] = [
				'label' => 'App version',
				'detail' => "Package version '{$version}' is not newer than the installed version {$installedVersion}.",
			];
		}

		foreach ($this->requirements->unmet($xml) as $message) {
			$problems[] = ['label' => 'Requirements', 'detail' => $message];
		}

		return $problems;
	}

	/**
	 * Version of the app currently on disk in AppDrop's apps path, or null if
	 * it is not installed there.
	 */
	public function getInstalledVersion(string $appId): ?string {
		try {
			$infoPath = $this->pathResolver->resolveWritablePath() . '/' . $appId . '/appinfo/info.xml';
		} catch (AppInstallException) {
			return null;
		}
		if (!is_file($infoPath)) {
			return null;
		}

		$content = file_get_contents($infoPath);
		if ($content === false) {
			return null;
		}

		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($content);
		if ($xml === false || trim((string)($xml->id ?? '')) !== $appId) {
			return null;
		}

		$version = trim((string)($xml->version ?? ''));
		return $version !== '' ? $version : '0.0.0';
	}
}
