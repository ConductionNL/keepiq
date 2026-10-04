<?php

/**
 * Keepiq Federation Partner Test
 *
 * The partner allowlist (keepiq#789, task 1.3 backend and 1.2): adding a
 * partner pins the root fingerprint the administrator confirmed and refuses
 * one that changed since the preview; partner addresses are https; every
 * change is admin-only and needs a fresh password confirmation; the
 * discovery document publishes this instance's root fingerprint, computed
 * like `openssl x509 -fingerprint -sha256`.
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
 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use InvalidArgumentException;
use OCA\Keepiq\Controller\DiscoveryController;
use OCA\Keepiq\Controller\FederationPartnerController;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/FederationFixtures.php';

class FederationPartnerTest extends TestCase {
	use FederationFixtures;

	/** @var array<int,string> URLs the fake HTTP client was asked for */
	private array $fetched = [];

	/**
	 * A partner service whose HTTP client answers with this discovery document.
	 *
	 * @param mixed $document The partner's discovery document
	 * @param array $partners Existing partner rows
	 * @param bool $allowLocal Nextcloud's allow_local_remote_servers
	 *
	 * @return FederationPartnerService
	 */
	private function service(mixed $document, array $partners = [], bool $allowLocal = false): FederationPartnerService {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode($document));
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use ($response): IResponse {
			$this->fetched[] = $url;
			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn($allowLocal);

		return new FederationPartnerService($this->partnerMapper($partners), $clients, $config);
	}

	/**
	 * A discovery document with a federation block.
	 *
	 * @param string $fingerprint The root fingerprint
	 * @param bool $enabled Whether federation is on
	 *
	 * @return array<string,mixed>
	 */
	private function discovery(string $fingerprint, bool $enabled = true): array {
		return ['apiVersion' => 1, 'federation' => ['enabled' => $enabled, 'rootFingerprint' => $fingerprint]];
	}

	public function testPreviewReadsTheRootFingerprintFromThePartnersDiscovery(): void {
		$fingerprint = str_repeat('cd', 32);

		$preview = $this->service($this->discovery($fingerprint))->preview('HTTPS://Cloud.Partner.Example/nc/');

		$this->assertSame(
			['baseUrl' => 'https://cloud.partner.example/nc', 'host' => 'cloud.partner.example', 'rootFingerprint' => $fingerprint],
			$preview
		);
		$this->assertSame(
			['https://cloud.partner.example/nc/index.php/apps/keepiq' . DiscoveryController::CANONICAL_DISCOVERY_PATH],
			$this->fetched
		);
	}

	public function testAddPinsTheConfirmedFingerprintWithBothDirections(): void {
		$fingerprint = str_repeat('cd', 32);

		$partner = $this->service($this->discovery($fingerprint))
			->add('https://cloud.partner.example', strtoupper($fingerprint), true, false, 'admin');

		$this->assertSame('cloud.partner.example', $partner->getHost());
		$this->assertSame($fingerprint, $partner->getRootFingerprint());
		$this->assertTrue($partner->getAllowOutbound());
		$this->assertFalse($partner->getAllowInbound());
		$this->assertSame('admin', $partner->getAddedBy());
	}

	public function testAddRefusesAFingerprintThatChangedSinceThePreview(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('not the one you confirmed');

		$this->service($this->discovery(str_repeat('ee', 32)))
			->add('https://cloud.partner.example', str_repeat('cd', 32), true, true, 'admin');
	}

	public function testAddRefusesAPartnerThatIsAlreadyThere(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('already added');

		$this->service($this->discovery(str_repeat('cd', 32)), [$this->partner('cloud.partner.example', true, true)])
			->add('https://cloud.partner.example', str_repeat('cd', 32), true, true, 'admin');
	}

	/**
	 * Partner documents that do not offer federation.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function noFederation(): array {
		return [
			'no federation block' => [['apiVersion' => 1]],
			'federation off' => [['federation' => ['enabled' => false, 'rootFingerprint' => str_repeat('cd', 32)]]],
			'malformed fingerprint' => [['federation' => ['enabled' => true, 'rootFingerprint' => 'cd:cd']]],
			'not json' => ['<html>'],
		];
	}

	/**
	 * @dataProvider noFederation
	 *
	 * @param mixed $document The partner document
	 */
	public function testPreviewRefusesAPartnerWithoutFederation(mixed $document): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service($document)->preview('https://cloud.partner.example');
	}

	/**
	 * Addresses a partner may not have.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function badAddresses(): array {
		return [
			'plain http' => ['http://cloud.partner.example'],
			'no scheme' => ['cloud.partner.example'],
			'credentials' => ['https://user:pass@cloud.partner.example'],
			'query' => ['https://cloud.partner.example/?x=1'],
			'other scheme' => ['ftp://cloud.partner.example'],
		];
	}

	/**
	 * @dataProvider badAddresses
	 *
	 * @param string $url The address
	 */
	public function testRefusesAnAddressThatIsNotPlainHttps(string $url): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service($this->discovery(str_repeat('cd', 32)))->normaliseBaseUrl($url);
	}

	public function testAllowsHttpOnlyWhereNextcloudAllowsLocalRemoteServers(): void {
		$this->assertSame(
			'http://keepiq-b:8080',
			$this->service([], [], allowLocal: true)->normaliseBaseUrl('http://keepiq-b:8080/')
		);
	}

	public function testHostOfMatchesNextcloudsSignerIdentity(): void {
		$service = $this->service([]);

		$this->assertSame('cloud.partner.example', $service->hostOf('https://Cloud.Partner.Example/nc'));
		$this->assertSame('cloud.partner.example:8443', $service->hostOf('https://cloud.partner.example:8443'));
		$this->assertSame('cloud.partner.example', $service->hostOf('cloud.partner.example'));
		$this->assertNull($service->hostOf(''));
	}

	public function testRootFingerprintIsSha256OverTheDerLikeOpenssl(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$csr = openssl_csr_new(['commonName' => 'Keepiq Root'], $key, ['digest_alg' => 'sha256']);
		openssl_x509_export(openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']), $pem);

		$root = new FederationRootService($this->caMapper());

		$this->assertSame(openssl_x509_fingerprint($pem, 'sha256'), $root->fingerprint($pem));
		$this->assertSame(hash('sha256', 'root-der'), $root->localRootFingerprint());
		$this->assertSame([self::INTERMEDIATE_PEM, self::ROOT_PEM], $root->localChain());
		$this->assertNull($root->fingerprint('not a certificate'));
		$this->assertNull((new FederationRootService($this->caMapper(false)))->localRootFingerprint());
	}

	public function testTheDiscoveryDocumentPublishesTheRootFingerprint(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/index.php/apps/keepiq/x');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);
		$discovery = new DiscoveryController(
			$this->createMock(IRequest::class),
			$urls,
			null,
			null,
			new FederationRootService($this->caMapper()),
		);

		$this->assertSame(
			['enabled' => true, 'rootFingerprint' => hash('sha256', 'root-der')],
			$discovery->document()->getData()['federation']
		);
	}

	public function testEveryPartnerChangeIsAdminOnlyAndNeedsAPasswordConfirmation(): void {
		foreach (['index', 'preview', 'create', 'update', 'destroy'] as $method) {
			$reflection = new ReflectionMethod(FederationPartnerController::class, $method);
			$this->assertNotEmpty($reflection->getAttributes(AuthorizedAdminSetting::class), "$method is not admin-guarded");
		}

		foreach (['create', 'update', 'destroy'] as $method) {
			$reflection = new ReflectionMethod(FederationPartnerController::class, $method);
			$this->assertNotEmpty(
				$reflection->getAttributes(PasswordConfirmationRequired::class),
				"$method does not need a password confirmation"
			);
		}
	}

	public function testBelowNextcloud33NoPartnerCanBeAdded(): void {
		$root = $this->createMock(FederationRootService::class);
		$root->method('isSupported')->willReturn(false);
		$partners = $this->createMock(FederationPartnerService::class);
		$partners->expects($this->never())->method('add');
		$partners->method('all')->willReturn([]);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$controller = new FederationPartnerController($this->createMock(IRequest::class), $partners, $root, $session, $this->createMock(\OCA\Keepiq\Service\FederatedShareService::class));

		$this->assertSame(Http::STATUS_CONFLICT, $controller->create('https://cloud.partner.example', str_repeat('cd', 32), true, true)->getStatus());
		$this->assertSame(Http::STATUS_CONFLICT, $controller->preview('https://cloud.partner.example')->getStatus());
		$this->assertFalse($controller->index()->getData()['supported']);
	}

	public function testThePartnerRowSerialisesWithoutSurprises(): void {
		$row = $this->partner('cloud.partner.example', true, false);

		$this->assertSame(
			['id', 'baseUrl', 'host', 'rootFingerprint', 'allowOutbound', 'allowInbound', 'addedBy', 'addedAt'],
			array_keys($row->jsonSerialize())
		);
		$this->assertInstanceOf(FederationPartner::class, $row);
	}
}
