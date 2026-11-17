<?php

/**
 * Unit tests for ExtensionAppPassword.
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

use OCA\Keepiq\Service\ExtensionAppPassword;
use OCP\Authentication\Token\IProvider;
use OCP\Authentication\Token\IToken;
use OCP\ISession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The session's app password resolves to a token id only for its own user.
 */
#[CoversClass(ExtensionAppPassword::class)]
class ExtensionAppPasswordTest extends TestCase {
	/** @var ISession&MockObject */
	private ISession $session;

	/** @var IProvider&MockObject */
	private IProvider $tokens;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->session = $this->createMock(ISession::class);
		$this->tokens = $this->createMock(IProvider::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return ExtensionAppPassword
	 */
	private function service(): ExtensionAppPassword {
		return new ExtensionAppPassword(session: $this->session, tokens: $this->tokens);
	}//end service()

	/**
	 * Session values that are no app password.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function noAppPassword(): array {
		return [
			'absent'     => [null],
			'empty'      => [''],
			'not string' => [42],
		];
	}//end noAppPassword()

	/**
	 * A browser session (no app password) has no token, and the provider is not asked.
	 *
	 * @param mixed $value The session's app_password value
	 *
	 * @return void
	 */
	#[DataProvider('noAppPassword')]
	public function testNoAppPasswordHasNoToken(mixed $value): void {
		$this->session->expects($this->once())->method('get')->with('app_password')->willReturn($value);
		$this->tokens->expects($this->never())->method('getToken');

		$this->assertNull($this->service()->tokenId(uid: 'alice'));
	}//end testNoAppPasswordHasNoToken()

	/**
	 * The token of the session's app password is returned for its own user.
	 *
	 * @return void
	 */
	public function testTheOwnTokenIdIsReturned(): void {
		$this->session->method('get')->willReturn('app-pass-1');
		$token = $this->createStub(IToken::class);
		$token->method('getUID')->willReturn('alice');
		$token->method('getId')->willReturn(91);
		$this->tokens->expects($this->once())->method('getToken')->with('app-pass-1')->willReturn($token);

		$this->assertSame(91, $this->service()->tokenId(uid: 'alice'));
	}//end testTheOwnTokenIdIsReturned()

	/**
	 * A token belonging to another user is not this user's.
	 *
	 * @return void
	 */
	public function testAnotherUsersTokenIsNotReturned(): void {
		$this->session->method('get')->willReturn('app-pass-1');
		$token = $this->createStub(IToken::class);
		$token->method('getUID')->willReturn('mallory');
		$token->method('getId')->willReturn(91);
		$this->tokens->method('getToken')->willReturn($token);

		$this->assertNull($this->service()->tokenId(uid: 'alice'));
	}//end testAnotherUsersTokenIsNotReturned()

	/**
	 * An app password the provider cannot resolve yields no token instead of an error.
	 *
	 * @return void
	 */
	public function testAnUnresolvableAppPasswordHasNoToken(): void {
		$this->session->method('get')->willReturn('stale');
		$this->tokens->method('getToken')->willThrowException(new RuntimeException('gone'));

		$this->assertNull($this->service()->tokenId(uid: 'alice'));
	}//end testAnUnresolvableAppPasswordHasNoToken()
}//end class
