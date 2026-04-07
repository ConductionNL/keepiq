<template>
	<NcDialog
		:open="open"
		:name="t('doriath', 'Rename folder')"
		@update:open="$emit('update:open', $event)">
		<NcInputField
			v-model="newName"
			:label="t('doriath', 'New name')"
			:error="!!newName && isDuplicate"
			:helper-text="isDuplicate ? t('doriath', 'A folder with this name already exists in the same location') : ''" />
		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="!newName || isDuplicate" @click="submit">
				{{ t('doriath', 'Rename') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcInputField } from '@nextcloud/vue'
import { useFolderStore } from '../store/modules/folder.js'

export default {
	name: 'RenameFolderDialog',
	components: {
		NcButton,
		NcDialog,
		NcInputField,
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
	emits: ['update:open', 'renamed'],
	data() {
		return {
			newName: '',
		}
	},
	computed: {
		folderStore() {
			return useFolderStore()
		},
		isDuplicate() {
			if (!this.folder || !this.newName.trim()) return false
			return this.folderStore.isDuplicateName(
				this.newName,
				this.folder.parentId ?? null,
				this.folder.id,
			)
		},
	},
	watch: {
		open(val) {
			if (val && this.folder) {
				this.newName = this.folder.name
			}
		},
	},
	methods: {
		async submit() {
			if (!this.newName.trim() || !this.folder) return
			try {
				await this.folderStore.updateFolder(
					this.folder.id,
					this.newName.trim(),
					this.folder.parentId ?? null,
				)
				await this.folderStore.fetchFolders()
				this.$emit('renamed')
			} catch {
				// Silently handled.
			}
			this.$emit('update:open', false)
		},
	},
}
</script>
