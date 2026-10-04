<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin section for federation partners (sharing-federated-recipients D1,
  task 1.3). The administrator types a partner address, Keepiq reads that
  partner's root fingerprint, and the administrator compares it with the
  partner's administrator before adding it. Each partner has two
  directions. Every change asks for the Nextcloud password again.

  @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Partner organisations')"
		:description="
			t(
				'keepiq',
				'Exchange secrets with another Keepiq. Both administrators add each other and compare the root fingerprints by phone or in person before saving.',
			)
		">
		<div class="federation-partners" data-testid="federation-partners-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard
				v-if="loaded && !supported"
				type="info"
				data-testid="federation-unsupported">
				{{ t('keepiq', 'Federation needs Nextcloud 33 or later.') }}
			</NcNoteCard>

			<template v-if="supported">
				<p v-if="localRootFingerprint" class="federation-partners__own">
					<span>{{ t('keepiq', 'Your root fingerprint') }}</span>
					<code data-testid="federation-own-fingerprint">{{
						formatFingerprint(localRootFingerprint)
					}}</code>
				</p>

				<p
					v-if="partners.length === 0"
					class="federation-partners__empty"
					data-testid="federation-no-partners">
					{{ t('keepiq', 'No partners yet.') }}
				</p>
				<ul v-else class="federation-partners__list">
					<li
						v-for="partner in partners"
						:key="partner.id"
						class="federation-partners__row"
						data-testid="federation-partner">
						<strong>{{ partner.baseUrl }}</strong>
						<code>{{ formatFingerprint(partner.rootFingerprint) }}</code>
						<NcCheckboxRadioSwitch
							:modelValue="partner.allowOutbound"
							type="switch"
							@update:modelValue="
								updatePartner(partner, { allowOutbound: $event })
							">
							{{ t('keepiq', 'Users here may share to this partner') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:modelValue="partner.allowInbound"
							type="switch"
							@update:modelValue="
								updatePartner(partner, { allowInbound: $event })
							">
							{{ t('keepiq', 'This partner may share to users here') }}
						</NcCheckboxRadioSwitch>
						<NcButton
							variant="tertiary"
							data-testid="federation-remove"
							@click="removePartner(partner)">
							{{ t('keepiq', 'Remove') }}
						</NcButton>
					</li>
				</ul>

				<div class="federation-partners__add">
					<NcTextField
						v-model="url"
						:label="t('keepiq', 'Partner address')"
						placeholder="https://cloud.partner.example"
						data-testid="federation-url" />
					<NcButton
						variant="secondary"
						:disabled="busy || url.trim() === ''"
						data-testid="federation-check"
						@click="check">
						{{ t('keepiq', 'Check partner') }}
					</NcButton>
				</div>

				<div
					v-if="preview"
					class="federation-partners__preview"
					data-testid="federation-preview">
					<p>
						<span>{{ t('keepiq', 'Partner root fingerprint') }}</span>
						<code>{{ formatFingerprint(preview.rootFingerprint) }}</code>
					</p>
					<NcCheckboxRadioSwitch
						v-model="compared"
						data-testid="federation-compared">
						{{
							t(
								'keepiq',
								"I compared this fingerprint with the partner's administrator",
							)
						}}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="allowOutbound" type="switch">
						{{ t('keepiq', 'Users here may share to this partner') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="allowInbound" type="switch">
						{{ t('keepiq', 'This partner may share to users here') }}
					</NcCheckboxRadioSwitch>
					<NcButton
						variant="primary"
						:disabled="busy || !compared"
						data-testid="federation-add"
						@click="add">
						{{ t('keepiq', 'Add partner') }}
					</NcButton>
				</div>
			</template>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'

const BASE = '/apps/keepiq/api/v1/federation/partners'

/**
 * Ask for the Nextcloud password before a change, as the server requires.
 *
 * @return {Promise<void>}
 */
async function confirmPasswordFirst() {
	const { confirmPassword } = await import('@nextcloud/password-confirmation')
	await confirmPassword()
}

export default {
	name: 'FederationPartnersSection',
	components: {
		CnSettingsSection,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			loaded: false,
			supported: false,
			localRootFingerprint: null,
			partners: [],
			url: '',
			preview: null,
			compared: false,
			allowOutbound: true,
			allowInbound: true,
			busy: false,
			error: null,
		}
	},

	/**
	 * Load the partners.
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	async created() {
		await this.load()
	},

	methods: {
		/**
		 * @param {string} hex A lowercase hex SHA-256.
		 * @return {string} Upper case, colon separated, as people read it aloud.
		 * @spec exclude Display formatting only.
		 */
		formatFingerprint(hex) {
			return (String(hex || '').match(/.{2}/g) || []).join(':').toUpperCase()
		},

		/**
		 * @param {Error} e A failed request.
		 * @return {void}
		 * @spec exclude Error display only.
		 */
		fail(e) {
			this.error = e?.response?.data?.message || e?.message || null
		},

		/**
		 * Read the partners, the own fingerprint and the version gate.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
		 */
		async load() {
			try {
				const { data } = await axios.get(generateUrl(BASE))
				this.supported = data?.supported === true
				this.localRootFingerprint = data?.localRootFingerprint ?? null
				this.partners = Array.isArray(data?.partners) ? data.partners : []
			} catch (e) {
				this.fail(e)
			} finally {
				this.loaded = true
			}
		},

		/**
		 * Read the partner's root fingerprint for comparison.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
		 */
		async check() {
			this.error = null
			this.preview = null
			this.compared = false
			this.busy = true
			try {
				const { data } = await axios.post(generateUrl(BASE + '/preview'), {
					url: this.url.trim(),
				})
				this.preview = data
			} catch (e) {
				this.fail(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Add the previewed partner with the fingerprint the administrator compared.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
		 */
		async add() {
			if (!this.preview || !this.compared) {
				return
			}
			this.error = null
			this.busy = true
			try {
				await confirmPasswordFirst()
				await axios.post(generateUrl(BASE), {
					url: this.preview.baseUrl,
					rootFingerprint: this.preview.rootFingerprint,
					allowOutbound: this.allowOutbound,
					allowInbound: this.allowInbound,
				})
				this.url = ''
				this.preview = null
				this.compared = false
				await this.load()
			} catch (e) {
				this.fail(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Change one direction of a partner.
		 *
		 * @param {object} partner The partner row.
		 * @param {object} change `{allowOutbound}` or `{allowInbound}`.
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
		 */
		async updatePartner(partner, change) {
			this.error = null
			try {
				await confirmPasswordFirst()
				await axios.put(
					generateUrl(BASE + '/' + encodeURIComponent(partner.id)),
					{
						allowOutbound: partner.allowOutbound,
						allowInbound: partner.allowInbound,
						...change,
					},
				)
			} catch (e) {
				this.fail(e)
			}
			await this.load()
		},

		/**
		 * Remove a partner.
		 *
		 * @param {object} partner The partner row.
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
		 */
		async removePartner(partner) {
			this.error = null
			try {
				await confirmPasswordFirst()
				await axios.delete(
					generateUrl(BASE + '/' + encodeURIComponent(partner.id)),
				)
			} catch (e) {
				this.fail(e)
			}
			await this.load()
		},
	},
}
</script>

<style scoped>
.federation-partners {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 640px;
}

.federation-partners code {
	overflow-wrap: anywhere;
	font-size: var(--font-size-small, 13px);
}

.federation-partners__own,
.federation-partners__preview p {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.federation-partners__list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.federation-partners__row,
.federation-partners__preview {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.federation-partners__add {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}
</style>
