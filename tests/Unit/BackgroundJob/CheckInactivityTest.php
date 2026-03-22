<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UploadMonitor\Tests\Unit\BackgroundJob;

use OCA\UploadMonitor\BackgroundJob\CheckInactivity;
use OCA\UploadMonitor\Db\Rule;
use OCA\UploadMonitor\Db\RuleMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class CheckInactivityTest extends TestCase {
	private RuleMapper&MockObject $ruleMapper;
	private IRootFolder&MockObject $rootFolder;
	private INotificationManager&MockObject $notificationManager;
	private ITimeFactory&MockObject $timeFactory;
	private CheckInactivity $job;

	private const NOW = 1000000;

	protected function setUp(): void {
		parent::setUp();

		$this->ruleMapper = $this->createMock(RuleMapper::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturn(self::NOW);
		$this->timeFactory->method('getDateTime')->willReturn(new \DateTime('@' . self::NOW));

		$this->job = new CheckInactivity(
			$this->timeFactory,
			$this->ruleMapper,
			$this->rootFolder,
			$this->notificationManager,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testSendsInactivityNotification(): void {
		// lastUploadAt 8 days ago exceeds 7-day threshold
		$rule = $this->createRule('rule1', 'alice', '/Photos', 7, self::NOW - 8 * 86400, null);

		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->with('/Photos')->willReturn($this->createMock(Folder::class));
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

		$notification = $this->createNotificationMock();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify')->with($notification);
		$this->ruleMapper->expects($this->once())->method('updateLastNotifiedAt')->with('rule1', self::NOW);

		$this->invokeRun();
	}

	public function testDoesNotNotifyWhenWithinThreshold(): void {
		$rule = $this->createRule('rule1', 'alice', '/Photos', 7, self::NOW - 3 * 86400, null);

		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturn($this->createMock(Folder::class));
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->notificationManager->expects($this->never())->method('notify');

		$this->invokeRun();
	}

	public function testRespectsRenotifyInterval(): void {
		// lastUploadAt 30 days ago (threshold exceeded), but notified only 3 days ago — should NOT re-notify
		$rule = $this->createRule('rule1', 'alice', '/Photos', 7, self::NOW - 30 * 86400, self::NOW - 3 * 86400);

		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturn($this->createMock(Folder::class));
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		// Last notified 3 days ago, renotify interval is 7 — should NOT notify
		$this->notificationManager->expects($this->never())->method('notify');

		$this->invokeRun();
	}

	public function testRenotifiesAfterInterval(): void {
		// lastUploadAt 30 days ago, lastNotifiedAt 8 days ago — should re-notify
		$rule = $this->createRule('rule1', 'alice', '/Photos', 7, self::NOW - 30 * 86400, self::NOW - 8 * 86400);

		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturn($this->createMock(Folder::class));
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$notification = $this->createNotificationMock();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify');

		$this->invokeRun();
	}

	public function testSendsMissingDirectoryNotification(): void {
		$rule = $this->createRule('rule1', 'alice', '/Deleted', 7, null, null);

		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->with('/Deleted')->willThrowException(new NotFoundException());
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

		$notification = $this->createNotificationMock();
		$notification->expects($this->once())->method('setSubject')
			->with('directory_missing', $this->anything())
			->willReturnSelf();

		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify');

		$this->invokeRun();
	}

	public function testSkipsMissingDirectoryRenotifyWithinInterval(): void {
		$rule = $this->createRule('rule1', 'alice', '/Deleted', 7, null, self::NOW - 3 * 86400);

		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willThrowException(new NotFoundException());
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->notificationManager->expects($this->never())->method('notify');

		$this->invokeRun();
	}

	private function invokeRun(): void {
		$method = new \ReflectionMethod($this->job, 'run');
		$method->invoke($this->job, null);
	}

	private function createNotificationMock(): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		return $notification;
	}

	private function createRule(
		string $id,
		string $userId,
		string $path,
		int $threshold,
		?int $lastUploadAt,
		?int $lastNotifiedAt,
	): Rule {
		$rule = new Rule();
		$ref = new \ReflectionProperty($rule, 'id');
		$ref->setValue($rule, $id);
		$rule->setUserId($userId);
		$rule->setDirectoryPath($path);
		$rule->setThresholdDays($threshold);
		if ($lastUploadAt !== null) {
			$rule->setLastUploadAt($lastUploadAt);
		}
		if ($lastNotifiedAt !== null) {
			$rule->setLastNotifiedAt($lastNotifiedAt);
		}
		return $rule;
	}
}
