<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
 -->

<template>
	<tr
		:class="{ 'watch-rule--missing': !rule.directoryExists }">
		<td
			class="watch-rule__path"
			:title="rule.directoryPath">
			<a
				v-if="rule.directoryExists && rule.fileId"
				:href="folderLink"
				class="watch-rule__path-link">
				{{ rule.directoryPath }}
			</a>
			<span v-else>
				{{ rule.directoryPath }}
			</span>
		</td>
		<td class="watch-rule__threshold">
			<template v-if="!editing">
				{{ n('upload_monitor', '%n day', '%n days', rule.thresholdDays) }}
			</template>
			<NcTextField
				v-else
				v-model="editThresholdDays"
				type="text"
				:label="t('upload_monitor', 'Days')"
				@keyup.enter="onSave"
				@keyup.escape="onCancel" />
		</td>
		<td class="watch-rule__last-upload">
			<span
				v-if="!rule.directoryExists"
				class="watch-rule__path-warning">
				{{ t('upload_monitor', 'Folder not found') }}
			</span>
			<template v-else>
				{{ lastUploadLabel }}
			</template>
		</td>
		<td class="watch-rule__action">
			<template v-if="!editing">
				<NcButton
					variant="tertiary"
					:aria-label="t('upload_monitor', 'Edit threshold for {path}', { path: rule.directoryPath })"
					@click="onEdit">
					<template #icon>
						<Pencil :size="20" />
					</template>
				</NcButton>
				<NcButton
					variant="tertiary"
					:aria-label="t('upload_monitor', 'Delete watch rule for {path}', { path: rule.directoryPath })"
					@click="$emit('delete', rule.id)">
					<template #icon>
						<Delete :size="20" />
					</template>
				</NcButton>
			</template>
			<template v-else>
				<NcButton
					variant="tertiary"
					:aria-label="t('upload_monitor', 'Save')"
					@click="onSave">
					<template #icon>
						<Check :size="20" />
					</template>
				</NcButton>
				<NcButton
					variant="tertiary"
					:aria-label="t('upload_monitor', 'Cancel')"
					@click="onCancel">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</template>
		</td>
	</tr>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'WatchRule',

	components: {
		NcButton,
		NcTextField,
		Check,
		Close,
		Delete,
		Pencil,
	},

	props: {
		rule: {
			type: Object,
			required: true,
		},
	},

	emits: ['delete', 'update'],

	data() {
		return {
			editing: false,
			editThresholdDays: '',
		}
	},

	computed: {
		lastUploadLabel() {
			if (this.rule.lastUploadAt) {
				const date = new Date(this.rule.lastUploadAt * 1000)
				return date.toLocaleDateString()
			}
			return t('upload_monitor', 'Never')
		},

		folderLink() {
			if (this.rule.fileId) {
				return generateUrl('/f/{fileId}', { fileId: this.rule.fileId })
			}
			return null
		},
	},

	methods: {
		t,
		n,

		onEdit() {
			this.editing = true
			this.editThresholdDays = String(this.rule.thresholdDays)
		},

		onCancel() {
			this.editing = false
			this.editThresholdDays = ''
		},

		onSave() {
			const thresholdDays = parseInt(this.editThresholdDays, 10)
			if (isNaN(thresholdDays) || thresholdDays < 1) {
				showError(t('upload_monitor', 'Threshold must be at least 1 day'))
				return
			}
			this.$emit('update', this.rule.id, thresholdDays)
			this.editing = false
		},
	},
}
</script>

<style scoped lang="scss">
.watch-rule {
	&__path,
	&__threshold,
	&__last-upload,
	&__action {
		border-top: 1px solid var(--color-border);
		max-width: 200px;
		padding: 10px 10px 10px 13px;
	}

	&__path {
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__path-link {
		color: var(--color-main-text);
		text-decoration: none;

		&:hover {
			text-decoration: underline;
		}
	}

	&__path-warning {
		font-style: italic;
		white-space: nowrap;
	}

	&__action {
		padding-inline-start: 10px;
		display: flex;
		gap: 0;
	}

	&--missing {
		td {
			color: var(--color-error-text);
			background-color: var(--color-error-hover);
		}
	}
}
</style>
