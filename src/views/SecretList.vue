<template>
	<div class="secret-list-view">
		<div class="secret-list-view__sidebar">
			<NcButton type="secondary" wide @click="selectFolder(null)">
				<template #icon>
					<AllInclusive :size="18" />
				</template>
				{{ t('doriath', 'All secrets') }}
			</NcButton>
			<FolderTree :folders="folderTree"
				:selected-id="selectedFolderId"
				@select="selectFolder" />
		</div>

		<div class="secret-list-view__main">
			<div class="secret-list-view__toolbar">
				<NcTextField :value.sync="searchTerm"
					:label="t('doriath', 'Search secrets')"
					trailing-button-icon="close"
					:show-trailing-button="searchTerm !== ''"
					@trailing-button-click="clearSearch"
					@update:value="onSearchInput">
					<Magnify :size="18" />
				</NcTextField>

				<NcSelect v-model="sortField"
					:options="sortOptions"
					:reduce="opt => opt.value"
					:input-label="t('doriath', 'Sort by')"
					:clearable="false"
					@update:model-value="reload" />
			</div>

			<NcEmptyContent v-if="!loading && secrets.length === 0"
				:name="t('doriath', 'No secrets yet')"
				:description="t('doriath', 'Create your first secret to get started.')">
				<template #icon>
					<KeyIcon />
				</template>
			</NcEmptyContent>

			<NcLoadingIcon v-else-if="loading" :size="32" class="secret-list-view__loading" />

			<div v-else class="secret-list-view__items">
				<SecretListItem v-for="secret in secrets"
					:key="secret.id"
					:secret="secret"
					@open="openSecret" />
			</div>

			<div v-if="totalPages > 1" class="secret-list-view__pagination">
				<NcButton :disabled="page <= 1" @click="goToPage(page - 1)">
					{{ t('doriath', 'Previous') }}
				</NcButton>
				<span class="secret-list-view__page-info">
					{{ t('doriath', 'Page {page} of {total}', { page, total: totalPages }) }}
				</span>
				<NcButton :disabled="page >= totalPages" @click="goToPage(page + 1)">
					{{ t('doriath', 'Next') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import AllInclusive from 'vue-material-design-icons/AllInclusive.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import FolderTree from '../components/FolderTree.vue'
import SecretListItem from '../components/SecretListItem.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { useFolderStore } from '../store/modules/folder.js'

const PAGE_SIZE = 50

/**
 * The main vault view: a folder sidebar plus a searchable, sortable,
 * paginated secret list. Names and urls are plaintext; encrypted fields are
 * decrypted only on demand (copy / detail).
 */
export default {
	name: 'SecretList',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		AllInclusive,
		KeyIcon,
		Magnify,
		FolderTree,
		SecretListItem,
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
		page() {
			return this.secretStore.page
		},
		totalPages() {
			return Math.max(1, Math.ceil(this.secretStore.totalCount / PAGE_SIZE))
		},
		folderTree() {
			return this.folderStore.folderTree
		},
		selectedFolderId() {
			return this.$route.params.folderId || null
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
		 * Debounced search input handler.
		 *
		 * @return {void}
		 */
		onSearchInput() {
			if (this.searchTimer) {
				clearTimeout(this.searchTimer)
			}
			this.searchTimer = setTimeout(() => {
				this.secretStore.searchSecrets(this.searchTerm)
			}, 300)
		},

		/**
		 * Clear the search and reload.
		 *
		 * @return {void}
		 */
		clearSearch() {
			this.searchTerm = ''
			this.reload()
		},

		/**
		 * Navigate to a folder filter.
		 *
		 * @param {string|null} folderId The folder ID (null = all).
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
		 * Go to a specific page.
		 *
		 * @param {number} target The target page number.
		 * @return {Promise<void>}
		 */
		async goToPage(target) {
			await this.secretStore.fetchSecrets({
				folderId: this.selectedFolderId,
				search: this.searchTerm,
				sort: this.sortField,
				page: target,
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

.secret-list-view__toolbar {
	display: flex;
	gap: 12px;
	align-items: flex-end;
	margin-bottom: 12px;
}

.secret-list-view__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	margin-top: 16px;
}

.secret-list-view__loading {
	display: flex;
	justify-content: center;
	margin-top: 32px;
}
</style>
