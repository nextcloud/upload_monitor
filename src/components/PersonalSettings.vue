<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
 -->

<template>
	<NcSettingsSection
		:name="t('upload_monitor', 'Upload Monitor')"
		:description="t('upload_monitor', 'Monitor folders for file upload inactivity and receive notifications.')">
		<table class="watch-rules-table">
			<thead>
				<tr>
					<th class="watch-heading__path">
						{{ t('upload_monitor', 'Folder') }}
					</th>
					<th class="watch-heading__threshold">
						{{ t('upload_monitor', 'Threshold') }}
					</th>
					<th class="watch-heading__last-upload">
						{{ t('upload_monitor', 'Last upload') }}
					</th>
					<th class="watch-heading__action" />
				</tr>
			</thead>
			<tbody>
				<tr v-if="loading">
					<td colspan="4" class="watch-rule__loading">
						<NcLoadingIcon :size="32" />
					</td>
				</tr>

				<WatchRule
					v-for="rule in rules"
					:key="rule.id"
					:rule="rule"
					@delete="deleteRule"
					@update="updateRule" />

				<tr>
					<td class="watch-rule__path">
						<NcButton
							variant="secondary"
							:disabled="adding"
							@click="showFolderPicker">
							<template #icon>
								<FolderOpenOutline :size="20" />
							</template>
							{{ newDirectoryPath || t('upload_monitor', 'Select folder') }}
						</NcButton>
					</td>
					<td class="watch-rule__threshold">
						<NcTextField
							v-model="newThresholdDays"
							:disabled="adding"
							type="text"
							:label="t('upload_monitor', 'Days')"
							placeholder="7" />
					</td>
					<td class="watch-rule__last-upload" />
					<td class="watch-rule__action">
						<div class="watch-rule__action--button-aligner">
							<NcButton
								variant="primary"
								:disabled="adding || !newDirectoryPath"
								:aria-label="t('upload_monitor', 'Add watch rule')"
								@click="addRule">
								<template #icon>
									<Plus :size="20" />
								</template>
								{{ t('upload_monitor', 'Create') }}
							</NcButton>
						</div>
					</td>
				</tr>
			</tbody>
		</table>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { getFilePickerBuilder, showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FolderOpenOutline from 'vue-material-design-icons/FolderOpenOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import WatchRule from './WatchRule.vue'

export default {
	name: 'PersonalSettings',

	components: {
		NcButton,
		NcLoadingIcon,
		NcSettingsSection,
		NcTextField,
		FolderOpenOutline,
		Plus,
		WatchRule,
	},

	data() {
		return {
			rules: [],
			loading: true,
			adding: false,
			newDirectoryPath: '',
			newThresholdDays: '7',
		}
	},

	async mounted() {
		try {
			const response = await axios.get(generateOcsUrl('/apps/upload_monitor/api/v1/rules'))
			this.rules = response.data.ocs.data
		} catch (error) {
			showError(t('upload_monitor', 'Failed to load watch rules'))
			console.error(error)
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		async showFolderPicker() {
			const filePicker = getFilePickerBuilder(t('upload_monitor', 'Select folder to watch'))
				.setMultiSelect(false)
				.allowDirectories(true)
				.addMimeTypeFilter('httpd/unix-directory')
				.addButton({
					label: t('upload_monitor', 'Choose'),
					callback: (nodes) => {
						const path = nodes[0]?.path
						if (path) {
							this.newDirectoryPath = path
						}
					},
					variant: 'primary',
				})
				.build()
			await filePicker.pickNodes()
		},

		getErrorMessage(error, fallback) {
			return error?.response?.data?.ocs?.data?.message
				|| error?.response?.data?.ocs?.meta?.message
				|| fallback
		},

		async addRule() {
			const thresholdDays = parseInt(this.newThresholdDays, 10)
			if (!this.newDirectoryPath || isNaN(thresholdDays) || thresholdDays < 1) {
				showError(t('upload_monitor', 'Please provide a valid folder and threshold'))
				return
			}

			this.adding = true
			try {
				const response = await axios.post(
					generateOcsUrl('/apps/upload_monitor/api/v1/rules'),
					{
						directoryPath: this.newDirectoryPath,
						thresholdDays,
					},
				)
				this.rules.push(response.data.ocs.data)
				this.newDirectoryPath = ''
				this.newThresholdDays = '7'
			} catch (error) {
				showError(this.getErrorMessage(error, t('upload_monitor', 'Failed to add watch rule')))
				console.error(error)
			} finally {
				this.adding = false
			}
		},

		async updateRule(id, thresholdDays) {
			try {
				const response = await axios.put(
					generateOcsUrl('/apps/upload_monitor/api/v1/rules/{id}', { id }),
					{ thresholdDays },
				)
				const idx = this.rules.findIndex((r) => r.id === id)
				if (idx !== -1) {
					this.rules[idx] = response.data.ocs.data
				}
			} catch (error) {
				showError(this.getErrorMessage(error, t('upload_monitor', 'Failed to update watch rule')))
				console.error(error)
			}
		},

		async deleteRule(id) {
			try {
				await axios.delete(generateOcsUrl('/apps/upload_monitor/api/v1/rules/{id}', { id }))
				this.rules = this.rules.filter((r) => r.id !== id)
			} catch (error) {
				showError(this.getErrorMessage(error, t('upload_monitor', 'Failed to remove watch rule')))
				console.error(error)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.watch-rules-table {
	width: 100%;
	min-height: 50px;
	padding-top: 5px;

	.watch-heading,
	.watch-rule {
		&__path,
		&__threshold,
		&__last-upload,
		&__action {
			color: var(--color-text-maxcontrast);
			padding: 10px 10px 10px 0;
			vertical-align: bottom;
		}

		&__action {
			padding-inline-start: 10px;
			flex-direction: row-reverse;
			display: flex;

			&--button-aligner {
				margin-top: 6px;
			}
		}
	}

	.watch-heading {
		&__path,
		&__threshold,
		&__last-upload,
		&__action {
			padding-inline-start: 13px;
		}
	}

	.watch-rule__loading {
		text-align: center;
		padding: 20px;
	}
}
</style>
