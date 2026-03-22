<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UploadMonitor\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

#[CreateTable(table: 'upload_monitor_rules', columns: ['id', 'user_id', 'directory_path', 'directory_path_hash', 'threshold_days', 'last_upload_at', 'last_notified_at'], description: 'Store upload monitoring watch rules')]
class Version1000Date20260322000000 extends SimpleMigrationStep {
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('upload_monitor_rules')) {
			$table = $schema->createTable('upload_monitor_rules');

			$table->addColumn('id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('directory_path', Types::STRING, [
				'notnull' => true,
				'length' => 4000,
			]);
			$table->addColumn('directory_path_hash', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('threshold_days', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('last_upload_at', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('last_notified_at', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueConstraint(['user_id', 'directory_path_hash'], 'um_rules_user_dir');
		}

		return $schema;
	}
}
