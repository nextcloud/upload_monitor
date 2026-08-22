<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UploadMonitor\Tests\Unit\Listener;

use OCA\UploadMonitor\Db\Rule;
use OCA\UploadMonitor\Db\RuleMapper;
use OCA\UploadMonitor\Listener\FileCreatedListener;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\Files\Config\ICachedMountFileInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class FileCreatedListenerTest extends TestCase {
	private RuleMapper&MockObject $ruleMapper;
	private ITimeFactory&MockObject $timeFactory;
	private IUserMountCache&MockObject $userMountCache;
	private INotificationManager&MockObject $notificationManager;
	private ICache&MockObject $cache;
	private FileCreatedListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->ruleMapper = $this->createMock(RuleMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturn(1000000);
		$this->userMountCache = $this->createMock(IUserMountCache::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->cache = $this->createMock(ICache::class);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);

		$this->listener = new FileCreatedListener(
			$this->ruleMapper,
			$this->timeFactory,
			$this->createMock(LoggerInterface::class),
			$this->userMountCache,
			$this->notificationManager,
			$cacheFactory,
		);
	}

	public function testIgnoresNonNodeCreatedEvent(): void {
		$this->ruleMapper->expects($this->never())->method('findAll');
		$this->listener->handle($this->createMock(Event::class));
	}

	public function testIgnoresFolderCreation(): void {
		$folder = $this->createMock(Folder::class);
		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($folder);

		$this->ruleMapper->expects($this->never())->method('findAll');
		$this->listener->handle($event);
	}

	public function testMatchesFileInWatchedDirectory(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/Photos/vacation/pic.jpg');

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn(null);

		$rule = $this->createRule('rule1', 'alice', '/Photos');
		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$this->ruleMapper->expects($this->once())
			->method('updateLastUploadAt')
			->with('rule1', 1000000);

		$this->listener->handle($event);
	}

	public function testDismissesNotificationOfWatchedDirectory(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/Photos/vacation/pic.jpg');

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn(null);

		$rule = $this->createRule('rule1', 'alice', '/Photos');
		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$notification = $this->createMock(INotification::class);
		$notification->expects($this->once())->method('setApp')->with('upload_monitor')->willReturnSelf();
		$notification->expects($this->once())->method('setUser')->with('alice')->willReturnSelf();
		$notification->expects($this->once())->method('setObject')->with('rule', 'rule1')->willReturnSelf();

		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())
			->method('markProcessed')
			->with($notification);

		$this->listener->handle($event);
	}

	public function testDismissesNotificationForUploadOfOtherUser(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/bob/files/Alices Photos/pic.jpg');
		$file->method('getId')->willReturn(42);

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn(null);

		$rule = $this->createRule('rule1', 'alice', '/Photos');
		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$this->userMountCache->method('getMountsForFileId')
			->with(42)
			->willReturn([
				$this->createMountInfo('bob', '/bob/files/Alices Photos/pic.jpg'),
				$this->createMountInfo('alice', '/alice/files/Photos/pic.jpg'),
			]);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->expects($this->once())->method('setUser')->with('alice')->willReturnSelf();
		$notification->expects($this->once())->method('setObject')->with('rule', 'rule1')->willReturnSelf();

		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())
			->method('markProcessed')
			->with($notification);

		$this->listener->handle($event);
	}

	public function testDoesNotDismissNotificationOfUnrelatedDirectory(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/Documents/report.pdf');

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn(null);

		$rule = $this->createRule('rule1', 'alice', '/Photos');
		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$this->notificationManager->expects($this->never())->method('markProcessed');

		$this->listener->handle($event);
	}

	public function testDoesNotMatchFileOutsideWatchedDirectory(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/Documents/report.pdf');

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn(null);

		$rule = $this->createRule('rule1', 'alice', '/Photos');
		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$this->ruleMapper->expects($this->never())->method('updateLastUploadAt');

		$this->listener->handle($event);
	}

	public function testDoesNotMatchSimilarDirectoryName(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/PhotosBackup/pic.jpg');

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn(null);

		$rule = $this->createRule('rule1', 'alice', '/Photos');
		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$this->ruleMapper->expects($this->never())->method('updateLastUploadAt');

		$this->listener->handle($event);
	}

	public function testDoesNotMatchOtherUsersRule(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/Photos/pic.jpg');
		$file->method('getId')->willReturn(42);

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn(null);

		$rule = $this->createRule('rule1', 'bob', '/Photos');
		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		// The file is not available to bob at all
		$this->userMountCache->method('getMountsForFileId')
			->with(42)
			->willReturn([$this->createMountInfo('alice', '/alice/files/Photos/pic.jpg')]);

		$this->ruleMapper->expects($this->never())->method('updateLastUploadAt');

		$this->listener->handle($event);
	}

	public function testMatchesUploadOfOtherUserIntoSharedFolder(): void {
		// bob uploaded into a folder that alice shared with him
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/bob/files/Alices Photos/pic.jpg');
		$file->method('getId')->willReturn(42);

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn(null);

		$rule = $this->createRule('rule1', 'alice', '/Photos');
		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$this->userMountCache->method('getMountsForFileId')
			->with(42)
			->willReturn([
				$this->createMountInfo('bob', '/bob/files/Alices Photos/pic.jpg'),
				$this->createMountInfo('alice', '/alice/files/Photos/pic.jpg'),
			]);

		$this->ruleMapper->expects($this->once())
			->method('updateLastUploadAt')
			->with('rule1', 1000000);

		$this->listener->handle($event);
	}

	public function testDoesNotMatchUploadOfOtherUserOutsideWatchedDirectory(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/bob/files/Alices Documents/report.pdf');
		$file->method('getId')->willReturn(42);

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn(null);

		$rule = $this->createRule('rule1', 'alice', '/Photos');
		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		$this->userMountCache->method('getMountsForFileId')
			->with(42)
			->willReturn([
				$this->createMountInfo('bob', '/bob/files/Alices Documents/report.pdf'),
				$this->createMountInfo('alice', '/alice/files/Documents/report.pdf'),
			]);

		$this->ruleMapper->expects($this->never())->method('updateLastUploadAt');

		$this->listener->handle($event);
	}

	public function testDoesNotResolveMountsForOwnNamespace(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/Documents/report.pdf');
		$file->method('getId')->willReturn(42);

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn(null);

		$rule = $this->createRule('rule1', 'alice', '/Photos');
		$this->ruleMapper->method('findAll')->willReturn([$rule]);

		// The path is already in alice's namespace, so there is nothing to resolve
		$this->userMountCache->expects($this->never())->method('getMountsForFileId');
		$this->ruleMapper->expects($this->never())->method('updateLastUploadAt');

		$this->listener->handle($event);
	}

	public function testResolvesMountsOnlyOnceForMultipleRules(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/bob/files/Alices Photos/pic.jpg');
		$file->method('getId')->willReturn(42);

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn(null);

		$this->ruleMapper->method('findAll')->willReturn([
			$this->createRule('rule1', 'alice', '/Photos'),
			$this->createRule('rule2', 'alice', '/Photos/2026'),
			$this->createRule('rule3', 'carol', '/Photos'),
		]);

		$this->userMountCache->expects($this->once())
			->method('getMountsForFileId')
			->with(42)
			->willReturn([
				$this->createMountInfo('bob', '/bob/files/Alices Photos/pic.jpg'),
				$this->createMountInfo('alice', '/alice/files/Photos/pic.jpg'),
			]);

		$this->ruleMapper->expects($this->once())
			->method('updateLastUploadAt')
			->with('rule1', 1000000);

		$this->listener->handle($event);
	}

	public function testSkipsMountLookupWithoutRules(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/bob/files/Alices Photos/pic.jpg');

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn(null);
		$this->ruleMapper->method('findAll')->willReturn([]);

		$this->userMountCache->expects($this->never())->method('getMountsForFileId');
		$this->ruleMapper->expects($this->never())->method('updateLastUploadAt');

		$this->listener->handle($event);
	}

	public function testUsesCachedRules(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/Photos/pic.jpg');

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn([
			['id' => 'rule1', 'userId' => 'alice', 'directoryPath' => '/Photos'],
		]);

		// findAll should NOT be called when cache is populated
		$this->ruleMapper->expects($this->never())->method('findAll');
		$this->ruleMapper->expects($this->once())->method('updateLastUploadAt');

		$this->listener->handle($event);
	}

	private function createMountInfo(string $userId, string $path): ICachedMountFileInfo&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$mountInfo = $this->createMock(ICachedMountFileInfo::class);
		$mountInfo->method('getUser')->willReturn($user);
		$mountInfo->method('getPath')->willReturn($path);
		return $mountInfo;
	}

	private function createRule(string $id, string $userId, string $path): Rule {
		$rule = new Rule();
		$ref = new \ReflectionProperty($rule, 'id');
		$ref->setValue($rule, $id);
		$rule->setUserId($userId);
		$rule->setDirectoryPath($path);
		return $rule;
	}
}
