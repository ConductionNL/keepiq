<template>
	<NcDialog
		:open="open"
		:name="t('doriath', 'Move secret')"
		size="small"
		@update:open="$emit('update:open', $event)">
		<div class="move-secret">
			<p class="move-secret__subtitle">
				{{ t('doriath', 'Choose a destination folder for "{name}".', { name: secretName }) }}
			</p>

			<div class="move-secret__tree">
				<button
					class="move-secret__root-row"
					:class="{ 'move-secret__root-row--selected': selectedFolderId === 'root' }"
					@click="selectedFolderId = 'root'">
					<HomeIcon :size="20" />
					<span>{{ t('doriath', '/ (Root)') }}</span>
				</button>

				<FolderPickerNode
					v-for="folder in folderTree"
					:key="folder.id"
					:folder="folder"
					:selected-folder-id="selectedFolderId"
					@select="selectedFolderId = $event" />
			</div>

			<NcNoteCard v-if="isSameFolder" type="info">
				{{ t('doriath', 'This secret is already in the selected folder.') }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="!canMove" @click="confirmMove">
				{{ moving ? t('doriath', 'Moving...') : t('doriath', 'Move') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import FolderPickerNode from '../components/FolderPickerNode.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'

export default {
	name: 'MoveSecretDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		HomeIcon,
		FolderPickerNode,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		secretId: {
			type: String,
			default: null,
		},
		secretName: {
			type: String,
			default: '',
		},
		currentFolderId: {
			type: String,
			default: null,
		},
	},
	emits: ['update:open', 'moved'],
	data() {
		return {
			selectedFolderId: null,
			moving: false,
		}
	},
	computed: {
		folderStore() {
			return useFolderStore()
		},
		secretStore() {
			return useSecretStore()
		},
		folderTree() {
			return this.folderStore.folderTree
		},
		normalizedCurrentFolderId() {
			return this.currentFolderId ?? 'root'
		},
		isSameFolder() {
			return this.selectedFolderId === this.normalizedCurrentFolderId
		},
		canMove() {
			return !this.isSameFolder && !this.moving
		},
	},
	watch: {
		open(val) {
			if (val) {
				this.selectedFolderId = this.normalizedCurrentFolderId
				this.moving = false
				this.folderStore.fetchFolders()
			}
		},
	},
	methods: {
		async confirmMove() {
			if (!this.secretId) return
			this.moving = true
			try {
				await this.secretStore.updateSecret(this.secretId, { folderId: this.selectedFolderId })
				this.$emit('update:open', false)
				this.$emit('moved')
			} catch (e) {
				console.error('Doriath: Failed to move secret', e)
				this.$emit('update:open', false)
			} finally {
				this.moving = false
			}
		},
	},
}
</script>

<style scoped>
.move-secret {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 0 0 4px;
}

.move-secret__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	margin: 0 0 8px;
}

.move-secret__tree {
	max-height: 320px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.move-secret__root-row {
	display: flex;
	align-items: center;
	gap: 8px;
	height: 36px;
	padding: 0 8px;
	width: 100%;
	border: none;
	background: transparent;
	cursor: pointer;
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	font-size: inherit;
	/* reset margin so it does not create a scrollable field */
	margin: 0 !important;
}

.move-secret__root-row:hover {
	background: var(--color-background-hover);
}

.move-secret__root-row--selected {
	background: var(--color-primary-element-light);
	font-weight: 600;
}

.move-secret__root-row--selected:hover {
	background: var(--color-primary-element-light);
}
</style>
