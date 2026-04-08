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
			:sort-order="secretStore.direction.toLowerCase()"
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
			:folder-id="folderId"
			@update:open="showSecretDialog = $event"
			@created="onSecretCreated"
			@updated="onSecretUpdated" />
	</div>
</template>

<script>
import Fuse from 'fuse.js'
import { NcEmptyContent, NcInputField } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import KeyVariantIcon from 'vue-material-design-icons/KeyVariant.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CreateSecretDialog from '../dialog/CreateSecretDialog.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'
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
		CreateSecretDialog,
		KeyVariantIcon,
		OpenInNewIcon,
	},
	props: {
		folderId: {
			type: String,
			default: null,
		},
		rootOnly: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			searchTerm: '',
			showSecretDialog: false,
			editSecret: null,
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
		pageTitle() {
			if (this.folderId) {
				const folder = this.folderStore.folders.find(f => f.id === this.folderId)
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
		rowActions() {
			return [
				{
					label: t('doriath', 'Edit'),
					icon: PencilIcon,
					handler: (row) => {
						this.openSecretForEdit(row.id)
					},
				},
			]
		},
	},
	watch: {
		$route() {
			this.loadSecrets()
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
			this.secretStore.page = 1
			this.searchTerm = ''
			await this.secretStore.fetchSecrets(this.folderId, this.rootOnly)
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
			this.secretStore.sort = key
			this.secretStore.direction = order.toUpperCase()
			this.secretStore.page = 1
			this.secretStore.fetchSecrets(this.folderId, this.rootOnly)
		},
		async onPageChanged(page) {
			this.secretStore.page = page
			await this.secretStore.fetchSecrets(this.folderId, this.rootOnly)
		},
		async onPageSizeChanged() {
			this.secretStore.page = 1
			await this.secretStore.fetchSecrets(this.folderId, this.rootOnly)
		},
		async onDeleteConfirm(id) {
			try {
				await this.secretStore.deleteSecret(id)
				await this.secretStore.fetchSecrets(this.folderId, this.rootOnly)
				this.$refs.indexPage.setSingleDeleteResult({ success: true })
			} catch (e) {
				this.$refs.indexPage.setSingleDeleteResult({
					error: e.message || t('doriath', 'Failed to delete'),
				})
			}
		},
		async onSecretCreated(created) {
			await this.secretStore.fetchSecrets(this.folderId, this.rootOnly)
			await this.openSecret(created.id)
		},
		async onSecretUpdated() {
			await this.secretStore.fetchSecrets(this.folderId, this.rootOnly)
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
	color: inherit;
	border-radius: 2px;
}
</style>
