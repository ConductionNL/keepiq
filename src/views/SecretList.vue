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

		<!-- Main: the shared CnIndexPage in list mode, plus the secondary
		     actions (new folder / import / export / GDPR / account deletion)
		     that live alongside it. -->
		<div class="secret-list-view__main">
			<!-- Secondary actions: new folder / import / export / GDPR / account
			     deletion (secret-export-gdpr §6.5, secret-import). "New secret"
			     itself is CnIndexPage's own add button below. -->
			<div class="secret-list-view__actions">
				<NcButton type="secondary" @click="openCreateFolder">
					<template #icon>
						<FolderPlus :size="20" />
					</template>
					{{ t('doriath', 'New folder') }}
				</NcButton>
				<NcButton type="secondary"
					:disabled="vaultLocked"
					data-testid="import-secrets"
					@click="openImport">
					<template #icon>
						<Import :size="20" />
					</template>
					{{ t('doriath', 'Import') }}
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
			<ImportWizardDialog :open="importOpen"
				@update:open="importOpen = $event"
				@imported="onImported" />

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
import { NcActionButton, NcActions, NcButton } from '@nextcloud/vue'
import FolderPlus from 'vue-material-design-icons/FolderPlus.vue'
import Import from 'vue-material-design-icons/Import.vue'
import SecretListItem from '../components/SecretListItem.vue'
import ExportDialog from '../dialogs/ExportDialog.vue'
import GdprExportDialog from '../dialogs/GdprExportDialog.vue'
import AccountDeletionDialog from '../dialogs/AccountDeletionDialog.vue'
import ImportWizardDialog from '../dialogs/ImportWizardDialog.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useHealthStore } from '../store/modules/health.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { useFolderStore } from '../store/modules/folder.js'
import { useSessionStore } from '../store/modules/session.js'

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
		NcActionButton,
		NcActions,
		NcButton,
		FolderPlus,
		Import,
		SecretListItem,
		ExportDialog,
		GdprExportDialog,
		AccountDeletionDialog,
		ImportWizardDialog,
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
			importOpen: false,
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
		/**
		 * The flat folder list, used to feed the folder sidebar, populate the
		 * export scope selector, and resolve relative folder paths in the
		 * export serializer.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
		 */
		folders() {
			return this.folderStore.folders
		},
		/**
		 * Whether the vault is locked — the Import action is disabled while
		 * locked (import requires the session CryptoKey to encrypt rows).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-client-side-parsing-and-e2e-guarantee
		 */
		vaultLocked() {
			return useSessionStore().isLocked
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
		 * Open the import wizard. Guarded against a locked vault — import needs
		 * the session CryptoKey to encrypt rows client-side. When locked, the
		 * wizard is not opened and reads no file; the toolbar button is also
		 * disabled while locked, and the wizard itself renders a lock guard.
		 *
		 * @return {void}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-client-side-parsing-and-e2e-guarantee
		 */
		openImport() {
			if (this.vaultLocked) {
				return
			}
			this.importOpen = true
		},

		/**
		 * After an import completes, reload the secret list + folder tree so the
		 * imported secrets and any created folders appear.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-import-summary-report
		 */
		async onImported() {
			await this.folderStore.fetchFolders()
			await this.reload()
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
