<template>
	<div class="secret-list-view">
		<!-- Single pane (restyle Stage 7): folder navigation lives in the app
		     nav's folder tree (KeepiqAppNav/NavFolderTree) and in the list's
		     own subfolder rows + breadcrumbs (Stage 6); the in-page folder
		     sidebar is gone. -->
		<div class="secret-list-view__main">
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
				:objects="listObjects"
				:schema="listSchema"
				:loading="loading || folderSwitching"
				:pagination="pagination"
				showTitle
				:title="pageTitle"
				:addLabel="offlineReadOnly ? '' : t('keepiq', 'New secret')"
				addIcon="Plus"
				inlineSearch
				:searchValue="searchTerm"
				:searchPlaceholder="t('keepiq', 'Search secrets')"
				showSortSelect
				:sortSelectOptions="sortOptions"
				:sortSelectValue="sortField"
				rowKey="id"
				:emptyText="t('keepiq', 'No secrets found')"
				:refreshing="loading"
				@refresh="reload"
				@add="openCreateSecret"
				@search="onSearch"
				@sortChange="onSort"
				@pageChanged="goToPage">
				<!-- Title row (restyle Stage 8): the page title and the
				     toolbar share ONE row, aligned with the page content.
				     The toolbar is the Stage-5 declarative toolbarItems()
				     list — per-item `placement` decides between a visible
				     button and the single "More actions" overflow, which
				     carries the secondary actions, the "My data" entries
				     (secret-export-gdpr §6.5) and the secret-type filter
				     (passkey-item-type §3.3). data-testids and disabled
				     conditions are unchanged. -->
				<template #header="{ title }">
					<div class="secret-list-view__header">
						<CnPageHeader :title="title" />
						<div class="secret-list-view__actions">
							<NcButton
								v-for="item in visibleToolbarItems"
								:key="item.id"
								variant="secondary"
								:disabled="item.disabled"
								:data-testid="item.testid"
								@click="item.run()">
								<template #icon>
									<component :is="item.icon" :size="20" />
								</template>
								{{ item.label }}
							</NcButton>
							<NcActions
								:menuName="t('keepiq', 'More actions')"
								:forceMenu="true"
								:forceName="true"
								data-testid="more-actions">
								<NcActionButton
									v-for="item in overflowToolbarItems"
									:key="item.id"
									:disabled="item.disabled"
									:data-testid="item.testid"
									:closeAfterClick="true"
									@click="item.run()">
									<template #icon>
										<component :is="item.icon" :size="20" />
									</template>
									{{ item.label }}
								</NcActionButton>

								<!-- Data export / GDPR / deletion entry points
								     (secret-export-gdpr §6.5). -->
								<NcActionSeparator />
								<NcActionButton
									data-testid="open-new-send"
									:closeAfterClick="true"
									@click="newSendOpen = true">
									{{ t('keepiq', 'New ephemeral send') }}
								</NcActionButton>
								<NcActionButton
									data-testid="open-my-sends"
									:closeAfterClick="true"
									@click="mySendsOpen = true">
									{{ t('keepiq', 'My ephemeral sends') }}
								</NcActionButton>
								<NcActionButton
									:closeAfterClick="true"
									@click="openExport">
									{{ t('keepiq', 'Export data') }}
								</NcActionButton>
								<NcActionButton
									:disabled="vaultLocked"
									data-testid="cxp-transfer"
									:closeAfterClick="true"
									@click="openCxp">
									{{ t('keepiq', 'Encrypted transfer (CXP)') }}
								</NcActionButton>
								<NcActionButton
									:closeAfterClick="true"
									@click="openGdpr">
									{{ t('keepiq', 'GDPR export') }}
								</NcActionButton>
								<NcActionButton
									:closeAfterClick="true"
									@click="deletionOpen = true">
									{{ t('keepiq', 'Delete my Keepiq data') }}
								</NcActionButton>

								<!-- Secret-type filter (passkey-item-type §3.3):
								     show only one type, e.g. passkeys.
								     Server-side via the typeId param. -->
								<NcActionSeparator />
								<NcActionCaption
									:name="t('keepiq', 'Filter by type')" />
								<NcActionRadio
									name="secret-type-filter"
									value=""
									:modelValue="typeFilter ?? ''"
									data-testid="secret-type-filter"
									@update:modelValue="onTypeFilter(null)">
									{{ t('keepiq', 'All types') }}
								</NcActionRadio>
								<NcActionRadio
									v-for="option in typeFilterOptions"
									:key="option.value"
									name="secret-type-filter"
									:value="option.value"
									:modelValue="typeFilter ?? ''"
									data-testid="secret-type-filter"
									@update:modelValue="onTypeFilter(option.value)">
									{{ option.label }}
								</NcActionRadio>
							</NcActions>
						</div>
					</div>
				</template>
				<!-- Folder trail (restyle Stage 5): home crumb + parent walk to
				     the current folder (unlinked). Empty at the vault root, so
				     CnBreadcrumbs renders no landmark there. -->
				<template #below-header>
					<CnBreadcrumbs :crumbs="breadcrumbs" />
				</template>
				<!-- Rich empty state (restyle Stage 5). -->
				<template #empty>
					<NcEmptyContent
						:name="t('keepiq', 'No secrets found')"
						:description="
							t(
								'keepiq',
								'Add your first secret using the button above',
							)
						">
						<template #icon>
							<KeyVariant :size="64" />
						</template>
					</NcEmptyContent>
				</template>
				<template #list-item="{ object }">
					<!-- Subfolder row (restyle Stage 6): the current folder's
					     direct subfolders list ABOVE the secrets, file-manager
					     style; clicking one navigates into it. No bulk
					     checkbox — folders are not bulk-operable. -->
					<button
						v-if="object.isFolder"
						type="button"
						class="secret-list-view__folder-row"
						:data-testid="`folder-row-${object.folderId}`"
						@click="openFolder(object.folderId)">
						<!-- Root rows ARE the vaults → safe glyph; inside a
						     vault the rows are plain folders. -->
						<Safe v-if="!selectedFolderId" :size="20" />
						<FolderOutline v-else :size="20" />
						<span class="secret-list-view__folder-name">
							{{ object.name }}
						</span>
					</button>
					<div v-else class="secret-list-view__row">
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
import { CnBreadcrumbs, CnIndexPage, CnPageHeader } from '@conduction/nextcloud-vue'
import {
	NcActionButton,
	NcActionCaption,
	NcActionRadio,
	NcActions,
	NcActionSeparator,
	NcButton,
	NcEmptyContent,
} from '@nextcloud/vue'
import { markRaw } from 'vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountQuestion from 'vue-material-design-icons/AccountQuestion.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FolderPlus from 'vue-material-design-icons/FolderPlus.vue'
import Import from 'vue-material-design-icons/Import.vue'
import KeyVariant from 'vue-material-design-icons/KeyVariant.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Safe from 'vue-material-design-icons/Safe.vue'
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
import { secretDetailLocation } from '../utils/detailRoute.js'
import { subfolderRows } from '../utils/vaultList.js'

