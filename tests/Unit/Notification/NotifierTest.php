<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UploadMonitor\Tests\Unit\Notification;

use OCA\UploadMonitor\Notification\Notifier;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class NotifierTest extends TestCase {
	private IFactory&MockObject $l10nFactory;
	private IL10N&MockObject $l;
	private Notifier $notifier;

	protected function setUp(): void {
		parent::setUp();

		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(fn (string $s, $args = []) => vsprintf($s, $args));
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->l10nFactory->method('get')->willReturn($this->l);

		$this->notifier = new Notifier($this->l10nFactory);
	}

	public function testGetID(): void {
		$this->assertSame('upload_monitor', $this->notifier->getID());
	}

	public function testPrepareWrongApp(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('wrong_app');

		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepareUnknownSubject(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('upload_monitor');
		$notification->method('getSubject')->willReturn('unknown');
		$notification->method('getSubjectParameters')->willReturn([]);

		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepareInactivityAlertWithDate(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('upload_monitor');
		$notification->method('getSubject')->willReturn('inactivity_alert');
		$notification->method('getSubjectParameters')->willReturn([
			'path' => '/Photos',
			'lastUploadAt' => 1700000000,
		]);
		$notification->expects($this->once())->method('setParsedSubject')
			->with($this->stringContains('/Photos'))
			->willReturnSelf();

		$result = $this->notifier->prepare($notification, 'en');
		$this->assertSame($notification, $result);
	}

	public function testPrepareInactivityAlertNeverUploaded(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('upload_monitor');
		$notification->method('getSubject')->willReturn('inactivity_alert');
		$notification->method('getSubjectParameters')->willReturn([
			'path' => '/Photos',
			'lastUploadAt' => null,
			'createdAt' => null,
		]);
		$notification->expects($this->once())->method('setParsedSubject')
			->with($this->stringContains('never detected'))
			->willReturnSelf();

		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepareDirectoryMissing(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('upload_monitor');
		$notification->method('getSubject')->willReturn('directory_missing');
		$notification->method('getSubjectParameters')->willReturn([
			'path' => '/Deleted',
		]);
		$notification->expects($this->once())->method('setParsedSubject')
			->with($this->stringContains('/Deleted'))
			->willReturnSelf();

		$this->notifier->prepare($notification, 'en');
	}
}
