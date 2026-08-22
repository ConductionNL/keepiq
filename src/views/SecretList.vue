<template>
	<div class="secret-list-view">
		<!-- Folder sidebar — the shared CnFolderSidebar (custom source), fed by
		     the per-user folder store. -->
		<div class="secret-list-view__sidebar">
			<CnFolderSidebar
				:folders="folders"
				:selectedId="selectedFolderId"
				:allLabel="t('keepiq', 'All secrets')"
				allowCreate
				:createLabel="t('keepiq', 'New folder')"
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
				<NcButton
					variant="secondary"
					:disabled="offlineReadOnly"
					@click="openCreateFolder">
					<template #icon>
						<FolderPlus :size="20" />
					</template>
					{{ t('keepiq', 'New folder') }}
				</NcButton>
				<!-- Ask someone for a credential (request-first-secret-requests):
				     reachable with an EMPTY vault, because a requester has nothing
				     to point at yet. The Secret is created by the request itself, so
				     this is not "make a secret then request into it". -->
				<NcButton
					variant="secondary"
					:disabled="vaultLocked || offlineReadOnly"
					data-testid="open-credential-request"
					@click="credentialRequestOpen = true">
					<template #icon>
						<AccountQuestion :size="20" />
					</template>
					{{ t('keepiq', 'Ask for a credential') }}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="vaultLocked || offlineReadOnly"
					data-testid="import-secrets"
					@click="openImport">
					<template #icon>
						<Import :size="20" />
					</template>
					{{ t('keepiq', 'Import') }}
				</NcButton>

				<!-- Team folder sharing (team-folder-sharing §5.1): only for a
				     concrete selected folder; the dialog owns membership + fan-out. -->
				<NcButton
					v-if="selectedFolderId"
					variant="secondary"
					:disabled="vaultLocked"
					data-testid="team-folder-open"
					@click="teamFolderOpen = true">
					<template #icon>
						<AccountGroup :size="20" />
					</template>
					{{ t('keepiq', 'Team sharing') }}
				</NcButton>

				<!-- Secret-type filter (passkey-item-type §3.3): show only one
				     type, e.g. passkeys. Server-side via the typeId param. -->
				<!-- v9 NcSelect models through `modelValue` and emits ONLY
				     `update:modelValue`. The Vue-2 pair (`:value` + `@input`)
				     is dead on BOTH sides: the filter never showed a selection
				     and selecting a type never filtered. -->
				<NcSelect
					class="secret-list-view__type-filter"
					:modelValue="typeFilterOption"
					:options="typeFilterOptions"
					:inputLabel="t('keepiq', 'Type')"
					:clearable="true"
					:placeholder="t('keepiq', 'All types')"
					data-testid="secret-type-filter"
					@update:modelValue="
						onTypeFilter($event ? $event.value : null)
					" />

				<!-- Data export / GDPR / deletion entry points (secret-export-gdpr §6.5). -->
				<NcActions :menuName="t('keepiq', 'My data')">
					<NcActionButton
						data-testid="open-new-send"
						@click="newSendOpen = true">
						{{ t('keepiq', 'New ephemeral send') }}
					</NcActionButton>
					<NcActionButton
						data-testid="open-my-sends"
						@click="mySendsOpen = true">
						{{ t('keepiq', 'My ephemeral sends') }}
					</NcActionButton>
					<NcActionButton @click="openExport">
						{{ t('keepiq', 'Export data') }}
					</NcActionButton>
					<NcActionButton
						:disabled="vaultLocked"
						data-testid="cxp-transfer"
						@click="openCxp">
						{{ t('keepiq', 'Encrypted transfer (CXP)') }}
					</NcActionButton>
					<NcActionButton @click="openGdpr">
						{{ t('keepiq', 'GDPR export') }}
					</NcActionButton>
					<NcActionButton @click="deletionOpen = true">
						{{ t('keepiq', 'Delete my Keepiq data') }}
					</NcActionButton>
				</NcActions>
			</div>

			<ExportDialog
				:open="exportOpen"
				:secrets="decryptedSecrets"
				:folders="folders"
				@update:open="exportOpen = $event" />
			<CxpTransferDialog
				:open="cxpOpen"
				:secrets="decryptedSecrets"
				:folders="folders"
				@update:open="cxpOpen = $event"
				@openImport="importOpen = true" />
			<GdprExportDialog
				:open="gdprOpen"
				:secrets="decryptedSecrets"
				:folders="folders"
				@update:open="gdprOpen = $event" />
			<AccountDeletionDialog
				:open="deletionOpen"
				@update:open="deletionOpen = $event"
				@exportFirst="onExportFirst" />
			<ImportWizardDialog
				:open="importOpen"
				@update:open="importOpen = $event"
				@imported="onImported" />
			<TeamFolderDialog
				:open="teamFolderOpen"
				:folderId="selectedFolderId"
				:folderName="selectedFolderName"
				@update:open="teamFolderOpen = $event" />

			<!-- Ephemeral send (ephemeral-send §5). -->
			<NewSendDialog
				v-if="newSendOpen"
				:open="true"
				@close="newSendOpen = false" />
			<MySendsDialog
				v-if="mySendsOpen"
				:open="true"
				@close="mySendsOpen = false" />

			<!-- Bulk action dialogs (bulk-actions §3). -->
			<BulkMoveDialog
				v-if="bulkDialog === 'move'"
				:open="true"
				@close="closeBulkDialog"
				@done="onBulkDone" />
			<BulkDeleteDialog
				v-if="bulkDialog === 'delete'"
				:open="true"
				@close="closeBulkDialog"
				@done="onBulkDone" />
			<BulkShareDialog
				v-if="bulkDialog === 'share'"
				:open="true"
				@close="closeBulkDialog"
				@done="onBulkDone" />
			<BulkTeamFolderDialog
				v-if="bulkDialog === 'teamFolder'"
				:open="true"
				@close="closeBulkDialog"
				@done="onBulkDone" />

			<!-- Bulk action bar (bulk-actions §3.1): visible only while a
			     selection is active; selection is client-only (§1.2). -->
			<div
				v-if="bulkStore.selectionCount > 0"
				class="secret-list-view__bulk-bar"
				data-testid="bulk-action-bar">
				<span data-testid="bulk-selection-count">
					{{
						t('keepiq', '{count} selected', {
							count: bulkStore.selectionCount,
						})
					}}
				</span>
				<NcButton
					variant="secondary"
					data-testid="bulk-open-move"
					@click="bulkDialog = 'move'">
					{{ t('keepiq', 'Move') }}
				</NcButton>
				<NcButton
					variant="secondary"
					data-testid="bulk-open-share"
					@click="bulkDialog = 'share'">
					{{ t('keepiq', 'Share') }}
				</NcButton>
				<NcButton
					variant="secondary"
					data-testid="bulk-open-team-folder"
					@click="bulkDialog = 'teamFolder'">
					{{ t('keepiq', 'Add to team folder') }}
				</NcButton>
				<NcButton
					variant="error"
					data-testid="bulk-open-delete"
					@click="bulkDialog = 'delete'">
					{{ t('keepiq', 'Delete') }}
				</NcButton>
				<NcButton
					variant="tertiary"
					data-testid="bulk-clear-selection"
					@click="bulkStore.clearSelection()">
					{{ t('keepiq', 'Clear selection') }}
				</NcButton>
			</div>

			<CnIndexPage
				viewMode="list"
				:availableViewModes="['list', 'cards', 'table']"
				listLabel="List"
				:selectable="false"
				:objects="secrets"
				:schema="listSchema"
				:loading="loading"
				:pagination="pagination"
				:addLabel="offlineReadOnly ? '' : t('keepiq', 'New secret')"
				addIcon="Plus"
				inlineSearch
				:searchValue="searchTerm"
				:searchPlaceholder="t('keepiq', 'Search secrets')"
				showSortSelect
				:sortSelectOptions="sortOptions"
				:sortSelectValue="sortField"
				rowKey="id"
				:emptyText="t('keepiq', 'No secrets yet')"
				@add="openCreateSecret"
				@search="onSearch"
				@sortChange="onSort"
				@pageChanged="goToPage">
				<template #list-item="{ object }">
					<div class="secret-list-view__row">
						<!-- Per-row selection (bulk-actions §1.1): click
						     toggles, shift-click selects the range from the
						     last-clicked row in the current view. -->
						<input
							type="checkbox"
							class="secret-list-view__check"
							:checked="bulkStore.selectedIds.includes(object.id)"
							:aria-label="
								t('keepiq', 'Select {name}', { name: object.name })
							"
							:data-testid="`bulk-check-${object.id}`"
							@click="onRowCheck(object, $event)" />
						<SecretListItem
							class="secret-list-view__row-item"
							:secret="object"
							:requestState="requestStateFor(object)"
							@open="openSecret"
							@copied="onCopied" />
					</div>
				</template>
				<template #actions>
					<!-- Select-all for the current filtered/paginated view. -->
					<label class="secret-list-view__select-all">
						<input
							type="checkbox"
							:checked="allCurrentSelected"
							data-testid="bulk-select-all"
							@change="onSelectAll" />
						<span>{{ t('keepiq', 'Select all') }}</span>
					</label>
				</template>
			</CnIndexPage>
		</div>
		<!-- No `:secret` prop: the dialog creates the placeholder itself. -->
		<SecretRequestCreateDialog
			v-if="credentialRequestOpen"
			:open="credentialRequestOpen"
			data-testid="credential-request-dialog"
			@update:open="credentialRequestOpen = $event"
			@created="onCredentialRequested" />
	</div>
