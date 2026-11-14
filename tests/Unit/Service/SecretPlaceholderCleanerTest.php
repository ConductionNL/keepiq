<?php

/**
 * Keepiq - SecretPlaceholderCleaner tests
 *
 * Golden outcomes of removing a request's placeholder Secret, for every actor and
 * every reason to leave it alone: a half-stated owner expectation is refused, a
 * Secret that is gone, ambiguous, filled, ownerless or in a vault the caller has
 * no claim on is kept, and a removable one goes through its own vault's delete
 * path, with a failed delete logged rather than thrown.
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Service\SecretPlaceholderCleaner;
use OCA\Keepiq\Service\SecretService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the placeholder cleaner.
 */
class SecretPlaceholderCleanerTest extends TestCase {
	/**
	 * The Secret mapper mock.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $secretMapper;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The Secret service mock.
	 *
	 * @var SecretService&MockObject
	 */
	private SecretService&MockObject $secretService;

	/**
	 * The cleaner under test.
	 *
	 * @var SecretPlaceholderCleaner
	 */
	private SecretPlaceholderCleaner $cleaner;

	/**
	 * Build the cleaner over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->secretService = $this->createMock(SecretService::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->secretService);
		$this->cleaner = new SecretPlaceholderCleaner($this->secretMapper, $this->logger, $container);
	}//end setUp()

	/**
	 * A request targeting secret s-1.
	 *
	 * @return SecretRequest
	 */
	private function request(): SecretRequest {
		$request = new SecretRequest();
		$request->setId(7);
		$request->setSecretId('s-1');
		return $request;
	}//end request()

