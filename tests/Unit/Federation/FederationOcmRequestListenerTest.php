<?php

/**
 * Keepiq Federation OCM Request Listener Test
 *
 * The partner-facing certificate lookup under /ocm/keepiq/ (keepiq#789,
 * sharing-federated-recipients task 2.1), with the REAL
 * OCMEndpointRequestEvent and the real FederatedCertificateService,
 * FederationPartnerService and FederationRootService. Only storage, users,
 * preferences and suites are doubled.
 *
 * Every refusal must produce the exact same answer as an unknown user, so a
 * probe learns nothing about who exists, who opted in, or which instances
 * are partners.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Federation
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use OCA\Keepiq\Listener\FederationOcmRequestListener;
use OCA\Keepiq\Service\FederatedCertificateService;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCA\Keepiq\Service\ShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\OCM\Events\OCMEndpointRequestEvent;
use OCP\OCM\IOCMDiscoveryService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FederationFixtures.php';

class FederationOcmRequestListenerTest extends TestCase {
	use FederationFixtures;

	/**
	 * Skip on a Nextcloud without the OCM APIs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->skipWithoutFederation();
	}//end setUp()

	/** @var array<string,string> userId => receive preference */
	private array $optIns = ['bob' => '1', 'carol' => '0', 'dave' => '1'];

	/** @var array<string,string> userId => certificate, users with an active suite */
	private array $suites = ['bob' => 'BOB-CERT', 'carol' => 'CAROL-CERT'];

	/**
	 * Build the listener over the given partners.
	 *
	 * @param array $partners The partner rows
	 * @param bool $caPresent Whether the local CA exists
	 *
	 * @return FederationOcmRequestListener
	 */
	private function listener(array $partners, bool $caPresent = true): FederationOcmRequestListener {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $user, string $app, string $key, $default) => $this->optIns[$user] ?? $default
		);
		$config->method('getSystemValueBool')->willReturn(false);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, ['bob', 'carol', 'dave'], true)
		);
		$shares = $this->createMock(ShareService::class);
		$shares->method('recipientCertificates')->willReturnCallback(
			fn (array $ids): array => array_intersect_key($this->suites, array_flip($ids))
		);

		$service = new FederatedCertificateService(
			new FederationPartnerService($this->partnerMapper($partners), $this->createMock(IClientService::class), $config),
			new FederationRootService($this->caMapper($caPresent)),
			$shares,
			$this->cloudIdManager(),
			$users,
			$config,
			$this->createMock(IOCMDiscoveryService::class),
		);

		return new FederationOcmRequestListener($service, $this->createMock(FederatedShareService::class));
	}

	/**
	 * Dispatch one request through the listener and return the event.
	 *
	 * @param FederationOcmRequestListener $listener The listener
	 * @param string|null $signer The verified signer, null for unsigned
	 * @param string $cloudId The cloud id asked about
	 * @param string $method The HTTP method
	 * @param string $path The path below /ocm/
	 *
	 * @return OCMEndpointRequestEvent
	 */
	private function ask(
		FederationOcmRequestListener $listener,
		?string $signer,
		string $cloudId,
		string $method = 'POST',
		string $path = 'keepiq/recipient-certificate',
	): OCMEndpointRequestEvent {
		$event = new OCMEndpointRequestEvent($method, $path, ['cloudId' => $cloudId], $signer);
		$listener->handle($event);

		return $event;
	}

	/**
	 * The status and body of the event's answer.
	 *
	 * @param OCMEndpointRequestEvent $event The answered event
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function answerOf(OCMEndpointRequestEvent $event): array {
		$response = $event->getResponse();
		$this->assertInstanceOf(JSONResponse::class, $response);

		return [$response->getStatus(), $response->getData()];
	}

	public function testAnInboundPartnerGetsTheCertificateAndChainOfAnOptedInUser(): void {
		$listener = $this->listener([$this->partner('cloud.city.example', false, true)]);

		[$status, $data] = $this->answerOf($this->ask($listener, 'cloud.city.example', 'bob@cloud.here.example'));

		$this->assertSame(Http::STATUS_OK, $status);
		$this->assertSame(
			[
				'cloudId' => 'bob@cloud.here.example',
				'certificate' => 'BOB-CERT',
				'chain' => [self::INTERMEDIATE_PEM, self::ROOT_PEM],
			],
			$data
		);
	}

	/**
	 * On an http instance Nextcloud writes a user's own cloud id with the
	 * scheme (`bob@http://cloud.here.example`) while the partner asks for
	 * `bob@cloud.here.example`; that is the same user.
	 */
	public function testAUserOfAnHttpInstanceIsFoundUnderTheCloudIdWithoutScheme(): void {
		$this->localHost = 'http://cloud.here.example';
		$listener = $this->listener([$this->partner('cloud.city.example', false, true)]);

		[$status, $data] = $this->answerOf($this->ask($listener, 'cloud.city.example', 'bob@cloud.here.example'));

		$this->assertSame(Http::STATUS_OK, $status);
		$this->assertSame('BOB-CERT', $data['certificate']);
		// And still not a user of another instance.
		[$status] = $this->answerOf($this->ask($listener, 'cloud.city.example', 'bob@cloud.elsewhere.example'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $status);
	}

	/**
	 * Each refusal, and the answer it must be indistinguishable from.
	 *
	 * @return array<string,array{0:?string,1:string,2:array<int,array{0:string,1:bool,2:bool}>}>
	 */
	public static function refusals(): array {
		$partner = [['cloud.city.example', false, true]];

		return [
			'unsigned request' => [null, 'bob@cloud.here.example', $partner],
			'signer is no partner' => ['cloud.stranger.example', 'bob@cloud.here.example', $partner],
			'partner without inbound' => ['cloud.city.example', 'bob@cloud.here.example', [['cloud.city.example', true, false]]],
			'user did not opt in' => ['cloud.city.example', 'carol@cloud.here.example', $partner],
			'user has no active suite' => ['cloud.city.example', 'dave@cloud.here.example', $partner],
			'user does not exist' => ['cloud.city.example', 'nobody@cloud.here.example', $partner],
			'user of another instance' => ['cloud.city.example', 'bob@cloud.elsewhere.example', $partner],
			'not a cloud id' => ['cloud.city.example', 'bob', $partner],
		];
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param string|null $signer The signer
	 * @param string $cloudId The cloud id
	 * @param array $partners Partner specs
	 */
	public function testEveryRefusalLooksLikeAnUnknownUser(?string $signer, string $cloudId, array $partners): void {
		$rows = array_map(fn (array $p) => $this->partner(...$p), $partners);
		$listener = $this->listener($rows);
		$unknown = $this->answerOf($this->ask($listener, 'cloud.city.example', 'nobody@cloud.here.example'));

		$answer = $this->answerOf($this->ask($listener, $signer, $cloudId));

		$this->assertSame(Http::STATUS_NOT_FOUND, $answer[0]);
		$this->assertSame($unknown, $answer, 'a refusal must be indistinguishable from an unknown user');
	}

	public function testRefusesWhenThisInstanceHasNoCaYet(): void {
		$listener = $this->listener([$this->partner('cloud.city.example', false, true)], caPresent: false);

		[$status] = $this->answerOf($this->ask($listener, 'cloud.city.example', 'bob@cloud.here.example'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $status);
	}

	public function testOnlyAPostAnswers(): void {
		$listener = $this->listener([$this->partner('cloud.city.example', false, true)]);

		[$status] = $this->answerOf($this->ask($listener, 'cloud.city.example', 'bob@cloud.here.example', 'GET'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $status);
	}

	public function testAnUnknownKeepiqPathGetsTheSameAnswer(): void {
		$listener = $this->listener([$this->partner('cloud.city.example', false, true)]);

		[$status] = $this->answerOf(
			$this->ask($listener, 'cloud.city.example', 'bob@cloud.here.example', 'POST', 'keepiq/users')
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $status);
	}

	public function testLeavesOtherCapabilitiesAlone(): void {
		$listener = $this->listener([$this->partner('cloud.city.example', false, true)]);

		$event = $this->ask($listener, 'cloud.city.example', 'bob@cloud.here.example', 'POST', 'other-app/recipient-certificate');

		$this->assertNull($event->getResponse());
	}

	public function testMatchesTheSignerWithItsPort(): void {
		$listener = $this->listener([$this->partner('cloud.city.example:8443', false, true)]);

		[$plain] = $this->answerOf($this->ask($listener, 'cloud.city.example', 'bob@cloud.here.example'));
		[$withPort] = $this->answerOf($this->ask($listener, 'cloud.city.example:8443', 'bob@cloud.here.example'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $plain);
		$this->assertSame(Http::STATUS_OK, $withPort);
	}

	public function testTheCapabilityNameIsKeepiq(): void {
		$this->assertSame('keepiq', FederatedCertificateService::OCM_CAPABILITY);
	}
}
