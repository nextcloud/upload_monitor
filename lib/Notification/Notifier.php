<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UploadMonitor\Notification;

use OCA\UploadMonitor\AppInfo\Application;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;
use Override;

class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
	) {
	}

	#[Override]
	public function getID(): string {
		return Application::APP_ID;
	}

	#[Override]
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Upload Monitor');
	}

	#[Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();

		switch ($notification->getSubject()) {
			case 'inactivity_alert':
				$path = $params['path'];
				$lastUploadAt = $params['lastUploadAt'] ?? $params['createdAt'];

				if ($lastUploadAt !== null) {
					$date = new \DateTime('@' . $lastUploadAt);
					$dateStr = $date->format('Y-m-d');
					$notification->setParsedSubject(
						$l->t('No new files have been uploaded to %1$s since %2$s.', [$path, $dateStr])
					);
				} else {
					$notification->setParsedSubject(
						$l->t('No new files have been uploaded to %1$s since this rule was created (never detected).', [$path])
					);
				}
				break;

			case 'directory_missing':
				$path = $params['path'];
				$notification->setParsedSubject(
					$l->t('The watched folder %1$s no longer exists. You may want to remove this watch rule.', [$path])
				);
				break;

			default:
				throw new UnknownNotificationException();
		}

		return $notification;
	}
}
