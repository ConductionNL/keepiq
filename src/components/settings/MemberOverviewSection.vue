<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin "Members" section (admin-member-overview-and-offboarding §3.1).
  Lists every Nextcloud user with their vault status, filtered by status and
  searched by user id or display name. A row hands its user to the team
  offboarding section and its active suite id to the encryption suites
  section, through the member overview store. Metadata only: no certificate,
  key or ciphertext ever reaches this component.

  @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#3.1
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Members')"
		:description="
			t(
				'keepiq',
				'See which users have set up a vault. Start offboarding or revoke a suite from a row.',
			)
		">
		<div class="member-overview" data-testid="member-overview-section">
			<div class="member-overview__filters">
				<NcSelect
					v-model="statusOption"
					class="member-overview__filter"
					:options="statusOptions"
					label="label"
					:clearable="false"
					:inputLabel="t('keepiq', 'Vault status')"
					data-testid="member-overview-status"
					@update:modelValue="reload" />
				<NcTextField
					v-model="search"
					class="member-overview__filter"
					:label="t('keepiq', 'Search users')"
					data-testid="member-overview-search"
					@update:modelValue="reload" />
			</div>

			<NcNoteCard
				v-if="store.error"
				type="error"
				data-testid="member-overview-error">
				{{ t('keepiq', 'Could not load the members.') }}
			</NcNoteCard>

			<CnDataTable
				:columns="columns"
				:rows="store.members"
				:loading="store.loading"
				rowKey="userId"
				:emptyText="t('keepiq', 'No users match this filter.')"
				data-testid="member-overview-table">
				<template #column-vaultStatus="{ row }">
					<span :data-testid="'member-status-' + row.userId">{{
						statusLabel(row.vaultStatus)
					}}</span>
				</template>
				<template #column-hasEmergencyContact="{ row }">
					{{
						row.hasEmergencyContact
							? t('keepiq', 'Yes')
							: t('keepiq', 'No')
					}}
				</template>
				<template #row-actions="{ row }">
					<div class="member-overview__actions">
						<NcButton
							variant="tertiary"
							:data-testid="'member-offboard-' + row.userId"
							@click="store.prefillOffboarding(row.userId)">
							{{ t('keepiq', 'Offboard') }}
						</NcButton>
						<NcButton
							v-if="row.activeSuiteId"
							variant="tertiary"
							:data-testid="'member-revoke-' + row.userId"
							@click="store.prefillSuiteRevocation(row.activeSuiteId)">
							{{ t('keepiq', 'Revoke suite') }}
						</NcButton>
					</div>
				</template>
			</CnDataTable>

			<div class="member-overview__paging">
				<NcButton
					:disabled="offset === 0 || store.loading"
					data-testid="member-overview-previous"
					@click="page(-1)">
					{{ t('keepiq', 'Previous') }}
				</NcButton>
				<NcButton
					:disabled="!store.hasMore || store.loading"
					data-testid="member-overview-next"
					@click="page(1)">
					{{ t('keepiq', 'Next') }}
				</NcButton>
			</div>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnDataTable, CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	MEMBER_PAGE_SIZE,
	useMemberOverviewStore,
} from '../../store/modules/memberOverview.js'

export default {
	name: 'MemberOverviewSection',

	components: {
		CnDataTable,
		CnSettingsSection,
		NcButton,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			statusOption: null,
			search: '',
			offset: 0,
		}
	},

	computed: {
		/**
		 * The member overview store.
		 *
		 * @return {object}
		 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#3.1
		 */
		store() {
			return useMemberOverviewStore()
		},

		/**
		 * Status filter choices; the first means every status.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#3.1
		 */
		statusOptions() {
			return [
				{ id: '', label: this.t('keepiq', 'All statuses') },
				{ id: 'none', label: this.statusLabel('none') },
				{ id: 'active', label: this.statusLabel('active') },
				{ id: 'revoked', label: this.statusLabel('revoked') },
				{ id: 'compromised', label: this.statusLabel('compromised') },
			]
		},

		/**
		 * Table columns.
		 *
		 * @return {Array<{key: string, label: string}>}
		 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#3.1
		 */
		columns() {
			return [
				{ key: 'displayName', label: this.t('keepiq', 'User') },
				{ key: 'userId', label: this.t('keepiq', 'User ID') },
				{ key: 'vaultStatus', label: this.t('keepiq', 'Vault') },
				{ key: 'secretCount', label: this.t('keepiq', 'Secrets') },
				{
					key: 'teamFolderMemberships',
					label: this.t('keepiq', 'Team folders'),
				},
				{
					key: 'hasEmergencyContact',
					label: this.t('keepiq', 'Emergency contact'),
				},
			]
		},
	},

	/**
	 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#3.1
	 */
	created() {
		this.statusOption = this.statusOptions[0]
		this.store.fetchMembers({ status: '', search: '', offset: 0 })
	},

	methods: {
		/**
		 * The translated label for a vault status.
		 *
		 * @param {string} status One of none, active, revoked, compromised.
		 * @return {string}
		 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#3.1
		 */
		statusLabel(status) {
			return (
				{
					none: this.t('keepiq', 'Not set up'),
					active: this.t('keepiq', 'Active'),
					revoked: this.t('keepiq', 'Revoked'),
					compromised: this.t('keepiq', 'Compromised'),
				}[status] ?? status
			)
		},

		/**
		 * Load the first page again after the filter or search changed.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#3.1
		 */
		reload() {
			this.offset = 0
			return this.load()
		},

		/**
		 * Move one page forward or back.
		 *
		 * @param {number} direction 1 for next, -1 for previous.
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#3.1
		 */
		page(direction) {
			this.offset = Math.max(0, this.offset + direction * MEMBER_PAGE_SIZE)
			return this.load()
		},

		/**
		 * Fetch the current page with the current filter.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#3.1
		 */
		load() {
			return this.store.fetchMembers({
				status: this.statusOption?.id ?? '',
				search: this.search,
				offset: this.offset,
			})
		},
	},
}
</script>

<style scoped>
.member-overview {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.member-overview__filters {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	align-items: flex-end;
}

.member-overview__filter {
	min-width: 220px;
}

.member-overview__actions,
.member-overview__paging {
	display: flex;
	gap: 8px;
}
</style>
