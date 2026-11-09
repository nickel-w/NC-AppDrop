<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AppDrop\Service;

use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Handles app package upload, validation and installation.
 *
 * The app ID is auto-detected from info.xml inside the zip.
 * If the app already exists, it is backed up and replaced.
 * Safety checks prevent installing a zip whose app ID does not match
 * the existing installation (prevents accidental overwrites).
 */
class AppInstallService {
	private const APP_ID = 'appdrop';
	private const DEFAULT_MAX_SIZE_MB = 20;
	private const APPID_PATTERN = '/^[a-z0-9_]{3,64}$/';
	private const ALLOWED_MIME_TYPES = [
		'application/zip',
		'application/x-zip',
		'application/x-zip-compressed',
		'application/octet-stream',
	];

	public function __construct(
		private readonly AppPathResolver $pathResolver,
		private readonly ITempManager $tempManager,
		private readonly IAppManager $appManager,
		private readonly IConfig $config,
		private readonly AppUpgradeService $upgrader,
		private readonly LoggerInterface $logger,
		private readonly UpdateEligibilityService $eligibility,
	) {
	}

	private function getMaxSizeBytes(): int {
		$mb = (int)$this->config->getAppValue(self::APP_ID, 'max_upload_size_mb', (string)self::DEFAULT_MAX_SIZE_MB);
		return max(1, $mb) * 1024 * 1024;
	}

	/**
	 * Install or update an app from an uploaded zip file.
	 *
	 * @param array $file $_FILES-style array from IRequest::getUploadedFile()
	 * @param string[]|null $updateOnlyApps null = unrestricted. Otherwise only an
	 *                                      update of one of these installed apps to a newer version whose
	 *                                      requirements are met is accepted, and the app's enabled state is kept.
	 * @return array{success: bool, message: string, appId: string, version: string, name: string, isUpdate: bool, previousVersion: ?string}
	 */
	public function install(array $file, bool $autoEnable = true, ?array $updateOnlyApps = null): array {
		$this->validateUploadedFile($file);
		$tempPath = $this->storeToTemp($file);

		try {
			// Validate zip structure + extract app metadata from info.xml
			$zipInfo = $this->validateAndAnalyzeZip($tempPath);
			$appId = $zipInfo['appId'];

			// Prevent self-update: installing appdrop over itself can corrupt the running app
			if ($appId === self::APP_ID) {
				throw new AppInstallException(
					'Cannot update AppDrop through itself. '
					. 'Please update via occ, the Nextcloud app store, or by replacing the files manually.',
				);
			}

			if ($updateOnlyApps !== null) {
				// Users with per-app update permission must not change which apps are enabled.
				$autoEnable = false;
				$xml = simplexml_load_string($zipInfo['infoXml']);
				if ($xml === false) {
					throw new AppInstallException('Could not parse info.xml from the zip archive.');
				}
				$problems = $this->eligibility->problems($xml, $updateOnlyApps);
				if ($problems !== []) {
					throw new AppInstallException(
						'Update not allowed: ' . implode(' ', array_column($problems, 'detail')),
					);
				}
			}

			$basePath = $this->pathResolver->resolveWritablePath();
			$targetPath = $basePath . '/' . $appId;

			// Check for existing installation
			$isUpdate = is_dir($targetPath);
			$previousVersion = null;

			if ($isUpdate) {
				$existing = $this->readInfoXmlSafe($targetPath . '/appinfo/info.xml');
				$previousVersion = $existing['version'];

				// Safety: prevent overwriting a different app
				if ($existing['appId'] !== '' && $existing['appId'] !== $appId) {
					throw new AppInstallException(
						"Safety check failed: the target directory already contains app '{$existing['appId']}' "
						. "but the uploaded zip contains app '{$appId}'. "
						. 'App IDs must match for updates.',
					);
				}

				$this->backupDirectory($targetPath);
			}

			$this->extractZip($tempPath, $targetPath, $zipInfo['topLevelPrefix']);
			$this->ensureAppIcon($targetPath, $appId);
			$this->chmodRecursive($targetPath);
			// Update, or re-install after a remove (neither AppDrop nor `occ app:remove`
			// clears installed_version): bring the DB in line with the new code.
			$this->upgrader->reconcile($appId, $targetPath);
			if ($autoEnable) {
				$this->enableApp($appId);
			}
		} finally {
			if (file_exists($tempPath)) {
				@unlink($tempPath);
			}
		}

		$message = $isUpdate
			? "App '{$zipInfo['name']}' updated successfully (v{$previousVersion} → v{$zipInfo['version']})."
			: "App '{$zipInfo['name']}' (v{$zipInfo['version']}) installed and enabled successfully.";

		return [
			'success' => true,
			'message' => $message,
			'appId' => $appId,
			'version' => $zipInfo['version'],
			'name' => $zipInfo['name'],
			'isUpdate' => $isUpdate,
			'previousVersion' => $previousVersion,
		];
	}

