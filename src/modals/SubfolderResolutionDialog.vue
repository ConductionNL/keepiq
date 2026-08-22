<template>
	<NcDialog
		:name="t('keepiq', 'Delete folder')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="resolution-dialog">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<p class="resolution-dialog__intro">
				{{
					n(
						'keepiq',
						'This folder contains %n secret directly.',
						'This folder contains %n secrets directly.',
						children.directSecretCount,
					)
				}}
			</p>

			<NcSelect
				v-model="directSecrets"
				:options="directOptions"
				:reduce="(opt) => opt.value"
				:inputLabel="t('keepiq', 'Secrets in this folder')"
				:clearable="false" />

			<div
				v-if="children.subfolders && children.subfolders.length"
				class="resolution-dialog__subfolders">
				<p>{{ t('keepiq', 'Choose what happens to each subfolder:') }}</p>
				<div
					v-for="sub in children.subfolders"
					:key="sub.id"
					class="resolution-dialog__row">
					<span class="resolution-dialog__name">{{ sub.name }}</span>
					<span class="resolution-dialog__count">
						{{ n('keepiq', '%n secret', '%n secrets', sub.secretCount) }}
					</span>
					<NcSelect
						v-model="plan[sub.id]"
						:options="subfolderOptions"
						:reduce="(opt) => opt.value"
						:inputLabel="t('keepiq', 'Action')"
						:clearable="false" />
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="error" :disabled="loading" @click="submit">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Delete v-else :size="20" />
				</template>
				{{ t('keepiq', 'Delete folder') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { useFolderStore } from '../store/modules/folder.js'

/**
 * A dialog letting the user choose, per direct subfolder, whether to delete,
 * move, or keep it (and the same for the folder's direct secrets) before a
 * non-empty folder is deleted. Emits `deleted` on success.
 */
export default {
	name: 'SubfolderResolutionDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		Delete,
	},

	props: {
		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			default: false,
		},

		/** The folder being deleted. */
		folderId: {
			type: String,
			required: true,
		},

		/** The children payload from GET /folders/{id}/children. */
		children: {
			type: Object,
			default: () => ({ directSecretCount: 0, subfolders: [] }),
		},
	},

	data() {
		return {
			directSecrets: 'move',
			plan: {},
			loading: false,
			error: '',
		}
	},

	computed: {
		directOptions() {
			return [
				{ value: 'move', label: t('keepiq', 'Move to parent folder') },
				{ value: 'delete', label: t('keepiq', 'Delete them') },
			]
		},

		subfolderOptions() {
			return [
				{ value: 'keep', label: t('keepiq', 'Keep (move to parent)') },
				{ value: 'move', label: t('keepiq', 'Move its secrets to parent') },
				{ value: 'delete', label: t('keepiq', 'Delete it entirely') },
			]
		},
	},

	watch: {
		children: {
			immediate: true,
			handler(value) {
				const next = {}
				;(value.subfolders || []).forEach((sub) => {
					next[sub.id] = this.plan[sub.id] || 'keep'
				})
				this.plan = next
			},
		},
	},

	methods: {
		t,
		n,

		/**
		 * Forward the open-state change to the parent.
		 *
		 * @param {boolean} value The new open state.
		 */
		onUpdateOpen(value) {
			this.$emit('update:open', value)
		},

		/**
		 * Submit the resolution plan and delete the folder.
		 *
		 * @return {Promise<void>}
		 */
		async submit() {
			this.loading = true
			this.error = ''
			try {
				const folderStore = useFolderStore()
				await folderStore.deleteFolder(this.folderId, {
					directSecrets: this.directSecrets,
					subfolders: { ...this.plan },
				})
				this.$emit('deleted', this.folderId)
				this.onUpdateOpen(false)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t('keepiq', 'Failed to delete folder')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.resolution-dialog__row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 6px 0;
}

.resolution-dialog__name {
	font-weight: bold;
	flex: 1 1 auto;
}

.resolution-dialog__count {
	color: var(--color-text-maxcontrast);
}
</style>
