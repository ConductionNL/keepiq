<template>
	<NcDialog
		:open="open"
		:name="t('doriath', 'Delete folder')"
		@update:open="$emit('update:open', $event)">
		<NcLoadingIcon v-if="loading" />

		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<template v-else>
			<p v-if="isEmpty">
				{{ t('doriath', 'Are you sure you want to delete "{name}"?', { name: folder?.name }) }}
			</p>

			<NcNoteCard v-else-if="directSecretCount > 0 && subfolders.length > 0" type="warning">
				{{ t('doriath', 'This folder cannot be deleted because it still contains {secrets} and {folders}.', { secrets: n('doriath', '{count} secret', '{count} secrets', directSecretCount, { count: directSecretCount }), folders: n('doriath', '{count} subfolder', '{count} subfolders', subfolders.length, { count: subfolders.length }) }) }}
			</NcNoteCard>

			<NcNoteCard v-else-if="directSecretCount > 0" type="warning">
				{{ n('doriath', 'This folder cannot be deleted because it still contains {count} secret.', 'This folder cannot be deleted because it still contains {count} secrets.', directSecretCount, { count: directSecretCount }) }}
			</NcNoteCard>

			<NcNoteCard v-else-if="subfolders.length > 0" type="warning">
				{{ n('doriath', 'This folder cannot be deleted because it still contains {count} subfolder.', 'This folder cannot be deleted because it still contains {count} subfolders.', subfolders.length, { count: subfolders.length }) }}
			</NcNoteCard>

			<NcNoteCard v-if="deleteError" type="error">
				{{ deleteError }}
			</NcNoteCard>
		</template>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton
				type="error"
				:disabled="!canDelete"
				@click="confirmDelete">
				{{ deleting ? t('doriath', 'Deleting...') : t('doriath', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { useFolderStore } from '../store/modules/folder.js'

export default {
	name: 'RemoveFolderDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		folder: {
			type: Object,
			default: null,
		},
	},
	emits: ['update:open', 'deleted'],
	data() {
		return {
			loading: false,
			loadError: null,
			deleting: false,
			deleteError: null,
			directSecretCount: 0,
			subfolders: [],
		}
	},
	computed: {
		folderStore() {
			return useFolderStore()
		},
		isEmpty() {
			return this.directSecretCount === 0 && this.subfolders.length === 0
		},
		canDelete() {
			return !this.loading && !this.loadError && !this.deleting && this.isEmpty
		},
	},
	watch: {
		open(val) {
			if (val) {
				this.reset()
				this.loadChildren()
			}
		},
	},
	methods: {
		reset() {
			this.loading = false
			this.loadError = null
			this.deleting = false
			this.deleteError = null
			this.directSecretCount = 0
			this.subfolders = []
		},
		async loadChildren() {
			if (!this.folder?.id) return
			this.loading = true
			this.loadError = null
			try {
				const result = await this.folderStore.fetchChildren(this.folder.id)
				this.directSecretCount = result.directSecretCount ?? 0
				this.subfolders = result.subfolders ?? []
			} catch (e) {
				this.loadError = e.message || t('doriath', 'Failed to load folder details')
			} finally {
				this.loading = false
			}
		},
		async confirmDelete() {
			if (!this.folder?.id || !this.isEmpty) return
			this.deleting = true
			this.deleteError = null
			try {
				await this.folderStore.deleteFolder(this.folder.id)
				this.$emit('update:open', false)
				this.$emit('deleted')
			} catch (e) {
				this.deleteError = e.message || t('doriath', 'Failed to delete folder')
			} finally {
				this.deleting = false
			}
		},
	},
}
</script>