const PAGE_SIZE = 50

// Toolbar icon components, marked raw once so the declarative toolbarItems()
// entries never wrap component options in a reactive proxy.
const TOOLBAR_ICONS = {
	refresh: markRaw(Refresh),
	folderPlus: markRaw(FolderPlus),
	safe: markRaw(Safe),
	accountQuestion: markRaw(AccountQuestion),
	import: markRaw(Import),
	accountGroup: markRaw(AccountGroup),
}

/**
 * Hard ceiling on the breadcrumb parent walk. A well-formed tree never gets
 * near it; it exists so a corrupt parentId cycle that survives the seen-set
 * (e.g. duplicated ids) still terminates.
 */
const MAX_TRAIL_DEPTH = 32

/**
 * The main vault view: a folder sidebar plus a searchable, sortable,
 * paginated secret list, built on the shared CnIndexPage list view and
 * the app nav's folder tree. Names and urls are plaintext; encrypted fields are
 * decrypted only on demand (copy / detail) inside SecretListItem.
 */
export default {
	name: 'SecretList',

	components: {
		SecretRequestCreateDialog,
		CnBreadcrumbs,
		CnIndexPage,
		CnPageHeader,
		NcActionButton,
		NcActionCaption,
		NcActionRadio,
		NcActionSeparator,
		NcActions,
		NcButton,
		NcEmptyContent,
		FolderOutline,
		KeyVariant,
		Safe,
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
			/**
			 * True while a folder navigation's fetch is in flight. Blanks
			 * the list so CnIndexPage shows its full loading spinner
			 * (it only does so when loading AND empty) instead of the
			 * PREVIOUS folder's rows suddenly swapping for the new ones.
			 *
			 * Starts TRUE: root ↔ folder navigations REMOUNT this view
			 * (CnPageRenderer keys renders on the page id, and the two
			 * routes are different pages), and a fresh mount renders the
			 * store's previous rows before mounted() fetches anything —
			 * the watcher below never sees that transition.
			 */
			folderSwitching: true,
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

		/**
		 * Minimal schema so CnIndexPage can offer cards/table fallbacks.
		 *
		 * @return {object}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-secret-list-rows-must-be-keyboard-operable
		 */
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

		/**
		 * The sort fields the list offers, matching the columns the server
		 * accepts for the paged secrets query.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
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

		/**
		 * The current folder's direct subfolders as list rows (restyle
		 * Stage 6): root = the top-level vaults. Filtered by the inline
		 * search term — everything visible in the list is searchable —
		 * and name-sorted as one group.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		folderRows() {
			return subfolderRows(
				this.folders,
				this.selectedFolderId,
				this.searchTerm,
			)
		},

		/**
		 * What the list renders: subfolders first as a group, then the
		 * secrets (restyle Stage 6, file-manager style). Folder rows show
		 * only on the FIRST page — repeating them on every page of a long
		 * secret list would read as duplicates. Pagination totals keep
		 * counting secrets only.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		listObjects() {
			// Mid folder-switch: nothing — the old folder's rows must not
			// linger while the new folder's secrets are still loading.
			if (this.folderSwitching) {
				return []
			}
			if (this.secretStore.page > 1) {
				return this.secrets
			}
			return [...this.folderRows, ...this.secrets]
		},

		/**
		 * The page title: the selected folder's name, or "Secrets" at the
		 * vault root (restyle Stage 5).
		 *
		 * @return {string}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		pageTitle() {
			return this.selectedFolderName || t('keepiq', 'Secrets')
		},

		/**
		 * Level-appropriate create label (restyle terminology): a TOP-LEVEL
		 * folder is a Vault, a folder inside one is a folder.
		 *
		 * @return {string}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
		 */
		newFolderLabel() {
			return this.selectedFolderId
				? t('keepiq', 'New folder')
				: t('keepiq', 'New vault')
		},

		/**
		 * The selected folder's ancestor chain, root first, current folder
		 * last — walked through `parentId` (the field buildTree links on in
		 * the folder store). Guarded against parentId cycles (seen-set) and
		 * runaway depth.
		 *
		 * @return {Array<object>} Folder objects along the trail.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		folderTrail() {
			if (!this.selectedFolderId) {
				return []
			}
			const byId = new Map(this.folders.map((f) => [f.id, f]))
			const trail = []
			const seen = new Set()
			let current = byId.get(this.selectedFolderId)
			while (
				current
				&& !seen.has(current.id)
				&& trail.length < MAX_TRAIL_DEPTH
			) {
				seen.add(current.id)
				trail.unshift(current)
				current = current.parentId ? byId.get(current.parentId) : null
			}
			return trail
		},

		/**
		 * The breadcrumb trail (restyle Stage 5): a Home crumb back to the
		 * vault root, the ancestor folders as links, the current folder
		 * last (CnBreadcrumbs renders it unlinked with aria-current). Empty
		 * at the root — no trail is rendered there.
		 *
		 * @return {Array<object>} CnBreadcrumbs `crumbs` entries.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		breadcrumbs() {
			const trail = this.folderTrail
			if (trail.length === 0) {
				return []
			}
			return [
				{ icon: 'Home', to: { name: 'SecretList' } },
				...trail.map((folder, index) =>
					index === trail.length - 1
						? { label: folder.name }
						: {
								label: folder.name,
								to: {
									name: 'SecretListFolder',
									params: { folderId: folder.id },
								},
							},
				),
			]
		},

		/**
		 * The declarative toolbar (restyle Stage 5). Per-item `placement`
		 * ('visible' | 'overflow') is the ONE knob that moves an action
		 * between the visible row and the "More actions" overflow; ids,
		 * testids and disabled conditions are stable. "New secret" is not
		 * listed — it stays CnIndexPage's own add button.
		 *
		 * @return {Array<{id: string, label: string, icon: object, placement: string, disabled: boolean, testid: (string|undefined), run: Function}>}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		toolbarItems() {
			const items = [
				{
					id: 'refresh',
					label: t('keepiq', 'Refresh'),
					icon: TOOLBAR_ICONS.refresh,
					placement: 'visible',
					disabled: this.loading,
					testid: 'vault-refresh',
					run: () => this.reload(),
				},
				{
					id: 'new-folder',
					label: this.newFolderLabel,
					// A top-level create makes a VAULT, so the entry carries
					// the safe glyph there (restyle terminology).
					icon: this.selectedFolderId
						? TOOLBAR_ICONS.folderPlus
						: TOOLBAR_ICONS.safe,

					placement: 'overflow',
					disabled: this.offlineReadOnly,
					testid: 'open-create-folder',
					run: () => this.openCreateFolder(),
				},
				// Ask someone for a credential (request-first-secret-requests):
				// reachable with an EMPTY vault, because a requester has nothing
				// to point at yet. The Secret is created by the request itself,
				// so this is not "make a secret then request into it".
				{
					id: 'credential-request',
					label: t('keepiq', 'Ask for a credential'),
					icon: TOOLBAR_ICONS.accountQuestion,
					placement: 'overflow',
					disabled: this.vaultLocked || this.offlineReadOnly,
					testid: 'open-credential-request',
					run: () => {
						this.credentialRequestOpen = true
					},
				},
				{
					id: 'import',
					label: t('keepiq', 'Import'),
					icon: TOOLBAR_ICONS.import,
					placement: 'overflow',
					disabled: this.vaultLocked || this.offlineReadOnly,
					testid: 'import-secrets',
					run: () => this.openImport(),
				},
			]
			// Team folder sharing (team-folder-sharing §5.1): only for a
			// concrete selected folder; the dialog owns membership + fan-out.
			if (this.selectedFolderId) {
				items.push({
					id: 'team-sharing',
					label: t('keepiq', 'Team sharing'),
					icon: TOOLBAR_ICONS.accountGroup,
					placement: 'overflow',
					disabled: this.vaultLocked,
					testid: 'team-folder-open',
					run: () => {
						this.teamFolderOpen = true
					},
				})
			}
			return items
		},

		/**
		 * Toolbar items rendered as visible buttons.
		 *
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		visibleToolbarItems() {
			return this.toolbarItems.filter((item) => item.placement === 'visible')
		},

		/**
		 * Toolbar items rendered inside the "More actions" overflow.
		 *
		 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
		 */
		overflowToolbarItems() {
			return this.toolbarItems.filter((item) => item.placement === 'overflow')
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
		async selectedFolderId() {
			// Blank the list for the duration of the fetch so the switch
			// reads as loading → new folder, never old rows → new rows.
			this.folderSwitching = true
			try {
				await this.reload()
			} finally {
				this.folderSwitching = false
			}
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
		try {
			await this.reload()
		} finally {
			// Mount-time counterpart of the selectedFolderId watcher:
			// `folderSwitching` starts true so the spinner covers this whole
			// preamble instead of the store's previous rows.
			this.folderSwitching = false
		}
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
		 * Open a secret's detail sidebar (restyle Stage 8): the optional
		 * `:id?` segment joins the current list route, so the list stays
		 * mounted behind the sidebar and the folder context survives in
		 * the path.
		 *
		 * @param {string} id The secret ID.
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 */
		openSecret(id) {
			this.$router.push(secretDetailLocation(this.$route, id))
		},

		/**
		 * Navigate into a subfolder row (restyle Stage 6).
		 *
		 * @param {string} folderId The clicked folder's id.
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		openFolder(folderId) {
			this.$router.push(`/folders/${folderId}`)
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
	height: 100%;
	padding: 16px;
}

.secret-list-view__main {
	min-width: 0;
}

/* Title row (restyle Stage 8): page title left, toolbar right, one line. */
.secret-list-view__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
}

.secret-list-view__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
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

/* Subfolder rows (restyle Stage 6): full-width, file-manager style. */
.secret-list-view__folder-row {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	padding: 10px 12px;
	border: none;
	border-radius: var(--border-radius-large, 12px);
	background: transparent;
	cursor: pointer;
	text-align: start;
	font: inherit;
	color: inherit;
}

.secret-list-view__folder-row:hover,
.secret-list-view__folder-row:focus-visible {
	background-color: var(--color-background-hover, #f5f5f5);
}

.secret-list-view__folder-name {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-weight: 600;
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
