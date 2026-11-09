<?php

declare(strict_types=1);

namespace OCA\AppDrop\Tests\Unit\Controller;

use OCA\AppDrop\Controller\AdminController;
use OCA\AppDrop\Service\AppInstallException;
use OCA\AppDrop\Service\AppInstallService;
use OCA\AppDrop\Service\PermissionService;
use OCA\AppDrop\Service\UploadHistoryService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private AppInstallService&MockObject $installService;
	private UploadHistoryService&MockObject $historyService;
	private PermissionService&MockObject $permissionService;
	private IConfig&MockObject $config;
	private AdminController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->installService = $this->createMock(AppInstallService::class);
		$this->historyService = $this->createMock(UploadHistoryService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->config = $this->createMock(IConfig::class);

		$this->controller = new AdminController(
			'appdrop',
			$this->request,
			$this->userSession,
			$this->groupManager,
			$this->installService,
			$this->historyService,
			$this->permissionService,
			$this->config,
		);
	}

	// =========================================================================
	// Admin access check
	// =========================================================================

	private function mockAdminUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin_user');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isInGroup')
			->with('admin_user', 'admin')
			->willReturn(true);
	}

	private function mockNonAdminUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('regular_user');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isInGroup')
			->with('regular_user', 'admin')
			->willReturn(false);
		$this->permissionService->method('canUpload')->willReturn(false);
	}

	// =========================================================================
	// index() tests
	// =========================================================================

	public function testIndexAsAdminReturnsUserTemplate(): void {
		$this->mockAdminUser();

		$response = $this->controller->index();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('admin/index', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_USER, $response->getRenderAs());
	}

	public function testIndexAsNonAdminReturnsGuestTemplateWithError(): void {
		$this->mockNonAdminUser();

		$response = $this->controller->index();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('admin/index', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
		$this->assertArrayHasKey('error', $response->getParams());
		$this->assertStringContainsString('Access denied', $response->getParams()['error']);
	}

	public function testIndexWithNoUserReturnsGuestTemplate(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->index();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
	}

	// =========================================================================
	// install() tests
	// =========================================================================

	public function testInstallAsNonAdminReturnsForbidden(): void {
		$this->mockNonAdminUser();

		$response = $this->controller->install();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(403, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Access denied', $data['message']);
	}

	public function testInstallWithNoFileReturnsError(): void {
		$this->mockAdminUser();
		$this->request->method('getUploadedFile')
			->with('zipFile')
			->willReturn(null);

		$response = $this->controller->install();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('No file uploaded.', $data['message']);
	}

	public function testInstallWithEmptyTmpNameReturnsError(): void {
		$this->mockAdminUser();
		$this->request->method('getUploadedFile')
			->with('zipFile')
			->willReturn(['tmp_name' => '', 'name' => 'app.zip']);

		$response = $this->controller->install();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('No file uploaded.', $data['message']);
	}

	public function testInstallSuccessful(): void {
		$this->mockAdminUser();

		$file = [
			'name' => 'myapp.zip',
			'tmp_name' => '/tmp/phpXXXXXX',
			'error' => UPLOAD_ERR_OK,
		];

		$this->request->method('getUploadedFile')
			->with('zipFile')
			->willReturn($file);

		$expected = [
			'success' => true,
			'message' => "App 'My App' (v1.0.0) installed and enabled successfully.",
			'appId' => 'my_app',
			'version' => '1.0.0',
			'name' => 'My App',
			'isUpdate' => false,
			'previousVersion' => null,
		];

		$this->installService->expects($this->once())
			->method('install')
			->willReturn($expected);

		$response = $this->controller->install();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame($expected, $response->getData());
	}

	public function testInstallAppInstallExceptionReturnsError(): void {
		$this->mockAdminUser();

		$file = [
			'name' => 'bad.zip',
			'tmp_name' => '/tmp/phpXXXXXX',
			'error' => UPLOAD_ERR_OK,
		];

		$this->request->method('getUploadedFile')
			->with('zipFile')
			->willReturn($file);

		$this->installService->method('install')
			->willThrowException(new AppInstallException('Only .zip files are accepted.'));

		$response = $this->controller->install();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('Only .zip files are accepted.', $data['message']);
	}

	public function testInstallUnexpectedExceptionReturnsError(): void {
		$this->mockAdminUser();

		$file = [
			'name' => 'app.zip',
			'tmp_name' => '/tmp/phpXXXXXX',
			'error' => UPLOAD_ERR_OK,
		];

		$this->request->method('getUploadedFile')
			->with('zipFile')
			->willReturn($file);

		$this->installService->method('install')
			->willThrowException(new \RuntimeException('Something went wrong'));

		$response = $this->controller->install();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Unexpected error', $data['message']);
	}

	// =========================================================================
	// Per-app update permission
	// =========================================================================

	private function mockUpdateOnlyUser(array $apps): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('updater');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->permissionService->method('canUpload')->willReturn(false);
		$this->permissionService->method('getUpdatableApps')->willReturn($apps);
	}

	public function testIndexForUpdateOnlyUserShowsRestrictedUpload(): void {
		$this->mockUpdateOnlyUser(['dropdemo']);

		$response = $this->controller->index();

		$this->assertSame(TemplateResponse::RENDER_AS_USER, $response->getRenderAs());
		$params = $response->getParams();
		$this->assertFalse($params['isAdmin']);
		$this->assertFalse($params['canUploadAll']);
		$this->assertSame(['dropdemo'], $params['updatableApps']);
	}

	public function testInstallForUpdateOnlyUserRestrictsToGrantedAppsAndNeverAutoEnables(): void {
		$this->mockUpdateOnlyUser(['dropdemo']);
		$file = ['name' => 'dropdemo.zip', 'tmp_name' => '/tmp/phpXXXXXX', 'error' => UPLOAD_ERR_OK];
		$this->request->method('getUploadedFile')->willReturn($file);
		$this->request->method('getParam')->willReturn('1');

		$this->installService->expects($this->once())
			->method('install')
			->with($file, false, ['dropdemo'])
			->willReturn(['success' => true, 'message' => 'ok', 'appId' => 'dropdemo', 'version' => '1.1.0', 'name' => 'Demo', 'isUpdate' => true, 'previousVersion' => '1.0.0']);

		$response = $this->controller->install();
		$this->assertTrue($response->getData()['success']);
	}

	public function testInstallForFullUploaderIsUnrestricted(): void {
		$this->mockAdminUser();
		$file = ['name' => 'x.zip', 'tmp_name' => '/tmp/phpXXXXXX', 'error' => UPLOAD_ERR_OK];
		$this->request->method('getUploadedFile')->willReturn($file);
		$this->request->method('getParam')->willReturn('1');

		$this->installService->expects($this->once())
			->method('install')
			->with($file, true)
			->willReturn(['success' => true, 'message' => 'ok', 'appId' => 'x', 'version' => '1', 'name' => 'x', 'isUpdate' => false, 'previousVersion' => null]);

		$this->controller->install();
	}
}
