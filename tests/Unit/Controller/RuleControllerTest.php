<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UploadMonitor\Tests\Unit\Controller;

use OCA\UploadMonitor\Controller\RuleController;
use OCA\UploadMonitor\Db\Rule;
use OCA\UploadMonitor\Db\RuleMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class RuleControllerTest extends TestCase {
	private RuleMapper&MockObject $ruleMapper;
	private IRootFolder&MockObject $rootFolder;
	private Folder&MockObject $userFolder;
	private IL10N&MockObject $l;
	private ICache&MockObject $cache;
	private RuleController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->ruleMapper = $this->createMock(RuleMapper::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userFolder = $this->createMock(Folder::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(fn (string $s, $args = []) => vsprintf($s, $args));
		$this->cache = $this->createMock(ICache::class);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);

		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($this->userFolder);

		$this->controller = new RuleController(
			'upload_monitor',
			$this->createMock(IRequest::class),
			$this->ruleMapper,
			$this->rootFolder,
			$this->l,
			$cacheFactory,
			'alice',
		);
	}

	public function testIndex(): void {
		$rule = $this->createRule('rule1', '/Photos', 7);
		$this->ruleMapper->method('findAllByUser')->with('alice')->willReturn([$rule]);

		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(42);
		$this->userFolder->method('get')->with('/Photos')->willReturn($folder);

		$response = $this->controller->index();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertCount(1, $data);
		$this->assertSame('/Photos', $data[0]['directoryPath']);
		$this->assertTrue($data[0]['directoryExists']);
		$this->assertSame(42, $data[0]['fileId']);
	}

	public function testIndexMissingDirectory(): void {
		$rule = $this->createRule('rule1', '/Deleted', 7);
		$this->ruleMapper->method('findAllByUser')->with('alice')->willReturn([$rule]);
		$this->userFolder->method('get')->with('/Deleted')->willThrowException(new NotFoundException());

		$response = $this->controller->index();
		$data = $response->getData();
		$this->assertFalse($data[0]['directoryExists']);
		$this->assertNull($data[0]['fileId']);
	}

	public function testCreateSuccess(): void {
		$folder = $this->createMock(Folder::class);
		$this->userFolder->method('get')->with('/Photos')->willReturn($folder);
		$this->ruleMapper->method('existsByUserAndPath')->willReturn(false);
		$this->ruleMapper->method('findAllByUser')->willReturn([]);
		$this->ruleMapper->method('insert')->willReturnCallback(fn (Rule $r) => $r);
		$this->cache->expects($this->once())->method('remove');

		$response = $this->controller->create('/Photos', 7);
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateThresholdTooLow(): void {
		$response = $this->controller->create('/Photos', 0);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateThresholdTooHigh(): void {
		$response = $this->controller->create('/Photos', 9999);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreatePathTraversal(): void {
		$response = $this->controller->create('/Photos/../../../etc', 7);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateFolderNotFound(): void {
		$this->userFolder->method('get')->willThrowException(new NotFoundException());

		$response = $this->controller->create('/NonExistent', 7);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testCreateNotAFolder(): void {
		$file = $this->createMock(Node::class);
		$this->userFolder->method('get')->willReturn($file);

		$response = $this->controller->create('/somefile.txt', 7);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateDuplicate(): void {
		$folder = $this->createMock(Folder::class);
		$this->userFolder->method('get')->willReturn($folder);
		$this->ruleMapper->method('existsByUserAndPath')->willReturn(true);

		$response = $this->controller->create('/Photos', 7);
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}

	public function testCreateMaxRulesReached(): void {
		$folder = $this->createMock(Folder::class);
		$this->userFolder->method('get')->willReturn($folder);
		$this->ruleMapper->method('existsByUserAndPath')->willReturn(false);
		$rules = [];
		for ($i = 0; $i < 100; $i++) {
			$rules[] = $this->createRule("r$i", "/dir$i", 1);
		}
		$this->ruleMapper->method('findAllByUser')->willReturn($rules);

		$response = $this->controller->create('/Photos', 7);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateSuccess(): void {
		$rule = $this->createRule('rule1', '/Photos', 7);
		$this->ruleMapper->method('findByIdAndUser')->with('rule1', 'alice')->willReturn($rule);
		$this->ruleMapper->method('update')->willReturnCallback(fn (Rule $r) => $r);

		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(42);
		$this->userFolder->method('get')->willReturn($folder);
		$this->cache->expects($this->once())->method('remove');

		$response = $this->controller->update('rule1', 14);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateNotFound(): void {
		$this->ruleMapper->method('findByIdAndUser')->willThrowException(new DoesNotExistException(''));

		$response = $this->controller->update('nonexistent', 7);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testUpdateInvalidThreshold(): void {
		$response = $this->controller->update('rule1', 0);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testDestroySuccess(): void {
		$rule = $this->createRule('rule1', '/Photos', 7);
		$this->ruleMapper->method('findByIdAndUser')->with('rule1', 'alice')->willReturn($rule);
		$this->ruleMapper->expects($this->once())->method('delete')->with($rule);
		$this->cache->expects($this->once())->method('remove');

		$response = $this->controller->destroy('rule1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDestroyNotFound(): void {
		$this->ruleMapper->method('findByIdAndUser')->willThrowException(new DoesNotExistException(''));

		$response = $this->controller->destroy('nonexistent');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	private function createRule(string $id, string $path, int $threshold): Rule {
		$rule = new Rule();
		// SnowflakeAwareEntity overrides setId to throw, set via reflection
		$ref = new \ReflectionProperty($rule, 'id');
		$ref->setValue($rule, $id);
		$rule->setDirectoryPath($path);
		$rule->setThresholdDays($threshold);
		$rule->setUserId('alice');
		return $rule;
	}
}
