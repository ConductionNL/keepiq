<?php

/**
 * Unit tests for deleting one attachment and listing a secret's attachments for a reader.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\Attachment;
use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\AttachmentGrantMapper;
use OCA\Keepiq\Db\AttachmentMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Service\AttachmentService;
use OCA\Keepiq\Service\WriteLockService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * delete() is owner-gated, removes every grant, the blob once no grant holds it, and the row,
 * and audits it; listForSecret() answers only what the reader holds a grant for.
 *
 * @covers \OCA\Keepiq\Service\AttachmentService
 * @uses \OCA\Keepiq\Db\Attachment
 * @uses \OCA\Keepiq\Db\AttachmentGrant
 * @uses \OCA\Keepiq\Db\Secret
 * @uses \OCA\Keepiq\Db\StringPrimaryKey
 * @uses \OCA\Keepiq\Event\Audit\AuditEvent
 * @uses \OCA\Keepiq\Event\Audit\AuditEventFactory
 */
final class AttachmentDeleteAndListTest extends TestCase {

	/**
	 * What happened, in order.
	 *
	 * @var list<mixed>
	 */
	private array $trace = [];

	/**
	 * The attachment att-1 of secret sec-1, blob blob.bin.
	 *
	 * @var Attachment
	 */
	private Attachment $attachment;

	/**
	 * Seed the attachment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->attachment = self::attachment(id: 'att-1');
	}//end setUp()

	/**
	 * An attachment of sec-1.
	 *
	 * @param string $id The id.
	 *
	 * @return Attachment
	 */
	private static function attachment(string $id): Attachment {
		$attachment = new Attachment();
		$attachment->setId($id);
		$attachment->setSourceSecretId('sec-1');
		$attachment->setBlobRef($id.'.bin');
		$attachment->setSizeBytes(2048);

		return $attachment;
	}//end attachment()

	/**
	 * A grant on an attachment.
	 *
	 * @param string $id           The grant id.
	 * @param string $attachmentId The attachment.
	 *
	 * @return AttachmentGrant
	 */
	private static function grant(string $id, string $attachmentId): AttachmentGrant {
		$grant = new AttachmentGrant();
		$grant->setId($id);
		$grant->setAttachmentId($attachmentId);
		$grant->setWrappedFileKey('wrapped-'.$id);

		return $grant;
	}//end grant()

