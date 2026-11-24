<?php

/**
 * Tests `PUT /api/v1/app/secrets/{id}` through the real SecretService: one
 * read per attempt, checked against If-Match and locked on.
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
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use DateTime;
use OCA\Keepiq\Controller\ApplicationSecretsController;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MachineSecretEnvelopeService;
use OCA\Keepiq\Service\MachineSecretResponseService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\ShareSaveRequiredGuard;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The controller reads no row of its own: the service's read is the one the
 * If-Match tag is checked against and the one the write locks on.
 *
 * @spec openspec/specs/secret-store-api/spec.md#requirement-conditional-machine-write-back
 */
class ApplicationSecretsWriteLockTest extends TestCase {

	/**
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper $mapper;

	private MachineSecretEnvelopeService $envelopes;

	private ApplicationSecretsController $controller;

	/**
	 * @var array<string,string> Request headers
	 */
	private array $headers = [];

	/**
	 * Wire the controller over the real SecretService and envelope service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => ['key' => 'NEW'][$key] ?? null);
		$request->method('getHeader')->willReturnCallback(fn (string $name) => $this->headers[$name] ?? '');
		$this->envelopes = new MachineSecretEnvelopeService(
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			folderMapper: $this->createMock(FolderMapper::class),
		);
		$service = new SecretService(
			mapper: $this->mapper,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			db: $this->createMock(IDBConnection::class),
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
			shareSaveRequired: $this->createStub(ShareSaveRequiredGuard::class),
		);
		$this->controller = new ApplicationSecretsController(
			request: $request,
			secretMapper: $this->mapper,
			folderMapper: $this->createMock(FolderMapper::class),
			secretService: $service,
			envelopeService: $this->envelopes,
			responseService: new MachineSecretResponseService(
				request: $request,
				envelopeService: $this->envelopes,
				eventDispatcher: $this->createMock(IEventDispatcher::class),
			),
		);
		$app = new Application();
		$app->setId('app-1');
		$this->controller->setApplication($app);
	}//end setUp()

	/**
	 * An application-owned row at a version.
	 *
	 * @param string $key     The key ciphertext
	 * @param string $version The stored version
	 *
	 * @return Secret
	 */
	private function row(string $key, string $version): Secret {
		$secret = new Secret();
		$secret->setId('s1');
		$secret->setName('token');
		$secret->setKey($key);
		$secret->setOwnerType('application');
		$secret->setOwnerId('app-1');
		$secret->setEncryptionSuiteId('suite-1');
		$secret->setUpdatedAt(new DateTime($version));
		return $secret;
	}//end row()

	/**
	 * The headers a response set itself.
	 *
	 * @param Response $response The response
	 *
	 * @return array<string,string>
	 */
	private function setHeaders(Response $response): array {
		return (new \ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end setHeaders()

	/**
	 * If-Match naming the row read passes, but the lock is lost to a write
	 * that landed after the read: the answer is 412 with the current ETag,
	 * and nothing is written over the version the caller never saw.
	 *
	 * @return void
	 */
	public function testAMatchingTagThatLosesTheLockIsA412WithTheCurrentEtag(): void {
		$read = $this->row(key: 'OLD', version: '2026-01-01T00:00:00+00:00');
		$current = $this->row(key: 'MEANWHILE', version: '2026-01-01T00:00:05+00:00');
		$this->headers['If-Match'] = $this->envelopes->etag($read);
		$this->mapper->expects($this->exactly(2))->method('findById')->willReturnOnConsecutiveCalls($read, $current);
		$this->mapper->expects($this->once())->method('advanceVersion')->willReturn(0);
		$this->mapper->expects($this->never())->method('update');

		$response = $this->controller->update('s1');

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		$this->assertSame($this->envelopes->etag($current), $this->setHeaders($response)['ETag']);
	}//end testAMatchingTagThatLosesTheLockIsA412WithTheCurrentEtag()

	/**
	 * A stale tag is refused on the one read, before any lock or write.
	 *
	 * @return void
	 */
	public function testAStaleTagIsA412WithoutALock(): void {
		$current = $this->row(key: 'B', version: '2026-01-01T00:00:05+00:00');
		$this->headers['If-Match'] = $this->envelopes->etag($this->row(key: 'A', version: '2026-01-01T00:00:00+00:00'));
		$this->mapper->expects($this->once())->method('findById')->willReturn($current);
		$this->mapper->expects($this->never())->method('advanceVersion');
		$this->mapper->expects($this->never())->method('update');

		$response = $this->controller->update('s1');

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		$this->assertSame($this->envelopes->etag($current), $this->setHeaders($response)['ETag']);
	}//end testAStaleTagIsA412WithoutALock()

	/**
	 * A matching tag writes once, and the returned ETag is the one the next
	 * read of the stored row gives: the version is in whole seconds.
	 *
	 * @return void
	 */
	public function testAMatchingTagWritesAndAnswersTheStoredEtag(): void {
		$read = $this->row(key: 'OLD', version: '2026-01-01T00:00:00+00:00');
		$this->headers['If-Match'] = $this->envelopes->etag($read);
		$this->mapper->expects($this->once())->method('findById')->willReturn($read);
		$this->mapper->expects($this->once())->method('advanceVersion')->willReturn(1);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$response = $this->controller->update('s1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$stored = $this->row(key: 'NEW', version: '@'.$read->getUpdatedAt()->getTimestamp());
		$this->assertSame($this->envelopes->etag($stored), $this->setHeaders($response)['ETag']);
	}//end testAMatchingTagWritesAndAnswersTheStoredEtag()

	/**
	 * Without If-Match, a lock lost twice is a 409, not a server error.
	 *
	 * @return void
	 */
	public function testALockLostTwiceIsA409(): void {
		$this->mapper->method('findById')->willReturnOnConsecutiveCalls(
			$this->row(key: 'OLD', version: '2026-01-01T00:00:00+00:00'),
			$this->row(key: 'MEANWHILE', version: '2026-01-01T00:00:05+00:00'),
			$this->row(key: 'LATEST', version: '2026-01-01T00:00:09+00:00')
		);
		$this->mapper->expects($this->exactly(2))->method('advanceVersion')->willReturn(0);
		$this->mapper->expects($this->never())->method('update');

		$response = $this->controller->update('s1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStringNotContainsString('LATEST', (string)json_encode($response->getData()));
	}//end testALockLostTwiceIsA409()
}//end class
