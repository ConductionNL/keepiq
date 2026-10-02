<?php

/**
 * Unit tests for ExtensionController.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\ExtensionController;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\AdminSettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Keepiq\Controller\ExtensionController
 */
class ExtensionControllerTest extends TestCase {
	/**
	 * Build the controller + collaborators.
	 *
	 * @param string|null $userId The session user
	 * @param int|null $storedMaxIdle The stored extension idle maximum, or null for unset
	 *
	 * @return array{0:ExtensionController,1:SecretMapper}
	 */
	private function build(?string $userId = 'alice', ?int $storedMaxIdle = null): array {
		$request = $this->createMock(IRequest::class);
		$session = $this->createMock(IUserSession::class);
		$mapper = $this->createMock(SecretMapper::class);

		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		// The REAL settings service over a mocked app config, so the test
		// covers the default and the stored-value path the endpoint reads.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default) => ($key === 'extension_max_idle_minutes' && $storedMaxIdle !== null) ? $storedMaxIdle : $default
		);
		$settings = new AdminSettingsService(
			appConfig: $appConfig,
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->with('keepiq')->willReturn('0.3.4-unstable.20261002180000');

		return [new ExtensionController($request, $mapper, $session, $settings, $appManager), $mapper];
	}//end build()

	/**
	 * Pairing without a session is unauthorized.
	 *
	 * @return void
	 */
	public function testPairRequiresAuth(): void {
		[$controller] = $this->build(null);
		$response = $controller->pair();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testPairRequiresAuth()

	/**
	 * A paired session gets ok + capabilities.
	 *
	 * @return void
	 */
	public function testPairSucceeds(): void {
		[$controller] = $this->build('alice');
		$response = $controller->pair();
		$data = $response->getData();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['ok']);
		$this->assertSame('alice', $data['user']);
		$this->assertContains('passkey-provider', $data['capabilities']);
		// The installed app version, for the extension's version handshake.
		$this->assertSame('0.3.4-unstable.20261002180000', $data['serverVersion']);
	}//end testPairSucceeds()

	/**
	 * Match without a session is unauthorized.
	 *
	 * @return void
	 */
	public function testMatchRequiresAuth(): void {
		[$controller] = $this->build(null);
		$response = $controller->match('example.com');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testMatchRequiresAuth()

	/**
	 * An empty host is a 400.
	 *
	 * @return void
	 */
	public function testMatchRejectsEmptyHost(): void {
		[$controller] = $this->build('alice');
		$response = $controller->match('');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testMatchRejectsEmptyHost()

	/**
	 * Match returns blob rows for the registrable domain, never plaintext.
	 *
	 * @return void
	 */
	public function testMatchReturnsBlobRowsForRegistrableDomain(): void {
		[$controller, $mapper] = $this->build('alice');

		$secret = $this->createMock(Secret::class);
		$secret->method('jsonSerialize')->willReturn(
			[
				'id' => 's1',
				'name' => 'Example',
				'url' => 'https://login.example.com',
				'key' => 'CIPHERTEXT_BLOB',
				'login' => 'CIPHERTEXT_LOGIN',
			]
		);

		// A subdomain host must be searched by its registrable domain term.
		$mapper->expects($this->once())
			->method('searchByNameOrUrl')
			->with('user', 'alice', 'example.com', 200)
			->willReturn([$secret]);

		$response = $controller->match('login.example.com');
		$data = $response->getData();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('example.com', $data['term']);
		$this->assertCount(1, $data['items']);
		// Only ciphertext is present; no decrypted value leaks.
		$this->assertSame('CIPHERTEXT_BLOB', $data['items'][0]['key']);
	}//end testMatchReturnsBlobRowsForRegistrableDomain()

	/**
	 * A full origin (scheme + path) is reduced to the host before matching.
	 *
	 * @return void
	 */
	public function testMatchStripsSchemeAndPath(): void {
		[$controller, $mapper] = $this->build('alice');
		$mapper->expects($this->once())
			->method('searchByNameOrUrl')
			->with('user', 'alice', 'example.com', 200)
			->willReturn([]);

		$response = $controller->match('https://www.example.com/login?x=1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testMatchStripsSchemeAndPath()

	/**
	 * Without an administrator setting the maximum is 240 minutes.
	 *
	 * @return void
	 */
	public function testPolicyDefaultsTo240Minutes(): void {
		[$controller] = $this->build('alice');
		$response = $controller->policy();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['maxIdleMinutes' => 240], $response->getData());
	}//end testPolicyDefaultsTo240Minutes()

	/**
	 * A set maximum is returned as set.
	 *
	 * @return void
	 */
	public function testPolicyReturnsTheSetMaximum(): void {
		[$controller] = $this->build('alice', 30);
		$this->assertSame(['maxIdleMinutes' => 30], $controller->policy()->getData());
	}//end testPolicyReturnsTheSetMaximum()

	/**
	 * A stored value outside the offered delays never switches the lock off.
	 *
	 * @return void
	 */
	public function testPolicyIgnoresAnUnofferedStoredValue(): void {
		[$controller] = $this->build('alice', 0);
		$this->assertSame(['maxIdleMinutes' => 240], $controller->policy()->getData());
	}//end testPolicyIgnoresAnUnofferedStoredValue()

	/**
	 * The policy needs a session.
	 *
	 * @return void
	 */
	public function testPolicyRequiresAuth(): void {
		[$controller] = $this->build(null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->policy()->getStatus());
	}//end testPolicyRequiresAuth()

	/**
	 * The admin update accepts an offered delay and refuses anything else.
	 *
	 * @return void
	 */
	public function testAdminUpdateAcceptsOnlyOfferedDelays(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$appConfig->expects($this->once())->method('setValueInt')->with('keepiq', 'extension_max_idle_minutes', 30);
		$settings = new AdminSettingsService(
			appConfig: $appConfig,
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
		);
		$settings->updateAdminSettings(['extension_max_idle_minutes' => 30]);

		$this->expectException(\InvalidArgumentException::class);
		$settings->updateAdminSettings(['extension_max_idle_minutes' => 45]);
	}//end testAdminUpdateAcceptsOnlyOfferedDelays()
}//end class
