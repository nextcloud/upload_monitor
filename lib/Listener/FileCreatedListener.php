<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UploadMonitor\Listener;

use OCA\UploadMonitor\AppInfo\Application;
use OCA\UploadMonitor\Db\RuleMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\File;
use OCP\ICache;
use OCP\ICacheFactory;
use Override;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<NodeCreatedEvent> */
class FileCreatedListener implements IEventListener {
	public const CACHE_KEY = 'all_rules';

	private ICache $cache;

	public function __construct(
		private RuleMapper $ruleMapper,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID);
	}

	#[Override]
	public function handle(Event $event): void {
		if (!($event instanceof NodeCreatedEvent)) {
			return;
		}

		$node = $event->getNode();

		// Only track file creations, not folders
		if (!($node instanceof File)) {
			return;
		}

		try {
			$filePath = $node->getPath();
		} catch (\Exception $e) {
			$this->logger->error('Could not get path for created file', ['exception' => $e]);
			return;
		}

		$allRules = $this->getCachedRules();
		$now = $this->timeFactory->getTime();

		foreach ($allRules as $rule) {
			// The file path from the node is like /user/files/Photos/file.jpg
			// The watched directory is like /Photos
			// We need to check if the file is inside /<userId>/files/<watchedDir>
			$expectedPrefix = '/' . $rule['userId'] . '/files' . $rule['directoryPath'];

			// Ensure prefix matching works for directories (avoid /Photos matching /PhotosBackup)
			$normalizedPrefix = rtrim($expectedPrefix, '/') . '/';

			if (str_starts_with($filePath, $normalizedPrefix) || $filePath === rtrim($expectedPrefix, '/')) {
				$this->ruleMapper->updateLastUploadAt($rule['id'], $now);
			}
		}
	}

	/**
	 * @return list<array{id: string, userId: string, directoryPath: string}>
	 */
	private function getCachedRules(): array {
		$cached = $this->cache->get(self::CACHE_KEY);
		if ($cached !== null) {
			return $cached;
		}

		$rules = $this->ruleMapper->findAll();
		$data = array_values(array_map(fn ($rule) => [
			'id' => (string)$rule->getId(),
			'userId' => $rule->getUserId(),
			'directoryPath' => $rule->getDirectoryPath(),
		], $rules));

		// Cache for 15 minutes
		$this->cache->set(self::CACHE_KEY, $data, 900);
		return $data;
	}
}
