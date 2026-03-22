<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UploadMonitor\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Rule>
 */
class RuleMapper extends QBMapper {
	public const TABLE_NAME = 'upload_monitor_rules';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, Rule::class);
	}

	/**
	 * @return Rule[]
	 */
	public function findAllByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntities($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByIdAndUser(string $id, string $userId): Rule {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * @return Rule[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());

		return $this->findEntities($qb);
	}

	public function existsByUserAndPath(string $userId, string $directoryPath): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('directory_path_hash', $qb->createNamedParameter(md5($directoryPath))));

		$cursor = $qb->executeQuery();
		$count = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $count > 0;
	}

	/**
	 * Update last_upload_at for a rule.
	 */
	public function updateLastUploadAt(string $id, int $timestamp): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('last_upload_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}

	/**
	 * Update last_notified_at for a rule.
	 */
	public function updateLastNotifiedAt(string $id, int $timestamp): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('last_notified_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}
}
