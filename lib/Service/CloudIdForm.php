<?php

/**
 * Keepiq Cloud Id Form
 *
 * The form two federated cloud ids are compared in
 * (sharing-federated-recipients). Nextcloud writes a user's own cloud id on
 * an http instance with the scheme (`bob@http://cloud.example`) and without
 * it on https, while a typed or resolved cloud id has none. Compared as
 * `user@host[:port][/path]`, with the scheme and a trailing slash dropped
 * and the host part lowercased, they name the same user. The user part stays
 * as it is.
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

/**
 * Canonical form of a federated cloud id.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */
final class CloudIdForm {
	/**
	 * The canonical form, or '' when there is no `user@remote`.
	 *
	 * @param string $cloudId A cloud id
	 *
	 * @return string
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function canonical(string $cloudId): string {
		$atSign = strrpos($cloudId, '@');
		if ($atSign === false || $atSign === 0) {
			return '';
		}

		$remote = (string)preg_replace('#^https?://#i', '', substr($cloudId, $atSign + 1));
		$remote = strtolower(rtrim($remote, '/'));
		if ($remote === '') {
			return '';
		}

		return substr($cloudId, 0, $atSign) . '@' . $remote;
	}//end canonical()

	/**
	 * Whether two cloud ids name the same user on the same instance.
	 *
	 * @param string $first A cloud id
	 * @param string $second Another
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function same(string $first, string $second): bool {
		$canonical = $this->canonical(cloudId: $first);

		return $canonical !== '' && $canonical === $this->canonical(cloudId: $second);
	}//end same()
}//end class
