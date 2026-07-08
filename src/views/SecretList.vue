<template>
	<div class="secret-list-view">
		<!-- Folder sidebar — the shared CnFolderSidebar (custom source), fed by
		     the per-user folder store. -->
		<div class="secret-list-view__sidebar">
			<CnFolderSidebar
				:folders="folders"
				:selected-id="selectedFolderId"
				:all-label="t('doriath', 'All secrets')"
				allow-create
				:create-label="t('doriath', 'New folder')"
				@select="selectFolder"
				@create="openCreateFolder" />
		</div>

		<!-- Main: the shared CnIndexPage in list mode. Rows are rendered by the
		     bespoke SecretListItem (favicon/type-icon + on-demand decryption)
		     via the #list-item slot. -->
		<div class="secret-list-view__main">
			<CnIndexPage
				view-mode="list"
				:available-view-modes="['list', 'cards', 'table']"
				list-label="List"
				:selectable="false"
				:objects="secrets"
				:schema="listSchema"
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
				@add="openCreateSecret"
				@search="onSearch"
				@sort-change="onSort"
				@page-changed="goToPage">
				<template #list-item="{ object }">
					<SecretListItem
						:secret="object"
						@open="openSecret"
						@copied="onCopied" />
				</template>
			</CnIndexPage>
		</div>
	</div>
</template>

<script>
// eslint-disable-next-line import/named
import { CnIndexPage, CnFolderSidebar } from '@conduction/nextcloud-vue'
import SecretListItem from '../components/SecretListItem.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { useFolderStore } from '../store/modules/folder.js'

const PAGE_SIZE = 50

/**
 * The main vault view: a folder sidebar plus a searchable, sortable,
 * paginated secret list, built on the shared CnIndexPage list view and
 * CnFolderSidebar. Names and urls are plaintext; encrypted fields are
 * decrypted only on demand (copy / detail) inside SecretListItem.
 */
export default {
	name: 'SecretList',

	components: {
		CnIndexPage,
		CnFolderSidebar,
		SecretListItem,
	},

	inject: {
		/**
		 * Open a registry-registered modal. Provided by CnAppRoot; defaults to a
		 * no-op so the view still mounts in isolation (e.g. unit tests).
		 */
		cnOpenModal: { default: () => () => {} },
	},

	data() {
		return {
			searchTerm: '',
			sortField: 'name',
			searchTimer: null,
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
		/** Minimal schema so CnIndexPage can offer cards/table fallbacks. */
		listSchema() {
			return {
				properties: {
					name: { title: t('doriath', 'Name'), type: 'string' },
					url: { title: t('doriath', 'URL'), type: 'string' },
				},
				configuration: { objectNameField: 'name', objectDescriptionField: 'url' },
			}
		},
		sortOptions() {
			return [
				{ value: 'name', label: t('doriath', 'Name') },
				{ value: 'url', label: t('doriath', 'URL') },
				{ value: 'created_at', label: t('doriath', 'Created') },
				{ value: 'updated_at', label: t('doriath', 'Updated') },
			]
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

		/**
		 * Reload the current page of secrets with the active filters.
		 *
		 * @return {Promise<void>}
		 */
		async reload() {
			await this.secretStore.fetchSecrets({
				folderId: this.selectedFolderId,
				search: this.searchTerm,
				sort: this.sortField,
				page: 1,
			})
		},

		/**
		 * Debounced inline-search handler.
		 *
		 * @param {string} value The current search value.
		 * @return {void}
		 */
		onSearch(value) {
			this.searchTerm = value
			if (this.searchTimer) {
				clearTimeout(this.searchTimer)
			}
			this.searchTimer = setTimeout(() => {
				this.secretStore.searchSecrets(this.searchTerm)
			}, 300)
		},

		/**
		 * Change the sort field and reload.
		 *
		 * @param {string} value The chosen sort field.
		 * @return {void}
		 */
		onSort(value) {
			this.sortField = value
			this.reload()
		},

		/**
		 * Go to a specific page.
		 *
		 * @param {number} target The target page number.
		 * @return {void}
		 */
		goToPage(target) {
			this.secretStore.fetchSecrets({
				folderId: this.selectedFolderId,
				search: this.searchTerm,
				sort: this.sortField,
				page: target,
			})
		},

		/**
		 * Navigate to a folder filter (null = all secrets).
		 *
		 * @param {string|null} folderId The folder ID.
		 * @return {void}
		 */
		selectFolder(folderId) {
			if (folderId) {
				this.$router.push(`/folders/${folderId}`)
			} else if (this.$route.path !== '/secrets') {
				this.$router.push('/secrets')
			} else {
				this.reload()
			}
		},

		/**
		 * Open a secret's detail view.
		 *
		 * @param {string} id The secret ID.
		 * @return {void}
		 */
		openSecret(id) {
			this.$router.push(`/secrets/${id}`)
		},

		/**
		 * Copied-toast hook (SecretListItem @copied). No-op placeholder for
		 * future toast wiring; kept so the event has a handler.
		 *
		 * @return {void}
		 */
		onCopied() {},

		/**
		 * Open the create-secret dialog, defaulting the folder to the current
		 * view, and reload the list on success.
		 *
		 * @return {void}
		 */
		openCreateSecret() {
			this.cnOpenModal('secret-create', {
				folderId: this.selectedFolderId,
				onSaved: () => this.reload(),
			})
		},

		/**
		 * Open the create-folder dialog and reload the folder tree on success.
		 *
		 * @param {{ parentId: (string|null) }} [payload] The parent folder id.
		 * @return {void}
		 */
		openCreateFolder({ parentId } = {}) {
			this.cnOpenModal('folder-create', {
				parentId: parentId ?? this.selectedFolderId,
				onSaved: () => this.folderStore.fetchFolders(),
			})
		},
	},
}
</script>

<style scoped>
.secret-list-view {
	display: flex;
	gap: 16px;
	height: 100%;
	padding: 16px;
}

.secret-list-view__sidebar {
	width: 240px;
	flex: 0 0 auto;
}

.secret-list-view__main {
	flex: 1 1 auto;
	min-width: 0;
}
</style>
