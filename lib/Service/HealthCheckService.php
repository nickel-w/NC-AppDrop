<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AppDrop\Service;

/**
 * Pre-install health check: validates a zip before installation.
 * Returns a structured report with individual check results.
 *
 * Each check has: label (display name), status (pass|fail|warn), detail (explanation).
 */
class HealthCheckService {
	private const APPID_PATTERN = '/^[a-z0-9_]{3,64}$/';
	private const NAMESPACE_PATTERN = '/^[A-Z][a-zA-Z0-9]*$/';

	public function __construct(
		private readonly RequirementChecker $requirements,
		private readonly UpdateEligibilityService $eligibility,
	) {
	}

	/**
	 * Analyze a zip file and return a health report.
	 *
	 * @param string $zipPath Path to the temporary zip file
	 * @param string[]|null $updateOnlyApps null for users with full upload permission;
	 *                                      otherwise the apps the user may update (adds update-permission checks)
	 * @return array{checks: array[], errors: string[], warnings: string[], appId: string, version: string, name: string, icon: ?string}
	 */
	public function analyze(string $zipPath, ?array $updateOnlyApps = null): array {
		$checks = [];
		$errors = [];
		$warnings = [];
		$appId = '';
		$version = '';
		$name = '';

		$zip = new \ZipArchive();
		if ($zip->open($zipPath) !== true) {
			$checks[] = ['label' => 'Zip Archive', 'status' => 'fail', 'detail' => 'Cannot open the zip archive.', 'fix' => 'Ensure the file is a valid .zip archive. Try re-downloading or re-exporting it.'];
			return $this->buildResult($checks, ['Cannot open the zip archive.'], [], '', '', '', null);
		}
		$checks[] = ['label' => 'Zip Archive', 'status' => 'pass', 'detail' => 'Archive opened successfully.'];

		// ── Scan all entries ─────────────────────────────────────────────────
		$topLevelPrefix = '';
		$entries = [];
		$relativeEntries = [];
		$infoXmlContent = null;
		$iconIndex = null;
		$iconExt = '';
		$securityOk = true;
		$applicationPhpContent = null;
		$routesPhpContent = null;

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entryName = $zip->getNameIndex($i);
			if ($entryName === false) {
				continue;
			}
			$normalised = str_replace('\\', '/', $entryName);
			$entries[] = $normalised;

			if ($i === 0 && str_ends_with($normalised, '/') && substr_count($normalised, '/') === 1) {
				$topLevelPrefix = $normalised;
			}

			// Security checks
			if (str_starts_with($normalised, '/')) {
				$errors[] = "Security: absolute path in entry '{$entryName}'.";
				$securityOk = false;
			}
			foreach (explode('/', $normalised) as $part) {
				if ($part === '..') {
					$errors[] = "Security: directory traversal in entry '{$entryName}'.";
					$securityOk = false;
					break;
				}
			}

			$relative = $normalised;
			if ($topLevelPrefix !== '' && str_starts_with($normalised, $topLevelPrefix)) {
				$relative = substr($normalised, strlen($topLevelPrefix));
			}
			$relativeEntries[] = $relative;

			if ($relative === 'appinfo/info.xml') {
				$infoXmlContent = $zip->getFromIndex($i);
			}
			if ($relative === 'lib/AppInfo/Application.php') {
				$applicationPhpContent = $zip->getFromIndex($i);
			}
			if ($relative === 'appinfo/routes.php') {
				$routesPhpContent = $zip->getFromIndex($i);
			}
			if ($iconIndex === null && preg_match('#^img/app\.(svg|png|jpg|jpeg|gif)$#i', $relative, $iconMatch)) {
				$iconIndex = $i;
				$iconExt = strtolower($iconMatch[1]);
			}
		}

		// ── Security ─────────────────────────────────────────────────────────
		if ($securityOk) {
			$checks[] = ['label' => 'Security (Zip Slip)', 'status' => 'pass', 'detail' => 'No malicious paths detected.'];
		} else {
			$checks[] = ['label' => 'Security (Zip Slip)', 'status' => 'fail', 'detail' => 'Dangerous paths found in archive.', 'fix' => 'The zip contains absolute paths or "../" traversals. Re-create the archive from the app directory without path manipulation.'];
		}

