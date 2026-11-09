<?php

declare(strict_types=1);

namespace OCA\AppDrop\Tests\Unit\Service;

use OCA\AppDrop\Service\AppPathResolver;
use OCA\AppDrop\Service\HealthCheckService;
use OCA\AppDrop\Service\RequirementChecker;
use OCA\AppDrop\Service\UpdateEligibilityService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class HealthCheckServiceTest extends TestCase {
	private HealthCheckService $service;
	private string $tmpDir;

	protected function setUp(): void {
		$this->tmpDir = sys_get_temp_dir() . '/appdrop_test_' . uniqid();
		mkdir($this->tmpDir, 0755, true);
		mkdir($this->tmpDir . '/apps', 0755, true);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = '') => $key === 'version' ? '32.0.4.1' : $default,
		);
		$pathResolver = $this->createMock(AppPathResolver::class);
		$pathResolver->method('resolveWritablePath')->willReturn($this->tmpDir . '/apps');
		$requirements = new RequirementChecker($config);
		$this->service = new HealthCheckService($requirements, new UpdateEligibilityService($pathResolver, $requirements));
	}

	protected function tearDown(): void {
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($it as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		@rmdir($this->tmpDir);
	}

	private function createZip(array $files): string {
		$path = $this->tmpDir . '/test.zip';
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

		// Add top-level directory entry first (required for prefix detection)
		$dirs = [];
		foreach ($files as $name => $content) {
			$topDir = explode('/', $name)[0] . '/';
			if (!isset($dirs[$topDir])) {
				$zip->addEmptyDir(rtrim($topDir, '/'));
				$dirs[$topDir] = true;
			}
		}
		foreach ($files as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		return $path;
	}

	private function validInfoXml(string $id = 'test_app', string $version = '1.0.0', string $namespace = 'TestApp'): string {
		return <<<XML
        <?xml version="1.0"?>
        <info>
            <id>{$id}</id>
            <name>Test App</name>
            <version>{$version}</version>
            <licence>agpl</licence>
            <namespace>{$namespace}</namespace>
            <dependencies>
                <nextcloud min-version="30" max-version="32"/>
                <php min-version="8.1"/>
            </dependencies>
        </info>
        XML;
	}

	// =========================================================================
	// Archive validation
	// =========================================================================

	public function testAnalyzeInvalidZipReturnsError(): void {
		$path = $this->tmpDir . '/bad.zip';
		file_put_contents($path, 'not a zip');

		$result = $this->service->analyze($path);

		$this->assertNotEmpty($result['errors']);
		$this->assertSame('fail', $result['checks'][0]['status']);
	}

	// =========================================================================
	// Security checks
	// =========================================================================

	public function testAnalyzeValidZipPassesSecurity(): void {
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $this->validInfoXml(),
		]);

		$result = $this->service->analyze($zip);

		$securityCheck = $this->findCheck($result, 'Security (Zip Slip)');
		$this->assertSame('pass', $securityCheck['status']);
	}

	public function testAnalyzeDetectsDirectoryTraversal(): void {
		$zip = $this->createZip([
			'test_app/../etc/passwd' => 'malicious',
			'test_app/appinfo/info.xml' => $this->validInfoXml(),
		]);

		$result = $this->service->analyze($zip);

		$securityCheck = $this->findCheck($result, 'Security (Zip Slip)');
		$this->assertSame('fail', $securityCheck['status']);
	}

	// =========================================================================
	// info.xml checks
	// =========================================================================

	public function testAnalyzeMissingInfoXml(): void {
		$zip = $this->createZip([
			'test_app/README.md' => 'Hello',
		]);

		$result = $this->service->analyze($zip);

		$infoCheck = $this->findCheck($result, 'appinfo/info.xml');
		$this->assertSame('fail', $infoCheck['status']);
		$this->assertNotEmpty($result['errors']);
	}

	public function testAnalyzeInvalidXml(): void {
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => 'not xml at all',
		]);

		$result = $this->service->analyze($zip);

		$xmlCheck = $this->findCheck($result, 'XML Parsing');
		$this->assertSame('fail', $xmlCheck['status']);
	}

	// =========================================================================
	// App ID checks
	// =========================================================================

	public function testAnalyzeValidAppId(): void {
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $this->validInfoXml(),
		]);

		$result = $this->service->analyze($zip);

		$idCheck = $this->findCheck($result, 'App ID (<id>)');
		$this->assertSame('pass', $idCheck['status']);
		$this->assertSame('test_app', $result['appId']);
	}

	public function testAnalyzeMissingAppId(): void {
		$xml = '<?xml version="1.0"?><info><name>No ID</name></info>';
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $xml,
		]);

		$result = $this->service->analyze($zip);

		$idCheck = $this->findCheck($result, 'App ID (<id>)');
		$this->assertSame('fail', $idCheck['status']);
	}

	public function testAnalyzeInvalidAppId(): void {
		$zip = $this->createZip([
			'BadApp/appinfo/info.xml' => $this->validInfoXml('Bad-App'),
		]);

		$result = $this->service->analyze($zip);

		$idCheck = $this->findCheck($result, 'App ID (<id>)');
		$this->assertSame('fail', $idCheck['status']);
	}

	// =========================================================================
	// Self-update protection
	// =========================================================================

	public function testAnalyzeBlocksSelfUpdate(): void {
		$zip = $this->createZip([
			'appdrop/appinfo/info.xml' => $this->validInfoXml('appdrop', '2.0.0', 'AppDrop'),
		]);

		$result = $this->service->analyze($zip);

		$selfCheck = $this->findCheck($result, 'Self-update Protection');
		$this->assertSame('fail', $selfCheck['status']);
	}

	// =========================================================================
	// Directory match
	// =========================================================================

	public function testAnalyzeDirectoryMatchesAppId(): void {
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $this->validInfoXml(),
		]);

		$result = $this->service->analyze($zip);

		$dirCheck = $this->findCheck($result, 'Directory matches ID');
		$this->assertSame('pass', $dirCheck['status']);
	}

	public function testAnalyzeDirectoryMismatchWarns(): void {
		$zip = $this->createZip([
			'wrong_dir/appinfo/info.xml' => $this->validInfoXml(),
		]);

		$result = $this->service->analyze($zip);

		$dirCheck = $this->findCheck($result, 'Directory matches ID');
		$this->assertSame('warn', $dirCheck['status']);
	}

	// =========================================================================
	// Metadata extraction
	// =========================================================================

	public function testAnalyzeExtractsVersionAndName(): void {
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $this->validInfoXml('test_app', '2.5.1'),
		]);

		$result = $this->service->analyze($zip);

		$this->assertSame('2.5.1', $result['version']);
		$this->assertSame('Test App', $result['name']);
	}

	public function testAnalyzeMissingVersionWarns(): void {
		$xml = '<?xml version="1.0"?><info><id>test_app</id><name>Test</name></info>';
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $xml,
		]);

		$result = $this->service->analyze($zip);

		$versionCheck = $this->findCheck($result, 'Version (<version>)');
		$this->assertSame('warn', $versionCheck['status']);
		$this->assertSame('0.0.0', $result['version']);
	}

	public function testAnalyzeMissingNameWarns(): void {
		$xml = '<?xml version="1.0"?><info><id>test_app</id><version>1.0.0</version></info>';
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $xml,
		]);

		$result = $this->service->analyze($zip);

		$nameCheck = $this->findCheck($result, 'App Name (<name>)');
		$this->assertSame('warn', $nameCheck['status']);
	}

	// =========================================================================
	// Namespace checks
	// =========================================================================

	public function testAnalyzeValidNamespace(): void {
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $this->validInfoXml(),
		]);

		$result = $this->service->analyze($zip);

		$nsCheck = $this->findCheck($result, 'Namespace (<namespace>)');
		$this->assertSame('pass', $nsCheck['status']);
	}

	public function testAnalyzeMissingNamespaceWarns(): void {
		$xml = '<?xml version="1.0"?><info><id>test_app</id><name>T</name><version>1.0.0</version></info>';
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $xml,
		]);

		$result = $this->service->analyze($zip);

		$nsCheck = $this->findCheck($result, 'Namespace (<namespace>)');
		$this->assertSame('warn', $nsCheck['status']);
	}

	public function testAnalyzeNonPascalCaseNamespaceWarns(): void {
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $this->validInfoXml('test_app', '1.0.0', 'testapp'),
		]);

		$result = $this->service->analyze($zip);

		$nsCheck = $this->findCheck($result, 'Namespace (<namespace>)');
		$this->assertSame('warn', $nsCheck['status']);
	}

	// =========================================================================
	// Namespace consistency with Application.php
	// =========================================================================

	public function testAnalyzeNamespaceConsistencyPass(): void {
		$appPhp = "<?php\nnamespace OCA\\TestApp\\AppInfo;\nclass Application {}";
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $this->validInfoXml(),
			'test_app/lib/AppInfo/Application.php' => $appPhp,
		]);

		$result = $this->service->analyze($zip);

		$nsCheck = $this->findCheck($result, 'Namespace in Application.php');
		$this->assertSame('pass', $nsCheck['status']);
	}

	public function testAnalyzeNamespaceMismatchFails(): void {
		$appPhp = "<?php\nnamespace OCA\\WrongNs\\AppInfo;\nclass Application {}";
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $this->validInfoXml(),
			'test_app/lib/AppInfo/Application.php' => $appPhp,
		]);

		$result = $this->service->analyze($zip);

		$nsCheck = $this->findCheck($result, 'Namespace in Application.php');
		$this->assertSame('fail', $nsCheck['status']);
	}

	// =========================================================================
	// Recommended files
	// =========================================================================

	public function testAnalyseRecommendedFilesPresent(): void {
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $this->validInfoXml(),
			'test_app/appinfo/routes.php' => '<?php return [];',
			'test_app/lib/AppInfo/Application.php' => "<?php\nnamespace OCA\\TestApp\\AppInfo;\nclass Application {}",
		]);

		$result = $this->service->analyze($zip);

		$routesCheck = $this->findCheck($result, 'appinfo/routes.php');
		$this->assertSame('pass', $routesCheck['status']);
		$appCheck = $this->findCheck($result, 'lib/AppInfo/Application.php');
		$this->assertSame('pass', $appCheck['status']);
	}

	public function testAnalyseMissingRecommendedFilesWarn(): void {
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $this->validInfoXml(),
		]);

		$result = $this->service->analyze($zip);

		$routesCheck = $this->findCheck($result, 'appinfo/routes.php');
		$this->assertSame('warn', $routesCheck['status']);
	}

	// =========================================================================
	// Icon detection
	// =========================================================================

	public function testAnalyzeDetectsIcon(): void {
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $this->validInfoXml(),
			'test_app/img/app.svg' => '<svg></svg>',
		]);

		$result = $this->service->analyze($zip);

		$iconCheck = $this->findCheck($result, 'App Icon');
		$this->assertSame('pass', $iconCheck['status']);
		$this->assertNotNull($result['icon']);
		$this->assertStringStartsWith('data:image/svg+xml;base64,', $result['icon']);
	}

	public function testAnalyzeMissingIconWarns(): void {
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $this->validInfoXml(),
		]);

		$result = $this->service->analyze($zip);

		$iconCheck = $this->findCheck($result, 'App Icon');
		$this->assertSame('warn', $iconCheck['status']);
		$this->assertNull($result['icon']);
	}

	// =========================================================================
	// Full valid package
	// =========================================================================

	public function testAnalyzeFullValidPackageHasNoErrors(): void {
		$zip = $this->createZip([
			'test_app/appinfo/info.xml' => $this->validInfoXml(),
			'test_app/appinfo/routes.php' => '<?php return [];',
			'test_app/lib/AppInfo/Application.php' => "<?php\nnamespace OCA\\TestApp\\AppInfo;\nclass Application {}",
			'test_app/img/app.svg' => '<svg></svg>',
		]);

		$result = $this->service->analyze($zip);

		$this->assertEmpty($result['errors']);
		$this->assertEmpty($result['warnings']);
		$this->assertSame('test_app', $result['appId']);
		$this->assertSame('1.0.0', $result['version']);
	}

	// =========================================================================
	// Helper
	// =========================================================================

	private function findCheck(array $result, string $label): array {
		foreach ($result['checks'] as $check) {
			if ($check['label'] === $label) {
				return $check;
			}
		}
		$this->fail("Check '{$label}' not found in result.");
	}

	// =========================================================================
	// Requirements and per-app update permission
	// =========================================================================

	private function installExisting(string $id, string $version): void {
		mkdir($this->tmpDir . '/apps/' . $id . '/appinfo', 0755, true);
		file_put_contents(
			$this->tmpDir . '/apps/' . $id . '/appinfo/info.xml',
			"<?xml version=\"1.0\"?><info><id>{$id}</id><version>{$version}</version></info>",
		);
	}

	public function testNextcloudVersionOutOfRangeIsWarningForFullUploaders(): void {
		$xml = str_replace('max-version="32"', 'max-version="31"', $this->validInfoXml());
		$result = $this->service->analyze($this->createZip(['test_app/appinfo/info.xml' => $xml]));

		$check = $this->findCheck($result, 'Nextcloud Compatibility');
		$this->assertSame('warn', $check['status']);
		$this->assertStringContainsString('up to 31', $check['detail']);
		$this->assertSame([], $result['errors']);
	}

	public function testUpdateOnlyPassesForGrantedNewerCompatibleApp(): void {
		$this->installExisting('test_app', '1.0.0');
		$result = $this->service->analyze(
			$this->createZip(['test_app/appinfo/info.xml' => $this->validInfoXml('test_app', '1.2.0')]),
			['test_app'],
		);

		$this->assertSame('pass', $this->findCheck($result, 'Update permission')['status']);
		$this->assertSame([], $result['errors']);
	}

	public function testUpdateOnlyFailsForAppNotGranted(): void {
		$this->installExisting('test_app', '1.0.0');
		$result = $this->service->analyze(
			$this->createZip(['test_app/appinfo/info.xml' => $this->validInfoXml('test_app', '1.2.0')]),
			['other_app'],
		);

		$this->assertSame('fail', $this->findCheck($result, 'Update permission')['status']);
		$this->assertNotEmpty($result['errors']);
	}

	public function testUpdateOnlyFailsForIncompatibleNextcloudAndOldVersion(): void {
		$this->installExisting('test_app', '1.0.0');
		$xml = str_replace('max-version="32"', 'max-version="31"', $this->validInfoXml('test_app', '1.0.0'));
		$result = $this->service->analyze($this->createZip(['test_app/appinfo/info.xml' => $xml]), ['test_app']);

		$this->assertSame('fail', $this->findCheck($result, 'App version')['status']);
		$this->assertSame('fail', $this->findCheck($result, 'Requirements')['status']);
		$this->assertCount(2, $result['errors']);
	}
}
