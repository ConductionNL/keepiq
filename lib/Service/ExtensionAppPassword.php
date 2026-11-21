<?php

/**
 * Keepiq Extension App Password
 *
 * The app password a browser-extension request authenticated with. The
 * extension signs in to Nextcloud through Login Flow v2 (or a pasted app
 * password) and sends it as Basic auth without cookies; core then records
 * the app password in the request's session. Its token id is what an
 * extension passkey and its challenges are bound to. A request that did not
 * authenticate with an app password, such as a browser session, has none.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCP\Authentication\Token\IProvider;
use OCP\ISession;
use Throwable;

/**
 * Resolves the token id of the request's app password.
 *
 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
 */
class ExtensionAppPassword {
	/**
	 * Constructor for ExtensionAppPassword.
	 *
	 * @param ISession $session The request's session, where core records the app password
	 * @param IProvider $tokens Core's token provider
	 *
	 * @return void
	 */
	public function __construct(
		private ISession $session,
		private IProvider $tokens,
	) {
	}//end __construct()

	/**
	 * The token id of the app password this request authenticated with, as
	 * long as it belongs to the given user; null otherwise.
	 *
	 * @param string $uid The session user
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
	 */
	public function tokenId(string $uid): ?int {
		$appPassword = $this->session->get('app_password');
		if (is_string($appPassword) === false || $appPassword === '') {
			return null;
		}

		try {
			$token = $this->tokens->getToken($appPassword);
		} catch (Throwable) {
			return null;
		}

		if ($token->getUID() !== $uid) {
			return null;
		}

		return $token->getId();
	}//end tokenId()
}//end class
