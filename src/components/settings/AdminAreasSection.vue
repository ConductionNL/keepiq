<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The "Admin areas" note in the General area (admin-scoped-roles §3.3):
  lists the five delegable areas and what each covers, and links to
  Nextcloud's administration privileges page. The notice about the former
  vault_admin group is gone with its alias (#1043).

  @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#3.3
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Admin areas')"
		:description="
			t(
				'keepiq',
				'Give a group only the parts of Keepiq administration it needs.',
			)
		">
		<div class="admin-areas" data-testid="admin-areas-section">
			<p>
				{{
					t(
						'keepiq',
						'Delegate one or more areas to a group on the administration privileges page. Instance administrators hold every area.',
					)
				}}
			</p>
			<ul class="admin-areas__list">
				<li
					v-for="area in areas"
					:key="area.key"
					:data-testid="'admin-area-' + area.key">
					<strong>{{ area.name }}</strong
					>: {{ area.covers }}
				</li>
			</ul>
			<a
				:href="privilegesUrl"
				class="admin-areas__link"
				data-testid="admin-areas-privileges-link">
				{{ t('keepiq', 'Open administration privileges') }}
			</a>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'AdminAreasSection',
	components: { CnSettingsSection },

	computed: {
		/**
		 * The five areas with what each one covers.
		 *
		 * @return {Array<{key: string, name: string, covers: string}>} The areas
		 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#3.3
		 */
		areas() {
			return [
				{
					key: 'general',
					name: this.t('keepiq', 'General'),
					covers: this.t(
						'keepiq',
						'version, certificate authority, attachments, offline cache, breach check, secret types and backups',
					),
				},
				{
					key: 'policies',
					name: this.t('keepiq', 'Policies'),
					covers: this.t(
						'keepiq',
						'master password, organisation password, vault policies, rotation, version history and trash',
					),
				},
				{
					key: 'applications',
					name: this.t('keepiq', 'Applications and machine access'),
					covers: this.t(
						'keepiq',
						'application queue, application requests and machine leases',
					),
				},
				{
					key: 'people',
					name: this.t('keepiq', 'People and offboarding'),
					covers: this.t(
						'keepiq',
						'team offboarding, encryption suites and admin handover',
					),
				},
				{
					key: 'audit',
					name: this.t('keepiq', 'Audit and compliance'),
					covers: this.t(
						'keepiq',
						'audit log, compliance reports, SIEM export and honey alerts',
					),
				},
			]
		},

		/**
		 * Nextcloud's administration privileges page.
		 *
		 * @return {string} The URL
		 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#3.3
		 */
		privilegesUrl() {
			return generateUrl('/settings/admin/admindelegation')
		},
	},
}
</script>

<style scoped>
.admin-areas {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 640px;
}

.admin-areas__list {
	list-style: disc;
	padding-inline-start: 20px;
}

.admin-areas__link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
