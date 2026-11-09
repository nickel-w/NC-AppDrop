<?php

declare(strict_types=1);

namespace OCA\AppDrop\Tests\Unit\Service;

use OCA\AppDrop\Service\AppInstallException;
use OCA\AppDrop\Service\TemplateGeneratorService;
use OCP\IConfig;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TemplateGeneratorServiceTest extends TestCase {
	private ITempManager&MockObject $tempManager;
	private IConfig&MockObject $config;
	private TemplateGeneratorService $service;
	private string $tmpDir;

	protected function setUp(): void {
		$this->tempManager = $this->createMock(ITempManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValueString')->with('version', '')->willReturn('35.0.2.1');
		$this->service = new TemplateGeneratorService($this->tempManager, $this->config);
		$this->tmpDir = sys_get_temp_dir() . '/appdrop_gen_' . uniqid();
		mkdir($this->tmpDir, 0755, true);
	}

	protected function tearDown(): void {
		array_map('unlink', glob($this->tmpDir . '/*') ?: []);
		@rmdir($this->tmpDir);
	}

	public function testGenerateCreatesValidZip(): void {
		$tmpPath = $this->tmpDir . '/skeleton.zip';
		$this->tempManager->method('getTemporaryFile')->willReturn($tmpPath);

		$result = $this->service->generate('my_app', 'My App', 'MyApp');

		$this->assertFileExists($result);

		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($result) === true);

		$this->assertNotFalse($zip->locateName('my_app/appinfo/info.xml'));
		$this->assertNotFalse($zip->locateName('my_app/appinfo/routes.php'));
		$this->assertNotFalse($zip->locateName('my_app/lib/AppInfo/Application.php'));
		$this->assertNotFalse($zip->locateName('my_app/lib/Controller/PageController.php'));
		$this->assertNotFalse($zip->locateName('my_app/templates/main.php'));
		$this->assertNotFalse($zip->locateName('my_app/css/style.css'));
		$this->assertNotFalse($zip->locateName('my_app/js/script.js'));
		$this->assertNotFalse($zip->locateName('my_app/img/app.svg'));

		$zip->close();
	}

	public function testGenerateInfoXmlContainsCorrectData(): void {
		$tmpPath = $this->tmpDir . '/skeleton.zip';
		$this->tempManager->method('getTemporaryFile')->willReturn($tmpPath);

		$this->service->generate('calc_app', 'Calculator', 'CalcApp', '2.0.0', 'John', 'A calc');

		$zip = new \ZipArchive();
		$zip->open($tmpPath);
		$infoXml = $zip->getFromName('calc_app/appinfo/info.xml');
		$zip->close();

		$this->assertStringContainsString('<id>calc_app</id>', $infoXml);
		$this->assertStringContainsString('<name>Calculator</name>', $infoXml);
		$this->assertStringContainsString('<version>2.0.0</version>', $infoXml);
		$this->assertStringContainsString('<author>John</author>', $infoXml);
		$this->assertStringContainsString('<namespace>CalcApp</namespace>', $infoXml);
	}

	public function testGenerateApplicationPhpUsesCorrectNamespace(): void {
		$tmpPath = $this->tmpDir . '/skeleton.zip';
		$this->tempManager->method('getTemporaryFile')->willReturn($tmpPath);

		$this->service->generate('my_app', 'My App', 'MyApp');

		$zip = new \ZipArchive();
		$zip->open($tmpPath);
		$appPhp = $zip->getFromName('my_app/lib/AppInfo/Application.php');
		$zip->close();

		$this->assertStringContainsString('namespace OCA\MyApp\AppInfo;', $appPhp);
		$this->assertStringContainsString("public const APP_ID = 'my_app';", $appPhp);
	}

	public function testGenerateUsesDefaultsForOptionalParams(): void {
		$tmpPath = $this->tmpDir . '/skeleton.zip';
		$this->tempManager->method('getTemporaryFile')->willReturn($tmpPath);

		$this->service->generate('my_app', 'My App', 'MyApp');

		$zip = new \ZipArchive();
		$zip->open($tmpPath);
		$infoXml = $zip->getFromName('my_app/appinfo/info.xml');
		$zip->close();

		$this->assertStringContainsString('<version>1.0.0</version>', $infoXml);
		$this->assertStringContainsString('<author>Your Name</author>', $infoXml);
	}

	public function testGenerateThrowsOnTempFileFailure(): void {
		$this->tempManager->method('getTemporaryFile')->willReturn(false);

		$this->expectException(AppInstallException::class);
		$this->expectExceptionMessage('Could not create temporary file');

		$this->service->generate('my_app', 'My App', 'MyApp');
	}

	public function testGenerateEscapesXmlEntities(): void {
		$tmpPath = $this->tmpDir . '/skeleton.zip';
		$this->tempManager->method('getTemporaryFile')->willReturn($tmpPath);

		$this->service->generate('my_app', 'My <App>', 'MyApp', '1.0.0', '', 'A "cool" & useful app');

		$zip = new \ZipArchive();
		$zip->open($tmpPath);
		$infoXml = $zip->getFromName('my_app/appinfo/info.xml');
		$zip->close();

		$this->assertStringContainsString('My &lt;App&gt;', $infoXml);
		// ENT_XML1 escapes <, >, & but not quotes
		$this->assertStringContainsString('A "cool" &amp; useful app', $infoXml);
	}

	public function testGenerateInfoXmlMaxVersionFollowsRunningServer(): void {
		$tmpPath = $this->tmpDir . '/skeleton.zip';
		$this->tempManager->method('getTemporaryFile')->willReturn($tmpPath);

		$this->service->generate('my_app', 'My App', 'MyApp');

		$zip = new \ZipArchive();
		$zip->open($tmpPath);
		$infoXml = $zip->getFromName('my_app/appinfo/info.xml');
		$zip->close();

		// Server is 35.0.2.1 (see setUp): a skeleton generated here must be installable here.
		$this->assertStringContainsString('<nextcloud min-version="30" max-version="35"/>', $infoXml);
	}

	public function testGenerateInfoXmlMaxVersionOnOlderServer(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('version', '')->willReturn('31.0.2.1');
		$service = new TemplateGeneratorService($this->tempManager, $config);

		$tmpPath = $this->tmpDir . '/skeleton.zip';
		$this->tempManager->method('getTemporaryFile')->willReturn($tmpPath);

		$service->generate('my_app', 'My App', 'MyApp');

		$zip = new \ZipArchive();
		$zip->open($tmpPath);
		$infoXml = $zip->getFromName('my_app/appinfo/info.xml');
		$zip->close();

		$this->assertStringContainsString('<nextcloud min-version="30" max-version="31"/>', $infoXml);
	}

	public function testGeneratedControllerUsesPhpAttributesNotAnnotations(): void {
		$tmpPath = $this->tmpDir . '/skeleton.zip';
		$this->tempManager->method('getTemporaryFile')->willReturn($tmpPath);

		$this->service->generate('my_app', 'My App', 'MyApp');

		$zip = new \ZipArchive();
		$zip->open($tmpPath);
		$controller = $zip->getFromName('my_app/lib/Controller/PageController.php');
		$zip->close();

		$this->assertStringContainsString('use OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired;', $controller);
		$this->assertStringContainsString('use OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired;', $controller);
		$this->assertStringContainsString('#[NoAdminRequired]', $controller);
		$this->assertStringContainsString('#[NoCSRFRequired]', $controller);
		$this->assertStringNotContainsString('@NoAdminRequired', $controller);
		$this->assertStringNotContainsString('@NoCSRFRequired', $controller);
	}
}
