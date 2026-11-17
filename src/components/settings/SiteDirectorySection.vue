<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin settings section for the site directory switch (health-site-security-checks
  D3). When on, the server downloads the public 2FA Directory and Passkeys
  Directory once a day and the password health report matches logins against
  them in the browser. Off by default, so an instance makes no surprise external
  call. The disclosure says what is fetched and that nothing is sent; the
  attribution is what both directories' licences ask for (design D2a).

  @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-2.3
-->
<template>
	<CnSettingsSection
		id="section-site-directory"
		:name="t('keepiq', 'Site directory')"
		:description="t('keepiq', 'Show users which of their sites offer one-time codes or passkeys they have not set up.')">
		<div class="site-directory">
			<label class="site-directory__toggle" for="site-directory-enabled">
				<input
					id="site-directory-enabled"
					v-model="enabled"
					type="checkbox"
					data-testid="site-directory-enabled"
					@change="save" />
				{{ t('keepiq', 'Use the site directory in the password health report') }}
			</label>
			<p class="site-directory__disclosure" data-testid="site-directory-privacy">
				{{ t('keepiq', 'Once a day the server downloads the whole public list from api.2fa.directory and passkeys-api.2fa.directory. Matching happens in each user\'s browser: no login, address or result is sent to the directories or stored on the server. Leave this off for air-gapped instances.') }}
			</p>
			<p class="site-directory__attribution" data-testid="site-directory-attribution">
				{{ t('keepiq', 'Data: 2FA Directory (MIT) and Passkeys Directory (CC BY 4.0), by 2factorauth and contributors.') }}
				<a href="https://2fa.directory/" target="_blank" rel="noopener noreferrer">2fa.directory</a>
				·
				<a href="https://passkeys.2fa.directory/" target="_blank" rel="noopener noreferrer">passkeys.2fa.directory</a>
			</p>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'SiteDirectorySection',
	components: { CnSettingsSection },

	data() {
		return {
			enabled: false,
		}
	},

	/**
	 * Load the current site directory switch.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-2.3
	 */
	async created() {
		try {
			const response = await axios.get(generateUrl('/apps/keepiq/api/settings/admin/general'))
			this.enabled = response.data?.site_directory_enabled === true
				|| response.data?.site_directory_enabled === '1'
		} catch {
			// The switch stays off when the settings cannot be read.
			this.enabled = false
		}
	},

	methods: {
		/**
		 * Persist the site directory switch.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-2.3
		 */
		async save() {
			await axios.put(generateUrl('/apps/keepiq/api/settings/admin/general'), {
				site_directory_enabled: this.enabled,
			})
		},
	},
}
</script>

<style scoped>
.site-directory__toggle {
	display: block;
	margin-bottom: 0.5rem;
}

.site-directory__disclosure,
.site-directory__attribution {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
</style>
