<?php

/**
 * Unit tests for the share, delegation, request, attachment and organisation sections of the GDPR metadata export.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\Attachment;
use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\AttachmentGrantMapper;
use OCA\Keepiq\Db\AttachmentMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\LinkShare;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\QuarantinedShareTargetMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Db\SecretRequestMapper;
use OCA\Keepiq\Db\SecretTagMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\GdprService;
use OCA\Keepiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every section of the metadata package carries the subject's rows, flagged and stripped as documented.
 */
#[CoversClass(GdprService::class)]
#[UsesClass(Attachment::class)]
#[UsesClass(AttachmentGrant::class)]
#[UsesClass(EncryptionSuite::class)]
#[UsesClass(LinkShare::class)]
#[UsesClass(Secret::class)]
#[UsesClass(SecretDelegation::class)]
#[UsesClass(SecretRequest::class)]
#[UsesClass(ShareTarget::class)]
class GdprServiceSectionsTest extends TestCase {

	/** @var SecretMapper&MockObject */
	private SecretMapper $secrets;

	/** @var ShareTargetMapper&MockObject */
	private ShareTargetMapper $shares;

	/** @var SecretDelegationMapper&MockObject */
	private SecretDelegationMapper $delegations;

	/** @var SecretRequestMapper&MockObject */
	private SecretRequestMapper $requests;

	/** @var AttachmentMapper&MockObject */
	private AttachmentMapper $attachments;

	/** @var AttachmentGrantMapper&MockObject */
	private AttachmentGrantMapper $grants;

	/** @var SecretTagMapper&MockObject */
	private SecretTagMapper $tags;

	/** @var QuarantinedShareTargetMapper&MockObject */
	private QuarantinedShareTargetMapper $quarantined;

	/** @var EncryptionSuiteMapper&MockObject */
	private EncryptionSuiteMapper $suites;

	/** @var LinkShareMapper&MockObject */
	private LinkShareMapper $links;

	/**
	 * Builds mappers that return nothing unless a test says otherwise.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->secrets     = $this->createMock(SecretMapper::class);
		$this->shares      = $this->createMock(ShareTargetMapper::class);
		$this->delegations = $this->createMock(SecretDelegationMapper::class);
		$this->requests    = $this->createMock(SecretRequestMapper::class);
		$this->attachments = $this->createMock(AttachmentMapper::class);
		$this->grants      = $this->createMock(AttachmentGrantMapper::class);
		$this->tags        = $this->createMock(SecretTagMapper::class);
		$this->quarantined = $this->createMock(QuarantinedShareTargetMapper::class);
		$this->suites      = $this->createMock(EncryptionSuiteMapper::class);
		$this->links       = $this->createMock(LinkShareMapper::class);
	}//end setUp()

	/**
	 * The service, with or without the optional mappers.
	 *
	 * @param bool $optional Whether the attachment, tag and quarantine mappers are wired
	 *
	 * @return GdprService
	 */
	private function service(bool $optional = true): GdprService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getUserPreferences')->willReturn(['theme' => 'dark']);

