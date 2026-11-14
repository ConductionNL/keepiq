<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin policy switch for automatic confirmation of new team folder members
  (admin-auto-confirm-members §1.2). Off by default. When on, the unlocked
  browser of the folder owner or a member with write access hands a new
  member their copies without a click. The server never decrypts. The
  change is audited as a policy change.

  @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-administrator-switches-automatic-member-confirmation-on
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'New team folder members')"
		:description="
			t(
				'keepiq',
				'Give new team folder members access without waiting for the folder owner.',
			)
		">
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
				{{
					t(
						'keepiq',
						'The owner or a member with write access confirms them from their open vault. Keepiq never decrypts on the server.',
					)
				}}
			</p>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcNoteCard } from '@nextcloud/vue'
import { adminSettingsForm } from '../../mixins/adminSettingsForm.js'

export default {
	name: 'TeamFolderAutoConfirmSection',
	components: { CnSettingsSection, NcNoteCard },
	mixins: [adminSettingsForm('/apps/keepiq/api/settings/admin/policies')],

	data() {
		return {
			enabled: false,
		}
	},

	methods: {
		/**
		 * Take the stored switch into the form.
		 *
		 * @param {object} data The stored settings.
		 * @return {void}
		 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-administrator-switches-automatic-member-confirmation-on
		 */
		readSettings(data) {
			this.enabled = data?.team_folder_auto_confirm === true
		},

		/**
		 * The switch to store.
		 *
		 * @return {object} The settings to store.
		 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-administrator-switches-automatic-member-confirmation-on
		 */
		settingsPayload() {
			return {
				team_folder_auto_confirm: this.enabled,
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
