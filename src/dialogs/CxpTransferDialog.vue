<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  CXP (FIDO Credential Exchange Protocol) transfer dialog (cxp-transfer).

  Encrypted, direct provider-to-provider credential transfer — the CXF payload
  travels HPKE-sealed and NO plaintext file is ever written, in either
  direction. This is the encrypted alternative to the file-based CXF export.

  Two directions, exchanged through the opaque relay (public keys + HPKE
  ciphertext only):

  - Receive (import): generate an ephemeral HPKE keypair, publish a CXP request,
    poll for the sealed envelope, open it in-memory, and hand the CXF payload to
    the EXISTING import wizard (mapping → duplicate → commit).
  - Send (export): fetch a peer's CXP request, gate on fresh master-password
    re-auth, assemble + seal the CXF payload via the existing export path, and
    publish only the sealed envelope. Reports the transfer with mode `cxp`.

  @spec openspec/changes/cxp-transfer/specs/cxp-transfer/spec.md
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Encrypted transfer (CXP)')"
		:open="open"
		size="normal"
		data-testid="cxp-dialog"
		@update:open="onUpdateOpen">
		<div class="cxp-dialog">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard type="success">
				{{
					t(
						'keepiq',
						'Credentials travel HPKE-sealed directly between providers. No plaintext file is ever written to disk.',
					)
				}}
			</NcNoteCard>

			<div class="cxp-dialog__modes">
				<NcCheckboxRadioSwitch
					:modelValue="direction"
					value="receive"
					name="cxp-direction"
					type="radio"
					data-testid="cxp-direction-receive"
					@update:modelValue="direction = $event">
					{{ t('keepiq', 'Receive credentials (import)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:modelValue="direction"
					value="send"
					name="cxp-direction"
					type="radio"
					data-testid="cxp-direction-send"
					@update:modelValue="direction = $event">
					{{ t('keepiq', 'Send credentials (export)') }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Receive / import -->
			<div v-if="direction === 'receive'" class="cxp-dialog__panel">
				<p v-if="!pairingId">
					{{
						t(
							'keepiq',
							'Start a request; share the pairing code with the sending provider, then wait for the sealed transfer.',
						)
					}}
				</p>
				<NcButton
					v-if="!pairingId"
					variant="primary"
					:disabled="busy"
					data-testid="cxp-start-receive"
					@click="startReceive">
					{{ t('keepiq', 'Start encrypted request') }}
				</NcButton>
				<div v-else>
					<p>
						{{ t('keepiq', 'Pairing code — share with the sender:') }}
					</p>
					<code class="cxp-dialog__code" data-testid="cxp-pairing-code">{{
						pairingId
					}}</code>
					<p v-if="waiting" class="cxp-dialog__status">
						{{ t('keepiq', 'Waiting for the sealed transfer…') }}
					</p>
				</div>
			</div>

			<!-- Send / export -->
			<div v-else class="cxp-dialog__panel">
				<NcTextField
					v-model="sendPairingId"
					:label="t('keepiq', 'Pairing code from the receiving provider')"
					data-testid="cxp-send-pairing" />
				<NcNoteCard type="warning">
					{{
						t(
							'keepiq',
							'Sending requires re-entering your master password, even while unlocked.',
						)
					}}
				</NcNoteCard>
				<NcPasswordField
					v-model="masterPassword"
					:label="t('keepiq', 'Re-enter your master password')"
					data-testid="cxp-master-password" />
				<NcNoteCard
					v-if="cxpReport && cxpReport.unmapped.length > 0"
					type="warning"
					data-testid="cxp-unmapped-report">
					{{
						n(
							'keepiq',
							'%n item cannot be represented in CXF and will be skipped.',
							'%n items cannot be represented in CXF and will be skipped.',
							cxpReport.unmapped.length,
						)
					}}
				</NcNoteCard>
				<NcButton
					variant="primary"
					:disabled="busy || !sendPairingId || !masterPassword"
					data-testid="cxp-do-send"
					@click="doSend">
					{{
						cxpReport
							? t('keepiq', 'Confirm and send sealed transfer')
							: t('keepiq', 'Seal and send')
					}}
				</NcButton>
				<NcNoteCard v-if="sent" type="success" data-testid="cxp-sent">
					{{
						t(
							'keepiq',
							'Sealed transfer sent. No plaintext file was written.',
						)
					}}
				</NcNoteCard>
			</div>
		</div>

		<template #actions>
			<NcButton @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcPasswordField,
	NcTextField,
} from '@nextcloud/vue'
import { createImportRequest, openEnvelope } from '../crypto/cxp.js'
import { verifyMasterPassword } from '../crypto/reauth.js'
import { useExportStore } from '../store/modules/export.js'
import { useImportStore } from '../store/modules/import.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { useSessionStore } from '../store/modules/session.js'

/** Poll interval (ms) while waiting for the sealed envelope. */
const POLL_INTERVAL = 2000
/** Maximum polls before giving up (~2 min). */
const POLL_MAX = 60

export default {
	name: 'CxpTransferDialog',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcTextField,
		NcPasswordField,
		NcCheckboxRadioSwitch,
	},

	props: {
		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			default: false,
		},

		/** Already-decrypted secrets to send. */
		secrets: {
			type: Array,
			default: () => [],
		},

		/** Folder rows ({ id, name, parentId }). */
		folders: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:open', 'open-import'],
	/**
	 * Provide the export/import/session/type stores.
	 *
	 * @return {object}
	 */
	setup() {
		return {
			exportStore: useExportStore(),
			importStore: useImportStore(),
			sessionStore: useSessionStore(),
			typeStore: useSecretTypeStore(),
		}
	},

	data() {
		return {
			direction: 'receive',
			error: null,
			busy: false,
			// receive state
			pairingId: null,
			session: null,
			waiting: false,
			pollCount: 0,
			pollTimer: null,
			// send state
			sendPairingId: '',
			masterPassword: '',
			fetchedRequest: null,
			cxpReport: null,
			sent: false,
		}
	},

	computed: {
		/** Type names keyed by id, for the CXF mapping. */
		typeNamesById() {
			const map = {}
			for (const type of this.typeStore.types || []) {
				map[type.id] = type.name
			}
			return map
		},
	},

	methods: {
		/**
		 * Absolute relay URL.
		 *
		 * @param path
		 */
		relayUrl(path = '') {
			return generateUrl('/apps/keepiq/api/v1/cxp/relay' + path)
		},

		/**
		 * Receive flow: publish a CXP request and poll for the sealed envelope.
		 *
		 * @return {Promise<void>}
		 */
		async startReceive() {
			this.error = null
			this.busy = true
			try {
				const { request, session } = await createImportRequest()
				this.session = session
				const res = await axios.post(this.relayUrl(), {
					slot: 'request',
					payload: JSON.stringify(request),
				})
				this.pairingId = res.data.pairingId
				this.waiting = true
				this.pollCount = 0
				this.pollForEnvelope()
			} catch (e) {
				this.error =
					e.message || this.t('keepiq', 'Could not start the transfer')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Poll the relay for the sealed response envelope; open it in-memory and
		 * hand the CXF payload to the existing import wizard.
		 *
		 * @return {void}
		 */
		pollForEnvelope() {
			this.pollTimer = setTimeout(async () => {
				if (!this.waiting) {
					return
				}
				this.pollCount += 1
				try {
					const res = await axios.get(
						this.relayUrl('/' + this.pairingId + '/response'),
					)
					const envelope = JSON.parse(res.data.payload)
					this.waiting = false
					const cxfBytes = await openEnvelope(this.session, envelope)
					// Discard the ephemeral key material now that the open succeeded.
					this.session = null
					const cxfText = new TextDecoder().decode(cxfBytes)
					// Feed the EXISTING import pipeline — no plaintext file written.
					await this.importStore.parseFile(cxfText, 'cxf', {})
					this.importStore.goToStep('mapping')
					this.$emit('open-import')
					this.onUpdateOpen(false)
				} catch (e) {
					// 404 = not yet delivered; keep polling until the cap.
					if (
						e.response
						&& e.response.status === 404
						&& this.pollCount < POLL_MAX
					) {
						this.pollForEnvelope()
						return
					}
					if (this.pollCount >= POLL_MAX) {
						this.waiting = false
						this.error = this.t(
							'keepiq',
							'Timed out waiting for the sealed transfer',
						)
					} else {
						this.error =
							e.message
							|| this.t(
								'keepiq',
								'Could not open the sealed transfer',
							)
					}
				}
			}, POLL_INTERVAL)
		},

		/**
		 * Send flow: fetch the peer's request, gate on re-auth, assemble + seal
		 * the CXF payload, and publish only the sealed envelope.
		 *
		 * @return {Promise<void>}
		 */
		async doSend() {
			this.error = null
			this.busy = true
			try {
				// Fresh master-password re-auth (client-side proof of knowledge).
				const ok = await verifyMasterPassword(
					this.sessionStore.encryptedPrivateKey,
					this.masterPassword,
				)
				if (!ok) {
					this.error = this.t('keepiq', 'Incorrect master password')
					return
				}
				// Fetch the peer's CXP request from the relay ONCE and cache it: the
				// relay GET is one-shot (consumed on read), so the two-pass send
				// (dry-run report, then confirm) must reuse the same request rather
				// than re-fetch it.
				if (this.fetchedRequest === null) {
					const reqRes = await axios.get(
						this.relayUrl('/' + this.sendPairingId + '/request'),
					)
					this.fetchedRequest = JSON.parse(reqRes.data.payload)
				}
				const request = this.fetchedRequest

				// First pass surfaces the unmapped-item report before sending.
				if (this.cxpReport === null) {
					const report = await this.exportStore.exportCxpSealed(
						request,
						this.secrets,
						this.folders,
						{ mode: 'vault' },
						{ typeNamesById: this.typeNamesById, dryRun: true },
					)
					if (report.unmapped.length > 0) {
						this.cxpReport = report
						return
					}
				}

				const { envelope } = await this.exportStore.exportCxpSealed(
					request,
					this.secrets,
					this.folders,
					{ mode: 'vault' },
					{ typeNamesById: this.typeNamesById },
				)
				await axios.post(this.relayUrl(), {
					pairingId: this.sendPairingId,
					slot: 'response',
					payload: JSON.stringify(envelope),
				})
				this.sent = true
				this.masterPassword = ''
			} catch (e) {
				this.error =
					e.message
					|| this.t('keepiq', 'Could not send the sealed transfer')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Close and reset all transient state (releases ephemeral key material).
		 *
		 * @param {boolean} value The open state.
		 * @return {void}
		 */
		onUpdateOpen(value) {
			if (!value) {
				if (this.pollTimer) {
					clearTimeout(this.pollTimer)
					this.pollTimer = null
				}
				this.waiting = false
				this.session = null
				this.pairingId = null
				this.sendPairingId = ''
				this.masterPassword = ''
				this.fetchedRequest = null
				this.cxpReport = null
				this.sent = false
				this.error = null
			}
			this.$emit('update:open', value)
		},
	},
}
</script>

<style scoped lang="scss">
.cxp-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__modes {
		display: flex;
		gap: 16px;
	}

	&__panel {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	&__code {
		display: inline-block;
		padding: 6px 10px;
		border-radius: var(--border-radius);
		background: var(--color-background-dark);
		font-family: monospace;
		user-select: all;
	}

	&__status {
		color: var(--color-text-maxcontrast);
	}
}
</style>
