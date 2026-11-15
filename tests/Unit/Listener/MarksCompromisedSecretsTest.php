<?php

/**
 * Unit tests for the MarksCompromisedSecrets trait.
 *
 * Marking a secret possibly compromised stamps it once, raises the
 * suite_compromise rotation flag, and reports a failure instead of throwing
 * so one bad row never stops the containment of the others.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
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

namespace OCA\Keepiq\Tests\Unit\Listener;

use DateTime;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Listener\MarksCompromisedSecrets;
use OCA\Keepiq\Service\RotationPolicyService;
use OCA\Keepiq\Service\ShareRowJudge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Stamping and flagging one possibly compromised secret.
 */
#[CoversClass(MarksCompromisedSecrets::class)]
#[UsesClass(Secret::class)]
class MarksCompromisedSecretsTest extends TestCase {
	private SecretMapper&MockObject $secretMapper;

	private LoggerInterface&MockObject $logger;

	private RotationPolicyService&MockObject $rotationService;

	protected function setUp(): void {
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rotationService = $this->createMock(RotationPolicyService::class);
	}

	/**
	 * A host using the trait, with or without a rotation service.
	 *
	 * @param bool $withRotation Whether a rotation service is wired.
	 *
	 * @return object A host exposing stampAndFlag().
	 */
	private function host(bool $withRotation = true): object {
		return new class(
			$this->secretMapper,
			$this->createMock(ShareRowJudge::class),
			$this->logger,
			$withRotation === true ? $this->rotationService : null,
		) {
			use MarksCompromisedSecrets;

			public function __construct(
				private SecretMapper $secretMapper,
				private ShareRowJudge $rowJudge,
				private LoggerInterface $logger,
				private ?RotationPolicyService $rotationService,
			) {
			}

			public function mark(Secret $secret): bool {
				return $this->stampAndFlag($secret);
			}
		};
	}

	private static function secret(?DateTime $stamped = null): Secret {
		$secret = new Secret();
		$secret->setId(7);
		$secret->setPossiblyCompromisedAt($stamped);
		return $secret;
	}

	public function testAnUnstampedSecretIsStampedSavedAndFlagged(): void {
		$secret = self::secret();
		$this->secretMapper->expects($this->once())->method('update')->with($secret)->willReturnArgument(0);
		$this->rotationService->expects($this->once())->method('flag')->with(7, 'suite_compromise');

		$this->assertTrue($this->host()->mark($secret));
		$this->assertInstanceOf(DateTime::class, $secret->getPossiblyCompromisedAt());
	}

	public function testAnAlreadyStampedSecretKeepsItsStampAndIsOnlyFlagged(): void {
		$stamp = new DateTime('2026-01-01 10:00:00');
		$secret = self::secret($stamp);
		$this->secretMapper->expects($this->never())->method('update');
		$this->rotationService->expects($this->once())->method('flag')->with(7, 'suite_compromise');

		$this->assertTrue($this->host()->mark($secret));
		$this->assertSame($stamp, $secret->getPossiblyCompromisedAt());
	}

	public function testWithoutARotationServiceTheStampAlone(): void {
		$this->secretMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->assertTrue($this->host(withRotation: false)->mark(self::secret()));
	}

	public function testAFailingSaveIsReportedNotThrown(): void {
		$this->secretMapper->method('update')->willThrowException(new RuntimeException('deadlock'));
		$this->rotationService->expects($this->never())->method('flag');
		$this->logger->expects($this->once())->method('warning')->with(
			'Keepiq: could not mark secret 7 possibly compromised: deadlock',
			['app' => 'keepiq']
		);

		$this->assertFalse($this->host()->mark(self::secret()));
	}

	public function testAFailingFlagIsReportedNotThrown(): void {
		$this->secretMapper->method('update')->willReturnArgument(0);
		$this->rotationService->method('flag')->willThrowException(new RuntimeException('flag table gone'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Keepiq: could not mark secret 7 possibly compromised: flag table gone',
			['app' => 'keepiq']
		);

		$this->assertFalse($this->host()->mark(self::secret()));
	}
}
