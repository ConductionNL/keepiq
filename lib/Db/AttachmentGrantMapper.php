<?php

/**
 * Doriath Attachment Grant Mapper
 *
 * Query-builder mapper for AttachmentGrant rows, including the
 * reference-count query the blob garbage collection relies on.
 *
 * @category Db
 * @package  OCA\Doriath\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for the doriath_attachment_grants table.
 *
 * @template-extends QBMapper<AttachmentGrant>
 */
class AttachmentGrantMapper extends QBMapper {
	/**
	 * Constructor for AttachmentGrantMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'doriath_attachment_grants',
			entityClass: AttachmentGrant::class
		);
	}//end __construct()

	/**
	 * Find all grants of an attachment.
	 *
	 * @param string $attachmentId The attachment UUID
	 *
	 * @return AttachmentGrant[]
	 */
	public function findByAttachment(string $attachmentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('attachment_id', $qb->createNamedParameter($attachmentId)));

		return $this->findEntities(query: $qb);
	}//end findByAttachment()

	/**
	 * Find the grant addressed to one recipient for one attachment.
	 *
	 * @param string $attachmentId The attachment UUID
	 * @param string $recipientId The recipient user/application ID
	 *
	 * @return AttachmentGrant
	 *
	 * @throws DoesNotExistException When the caller holds no grant
	 */
	public function findForRecipient(string $attachmentId, string $recipientId): AttachmentGrant {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('attachment_id', $qb->createNamedParameter($attachmentId)))
			->andWhere($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)))
			->setMaxResults(1);

		return $this->findEntity(query: $qb);
	}//end findForRecipient()

	/**
	 * Find all grants attached to a Secret copy (revocation cascade).
	 *
	 * @param string $secretId The Secret copy UUID
	 *
	 * @return AttachmentGrant[]
	 */
	public function findBySecret(string $secretId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $this->findEntities(query: $qb);
	}//end findBySecret()

	/**
	 * Count the remaining grants of an attachment (blob GC reference count).
	 *
	 * @param string $attachmentId The attachment UUID
	 *
	 * @return int
	 */
	public function countByAttachment(string $attachmentId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('attachment_id', $qb->createNamedParameter($attachmentId)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countByAttachment()
}//end class
