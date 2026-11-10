<?php

/**
 * The upload and grant writes of AttachmentService, pinned call by call.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
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
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Exception\WriteLockedException;
use OCA\Keepiq\Service\AttachmentService;
use OCA\Keepiq\Service\WriteLockService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Every refusal, row and audit event of upload and addGrant.
 */
class AttachmentServiceWriteTest extends TestCase {

	/**
	 * The calls recorded so far.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Whether the owner's vault is write-locked.
	 *
	 * @var bool
	 */
	private bool $locked = false;

	/**
	 * Whether the active suite lookup finds one.
	 *
	 * @var bool
	 */
	private bool $suiteFound = true;

	/**
	 * The grants the copy already has.
	 *
	 * @var AttachmentGrant[]
	 */
	private array $existingGrants = [];

	/**
	 * Build the service over recording collaborators.
	 *
	 * @return AttachmentService
	 */
	private function service(): AttachmentService {
		$mapper = $this->createMock(AttachmentMapper::class);
		$mapper->method('sumBytesForOwner')->willReturnCallback(fn (string $ownerId): int => $this->record(call: ['sumBytesForOwner', $ownerId], answer: 90));
		$mapper->method('insert')->willReturnCallback(fn (Attachment $row): Attachment => $this->record(call: ['insertAttachment', $this->attachmentRow(row: $row)], answer: $row));
		$mapper->method('findById')->willReturnCallback(function (string $id): Attachment {
			$this->calls[] = ['findAttachment', $id];
			$attachment = new Attachment();
			$attachment->setId($id);
			$attachment->setSourceSecretId('sec-1');
			return $attachment;
		});

		$grants = $this->createMock(AttachmentGrantMapper::class);
		$grants->method('insert')->willReturnCallback(fn (AttachmentGrant $row): AttachmentGrant => $this->record(call: ['insertGrant', $this->grantRow(row: $row)], answer: $row));
		$grants->method('findBySecret')->willReturnCallback(fn (string $secretId): array => $this->record(call: ['grantsOfSecret', $secretId], answer: $this->existingGrants));

		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(function (string $id): Secret {
			$this->calls[] = ['findSecret', $id];
			if ($id === 'missing') {
				throw new DoesNotExistException('');
			}

			$secret = new Secret();
			$secret->setId($id);
			$secret->setName('Wiki');
			$secret->setOwnerType('user');
			$secret->setOwnerId('alice');
			return $secret;
		});

		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturnCallback(function (string $ownerType, string $ownerId): EncryptionSuite {
			$this->calls[] = ['activeSuite', $ownerType, $ownerId];
			if ($this->suiteFound === false) {
				throw new DoesNotExistException('');
			}

			$suite = new EncryptionSuite();
			$suite->setId('suite-' . $ownerId);
			return $suite;
		});

		$file = $this->createMock(ISimpleFile::class);
		$file->method('putContent')->willReturnCallback(function (string $content): void {
			$this->calls[] = ['putContent', $content];
		});
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('newFile')->willReturnCallback(function (string $name) use ($file): ISimpleFile {
			$this->calls[] = ['newFile', preg_match('/^[0-9a-f-]{36}\.bin$/', $name) === 1];
			return $file;
		});
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$appDataFactory = $this->createMock(IAppDataFactory::class);
		$appDataFactory->method('get')->willReturn($appData);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default): int => $this->record(call: ['config', $app, $key, $default], answer: ['attachment_max_bytes' => 20, 'attachment_user_quota_bytes' => 100][$key])
		);

		$lock = $this->createMock(WriteLockService::class);
		$lock->method('assertNotWriteLocked')->willReturnCallback(function (string $ownerId): void {
			$this->calls[] = ['assertNotWriteLocked', $ownerId];
			if ($this->locked === true) {
				throw new WriteLockedException('locked');
			}
		});

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (AuditEvent $event): void {
			$this->calls[] = ['audit', $event->getActorId(), $event->getEventType(), $event->getObjectType(), $event->getObjectId() !== null, $event->getObjectName(), $event->getMetadata()];
		});

		return new AttachmentService(
			mapper: $mapper,
			grantMapper: $grants,
			secretMapper: $secrets,
			suiteMapper: $suites,
			appDataFactory: $appDataFactory,
			appConfig: $config,
			writeLockService: $lock,
			eventDispatcher: $events,
		);
	}//end service()

	/**
	 * Record a call and answer with a value.
	 *
	 * @param array<int,mixed> $call The call
	 * @param mixed $answer The answer
	 *
	 * @return mixed
	 */
	private function record(array $call, mixed $answer): mixed {
		$this->calls[] = $call;
		return $answer;
	}//end record()

	/**
	 * The stored fields of an attachment row.
	 *
	 * @param Attachment $row The row
	 *
	 * @return array<string,mixed>
	 */
	private function attachmentRow(Attachment $row): array {
		return [
			'id' => strlen((string)$row->getId()),
			'sourceSecretId' => $row->getSourceSecretId(),
			'blobRef' => str_ends_with((string)$row->getBlobRef(), '.bin'),
			'encryptedMetadata' => $row->getEncryptedMetadata(),
			'sizeBytes' => $row->getSizeBytes(),
			'dates' => $row->getCreatedAt() !== null && $row->getUpdatedAt() !== null,
		];
	}//end attachmentRow()

	/**
	 * The stored fields of a grant row.
	 *
	 * @param AttachmentGrant $row The row
	 *
	 * @return array<string,mixed>
	 */
	private function grantRow(AttachmentGrant $row): array {
		return [
			'id' => strlen((string)$row->getId()),
			'attachmentId' => $row->getAttachmentId() !== null,
			'secretId' => $row->getSecretId(),
			'recipientType' => $row->getRecipientType(),
			'recipientId' => $row->getRecipientId(),
			'wrappedFileKey' => $row->getWrappedFileKey(),
			'encryptionSuiteId' => $row->getEncryptionSuiteId(),
			'createdAt' => $row->getCreatedAt() !== null,
		];
	}//end grantRow()

	/**
	 * Run a call and answer its outcome with the trace.
	 *
	 * @param callable $call The call
	 *
	 * @return array<int,mixed>
	 */
	private function trace(callable $call): array {
		try {
			$call($this->service());
			$this->calls[] = ['returned'];
		} catch (Throwable $e) {
			$this->calls[] = ['threw', get_class($e), $e->getMessage()];
		}

		return $this->calls;
	}//end trace()

	/**
	 * Upload a blob of the given content.
	 *
	 * @param string $blob The ciphertext
	 * @param string $secretId The owning secret
	 * @param string $userId The caller
	 *
	 * @return callable
	 */
	private static function upload(string $blob, string $secretId = 'sec-1', string $userId = 'alice'): callable {
		return static fn (AttachmentService $s) => $s->upload(secretId: $secretId, userId: $userId, blob: $blob, encryptedMetadata: 'META', wrappedFileKey: 'WRAPPED');
	}//end upload()

	/**
	 * A complete upload writes the blob, the row, the owner grant and the audit event, in order.
	 *
	 * @return void
	 */
	public function testUploadWritesInOrder(): void {
		$this->assertSame(
			[
				['assertNotWriteLocked', 'alice'],
				['findSecret', 'sec-1'],
				['config', 'keepiq', 'attachment_max_bytes', 26214400],
				['config', 'keepiq', 'attachment_user_quota_bytes', 104857600],
				['sumBytesForOwner', 'alice'],
				['activeSuite', 'user', 'alice'],
				['newFile', true],
				['putContent', 'CIPHER'],
				['insertAttachment', ['id' => 36, 'sourceSecretId' => 'sec-1', 'blobRef' => true, 'encryptedMetadata' => 'META', 'sizeBytes' => 6, 'dates' => true]],
				['insertGrant', ['id' => 36, 'attachmentId' => true, 'secretId' => 'sec-1', 'recipientType' => 'user', 'recipientId' => 'alice', 'wrappedFileKey' => 'WRAPPED', 'encryptionSuiteId' => 'suite-alice', 'createdAt' => true]],
				['audit', 'alice', 'attachment.uploaded', 'attachment', true, 'Wiki', ['secretId' => 'sec-1', 'sizeBytes' => 6]],
				['returned'],
			],
			$this->trace(self::upload(blob: 'CIPHER'))
		);
	}//end testUploadWritesInOrder()

	/**
	 * Without an active suite the grant carries no suite and the upload proceeds.
	 *
	 * @return void
	 */
	public function testUploadWithoutSuiteGrantsWithoutOne(): void {
		$this->suiteFound = false;
		$calls = $this->trace(self::upload(blob: 'CIPHER'));

		$this->assertNull($calls[9][1]['encryptionSuiteId']);
		$this->assertSame(['returned'], end($calls));
	}//end testUploadWithoutSuiteGrantsWithoutOne()

	/**
	 * Each refusal of upload, with the calls made before it.
	 *
	 * @return void
	 */
	public function testUploadRefusals(): void {
		$this->assertSame(['threw', InvalidArgumentException::class, 'blob, encryptedMetadata, and wrappedFileKey are required'], $this->lastOf($this->resetAnd(self::upload(blob: ''))));
		$this->assertSame(['threw', InvalidArgumentException::class, 'Secret not found'], $this->lastOf($this->resetAnd(self::upload(blob: 'X', secretId: 'missing'))));
		$this->assertSame(['threw', InvalidArgumentException::class, 'Not authorized for this secret'], $this->lastOf($this->resetAnd(self::upload(blob: 'X', userId: 'mallory'))));
		$this->assertSame(['threw', InvalidArgumentException::class, 'Attachment exceeds the per-attachment limit of 20 bytes'], $this->lastOf($this->resetAnd(self::upload(blob: str_repeat('x', 21)))));
		$this->assertSame(['threw', InvalidArgumentException::class, 'Attachment quota exceeded (90 of 100 bytes in use)'], $this->lastOf($this->resetAnd(self::upload(blob: str_repeat('x', 11)))));

		$this->locked = true;
		$this->assertSame([['assertNotWriteLocked', 'alice'], ['threw', WriteLockedException::class, 'locked']], $this->resetAnd(self::upload(blob: 'X')));
	}//end testUploadRefusals()

	/**
	 * The last recorded call.
	 *
	 * @param array<int,mixed> $calls The calls
	 *
	 * @return mixed
	 */
	private function lastOf(array $calls): mixed {
		return $calls[array_key_last($calls)];
	}//end lastOf()

	/**
	 * Clear the trace and run a call.
	 *
	 * @param callable $call The call
	 *
	 * @return array<int,mixed>
	 */
	private function resetAnd(callable $call): array {
		$this->calls = [];
		return $this->trace($call);
	}//end resetAnd()

	/**
	 * Add a grant for a copy.
	 *
	 * @param string $recipientType The recipient type
	 * @param string $copySecretId The copy
	 * @param string $userId The caller
	 *
	 * @return callable
	 */
	private static function grant(string $recipientType = 'user', string $copySecretId = 'copy-1', string $userId = 'alice'): callable {
		return static fn (AttachmentService $s) => $s->addGrant(
			attachmentId: 'att-1',
			userId: $userId,
			copySecretId: $copySecretId,
			recipientId: 'bob',
			wrappedFileKey: 'REWRAPPED',
			recipientType: $recipientType,
		);
	}//end grant()

	/**
	 * A new grant is checked, looked up and inserted, in order.
	 *
	 * @return void
	 */
	public function testAddGrantWritesInOrder(): void {
		$this->assertSame(
			[
				['assertNotWriteLocked', 'alice'],
				['findAttachment', 'att-1'],
				['findSecret', 'sec-1'],
				['grantsOfSecret', 'copy-1'],
				['activeSuite', 'application', 'bob'],
				['insertGrant', ['id' => 36, 'attachmentId' => true, 'secretId' => 'copy-1', 'recipientType' => 'application', 'recipientId' => 'bob', 'wrappedFileKey' => 'REWRAPPED', 'encryptionSuiteId' => 'suite-bob', 'createdAt' => true]],
				['returned'],
			],
			$this->trace(self::grant(recipientType: 'application'))
		);
	}//end testAddGrantWritesInOrder()

	/**
	 * An existing grant for the copy is answered without a lookup or insert.
	 *
	 * @return void
	 */
	public function testAddGrantIsIdempotentPerCopy(): void {
		$other = new AttachmentGrant();
		$other->setAttachmentId('att-2');
		$same = new AttachmentGrant();
		$same->setAttachmentId('att-1');
		$this->existingGrants = [$other, $same];

		$this->assertSame(
			[['assertNotWriteLocked', 'alice'], ['findAttachment', 'att-1'], ['findSecret', 'sec-1'], ['grantsOfSecret', 'copy-1'], ['returned']],
			$this->trace(self::grant())
		);
	}//end testAddGrantIsIdempotentPerCopy()

	/**
	 * Each refusal of addGrant.
	 *
	 * @return void
	 */
	public function testAddGrantRefusals(): void {
		$this->assertSame(['threw', InvalidArgumentException::class, 'copySecretId, recipientId, and wrappedFileKey are required'], $this->lastOf($this->resetAnd(self::grant(copySecretId: ''))));
		$this->assertSame(['threw', InvalidArgumentException::class, 'recipientType must be user or application'], $this->lastOf($this->resetAnd(self::grant(recipientType: 'group'))));
		$this->assertSame(['threw', InvalidArgumentException::class, 'Not authorized for this secret'], $this->lastOf($this->resetAnd(self::grant(userId: 'mallory'))));

		$this->locked = true;
		$this->assertSame([['assertNotWriteLocked', 'alice'], ['threw', WriteLockedException::class, 'locked']], $this->resetAnd(self::grant()));
	}//end testAddGrantRefusals()
}//end class
