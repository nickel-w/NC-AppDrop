<?php

declare(strict_types=1);

namespace OCA\AppDrop\Tests\Unit\Controller;

use OCA\AppDrop\Controller\AdminController;
use OCA\AppDrop\Controller\AppManagerController;
use OCA\AppDrop\Controller\BackupController;
use OCA\AppDrop\Controller\HealthCheckController;
use OCA\AppDrop\Controller\HistoryController;
use OCA\AppDrop\Controller\PermissionController;
use OCA\AppDrop\Controller\SettingsController;
use OCA\AppDrop\Controller\TemplateGeneratorController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use PHPUnit\Framework\TestCase;

/**
 * Pins the security contract of every controller action.
 *
 * Nextcloud 27+ prefers PHP attributes over docblock annotations; NC 33 still
 * honours the annotations but logs a deprecation for each request. This test
 * guarantees (a) no docblock annotation is left behind and (b) exactly the
 * intended actions relax admin/CSRF checks — nothing more.
 */
class SecurityAttributesTest extends TestCase {
	private const CONTROLLERS = [
		AdminController::class,
		AppManagerController::class,
		BackupController::class,
		HealthCheckController::class,
		HistoryController::class,
		PermissionController::class,
		SettingsController::class,
		TemplateGeneratorController::class,
	];

	/** action => list of attribute classes that MUST be present (and nothing else). */
	private const EXPECTED = [
		AdminController::class . '::index' => [NoAdminRequired::class, NoCSRFRequired::class],
		AdminController::class . '::install' => [NoAdminRequired::class],
		HealthCheckController::class . '::validate' => [NoAdminRequired::class],
		HistoryController::class . '::list' => [NoAdminRequired::class, NoCSRFRequired::class],
		TemplateGeneratorController::class . '::generate' => [NoAdminRequired::class],
		SettingsController::class . '::get' => [NoCSRFRequired::class],
		PermissionController::class . '::get' => [NoCSRFRequired::class],
		PermissionController::class . '::getUpdateGrants' => [NoCSRFRequired::class],
		PermissionController::class . '::searchUsers' => [NoCSRFRequired::class],
		PermissionController::class . '::searchGroups' => [NoCSRFRequired::class],
	];

	public function testNoDocblockSecurityAnnotationsRemain(): void {
		foreach ($this->actions() as $method) {
			$doc = $method->getDocComment() ?: '';
			$this->assertDoesNotMatchRegularExpression(
				'/@(NoAdminRequired|NoCSRFRequired|PublicPage|SubAdminRequired)\b/',
				$doc,
				$method->class . '::' . $method->name . ' still uses a docblock security annotation',
			);
		}
	}

	public function testExactlyTheIntendedActionsRelaxSecurity(): void {
		foreach ($this->actions() as $method) {
			$key = $method->class . '::' . $method->name;
			$present = array_map(
				static fn (\ReflectionAttribute $a): string => $a->getName(),
				$method->getAttributes(),
			);
			$present = array_values(array_filter(
				$present,
				static fn (string $n): bool => in_array($n, [NoAdminRequired::class, NoCSRFRequired::class], true),
			));
			sort($present);
			$expected = self::EXPECTED[$key] ?? [];
			sort($expected);
			$this->assertSame($expected, $present, "Unexpected security attributes on {$key}");
		}
	}

	/** @return \ReflectionMethod[] */
	private function actions(): array {
		$out = [];
		foreach (self::CONTROLLERS as $class) {
			$ref = new \ReflectionClass($class);
			foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
				if ($m->class !== $class || $m->isConstructor()) {
					continue;
				}
				$out[] = $m;
			}
		}
		$this->assertNotEmpty($out);
		return $out;
	}
}