</template>

<script>
import { CnFolderSidebar, CnIndexPage } from '@conduction/nextcloud-vue'
import { NcActionButton, NcActions, NcButton, NcSelect } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountQuestion from 'vue-material-design-icons/AccountQuestion.vue'
import FolderPlus from 'vue-material-design-icons/FolderPlus.vue'
import Import from 'vue-material-design-icons/Import.vue'
import SecretListItem from '../components/SecretListItem.vue'
import AccountDeletionDialog from '../dialogs/AccountDeletionDialog.vue'
import BulkDeleteDialog from '../dialogs/BulkDeleteDialog.vue'
import BulkMoveDialog from '../dialogs/BulkMoveDialog.vue'
import BulkShareDialog from '../dialogs/BulkShareDialog.vue'
import BulkTeamFolderDialog from '../dialogs/BulkTeamFolderDialog.vue'
import CxpTransferDialog from '../dialogs/CxpTransferDialog.vue'
import ExportDialog from '../dialogs/ExportDialog.vue'
import GdprExportDialog from '../dialogs/GdprExportDialog.vue'
import ImportWizardDialog from '../dialogs/ImportWizardDialog.vue'
import SecretRequestCreateDialog from '../dialogs/SecretRequestCreateDialog.vue'
import MySendsDialog from '../modals/MySendsDialog.vue'
import NewSendDialog from '../modals/NewSendDialog.vue'
import TeamFolderDialog from '../modals/TeamFolderDialog.vue'
import { useBulkStore } from '../store/modules/bulk.js'
import { useFolderStore } from '../store/modules/folder.js'
import { useHealthStore } from '../store/modules/health.js'
import { useOfflineStore } from '../store/modules/offline.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretRequestStore } from '../store/modules/secretRequest.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
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
		SecretRequestCreateDialog,
		AccountQuestion,
		CnIndexPage,
		CnFolderSidebar,
		NcActionButton,
		NcActions,
		NcButton,
		NcSelect,
		FolderPlus,
		Import,
		AccountGroup,
		SecretListItem,
		ExportDialog,
		CxpTransferDialog,
		GdprExportDialog,
		AccountDeletionDialog,
		ImportWizardDialog,
		TeamFolderDialog,
		BulkMoveDialog,
		BulkDeleteDialog,
		BulkShareDialog,
		BulkTeamFolderDialog,
		NewSendDialog,
		MySendsDialog,
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
			credentialRequestOpen: false,
			searchTerm: '',
			sortField: 'name',
			searchTimer: null,
			exportOpen: false,
			cxpOpen: false,
			gdprOpen: false,
			deletionOpen: false,
			importOpen: false,
			teamFolderOpen: false,
			typeFilter: null,
			decryptedSecrets: [],
			bulkDialog: null,
			lastCheckedId: null,
			newSendOpen: false,
			mySendsOpen: false,
		}
	},

	computed: {
		/**
		 * Secret id -> its pending request, for the row indicator.
		 *
		 * @return {object} Map of secretId to the pending request.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-outstanding-request-indicator
		 */
		pendingRequestsBySecret() {
			const map = {}
			for (const r of useSecretRequestStore().secretRequests || []) {
				if (r && r.status === 'pending' && r.secretId) {
					map[r.secretId] = r
				}
			}

			return map
		},

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

		/**
		 * Whether the vault is being served from the offline cache and is
		 * therefore read-only (offline-readonly-cache §4.2). All write controls
		 * are disabled while true.
		 *
		 * @return {boolean}
		 */
		offlineReadOnly() {
			return useOfflineStore().readOnly
		},

		selectedFolderId() {
			return this.$route.params.folderId || null
		},

		/** Display name of the selected folder for the team-sharing dialog. */
		selectedFolderName() {
			const folder = this.folders.find((f) => f.id === this.selectedFolderId)
			return folder?.name ?? ''
		},

		pagination() {
			return {
				page: this.secretStore.page,
				pages: Math.max(
					1,
					Math.ceil(this.secretStore.totalCount / PAGE_SIZE),
				),

				total: this.secretStore.totalCount,
				limit: PAGE_SIZE,
			}
		},

		/** Minimal schema so CnIndexPage can offer cards/table fallbacks. */
		listSchema() {
			return {
				properties: {
					name: { title: t('keepiq', 'Name'), type: 'string' },
					url: { title: t('keepiq', 'URL'), type: 'string' },
				},

				configuration: {
					objectNameField: 'name',
					objectDescriptionField: 'url',
				},
			}
		},

		sortOptions() {
			return [
				{ value: 'name', label: t('keepiq', 'Name') },
				{ value: 'url', label: t('keepiq', 'URL') },
				{ value: 'created_at', label: t('keepiq', 'Created') },
				{ value: 'updated_at', label: t('keepiq', 'Updated') },
			]
		},

		/** Options for the secret-type filter (passkey-item-type §3.3). */
		typeFilterOptions() {
			return useSecretTypeStore().types.map((type) => ({
				value: type.id,
				label: type.label || type.name,
			}))
		},

		/** The currently selected type-filter option object (or null). */
		typeFilterOption() {
			return (
				this.typeFilterOptions.find((opt) => opt.value === this.typeFilter)
				?? null
			)
		},

		/** Bulk selection store (bulk-actions §1). */
		bulkStore() {
			return useBulkStore()
		},

		/** Whether every secret in the current view is selected. */
		allCurrentSelected() {
			return (
				this.secrets.length > 0
				&& this.secrets.every((s) =>
					this.bulkStore.selectedIds.includes(s.id),
				)
			)
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
		// The bulk selection is client-only and dies with the lock (§1.2).
		this.bulkStore.registerLockReset()
		// allSettled (not all): offline, the type/folder fetches fall back to
		// the cache but must never block the secret-list load if one rejects
		// (offline-readonly-cache §4.2).
		await Promise.allSettled([
			useSecretTypeStore().fetchTypes(),
			this.folderStore.fetchFolders(),
			// Drives the outstanding-request badge. allSettled, so a failure here
			// costs the badge and never the list itself.
			useSecretRequestStore().fetchRequests(),
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
		 * Per-row selection toggle with shift-click range support
		 * (bulk-actions §1.1): shift extends from the last-clicked row
		 * to this one within the current view's order.
		 *
		 * @param {object} object The clicked row's secret.
		 * @param {MouseEvent} event The click event (shiftKey).
		 * @return {void}
		 */
		onRowCheck(object, event) {
			const ids = new Set(this.bulkStore.selectedIds)
			if (event.shiftKey && this.lastCheckedId) {
				const order = this.secrets.map((s) => s.id)
				const from = order.indexOf(this.lastCheckedId)
				const to = order.indexOf(object.id)
				if (from !== -1 && to !== -1) {
					for (const id of order.slice(
						Math.min(from, to),
						Math.max(from, to) + 1,
					)) {
						ids.add(id)
					}
					this.bulkStore.setSelection([...ids])
					this.lastCheckedId = object.id
					return
				}
			}

			if (ids.has(object.id)) {
				ids.delete(object.id)
			} else {
				ids.add(object.id)
			}
			this.bulkStore.setSelection([...ids])
			this.lastCheckedId = object.id
		},

		/**
		 * Select-all / clear-all for the current filtered view (§1.1).
		 *
		 * @param {Event} event The checkbox change event.
		 * @return {void}
		 */
		onSelectAll(event) {
			const ids = new Set(this.bulkStore.selectedIds)
			for (const secret of this.secrets) {
				if (event.target.checked) {
					ids.add(secret.id)
				} else {
					ids.delete(secret.id)
				}
			}
			this.bulkStore.setSelection([...ids])
		},

		/** Close whichever bulk dialog is open. */
		closeBulkDialog() {
			this.bulkDialog = null
		},

		/**
		 * A bulk run finished: refresh the list so moved/deleted rows
		 * reflect reality; keep the dialog open to show the report.
		 *
		 * @return {Promise<void>}
		 */
		async onBulkDone() {
			await this.reload()
		},

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
			// Pull the WHOLE vault (the export covers everything, not just the
			// paginated view); paged within the server's per-request cap.
			await store.fetchAllSecrets()
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
		 * Open the CXP encrypted-transfer dialog; decrypt the vault so the send
		 * direction can assemble + seal the CXF payload client-side.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/cxp-transfer/specs/cxp-transfer/spec.md
		 */
		async openCxp() {
			this.decryptedSecrets = await this.decryptAllSecrets()
			this.cxpOpen = true
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
		/**
		 * The outstanding-request state for a row, or null.
		 *
		 * Distinguishes a Secret that cannot be used yet from one that works until
		 * new values arrive — the consequences differ, so one badge for both would
		 * be worse than none. Returns null once the request is no longer pending,
		 * which is what clears the badge on fulfilment, revocation or expiry.
		 *
		 * @param {object} secret The row's secret.
		 *
		 * @return {string|null} 'awaiting-fill', 're-request' or null.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-outstanding-request-indicator
		 */
		requestStateFor(secret) {
			if (!secret || !this.pendingRequestsBySecret[secret.id]) {
				return null
			}

			return String(secret.key || '') === '' ? 'awaiting-fill' : 're-request'
		},

		/**
		 * A credential was requested from the vault level.
		 *
		 * The request created its own placeholder Secret, so the list must refetch
		 * to show it — otherwise the row only appears after a manual reload and
		 * the user cannot see what they just asked for.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-outstanding-request-indicator
		 */
		async onCredentialRequested() {
			// Deliberately NOT closing: the dialog has just computed the fill link
			// and is showing it with a Copy button and its own Done action. Closing
			// here unmounts it one tick later and destroys the only copy of the URL
			// the requester will ever be offered.
			// Both: the placeholder Secret is new, and its request drives the badge.
			await Promise.all([
				this.reload(),
				useSecretRequestStore().fetchRequests(),
			])
		},

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
				health
					.analyseVault({
						stalenessThreshold: '365',
						breachEnabled: false,
					})
					.catch(() => {})
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
				typeId: this.typeFilter,
				page: 1,
			})
		},

		/**
		 * Type-filter change handler (passkey-item-type §3.3).
		 *
		 * @param {string|null} typeId The selected type id (null = all).
		 * @return {void}
		 */
		onTypeFilter(typeId) {
			this.typeFilter = typeId
			this.reload()
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

.secret-list-view__bulk-bar {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	padding: 8px 12px;
	margin-bottom: 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover, #f5f5f5);
}

.secret-list-view__row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.secret-list-view__check {
	flex: 0 0 auto;
	width: 18px;
	height: 18px;
	cursor: pointer;
}

.secret-list-view__row-item {
	flex: 1 1 auto;
	min-width: 0;
}

.secret-list-view__select-all {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
}
</style>
