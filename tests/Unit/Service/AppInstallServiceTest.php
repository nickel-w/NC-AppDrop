<?php

declare(strict_types=1);

namespace OCA\AppDrop\Tests\Unit\Service;

use OCA\AppDrop\Service\AppInstallException;
use OCA\AppDrop\Service\AppInstallService;
use OCA\AppDrop\Service\AppPathResolver;
use OCA\AppDrop\Service\AppUpgradeService;
use OCA\AppDrop\Service\RequirementChecker;
use OCA\AppDrop\Service\UpdateEligibilityService;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AppInstallServiceTest extends TestCase {
	private AppPathResolver&MockObject $pathResolver;
	private ITempManager&MockObject $tempManager;
	private IAppManager&MockObject $appManager;
	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;
	private AppInstallService $service;

	protected function setUp(): void {
		$this->pathResolver = $this->createMock(AppPathResolver::class);
		$this->tempManager = $this->createMock(ITempManager::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new AppInstallService(
			$this->pathResolver,
			$this->tempManager,
			$this->appManager,
			$this->config,
			new AppUpgradeService($this->appManager, $this->config, $this->logger),
			$this->logger,
			new UpdateEligibilityService($this->pathResolver, new RequirementChecker($this->config)),
		);
	}

	// =========================================================================
	// File validation tests
	// =========================================================================

	public function testInstallThrowsOnUploadError(): void {
		$this->expectException(AppInstallException::class);
		$this->expectExceptionMessage('File upload error');

		$this->service->install([
			'name' => 'app.zip',
			'tmp_name' => '/tmp/fake',
			'error' => UPLOAD_ERR_INI_SIZE,
		]);
	}

	public function testInstallThrowsOnWrongExtension(): void {
		$this->expectException(AppInstallException::class);
		$this->expectExceptionMessage('Only .zip files are accepted');

		$this->service->install([
			'name' => 'app.tar.gz',
			'tmp_name' => '/tmp/fake',
			'error' => UPLOAD_ERR_OK,
		]);
	}

	public function testInstallThrowsOnMissingTmpFile(): void {
		$this->expectException(AppInstallException::class);
		$this->expectExceptionMessage('Uploaded temporary file not found');

		$this->service->install([
			'name' => 'app.zip',
			'tmp_name' => '/tmp/nonexistent_' . uniqid(),
			'error' => UPLOAD_ERR_OK,
		]);
	}

	public function testInstallThrowsOnInvalidMimeType(): void {
		$this->expectException(AppInstallException::class);
		$this->expectExceptionMessage('Invalid file type');

		$tmp = tempnam(sys_get_temp_dir(), 'test_');
		file_put_contents($tmp, 'this is not a zip file');

		try {
			$this->service->install([
				'name' => 'app.zip',
				'tmp_name' => $tmp,
				'error' => UPLOAD_ERR_OK,
			]);
		} finally {
			@unlink($tmp);
		}
	}

	// =========================================================================
	// App ID validation tests (via parseInfoXml, invoked through install)
	// =========================================================================

	#[DataProvider('validAppIdProvider')]
	public function testValidAppIdPattern(string $appId): void {
		$this->assertMatchesRegularExpression('/^[a-z0-9_]{3,64}$/', $appId);
	}

	public static function validAppIdProvider(): array {
		return [
			'simple' => ['myapp'],
			'with underscores' => ['my_app'],
			'with numbers' => ['app123'],
			'minimum length' => ['abc'],
			'all digits' => ['123'],
			'mixed' => ['my_cool_app_42'],
		];
	}

	#[DataProvider('invalidAppIdProvider')]
	public function testInvalidAppIdPattern(string $appId): void {
		$this->assertDoesNotMatchRegularExpression('/^[a-z0-9_]{3,64}$/', $appId);
	}

	public static function invalidAppIdProvider(): array {
		return [
			'too short' => ['ab'],
			'uppercase' => ['MyApp'],
			'with dash' => ['my-app'],
			'with spaces' => ['my app'],
			'empty' => [''],
			'special chars' => ['app@home'],
			'with dots' => ['my.app'],
		];
	}

	// =========================================================================
	// info.xml parsing tests (via reflection to test the private method)
	// =========================================================================

	public function testParseInfoXmlValid(): void {
		$method = new \ReflectionMethod(AppInstallService::class, 'parseInfoXml');

		$xml = <<<'XML'
        <?xml version="1.0"?>
        <info>
            <id>my_test_app</id>
            <name>My Test App</name>
            <version>1.2.3</version>
        </info>
        XML;

		$result = $method->invoke($this->service, $xml);

		$this->assertSame('my_test_app', $result['appId']);
		$this->assertSame('1.2.3', $result['version']);
		$this->assertSame('My Test App', $result['name']);
	}

	public function testParseInfoXmlMissingId(): void {
		$method = new \ReflectionMethod(AppInstallService::class, 'parseInfoXml');

		$xml = <<<'XML'
        <?xml version="1.0"?>
        <info>
            <name>My Test App</name>
            <version>1.0.0</version>
        </info>
        XML;

		$this->expectException(AppInstallException::class);
		$this->expectExceptionMessage('missing the required <id> element');

		$method->invoke($this->service, $xml);
	}

	public function testParseInfoXmlInvalidAppId(): void {
		$method = new \ReflectionMethod(AppInstallService::class, 'parseInfoXml');

		$xml = <<<'XML'
        <?xml version="1.0"?>
        <info>
            <id>Invalid-App-ID</id>
            <name>Bad App</name>
            <version>1.0.0</version>
        </info>
        XML;

		$this->expectException(AppInstallException::class);
		$this->expectExceptionMessage('Invalid app ID');

		$method->invoke($this->service, $xml);
	}

	public function testParseInfoXmlInvalidXml(): void {
		$method = new \ReflectionMethod(AppInstallService::class, 'parseInfoXml');

		$this->expectException(AppInstallException::class);
		$this->expectExceptionMessage('Could not parse info.xml');

		$method->invoke($this->service, 'this is not xml at all');
	}

	public function testParseInfoXmlDefaultsVersionAndName(): void {
		$method = new \ReflectionMethod(AppInstallService::class, 'parseInfoXml');

		$xml = <<<'XML'
        <?xml version="1.0"?>
        <info>
            <id>test_app</id>
        </info>
        XML;

		$result = $method->invoke($this->service, $xml);

		$this->assertSame('test_app', $result['appId']);
		$this->assertSame('0.0.0', $result['version']);
		$this->assertSame('test_app', $result['name']);
	}

	// =========================================================================
	// End-to-end install() flow (real zip, real temp apps dir)
	// =========================================================================

	private function makeAppsDir(): string {
		$dir = sys_get_temp_dir() . '/appdrop_apps_' . uniqid();
		mkdir($dir, 0755, true);
		return $dir;
	}

	private function makeAppZip(string $dir, string $appId, string $version, string $deps = ''): string {
		$path = $dir . '/' . $appId . '-' . $version . '.zip';
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::CREATE);
		$zip->addEmptyDir($appId);
		$zip->addFromString(
			$appId . '/appinfo/info.xml',
			"<?xml version=\"1.0\"?><info><id>{$appId}</id><name>Demo</name><version>{$version}</version>{$deps}</info>",
		);
		$zip->addFromString($appId . '/lib/AppInfo/Application.php', '<?php');
		$zip->close();
		return $path;
	}

	private function removeDir(string $path): void {
		if (!is_dir($path)) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($it as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($path);
	}

	/**
	 * Wires the mocks so install() can run end-to-end against $appsDir.
	 *
	 * @param string $installedVersion what oc_appconfig says about dropdemo ('' = unknown to the DB)
	 */
	private function wireInstall(string $appsDir, string $installedVersion = ''): void {
		$this->pathResolver->method('resolveWritablePath')->willReturn($appsDir);
		$this->tempManager->method('getTemporaryFile')->willReturn($appsDir . '/upload.tmp.zip');
		$this->config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = '') => $key === 'version' ? '35.0.2.1' : $default,
		);
		$this->config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default) => match ($key) {
				'max_upload_size_mb' => '20',
				'installed_version' => $installedVersion,
				default => $default,
			},
		);
	}

	private function makeExistingApp(string $appsDir, string $appId, string $version): void {
		mkdir($appsDir . '/' . $appId . '/appinfo', 0755, true);
		file_put_contents(
			$appsDir . '/' . $appId . '/appinfo/info.xml',
			"<?xml version=\"1.0\"?><info><id>{$appId}</id><version>{$version}</version></info>",
		);
	}

	public function testFreshInstallEnablesButDoesNotUpgrade(): void {
		$appsDir = $this->makeAppsDir();
		$this->wireInstall($appsDir);
		$zip = $this->makeAppZip($appsDir, 'dropdemo', '1.0.0');

		$this->appManager->expects($this->never())->method('upgradeApp');
		$this->appManager->expects($this->once())->method('enableApp')->with('dropdemo');

		$result = $this->service->install(['name' => 'dropdemo.zip', 'tmp_name' => $zip, 'error' => UPLOAD_ERR_OK]);

		$this->assertTrue($result['success']);
		$this->assertFalse($result['isUpdate']);
		$this->assertSame('1.0.0', $result['version']);
		$this->assertFileExists($appsDir . '/dropdemo/appinfo/info.xml');

		$this->removeDir($appsDir);
	}

	public function testUpdateRunsAppUpgradeSoInstanceDoesNotEnterUpgradeMode(): void {
		$appsDir = $this->makeAppsDir();
		$this->wireInstall($appsDir, '1.0.0');
		$this->makeExistingApp($appsDir, 'dropdemo', '1.0.0');
		$zip = $this->makeAppZip($appsDir, 'dropdemo', '1.1.0');

		// Without this, installed_version (1.0.0) != code version (1.1.0) and Nextcloud
		// answers 503 "upgrade needed" to every request until `occ upgrade`.
		$this->appManager->expects($this->once())->method('upgradeApp')->with('dropdemo')->willReturn(true);
		$this->appManager->expects($this->once())->method('enableApp')->with('dropdemo');

		$result = $this->service->install(['name' => 'dropdemo.zip', 'tmp_name' => $zip, 'error' => UPLOAD_ERR_OK]);

		$this->assertTrue($result['isUpdate']);
		$this->assertSame('1.0.0', $result['previousVersion']);
		$this->assertSame('1.1.0', $result['version']);
		$this->assertNotEmpty(glob($appsDir . '/dropdemo_backup_*'), 'previous version must be backed up');

		$this->removeDir($appsDir);
	}

	public function testUpdateUpgradesEvenWhenAutoEnableIsOff(): void {
		$appsDir = $this->makeAppsDir();
		$this->wireInstall($appsDir, '1.0.0');
		$this->makeExistingApp($appsDir, 'dropdemo', '1.0.0');
		$zip = $this->makeAppZip($appsDir, 'dropdemo', '1.1.0');

		$this->appManager->expects($this->once())->method('upgradeApp')->with('dropdemo')->willReturn(true);
		$this->appManager->expects($this->never())->method('enableApp');

		$this->service->install(['name' => 'dropdemo.zip', 'tmp_name' => $zip, 'error' => UPLOAD_ERR_OK], false);

		$this->removeDir($appsDir);
	}

	public function testUpdateReportsUpgradeFailureWithOccHint(): void {
		$appsDir = $this->makeAppsDir();
		$this->wireInstall($appsDir, '1.0.0');
		$this->makeExistingApp($appsDir, 'dropdemo', '1.0.0');
		$zip = $this->makeAppZip($appsDir, 'dropdemo', '1.1.0');

		$this->appManager->method('upgradeApp')->willThrowException(new \RuntimeException('migration boom'));
		$this->logger->expects($this->atLeastOnce())->method('error');

		try {
			$this->service->install(['name' => 'dropdemo.zip', 'tmp_name' => $zip, 'error' => UPLOAD_ERR_OK]);
			$this->fail('expected AppInstallException');
		} catch (AppInstallException $e) {
			$this->assertStringContainsString('occ upgrade', $e->getMessage());
			$this->assertStringContainsString('migration boom', $e->getMessage());
		}

		$this->removeDir($appsDir);
	}

	public function testReinstallAfterRemoveUpgradesWhenDbStillKnowsAnotherVersion(): void {
		$appsDir = $this->makeAppsDir();
		// Directory is gone (removed via AppDrop or `occ app:remove`) but the DB
		// still records 1.1.0; re-installing 1.0.0 must reconcile or NC goes 503.
		$this->wireInstall($appsDir, '1.1.0');
		$zip = $this->makeAppZip($appsDir, 'dropdemo', '1.0.0');

		$this->appManager->expects($this->once())->method('upgradeApp')->with('dropdemo')->willReturn(true);
		$this->appManager->expects($this->once())->method('enableApp')->with('dropdemo');

		$result = $this->service->install(['name' => 'dropdemo.zip', 'tmp_name' => $zip, 'error' => UPLOAD_ERR_OK]);

		$this->assertFalse($result['isUpdate']);

		$this->removeDir($appsDir);
	}

	public function testReuploadingSameVersionDoesNotUpgrade(): void {
		$appsDir = $this->makeAppsDir();
		$this->wireInstall($appsDir, '1.0.0');
		$this->makeExistingApp($appsDir, 'dropdemo', '1.0.0');
		$zip = $this->makeAppZip($appsDir, 'dropdemo', '1.0.0');

		$this->appManager->expects($this->never())->method('upgradeApp');
		$this->appManager->expects($this->once())->method('enableApp')->with('dropdemo');

		$result = $this->service->install(['name' => 'dropdemo.zip', 'tmp_name' => $zip, 'error' => UPLOAD_ERR_OK]);

		$this->assertTrue($result['isUpdate']);

		$this->removeDir($appsDir);
	}

	// =========================================================================
	// Update-only mode (per-app update permission)
	// =========================================================================

	private const COMPATIBLE_DEPS = '<dependencies><nextcloud min-version="30" max-version="35"/><php min-version="8.1"/></dependencies>';

	public function testUpdateOnlyAcceptsNewerCompatibleVersionAndKeepsEnabledState(): void {
		$appsDir = $this->makeAppsDir();
		$this->wireInstall($appsDir, '1.0.0');
		$this->makeExistingApp($appsDir, 'dropdemo', '1.0.0');
		$zip = $this->makeAppZip($appsDir, 'dropdemo', '1.1.0', self::COMPATIBLE_DEPS);

		$this->appManager->expects($this->never())->method('enableApp');

		$result = $this->service->install(['name' => 'dropdemo.zip', 'tmp_name' => $zip, 'error' => UPLOAD_ERR_OK], true, ['dropdemo']);

		$this->assertTrue($result['isUpdate']);
		$this->assertSame('1.1.0', $result['version']);
		$this->removeDir($appsDir);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string[], 3: string}>
	 */
	public static function rejectedUpdateProvider(): array {
		return [
			'app not granted' => ['1.1.0', self::COMPATIBLE_DEPS, ['otherapp'], 'not allowed to update'],
			'same version' => ['1.0.0', self::COMPATIBLE_DEPS, ['dropdemo'], 'not newer'],
			'downgrade' => ['0.9.0', self::COMPATIBLE_DEPS, ['dropdemo'], 'not newer'],
			'nextcloud too new' => ['1.1.0', '<dependencies><nextcloud min-version="30" max-version="33"/></dependencies>', ['dropdemo'], 'up to 33'],
			'nextcloud too old' => ['1.1.0', '<dependencies><nextcloud min-version="36" max-version="37"/></dependencies>', ['dropdemo'], 'Nextcloud >= 36'],
			'no nextcloud dependency' => ['1.1.0', '', ['dropdemo'], 'does not declare'],
			'php too old' => ['1.1.0', '<dependencies><nextcloud min-version="30" max-version="35"/><php min-version="99.0"/></dependencies>', ['dropdemo'], 'PHP >= 99.0'],
			'missing extension' => ['1.1.0', '<dependencies><nextcloud min-version="30" max-version="35"/><lib>appdrop_no_such_ext</lib></dependencies>', ['dropdemo'], 'appdrop_no_such_ext'],
		];
	}

	#[DataProvider('rejectedUpdateProvider')]
	public function testUpdateOnlyRejectsAndLeavesInstalledAppUntouched(string $version, string $deps, array $allowed, string $expected): void {
		$appsDir = $this->makeAppsDir();
		$this->wireInstall($appsDir, '1.0.0');
		$this->makeExistingApp($appsDir, 'dropdemo', '1.0.0');
		$zip = $this->makeAppZip($appsDir, 'dropdemo', $version, $deps);

		$this->appManager->expects($this->never())->method('upgradeApp');

		try {
			$this->service->install(['name' => 'dropdemo.zip', 'tmp_name' => $zip, 'error' => UPLOAD_ERR_OK], true, $allowed);
			$this->fail('Expected AppInstallException');
		} catch (AppInstallException $e) {
			$this->assertStringContainsString($expected, $e->getMessage());
		}

		$this->assertStringContainsString('<version>1.0.0</version>', (string)file_get_contents($appsDir . '/dropdemo/appinfo/info.xml'));
		$this->assertSame([], glob($appsDir . '/dropdemo_backup_*') ?: [], 'No backup must be made for a rejected update');
		$this->removeDir($appsDir);
	}

	public function testUpdateOnlyRefusesNewInstall(): void {
		$appsDir = $this->makeAppsDir();
		$this->wireInstall($appsDir);
		$zip = $this->makeAppZip($appsDir, 'dropdemo', '1.0.0', self::COMPATIBLE_DEPS);

		try {
			$this->service->install(['name' => 'dropdemo.zip', 'tmp_name' => $zip, 'error' => UPLOAD_ERR_OK], true, ['dropdemo']);
			$this->fail('Expected AppInstallException');
		} catch (AppInstallException $e) {
			$this->assertStringContainsString('not installed', $e->getMessage());
		}

		$this->assertDirectoryDoesNotExist($appsDir . '/dropdemo');
		$this->removeDir($appsDir);
	}
}
