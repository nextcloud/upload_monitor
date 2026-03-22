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
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class FileCreatedListenerTest extends TestCase {
	private RuleMapper&MockObject $ruleMapper;
	private ITimeFactory&MockObject $timeFactory;
	private ICache&MockObject $cache;
	private FileCreatedListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->ruleMapper = $this->createMock(RuleMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturn(1000000);
		$this->cache = $this->createMock(ICache::class);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);

		$this->listener = new FileCreatedListener(
			$this->ruleMapper,
			$this->timeFactory,
			$this->createMock(LoggerInterface::class),
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

		$event = $this->createMock(NodeCreatedEvent::class);
		$event->method('getNode')->willReturn($file);

		$this->cache->method('get')->willReturn(null);

		$rule = $this->createRule('rule1', 'bob', '/Photos');
		$this->ruleMapper->method('findAll')->willReturn([$rule]);

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

	private function createRule(string $id, string $userId, string $path): Rule {
		$rule = new Rule();
		$ref = new \ReflectionProperty($rule, 'id');
		$ref->setValue($rule, $id);
		$rule->setUserId($userId);
		$rule->setDirectoryPath($path);
		return $rule;
	}
}
