<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UploadMonitor\Db;

use JsonSerializable;
use OCP\AppFramework\Db\SnowflakeAwareEntity;
use OCP\DB\Types;
use Override;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getDirectoryPath()
 * @method string getDirectoryPathHash()
 * @method void setDirectoryPathHash(string $directoryPathHash)
 * @method int getThresholdDays()
 * @method void setThresholdDays(int $thresholdDays)
 * @method int|null getLastUploadAt()
 * @method void setLastUploadAt(?int $lastUploadAt)
 * @method int|null getLastNotifiedAt()
 * @method void setLastNotifiedAt(?int $lastNotifiedAt)
 */
class Rule extends SnowflakeAwareEntity implements JsonSerializable {
	protected ?string $userId = null;
	protected ?string $directoryPath = null;
	protected ?string $directoryPathHash = null;
	protected ?int $thresholdDays = null;
	protected ?int $lastUploadAt = null;
	protected ?int $lastNotifiedAt = null;

	public function __construct() {
		$this->addType('id', Types::STRING);
		$this->addType('userId', Types::STRING);
		$this->addType('directoryPath', Types::STRING);
		$this->addType('directoryPathHash', Types::STRING);
		$this->addType('thresholdDays', Types::INTEGER);
		$this->addType('lastUploadAt', Types::INTEGER);
		$this->addType('lastNotifiedAt', Types::INTEGER);
	}

	public function setDirectoryPath(string $directoryPath): void {
		$this->setter('directoryPath', [$directoryPath]);
		$this->setDirectoryPathHash(md5($directoryPath));
	}

	#[Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'directoryPath' => $this->getDirectoryPath(),
			'thresholdDays' => $this->getThresholdDays(),
			'lastUploadAt' => $this->getLastUploadAt(),
		];
	}
}
