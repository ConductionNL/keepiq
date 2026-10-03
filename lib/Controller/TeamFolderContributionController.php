<?php

/**
 * Keepiq Team Folder Contribution Controller
 *
 * The team folder endpoints of the vault policies (admin-vault-policies D5,
 * D6): a write-grade member saves a new secret into a team folder they do
 * not own, the team folders they can write to, the public certificates a
 * contribution is encrypted for, and the user's own secrets that break the
 * ownership policy. Every endpoint is scoped to the session user; grade
 * checks run in the services before anything is read or stored.
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
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\OrgOwnershipGuard;
use OCA\Keepiq\Service\TeamFolderContributionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Write-grade contributions and ownership findings.
 */
class TeamFolderContributionController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 * @param IUserSession $userSession The user session
	 * @param TeamFolderContributionService $contributions Write-grade member contributions
	 * @param OrgOwnershipGuard $ownership The ownership policy findings
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private TeamFolderContributionService $contributions,
		private OrgOwnershipGuard $ownership,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The session user id, or null when anonymous.
	 *
	 * @return string|null
	 */
	private function sessionUserId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end sessionUserId()

	/**
	 * The team folders the session user may contribute to (write grade, not
	 * owned), for the secret form's folder picker (admin-vault-policies §4.3).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-vault-policies/tasks.md#4.3
	 */
	#[NoAdminRequired]
	public function contributable(): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: $this->contributions->contributable(userId: $userId));
	}//end contributable()

	/**
	 * The session user's own secrets that break the team folder ownership
	 * policy, for the health report (admin-vault-policies D6). Metadata
	 * only, scoped to the session user; empty when the policy does not
	 * apply.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-vault-policies/tasks.md#4.4
	 */
	#[NoAdminRequired]
	public function ownershipFindings(): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: $this->ownership->findings(userId: $userId));
	}//end ownershipFindings()

	/**
	 * The public certificates a write-grade member encrypts a contribution
	 * for (admin-vault-policies §4.3). Refused to anyone without write.
	 *
	 * @param string $id The TeamFolder UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-vault-policies/tasks.md#4.3
	 */
	#[NoAdminRequired]
	public function contributionContext(string $id): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $this->contributions->context(teamFolderId: $id, userId: $userId));
		} catch (NotFoundException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}
	}//end contributionContext()

	/**
	 * A write-grade member saves a new secret into a team folder they do not
	 * own (admin-vault-policies D5). The body carries the owner ciphertext
	 * (`key`, `login`, `additionalFields`), metadata and one `copies` row per
	 * member. The grade is checked in the service before anything is stored;
	 * a read-grade member or a non-member gets 403.
	 *
	 * @param string $id The TeamFolder UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-vault-policies/tasks.md#4.2
	 */
	#[NoAdminRequired]
	public function contribute(string $id): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$data = [];
		foreach (['name', 'url', 'typeId', 'folderId', 'key', 'login', 'additionalFields', 'copies'] as $field) {
			$data[$field] = $this->request->getParam($field);
		}

		try {
			$result = $this->contributions->contribute(teamFolderId: $id, data: $data, userId: $userId);
		} catch (NotFoundException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(
			data: ['secret' => $result['secret']->jsonSerialize(), 'copies' => $result['copies']],
			statusCode: Http::STATUS_CREATED
		);
	}//end contribute()
}//end class
