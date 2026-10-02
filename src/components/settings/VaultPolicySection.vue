<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin section for the vault policies (admin-vault-policies §1.3): block
  personal vault export, require Nextcloud two-factor login before unlock,
  and keep work logins in team folders. Each is off by default and applies
  to everyone or to the chosen groups. Before the two-factor policy is
  switched on, the section says how many users in scope have no second
  factor yet, because they lose vault access at once.

  @spec openspec/changes/admin-vault-policies/tasks.md#1.3
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Vault policies')"
		:description="
			t(
				'keepiq',
				'Rules for every vault. Each applies to everyone, or only to the groups you choose.',
			)
		">
		<div class="vault-policy" data-testid="vault-policy-section">
			<NcNoteCard v-if="error" type="error" data-testid="vault-policy-error">
				{{ error }}
			</NcNoteCard>

			<div
				v-for="policy in policies"
				:key="policy.key"
				class="vault-policy__item">
				<label class="vault-policy__check">
					<input
						v-model="values[policy.key]"
						type="checkbox"
						:data-testid="`vault-policy-${policy.key}`"
						@change="onToggle(policy.key)" />
					<span>{{ policy.label }}</span>
				</label>
				<p class="vault-policy__hint">
					{{ policy.hint }}
				</p>
				<NcSelect
					v-model="values[policy.key + '_groups']"
					:options="groupOptions"
					:inputLabel="
						t('keepiq', 'Only for these groups (empty is everyone)')
					"
					multiple
					:data-testid="`vault-policy-${policy.key}-groups`"
					@update:modelValue="onGroupsChange(policy.key)" />
				<NcSelect
					v-if="policy.key === 'vault_org_ownership'"
					v-model="values.vault_org_ownership_types"
					:options="typeOptions"
					:inputLabel="
						t('keepiq', 'Secret types that belong in a team folder')
					"
					multiple
					data-testid="vault-policy-ownership-types"
					@update:modelValue="save" />
				<NcNoteCard
					v-if="
						policy.key === 'vault_require_two_factor'
						&& gaps !== null
						&& gaps.withoutTwoFactor > 0
					"
					type="warning"
					data-testid="vault-policy-two-factor-gaps">
					{{
						n(
							'keepiq',
							'%n user in scope has no two-factor login yet and cannot open the vault while this is on.',
							'%n users in scope have no two-factor login yet and cannot open the vault while this is on.',
							gaps.withoutTwoFactor,
						)
					}}
				</NcNoteCard>
			</div>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcNoteCard, NcSelect } from '@nextcloud/vue'
import { useGroupStore } from '../../store/modules/group.js'
import { useSecretTypeStore } from '../../store/modules/secretType.js'

const KEYS = [
	'vault_export_disabled',
	'vault_export_disabled_groups',
	'vault_require_two_factor',
	'vault_require_two_factor_groups',
	'vault_org_ownership',
	'vault_org_ownership_groups',
	'vault_org_ownership_types',
]

export default {
	name: 'VaultPolicySection',
	components: { CnSettingsSection, NcNoteCard, NcSelect },

	data() {
		return {
			values: {
				vault_export_disabled: false,
				vault_export_disabled_groups: [],
				vault_require_two_factor: false,
				vault_require_two_factor_groups: [],
				vault_org_ownership: false,
				vault_org_ownership_groups: [],
				vault_org_ownership_types: ['login', 'api_key', 'database'],
			},

			/** @type {{inScope: number, withoutTwoFactor: number}|null} */
			gaps: null,
			error: null,
		}
	},

	computed: {
		/**
		 * The three policies with their labels.
		 *
		 * @return {Array<{key: string, label: string, hint: string}>}
		 * @spec openspec/changes/admin-vault-policies/tasks.md#1.3
		 */
		policies() {
			return [
				{
					key: 'vault_export_disabled',
					label: this.t('keepiq', 'Block personal vault export'),
					hint: this.t(
						'keepiq',
						'Users cannot download a backup, CSV or transfer file. Their personal data package stays available.',
					),
				},
				{
					key: 'vault_require_two_factor',
					label: this.t(
						'keepiq',
						'Require two-factor login before the vault opens',
					),

					hint: this.t(
						'keepiq',
						'Backup codes do not count. If your users sign in through an identity provider with its own second factor, leave their groups out.',
					),
				},
				{
					key: 'vault_org_ownership',
					label: this.t('keepiq', 'Keep work logins in team folders'),
					hint: this.t(
						'keepiq',
						'Users cannot save these secret types in a personal folder.',
					),
				},
			]
		},

		/**
		 * Group ids for the scope pickers.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/admin-vault-policies/tasks.md#1.3
		 */
		groupOptions() {
			return useGroupStore().groups.map((group) =>
				typeof group === 'string' ? group : group.id,
			)
		},

		/**
		 * Secret type names for the ownership picker.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/admin-vault-policies/tasks.md#1.3
		 */
		typeOptions() {
			return useSecretTypeStore().types.map((type) => type.name)
		},
	},

	/**
	 * Load the policies, the groups, the types and the two-factor gap count.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/admin-vault-policies/tasks.md#1.3
	 */
	async created() {
		useGroupStore()
			.fetchGroups()
			.catch(() => {})
		const typeStore = useSecretTypeStore()
		if (typeStore.types.length === 0) {
			typeStore.fetchTypes().catch(() => {})
		}
		try {
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/settings/admin'),
			)
			for (const key of KEYS) {
				if (response.data?.[key] !== undefined) {
					this.values[key] = response.data[key]
				}
			}
		} catch (e) {
			this.error = e?.response?.data?.message || e?.message
		}
		await this.loadGaps()
	},

	methods: {
		/**
		 * Count the users in the two-factor scope without a second factor.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-vault-policies/tasks.md#1.3
		 */
		async loadGaps() {
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/settings/admin/two-factor-gaps'),
					{
						params: {
							groups: this.values.vault_require_two_factor_groups,
						},
					},
				)
				this.gaps = response.data
			} catch {
				this.gaps = null
			}
		},

		/**
		 * Save after a switch changed.
		 *
		 * @param {string} key The policy key.
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-vault-policies/tasks.md#1.3
		 */
		onToggle(key) {
			return this.save(key)
		},

		/**
		 * Save after a group scope changed; recount for the two-factor scope.
		 *
		 * @param {string} key The policy key.
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-vault-policies/tasks.md#1.3
		 */
		async onGroupsChange(key) {
			await this.save(key)
			if (key === 'vault_require_two_factor') {
				await this.loadGaps()
			}
		},

		/**
		 * Persist the vault policy keys; the server validates and audits.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-vault-policies/tasks.md#1.3
		 */
		async save() {
			this.error = null
			const payload = {}
			for (const key of KEYS) {
				payload[key] = this.values[key]
			}
			try {
				await axios.put(
					generateUrl('/apps/keepiq/api/settings/admin'),
					payload,
				)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},
	},
}
</script>

<style scoped>
.vault-policy {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 560px;
}

.vault-policy__item {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.vault-policy__check {
	display: flex;
	align-items: center;
	gap: 8px;
}

.vault-policy__hint {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
