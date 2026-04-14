<template>
	<div class="secret-list">
		<CnIndexPage
			ref="indexPage"
			:title="pageTitle"
			:show-title="true"
			:objects="filteredSecrets"
			:columns="tableColumns"
			:pagination="paginationData"
			:refreshing="secretStore.loading"
			:selectable="false"
			:show-form-dialog="false"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="true"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			:show-view-toggle="false"
			:actions="rowActions"
			:add-label="t('doriath', 'New secret')"
			:sort-key="secretStore.sort"
			:sort-order="secretStore.direction?.toLowerCase() ?? null"
			:empty-text="emptyText"
			row-key="id"
			mass-action-name-field="name"
			@add="editSecret = null; showSecretDialog = true"
			@row-click="onRowClick"
			@sort="onSort"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@delete="onDeleteConfirm"
			@refresh="loadSecrets">
			<template #below-header>
				<nav v-if="breadcrumbs.length" class="secret-list__breadcrumbs">
					<template v-for="(crumb, i) in breadcrumbs">
						<router-link
							v-if="i < breadcrumbs.length - 1"
							:key="'link-' + (crumb.id ?? 'root')"
							:to="crumb.to"
							class="secret-list__breadcrumb">
							<HomeIcon v-if="i === 0" :size="16" />
							<span v-else>{{ crumb.name }}</span>
						</router-link>
						<span
							v-else
							:key="'cur-' + (crumb.id ?? 'root')"
							class="secret-list__breadcrumb secret-list__breadcrumb--current">
							{{ crumb.name }}
						</span>
						<ChevronRightIcon
							v-if="i < breadcrumbs.length - 1"
							:key="'sep-' + i"
							:size="16"
							class="secret-list__breadcrumb-sep" />
					</template>
				</nav>
			</template>

			<template #header-actions>
				<NcInputField
					v-model="searchTerm"
					:label="t('doriath', 'Search secrets')"
					type="search"
					class="secret-list__search" />
			</template>

			<template #empty>
				<NcEmptyContent
					:name="t('doriath', 'No secrets found')"
					:description="searchTerm ? t('doriath', 'Try a different search term') : t('doriath', 'Add your first secret using the button above')">
					<template #icon>
						<KeyVariantIcon :size="64" />
					</template>
				</NcEmptyContent>
			</template>

			<template #column-name="{ row }">
				<div class="secret-list__name-cell">
					<img
						v-if="getFavicon(row.url)"
						:src="getFavicon(row.url)"
						:alt="row.name"
						class="secret-list__favicon"
						@error="$event.target.style.display = 'none'">
					<KeyVariantIcon v-else :size="20" />
					<!-- eslint-disable-next-line vue/no-v-html -->
					<span v-html="highlight(row.name, row.id, 'name')" />
					<AlertIcon
						v-if="row.possiblyCompromisedAt"
						:size="16"
						class="secret-list__compromised-icon"
						:title="t('doriath', 'Possibly compromised — consider rotating this credential')" />
				</div>
			</template>

			<template #column-url="{ row }">
				<div v-if="row.url" class="secret-list__url-cell">
					<a
						:href="normalizeUrl(row.url)"
						class="secret-list__url"
						target="_blank"
						rel="noopener noreferrer"
						@click.stop>
						<!-- eslint-disable-next-line vue/no-v-html -->
						<span v-html="highlight(row.url, row.id, 'url')" />
						<OpenInNewIcon :size="14" class="secret-list__external-icon" />
					</a>
				</div>
				<span v-else class="secret-list__empty-cell">—</span>
			</template>

			<template #column-type="{ row }">
				<!-- eslint-disable-next-line vue/no-v-html -->
				<span v-if="row.typeName" v-html="highlight(row.typeName, row.id, 'typeName')" />
				<span v-else class="secret-list__empty-cell">—</span>
			</template>

			<template #column-createdAt="{ row }">
				{{ formatDate(row.createdAt) }}
			</template>
		</CnIndexPage>

		<CreateSecretDialog
			:open="showSecretDialog"
			:secret="editSecret"
			:folder-id="resolvedFolderId"
			@update:open="showSecretDialog = $event"
			@created="onSecretCreated"
			@updated="onSecretUpdated" />

		<MoveSecretDialog
			:open="showMoveDialog"
			:secret-id="moveSecret?.id"
			:secret-name="moveSecret?.name || ''"
			:current-folder-id="moveSecret?.folderId ?? null"
			@update:open="showMoveDialog = $event"
			@moved="onSecretMoved" />
	</div>