	/**
	 * An empty Secret owned as given.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId   The owner id
	 *
	 * @return Secret
	 */
	private function placeholder(string $ownerType, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId('s-1');
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);
		return $secret;
	}//end placeholder()

	/**
	 * Half-stated expectations.
	 *
	 * @return array<string, array{0: ?string, 1: ?string}>
	 */
	public static function halfExpectations(): array {
		return [
			'type only' => ['user', null],
			'id only'   => [null, 'alice'],
		];
	}//end halfExpectations()

	/**
	 * A half-stated owner expectation is refused before anything is read.
	 *
	 * @param string|null $type The expected owner type
	 * @param string|null $id   The expected owner id
	 *
	 * @return void
	 */
	#[DataProvider('halfExpectations')]
	public function testRefusesAHalfStatedExpectation(?string $type, ?string $id): void {
		$this->secretMapper->expects($this->never())->method('findById');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('An owner expectation needs both a type and an id, or neither');
		$this->cleaner->removeIfUnfilled($this->request(), $type, $id);
	}//end testRefusesAHalfStatedExpectation()

	/**
	 * A Secret that is gone or ambiguous is left alone.
	 *
	 * @return void
	 */
	public function testKeepsWhatCannotBeFound(): void {
		$this->secretMapper->method('findById')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('gone')),
			$this->throwException(new MultipleObjectsReturnedException('two'))
		);
		$this->secretService->expects($this->never())->method('delete');
		$this->cleaner->removeIfUnfilled($this->request(), null, null);
		$this->cleaner->removeIfUnfilled($this->request(), null, null);
		$this->addToAssertionCount(1);
	}//end testKeepsWhatCannotBeFound()

	/**
	 * Cases where the Secret stays, by why.
	 *
	 * @return array<string, array{0: string, 1: string, 2: ?string, 3: ?string, 4: bool}>
	 */
	public static function kept(): array {
		return [
			'filled'                    => ['user', 'alice', 'user', 'alice', true],
			'no owner'                  => ['user', '', null, null, false],
			'another user'              => ['user', 'bob', 'user', 'alice', false],
			'a user expecting an app'   => ['user', 'alice', 'application', 'alice', false],
			'another application'       => ['application', 'app-2', 'application', 'app-1', false],
			'an app expecting a user'   => ['application', 'app-1', 'user', 'app-1', false],
		];
	}//end kept()

	/**
	 * A filled, ownerless or foreign Secret is never deleted.
	 *
	 * @param string      $ownerType    The Secret's owner type
	 * @param string      $ownerId      The Secret's owner id
	 * @param string|null $expectedType The caller's expected owner type
	 * @param string|null $expectedId   The caller's expected owner id
	 * @param bool        $filled       Whether the Secret holds a value
	 *
	 * @return void
	 */
	#[DataProvider('kept')]
	public function testKeeps(string $ownerType, string $ownerId, ?string $expectedType, ?string $expectedId, bool $filled): void {
		$secret = $this->placeholder($ownerType, $ownerId);
		if ($filled === true) {
			$secret->setKey('CIPHER');
		}
		$this->secretMapper->method('findById')->with('s-1')->willReturn($secret);
		$this->secretService->expects($this->never())->method('delete');
		$this->secretService->expects($this->never())->method('deleteByApplication');
		$this->cleaner->removeIfUnfilled($this->request(), $expectedType, $expectedId);
	}//end testKeeps()

	/**
	 * Cases where the placeholder goes.
	 *
	 * @return array<string, array{0: string, 1: string, 2: ?string, 3: ?string}>
	 */
	public static function removed(): array {
		return [
			'the user revoking'          => ['user', 'alice', 'user', 'alice'],
			'the system expiring a user' => ['user', 'alice', null, null],
			'an admin for the app'       => ['application', 'app-1', 'application', 'app-1'],
			'the system expiring an app' => ['application', 'app-1', null, null],
		];
	}//end removed()

	/**
	 * A removable placeholder goes through its own vault's delete path.
	 *
	 * @param string      $ownerType    The Secret's owner type
	 * @param string      $ownerId      The Secret's owner id
	 * @param string|null $expectedType The caller's expected owner type
	 * @param string|null $expectedId   The caller's expected owner id
	 *
	 * @return void
	 */
	#[DataProvider('removed')]
	public function testRemoves(string $ownerType, string $ownerId, ?string $expectedType, ?string $expectedId): void {
		$this->secretMapper->method('findById')->willReturn($this->placeholder($ownerType, $ownerId));
		if ($ownerType === 'user') {
			$this->secretService->expects($this->once())->method('delete')->with('s-1', $ownerId);
			$this->secretService->expects($this->never())->method('deleteByApplication');
		} else {
			$this->secretService->expects($this->once())->method('deleteByApplication')
				->with('s-1', $ownerId);
			$this->secretService->expects($this->never())->method('delete');
		}
		$this->cleaner->removeIfUnfilled($this->request(), $expectedType, $expectedId);
	}//end testRemoves()

	/**
	 * A Secret of another owner type is never deleted, even by the system.
	 *
	 * @return void
	 */
	public function testDeletesNothingForAnUnknownOwnerType(): void {
		$this->secretMapper->method('findById')->willReturn($this->placeholder('team', 't-1'));
		$this->secretService->expects($this->never())->method('delete');
		$this->secretService->expects($this->never())->method('deleteByApplication');
		$this->cleaner->removeIfUnfilled($this->request(), null, null);
	}//end testDeletesNothingForAnUnknownOwnerType()

	/**
	 * A failed delete is logged with the request id, never thrown.
	 *
	 * @return void
	 */
	public function testLogsAFailedDelete(): void {
		$this->secretMapper->method('findById')->willReturn($this->placeholder('user', 'alice'));
		$failure = new RuntimeException('db down');
		$this->secretService->method('delete')->willThrowException($failure);
		$this->logger->expects($this->once())->method('error')->with(
			'Keepiq: could not delete the placeholder for request 7: db down',
			['exception' => $failure]
		);
		$this->cleaner->removeIfUnfilled($this->request(), 'user', 'alice');
	}//end testLogsAFailedDelete()
}//end class
