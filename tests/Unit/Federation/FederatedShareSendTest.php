<?php

/**
 * Sending a federated share: the ciphertext stays on the sending server and
 * the OCM share announces it without it (sharing-federated-recipients 3.1).
 *
 * @category Test
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
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use DateTime;
use OCA\Keepiq\Controller\FederatedShareController;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\FederatedShareMessenger;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\FederatedShareSources;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Tests\Support\ShareRowJudgeFixture;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FederationFixtures.php';
require_once __DIR__ . '/OcmDoubles.php';

class FederatedShareSendTest extends TestCase {
	use FederationFixtures;
	use ShareRowJudgeFixture;

	private const CIPHER_KEY = 'CIPHER-KEY-FOR-BOB';
	private const CIPHER_LOGIN = 'CIPHER-LOGIN-FOR-BOB';
	private const CIPHER_EXTRA = 'CIPHER-EXTRA-FOR-BOB';
	private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private Secret $source;

	/** @var array<string,FederatedShare> */
	private array $rows = [];

	/** @var FakeCloudFederationShare[] */
	private array $sent = [];

	private int $deliveryStatus = 201;

	private ICloudFederationProviderManager&MockObject $ocm;

	/** @var list<ShareTarget> The share rows naming a secret as a copy */
	private array $copyRows = [];

	/** The pending quarantine scan; null holds no row back */
	private ?ShareQuarantineScanner $scanner = null;

	/** @var array<string,Secret> Secrets besides Alice's `src`, by id */
	private array $otherSecrets = [];

	/** @var list<SecretDelegation> The active delegations */
	private array $delegations = [];

	protected function setUp(): void {
		$this->skipWithoutFederation();
		$this->source = new Secret();
		$this->source->setId('src');
		$this->source->setName('Supplier portal');
		$this->source->setUrl('https://supplier.example');
		$this->source->setOwnerType('user');
		$this->source->setOwnerId('alice');
		$this->source->setKey('ALICE-OWN-CIPHERTEXT');
		$this->source->setUpdatedAt(new DateTime('2026-10-01T00:00:00Z'));
	}

	/**
	 * The controller over the given partners, as Alice unless told otherwise.
	 *
	 * @param array $partners The partner rows
	 * @param string $uid The signed-in user
	 *
	 * @return FederatedShareController
	 */
	private function controller(array $partners, string $uid = 'alice'): FederatedShareController {
		$secrets = $this->secretTable();

		$mapper = $this->createMock(FederatedShareMapper::class);
		$mapper->method('insert')->willReturnCallback(
			function (FederatedShare $row): FederatedShare {
				$this->rows[$row->getId()] = $row;
				return $row;
			}
		);
		$mapper->method('delete')->willReturnCallback(
			function (FederatedShare $row): FederatedShare {
				unset($this->rows[$row->getId()]);
				return $row;
			}
		);
		$mapper->method('findBySourceSecret')->willReturnCallback(
			fn (string $sourceSecretId): array => array_values(
				array_filter($this->rows, static fn (FederatedShare $row): bool => $row->getSourceSecretId() === $sourceSecretId)
			)
		);
		$mapper->method('findById')->willReturnCallback(
			fn (string $id): FederatedShare => ($this->rows[$id] ?? throw new DoesNotExistException('no share'))
		);

		$factory = $this->createMock(ICloudFederationFactory::class);
		$factory->method('getCloudFederationShare')->willReturnCallback(
			static function (...$args): FakeCloudFederationShare {
				[$shareWith, $name, $description, $providerId, $owner, $ownerName, $sharedBy, $sharedByName, $secret, $type, $resourceType] = $args;
				$share = new FakeCloudFederationShare();
				$share->setShareWith($shareWith);
				$share->setResourceName($name);
				$share->setDescription($description);
				$share->setProviderId($providerId);
				$share->setOwner($owner);
				$share->setOwnerDisplayName($ownerName);
				$share->setSharedBy($sharedBy);
				$share->setSharedByDisplayName($sharedByName);
				$share->setShareType($type);
				$share->setResourceType($resourceType);
				$share->setProtocol(['name' => 'webdav', 'options' => ['sharedSecret' => $secret]]);
				return $share;
			}
		);

		$this->ocm = $this->createMock(ICloudFederationProviderManager::class);
		$this->ocm->method('sendCloudShare')->willReturnCallback(
			function (FakeCloudFederationShare $share): IResponse {
				$this->sent[] = $share;
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($this->deliveryStatus);
				return $response;
			}
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('s', 64));

		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturn('Alice Adams');

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);

		$service = new FederatedShareService(
			shareMapper: $mapper,
			sources: new FederatedShareSources(secretMapper: $secrets, rowJudge: $this->shareRowJudge(targets: $this->shareTargets(), secrets: $secrets, scanner: $this->scanner)),
			partners: new FederationPartnerService($this->partnerMapper($partners), $this->createMock(IClientService::class), $config),
			root: new FederationRootService($this->caMapper()),
			cloudIdManager: $this->cloudIdManager(),
			messenger: new FederatedShareMessenger(
				providerManager: $this->ocm,
				factory: $factory,
				cloudIdManager: $this->cloudIdManager(),
				userManager: $users,
			),
			random: $random,
			delivery: $this->createMock(\OCA\Keepiq\Service\FederatedNotificationDelivery::class),
			audit: new \OCA\Keepiq\Service\FederatedShareAuditTrail(),
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new FederatedShareController($this->createMock(IRequest::class), $service, $session);
	}

	/**
	 * The secrets: Alice's `src` and $this->otherSecrets.
	 *
	 * @return SecretMapper
	 */
	private function secretTable(): SecretMapper {
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(
			fn (string $id): Secret => ($id === 'src') ? $this->source : ($this->otherSecrets[$id] ?? throw new DoesNotExistException('none'))
		);

		return $secrets;
	}

	/**
	 * The share rows, found by the copy they name or by their pair from $this->copyRows.
	 *
	 * @return ShareTargetMapper
	 */
	private function shareTargets(): ShareTargetMapper {
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			function (string $sourceId, string $targetUserId): ShareTarget {
				foreach ($this->copyRows as $row) {
					if ($row->getSourceSecretId() === $sourceId && $row->getTargetUserId() === $targetUserId) {
						return $row;
					}
				}

				throw new DoesNotExistException('no row');
			}
		);
		$targets->method('findByRecipientSecret')->willReturnCallback(
			function (string $copyId): ShareTarget {
				$rows = array_values(array_filter($this->copyRows, static fn (ShareTarget $row): bool => $row->getSecretId() === $copyId));
				if ($rows === []) {
					throw new DoesNotExistException('not a copy');
				}

				if (count($rows) > 1) {
					throw new MultipleObjectsReturnedException('two rows');
				}

				return $rows[0];
			}
		);

		return $targets;
	}

	/**
	 * A share row making `src` the copy that `$holder` received of `orig`.
	 *
	 * @param string $holder The row's target
	 * @param string|null $groupShareId The group share it came from, if any
	 * @param string|null $teamFolderId The team folder it came from, if any
	 *
	 * @return ShareTarget
	 */
	private function copyRow(string $holder = 'alice', ?string $groupShareId = null, ?string $teamFolderId = null): ShareTarget {
		$row = new ShareTarget();
		$row->setId('st-'.count($this->copyRows));
		$row->setSourceSecretId('orig');
		$row->setSecretId('src');
		$row->setTargetUserId($holder);
		$row->setGroupShareId($groupShareId);
		$row->setTeamFolderId($teamFolderId);

		return $row;
	}

	/**
	 * Olivia owns `orig` and has an active delegation of it to Alice, whose copy `src` is.
	 *
	 * @return ShareAuthorizationService The authorization service over that delegation
	 */
	private function aliceIsOliviasDelegate(): ShareAuthorizationService {
		$orig = new Secret();
		$orig->setId('orig');
		$orig->setOwnerType('user');
		$orig->setOwnerId('olivia');
		$this->otherSecrets['orig'] = $orig;

		$delegation = new SecretDelegation();
		$delegation->setSecretId('orig');
		$delegation->setOriginalOwnerId('olivia');
		$delegation->setDelegatedTo('alice');
		$delegation->setIsPermanent(false);
		$this->delegations[] = $delegation;

		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('findActiveBySecretAndUser')->willReturnCallback(
			function (string $secretId, string $userId): SecretDelegation {
				foreach ($this->delegations as $delegation) {
					if ($delegation->getSecretId() === $secretId && $delegation->getDelegatedTo() === $userId) {
						return $delegation;
					}
				}

				throw new DoesNotExistException('no delegation');
			}
		);
		$delegations->method('findBySecret')->willReturnCallback(
			fn (string $secretId): array => array_values(
				array_filter($this->delegations, static fn (SecretDelegation $delegation): bool => $delegation->getSecretId() === $secretId)
			)
		);

		$secrets = $this->secretTable();
		return new ShareAuthorizationService(
			secretMapper: $secrets,
			delegationMapper: $delegations,
			rowJudge: $this->shareRowJudge(targets: $this->shareTargets(), secrets: $secrets),
		);
	}

	/**
	 * A pending quarantine scan answering `$verdict` for every row.
	 *
	 * @param string $verdict What heldBack() answers
	 *
	 * @return ShareQuarantineScanner
	 */
	private function scanAnswering(string $verdict): ShareQuarantineScanner {
		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->method('heldBack')->willReturn($verdict);

		return $scanner;
	}

	/**
	 * Alice shares with bob@cloud.partner.example.
	 *
	 * @param FederatedShareController $controller The controller
	 * @param string $cloudId The recipient
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function share(FederatedShareController $controller, string $cloudId = 'bob@cloud.partner.example') {
		return $controller->create('src', $cloudId, self::FINGERPRINT, self::CIPHER_KEY, self::CIPHER_LOGIN, self::CIPHER_EXTRA);
	}

	public function testTheOcmShareAnnouncesTheShareWithoutTheCiphertext(): void {
		$response = $this->share($this->controller([$this->partner('cloud.partner.example', true, false)]));

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertCount(1, $this->sent);
		$ocm = $this->sent[0]->getShare();

		// Nothing the owner's browser encrypted travels in the announcement,
		// nor the owner's own ciphertext.
		$wire = json_encode($ocm);
		foreach ([self::CIPHER_KEY, self::CIPHER_LOGIN, self::CIPHER_EXTRA, 'ALICE-OWN-CIPHERTEXT'] as $cipher) {
			$this->assertStringNotContainsString($cipher, $wire);
		}

		$row = array_values($this->rows)[0];
		$this->assertSame('keepiq-secret', $ocm['resourceType']);
		$this->assertSame('user', $ocm['shareType']);
		$this->assertSame('bob@cloud.partner.example', $ocm['shareWith']);
		$this->assertSame('alice@cloud.here.example', $ocm['owner']);
		$this->assertSame('Supplier portal', $ocm['name']);
		$this->assertSame($row->getId(), $ocm['providerId']);

		// The shared secret travels; only its hash is kept here.
		$secret = $this->sent[0]->getShareSecret();
		$this->assertSame(64, strlen($secret));
		$this->assertSame(hash('sha256', $secret), $row->getSharedSecretHash());

		// The ciphertext is stored for the pull.
		$this->assertSame(self::CIPHER_KEY, $row->getKey());
		$this->assertSame(self::CIPHER_LOGIN, $row->getLogin());
		$this->assertSame(self::CIPHER_EXTRA, $row->getAdditionalFields());
		$this->assertSame(self::FINGERPRINT, $row->getRecipientCertFingerprint());
		$this->assertSame('p-cloud.partner.example', $row->getPartnerId());

		// And the owner's answer carries none of it either.
		$this->assertArrayNotHasKey('key', $response->getData());
		$this->assertArrayNotHasKey('sharedSecretHash', $response->getData());
	}

	public function testNothingIsSentToAnInstanceThatIsNoOutboundPartner(): void {
		$this->ocm = $this->createMock(ICloudFederationProviderManager::class);
		$cases = [
			'stranger' => [[], 'bob@cloud.stranger.example'],
			'inbound only' => [[$this->partner('cloud.partner.example', false, true)], 'bob@cloud.partner.example'],
		];
		foreach ($cases as $label => [$partners, $cloudId]) {
			$response = $this->share($this->controller($partners), $cloudId);
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $label);
			$this->assertSame('not_a_partner', $response->getData()['message'], $label);
		}

		$this->assertSame([], $this->sent);
		$this->assertSame([], $this->rows);
	}

	public function testOnlyTheOwnerMayShare(): void {
		$response = $this->share($this->controller([$this->partner('cloud.partner.example', true, false)], 'mallory'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->sent);
	}

	public function testAReadOnlyCopyIsNotSharedOnward(): void {
		$this->source->setReadOnly(true);

		$response = $this->share($this->controller([$this->partner('cloud.partner.example', true, false)]));

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->sent);
	}

	public function testTheSameRecipientIsNotSharedWithTwice(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->share($controller);

		$response = $this->share($controller);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('already_shared', $response->getData()['message']);
		$this->assertCount(1, $this->sent);
	}

	public function testTheOwnerCanShareAgainAfterASuspendedOrFailedShare(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		foreach (['suspended', 'failed', 'revoked'] as $status) {
			$this->share($controller);
			foreach ($this->rows as $row) {
				$row->setStatus($status);
			}

			// The owner's list says "Revoke it or share again": sharing again works.
			$this->assertSame(Http::STATUS_CREATED, $this->share($controller)->getStatus(), $status);
			$this->rows = [];
		}
	}

	public function testAMalformedRequestIsRefusedBeforeAnythingIsStored(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->create('src', 'bob@cloud.partner.example', 'not-a-fingerprint', self::CIPHER_KEY)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->create('src', 'bob@cloud.partner.example', self::FINGERPRINT, '')->getStatus());
		$this->assertSame([], $this->rows);
	}

	public function testARefusedDeliveryKeepsNothing(): void {
		$this->deliveryStatus = 400;

		$response = $this->share($this->controller([$this->partner('cloud.partner.example', true, false)]));

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('delivery_failed', $response->getData()['message']);
		$this->assertSame([], $this->rows);
	}

	public function testTheOwnerListsTheSharesOfASecret(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->share($controller);

		$list = $controller->index('src');

		$this->assertSame(Http::STATUS_OK, $list->getStatus());
		$this->assertSame('bob@cloud.partner.example', $list->getData()[0]['recipientCloudId']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller([], 'mallory')->index('src')->getStatus());
	}

	/**
	 * Every way a share row can name Alice's `src` as a copy she received.
	 *
	 * @return array<string,array{0:list<array{0:string,1:?string,2:?string}>,1:?string,2:int,3:string,4?:bool}>
	 *   The rows (holder, group share, team folder), the scan's verdict, the status, the answer's code,
	 *   and whether Alice holds an active delegation of the source
	 */
	public static function receivedCopies(): array {
		return [
			'a plain recipient\'s copy' => [[['alice', null, null]], null, Http::STATUS_BAD_REQUEST, 'not_shareable'],
			// A delegation on the source changes nothing here: partner shares are the owner's.
			'a delegate\'s copy' => [[['alice', null, null]], null, Http::STATUS_BAD_REQUEST, 'not_shareable', true],
			'a copy reached through a group share' => [[['alice', 'gs-1', null]], null, Http::STATUS_BAD_REQUEST, 'not_shareable'],
			'a copy reached through a team folder' => [[['alice', null, 'tf-1']], null, Http::STATUS_BAD_REQUEST, 'not_shareable'],
			'a copy two share rows name' => [[['alice', null, null], ['alice', 'gs-1', null]], null, Http::STATUS_BAD_REQUEST, 'not_shareable'],
			'a row whose target does not hold it' => [[['carol', null, null]], null, Http::STATUS_BAD_REQUEST, 'not_shareable'],
			'a row the pending scan cannot judge' => [[['alice', null, null]], ShareQuarantineScanner::HELD_UNJUDGED, Http::STATUS_CONFLICT, 'share_scan_pending'],
			'a foreign row the pending scan cannot judge' => [[['carol', null, null]], ShareQuarantineScanner::HELD_UNJUDGED, Http::STATUS_CONFLICT, 'share_scan_pending'],
		];
	}

	/**
	 * @param list<array{0:string,1:?string,2:?string}> $rows The share rows naming `src`
	 * @param string|null $verdict What the pending scan answers for each, null when it holds none back
	 * @param int $status The status the refusal answers
	 * @param string $code The refusal's code
	 * @param bool $delegated Whether Alice holds an active delegation of the source
	 *
	 * @dataProvider receivedCopies
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#requirement-a-received-copy-is-not-shared-with-a-partner
	 */
	public function testAReceivedCopyIsNotSharedWithAPartner(array $rows, ?string $verdict, int $status, string $code, bool $delegated = false): void {
		foreach ($rows as [$holder, $groupShareId, $teamFolderId]) {
			$this->copyRows[] = $this->copyRow(holder: $holder, groupShareId: $groupShareId, teamFolderId: $teamFolderId);
		}

		if ($verdict !== null) {
			$this->scanner = $this->scanAnswering(verdict: $verdict);
		}

		if ($delegated === true) {
			// The delegation is live: Alice is Olivia's delegate and may share her copy onward through other paths.
			$auth = $this->aliceIsOliviasDelegate();
			$this->assertTrue($auth->isOwnerOrDelegate(secret: $this->otherSecrets['orig'], userId: 'alice'));
			$auth->assertMayShareOnward(source: $this->otherSecrets['orig'], userId: 'alice');
		}

		$response = $this->share($this->controller([$this->partner('cloud.partner.example', true, false)]));

		$this->assertSame($status, $response->getStatus());
		$data = $response->getData();
		$this->assertSame($code, ($data['error'] ?? $data['message']));
		$this->assertSame([], $this->rows);
		$this->assertSame([], $this->sent);
	}

	/**
	 * `src` is Alice's copy of `orig`, which `$owner` owns: Alice herself after a transfer made her its owner.
	 *
	 * @param string $owner The source's owner
	 * @param string $ownerType The kind of owner: a user, or an organisation whose id may equal a user's
	 *
	 * @return void
	 */
	private function aliceHoldsACopyOfASourceOwnedBy(string $owner, string $ownerType = 'user'): void {
		$orig = new Secret();
		$orig->setId('orig');
		$orig->setName('Supplier portal');
		$orig->setOwnerType($ownerType);
		$orig->setOwnerId($owner);
		$this->otherSecrets['orig'] = $orig;
		$this->copyRows[] = $this->copyRow();
	}

	/**
	 * Alice owns `orig` and works through her own copy `src`, as after a transfer the new owner does: the partner share
	 * is made on the source.
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#scenario-the-new-owner-shares-their-own-copy-with-a-partner
	 */
	public function testTheSourcesOwnerSharesTheirOwnCopy(): void {
		$this->aliceHoldsACopyOfASourceOwnedBy(owner: 'alice');

		$response = $this->share($this->controller([$this->partner('cloud.partner.example', true, false)]));

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertCount(1, $this->rows);
		$row = array_values($this->rows)[0];
		$this->assertSame('orig', $row->getSourceSecretId());
		$this->assertSame('alice', $row->getOwnerId());
		$this->assertSame(self::CIPHER_KEY, $row->getKey());
		$this->assertSame(self::FINGERPRINT, $row->getRecipientCertFingerprint());
		$this->assertCount(1, $this->sent);
	}

	/**
	 * The share the owner made through their copy shows in the list of that copy, next to one made from the copy
	 * before such shares were refused, and the owner revokes it from there.
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#scenario-the-new-owner-shares-their-own-copy-with-a-partner
	 */
	public function testTheSourcesOwnerListsAndRevokesThroughTheirOwnCopy(): void {
		$this->aliceHoldsACopyOfASourceOwnedBy(owner: 'alice');
		$old = new FederatedShare();
		$old->setId('fs-old');
		$old->setSourceSecretId('src');
		$old->setOwnerId('alice');
		$old->setRecipientCloudId('carol@cloud.partner.example');
		$old->setStatus(FederatedShare::STATUS_ACTIVE);
		$this->rows['fs-old'] = $old;
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$made = $this->share($controller)->getData();

		$listed = array_column($controller->index('src')->getData(), 'id');

		$this->assertEqualsCanonicalizing([$made['id'], 'fs-old'], $listed);
		$this->assertSame(Http::STATUS_OK, $controller->destroy($made['id'])->getStatus());
		$this->assertSame(FederatedShare::STATUS_REVOKED, $this->rows[$made['id']]->getStatus());
	}

	/**
	 * A copy holder who does not own the source sees only the partner shares of their own copy.
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#scenario-the-new-owner-shares-their-own-copy-with-a-partner
	 */
	public function testACopyHolderListsOnlyTheirOwnCopysShares(): void {
		$this->aliceHoldsACopyOfASourceOwnedBy(owner: 'olivia');
		foreach (['fs-orig' => 'orig', 'fs-src' => 'src'] as $id => $sourceId) {
			$row = new FederatedShare();
			$row->setId($id);
			$row->setSourceSecretId($sourceId);
			$row->setOwnerId('olivia');
			$this->rows[$id] = $row;
		}

		$listed = array_column($this->controller([])->index('src')->getData(), 'id');

		$this->assertSame(['fs-src'], $listed);
	}

	/**
	 * The same request from a copy holder who does not own the source is refused as before.
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#scenario-the-new-owner-shares-their-own-copy-with-a-partner
	 */
	public function testACopyOfASourceTheHolderDoesNotOwnIsStillRefused(): void {
		$this->aliceHoldsACopyOfASourceOwnedBy(owner: 'olivia');

		$response = $this->share($this->controller([$this->partner('cloud.partner.example', true, false)]));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('not_shareable', $response->getData()['message']);
		$this->assertSame([], $this->rows);
		$this->assertSame([], $this->sent);
	}

	/**
	 * A source an organisation owns is not the caller's, even when the organisation's id equals the caller's user id:
	 * the copy is refused and its list does not reach the source.
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#scenario-the-new-owner-shares-their-own-copy-with-a-partner
	 */
	public function testASourceAnOrganisationOwnsUnderTheCallersIdIsNotTheirs(): void {
		$this->aliceHoldsACopyOfASourceOwnedBy(owner: 'alice', ownerType: 'organisation');
		$orig = new FederatedShare();
		$orig->setId('fs-orig');
		$orig->setSourceSecretId('orig');
		$orig->setOwnerId('alice');
		$this->rows['fs-orig'] = $orig;
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);

		$response = $this->share($controller);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('not_shareable', $response->getData()['message']);
		$this->assertSame(['fs-orig'], array_keys($this->rows));
		$this->assertSame([], $this->sent);
		$this->assertSame([], $controller->index('src')->getData());
	}

	/**
	 * A copy of a secret the holder owns waits for the pending scan like any copy: nothing leads to the source
	 * until the scan has judged the row.
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#requirement-a-received-copy-is-not-shared-with-a-partner
	 */
	public function testTheOwnersCopyTheScanCannotJudgeWaits(): void {
		$this->aliceHoldsACopyOfASourceOwnedBy(owner: 'alice');
		$this->scanner = $this->scanAnswering(verdict: ShareQuarantineScanner::HELD_UNJUDGED);

		$response = $this->share($this->controller([$this->partner('cloud.partner.example', true, false)]));

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('share_scan_pending', ($data['error'] ?? $data['message']));
		$this->assertSame([], $this->rows);
		$this->assertSame([], $this->sent);
	}

	/**
	 * An original, or a secret whose only row the pending scan quarantines,
	 * is Alice's own, as register-batch and group shares judge it.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public static function originals(): array {
		return [
			'no share row names it' => [false],
			'the row naming it is quarantined' => [true],
		];
	}

	/**
	 * @param bool $quarantined Whether a quarantined row names `src`
	 *
	 * @dataProvider originals
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#scenario-the-owners-original-is-still-shared
	 */
	public function testTheOwnersOriginalIsStillShared(bool $quarantined): void {
		if ($quarantined === true) {
			$this->copyRows[] = $this->copyRow();
			$this->scanner = $this->scanAnswering(verdict: ShareQuarantineScanner::HELD_QUARANTINED);
		}

		$response = $this->share($this->controller([$this->partner('cloud.partner.example', true, false)]));

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertCount(1, $this->rows);
		$this->assertCount(1, $this->sent);
	}
}
