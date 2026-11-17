<?php

/**
 * Keepiq Secret Trash Controller
 *
 * Restore, delete for good, archive and unarchive a secret
 * (vault-trash-and-archive). Every action is the owner's alone; the service
 * refuses anyone else with a ForbiddenException, answered here as 403.
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

use Closure;
use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Kernel\Contracts\SecretTrashServiceInterface;
use OCA\Keepiq\Service\PrivilegedAccess\PrivilegedCopyGate;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Trash and archive actions on one secret.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thin transport layer: each
 *   refusal maps to its own status, and the privileged-copy gate that
 *   withholds a returned copy adds one.
 */
class SecretTrashController extends Controller {
	/**
	 * Constructor for SecretTrashController.
	 *
	 * @param IRequest $request The request
	 * @param SecretTrashServiceInterface $trashService The trash service
	 * @param IUserSession $userSession The user session
	 * @param PrivilegedCopyGate|null $privilegedGate Withholds a privileged copy outside its approved window
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private SecretTrashServiceInterface $trashService,
		private IUserSession $userSession,
		private ?PrivilegedCopyGate $privilegedGate = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Delete a secret: it moves to the trash and its shares end now. The
	 * response keeps `status: deleted` for existing clients and adds
	 * `trashed: true`.
	 *
	 * @param string $id The secret ID
	 * @param string|null $baseUpdatedAt The version an offline delete was made from (409 when it changed)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-deleting-a-secret-moves-it-to-the-trash
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	#[NoAdminRequired]
	public function trash(string $id, ?string $baseUpdatedAt=null): JSONResponse {
		return $this->run(
			action: function (string $userId) use ($id, $baseUpdatedAt): array {
				$this->trashService->trash($id, $userId, $baseUpdatedAt);
				return ['status' => 'deleted', 'trashed' => true];
			}
		);
	}//end trash()

	/**
	 * Take a secret out of the trash. The body is the secret; a restored
	 * read-only copy from another organisation adds `federatedShare`
	 * (`resumed`, `ended` or `unreachable`).
	 *
	 * @param string $id The secret ID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-the-owner-revoked-the-share-meanwhile
	 */
	#[NoAdminRequired]
	public function restore(string $id): JSONResponse {
		return $this->run(
			action: function (string $userId) use ($id): array {
				$restored = $this->trashService->restore($id, $userId);
				$body = $this->serialised(secret: $restored['secret'], userId: $userId);
				if ($restored['federatedShare'] !== null) {
					$body['federatedShare'] = $restored['federatedShare'];
				}

				return $body;
			}
		);
	}//end restore()

	/**
	 * Delete a trashed secret for good.
	 *
	 * @param string $id The secret ID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	#[NoAdminRequired]
	public function purge(string $id): JSONResponse {
		return $this->run(
			action: function (string $userId) use ($id): array {
				$this->trashService->purge($id, $userId);
				return ['status' => 'purged'];
			}
		);
	}//end purge()

	/**
	 * Archive a secret.
	 *
	 * @param string $id The secret ID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
	 */
	#[NoAdminRequired]
	public function archive(string $id): JSONResponse {
		return $this->run(action: fn (string $userId) => $this->trashService->archive($id, $userId));
	}//end archive()

	/**
	 * Bring an archived secret back into the vault.
	 *
	 * @param string $id The secret ID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
	 */
	#[NoAdminRequired]
	public function unarchive(string $id): JSONResponse {
		return $this->run(action: fn (string $userId) => $this->trashService->unarchive($id, $userId));
	}//end unarchive()

	/**
	 * Run one owner action and map its refusals onto HTTP statuses.
	 *
	 * @param Closure $action Receives the user id, returns the response body
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	private function run(Closure $action): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $action($user->getUID()));
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (StaleWriteException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage(), 'current' => $this->serialised(secret: $e->getCurrent(), userId: $user->getUID())],
				statusCode: Http::STATUS_CONFLICT
			);
		} catch (ShareScanPendingException $e) {
			// A share of the secret waits for the share quarantine scan.
			return new JSONResponse(
				data: ['error' => 'share_scan_pending', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_CONFLICT
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_CONFLICT);
		}
	}//end run()

	/**
	 * A row as it is returned to the caller: without its ciphertext when it
	 * is a privileged copy outside its approved window.
	 *
	 * @param Secret $secret The row
	 * @param string $userId The caller
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	private function serialised(Secret $secret, string $userId): array {
		return $this->privilegedGate?->serialisedFor(secret: $secret, userId: $userId) ?? $secret->jsonSerialize();
	}//end serialised()
}//end class
