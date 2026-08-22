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
use OCP\Files\Config\IUserMountCache;
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
		private IUserMountCache $userMountCache,
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

		$allRules = $this->getCachedRules();
		if ($allRules === []) {
			return;
		}

		try {
			$filePath = $node->getPath();
			$fileId = $node->getId();
		} catch (\Exception $e) {
			$this->logger->error('Could not get path for created file', ['exception' => $e]);
			return;
		}

		$now = $this->timeFactory->getTime();

		/** @var array<string, list<string>>|null $pathsByUser */
		$pathsByUser = null;

		foreach ($allRules as $rule) {
			$userId = $rule['userId'];

			// The file path from the node is like /user/files/Photos/file.jpg
			// The watched directory is like /Photos
			// We need to check if the file is inside /<userId>/files/<watchedDir>
			if ($this->isWatched($filePath, $userId, $rule['directoryPath'])) {
				$this->ruleMapper->updateLastUploadAt($rule['id'], $now);
				continue;
			}

			// The event carries the path as seen by the user performing the upload. When
			// somebody else uploads into a folder that is shared with the owner of the
			// rule, that path is in the uploader's namespace and never matches above, so
			// look up where the file is located for the owner of the rule instead.
			if (str_starts_with($filePath, '/' . $userId . '/files/')) {
				// The path already belongs to the owner of the rule, so it is simply not watched
				continue;
			}

			$pathsByUser ??= $this->getPathsByUser($fileId);

			foreach ($pathsByUser[$userId] ?? [] as $userPath) {
				if ($this->isWatched($userPath, $userId, $rule['directoryPath'])) {
					$this->ruleMapper->updateLastUploadAt($rule['id'], $now);
					break;
				}
			}
		}
	}

	/**
	 * Check whether a path in the namespace of the given user is inside the watched directory.
	 */
	private function isWatched(string $path, string $userId, string $directoryPath): bool {
		$watchedPath = rtrim('/' . $userId . '/files' . $directoryPath, '/');

		// Ensure prefix matching works for directories (avoid /Photos matching /PhotosBackup)
		return $path === $watchedPath || str_starts_with($path, $watchedPath . '/');
	}

	/**
	 * Get all paths the file is available at, grouped by the user it is available for.
	 *
	 * @return array<string, list<string>>
	 */
	private function getPathsByUser(int $fileId): array {
		try {
			$mountInfos = $this->userMountCache->getMountsForFileId($fileId);
		} catch (\Exception $e) {
			$this->logger->error('Could not resolve mounts for created file', ['exception' => $e]);
			return [];
		}

		$pathsByUser = [];
		foreach ($mountInfos as $mountInfo) {
			$pathsByUser[$mountInfo->getUser()->getUID()][] = $mountInfo->getPath();
		}

		return $pathsByUser;
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
