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
					v-for="folder in mergedFolderTree"
					:key="folder.id"
					:folder="folder"
					:selected-folder-id="selectedFolderId"
					:is-duplicate-name="checkDuplicateName"
					@select="selectedFolderId = $event"
					@create-folder="onCreatePendingFolder" />

				<button
					v-if="selectedFolderId === 'root' && !showRootNewFolder"
					class="move-secret__new-folder-btn"
					@click.stop="startRootNewFolder">
					<FolderPlusIcon :size="20" />
					<span>{{ t('doriath', 'New folder') }}</span>
				</button>

				<div
					v-if="selectedFolderId === 'root' && showRootNewFolder"
					class="move-secret__inline-input"
					@click.stop>
					<FolderPlusIcon :size="20" class="move-secret__inline-input-icon" />
					<NcInputField
						ref="rootNewFolderInput"
						v-model="rootNewFolderName"
						v-tooltip="isRootNewFolderDuplicate ? t('doriath', 'A folder with this name already exists in the same location') : ''"
						:label="t('doriath', 'Folder name')"
						:error="!!rootNewFolderName && isRootNewFolderDuplicate"
						@keyup.enter="confirmRootNewFolder"
						@keyup.escape="cancelRootNewFolder"
						@blur="handleRootNewFolderBlur" />
				</div>
			</div>

			<NcNoteCard v-if="isSameFolder" type="info">
				{{ t('doriath', 'This secret is already in the selected folder.') }}
			</NcNoteCard>

			<NcNoteCard v-if="pendingFolders.length > 0" type="info">
				{{ t('doriath', 'New folders marked with ✨ will only be created when you press Move.') }}
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
import { NcButton, NcDialog, NcInputField, NcNoteCard } from '@nextcloud/vue'
import FolderPlusIcon from 'vue-material-design-icons/FolderPlus.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import FolderPickerNode from '../components/FolderPickerNode.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'

export default {
	name: 'MoveSecretDialog',
	components: {
		NcButton,
		NcDialog,
		NcInputField,
		NcNoteCard,
		FolderPlusIcon,
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
			pendingFolders: [],
			pendingIdCounter: 0,
			showRootNewFolder: false,
			rootNewFolderName: '',
		}
	},
	computed: {
		folderStore() {
			return useFolderStore()
		},
		secretStore() {
			return useSecretStore()
		},
		mergedFolderTree() {
			const allFolders = [
				...this.folderStore.folders,
				...this.pendingFolders,
			]
			const map = {}
			allFolders.forEach(folder => {
				map[folder.id] = { ...folder, children: [] }
			})
			const roots = []
			allFolders.forEach(folder => {
				if (folder.parentId && map[folder.parentId]) {
					map[folder.parentId].children.push(map[folder.id])
				} else {
					roots.push(map[folder.id])
				}
			})
			return roots
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
		isRootNewFolderDuplicate() {
			if (!this.rootNewFolderName.trim()) return false
			return this.checkDuplicateName(this.rootNewFolderName, null)
		},
	},
	watch: {
		open(val) {
			if (val) {
				this.selectedFolderId = this.normalizedCurrentFolderId
				this.moving = false
				this.pendingFolders = []
				this.pendingIdCounter = 0
				this.showRootNewFolder = false
				this.rootNewFolderName = ''
				this.folderStore.fetchFolders()
			}
		},
	},
	methods: {
		checkDuplicateName(name, parentId) {
			const trimmed = name.trim().toLowerCase()
			if (this.folderStore.isDuplicateName(name, parentId)) return true
			return this.pendingFolders.some(
				f => (f.parentId ?? null) === parentId
					&& f.name.toLowerCase() === trimmed,
			)
		},
		addPendingFolder(name, parentId) {
			const id = `__pending__${this.pendingIdCounter++}`
			this.pendingFolders.push({
				id,
				name: name.trim(),
				parentId: parentId === 'root' ? null : parentId,
				isPending: true,
			})
			return id
		},
		onCreatePendingFolder({ name, parentId }) {
			const id = this.addPendingFolder(name, parentId)
			this.selectedFolderId = id
		},
		isPendingId(id) {
			return typeof id === 'string' && id.startsWith('__pending__')
		},
		async materializeAllPendingFolders() {
			// Sort so parents are created before children
			const sorted = [...this.pendingFolders].sort((a, b) => {
				const aDepth = this._pendingDepth(a)
				const bDepth = this._pendingDepth(b)
				return aDepth - bDepth
			})

			const idMapping = {}
			for (const pending of sorted) {
				const realParentId = pending.parentId
					? (idMapping[pending.parentId] ?? pending.parentId)
					: null
				const created = await this.folderStore.createFolder(pending.name, realParentId)
				idMapping[pending.id] = created.id
			}

			return idMapping
		},
		_pendingDepth(folder) {
			let depth = 0
			const pendingMap = {}
			this.pendingFolders.forEach(f => { pendingMap[f.id] = f })
			let current = folder
			while (current.parentId && pendingMap[current.parentId]) {
				depth++
				current = pendingMap[current.parentId]
			}
			return depth
		},
		async confirmMove() {
			if (!this.secretId) return
			this.moving = true
			try {
				let targetFolderId = this.selectedFolderId

				let idMapping = {}
				if (this.pendingFolders.length > 0) {
					idMapping = await this.materializeAllPendingFolders()
				}

				if (this.isPendingId(targetFolderId)) {
					targetFolderId = idMapping[targetFolderId]
				}

				await this.secretStore.updateSecret(this.secretId, { folderId: targetFolderId })
				this.$emit('update:open', false)
				this.$emit('moved')
			} catch (e) {
				console.error('Doriath: Failed to move secret', e)
				this.$emit('update:open', false)
			} finally {
				this.moving = false
			}
		},
		startRootNewFolder() {
			this.showRootNewFolder = true
			this.$nextTick(() => {
				this.$refs.rootNewFolderInput?.$el?.querySelector('input')?.focus()
			})
		},
		confirmRootNewFolder() {
			if (!this.rootNewFolderName.trim() || this.isRootNewFolderDuplicate) return
			const id = this.addPendingFolder(this.rootNewFolderName, null)
			this.rootNewFolderName = ''
			this.showRootNewFolder = false
			this.selectedFolderId = id
		},
		cancelRootNewFolder() {
			this.rootNewFolderName = ''
			this.showRootNewFolder = false
		},
		handleRootNewFolderBlur() {
			if (!this.rootNewFolderName.trim()) {
				this.showRootNewFolder = false
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

.move-secret__new-folder-btn {
	display: flex;
	align-items: center;
	gap: 8px;
	height: 36px;
	padding: 0 8px 0 32px;
	width: 100%;
	border: none;
	background: transparent;
	cursor: pointer;
	border-radius: var(--border-radius);
	color: var(--color-text-maxcontrast);
	font-style: italic;
	font-size: inherit;
}

.move-secret__new-folder-btn:hover {
	background: var(--color-background-hover);
}

.move-secret__inline-input {
	display: flex;
	align-items: center;
	height: 36px;
	padding: 0 8px 0 32px;
	gap: 8px;
}

.move-secret__inline-input-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}
</style>
