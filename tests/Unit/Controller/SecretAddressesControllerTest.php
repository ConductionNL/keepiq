<?php

/**
 * The extra website addresses of a login on the secret endpoints: stored on
 * create, returned on show, refused on someone else's copy before anything
 * is written, and carried in the extension's match.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\ExtensionController;
use OCA\Keepiq\Controller\SecretController;
use OCA\Keepiq\Controller\SecretUpdateController;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretUrlMapper;
use OCA\Keepiq\Kernel\Contracts\AdminSettingsServiceInterface;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretUrlService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The endpoints over a real SecretUrlService and a mocked addresses table.
 *
 * @covers \OCA\Keepiq\Controller\SecretController
 * @covers \OCA\Keepiq\Controller\SecretUpdateController
 * @covers \OCA\Keepiq\Controller\ExtensionController
 * @uses   \OCA\Keepiq\Service\SecretUrlService
 * @uses   \OCA\Keepiq\Db\Secret
 */
class SecretAddressesControllerTest extends TestCase {
	private SecretUrlMapper&MockObject $urlMapper;

	private SecretService&MockObject $secretService;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->urlMapper = $this->createMock(SecretUrlMapper::class);
		$this->secretService = $this->createMock(SecretService::class);
	}//end setUp()

	/**
	 * A session for alice.
	 *
	 * @return IUserSession
	 */
	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * A request answering the given parameters.
	 *
	 * @param array<string, mixed> $params The parameters
	 *
	 * @return IRequest
	 */
	private function request(array $params=[]): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $name, $default = null) => ($params[$name] ?? $default));

		return $request;
	}//end request()

	/**
	 * A login held by the given owner.
	 *
	 * @param string $ownerId The owner
	 *
	 * @return Secret
	 */
	private function secret(string $ownerId='alice'): Secret {
		$secret = new Secret();
		$secret->setId('s1');
		$secret->setName('Example');
		$secret->setUrl('https://example.com');
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);

		return $secret;
	}//end secret()

	/**
	 * A create with extra addresses stores them and answers them.
	 *
	 * @return void
	 */
	public function testACreateStoresAndAnswersTheExtraAddresses(): void {
		$this->secretService->method('create')->willReturn($this->secret());
		$this->urlMapper->expects($this->once())->method('replaceForSecret')->with('s1', 'alice', ['https://login.example.net']);
		$controller = new SecretController(request: $this->request(), secretService: $this->secretService, userSession: $this->session(), urls: new SecretUrlService($this->urlMapper));

		$response = $controller->create(name: 'Example', key: 'blob', url: 'https://example.com', additionalUrls: [' https://login.example.net', '']);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['https://login.example.net'], $response->getData()['additionalUrls']);
	}//end testACreateStoresAndAnswersTheExtraAddresses()

	/**
	 * Twenty-one addresses are refused before the login is created.
	 *
	 * @return void
	 */
	public function testTooManyAddressesAreRefusedBeforeTheLoginIsCreated(): void {
		$this->secretService->expects($this->never())->method('create');
		$controller = new SecretController(request: $this->request(), secretService: $this->secretService, userSession: $this->session(), urls: new SecretUrlService($this->urlMapper));

		$response = $controller->create(name: 'Example', key: 'blob', additionalUrls: array_map(static fn (int $i): string => 'https://s' . $i . '.example', range(1, 21)));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'A login carries at most 20 extra addresses'], $response->getData());
	}//end testTooManyAddressesAreRefusedBeforeTheLoginIsCreated()

	/**
	 * Show answers the login with its extra addresses.
	 *
	 * @return void
	 */
	public function testShowAnswersTheExtraAddresses(): void {
		$this->secretService->method('get')->willReturn($this->secret());
		$this->secretService->method('withTags')->willReturnArgument(0);
		$this->urlMapper->method('findUrlsBySecretIds')->with(['s1'])->willReturn(['s1' => ['https://login.example.net']]);
		$controller = new SecretController(request: $this->request(), secretService: $this->secretService, userSession: $this->session(), urls: new SecretUrlService($this->urlMapper));

		$this->assertSame(['https://login.example.net'], $controller->show('s1')->getData()['additionalUrls']);
	}//end testShowAnswersTheExtraAddresses()

	/**
	 * The owner's update replaces the list after the login is saved.
	 *
	 * @return void
	 */
	public function testTheOwnersUpdateReplacesTheList(): void {
		$this->secretService->method('get')->willReturn($this->secret());
		$this->secretService->expects($this->once())->method('update')->willReturn($this->secret());
		$this->urlMapper->expects($this->once())->method('replaceForSecret')->with('s1', 'alice', ['https://example.net']);
		$controller = new SecretUpdateController(request: $this->request(['additionalUrls' => ['https://example.net']]), secretService: $this->secretService, userSession: $this->session(), urls: new SecretUrlService($this->urlMapper));

		$response = $controller->update('s1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['https://example.net'], $response->getData()['additionalUrls']);
	}//end testTheOwnersUpdateReplacesTheList()

	/**
	 * Addresses sent for someone else's login are refused before anything is written.
	 *
	 * @return void
	 */
	public function testAddressesOnSomeoneElsesLoginAreRefusedBeforeAnyWrite(): void {
		$this->secretService->method('get')->willReturn($this->secret(ownerId: 'bob'));
		$this->secretService->expects($this->never())->method('update');
		$this->urlMapper->expects($this->never())->method('replaceForSecret');
		$controller = new SecretUpdateController(request: $this->request(['name' => 'Renamed', 'additionalUrls' => ['https://example.net']]), secretService: $this->secretService, userSession: $this->session(), urls: new SecretUrlService($this->urlMapper));

		$response = $controller->update('s1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Only the owner of a login changes its addresses'], $response->getData());
	}//end testAddressesOnSomeoneElsesLoginAreRefusedBeforeAnyWrite()

	/**
	 * An update without the field leaves the stored list alone.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutTheFieldLeavesTheList(): void {
		$this->secretService->method('update')->willReturn($this->secret());
		$this->urlMapper->expects($this->never())->method('replaceForSecret');
		$controller = new SecretUpdateController(request: $this->request(['name' => 'Renamed']), secretService: $this->secretService, userSession: $this->session(), urls: new SecretUrlService($this->urlMapper));

		$response = $controller->update('s1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayNotHasKey('additionalUrls', $response->getData());
	}//end testAnUpdateWithoutTheFieldLeavesTheList()

	/**
	 * The extension's match carries every address of a login.
	 *
	 * @return void
	 */
	public function testTheMatchCarriesEveryAddress(): void {
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->method('searchByNameOrUrl')->willReturn([$this->secret()]);
		$this->urlMapper->method('findUrlsBySecretIds')->willReturn(['s1' => ['https://login.example.net']]);
		$controller = new ExtensionController(
			request: $this->request(),
			secretMapper: $mapper,
			userSession: $this->session(),
			adminSettings: $this->createMock(AdminSettingsServiceInterface::class),
			appManager: $this->createMock(IAppManager::class),
			urls: new SecretUrlService($this->urlMapper)
		);

		$items = $controller->match('login.example.net')->getData()['items'];

		$this->assertSame('https://example.com', $items[0]['url']);
		$this->assertSame(['https://login.example.net'], $items[0]['additionalUrls']);
	}//end testTheMatchCarriesEveryAddress()
}//end class