	// =========================================================================
	// File validation
	// =========================================================================

	private function validateUploadedFile(array $file): void {
		if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
			throw new AppInstallException('File upload error (code ' . $file['error'] . ').');
		}

		$name = $file['name'] ?? '';
		if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
			throw new AppInstallException('Only .zip files are accepted.');
		}

		$tmpPath = $file['tmp_name'] ?? '';
		if (!is_file($tmpPath)) {
			throw new AppInstallException('Uploaded temporary file not found.');
		}

		$maxBytes = $this->getMaxSizeBytes();
		$size = filesize($tmpPath);
		if ($size === false || $size > $maxBytes) {
			throw new AppInstallException(
				sprintf('File too large. Maximum permitted size is %d MB.', $maxBytes / 1024 / 1024),
			);
		}

		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$mime = $finfo->file($tmpPath);
		if ($mime === false || !in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
			throw new AppInstallException("Invalid file type (detected: '{$mime}'). Only zip archives are accepted.");
		}
	}

	// =========================================================================
	// Temp storage
	// =========================================================================

	private function storeToTemp(array $file): string {
		$tmpPath = $this->tempManager->getTemporaryFile('.zip');
		if ($tmpPath === false || $tmpPath === null) {
			throw new AppInstallException('Could not create a temporary file.');
		}

		if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
			if (!copy($file['tmp_name'], $tmpPath)) {
				throw new AppInstallException('Failed to store uploaded file.');
			}
		}

		return $tmpPath;
	}

	// =========================================================================
	// Zip analysis + validation (combined for single-pass efficiency)
	// =========================================================================

	/**
	 * Validate all zip entries (Zip Slip protection) and extract app info
	 * from the embedded appinfo/info.xml.
	 *
	 * @return array{appId: string, version: string, name: string, topLevelPrefix: string, infoXml: string}
	 */
	private function validateAndAnalyzeZip(string $zipPath): array {
		$zip = new \ZipArchive();
		if ($zip->open($zipPath) !== true) {
			throw new AppInstallException('Cannot open the zip archive.');
		}

		$topLevelPrefix = '';
		$infoXmlContent = null;

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false) {
				$zip->close();
				throw new AppInstallException("Could not read entry #{$i} from zip.");
			}

			$normalised = str_replace('\\', '/', $name);

			// Zip Slip: reject absolute paths
			if (str_starts_with($normalised, '/')) {
				$zip->close();
				throw new AppInstallException("Security violation: absolute path in zip entry '{$name}'.");
			}

			// Zip Slip: reject directory traversal
			foreach (explode('/', $normalised) as $part) {
				if ($part === '..') {
					$zip->close();
					throw new AppInstallException("Security violation: directory traversal in zip entry '{$name}'.");
				}
			}

			// Detect top-level wrapper directory from first entry
			if ($i === 0 && str_ends_with($normalised, '/') && substr_count($normalised, '/') === 1) {
				$topLevelPrefix = $normalised;
			}

			// Compute relative path (stripping top-level wrapper if present)
			$relativePath = $normalised;
			if ($topLevelPrefix !== '' && str_starts_with($normalised, $topLevelPrefix)) {
				$relativePath = substr($normalised, strlen($topLevelPrefix));
			}

			if ($relativePath === 'appinfo/info.xml') {
				$infoXmlContent = $zip->getFromIndex($i);
			}
		}

		$zip->close();

		if ($infoXmlContent === false || $infoXmlContent === null) {
			throw new AppInstallException(
				"The zip does not contain 'appinfo/info.xml'. This is not a valid Nextcloud app package.",
			);
		}

		$info = $this->parseInfoXml($infoXmlContent);
		$info['topLevelPrefix'] = $topLevelPrefix;
		$info['infoXml'] = $infoXmlContent;

		return $info;
	}

	// =========================================================================
	// info.xml parsing
	// =========================================================================

	/**
	 * Parse info.xml content and extract app ID, version and name.
	 */
	private function parseInfoXml(string $content): array {
		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($content);
		if ($xml === false) {
			throw new AppInstallException('Could not parse info.xml from the zip archive.');
		}

		$appId = trim((string)($xml->id ?? ''));
		if ($appId === '') {
			throw new AppInstallException('info.xml is missing the required <id> element.');
		}

		if (preg_match(self::APPID_PATTERN, $appId) !== 1) {
			throw new AppInstallException(
				"Invalid app ID '{$appId}' in info.xml. Only lowercase letters, digits and underscores (3–64 chars).",
			);
		}

		return [
			'appId' => $appId,
			'version' => trim((string)($xml->version ?? '0.0.0')),
			'name' => trim((string)($xml->name ?? $appId)),
		];
	}

	/**
	 * Read info.xml from an existing installed app (non-throwing).
	 */
	private function readInfoXmlSafe(string $path): array {
		if (!file_exists($path)) {
			return ['appId' => '', 'version' => 'unknown', 'name' => ''];
		}

		$content = file_get_contents($path);
		if ($content === false) {
			return ['appId' => '', 'version' => 'unknown', 'name' => ''];
		}

		try {
			return $this->parseInfoXml($content);
		} catch (AppInstallException) {
			return ['appId' => '', 'version' => 'unknown', 'name' => ''];
		}
	}

	// =========================================================================
	// Backup
	// =========================================================================

	private function backupDirectory(string $path): void {
		$backup = $path . '_backup_' . date('Ymd_His');
		$this->logger->info("[AppDrop] Backing up '{$path}' to '{$backup}'.");

		if (!rename($path, $backup)) {
			$this->logger->warning('[AppDrop] Rename failed; removing old installation.');
			$this->removeDirectory($path);
		}
	}

	private function removeDirectory(string $path): void {
		if (!is_dir($path)) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);

		foreach ($items as $item) {
			if ($item->isDir() && !$item->isLink()) {
				rmdir($item->getRealPath());
			} else {
				unlink($item->getRealPath());
			}
		}

		rmdir($path);
	}

	// =========================================================================
	// Extraction
	// =========================================================================

	private function extractZip(string $zipPath, string $targetPath, string $topLevelPrefix): void {
		$zip = new \ZipArchive();
		if ($zip->open($zipPath) !== true) {
			throw new AppInstallException('Failed to reopen zip for extraction.');
		}

		if (!is_dir($targetPath) && !mkdir($targetPath, 0750, true)) {
			$zip->close();
			throw new AppInstallException("Could not create directory '{$targetPath}'.");
		}

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false) {
				continue;
			}

			$normalised = str_replace('\\', '/', $name);
			$relative = $normalised;

			if ($topLevelPrefix !== '' && str_starts_with($normalised, $topLevelPrefix)) {
				$relative = substr($normalised, strlen($topLevelPrefix));
			}

			if ($relative === '' || $relative === '/') {
				continue;
			}

			// Defence-in-depth
			if (str_contains($relative, '..')) {
				$zip->close();
				throw new AppInstallException("Traversal in entry '{$name}'.");
			}

			$dest = $targetPath . '/' . $relative;

			if (str_ends_with($relative, '/')) {
				if (!is_dir($dest) && !mkdir($dest, 0750, true)) {
					$zip->close();
					throw new AppInstallException("Could not create directory '{$dest}'.");
				}
			} else {
				$parent = dirname($dest);
				if (!is_dir($parent) && !mkdir($parent, 0750, true)) {
					$zip->close();
					throw new AppInstallException("Could not create directory '{$parent}'.");
				}

				$stream = $zip->getStream($name);
				if ($stream === false) {
					$zip->close();
					throw new AppInstallException("Could not read entry '{$name}'.");
				}

				$written = file_put_contents($dest, $stream);
				fclose($stream);

				if ($written === false) {
					$zip->close();
					throw new AppInstallException("Could not write '{$dest}'.");
				}
			}
		}

		$zip->close();
	}

	// =========================================================================
	// Enable app
	// =========================================================================

	/**
	 * Enable the app after extraction.
	 *
	 * For updates (app already known), IAppManager works directly.
	 * For new installs, the in-memory app cache was built at boot and doesn't
	 * include the newly extracted app. We fall back to running occ in a
	 * subprocess which forces a fresh filesystem scan.
	 */
	private function enableApp(string $appId): void {
		// Try native API first (works for updates of already-known apps)
		try {
			$this->appManager->enableApp($appId);
			return;
		} catch (\Throwable $e) {
			$this->logger->info('[AppDrop] Native enableApp failed, falling back to occ: ' . $e->getMessage());
		}

		// Fallback: occ in a subprocess (fresh cache scan)
		$occ = \OC::$SERVERROOT . '/occ';
		$escapedAppId = escapeshellarg($appId);
		$escapedOcc = escapeshellarg($occ);

		exec("php {$escapedOcc} app:enable {$escapedAppId} 2>&1", $output, $exitCode);

		if ($exitCode !== 0) {
			$msg = implode(' ', $output);
			$this->logger->warning("[AppDrop] occ app:enable failed: {$msg}");
			throw new AppInstallException(
				'App files were installed successfully but could not be enabled automatically. '
				. "Please enable '{$appId}' manually in Settings → Apps.",
			);
		}
	}

	// =========================================================================
	// Icon generation
	// =========================================================================

	/**
	 * If the app has no icon (img/app.svg or img/app.png), generate a
	 * placeholder SVG with the first letter of the app ID.
	 */
	private function ensureAppIcon(string $appPath, string $appId): void {
		$imgDir = $appPath . '/img';
		$iconFiles = ['app.svg', 'app.png', 'app.jpg', 'app.jpeg', 'app.gif'];

		foreach ($iconFiles as $iconFile) {
			if (file_exists($imgDir . '/' . $iconFile)) {
				return; // Icon already exists
			}
		}

		// Generate a placeholder SVG
		if (!is_dir($imgDir)) {
			@mkdir($imgDir, 0750, true);
		}

		$letter = strtoupper(substr($appId, 0, 1));
		$hue = (crc32($appId) % 360 + 360) % 360;
		$svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32">
  <rect width="32" height="32" rx="6" fill="hsl({$hue}, 50%, 45%)"/>
  <text x="16" y="22" text-anchor="middle" fill="#fff" font-family="sans-serif" font-size="18" font-weight="700">{$letter}</text>
</svg>
SVG;

		@file_put_contents($imgDir . '/app.svg', $svg);
		$this->logger->info("[AppDrop] Generated placeholder icon for '{$appId}'.");
	}

	// =========================================================================
	// Permissions
	// =========================================================================

	private function chmodRecursive(string $path): void {
		if (is_dir($path)) {
			@chmod($path, 0750);
			$items = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
			foreach ($items as $item) {
				$this->chmodRecursive($item->getPathname());
			}
		} else {
			@chmod($path, 0640);
		}
	}
}
