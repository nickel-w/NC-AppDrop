<?php

/**
 * SPDX-FileCopyrightText: 2026 CDNTV
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\AppDrop\Service;

use OCP\IConfig;

/**
 * Evaluates the <dependencies> of an app's info.xml against this server:
 * Nextcloud version, PHP version and required PHP extensions (<lib>).
 *
 * Versions are compared the way Nextcloud does it: the server version is cut
 * down to the precision of the constraint, so max-version="35" accepts every
 * 35.x release and min-version="8.2" accepts PHP 8.2.0.
 */
class RequirementChecker {
	public function __construct(
		private readonly IConfig $config,
	) {
	}

	public function getServerVersion(): string {
		return $this->config->getSystemValueString('version', '');
	}

	public function getPhpVersion(): string {
		return PHP_VERSION;
	}

	/**
	 * @return list<array{key: string, declared: bool, ok: bool, message: string}>
	 */
	public function evaluate(\SimpleXMLElement $xml): array {
		$results = [];
		$deps = $xml->dependencies;

		// ── Nextcloud ───────────────────────────────────────────────────────
		$nc = $deps->nextcloud ?? null;
		if ($nc === null || $nc->getName() === '') {
			$results[] = [
				'key' => 'nextcloud',
				'declared' => false,
				'ok' => false,
				'message' => 'The package does not declare which Nextcloud versions it supports.',
			];
		} else {
			$results[] = $this->evaluateRange(
				'nextcloud',
				'Nextcloud',
				$this->getServerVersion(),
				trim((string)($nc['min-version'] ?? '')),
				trim((string)($nc['max-version'] ?? '')),
			);
		}

		// ── PHP ─────────────────────────────────────────────────────────────
		$php = $deps->php ?? null;
		if ($php === null || $php->getName() === '') {
			$results[] = ['key' => 'php', 'declared' => false, 'ok' => true, 'message' => 'No PHP version requirement declared.'];
		} else {
			$results[] = $this->evaluateRange(
				'php',
				'PHP',
				$this->getPhpVersion(),
				trim((string)($php['min-version'] ?? '')),
				trim((string)($php['max-version'] ?? '')),
			);
		}

		// ── PHP extensions ──────────────────────────────────────────────────
		foreach ($deps->lib ?? [] as $lib) {
			$name = trim((string)$lib);
			if ($name === '') {
				continue;
			}
			$loaded = extension_loaded($name);
			$results[] = [
				'key' => 'lib:' . $name,
				'declared' => true,
				'ok' => $loaded,
				'message' => $loaded
					? "PHP extension '{$name}' is available."
					: "Requires PHP extension '{$name}', which is not installed on this server.",
			];
		}

		return $results;
	}

	/**
	 * Messages of all requirements this server does not meet.
	 *
	 * @return list<string>
	 */
	public function unmet(\SimpleXMLElement $xml): array {
		$messages = [];
		foreach ($this->evaluate($xml) as $result) {
			if (!$result['ok']) {
				$messages[] = $result['message'];
			}
		}
		return $messages;
	}

	/**
	 * @return array{key: string, declared: bool, ok: bool, message: string}
	 */
	private function evaluateRange(string $key, string $label, string $actual, string $min, string $max): array {
		$range = ($min !== '' ? $min : '*') . ' – ' . ($max !== '' ? $max : '*');

		if ($actual === '') {
			return ['key' => $key, 'declared' => true, 'ok' => false, 'message' => "Could not determine the {$label} version of this server (package requires {$range})."];
		}
		if ($min !== '' && self::compare($actual, $min) < 0) {
			return ['key' => $key, 'declared' => true, 'ok' => false, 'message' => "Requires {$label} >= {$min}, server runs {$actual}."];
		}
		if ($max !== '' && self::compare($actual, $max) > 0) {
			return ['key' => $key, 'declared' => true, 'ok' => false, 'message' => "Supports {$label} up to {$max}, server runs {$actual}."];
		}

		return ['key' => $key, 'declared' => true, 'ok' => true, 'message' => "{$label} {$range}, server runs {$actual}."];
	}

	/**
	 * Compare $actual against $constraint at the constraint's precision.
	 */
	public static function compare(string $actual, string $constraint): int {
		$constraintParts = explode('.', $constraint);
		$actualParts = array_slice(array_pad(explode('.', $actual), count($constraintParts), '0'), 0, count($constraintParts));
		return version_compare(implode('.', $actualParts), $constraint);
	}
}
