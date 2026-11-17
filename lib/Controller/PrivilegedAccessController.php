<?php

/**
 * Keepiq PrivilegedAccessController
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Service\PrivilegedAccess\AccessAsk;
use OCA\Keepiq\Service\PrivilegedAccess\AccessNarrowing;
use OCA\Keepiq\Service\PrivilegedAccess\PrivilegedAccessService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Endpoints for privileged logins: the owner's mark, a recipient's request
 * for a time window, and the owner's answer. Ownership and holding are
 * checked in the service; an unknown or foreign secret answers not found.
 */
class PrivilegedAccessController extends OCSController {
	/**
	 * Constructor.
	 *
	 * @param IRequest                $request     The request object
	 * @param PrivilegedAccessService $service     The privileged access service
	 * @param IUserSession            $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private PrivilegedAccessService $service,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The privileged state of a secret, as the caller may see it.
	 *
	 * @param string $id The owner's secret or the recipient's copy
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	#[NoAdminRequired]
	public function status(string $id): JSONResponse {
		return $this->run(step: fn (string $uid): array => $this->service->status(secretId: $id, userId: $uid));
	}//end status()

	/**
	 * Mark or unmark the caller's login as privileged.
	 *
	 * @param string $id         The owner's secret
	 * @param bool   $privileged The state asked for
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the request body.
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	#[NoAdminRequired]
	public function update(string $id, bool $privileged = false): JSONResponse {
		return $this->run(
			step: fn (string $uid): array => [
				'privileged' => $this->service->setPrivileged(secretId: $id, ownerId: $uid, privileged: $privileged),
			]
		);
	}//end update()

	/**
	 * Ask the owner for a time window on the caller's copy.
	 *
	 * @param string $id       The recipient's copy
	 * @param string $startsAt The start (ISO 8601)
	 * @param string $endsAt   The end (ISO 8601)
	 * @param string $reason   Why the login is needed
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	#[NoAdminRequired]
	public function request(string $id, string $startsAt = '', string $endsAt = '', string $reason = ''): JSONResponse {
		return $this->run(
			step: fn (string $uid): array => $this->service->request(
				copyId: $id,
				requesterId: $uid,
				ask: new AccessAsk(
					startsAt: self::moment(value: $startsAt),
					endsAt: self::moment(value: $endsAt),
					reason: $reason
				)
			)->jsonSerialize(),
			status: Http::STATUS_CREATED
		);
	}//end request()

	/**
	 * The requests waiting for the caller's answer.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	#[NoAdminRequired]
	public function pending(): JSONResponse {
		return $this->run(step: fn (string $uid): array => ['items' => $this->service->pendingForOwner(ownerId: $uid)]);
	}//end pending()

	/**
	 * Approve a request, optionally narrowed in time and to address ranges.
	 *
	 * @param string       $requestId The request
	 * @param string       $startsAt  A later start (ISO 8601), or '' to keep the requested one
	 * @param string       $endsAt    An earlier end (ISO 8601), or '' to keep the requested one
	 * @param list<string> $ranges    CIDR ranges; empty for any address
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	#[NoAdminRequired]
	public function approve(string $requestId, string $startsAt = '', string $endsAt = '', array $ranges = []): JSONResponse {
		return $this->run(
			step: fn (string $uid): array => $this->service->approve(
				requestId: $requestId,
				ownerId: $uid,
				narrowing: new AccessNarrowing(
					startsAt: self::optionalMoment(value: $startsAt),
					endsAt: self::optionalMoment(value: $endsAt),
					ranges: array_values(array_filter(array_map('strval', $ranges), static fn (string $range): bool => trim($range) !== ''))
				)
			)->jsonSerialize()
		);
	}//end approve()

	/**
	 * Deny a request.
	 *
	 * @param string $requestId The request
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	#[NoAdminRequired]
	public function deny(string $requestId): JSONResponse {
		return $this->run(step: fn (string $uid): array => $this->service->deny(requestId: $requestId, ownerId: $uid)->jsonSerialize());
	}//end deny()

	/**
	 * Run a step for the signed-in caller and map its refusals.
	 *
	 * @param callable(string): array<string,mixed> $step   The step
	 * @param int                                   $status The success status
	 *
	 * @return JSONResponse
	 */
	private function run(callable $step, int $status = Http::STATUS_OK): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $step($uid), statusCode: $status);
		} catch (NotFoundException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end run()

	/**
	 * A required moment from an ISO 8601 string.
	 *
	 * @param string $value The string
	 *
	 * @return DateTimeImmutable
	 *
	 * @throws InvalidArgumentException When it is empty or not a date
	 */
	private static function moment(string $value): DateTimeImmutable {
		$moment = self::optionalMoment(value: $value);
		if ($moment === null) {
			throw new InvalidArgumentException(message: 'A start and an end are required');
		}

		return $moment;
	}//end moment()

	/**
	 * An optional moment from an ISO 8601 string, null when empty.
	 *
	 * @param string $value The string
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @throws InvalidArgumentException When it is not a date
	 */
	private static function optionalMoment(string $value): ?DateTimeImmutable {
		if (trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception) {
			throw new InvalidArgumentException(message: 'Not a date: ' . $value);
		}
	}//end optionalMoment()
}//end class