	/**
	 * The service over sec-1 owned by alice, with the given grants left after deletion and blob state.
	 *
	 * @param int  $grantsLeft  What countByAttachment answers.
	 * @param bool $blobMissing Whether the blob is already gone from both namespaces.
	 *
	 * @return AttachmentService
	 */
	private function service(int $grantsLeft = 0, bool $blobMissing = false): AttachmentService {
		$mapper = $this->createMock(originalClassName: AttachmentMapper::class);
		$mapper->method('findById')->willReturnCallback(
			fn (string $id): Attachment => $id === 'att-1' ? $this->attachment : throw new DoesNotExistException('none')
		);
		$mapper->method('delete')->willReturnCallback(
			function (Attachment $attachment): Attachment {
				$this->trace[] = 'row:'.$attachment->getId();
				return $attachment;
			}
		);
		$mapper->method('findBySourceSecret')->with('sec-1')->willReturn([$this->attachment, self::attachment(id: 'att-2'), self::attachment(id: 'att-3')]);

		$grants = $this->createMock(originalClassName: AttachmentGrantMapper::class);
		$grants->method('findByAttachment')->willReturn([self::grant(id: 'g-1', attachmentId: 'att-1'), self::grant(id: 'g-2', attachmentId: 'att-1')]);
		$grants->method('delete')->willReturnCallback(
			function (AttachmentGrant $grant): AttachmentGrant {
				$this->trace[] = 'grant:'.$grant->getId();
				return $grant;
			}
		);
		$grants->method('countByAttachment')->willReturn($grantsLeft);
		$grants->method('findForRecipient')->willReturnCallback(
			fn (string $attachmentId, string $recipientId): AttachmentGrant => $attachmentId !== 'att-2' && $recipientId === 'bob'
				? self::grant(id: 'g-'.$attachmentId, attachmentId: $attachmentId)
				: throw new DoesNotExistException('no grant')
		);

		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setName('Wiki');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$secrets = $this->createMock(originalClassName: SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(
			fn (string $id): Secret => $id === 'sec-1' ? $secret : throw new DoesNotExistException('none')
		);

		$file = $this->createMock(originalClassName: ISimpleFile::class);
		$file->method('delete')->willReturnCallback(
			function (): void {
				$this->trace[] = 'blob';
			}
		);
		$folder = $this->createMock(originalClassName: ISimpleFolder::class);
		if ($blobMissing === true) {
			$folder->method('getFile')->willThrowException(new NotFoundException('gone'));
		} else {
			$folder->method('getFile')->willReturn($file);
		}

		$appData = $this->createMock(originalClassName: IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$appDataFactory = $this->createMock(originalClassName: IAppDataFactory::class);
		$appDataFactory->method('get')->willReturn($appData);

		$events = $this->createMock(originalClassName: IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (AuditEvent $event): void {
				$this->trace[] = [$event->getEventType(), $event->getActorId(), $event->getObjectId(), $event->getMetadata()];
			}
		);

		return new AttachmentService(
			mapper: $mapper,
			grantMapper: $grants,
			secretMapper: $secrets,
			suiteMapper: $this->createMock(originalClassName: EncryptionSuiteMapper::class),
			appDataFactory: $appDataFactory,
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			writeLockService: $this->createMock(originalClassName: WriteLockService::class),
			eventDispatcher: $events,
		);
	}//end service()

	/**
	 * The owner's delete removes the grants, then the orphaned blob, then the row, and audits it.
	 *
	 * @return void
	 */
	public function testTheOwnerDeletesGrantsBlobAndRow(): void {
		$this->service()->delete(attachmentId: 'att-1', userId: 'alice');

		$this->assertSame(expected: ['grant:g-1', 'grant:g-2', 'blob', 'row:att-1'], actual: array_slice($this->trace, 0, 4));
		[$type, $actor, $object, $metadata] = $this->trace[4];
		$this->assertSame(expected: ['alice', 'att-1', ['secretId' => 'sec-1', 'sizeBytes' => 2048]], actual: [$actor, $object, $metadata]);
		$this->assertStringContainsString(needle: 'attachment', haystack: $type);
	}//end testTheOwnerDeletesGrantsBlobAndRow()

	/**
	 * A blob another grant still holds is kept; a blob already gone is tolerated.
	 *
	 * @return void
	 */
	public function testTheBlobIsKeptWhileHeldAndAMissingOneIsTolerated(): void {
		$this->service(grantsLeft: 1)->delete(attachmentId: 'att-1', userId: 'alice');
		$this->assertNotContains(needle: 'blob', haystack: $this->trace);
		$this->assertContains(needle: 'row:att-1', haystack: $this->trace);

		$this->trace = [];
		$this->service(blobMissing: true)->delete(attachmentId: 'att-1', userId: 'alice');
		$this->assertSame(expected: ['grant:g-1', 'grant:g-2', 'row:att-1'], actual: array_slice($this->trace, 0, 3));
	}//end testTheBlobIsKeptWhileHeldAndAMissingOneIsTolerated()

	/**
	 * Anyone but the owner, and an unknown attachment, are refused before anything is deleted.
	 *
	 * @return void
	 */
	public function testOnlyTheOwnerDeletes(): void {
		foreach ([['att-1', 'bob', 'Not authorized for this secret'], ['att-9', 'alice', 'Attachment not found']] as [$id, $user, $message]) {
			try {
				$this->service()->delete(attachmentId: $id, userId: $user);
				$this->fail('The delete should be refused');
			} catch (InvalidArgumentException $e) {
				$this->assertSame(expected: $message, actual: $e->getMessage());
			}
		}

		$this->assertSame(expected: [], actual: $this->trace);
	}//end testOnlyTheOwnerDeletes()

	/**
	 * The listing answers only the attachments the reader holds a grant for, with the reader's wrapped key.
	 *
	 * @return void
	 */
	public function testTheListingAnswersOnlyGrantedAttachments(): void {
		$rows = $this->service()->listForSecret(secretId: 'sec-1', userId: 'bob');

		$this->assertSame(expected: ['att-1', 'att-3'], actual: array_column($rows, 'id'));
		$this->assertSame(expected: ['wrapped-g-att-1', 'wrapped-g-att-3'], actual: array_column($rows, 'wrappedFileKey'));
		$this->assertSame(expected: 2048, actual: $rows[0]['sizeBytes']);
		$this->assertSame(expected: [], actual: $this->service()->listForSecret(secretId: 'sec-1', userId: 'carol'));
	}//end testTheListingAnswersOnlyGrantedAttachments()
}//end class