		// ── Icon ─────────────────────────────────────────────────────────────
		$iconData = null;
		if ($iconIndex !== null) {
			$iconRaw = $zip->getFromIndex($iconIndex);
			if ($iconRaw !== false && $iconRaw !== '') {
				$mimeMap = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];
				$iconData = 'data:' . ($mimeMap[$iconExt] ?? 'image/png') . ';base64,' . base64_encode($iconRaw);
			}
		}
		if ($iconData !== null) {
			$checks[] = ['label' => 'App Icon', 'status' => 'pass', 'detail' => 'Icon found (img/app.' . $iconExt . ').'];
		} else {
			$checks[] = ['label' => 'App Icon', 'status' => 'warn', 'detail' => 'No icon found. A placeholder will be generated.', 'fix' => 'Add an img/app.svg (recommended) or img/app.png file to your app.'];
			$warnings[] = 'No app icon found.';
		}

		$zip->close();

		// ── info.xml exists ──────────────────────────────────────────────────
		if ($infoXmlContent === false || $infoXmlContent === null) {
			$checks[] = ['label' => 'appinfo/info.xml', 'status' => 'fail', 'detail' => 'Missing. Not a valid Nextcloud app package.', 'fix' => 'Every Nextcloud app must have an appinfo/info.xml file. Create one with at least <id>, <name>, <version> and <namespace> elements.'];
			$errors[] = "Missing 'appinfo/info.xml'.";
			return $this->buildResult($checks, $errors, $warnings, '', '', '', $iconData);
		}
		$checks[] = ['label' => 'appinfo/info.xml', 'status' => 'pass', 'detail' => 'File found in package.'];

		// ── info.xml is valid XML ────────────────────────────────────────────
		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($infoXmlContent);
		if ($xml === false) {
			$checks[] = ['label' => 'XML Parsing', 'status' => 'fail', 'detail' => 'info.xml is not valid XML.', 'fix' => 'Check for syntax errors: unclosed tags, invalid characters, or encoding issues. Validate with an XML linter.'];
			$errors[] = 'info.xml is not valid XML.';
			return $this->buildResult($checks, $errors, $warnings, '', '', '', $iconData);
		}
		$checks[] = ['label' => 'XML Parsing', 'status' => 'pass', 'detail' => 'info.xml parsed successfully.'];

		// ── App ID ───────────────────────────────────────────────────────────
		$appId = trim((string)($xml->id ?? ''));
		if ($appId === '') {
			$checks[] = ['label' => 'App ID (<id>)', 'status' => 'fail', 'detail' => 'Missing <id> element in info.xml.', 'fix' => 'Add <id>myapp</id> to info.xml. Use only lowercase letters, digits and underscores (3-64 chars).'];
			$errors[] = 'info.xml is missing the <id> element.';
		} elseif (preg_match(self::APPID_PATTERN, $appId) !== 1) {
			$checks[] = ['label' => 'App ID (<id>)', 'status' => 'fail', 'detail' => "Invalid: '{$appId}'. Must be lowercase letters, digits, underscores (3-64 chars).", 'fix' => 'Rename the <id> to use only a-z, 0-9 and _ (e.g. my_app). Must be 3-64 characters.'];
			$errors[] = "Invalid app ID '{$appId}'.";
		} else {
			$checks[] = ['label' => 'App ID (<id>)', 'status' => 'pass', 'detail' => $appId];
		}

		// ── Self-update protection ───────────────────────────────────────────
		if ($appId === 'appdrop') {
			$checks[] = ['label' => 'Self-update Protection', 'status' => 'fail', 'detail' => 'Cannot update AppDrop through itself.', 'fix' => 'Update AppDrop via occ (php occ app:update appdrop), the Nextcloud app store, or by replacing the files manually with SSH.'];
			$errors[] = 'Cannot update AppDrop through itself.';
		}

		// ── App ID matches directory ─────────────────────────────────────────
		if ($appId !== '' && $topLevelPrefix !== '') {
			$dirName = rtrim($topLevelPrefix, '/');
			if ($dirName === $appId) {
				$checks[] = ['label' => 'Directory matches ID', 'status' => 'pass', 'detail' => "Directory '{$dirName}' matches app ID."];
			} else {
				$checks[] = ['label' => 'Directory matches ID', 'status' => 'warn', 'detail' => "Directory '{$dirName}' differs from app ID '{$appId}'.", 'fix' => "Rename the zip root folder from '{$dirName}/' to '{$appId}/' so it matches the <id> in info.xml."];
				$warnings[] = "Directory name '{$dirName}' differs from app ID '{$appId}'.";
			}
		}

		// ── App Name ─────────────────────────────────────────────────────────
		$name = trim((string)($xml->name ?? ''));
		if ($name === '') {
			$checks[] = ['label' => 'App Name (<name>)', 'status' => 'warn', 'detail' => 'Missing. The app ID will be used as display name.', 'fix' => 'Add <name>My App</name> to info.xml for a human-readable display name.'];
			$warnings[] = 'No <name> in info.xml.';
			$name = $appId;
		} else {
			$checks[] = ['label' => 'App Name (<name>)', 'status' => 'pass', 'detail' => $name];
		}

		// ── Version ──────────────────────────────────────────────────────────
		$version = trim((string)($xml->version ?? ''));
		if ($version === '') {
			$checks[] = ['label' => 'Version (<version>)', 'status' => 'warn', 'detail' => 'Missing. Defaults to 0.0.0.', 'fix' => 'Add <version>1.0.0</version> to info.xml. Use semantic versioning (major.minor.patch).'];
			$warnings[] = 'No <version> in info.xml.';
			$version = '0.0.0';
		} else {
			$checks[] = ['label' => 'Version (<version>)', 'status' => 'pass', 'detail' => $version];
		}

		// ── Namespace ────────────────────────────────────────────────────────
		$namespace = trim((string)($xml->namespace ?? ''));
		if ($namespace === '') {
			$checks[] = ['label' => 'Namespace (<namespace>)', 'status' => 'warn', 'detail' => 'Missing. Controllers may not autoload correctly.', 'fix' => 'Add <namespace>MyApp</namespace> to info.xml. Must be PascalCase and match the PHP namespace in lib/ (OCA\\MyApp).'];
			$warnings[] = 'No <namespace> in info.xml.';
		} elseif (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
			$checks[] = ['label' => 'Namespace (<namespace>)', 'status' => 'warn', 'detail' => "'{$namespace}' is not PascalCase.", 'fix' => 'Use PascalCase for the namespace (e.g. MyApp, not myapp or my_app). It must match the PHP namespace OCA\\MyApp.'];
			$warnings[] = "Namespace '{$namespace}' is not PascalCase.";
		} else {
			$checks[] = ['label' => 'Namespace (<namespace>)', 'status' => 'pass', 'detail' => $namespace];
		}

		// ── Licence ──────────────────────────────────────────────────────────
		$licence = trim((string)($xml->licence ?? (string)($xml->license ?? '')));
		if ($licence === '') {
			$checks[] = ['label' => 'Licence (<licence>)', 'status' => 'warn', 'detail' => 'No licence declared.', 'fix' => 'Add <licence>agpl</licence> to info.xml. Common values: agpl, mit, apache.'];
			$warnings[] = 'No <licence> in info.xml.';
		} else {
			$checks[] = ['label' => 'Licence (<licence>)', 'status' => 'pass', 'detail' => $licence];
		}

		// ── Namespace consistency with Application.php ───────────────────────
		if ($namespace !== '' && $applicationPhpContent !== null && $applicationPhpContent !== false) {
			$expectedNs = 'namespace OCA\\' . $namespace . '\\AppInfo';
			if (str_contains($applicationPhpContent, $expectedNs)) {
				$checks[] = ['label' => 'Namespace in Application.php', 'status' => 'pass', 'detail' => "Matches OCA\\{$namespace}\\AppInfo."];
			} else {
				if (preg_match('/namespace\s+(OCA\\\\[A-Za-z0-9_\\\\]+)/', $applicationPhpContent, $nsMatch)) {
					$checks[] = ['label' => 'Namespace in Application.php', 'status' => 'fail', 'detail' => "Expected OCA\\{$namespace}\\AppInfo, found {$nsMatch[1]}.", 'fix' => "Change the namespace in Application.php to 'namespace OCA\\{$namespace}\\AppInfo;' or update <namespace> in info.xml to match."];
					$errors[] = 'Namespace mismatch in Application.php.';
				} else {
					$checks[] = ['label' => 'Namespace in Application.php', 'status' => 'warn', 'detail' => 'Could not detect namespace declaration.', 'fix' => "Ensure Application.php has 'namespace OCA\\{$namespace}\\AppInfo;' at the top."];
					$warnings[] = 'Could not verify namespace in Application.php.';
				}
			}
		}

		// ── Required/recommended files ───────────────────────────────────────
		$fileChecks = [
			'appinfo/routes.php' => ['required' => false, 'label' => 'appinfo/routes.php', 'fix' => 'Create appinfo/routes.php to define URL routes. Without it, the app will have no accessible pages.'],
			'lib/AppInfo/Application.php' => ['required' => false, 'label' => 'lib/AppInfo/Application.php', 'fix' => 'Create lib/AppInfo/Application.php extending OCP\\AppFramework\\App. This is the app bootstrap entry point.'],
		];

		foreach ($fileChecks as $file => $meta) {
			$found = in_array($file, $relativeEntries, true);
			if ($found) {
				$checks[] = ['label' => $meta['label'], 'status' => 'pass', 'detail' => 'File found.'];
			} else {
				$status = $meta['required'] ? 'fail' : 'warn';
				$checks[] = ['label' => $meta['label'], 'status' => $status, 'detail' => 'Not found in package.', 'fix' => $meta['fix']];
				if ($meta['required']) {
					$errors[] = "Required file '{$file}' missing.";
				} else {
					$warnings[] = "Recommended file '{$file}' not found.";
				}
			}
		}

		// ── Requirements: Nextcloud, PHP, PHP extensions ─────────────────────
		foreach ($this->requirements->evaluate($xml) as $req) {
			if ($req['key'] === 'nextcloud') {
				if (!$req['declared']) {
					$checks[] = ['label' => 'Nextcloud Compatibility', 'status' => 'warn', 'detail' => 'No Nextcloud version dependency declared.', 'fix' => 'Add <dependencies><nextcloud min-version="30" max-version="35"/></dependencies> to info.xml.'];
					$warnings[] = 'No Nextcloud version dependency declared.';
				} elseif ($req['ok']) {
					$checks[] = ['label' => 'Nextcloud Compatibility', 'status' => 'pass', 'detail' => $req['message']];
				} else {
					$checks[] = ['label' => 'Nextcloud Compatibility', 'status' => 'warn', 'detail' => $req['message'], 'fix' => 'Nextcloud will refuse to enable this app. Use a package built for this Nextcloud version, or adjust min-version/max-version in info.xml if your code supports it.'];
					$warnings[] = $req['message'];
				}
			} elseif ($req['key'] === 'php') {
				if (!$req['declared']) {
					$checks[] = ['label' => 'PHP Compatibility', 'status' => 'warn', 'detail' => 'No PHP version requirement declared.', 'fix' => 'Add <php min-version="8.1"/> inside <dependencies> in info.xml.'];
					$warnings[] = 'No PHP version requirement declared.';
				} elseif ($req['ok']) {
					$checks[] = ['label' => 'PHP Compatibility', 'status' => 'pass', 'detail' => $req['message']];
				} else {
					$checks[] = ['label' => 'PHP Compatibility', 'status' => 'fail', 'detail' => $req['message'], 'fix' => 'Change the PHP version on this server, or adjust the PHP min-version/max-version in info.xml if your code supports it.'];
					$errors[] = $req['message'];
				}
			} else {
				$label = 'PHP Extension (' . substr($req['key'], 4) . ')';
				if ($req['ok']) {
					$checks[] = ['label' => $label, 'status' => 'pass', 'detail' => $req['message']];
				} else {
					$checks[] = ['label' => $label, 'status' => 'warn', 'detail' => $req['message'], 'fix' => 'Install and enable the PHP extension on this server.'];
					$warnings[] = $req['message'];
				}
			}
		}

		// ── Per-app update permission (restricted users only) ────────────────
		if ($updateOnlyApps !== null) {
			$problems = $this->eligibility->problems($xml, $updateOnlyApps);
			if ($problems === []) {
				$installed = $this->eligibility->getInstalledVersion($appId) ?? '?';
				$checks[] = ['label' => 'Update permission', 'status' => 'pass', 'detail' => "You may update '{$appId}' from v{$installed} to v{$version}."];
			} else {
				foreach ($problems as $problem) {
					$checks[] = ['label' => $problem['label'], 'status' => 'fail', 'detail' => $problem['detail'], 'fix' => 'Your account may only update specific installed apps to a newer version that meets all requirements of this server. Ask an administrator if you need more.'];
					$errors[] = $problem['detail'];
				}
			}
		}

		return $this->buildResult($checks, $errors, $warnings, $appId, $version, $name, $iconData);
	}

	private function buildResult(array $checks, array $errors, array $warnings, string $appId, string $version, string $name, ?string $icon): array {
		return [
			'checks' => $checks,
			'errors' => $errors,
			'warnings' => $warnings,
			'appId' => $appId,
			'version' => $version,
			'name' => $name,
			'icon' => $icon,
		];
	}
}
