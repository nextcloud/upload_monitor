<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UploadMonitor\BackgroundJob;

use OCA\UploadMonitor\AppInfo\Application;
use OCA\UploadMonitor\Db\Rule;
use OCA\UploadMonitor\Db\RuleMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Notification\IManager as INotificationManager;
use Override;
use Psr\Log\LoggerInterface;

class CheckInactivity extends TimedJob {
	private const RENOTIFY_INTERVAL_DAYS = 7;

	public function __construct(
		ITimeFactory $time,
		private RuleMapper $ruleMapper,
		private IRootFolder $rootFolder,
		private INotificationManager $notificationManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// Run once daily (24 hours = 86400 seconds)
		$this->setInterval(86400);
	}

	#[Override]
	protected function run($argument): void {
		$rules = $this->ruleMapper->findAll();
		$now = $this->time->getTime();
		$renotifySeconds = self::RENOTIFY_INTERVAL_DAYS * 86400;

		foreach ($rules as $rule) {
			try {
				$this->checkRule($rule, $now, $renotifySeconds);
			} catch (\Exception $e) {
				$this->logger->error('Error checking upload monitor rule {id}', [
					'id' => $rule->getId(),
					'exception' => $e,
				]);
			}
		}
	}

	private function checkRule(Rule $rule, int $now, int $renotifySeconds): void {
		$userId = $rule->getUserId();
		$directoryPath = $rule->getDirectoryPath();

		// Check if directory still exists
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$userFolder->get($directoryPath);
		} catch (NotFoundException) {
			// Directory no longer exists
			$this->sendDirectoryMissingNotification($rule, $now, $renotifySeconds);
			return;
		} catch (\Exception $e) {
			// User might not exist anymore, skip
			$this->logger->warning('Could not access user folder for rule {id}', [
				'id' => $rule->getId(),
				'exception' => $e,
			]);
			return;
		}

		// Determine effective last upload time
		$lastUploadAt = $rule->getLastUploadAt();
		if ($lastUploadAt === null) {
			// Use created_at from snowflake ID
			$createdAt = $rule->getCreatedAt();
			$effectiveLastUpload = $createdAt?->getTimestamp() ?? $now;
		} else {
			$effectiveLastUpload = $lastUploadAt;
		}

		$thresholdSeconds = $rule->getThresholdDays() * 86400;
		$inactivityDuration = $now - $effectiveLastUpload;

		if ($inactivityDuration >= $thresholdSeconds) {
			$lastNotifiedAt = $rule->getLastNotifiedAt();

			if ($lastNotifiedAt === null || ($now - $lastNotifiedAt) >= $renotifySeconds) {
				$this->sendInactivityNotification($rule, $lastUploadAt, $now);
			}
		}
	}

	private function sendInactivityNotification(Rule $rule, ?int $lastUploadAt, int $now): void {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($rule->getUserId())
			->setDateTime($this->time->getDateTime())
			->setObject('rule', (string)$rule->getId())
			->setSubject('inactivity_alert', [
				'path' => $rule->getDirectoryPath(),
				'lastUploadAt' => $lastUploadAt,
			]);

		$this->notificationManager->notify($notification);
		$this->ruleMapper->updateLastNotifiedAt((string)$rule->getId(), $now);
	}

	private function sendDirectoryMissingNotification(Rule $rule, int $now, int $renotifySeconds): void {
		$lastNotifiedAt = $rule->getLastNotifiedAt();

		if ($lastNotifiedAt !== null && ($now - $lastNotifiedAt) < $renotifySeconds) {
			return;
		}

		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($rule->getUserId())
			->setDateTime($this->time->getDateTime())
			->setObject('rule', (string)$rule->getId())
			->setSubject('directory_missing', [
				'path' => $rule->getDirectoryPath(),
			]);

		$this->notificationManager->notify($notification);
		$this->ruleMapper->updateLastNotifiedAt((string)$rule->getId(), $now);
	}
}
