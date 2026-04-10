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
					@click="selectedFolderId = 'root'"
					@contextmenu.prevent="onRootContextMenu">
					<HomeIcon :size="20" />
					<span>{{ t('doriath', 'Main Folder') }}</span>
				</button>

				<FolderPickerNode
					v-for="folder in mergedFolderTree"
					:key="folder.id"
					:folder="folder"
					:selected-folder-id="selectedFolderId"
					:is-duplicate-name="checkDuplicateName"
					:trigger-action="triggerAction"
					@select="selectedFolderId = $event"
					@create-folder="onCreatePendingFolder"
					@rename-folder="onRenamePendingFolder"
					@revert-rename="onRevertRename"
					@remove-folder="onRemovePendingFolder"
					@context-menu="onFolderContextMenu" />

				<div
					v-if="showRootNewFolder"
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

			<p class="move-secret__hint">
				{{ t('doriath', 'Right-click a folder for more options.') }}
			</p>

			<CnContextMenu
				:open.sync="contextMenuOpen"
				@close="closeContextMenu">
				<NcActionButton close-after-click @click="onContextNewSubfolder">
					<template #icon>
						<FolderPlusIcon :size="20" />
					</template>
					{{ t('doriath', 'New subfolder') }}
				</NcActionButton>
				<NcActionButton
					v-if="contextMenuFolderId && contextMenuFolderId !== 'root'"
					close-after-click
					@click="onContextRename">
					<template #icon>
						<PencilIcon :size="20" />
					</template>
					{{ t('doriath', 'Rename') }}
				</NcActionButton>
				<NcActionButton
					v-if="contextMenuFolder && contextMenuFolder.isRenamed"
					close-after-click
					@click="onContextUndoRename">
					<template #icon>
						<UndoIcon :size="20" />
					</template>
					{{ t('doriath', 'Undo rename') }}
				</NcActionButton>
				<NcActionButton
					v-if="contextMenuFolder && contextMenuFolder.isPending"
					close-after-click
					@click="onContextRemove">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
					{{ t('doriath', 'Remove') }}
				</NcActionButton>
			</CnContextMenu>

			<NcNoteCard v-if="isSameFolder" type="info">
				{{ t('doriath', 'This secret is already in the selected folder.') }}
			</NcNoteCard>

			<NcNoteCard v-if="hasPendingChanges" type="info">
				{{ t('doriath', 'Changes marked with ✨ or ✏️ will only be applied when you press Move.') }}
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
import { NcActionButton, NcButton, NcDialog, NcInputField, NcNoteCard } from '@nextcloud/vue'
import { CnContextMenu, useContextMenu } from '@conduction/nextcloud-vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import FolderPlusIcon from 'vue-material-design-icons/FolderPlus.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import UndoIcon from 'vue-material-design-icons/Undo.vue'
import FolderPickerNode from '../components/FolderPickerNode.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'

