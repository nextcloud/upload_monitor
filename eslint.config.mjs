/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { nextcloudPlugin, recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,

	{
		name: 'notifications/disabled',
		plugins: {
			'@nextcloud': nextcloudPlugin,
		},
		rules: {
			'no-console': 'off',
		},
	},
]
