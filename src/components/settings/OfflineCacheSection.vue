<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin settings section for the org-wide offline read-only cache switch
  (offline-readonly-cache §1.1 / §4.4). When enabled (default), each online
  unlock writes an encrypted snapshot the user can read offline; disabling
  it org-wide makes the manifest endpoint 403 and purges caches on next load.

  @spec openspec/specs/offline-readonly-cache/spec.md#requirement-an-admin-can-disable-offline-caching-org-wide
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Offline read-only cache')"
		:description="
			t(
				'keepiq',
				'Let users read their vault offline from an encrypted local snapshot refreshed on each unlock.',
			)
		">
		<div class="offline-cache">
			<label class="offline-cache__toggle" for="offline-cache-enabled">
				<input
					id="offline-cache-enabled"
					v-model="enabled"
					type="checkbox"
					data-testid="offline-cache-enabled"
					@change="save" />
				{{ t('keepiq', 'Enable offline caching for this instance') }}
			</label>
			<p class="offline-cache__disclosure">
				{{
					t(
						'keepiq',
						"The offline snapshot stores secret ciphertext (openable only with the user's master-password-derived key, exactly as on the server) and encrypts secret names, URLs and folder names at rest. Offline access is strictly read-only. Disable this for endpoints that must never cache credentials; disabling purges existing caches on next load.",
					)
				}}
			</p>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'OfflineCacheSection',
	components: { CnSettingsSection },

	data() {
		return {
			enabled: true,
		}
	},

	/**
	 * Load the current instance-wide offline-cache switch.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-an-admin-can-disable-offline-caching-org-wide
	 */
	async created() {
		try {
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/settings/admin'),
			)
			this.enabled =
				response.data?.offline_cache_enabled !== false
				&& response.data?.offline_cache_enabled !== '0'
		} catch (e) {
			console.warn('Keepiq: failed to load offline-cache switch', e)
		}
	},

	methods: {
		/**
		 * Persist the instance-wide offline-cache switch.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-an-admin-can-disable-offline-caching-org-wide
		 */
		async save() {
			await axios.put(generateUrl('/apps/keepiq/api/settings/admin'), {
				offline_cache_enabled: this.enabled,
			})
		},
	},
}
</script>

<style scoped>
.offline-cache__toggle {
	display: block;
	margin-bottom: 0.5rem;
}

.offline-cache__disclosure {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
</style>
