<?php

/**
 * Contract tests for the AttachmentController write and list actions.
 *
 * Attachments are end-to-end encrypted: the client uploads ciphertext as
 * base64 and a file key wrapped for itself. The controller's contract is
 * that an anonymous caller is refused, the blob is decoded strictly (a
 * non-base64 blob never reaches storage), the SESSION user is the uploader
 * and the grantor, and a service refusal answers 400.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Controller\AttachmentController;
use OCA\Keepiq\Db\Attachment;
use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Service\AttachmentGrantRequest;
use OCA\Keepiq\Service\AttachmentService;
use OCA\Keepiq\Service\AttachmentUpload;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttachmentControllerWriteTest extends TestCase {

	/**
	 * The mocked attachment service.
	 *
	 * @var AttachmentService&MockObject
	 */
	private AttachmentService&MockObject $service;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The controller under test.
	 *
	 * @var AttachmentController
	 */
	private AttachmentController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: AttachmentService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->controller = new AttachmentController(
			request: $this->createMock(originalClassName: IRequest::class),
			attachmentService: $this->service,
			userSession: $this->session,
		);
	}//end setUp()

	/**
	 * Sign alice in on the mocked session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * A grant row with a wrapped key.
	 *
	 * @return AttachmentGrant
	 */
	private function grant(): AttachmentGrant {
		$grant = new AttachmentGrant();
		$grant->setId('grant-1');
		$grant->setAttachmentId('att-1');
		$grant->setWrappedFileKey('wrapped-for-alice');
		return $grant;
	}//end grant()

	/**
	 * The write and list endpoints, called with valid arguments.
	 *
	 * @return array<string, array{0: callable(AttachmentController): mixed}>
	 */
	public static function endpoints(): array {
		return [
			'create'   => [static fn (AttachmentController $c) => $c->create(secretId: 's-1', blob: 'AAAA')],
			'index'    => [static fn (AttachmentController $c) => $c->index(secretId: 's-1')],
			'addGrant' => [static fn (AttachmentController $c) => $c->addGrant(id: 'att-1')],
			'destroy'  => [static fn (AttachmentController $c) => $c->destroy(id: 'att-1')],
		];
	}//end endpoints()

	/**
	 * An anonymous caller is refused before the service runs.
	 *
	 * @param callable $call The endpoint call.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(callable $call): void {
		$this->session->method('getUser')->willReturn(null);
		foreach (['upload', 'listForSecret', 'addGrant', 'delete'] as $method) {
			$this->service->expects($this->never())->method($method);
		}

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $call($this->controller)->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A blob that is not strict base64 is refused before storage.
	 *
	 * @return void
	 */
	public function testANonBase64BlobIsRefusedBeforeStorage(): void {
		$this->signIn();
		$this->service->expects($this->never())->method('upload');

		$response = $this->controller->create(secretId: 's-1', blob: 'not base64 at all!');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'blob must be base64-encoded ciphertext'], actual: $response->getData());
	}//end testANonBase64BlobIsRefusedBeforeStorage()

	/**
	 * An upload stores the decoded ciphertext as the session user and returns the wrapped key.
	 *
	 * @return void
	 */
	public function testCreateStoresTheDecodedCiphertextAsTheSessionUser(): void {
		$this->signIn();
		$attachment = new Attachment();
		$attachment->setId('att-1');
		$attachment->setSourceSecretId('s-1');
		$this->service->expects($this->once())->method('upload')
			->with($this->callback(
				callback: static fn (AttachmentUpload $u): bool => $u->secretId === 's-1'
					&& $u->userId === 'alice'
					&& $u->blob === "\x00\x01ciphertext"
					&& $u->encryptedMetadata === 'meta'
					&& $u->wrappedFileKey === 'wrapped-for-alice'
			))
			->willReturn(['attachment' => $attachment, 'grant' => $this->grant()]);

		$response = $this->controller->create(
			secretId: 's-1',
			blob: base64_encode("\x00\x01ciphertext"),
			encryptedMetadata: 'meta',
			wrappedFileKey: 'wrapped-for-alice'
		);

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertSame(expected: 'att-1', actual: $response->getData()['id']);
		$this->assertSame(expected: 'wrapped-for-alice', actual: $response->getData()['wrappedFileKey']);
	}//end testCreateStoresTheDecodedCiphertextAsTheSessionUser()

	/**
	 * A refused upload answers 400 with the service's message.
	 *
	 * @return void
	 */
	public function testARefusedUploadAnswers400(): void {
		$this->signIn();
		$this->service->method('upload')->willThrowException(new InvalidArgumentException(message: 'Attachment too large'));

		$response = $this->controller->create(secretId: 's-1', blob: 'AAAA');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Attachment too large'], actual: $response->getData());
	}//end testARefusedUploadAnswers400()

	/**
	 * The index lists the secret's attachments for the session user.
	 *
	 * @return void
	 */
	public function testIndexListsForTheSessionUser(): void {
		$this->signIn();
		$this->service->expects($this->once())->method('listForSecret')->with('s-1', 'alice')
			->willReturn([['id' => 'att-1']]);

		$this->assertSame(expected: [['id' => 'att-1']], actual: $this->controller->index(secretId: 's-1')->getData());
	}//end testIndexListsForTheSessionUser()

	/**
	 * A grant is added with the session user as grantor; a refusal answers 400.
	 *
	 * @return void
	 */
	public function testAddGrantAsTheSessionUser(): void {
		$this->signIn();
		$this->service->method('addGrant')->willReturnCallback(
			function (AttachmentGrantRequest $request): AttachmentGrant {
				if ($request->recipientId !== 'bob') {
					throw new InvalidArgumentException(message: 'Recipient has no copy');
				}

				$this->assertSame(expected: 'alice', actual: $request->userId);
				$this->assertSame(expected: 'att-1', actual: $request->attachmentId);
				$this->assertSame(expected: 'copy-9', actual: $request->copySecretId);
				$this->assertSame(expected: 'group', actual: $request->recipientType);
				return $this->grant();
			}
		);

		$granted = $this->controller->addGrant(
			id: 'att-1',
			copySecretId: 'copy-9',
			recipientId: 'bob',
			wrappedFileKey: 'wrapped-for-bob',
			recipientType: 'group'
		);
		$refused = $this->controller->addGrant(id: 'att-1', recipientId: 'carol');

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $granted->getStatus());
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $refused->getStatus());
		$this->assertSame(expected: ['message' => 'Recipient has no copy'], actual: $refused->getData());
	}//end testAddGrantAsTheSessionUser()

	/**
	 * A delete runs as the session user; a refusal answers 400.
	 *
	 * @return void
	 */
	public function testDestroyAsTheSessionUser(): void {
		$this->signIn();
		$this->service->expects($this->exactly(count: 2))->method('delete')->with('att-1', 'alice')
			->willReturnOnConsecutiveCalls(
				null,
				$this->throwException(exception: new InvalidArgumentException(message: 'Not yours'))
			);

		$this->assertSame(expected: ['deleted' => true], actual: $this->controller->destroy(id: 'att-1')->getData());
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller->destroy(id: 'att-1')->getStatus());
	}//end testDestroyAsTheSessionUser()
}//end class
