<?php

/**
 * Contract of EncryptService
 *
 * The public surface of EncryptService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Keepiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/keepiq
 *
 * @spec openspec/specs/app-shell/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Kernel\Contracts;

/**
 * Contract of EncryptService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EncryptServiceInterface {

	/**
	 * Encrypt a PEM private key with an AES key derived from a password via PBKDF2-SHA256.
	 *
	 * @param string $pem PEM-encoded private key
	 * @param string $password The master password (or passphrase)
	 *
	 * @return string Base64-encoded envelope with salt embedded
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-3
	 */
	public function encryptPrivateKey(string $pem, string $password): string;

	/**
	 * Encrypt plaintext with an RSA public key using OAEP-SHA256 with chunking.
	 *
	 * @param string $plaintext The data to encrypt
	 * @param string $publicKeyPem PEM-encoded public key or certificate
	 *
	 * @return string Base64-encoded ciphertext: [4-byte chunk count][512-byte blocks...]
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-3
	 */
	public function rsaEncrypt(string $plaintext, string $publicKeyPem): string;
}//end interface
