<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin section for federation partners (sharing-federated-recipients D1,
  task 1.3). The administrator types a partner address, Keepiq reads that
  partner's root fingerprint, and the administrator compares it with the
  partner's administrator before adding it. Each partner has two
  directions. Re-pin reads a listed partner's fingerprint again after it
  renewed its root, for the same comparison. A renewal the partner announced
  and this server verified is shown on its row to accept without that
  comparison. Every change asks for the Nextcloud password again.

  @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
  @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
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
						:id="'federation-partner-' + partner.id"
						:key="partner.id"
						class="federation-partners__row"
						data-testid="federation-partner">
						<strong>{{ partner.baseUrl }}</strong>
						<code>{{ formatFingerprint(partner.rootFingerprint) }}</code>
						<div
							v-if="partner.pendingRenewal"
							class="federation-partners__renewal"
							data-testid="federation-renewal">
							<NcNoteCard type="info">
								{{
									t(
										'keepiq',
										'This partner renewed its root certificate, and its previous root signed the new one.',
									)
								}}
							</NcNoteCard>
							<p>
								<span>{{
									t('keepiq', 'Pinned root fingerprint')
								}}</span>
								<code data-testid="federation-renewal-pinned">{{
									formatFingerprint(partner.rootFingerprint)
								}}</code>
							</p>
							<p>
								<span>{{
									t('keepiq', 'Renewed root fingerprint')
								}}</span>
								<code data-testid="federation-renewal-new">{{
									formatFingerprint(
										partner.pendingRenewal.fingerprint,
									)
								}}</code>
							</p>
							<NcButton
								variant="primary"
								:disabled="busy"
								data-testid="federation-renewal-accept"
								@click="acceptRenewal(partner)">
								{{ t('keepiq', 'Accept renewed certificate') }}
							</NcButton>
						</div>
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
							:disabled="busy"
							data-testid="federation-repin"
							@click="checkRepin(partner)">
							{{ t('keepiq', 'Re-pin root') }}
						</NcButton>
						<NcButton
							variant="tertiary"
							data-testid="federation-remove"
							@click="removePartner(partner)">
							{{ t('keepiq', 'Remove') }}
						</NcButton>
						<div
							v-if="repin && repin.partner.id === partner.id"
							class="federation-partners__repin"
							data-testid="federation-repin-preview">
							<p>
								<span>{{
									t('keepiq', 'Pinned root fingerprint')
								}}</span>
								<code data-testid="federation-repin-pinned">{{
									formatFingerprint(repin.partner.rootFingerprint)
								}}</code>
							</p>
							<p>
								<span>{{
									t('keepiq', 'Published root fingerprint')
								}}</span>
								<code data-testid="federation-repin-published">{{
									formatFingerprint(repin.published)
								}}</code>
							</p>
							<NcNoteCard
								v-if="repinUnchanged"
								type="info"
								data-testid="federation-repin-unchanged">
								{{
									t(
										'keepiq',
										'This partner still publishes the pinned root fingerprint, so there is nothing to re-pin.',
									)
								}}
							</NcNoteCard>
							<template v-else>
								<NcCheckboxRadioSwitch
									v-model="repinCompared"
									data-testid="federation-repin-compared">
									{{
										t(
											'keepiq',
											"I compared this fingerprint with the partner's administrator",
										)
									}}
								</NcCheckboxRadioSwitch>
								<NcButton
									variant="primary"
									:disabled="busy || !repinCompared"
									data-testid="federation-repin-confirm"
									@click="confirmRepin">
									{{
										t('keepiq', 'Pin the published fingerprint')
									}}
								</NcButton>
							</template>
							<NcButton
								variant="tertiary"
								data-testid="federation-repin-cancel"
								@click="cancelRepin">
								{{ t('keepiq', 'Cancel') }}
							</NcButton>
						</div>
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
import { confirmPasswordFirst } from '../../utils/passwordConfirmation.js'

