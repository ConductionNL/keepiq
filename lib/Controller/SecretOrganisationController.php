<?php

/**
 * Keepiq Secret Organisation Controller
 *
 * Star a secret, set its tags, list the tags, and record a fill from the
 * browser extension (vault-favourites-tags-and-last-used). Every action is
 * on the caller's own row; the service answers anything else as not found,
 * mapped here to 404.
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
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\SecretOrganisationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Favourites, tags and last used on the caller's own secrets.
 */
class SecretOrganisationController extends Controller {
	/**
	 * Constructor for SecretOrganisationController.
	 *
	 * @param IRequest                  $request      The request
	 * @param SecretOrganisationService $organisation The organisation service
	 * @param IUserSession              $userSession  The user session
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private SecretOrganisationService $organisation,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Star or unstar one of the caller's secrets.
	 *
	 * @param string $id        The secret ID
	 * @param bool   $favourite The new star
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-favourite-items-per-holder
	 */
	#[NoAdminRequired]
	public function favourite(string $id, bool $favourite = false): JSONResponse {
		return $this->run(action: fn (string $userId): array => $this->organisation->setFavourite($id, $userId, $favourite));
	}//end favourite()

	/**
	 * Replace the tags on one of the caller's secrets.
	 *
	 * @param string       $id   The secret ID
	 * @param array<mixed> $tags The tags
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	#[NoAdminRequired]
	public function tags(string $id, array $tags = []): JSONResponse {
		return $this->run(
			action: fn (string $userId): array => ['id' => $id, 'tags' => $this->organisation->setTags($id, $userId, $tags)]
		);
	}//end tags()

	/**
	 * The caller's tags with how many live secrets carry each.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	#[NoAdminRequired]
	public function tagIndex(): JSONResponse {
		return $this->run(action: fn (string $userId): array => ['tags' => $this->organisation->listTags($userId)]);
	}//end tagIndex()

	/**
	 * Record that the paired browser extension filled one of the caller's
	 * secrets. Same authentication as the extension's other routes.
	 *
	 * @param string $id The secret ID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-sort-by-date-last-used
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function used(string $id): JSONResponse {
		return $this->run(
			action: function (string $userId) use ($id): array {
				$this->organisation->markUsed($id, $userId);
				return ['status' => 'recorded'];
			}
		);
	}//end used()

	/**
	 * Run one holder action and map its refusals onto HTTP statuses.
	 *
	 * @param Closure $action Receives the user id, returns the response body
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-sort-by-date-last-used
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
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end run()
}//end class