		return new GdprService(
			secretMapper: $this->secrets,
			shareMapper: $this->shares,
			delegationMapper: $this->delegations,
			linkShareMapper: $this->links,
			requestMapper: $this->requests,
			suiteMapper: $this->suites,
			settingsService: $settings,
			attachmentMapper: ($optional === true) ? $this->attachments : null,
			grantMapper: ($optional === true) ? $this->grants : null,
			tagMapper: ($optional === true) ? $this->tags : null,
			quarantinedMapper: ($optional === true) ? $this->quarantined : null,
		);
	}//end service()

	/**
	 * A secret owned by alice.
	 *
	 * @param string        $id         The id
	 * @param bool          $favourite  Whether it is a favourite
	 * @param DateTime|null $lastUsedAt When it was last used
	 *
	 * @return Secret
	 */
	private function secret(string $id, bool $favourite = false, ?DateTime $lastUsedAt = null): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$secret->setIsFavourite($favourite);
		$secret->setLastUsedAt($lastUsedAt);
		return $secret;
	}//end secret()

	/**
	 * A share row.
	 *
	 * @param string $source    The source secret
	 * @param string $recipient The recipient
	 * @param string $copy      The recipient copy
	 *
	 * @return ShareTarget
	 */
	private function share(string $source, string $recipient, string $copy): ShareTarget {
		$share = new ShareTarget();
		$share->setSourceSecretId($source);
		$share->setTargetUserId($recipient);
		$share->setSecretId($copy);
		$share->setCreatedAt(new DateTime('2026-01-02T03:04:05+00:00'));
		return $share;
	}//end share()

	/**
	 * Given shares list live rows before quarantined ones, each flagged.
	 *
	 * @return void
	 */
	public function testSharesGivenListLiveThenQuarantinedRows(): void {
		$this->secrets->expects($this->once())->method('findByOwner')->willReturnCallback(
			fn (string $ownerType, string $ownerId, mixed ...$rest): array => ($ownerType === 'user' && $ownerId === 'alice' && in_array(100000, $rest, true)) ? [$this->secret('s1')] : []
		);
		$this->shares->method('findBySourceSecret')->with(sourceSecretId: 's1')->willReturn([$this->share('s1', 'bob', 'c1')]);
		$this->quarantined->method('findQuarantinedBySourceSecret')->with(sourceSecretId: 's1')->willReturn([$this->share('s1', 'eve', 'c2')]);

		$package = $this->service()->collectMetadata(userId: 'alice');

		$this->assertSame(
			[
				['sourceSecretId' => 's1', 'targetUserId' => 'bob', 'groupShareId' => null, 'createdAt' => '2026-01-02T03:04:05+00:00', 'quarantined' => false],
				['sourceSecretId' => 's1', 'targetUserId' => 'eve', 'groupShareId' => null, 'createdAt' => '2026-01-02T03:04:05+00:00', 'quarantined' => true],
			],
			$package['sharesGiven']
		);
	}//end testSharesGivenListLiveThenQuarantinedRows()

	/**
	 * Received shares name the recipient copy and the quarantine flag.
	 *
	 * @return void
	 */
	public function testSharesReceivedNameTheRecipientCopy(): void {
		$this->secrets->method('findByOwner')->willReturn([]);
		$this->shares->method('findByTargetUser')->with(targetUserId: 'alice')->willReturn([$this->share('s9', 'alice', 'c9')]);
		$this->quarantined->method('findQuarantinedByTargetUser')->with(targetUserId: 'alice')->willReturn([$this->share('s8', 'alice', 'c8')]);

		$package = $this->service()->collectMetadata(userId: 'alice');

		$this->assertSame(
			[
				['sourceSecretId' => 's9', 'recipientCopyId' => 'c9', 'createdAt' => '2026-01-02T03:04:05+00:00', 'quarantined' => false],
				['sourceSecretId' => 's8', 'recipientCopyId' => 'c8', 'createdAt' => '2026-01-02T03:04:05+00:00', 'quarantined' => true],
			],
			$package['sharesReceived']
		);
	}//end testSharesReceivedNameTheRecipientCopy()

	/**
	 * Without the quarantine mapper only live rows are listed.
	 *
	 * @return void
	 */
	public function testWithoutTheQuarantineMapperOnlyLiveRowsAreListed(): void {
		$this->secrets->method('findByOwner')->willReturn([$this->secret('s1')]);
		$this->shares->method('findBySourceSecret')->willReturn([$this->share('s1', 'bob', 'c1')]);
		$this->shares->method('findByTargetUser')->willReturn([]);
		$this->quarantined->expects($this->never())->method('findQuarantinedBySourceSecret');

		$package = $this->service(optional: false)->collectMetadata(userId: 'alice');

		$this->assertCount(1, $package['sharesGiven']);
		$this->assertFalse($package['sharesGiven'][0]['quarantined']);
		$this->assertSame([], $package['attachments']);
		$this->assertSame([], $package['organisation']);
	}//end testWithoutTheQuarantineMapperOnlyLiveRowsAreListed()

	/**
	 * Delegations and requests carry their dates and flags, never a token.
	 *
	 * @return void
	 */
	public function testDelegationsAndRequestsCarryTheirMetadata(): void {
		$this->secrets->method('findByOwner')->willReturn([]);
		$delegation = new SecretDelegation();
		$delegation->setSecretId('s1');
		$delegation->setDelegatedTo('carol');
		$delegation->setIsPermanent(true);
		$delegation->setDelegatedAt(new DateTime('2026-02-01T00:00:00+00:00'));
		$this->delegations->method('findByOriginalOwner')->with(originalOwnerId: 'alice')->willReturn([$delegation]);

		$request = new SecretRequest();
		$request->setId(7);
		$request->setSecretId('s2');
		$request->setStatus('fulfilled');
		$request->setIsReRequest(false);
		$request->setToken('never-exported');
		$request->setCreatedAt(new DateTime('2026-03-01T00:00:00+00:00'));
		$request->setFulfilledAt(null);
		$this->requests->method('findByCreatedBy')->with(userId: 'alice')->willReturn([$request]);

		$package = $this->service()->collectMetadata(userId: 'alice');

		$this->assertSame(
			[['secretId' => 's1', 'delegatedTo' => 'carol', 'isPermanent' => true, 'delegatedAt' => '2026-02-01T00:00:00+00:00']],
			$package['delegations']
		);
		$this->assertSame(
			[['id' => '7', 'secretId' => 's2', 'status' => 'fulfilled', 'isReRequest' => false, 'createdAt' => '2026-03-01T00:00:00+00:00', 'fulfilledAt' => null]],
			$package['requests']
		);
		$this->assertSame(['theme' => 'dark'], $package['settings']);
	}//end testDelegationsAndRequestsCarryTheirMetadata()

	/**
	 * An attachment carries the subject's wrapped file key when a grant exists, and stays listed without one.
	 *
	 * @return void
	 */
	public function testAttachmentsCarryTheSubjectsWrappedKeyWhenGranted(): void {
		$this->secrets->method('findByOwner')->willReturn([$this->secret('s1')]);
		$this->shares->method('findBySourceSecret')->willReturn([]);
		$this->shares->method('findByTargetUser')->willReturn([]);
		$granted = new Attachment();
		$granted->setId(11);
		$granted->setSourceSecretId('s1');
		$granted->setSizeBytes(10);
		$ungranted = new Attachment();
		$ungranted->setId(12);
		$ungranted->setSourceSecretId('s1');
		$ungranted->setSizeBytes(20);
		$this->attachments->method('findBySourceSecret')->with(sourceSecretId: 's1')->willReturn([$granted, $ungranted]);
		$grant = new AttachmentGrant();
		$grant->setWrappedFileKey('wrapped-11');
		$this->grants->method('findForRecipient')->willReturnCallback(
			static function (int|string $attachmentId, string $recipientId) use ($grant): AttachmentGrant {
				if ((int)$attachmentId === 11 && $recipientId === 'alice') {
					return $grant;
				}

				throw new DoesNotExistException('no grant');
			}
		);

		$rows = $this->service()->collectMetadata(userId: 'alice')['attachments'];

		$this->assertCount(2, $rows);
		$this->assertSame('wrapped-11', $rows[0]['wrappedFileKey']);
		$this->assertSame(10, $rows[0]['sizeBytes']);
		$this->assertArrayNotHasKey('wrappedFileKey', $rows[1]);
		$this->assertSame(20, $rows[1]['sizeBytes']);
	}//end testAttachmentsCarryTheSubjectsWrappedKeyWhenGranted()

	/**
	 * The organisation section lists only secrets with a favourite flag, a tag or a last use.
	 *
	 * @return void
	 */
	public function testOrganisationListsOnlyOrganisedSecrets(): void {
		$used = new DateTime('2026-04-01T00:00:00+00:00');
		$this->secrets->method('findByOwner')->willReturn(
			[$this->secret('plain'), $this->secret('fav', true), $this->secret('tagged'), $this->secret('used', false, $used)]
		);
		$this->shares->method('findBySourceSecret')->willReturn([]);
		$this->shares->method('findByTargetUser')->willReturn([]);
		$this->attachments->method('findBySourceSecret')->willReturn([]);
		$this->tags->expects($this->once())->method('findTagsBySecretIds')
			->with('alice', ['plain', 'fav', 'tagged', 'used'])
			->willReturn(['tagged' => ['work']]);

		$this->assertSame(
			[
				['secretId' => 'fav', 'favourite' => true, 'tags' => [], 'lastUsedAt' => null, 'additionalUrls' => []],
				['secretId' => 'tagged', 'favourite' => false, 'tags' => ['work'], 'lastUsedAt' => null, 'additionalUrls' => []],
				['secretId' => 'used', 'favourite' => false, 'tags' => [], 'lastUsedAt' => '2026-04-01T00:00:00+00:00', 'additionalUrls' => []],
			],
			$this->service()->collectMetadata(userId: 'alice')['organisation']
		);
	}//end testOrganisationListsOnlyOrganisedSecrets()

	/**
	 * Suites never carry the private key; link shares never the token, and say when they were handed over.
	 *
	 * @return void
	 */
	public function testSuitesAndLinkSharesLeaveTheirSecretsOut(): void {
		$this->secrets->method('findByOwner')->willReturn([]);
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('revoked');
		$suite->setCertificate('PEM');
		$suite->setPrivateKey('never-exported');
		$suite->setRevokedAt(new DateTime('2026-05-01T00:00:00+00:00'));
		$suite->setRevokedReason('lost device');
		$suite->setRevokedBy('admin');
		$this->suites->method('findByOwner')->with(ownerType: 'user', ownerId: 'alice')->willReturn([$suite]);

		$own = new LinkShare();
		$own->setId('l1');
		$own->setSecretId('s1');
		$own->setToken('never-exported');
		$own->setUsageLimit(3);
		$own->setUsageCount(1);
		$own->setCreatedBy('alice');
		$handed = new LinkShare();
		$handed->setId('l2');
		$handed->setSecretId('s2');
		$handed->setUsageLimit(0);
		$handed->setUsageCount(0);
		$handed->setCreatedBy('bob');
		$handed->setExpiresAt(new DateTime('2026-06-01T00:00:00+00:00'));
		$this->links->method('findHeldOrMadeBy')->with(userId: 'alice')->willReturn([$own, $handed]);

		$package = $this->service()->collectMetadata(userId: 'alice');

		$this->assertSame(
			[[
				'id' => 'suite-1',
				'status' => 'revoked',
				'certificate' => 'PEM',
				'revokedAt' => '2026-05-01T00:00:00+00:00',
				'revokedReason' => 'lost device',
				'revokedBy' => 'admin',
				'reinstatedAt' => null,
				'reinstatedBy' => null,
				'createdAt' => null,
			]],
			$package['suites']
		);
		$this->assertSame(
			[
				['id' => 'l1', 'secretId' => 's1', 'usageLimit' => 3, 'usageCount' => 1, 'createdAt' => null, 'expiresAt' => null, 'handedOver' => false],
				['id' => 'l2', 'secretId' => 's2', 'usageLimit' => 0, 'usageCount' => 0, 'createdAt' => null, 'expiresAt' => '2026-06-01T00:00:00+00:00', 'handedOver' => true],
			],
			$package['linkShares']
		);
		$this->assertStringNotContainsString('never-exported', json_encode($package));
		$this->assertSame('keepiq-gdpr-metadata', $package['format']);
		$this->assertSame('alice', $package['subject']);
	}//end testSuitesAndLinkSharesLeaveTheirSecretsOut()
}//end class
