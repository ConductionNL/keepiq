<template>
	<div class="secrets-lab-view">
		<!-- Folder sidebar — the generic CnFolderSidebar (custom source), fed by
		     the same folder store as production. -->
		<div class="secrets-lab-view__sidebar">
			<CnFolderSidebar
				:folders="folders"
				:selected-id="selectedFolderId"
				:all-label="t('doriath', 'All secrets')"
				allow-create
				:create-label="t('doriath', 'New folder')"
				@select="selectFolder"
				@create="openCreateFolder" />
		</div>

		<!-- Main: the generic CnIndexPage in list mode -->
		<div class="secrets-lab-view__main">
			<CnIndexPage
				view-mode="list"
				:available-view-modes="['list', 'cards', 'table']"
				list-label="List"
				:selectable="false"
				:objects="secrets"
				:schema="labSchema"
				:list-config="listConfig"
				:loading="loading"
				:pagination="pagination"
				:add-label="t('doriath', 'New secret')"
				add-icon="Plus"
				inline-search
				:search-value="searchTerm"
				:search-placeholder="t('doriath', 'Search secrets')"
				show-sort-select
				:sort-select-options="sortOptions"
				:sort-select-value="sortField"
				row-key="id"
				:empty-text="t('doriath', 'No secrets yet')"
				@sort-change="onSort"
				@add="openCreateSecret"
				@search="onSearch"
				@row-click="openSecret"
				@page-changed="goToPage">
				<!-- Favicon (or type icon) — matches SecretListItem -->
				<template #row-icon="{ object }">
					<img v-if="faviconFor(object) && !failed[object.id]"
						:src="faviconFor(object)"
						:alt="''"
						width="24"
						height="24"
						@error="$set(failed, object.id, true)">
					<component :is="typeIconFor(object)" v-else :size="24" />
				</template>

				<!-- Copy button / locked badge — matches SecretListItem -->
				<template #row-actions="{ row }">
					<span v-if="row.blocked" class="secrets-lab-view__blocked">
						<Lock :size="16" />
						{{ t('doriath', 'Locked') }}
					</span>
					<CopyButton v-else
						:resolve="() => resolveKey(row)"
						:label="t('doriath', 'Copy password')" />
				</template>
			</CnIndexPage>
		</div>
	</div>
</template>

<script>
// eslint-disable-next-line import/named
import { CnIndexPage, CnFolderSidebar } from '@conduction/nextcloud-vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import Key from 'vue-material-design-icons/Key.vue'
import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import Console from 'vue-material-design-icons/Console.vue'
import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import NoteText from 'vue-material-design-icons/NoteText.vue'
import Database from 'vue-material-design-icons/Database.vue'
import CopyButton from '../components/CopyButton.vue'
import { resolveFaviconUrl, typeIconName } from '../utils/favicon.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { useFolderStore } from '../store/modules/folder.js'

const PAGE_SIZE = 50

/**
 * Side-by-side lab rebuild of the vault list on top of the shared
 * `CnIndexPage` list view (view-mode="list"). Backed by the same secret /
 * folder stores as the production SecretList view, so the two can be compared
 * pixel-for-pixel during development. Not linked from the primary menu once
 * the design is settled.
 */
export default {
	name: 'SecretsLabView',

	components: {
		CnIndexPage,
		CnFolderSidebar,
		Lock,
		Key,
		CodeTags,
		Console,
		ShieldCheck,
		NoteText,
		Database,
		CopyButton,
	},

	inject: {
		cnOpenModal: { default: () => () => {} },
	},

	data() {
		return {
			searchTerm: '',
			sortField: 'name',
			searchTimer: null,
			failed: {},
		}
	},

	computed: {
		secretStore() {
			return useSecretStore()
		},
		folderStore() {
			return useFolderStore()
		},
		secrets() {
			return this.secretStore.secrets
		},
		loading() {
			return this.secretStore.loading
		},
		folders() {
			return this.folderStore.folders
		},
		sortOptions() {
			return [
				{ value: 'name', label: t('doriath', 'Name') },
				{ value: 'url', label: t('doriath', 'URL') },
				{ value: 'created_at', label: t('doriath', 'Created') },
				{ value: 'updated_at', label: t('doriath', 'Updated') },
			]
		},
		selectedFolderId() {
			return this.$route.params.folderId || null
		},
		pagination() {
			return {
				page: this.secretStore.page,
				pages: Math.max(1, Math.ceil(this.secretStore.totalCount / PAGE_SIZE)),
				total: this.secretStore.totalCount,
				limit: PAGE_SIZE,
			}
		},
		/** Minimal schema so CnIndexPage's config-driven row can map fields. */
		labSchema() {
			return {
				properties: {
					name: { title: t('doriath', 'Name'), type: 'string' },
					url: { title: t('doriath', 'URL'), type: 'string' },
				},
				configuration: { objectNameField: 'name' },
			}
		},
		listConfig() {
			return { titleField: 'name', subtitleField: 'url', iconName: 'Key' }
		},
	},

	watch: {
		selectedFolderId() {
			this.reload()
		},
	},

	async mounted() {
		await Promise.all([
			useSecretTypeStore().fetchTypes(),
			this.folderStore.fetchFolders(),
		])
		await this.reload()
	},

	methods: {
		t,

		async reload() {
			await this.secretStore.fetchSecrets({
				folderId: this.selectedFolderId,
				search: this.searchTerm,
				sort: this.sortField,
				page: 1,
			})
		},

		onSort(value) {
			this.sortField = value
			this.reload()
		},

		onSearch(value) {
			this.searchTerm = value
			if (this.searchTimer) {
				clearTimeout(this.searchTimer)
			}
			this.searchTimer = setTimeout(() => {
				this.secretStore.searchSecrets(this.searchTerm)
			}, 300)
		},

		goToPage(page) {
			this.secretStore.fetchSecrets({ page })
		},

		selectFolder(folderId) {
			if (folderId) {
				this.$router.push(`/secrets-lab/folders/${folderId}`)
			} else if (this.$route.path !== '/secrets-lab') {
				this.$router.push('/secrets-lab')
			} else {
				this.reload()
			}
		},

		openSecret(object) {
			this.$router.push(`/secrets/${object.id}`)
		},

		openCreateSecret() {
			this.cnOpenModal('secret-create', {
				folderId: this.selectedFolderId,
				onSaved: () => this.reload(),
			})
		},

		openCreateFolder({ parentId } = {}) {
			this.cnOpenModal('folder-create', {
				parentId: parentId ?? this.selectedFolderId,
				onSaved: () => this.folderStore.fetchFolders(),
			})
		},

		faviconFor(secret) {
			return resolveFaviconUrl(secret.url)
		},

		typeIconFor(secret) {
			const typeStore = useSecretTypeStore()
			const type = typeStore.typesById[secret.typeId]
			return typeIconName(type ? type.name : 'login')
		},

		async resolveKey(secret) {
			const full = await this.secretStore.fetchSecret(secret.id)
			return full.key || ''
		},
	},
}
</script>

<style scoped>
.secrets-lab-view {
	display: flex;
	gap: 16px;
	height: 100%;
}

.secrets-lab-view__sidebar {
	flex: 0 0 240px;
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	border-right: 1px solid var(--color-border);
}

.secrets-lab-view__main {
	flex: 1;
	min-width: 0;
	padding: 12px 16px;
	overflow: auto;
}

.secrets-lab-view__blocked {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
