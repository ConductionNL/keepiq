<?php

/**
 * The attachments of a recipient's privileged copy are gated like its value.
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
use OCA\Keepiq\Service\AttachmentService;
use OCA\Keepiq\Service\PrivilegedAccess\PrivilegedCopyGate;
use OCA\Keepiq\Service\WriteLockService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
#[CoversClass(AttachmentService::class)]
#[UsesClass(Attachment::class)]
#[UsesClass(AttachmentGrant::class)]
#[UsesClass(Secret::class)]
class PrivilegedCopyAttachmentsTest extends TestCase {

	/**
	 * Bob's copy c1, which his grant opens.
	 *
	 * @var Secret
	 */
	private Secret $copy;

	/**
	 * The secrets.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper $secrets;

	/**
	 * The privileged copy gate.
	 *
	 * @var PrivilegedCopyGate&MockObject
	 */
	private PrivilegedCopyGate $gate;

	/**
	 * The attachments.
	 *
	 * @var AttachmentMapper&MockObject
	 */
	private AttachmentMapper $attachments;

	/**
	 * The grants.
	 *
	 * @var AttachmentGrantMapper&MockObject
	 */
	private AttachmentGrantMapper $grants;

	/**
	 * The blob folder.
	 *
	 * @var ISimpleFolder&MockObject
	 */
	private ISimpleFolder $folder;

	/**
	 * Bob holds a grant for attachment att-1 through his copy c1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->copy = new Secret();
		$this->copy->setId('c1');

		$attachment = new Attachment();
		$attachment->setId('att-1');
		$attachment->setBlobRef('blob.bin');
		$grant = new AttachmentGrant();
		$grant->setAttachmentId('att-1');
		$grant->setSecretId('c1');
		$grant->setWrappedFileKey('WRAPPED');

		$this->attachments = $this->createMock(AttachmentMapper::class);
		$this->attachments->method('findById')->willReturn($attachment);
		$this->attachments->method('findBySourceSecret')->willReturn([$attachment]);
		$this->grants = $this->createMock(AttachmentGrantMapper::class);
		$this->grants->method('findForRecipient')->with('att-1', 'bob')->willReturn($grant);
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->gate    = $this->createMock(PrivilegedCopyGate::class);
		$this->folder  = $this->createMock(ISimpleFolder::class);
	}

	/**
	 * The service under test.
	 *
	 * @return AttachmentService
	 */
	private function service(): AttachmentService {
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);

		return new AttachmentService(
			mapper: $this->attachments,
			grantMapper: $this->grants,
			secretMapper: $this->secrets,
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			appDataFactory: $factory,
			appConfig: $this->createMock(IAppConfig::class),
			writeLockService: $this->createMock(WriteLockService::class),
			eventDispatcher: null,
			privilegedGate: $this->gate,
		);
	}

	/**
	 * Outside the window the download is refused and audited, and no blob is read.
	 *
	 * @return void
	 */
	public function testADownloadOutsideTheWindowIsRefused(): void {
		$this->secrets->method('findById')->with('c1')->willReturn($this->copy);
		$this->gate->expects($this->once())->method('allows')
			->with($this->copy, 'bob', 'web', true)
			->willReturn(false);
		$this->folder->expects($this->never())->method('getFile');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(PrivilegedCopyGate::REASON);
		$this->service()->downloadBlob(attachmentId: 'att-1', userId: 'bob');
	}

	/**
	 * Inside the window the ciphertext is served.
	 *
	 * @return void
	 */
	public function testADownloadInsideTheWindowIsServed(): void {
		$this->secrets->method('findById')->with('c1')->willReturn($this->copy);
		$this->gate->method('allows')->willReturn(true);
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('CIPHERTEXT');
		$this->folder->method('getFile')->willReturn($file);

		$this->assertSame('CIPHERTEXT', $this->service()->downloadBlob(attachmentId: 'att-1', userId: 'bob'));
	}

	/**
	 * A copy row that cannot be read fails closed.
	 *
	 * @return void
	 */
	public function testAnUnreadableCopyFailsClosed(): void {
		$this->secrets->method('findById')->willThrowException(new DoesNotExistException('gone'));
		$this->gate->expects($this->never())->method('allows');

		$this->expectException(InvalidArgumentException::class);
		$this->service()->downloadBlob(attachmentId: 'att-1', userId: 'bob');
	}

	/**
	 * Outside the window the listing leaves the attachment out, without an audit.
	 *
	 * @return void
	 */
	public function testTheListingOutsideTheWindowLeavesTheKeyOut(): void {
		$this->secrets->method('findById')->with('c1')->willReturn($this->copy);
		$this->gate->expects($this->once())->method('allows')
			->with($this->copy, 'bob', 'web', false)
			->willReturn(false);

		$this->assertSame([], $this->service()->listForSecret(secretId: 's1', userId: 'bob'));
	}

	/**
	 * Inside the window the listing carries the caller's wrapped key.
	 *
	 * @return void
	 */
	public function testTheListingInsideTheWindowCarriesTheKey(): void {
		$this->secrets->method('findById')->with('c1')->willReturn($this->copy);
		$this->gate->method('allows')->willReturn(true);

		$listed = $this->service()->listForSecret(secretId: 's1', userId: 'bob');

		$this->assertCount(1, $listed);
		$this->assertSame('WRAPPED', $listed[0]['wrappedFileKey']);
	}
}//end class
