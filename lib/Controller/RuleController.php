<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UploadMonitor\Controller;

use OCA\UploadMonitor\AppInfo\Application;
use OCA\UploadMonitor\Db\Rule;
use OCA\UploadMonitor\Db\RuleMapper;
use OCA\UploadMonitor\Listener\FileCreatedListener;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;

class RuleController extends OCSController {
	private const MAX_THRESHOLD_DAYS = 3650;
	private const MAX_RULES_PER_USER = 100;
	private const MAX_PATH_LENGTH = 4000;

	private ICache $cache;
	private Folder $userFolder;

	public function __construct(
		string $appName,
		IRequest $request,
		private RuleMapper $ruleMapper,
		IRootFolder $rootFolder,
		private IL10N $l,
		ICacheFactory $cacheFactory,
		private string $userId,
	) {
		parent::__construct($appName, $request);
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID);
		$this->userFolder = $rootFolder->getUserFolder($this->userId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/rules')]
	public function index(): DataResponse {
		$rules = $this->ruleMapper->findAllByUser($this->userId);
		return new DataResponse(array_map(fn (Rule $r) => $this->serializeRule($r), $rules));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/rules')]
	public function create(string $directoryPath, int $thresholdDays): DataResponse {
		$validationError = $this->validateThreshold($thresholdDays);
		if ($validationError !== null) {
			return $validationError;
		}

		$directoryPath = '/' . trim($directoryPath, '/');

		if (strlen($directoryPath) > self::MAX_PATH_LENGTH) {
			return new DataResponse(['message' => $this->l->t('Folder path is too long')], Http::STATUS_BAD_REQUEST);
		}

		if (str_contains($directoryPath, '/../') || str_ends_with($directoryPath, '/..')) {
			return new DataResponse(['message' => $this->l->t('Invalid folder path')], Http::STATUS_BAD_REQUEST);
		}

		try {
			$node = $this->userFolder->get($directoryPath);
			if (!($node instanceof Folder)) {
				return new DataResponse(['message' => $this->l->t('The selected path is not a folder')], Http::STATUS_BAD_REQUEST);
			}
		} catch (NotFoundException) {
			return new DataResponse(['message' => $this->l->t('Folder not found')], Http::STATUS_NOT_FOUND);
		}

		if ($this->ruleMapper->existsByUserAndPath($this->userId, $directoryPath)) {
			return new DataResponse(['message' => $this->l->t('A watch rule for this folder already exists')], Http::STATUS_CONFLICT);
		}

		$existingCount = count($this->ruleMapper->findAllByUser($this->userId));
		if ($existingCount >= self::MAX_RULES_PER_USER) {
			return new DataResponse(['message' => $this->l->t('Maximum number of watch rules reached')], Http::STATUS_BAD_REQUEST);
		}

		$rule = new Rule();
		$rule->generateId();
		$rule->setUserId($this->userId);
		$rule->setDirectoryPath($directoryPath);
		$rule->setThresholdDays($thresholdDays);

		$rule = $this->ruleMapper->insert($rule);
		$this->cache->remove(FileCreatedListener::CACHE_KEY);
		return new DataResponse($this->serializeRule($rule), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/rules/{id}')]
	public function update(string $id, int $thresholdDays): DataResponse {
		$validationError = $this->validateThreshold($thresholdDays);
		if ($validationError !== null) {
			return $validationError;
		}

		try {
			$rule = $this->ruleMapper->findByIdAndUser($id, $this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l->t('Rule not found')], Http::STATUS_NOT_FOUND);
		}

		$rule->setThresholdDays($thresholdDays);
		$rule = $this->ruleMapper->update($rule);
		$this->cache->remove(FileCreatedListener::CACHE_KEY);
		return new DataResponse($this->serializeRule($rule));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/rules/{id}')]
	public function destroy(string $id): DataResponse {
		try {
			$rule = $this->ruleMapper->findByIdAndUser($id, $this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l->t('Rule not found')], Http::STATUS_NOT_FOUND);
		}

		$this->ruleMapper->delete($rule);
		$this->cache->remove(FileCreatedListener::CACHE_KEY);
		return new DataResponse(null, Http::STATUS_OK);
	}

	private function validateThreshold(int $thresholdDays): ?DataResponse {
		if ($thresholdDays < 1 || $thresholdDays > self::MAX_THRESHOLD_DAYS) {
			return new DataResponse(
				['message' => $this->l->t('Threshold must be between 1 and %s days', [self::MAX_THRESHOLD_DAYS])],
				Http::STATUS_BAD_REQUEST,
			);
		}
		return null;
	}

	private function serializeRule(Rule $rule): array {
		$data = $rule->jsonSerialize();
		try {
			$node = $this->userFolder->get($rule->getDirectoryPath());
			$data['directoryExists'] = true;
			$data['fileId'] = $node->getId();
		} catch (NotFoundException) {
			$data['directoryExists'] = false;
			$data['fileId'] = null;
		}
		return $data;
	}
}