</template>

<script>
import Fuse from 'fuse.js'
import { NcEmptyContent, NcInputField } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import KeyVariantIcon from 'vue-material-design-icons/KeyVariant.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import FolderMoveOutlineIcon from 'vue-material-design-icons/FolderMoveOutline.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CreateSecretDialog from '../dialog/CreateSecretDialog.vue'
import MoveSecretDialog from '../dialog/MoveSecretDialog.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'
import { pathToFolderId, folderIdToPath, folderDirQuery } from '../utils/folderPath.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { useSettingsStore } from '../store/modules/settings.js'
import { getFaviconUrl } from '../utils/favicon.js'

export default {
	name: 'SecretList',
	components: {
		CnIndexPage,
		NcEmptyContent,
		NcInputField,
		AlertIcon,
		ChevronRightIcon,
		CreateSecretDialog,
		HomeIcon,
		MoveSecretDialog,
		KeyVariantIcon,
		OpenInNewIcon,
	},
	props: {
		dirPath: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			searchTerm: '',
			showSecretDialog: false,
			editSecret: null,
			showMoveDialog: false,
			moveSecret: null,
			lastResolvedFolderId: null,
		}
	},
	computed: {
		folderStore() {
			return useFolderStore()
		},
		secretStore() {
			return useSecretStore()
		},
		secretTypeStore() {
			return useSecretTypeStore()
		},
		settingsStore() {
			return useSettingsStore()
		},
		typesById() {
			const map = {}
			for (const t of this.secretTypeStore.types) {
				map[t.id] = t.label
			}
			return map
		},
		isRootView() {
			return this.dirPath === '/'
		},
		resolvedFolderId() {
			if (!this.dirPath || this.dirPath === '/') return null
			if (this.folderStore.folders.length === 0) return null
			return pathToFolderId(this.dirPath, this.folderStore.folders) ?? null
		},
		pageTitle() {
			if (this.resolvedFolderId) {
				const folder = this.folderStore.foldersById[this.resolvedFolderId]
				if (folder) return folder.name
			}
			return t('doriath', 'Secrets')
		},
		tableColumns() {
			return [
				{ key: 'name', label: t('doriath', 'Name'), sortable: true },
				{ key: 'url', label: t('doriath', 'URL') },
				{ key: 'type', label: t('doriath', 'Type') },
				{ key: 'createdAt', label: t('doriath', 'Created'), sortable: true },
			]
		},
		enrichedSecrets() {
			return this.secretStore.secrets.map(s => ({
				...s,
				typeName: this.typesById[s.typeId] || '',
			}))
		},
		fuse() {
			return new Fuse(this.enrichedSecrets, {
				keys: ['name', 'url', 'typeName'],
				threshold: 0.4,
				includeMatches: true,
			})
		},
		fuseResults() {
			const query = this.searchTerm.trim()
			if (!query) return null
			return this.fuse.search(query)
		},
		filteredSecrets() {
			if (!this.fuseResults) return this.enrichedSecrets
			return this.fuseResults.map(r => r.item)
		},
		matchesById() {
			if (!this.fuseResults) return {}
			const map = {}
			for (const result of this.fuseResults) {
				map[result.item.id] = result.matches
			}
			return map
		},
		paginationData() {
			if (this.searchTerm.trim()) {
				// Local search: show filtered count, single page
				const total = this.filteredSecrets.length
				return { page: 1, pages: 1, total, limit: total || 1 }
			}
			const page = this.secretStore.page
			const limit = 50
			const total = this.secretStore.totalCount
			const pages = Math.ceil(total / limit) || 1
			return { page, pages, total, limit }
		},
		emptyText() {
			return this.searchTerm
				? t('doriath', 'No secrets found for your search')
				: t('doriath', 'No secrets found')
		},
		breadcrumbs() {
			if (!this.resolvedFolderId) return []

			const foldersById = this.folderStore.foldersById
			const crumbs = []
			let current = foldersById[this.resolvedFolderId]
			while (current) {
				crumbs.push({
					id: current.id,
					name: current.name,
					to: { name: 'FolderView', query: folderDirQuery(current.id, foldersById) },
				})
				current = current.parentId ? foldersById[current.parentId] : null
			}
			crumbs.reverse()
			crumbs.unshift({
				id: null,
				name: '/',
				to: { name: 'FolderView', query: { dir: '/' } },
			})
			return crumbs
		},
		rowActions() {
			return [
				{
					label: t('doriath', 'Edit'),
					icon: PencilIcon,
					handler: (row) => {
						this.openSecretForEdit(row.id)
					},
				},
				{
					label: t('doriath', 'Move'),
					icon: FolderMoveOutlineIcon,
					handler: (row) => {
						this.moveSecret = row
						this.showMoveDialog = true
					},
				},
			]
		},
	},
	watch: {
		dirPath() {
			this.loadSecrets()
		},
		'folderStore.folders': {
			deep: true,
			handler() {
				// After folder data changes (rename, move, etc.), sync the URL
				// if the path for the current folder has changed.
				// Use lastResolvedFolderId because the old dirPath can no longer
				// resolve against the updated folder data.
				const folderId = this.lastResolvedFolderId
				if (!folderId) return
				if (!this.folderStore.foldersById[folderId]) return
				const correctPath = folderIdToPath(folderId, this.folderStore.foldersById)
				if (correctPath !== this.dirPath) {
					this.$router.replace({ name: 'FolderView', query: { dir: correctPath } })
				}
			},
		},
	},
	async created() {
		await Promise.all([
			this.loadSecrets(),
			this.secretTypeStore.fetchTypes(),
		])
	},
	methods: {
		async loadSecrets() {
			this.secretStore.currentSecret = null
			this.secretStore.page = 1
			this.searchTerm = ''

			// For non-root paths, ensure folders are loaded for resolution
			if (this.dirPath && this.dirPath !== '/' && this.folderStore.folders.length === 0) {
				await this.folderStore.fetchFolders()
			}

			const folderId = (!this.dirPath || this.dirPath === '/')
				? null
				: pathToFolderId(this.dirPath, this.folderStore.folders)

			if (folderId === undefined) {
				// Invalid path — redirect to root
				this.$router.replace({ name: 'FolderView', query: { dir: '/' } })
				return
			}

			this.lastResolvedFolderId = folderId
			await this.secretStore.fetchSecrets(folderId, this.isRootView)
		},
		fetchCurrentSecrets() {
			return this.secretStore.fetchSecrets(this.resolvedFolderId, this.isRootView)
		},
		async openSecret(id) {
			try {
				await this.secretStore.fetchSecret(id)
			} catch (e) {
				console.error('Doriath: openSecret failed:', e)
			}
		},
		async openSecretForEdit(id) {
			try {
				await this.secretStore.fetchSecret(id)
				this.editSecret = this.secretStore.currentSecret
				this.showSecretDialog = true
			} catch (e) {
				console.error('Doriath: openSecretForEdit failed:', e)
			}
		},
		onRowClick(row) {
			this.openSecret(row.id)
		},
		onSort({ key, order }) {
			this.secretStore.sort = key ?? null
			this.secretStore.direction = order ? order.toUpperCase() : null
			this.secretStore.page = 1
			this.fetchCurrentSecrets()
		},
		async onPageChanged(page) {
			this.secretStore.page = page
			await this.fetchCurrentSecrets()
		},
		async onPageSizeChanged() {
			this.secretStore.page = 1
			await this.fetchCurrentSecrets()
		},
		async onDeleteConfirm(id) {
			try {
				await this.secretStore.deleteSecret(id)
				await this.fetchCurrentSecrets()
				this.$refs.indexPage.setSingleDeleteResult({ success: true })
			} catch (e) {
				this.$refs.indexPage.setSingleDeleteResult({
					error: e.message || t('doriath', 'Failed to delete'),
				})
			}
		},
		async onSecretCreated(created) {
			await this.fetchCurrentSecrets()
			await this.openSecret(created.id)
		},
		async onSecretMoved() {
			await this.fetchCurrentSecrets()
		},
		async onSecretUpdated() {
			await this.fetchCurrentSecrets()
			if (this.editSecret?.id && this.secretStore.currentSecret?.id === this.editSecret.id) {
				await this.secretStore.fetchSecret(this.editSecret.id)
			}
		},
		escapeHtml(text) {
			return text
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
		},
		highlight(text, rowId, key) {
			if (!text) return ''
			const escaped = this.escapeHtml(text)
			const matches = this.matchesById[rowId]
			if (!matches) return escaped

			const fieldMatch = matches.find(m => m.key === key)
			if (!fieldMatch) return escaped

			const indices = [...fieldMatch.indices].sort((a, b) => a[0] - b[0])
			let result = ''
			let lastEnd = 0
			for (const [start, end] of indices) {
				result += this.escapeHtml(text.slice(lastEnd, start))
				result += '<mark>' + this.escapeHtml(text.slice(start, end + 1)) + '</mark>'
				lastEnd = end + 1
			}
			result += this.escapeHtml(text.slice(lastEnd))
			return result
		},
		normalizeUrl(url) {
			if (!url) return ''
			return /^https?:\/\//i.test(url) ? url : 'https://' + url
		},
		getFavicon(url) {
			const faviconServiceUrl = this.settingsStore?.settings?.faviconServiceUrl ?? null
			return getFaviconUrl(url, faviconServiceUrl)
		},
		formatDate(dateString) {
			if (!dateString) return '—'
			try {
				return new Date(dateString).toLocaleDateString()
			} catch {
				return dateString
			}
		},
	},
}
</script>