export default {
	name: 'MoveSecretDialog',
	components: {
		NcActionButton,
		NcButton,
		NcDialog,
		NcInputField,
		NcNoteCard,
		CnContextMenu,
		CloseIcon,
		FolderPlusIcon,
		HomeIcon,
		PencilIcon,
		UndoIcon,
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
	setup() {
		const {
			isOpen: contextMenuOpen,
			targetItem: contextMenuFolderId,
			open: openContextMenu,
			close: closeContextMenu,
		} = useContextMenu()

		return {
			contextMenuOpen,
			contextMenuFolderId,
			openContextMenu,
			closeContextMenu,
		}
	},
	data() {
		return {
			selectedFolderId: null,
			moving: false,
			pendingFolders: [],
			pendingIdCounter: 0,
			pendingRenames: {},
			showRootNewFolder: false,
			rootNewFolderName: '',
			triggerAction: null,
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
				const displayName = this.pendingRenames[folder.id] ?? folder.name
				map[folder.id] = {
					...folder,
					name: displayName,
					isRenamed: folder.id in this.pendingRenames,
					children: [],
				}
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
		contextMenuFolder() {
			if (!this.contextMenuFolderId || this.contextMenuFolderId === 'root') return null
			const findFolder = (folders) => {
				for (const f of folders) {
					if (f.id === this.contextMenuFolderId) return f
					if (f.children) {
						const found = findFolder(f.children)
						if (found) return found
					}
				}
				return null
			}
			return findFolder(this.mergedFolderTree)
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
		hasPendingChanges() {
			return this.pendingFolders.length > 0 || Object.keys(this.pendingRenames).length > 0
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
				this.pendingRenames = {}
				this.showRootNewFolder = false
				this.rootNewFolderName = ''
				this.closeContextMenu()
				this.triggerAction = null
				this.folderStore.fetchFolders()
			}
		},
	},
	methods: {
		onFolderContextMenu({ folderId, event }) {
			this.openContextMenu({ item: folderId, event })
		},
		onRootContextMenu(event) {
			this.openContextMenu({ item: 'root', event })
		},
		onContextNewSubfolder() {
			const folderId = this.contextMenuFolderId
			this.closeContextMenu()
			this.selectedFolderId = folderId
			this.$nextTick(() => {
				if (folderId === 'root') {
					this.startRootNewFolder()
				} else {
					this.triggerAction = { folderId, action: 'new-subfolder' }
					this.$nextTick(() => { this.triggerAction = null })
				}
			})
		},
		onContextRename() {
			const folderId = this.contextMenuFolderId
			this.closeContextMenu()
			this.$nextTick(() => {
				this.triggerAction = { folderId, action: 'rename' }
				this.$nextTick(() => { this.triggerAction = null })
			})
		},
		onContextUndoRename() {
			const folderId = this.contextMenuFolderId
			this.onRevertRename(folderId)
			this.closeContextMenu()
		},
		onContextRemove() {
			const folderId = this.contextMenuFolderId
			this.onRemovePendingFolder(folderId)
			this.closeContextMenu()
		},
		checkDuplicateName(name, parentId, excludeId = null) {
			const trimmed = name.trim().toLowerCase()
			const storeHasDuplicate = this.folderStore.folders.some(f => {
				if (f.id === excludeId) return false
				if ((f.parentId ?? null) !== parentId) return false
				const effectiveName = this.pendingRenames[f.id] ?? f.name
				return effectiveName.trim().toLowerCase() === trimmed
			})
			if (storeHasDuplicate) return true
			return this.pendingFolders.some(
				f => f.id !== excludeId
					&& (f.parentId ?? null) === parentId
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
		onRenamePendingFolder({ folderId, newName }) {
			if (this.isPendingId(folderId)) {
				const pf = this.pendingFolders.find(f => f.id === folderId)
				if (pf) pf.name = newName.trim()
			} else {
				const storeFolder = this.folderStore.folders.find(f => f.id === folderId)
				if (storeFolder && storeFolder.name === newName.trim()) {
					// Renamed back to original — remove from pending renames
					const copy = { ...this.pendingRenames }
					delete copy[folderId]
					this.pendingRenames = copy
				} else {
					this.pendingRenames = { ...this.pendingRenames, [folderId]: newName.trim() }
				}
			}
		},
		onRevertRename(folderId) {
			const copy = { ...this.pendingRenames }
			delete copy[folderId]
			this.pendingRenames = copy
		},
		onRemovePendingFolder(folderId) {
			// Collect all IDs to remove (the folder + any pending descendants)
			const idsToRemove = new Set()
			const collect = (id) => {
				idsToRemove.add(id)
				this.pendingFolders
					.filter(f => f.parentId === id)
					.forEach(f => collect(f.id))
			}
			collect(folderId)
			this.pendingFolders = this.pendingFolders.filter(f => !idsToRemove.has(f.id))
			if (idsToRemove.has(this.selectedFolderId)) {
				this.selectedFolderId = 'root'
			}
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
		async materializeAllPendingRenames() {
			for (const [folderId, newName] of Object.entries(this.pendingRenames)) {
				if (this.isPendingId(folderId)) continue
				const folder = this.folderStore.folders.find(f => f.id === folderId)
				if (!folder) continue
				await this.folderStore.updateFolder(folderId, newName, folder.parentId ?? null)
			}
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

				if (Object.keys(this.pendingRenames).length > 0) {
					await this.materializeAllPendingRenames()
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

.move-secret__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
	margin: 4px 0 0;
	font-style: italic;
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
