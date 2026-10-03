<?php

/**
 * Keepiq Federation Lookup Test
 *
 * The owner-facing certificate lookup (keepiq#789, task 2.2), through
 * FederationController and the real FederatedCertificateService and
 * FederationPartnerService. Nextcloud's IOCMDiscoveryService is doubled: the
 * test asserts the exact OCM call (capability, partner, subpath, payload,
 * method) and that nothing is sent to an instance that is no outbound
 * partner.
 *
 * Also the OCM discovery listener: the `keepiq` capability is advertised
 * only while a partner exists.
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
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use OCA\Keepiq\Controller\FederationController;
use OCA\Keepiq\Listener\FederationOcmDiscoveryListener;
use OCA\Keepiq\Service\FederatedCertificateService;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCA\Keepiq\Service\ShareService;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;
use OCP\OCM\IOCMDiscoveryService;
use OCP\OCM\IOCMProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/FederationFixtures.php';

class FederationLookupTest extends TestCase {
	use FederationFixtures;

	private IOCMDiscoveryService&MockObject $ocm;

	/**
	 * The controller over the given partners.
	 *
	 * @param array $partners The partner rows
	 *
	 * @return FederationController
	 */
	private function controller(array $partners): FederationController {
		$this->ocm = $this->createMock(IOCMDiscoveryService::class);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$service = new FederatedCertificateService(
			new FederationPartnerService($this->partnerMapper($partners), $this->createMock(IClientService::class), $config),
			new FederationRootService($this->caMapper()),
			$this->createMock(ShareService::class),
			$this->cloudIdManager(),
			$this->createMock(IUserManager::class),
			$config,
			$this->ocm,
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		return new FederationController($this->createMock(IRequest::class), $service, $session);
	}

	/**
	 * A partner's HTTP answer.
	 *
	 * @param int $status The status
	 * @param mixed $body The JSON body
	 *
	 * @return IResponse
	 */
	private function response(int $status, mixed $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn(json_encode($body));

		return $response;
	}

	public function testAsksTheOutboundPartnerOverOcmAndReturnsThePinnedRoot(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->ocm->expects($this->once())
			->method('requestRemoteOcmEndpoint')
			->with(
				'keepiq',
				'https://cloud.partner.example',
				'keepiq/recipient-certificate',
				['cloudId' => 'bob@cloud.partner.example'],
				'post',
			)
			->willReturn($this->response(200, ['certificate' => 'BOB', 'chain' => ['INT', 'ROOT']]));

		$response = $controller->recipientCertificate('bob@cloud.partner.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'cloudId' => 'bob@cloud.partner.example',
				'certificate' => 'BOB',
				'chain' => ['INT', 'ROOT'],
				'partnerRootFingerprint' => str_repeat('ab', 32),
			],
			$response->getData()
		);
	}

	public function testSendsNothingToAnInstanceThatIsNoPartner(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->ocm->expects($this->never())->method('requestRemoteOcmEndpoint');

		$response = $controller->recipientCertificate('bob@cloud.stranger.example');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'not_a_partner'], $response->getData());
	}

	public function testSendsNothingToAPartnerWithoutOutbound(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', false, true)]);
		$this->ocm->expects($this->never())->method('requestRemoteOcmEndpoint');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->recipientCertificate('bob@cloud.partner.example')->getStatus()
		);
	}

	public function testReportsAnUnknownRecipientOnThePartnersRefusal(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->ocm->method('requestRemoteOcmEndpoint')
			->willReturn($this->response(404, ['message' => 'Unknown recipient']));

		$response = $controller->recipientCertificate('bob@cloud.partner.example');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'unknown_recipient'], $response->getData());
	}

	public function testReportsAnUnreachablePartnerOnAFailureOrAMalformedAnswer(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->ocm->method('requestRemoteOcmEndpoint')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new RuntimeException('timeout')),
				$this->response(200, ['certificate' => 7]),
			);

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $controller->recipientCertificate('bob@cloud.partner.example')->getStatus());
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $controller->recipientCertificate('bob@cloud.partner.example')->getStatus());
	}

	public function testAnInvalidCloudIdIsAnUnknownRecipient(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->ocm->expects($this->never())->method('requestRemoteOcmEndpoint');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->recipientCertificate('not-a-cloud-id')->getStatus());
	}

	public function testRefusesWithoutASession(): void {
		$controller = $this->controller([]);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$anonymous = new FederationController(
			$this->createMock(IRequest::class),
			$this->createMock(FederatedCertificateService::class),
			$session
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->recipientCertificate('bob@cloud.partner.example')->getStatus());
	}

	/**
	 * No partner, no federation: the share dialog is told it may offer a
	 * federated recipient only while an outbound partner exists.
	 */
	public function testTheDialogIsOfferedFederationOnlyWithAnOutboundPartner(): void {
		$this->assertFalse($this->controller([])->status()->getData()['outbound']);
		$this->assertFalse($this->controller([$this->partner('cloud.partner.example', false, true)])->status()->getData()['outbound']);
		$this->assertTrue($this->controller([$this->partner('cloud.partner.example', true, false)])->status()->getData()['outbound']);
	}

	public function testAdvertisesTheKeepiqCapabilityOnlyWhileAPartnerExists(): void {
		$config = $this->createMock(IConfig::class);
		$withPartner = new FederationOcmDiscoveryListener(
			new FederationPartnerService(
				$this->partnerMapper([$this->partner('cloud.partner.example', true, true)]),
				$this->createMock(IClientService::class),
				$config
			)
		);
		$withoutPartner = new FederationOcmDiscoveryListener(
			new FederationPartnerService($this->partnerMapper([]), $this->createMock(IClientService::class), $config)
		);

		$advertising = $this->createMock(IOCMProvider::class);
		$advertising->expects($this->once())->method('setCapabilities')->with(['keepiq']);
		$withPartner->handle(new LocalOCMDiscoveryEvent($advertising));

		$silent = $this->createMock(IOCMProvider::class);
		$silent->expects($this->never())->method('setCapabilities');
		$withoutPartner->handle(new LocalOCMDiscoveryEvent($silent));
	}
}
