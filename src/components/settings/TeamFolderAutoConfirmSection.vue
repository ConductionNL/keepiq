<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin policy switch for automatic confirmation of new team folder members
  (admin-auto-confirm-members §1.2). Off by default. When on, the unlocked
  browser of the folder owner or a member with write access hands a new
  member their copies without a click. The server never decrypts. The
  change is audited as a policy change.

  @spec openspec/changes/admin-auto-confirm-members/tasks.md#1.2
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'New team folder members')"
		:description="t('keepiq', 'Give new team folder members access without waiting for the folder owner.')">
		<div class="auto-confirm" data-testid="auto-confirm-section">
			<NcNoteCard v-if="error" type="error" data-testid="auto-confirm-error">
				{{ error }}
			</NcNoteCard>
			<label class="auto-confirm__toggle" for="team-folder-auto-confirm">
				<input
					id="team-folder-auto-confirm"
					v-model="enabled"
					type="checkbox"
					data-testid="auto-confirm-enabled"
					@change="save" />
				{{ t('keepiq', 'Automatically confirm new team folder members') }}
			</label>
			<p class="auto-confirm__disclosure">
				{{ t('keepiq', 'The owner or a member with write access confirms them from their open vault. Keepiq never decrypts on the server.') }}
			</p>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'TeamFolderAutoConfirmSection',
	components: { CnSettingsSection, NcNoteCard },

	data() {
		return {
			enabled: false,
			error: null,
		}
	},

	/**
	 * Load the current switch.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/admin-auto-confirm-members/tasks.md#1.2
	 */
	async created() {
		try {
			const response = await axios.get(generateUrl('/apps/keepiq/api/settings/admin'))
			this.enabled = response.data?.team_folder_auto_confirm === true
		} catch (e) {
			this.error = e?.response?.data?.message || e?.message
		}
	},

	methods: {
		/**
		 * Save the switch on its own, so the policy audit records only it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-auto-confirm-members/tasks.md#1.2
		 */
		async save() {
			this.error = null
			try {
				await axios.put(generateUrl('/apps/keepiq/api/settings/admin'), {
					team_folder_auto_confirm: this.enabled,
				})
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},
	},
}
</script>

<style scoped>
.auto-confirm__toggle {
	display: block;
	margin-bottom: 0.5rem;
}

.auto-confirm__disclosure {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
</style>
