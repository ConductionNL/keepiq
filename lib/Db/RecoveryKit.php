<?php

/**
 * Keepiq RecoveryKit
 *
 * @category Db
 * @package  OCA\Keepiq\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md
 */

declare(strict_types=1);

namespace OCA\Keepiq\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A user's suite private key wrapped with a key derived from their own
 * recovery code (crypto-recovery-kit). The code never reaches the server,
 * so the server holds nothing it can unwrap.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getSuiteId()
 * @method void setSuiteId(string $suiteId)
 * @method string getWrappedKey()
 * @method void setWrappedKey(string $wrappedKey)
 * @method string getKdf()
 * @method void setKdf(string $kdf)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
 */
class RecoveryKit extends Entity implements JsonSerializable {
	use StringPrimaryKey;

	/**
	 * The owner.
	 *
	 * @var string
	 */
	protected string $userId = '';

	/**
	 * The suite whose private key is wrapped.
	 *
	 * @var string
	 */
	protected string $suiteId = '';

	/**
	 * The wrapped private key, as the browser sent it.
	 *
	 * @var string
	 */
	protected string $wrappedKey = '';

	/**
	 * The key derivation parameters (salt, Argon2id costs), as the browser sent them.
	 *
	 * @var string
	 */
	protected string $kdf = '';

	/**
	 * When the kit was made.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * The primary key.
	 *
	 * @var string
	 */
	public $id = '';

	/**
	 * Declare the column types.
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'userId', type: 'string');
		$this->addType(fieldName: 'suiteId', type: 'string');
		$this->addType(fieldName: 'wrappedKey', type: 'string');
		$this->addType(fieldName: 'kdf', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
	}//end __construct()

	/**
	 * The kit without its wrapped key: what settings show.
	 *
	 * @return array{suiteId: string, createdAt: string|null}
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
	 */
	public function jsonSerialize(): array {
		return [
			'suiteId' => $this->suiteId,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
