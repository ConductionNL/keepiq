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
			<div class="secret-list-view__actions">
				<NcButton type="primary" @click="openCreateSecret">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('doriath', 'New secret') }}
				</NcButton>
				<NcButton type="secondary" @click="openCreateFolder">
					<template #icon>
						<FolderPlus :size="20" />
					</template>
					{{ t('doriath', 'New folder') }}
				</NcButton>

				<!-- Data export / GDPR / deletion entry points (secret-export-gdpr §6.5). -->
				<NcActions :menu-name="t('doriath', 'My data')">
					<NcActionButton @click="openExport">
						{{ t('doriath', 'Export data') }}
					</NcActionButton>
					<NcActionButton @click="openGdpr">
						{{ t('doriath', 'GDPR export') }}
					</NcActionButton>
					<NcActionButton @click="deletionOpen = true">
						{{ t('doriath', 'Delete my Doriath data') }}
					</NcActionButton>
				</NcActions>
			</div>

			<ExportDialog :open="exportOpen"
				:secrets="decryptedSecrets"
				:folders="folders"
				@update:open="exportOpen = $event" />
			<GdprExportDialog :open="gdprOpen"
				:secrets="decryptedSecrets"
				:folders="folders"
				@update:open="gdprOpen = $event" />
			<AccountDeletionDialog :open="deletionOpen"
				@update:open="deletionOpen = $event"
				@export-first="onExportFirst" />

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
					:input-label="t('doriath', 'Sort by')"
					:options="sortOptions"
					:reduce="opt => opt.value"
					:clearable="false"
					@update:model-value="reload" />
			</div>

			<NcEmptyContent v-if="!loading && secrets.length === 0"
				:name="t('doriath', 'No secrets yet')"
				:description="t('doriath', 'Create your first secret to get started.')">
				<template #icon>
					<KeyIcon />
				</template>
				<template #action>
					<NcButton type="primary" @click="openCreateSecret">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('doriath', 'New secret') }}
					</NcButton>
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
import { NcActionButton, NcActions, NcButton, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import AllInclusive from 'vue-material-design-icons/AllInclusive.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import FolderPlus from 'vue-material-design-icons/FolderPlus.vue'
import FolderTree from '../components/FolderTree.vue'
import SecretListItem from '../components/SecretListItem.vue'
import ExportDialog from '../dialogs/ExportDialog.vue'
import GdprExportDialog from '../dialogs/GdprExportDialog.vue'
import AccountDeletionDialog from '../dialogs/AccountDeletionDialog.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useHealthStore } from '../store/modules/health.js'
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
		NcActionButton,
		NcActions,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		AllInclusive,
		KeyIcon,
		Magnify,
		Plus,
		FolderPlus,
		FolderTree,
		SecretListItem,
		ExportDialog,
		GdprExportDialog,
		AccountDeletionDialog,
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
			exportOpen: false,
			gdprOpen: false,
			deletionOpen: false,
			decryptedSecrets: [],
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
		/**
		 * The flat folder list, used to populate the export scope selector and
		 * resolve relative folder paths in the export serializer.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		folders() {
			return this.folderStore.folders
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

	/**
	 * Load types + folders + the first secrets page, then lazily run the
	 * client-side password-health pass so strength badges appear.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-strength-scoring-and-badges
	 */
	async mounted() {
		await Promise.all([
			useSecretTypeStore().fetchTypes(),
			this.folderStore.fetchFolders(),
		])
		await this.reload()
		// Lazily run the client-side password-health pass after the first list
		// render so strength badges appear without blocking the render. Fire and
		// forget; it aborts cleanly when the vault is locked (password-health D2).
		this.triggerHealthPass()
	},

	methods: {
		t,

		/**
		 * Decrypt every secret the user can read, in the browser, so the export
		 * dialogs can serialize the full vault. Returns [] when the vault is
		 * locked (the dialogs then fall back to metadata-only where applicable).
		 *
		 * @return {Promise<Array<object>>}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		async decryptAllSecrets() {
			const store = this.secretStore
			// Pull a full page set (the export covers the whole vault, not the
			// paginated view). The list already lives in the store.
			await store.fetchSecrets({ page: 1, limit: 100000 })
			const out = []
			for (const secret of store.secrets) {
				try {
					out.push(await store.decryptSecret(secret))
				} catch {
					// A secret whose suite is blocked/revoked cannot be decrypted;
					// skip it rather than failing the whole export.
				}
			}
			return out
		},

		/**
		 * Open the export dialog after decrypting the vault client-side.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		async openExport() {
			this.decryptedSecrets = await this.decryptAllSecrets()
			this.exportOpen = true
		},

		/**
		 * Open the GDPR export dialog; decrypt the vault if it is unlocked.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		async openGdpr() {
			this.decryptedSecrets = await this.decryptAllSecrets()
			this.gdprOpen = true
		},

		/**
		 * "Export first" suggestion from the deletion dialog: close it and open
		 * the export dialog.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
		 */
		async onExportFirst() {
			this.deletionOpen = false
			await this.openExport()
		},

		/**
		 * Lazily run the password-health analysis so the list shows strength
		 * badges. Memory-only; aborts when the vault is locked.
		 *
		 * @return {void}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-strength-scoring-and-badges
		 */
		triggerHealthPass() {
			const health = useHealthStore()
			health.registerLockReset()
			if (health.status === 'idle') {
				health.analyseVault({ stalenessThreshold: '365', breachEnabled: false }).catch(() => {})
			}
		},

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
		 * @return {void}
		 */
		openCreateFolder() {
			this.cnOpenModal('folder-create', {
				parentId: this.selectedFolderId,
				onSaved: () => this.folderStore.fetchFolders(),
			})
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

.secret-list-view__actions {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
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