const BASE = '/apps/keepiq/api/v1/federation/partners'

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
			/** @type {{partner: object, published: string}|null} The partner being re-pinned and the fingerprint it publishes now. */
			repin: null,
			repinCompared: false,
		}
	},

	computed: {
		/**
		 * @return {boolean} Whether the partner still publishes the pinned fingerprint.
		 * @spec openspec/changes/partner-share-temporary-refusals/specs/federated-sharing/spec.md#scenario-nothing-to-re-pin
		 */
		repinUnchanged() {
			return this.repin?.published === this.repin?.partner.rootFingerprint
		},
	},

	/**
	 * Load the partners.
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	async created() {
		await this.load()
		this.$nextTick(() => this.revealLinkedPartner())
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
		 * Read the root fingerprint a listed partner publishes now, to show it
		 * next to the pinned one.
		 *
		 * @param {object} partner The partner row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/partner-share-temporary-refusals/specs/federated-sharing/spec.md#scenario-administrator-re-pins-a-renewed-partner-root
		 */
		async checkRepin(partner) {
			this.error = null
			this.repin = null
			this.repinCompared = false
			this.busy = true
			try {
				const { data } = await axios.post(generateUrl(BASE + '/preview'), {
					url: partner.baseUrl,
				})
				this.repin = { partner, published: data?.rootFingerprint ?? '' }
			} catch (e) {
				this.fail(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Pin the published fingerprint the administrator compared, with the
		 * password confirmed first. The server reads it again and refuses one
		 * that changed since.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/partner-share-temporary-refusals/specs/federated-sharing/spec.md#scenario-the-published-root-changed-after-the-preview
		 */
		async confirmRepin() {
			if (!this.repin || !this.repinCompared || this.repinUnchanged) {
				return
			}
			this.error = null
			this.busy = true
			try {
				await confirmPasswordFirst()
				await axios.put(
					generateUrl(
						BASE
							+ '/'
							+ encodeURIComponent(this.repin.partner.id)
							+ '/root',
					),
					{ fingerprint: this.repin.published },
				)
				this.repin = null
				this.repinCompared = false
				await this.load()
			} catch (e) {
				this.fail(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Accept the renewed root a partner announced and this server verified,
		 * with the password confirmed first. The server checks the announcement
		 * again and re-pins; a refusal shows why, and the partners are read
		 * again since it may have replaced or withdrawn the offer.
		 *
		 * @param {object} partner The partner row with its `pendingRenewal`.
		 * @return {Promise<void>}
		 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-an-administrator-accepts-a-verified-renewal
		 */
		async acceptRenewal(partner) {
			const fingerprint = partner?.pendingRenewal?.fingerprint
			if (!fingerprint) {
				return
			}
			this.error = null
			this.busy = true
			try {
				await confirmPasswordFirst()
				await axios.put(
					generateUrl(
						BASE + '/' + encodeURIComponent(partner.id) + '/root',
					),
					{ fingerprint, acceptRenewal: true },
				)
				await this.load()
			} catch (e) {
				this.fail(e)
				// A refusal may have replaced or withdrawn the offer, so show what the server has now.
				if (e?.response) {
					await this.load()
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Scroll to the partner a notification linked to (`#federation-partner-<id>`).
		 *
		 * @return {void}
		 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
		 */
		revealLinkedPartner() {
			const hash = window.location?.hash || ''
			if (!hash.startsWith('#federation-partner-')) {
				return
			}
			document
				.getElementById(decodeURIComponent(hash.slice(1)))
				?.scrollIntoView?.()
		},

		/**
		 * Close the re-pin comparison without pinning anything.
		 *
		 * @return {void}
		 * @spec exclude Closes a panel only.
		 */
		cancelRepin() {
			this.repin = null
			this.repinCompared = false
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
			await this.changePartner(() =>
				axios.put(generateUrl(BASE + '/' + encodeURIComponent(partner.id)), {
					allowOutbound: partner.allowOutbound,
					allowInbound: partner.allowInbound,
					...change,
				}),
			)
		},

		/**
		 * Remove a partner.
		 *
		 * @param {object} partner The partner row.
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
		 */
		async removePartner(partner) {
			await this.changePartner(() =>
				axios.delete(
					generateUrl(BASE + '/' + encodeURIComponent(partner.id)),
				),
			)
		},

		/**
		 * Run one change to the partner list: confirm the password first, as
		 * the server requires, show a refusal or failure, and read the list
		 * again whatever happened.
		 *
		 * @param {function(): Promise<object>} request The change request.
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
		 */
		async changePartner(request) {
			this.error = null
			try {
				await confirmPasswordFirst()
				await request()
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
.federation-partners__preview p,
.federation-partners__repin p,
.federation-partners__renewal p {
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
.federation-partners__preview,
.federation-partners__repin,
.federation-partners__renewal {
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
