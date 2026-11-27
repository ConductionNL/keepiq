<?php

/**
 * Keepiq Share Save Controller
 *
 * `PUT /api/v1/secrets/{id}/share-save`: a shared secret and the ciphertext
 * for every copy of it, saved in one transaction (ShareSaveService).
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

use InvalidArgumentException;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Exception\WriteLockedException;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Kernel\Contracts\ShareSaveServiceInterface;
use OCA\Keepiq\Service\PrivilegedAccess\PrivilegedCopyGate;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSave\ShareSaveRequest;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Saves a shared secret for every holder. Each 403 leaves as 428 through
 * OcsRefusalMiddleware.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Each refusal of the save is
 *   its own exception with its own status; the coupling is that mapping.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
 */
class ShareSaveController extends OCSController {
	/**
	 * HTTP 423 Locked status code.
	 *
	 * @var int
	 */
	private const STATUS_LOCKED = 423;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object
	 * @param ShareSaveServiceInterface $service The share-save service
	 * @param IUserSession $userSession The user session
	 * @param PrivilegedCopyGate|null $privilegedGate Withholds a privileged copy outside its approved window
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private ShareSaveServiceInterface $service,
		private IUserSession $userSession,
		private ?PrivilegedCopyGate $privilegedGate = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Save the caller's row `{id}`, every local copy and, for the owner, every
	 * partner share; or add a chunk to a stage (202).
	 *
	 * The secret's owner writes its partner shares whether `{id}` is the source or their own copy of it.
	 * A 409 with `error: partner_share_owner_mismatch` refuses a save that would write partner shares while one of them is not the secret owner's;
	 * nothing is written until an administrator runs `occ maintenance:repair`.
	 * The 200 body's `federated.notReached` counts the active partner shares made from a copy the save wrote,
	 * which no save writes: for the secret's owner from every copy written, for anyone else only from their own copy.
	 * `federated.ownerOnly` says whether partner shares exist that only the owner's save reaches.
	 *
	 * @param string $id The caller's row: the source, or their recipient copy
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	#[NoAdminRequired]
	public function save(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return $this->answer(id: $id, userId: $user->getUID());
		} catch (ShareSaveRefusal $e) {
			return new JSONResponse(data: $e->getBody(), statusCode: $e->getStatus());
		} catch (StaleWriteException $e) {
			return new JSONResponse(data: $this->staleBody(refusal: $e, userId: $user->getUID()), statusCode: Http::STATUS_CONFLICT);
		} catch (NotFoundException $e) {
			return new JSONResponse(
				data: ['error' => 'not_found', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_NOT_FOUND
			);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: $this->forbiddenBody(refusal: $e), statusCode: Http::STATUS_FORBIDDEN);
		} catch (WriteLockedException $e) {
			return new JSONResponse(
				data: ['error' => 'write_locked', 'message' => $e->getMessage()],
				statusCode: self::STATUS_LOCKED
			);
		} catch (InvalidArgumentException $e) {
			// A patch value SecretService::update refuses, such as an empty name.
			return new JSONResponse(
				data: ['error' => 'invalid_body', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end save()

	/**
	 * Parse the body and run the chunk (202) or the save (200).
	 *
	 * @param string $id The caller's row
	 * @param string $userId The caller
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	private function answer(string $id, string $userId): JSONResponse {
		$request = new ShareSaveRequest(params: $this->request->getParams());
		if ($request->isChunk() === true) {
			return new JSONResponse(
				data: $this->service->stage(id: $id, request: $request, userId: $userId),
				statusCode: Http::STATUS_ACCEPTED
			);
		}

		return new JSONResponse(data: $this->service->save(id: $id, request: $request, userId: $userId));
	}//end answer()

	/**
	 * The body of a 403: a vault policy refusal carries its code as `error`
	 * and `code` (admin-vault-policies D4).
	 *
	 * @param ForbiddenException $refusal The refusal
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	private function forbiddenBody(ForbiddenException $refusal): array {
		$code = $refusal->policyCode();
		if ($code === null) {
			return ['error' => 'forbidden', 'message' => $refusal->getMessage()];
		}

		return ['error' => $code, 'message' => $refusal->getMessage(), 'code' => $code];
	}//end forbiddenBody()

	/**
	 * The body of a `stale` 409: the caller's row as it is now and, on the
	 * copy-editor path, the source version the conflict stands on.
	 *
	 * @param StaleWriteException $refusal The refusal
	 * @param string $userId The caller (a privileged copy outside its window comes back without ciphertext)
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	private function staleBody(StaleWriteException $refusal, string $userId): array {
		$current = $refusal->getCurrent();
		$body = [
			'error' => 'stale',
			'message' => $refusal->getMessage(),
			'current' => $this->privilegedGate?->serialisedFor(secret: $current, userId: $userId) ?? $current->jsonSerialize(),
		];
		$sourceUpdatedAt = $refusal->getSourceUpdatedAt();
		if ($sourceUpdatedAt !== null) {
			$body['sourceUpdatedAt'] = $sourceUpdatedAt->format(DATE_ATOM);
		}

		return $body;
	}//end staleBody()
}//end class