<style scoped>
.secret-list__breadcrumbs {
	display: flex;
	align-items: center;
	gap: 2px;
	font-size: 14px;
	padding: 0 8px;
}

.secret-list__breadcrumb {
	display: inline-flex;
	align-items: center;
	color: var(--color-text-maxcontrast);
	text-decoration: none;
	padding: 4px 6px;
	border-radius: var(--border-radius);
	transition: background-color 0.15s ease, color 0.15s ease;
}

.secret-list__breadcrumb:hover {
	color: var(--color-main-text);
	background-color: var(--color-background-hover);
}

.secret-list__breadcrumb--current {
	color: var(--color-main-text);
	font-weight: 500;
	padding: 4px 6px;
}

.secret-list__breadcrumb-sep {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.secret-list__search {
	order: -1;
	max-width: 320px;
}

.secret-list__name-cell {
	display: flex;
	align-items: center;
	gap: 8px;
}

.secret-list__favicon {
	width: 16px;
	height: 16px;
	object-fit: contain;
	flex-shrink: 0;
}

.secret-list__compromised-icon {
	color: var(--color-warning);
	flex-shrink: 0;
}

.secret-list__url-cell {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.secret-list__url {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	max-width: 100%;
	color: var(--color-primary-element);
	text-decoration: none;
}

.secret-list__url:hover {
	text-decoration: underline;
}

.secret-list__external-icon {
	flex-shrink: 0;
	opacity: 0.6;
}

.secret-list__url:hover .secret-list__external-icon {
	opacity: 1;
}

.secret-list__empty-cell {
	color: var(--color-text-maxcontrast);
}

:deep(mark) {
	background-color: var(--color-warning);
	color: inherit;
	border-radius: 2px;
}
</style>
