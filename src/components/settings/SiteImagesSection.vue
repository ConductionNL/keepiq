<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin section for the two site image templates (vault-website-addresses D4):
  the site icon in the vault list (`{domain}`) and the site preview in the
  detail sidebar (`{url}`). Both are empty by default, and the section says
  what leaves the instance when one is set. The server checks that a template
  is an https address with its placeholder; this form checks the same first.

  @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-site-icons-and-previews-are-opt-in-per-instance
-->
<template>
	<CnSettingsSection
		id="section-site-images"
		:name="t('keepiq', 'Site images')"
		:description="t('keepiq', 'Show an icon and a preview image of the website a login belongs to. Off until you set a service.')">
		<div class="site-images" data-testid="site-images-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard type="warning" data-testid="site-images-privacy">
				{{ t('keepiq', 'When a service is set, the browser of every vault user asks it for an image of each login it shows: the icon service receives the website\'s host name, the preview service the full website address. Leave both empty to keep these addresses inside this instance.') }}
			</NcNoteCard>
			<NcTextField
				v-model="favicon"
				:label="t('keepiq', 'Site icon service')"
				:helperText="t('keepiq', 'An https address with {placeholder} where the host name goes.', { placeholder: '{domain}' })"
				placeholder="https://icons.example.com/{domain}.png"
				data-testid="site-images-favicon"
				@blur="save" />
			<NcTextField
				v-model="preview"
				:label="t('keepiq', 'Site preview service')"
				:helperText="t('keepiq', 'An https address with {placeholder} where the website address goes.', { placeholder: '{url}' })"
				placeholder="https://preview.example.com/shot?url={url}"
				data-testid="site-images-preview"
				@blur="save" />
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcNoteCard, NcTextField } from '@nextcloud/vue'
import { adminSettingsForm } from '../../mixins/adminSettingsForm.js'
import { templateProblem } from '../../utils/siteImages.js'

export default {
	name: 'SiteImagesSection',
	components: { CnSettingsSection, NcNoteCard, NcTextField },
	mixins: [adminSettingsForm('/apps/keepiq/api/settings/admin/general')],

	data() {
		return {
			favicon: '',
			preview: '',
		}
	},

	methods: {
		/**
		 * Take the stored templates into the form.
		 *
		 * @param {object} data The stored settings.
		 * @return {void}
		 */
		readSettings(data) {
			this.favicon = data.favicon_service_url ?? ''
			this.preview = data.site_preview_service_url ?? ''
		},

		/**
		 * Why the form cannot be saved, or null.
		 *
		 * @return {string|null}
		 */
		validateSettings() {
			if (templateProblem(this.favicon, '{domain}') || templateProblem(this.preview, '{url}')) {
				return t('keepiq', 'A service must be an https address that contains its placeholder.')
			}
			return null
		},

		/**
		 * The templates to store, trimmed; empty switches a service off.
		 *
		 * @return {object}
		 */
		settingsPayload() {
			return {
				favicon_service_url: this.favicon.trim(),
				site_preview_service_url: this.preview.trim(),
			}
		},
	},
}
</script>

<style scoped>
.site-images {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 600px;
}
</style>
