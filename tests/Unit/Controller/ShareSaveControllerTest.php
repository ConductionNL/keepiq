<?php

/**
 * Unit tests for ShareSaveController.
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

use InvalidArgumentException;
use OCA\Keepiq\Controller\ShareSaveController;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\PolicyViolationException;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Exception\WriteLockedException;
use OCA\Keepiq\Middleware\OcsRefusalMiddleware;
use OCA\Keepiq\Service\ShareSave\IncompleteShareSaveException;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSave\ShareSaveRequest;
use OCA\Keepiq\Service\ShareSaveService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Status and body of every answer of `PUT /secrets/{id}/share-save`.
 */
class ShareSaveControllerTest extends TestCase {
	private const BASE = '2026-10-01T10:00:00+00:00';

	private ShareSaveService&MockObject $service;

	/**
	 * The controller for a request with this body, as Alice unless signed out.
	 *
	 * @param array<string,mixed> $body The request body
	 * @param bool $signedIn Whether a user is signed in
	 *
	 * @return ShareSaveController
	 */
	private function controller(array $body, bool $signedIn = true): ShareSaveController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['id' => 'src'] + $body);
		$this->service = $this->createMock(ShareSaveService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);

		return new ShareSaveController(request: $request, service: $this->service, userSession: $session);
	}

	/**
	 * A valid one-call body.
	 *
	 * @return array<string,mixed>
	 */
	private function body(): array {
		return ['baseUpdatedAt' => self::BASE, 'patch' => ['key' => 'K']];
	}

	/**
	 * The answer when the service throws this.
	 *
	 * @param Throwable $refusal The exception
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function answerTo(Throwable $refusal): array {
		$controller = $this->controller($this->body());
		$this->service->method('save')->willThrowException($refusal);
		$response = $controller->save('src');

		return [$response->getStatus(), $response->getData()];
	}

	public function testWithoutASessionTheAnswerIs401(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($this->body(), false)->save('src')->getStatus());
	}

	public function testASavedShareSaveAnswersWithTheService(): void {
		$controller = $this->controller($this->body());
		$this->service->expects($this->once())->method('save')
			->with('src', $this->isInstanceOf(ShareSaveRequest::class), 'alice')
			->willReturn(['secret' => ['id' => 'src'], 'sourceUpdatedAt' => self::BASE]);

		$response = $controller->save('src');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['secret' => ['id' => 'src'], 'sourceUpdatedAt' => self::BASE], $response->getData());
	}

	public function testAChunkIsAcceptedWith202(): void {
		$controller = $this->controller([
			'baseUpdatedAt' => self::BASE,
			'stage' => ['final' => false],
			'copies' => [['secretId' => 'c-bob', 'key' => 'K']],
		]);
		$staged = ['stageId' => 'st', 'expiresAt' => self::BASE, 'staged' => ['copies' => 1, 'federated' => 0]];
		$this->service->expects($this->never())->method('save');
		$this->service->expects($this->once())->method('stage')->willReturn($staged);

		$response = $controller->save('src');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame($staged, $response->getData());
	}

	public function testAMalformedBodyIs400WithItsCodeAndNeverReachesTheService(): void {
		$controller = $this->controller(['patch' => ['key' => 'K']]);
		$this->service->expects($this->never())->method('save');

		$response = $controller->save('src');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('base_required', $response->getData()['error']);
	}

	public function testAStaleRowIs409WithTheCurrentRow(): void {
		$current = new Secret();
		$current->setId('src');

		[$status, $body] = $this->answerTo(new StaleWriteException(current: $current));

		$this->assertSame(Http::STATUS_CONFLICT, $status);
		$this->assertSame('stale', $body['error']);
		$this->assertSame('src', $body['current']['id']);
	}

	public function testAnIncompleteSaveIs409WithTheLists(): void {
		$copies = ['required' => [], 'missing' => ['c-bob'], 'unexpected' => [], 'skipRefused' => []];
		$federated = ['required' => [], 'missing' => [], 'unexpected' => []];

		[$status, $body] = $this->answerTo(new IncompleteShareSaveException('owner', self::BASE, $copies, $federated));

		$this->assertSame(Http::STATUS_CONFLICT, $status);
		$this->assertSame('incomplete', $body['error']);
		$this->assertSame('owner', $body['path']);
		$this->assertSame(self::BASE, $body['sourceUpdatedAt']);
		$this->assertSame($copies, $body['copies']);
		$this->assertSame($federated, $body['federated']);
	}

	public function testAServiceRefusalKeepsItsStatusAndCode(): void {
		[$status, $body] = $this->answerTo(new ShareSaveRefusal('stage_unknown', 'gone', Http::STATUS_NOT_FOUND));

		$this->assertSame([Http::STATUS_NOT_FOUND, 'stage_unknown'], [$status, $body['error']]);
	}

	public function testAMissingRowIs404(): void {
		[$status, $body] = $this->answerTo(new NotFoundException('Secret not found'));

		$this->assertSame([Http::STATUS_NOT_FOUND, 'not_found'], [$status, $body['error']]);
	}

	public function testAWriteLockIs423(): void {
		[$status, $body] = $this->answerTo(new WriteLockedException('locked'));

		$this->assertSame([423, 'write_locked'], [$status, $body['error']]);
	}

	public function testAPatchValueTheRowRefusesIs400(): void {
		[$status, $body] = $this->answerTo(new InvalidArgumentException('Secret name cannot be empty'));

		$this->assertSame([Http::STATUS_BAD_REQUEST, 'invalid_body'], [$status, $body['error']]);
	}

	public function testRefusalsLeaveAs428WithTheirCode(): void {
		$middleware = new OcsRefusalMiddleware();
		$cases = [
			'forbidden' => new ForbiddenException('Secret belongs to another user'),
			'not_allowed' => new ShareSaveRefusal('not_allowed', 'no grade', Http::STATUS_FORBIDDEN),
		];

		foreach ($cases as $code => $refusal) {
			$controller = $this->controller($this->body());
			$this->service->method('save')->willThrowException($refusal);
			$response = $middleware->afterController($controller, 'save', $controller->save('src'));

			$this->assertSame(OcsRefusalMiddleware::REFUSAL_STATUS, $response->getStatus());
			$this->assertSame($code, $response->getData()['error']);
		}
	}

	public function testAPolicyRefusalCarriesItsCode(): void {
		[$status, $body] = $this->answerTo(
			new PolicyViolationException(policyCode: 'org_ownership_required', message: 'Keep work logins in a team folder')
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $status);
		$this->assertSame('org_ownership_required', $body['error']);
		$this->assertSame('org_ownership_required', $body['code']);
	}
}
