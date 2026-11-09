<?php

declare(strict_types=1);

namespace OCA\AppDrop\Tests\Unit\Service;

use OCA\AppDrop\Service\RequirementChecker;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequirementCheckerTest extends TestCase {
	private function checker(string $serverVersion = '35.0.2.1'): RequirementChecker {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = '') => $key === 'version' ? $serverVersion : $default,
		);
		return new RequirementChecker($config);
	}

	private function xml(string $deps): \SimpleXMLElement {
		return simplexml_load_string("<info><id>demo</id>{$deps}</info>");
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function compareProvider(): array {
		return [
			'same major counts as equal' => ['35.0.2.1', '35', 0],
			'below min' => ['29.0.9', '30', -1],
			'above max' => ['36.0.0', '35', 1],
			'precise min, server newer' => ['30.0.6', '30.0.5', 1],
			'php minor equal' => ['8.3.6', '8.3', 0],
			'php below' => ['8.1.20', '8.2', -1],
			'short actual padded' => ['35', '35.0.1', -1],
		];
	}

	#[DataProvider('compareProvider')]
	public function testCompare(string $actual, string $constraint, int $expected): void {
		$this->assertSame($expected, RequirementChecker::compare($actual, $constraint));
	}

	public function testAllRequirementsMet(): void {
		$deps = '<dependencies><nextcloud min-version="30" max-version="35"/><php min-version="8.1"/><lib>json</lib></dependencies>';
		$this->assertSame([], $this->checker()->unmet($this->xml($deps)));
	}

	public function testUnmetRequirementsAreReported(): void {
		$deps = '<dependencies><nextcloud min-version="30" max-version="33"/><php min-version="8.1" max-version="7.4"/><lib>appdrop_no_such_ext</lib></dependencies>';
		$unmet = $this->checker()->unmet($this->xml($deps));

		$this->assertCount(3, $unmet);
		$this->assertStringContainsString('Nextcloud up to 33', $unmet[0]);
		$this->assertStringContainsString('PHP up to 7.4', $unmet[1]);
		$this->assertStringContainsString('appdrop_no_such_ext', $unmet[2]);
	}

	public function testMissingNextcloudDependencyIsUnmet(): void {
		$unmet = $this->checker()->unmet($this->xml(''));
		$this->assertCount(1, $unmet);
		$this->assertStringContainsString('does not declare', $unmet[0]);
	}

	public function testUnknownServerVersionIsUnmet(): void {
		$unmet = $this->checker('')->unmet($this->xml('<dependencies><nextcloud min-version="30" max-version="35"/></dependencies>'));
		$this->assertCount(1, $unmet);
		$this->assertStringContainsString('Could not determine', $unmet[0]);
	}
}
