<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UploadMonitor\Settings;

use OCA\UploadMonitor\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use Override;

class Personal implements ISettings {
	#[Override]
	public function getForm(): TemplateResponse {
		\OCP\Util::addScript(Application::APP_ID, 'upload_monitor-main');
		return new TemplateResponse(Application::APP_ID, 'settings/personal', [], '');
	}

	#[Override]
	public function getSection(): string {
		return 'workflow';
	}

	#[Override]
	public function getPriority(): int {
		return 10;
	}
}
