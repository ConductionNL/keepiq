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

  Two transports: the relay on this Nextcloud (a pairing code), or files for
  another provider (portability-cxp-cross-provider-transfer): the request is
  downloaded as a file and the sealed response loaded from one, or a request
  file is loaded and the sealed transfer downloaded. The send side chooses
  what goes: the whole vault, one folder, or the items selected on the list.

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

			<div class="cxp-dialog__modes" role="group" :aria-label="t('keepiq', 'Other side')">
				<NcCheckboxRadioSwitch
					:modelValue="transport"
					value="relay"
					name="cxp-transport"
					type="radio"
					data-testid="cxp-transport-relay"
					@update:modelValue="setTransport($event)">
					{{ t('keepiq', 'Keepiq on this Nextcloud') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:modelValue="transport"
					value="file"
					name="cxp-transport"
					type="radio"
					data-testid="cxp-transport-file"
					@update:modelValue="setTransport($event)">
					{{ t('keepiq', 'Another provider (files)') }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Receive / import -->
			<div v-if="direction === 'receive' && transport === 'file'" class="cxp-dialog__panel">
				<p>
					{{
						t(
							'keepiq',
							'Download a request file and give it to the other provider. Load the sealed file it returns here; only this window can open it.',
						)
					}}
				</p>
				<NcButton
					:variant="session ? 'secondary' : 'primary'"
					:disabled="busy"
					data-testid="cxp-download-request"
					@click="downloadRequest">
					{{ t('keepiq', 'Download request file') }}
				</NcButton>
				<template v-if="session">
					<label for="cxp-response-file">{{ t('keepiq', 'Sealed file from the other provider') }}</label>
					<input
						id="cxp-response-file"
						type="file"
						accept=".json,application/json"
						:disabled="busy"
						data-testid="cxp-response-file"
						@change="onResponseFile">
				</template>
			</div>

			<div v-else-if="direction === 'receive'" class="cxp-dialog__panel">
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
					v-if="transport === 'relay'"
					v-model="sendPairingId"
					:label="t('keepiq', 'Pairing code from the receiving provider')"
					data-testid="cxp-send-pairing" />
				<template v-else>
					<label for="cxp-request-file">{{ t('keepiq', 'Request file from the receiving provider') }}</label>
					<input
						id="cxp-request-file"
						type="file"
						accept=".json,application/json"
						:disabled="busy"
						data-testid="cxp-request-file"
						@change="onRequestFile">
					<p v-if="fetchedRequest" class="cxp-dialog__status" data-testid="cxp-request-loaded">
						{{ t('keepiq', 'Request loaded. The transfer is sealed for that provider only.') }}
					</p>
				</template>
				<NcSelect
					v-model="scopeChoice"
					:inputLabel="t('keepiq', 'What to send')"
					:options="scopeOptions"
					:reduce="(opt) => opt.value"
					:clearable="false"
					data-testid="cxp-scope" />
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
				<!-- Secrets that could not be decrypted are not in the transfer:
				     say how many before anything is sent (keepiq#794). -->
				<SkippedSecretsNotice
					v-if="skipped > 0"
					v-model:acknowledged="skippedAcknowledged"
					class="cxp-dialog__skipped"
					name="cxp"
					:skipped="skipped" />
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
					:disabled="
						busy
						|| !requestReady
						|| !masterPassword
						|| (skipped > 0 && !skippedAcknowledged)
					"
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
						transport === 'file'
							? t('keepiq', 'Sealed file downloaded. Only the provider that made the request can open it.')
							: t(
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
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import SkippedSecretsNotice from '../components/SkippedSecretsNotice.vue'
import { createImportRequest, openEnvelope } from '../crypto/cxp.js'
import exportSourceProps from '../mixins/exportSourceProps.js'
import masterPasswordCheck from '../mixins/masterPasswordCheck.js'
import { downloadBlob, useExportStore } from '../store/modules/export.js'
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
		SkippedSecretsNotice,
		NcDialog,
		NcButton,
		NcNoteCard,
		NcTextField,
		NcPasswordField,
		NcCheckboxRadioSwitch,
		NcSelect,
	},

	mixins: [exportSourceProps, masterPasswordCheck],

	props: {
		/** Ids of the secrets selected on the list, offered as "Selected items". */
		selectedIds: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:open', 'open-import'],
	/**
	 * Provide the export/import/session/type stores.
	 *
	 * @return {object}
	 *
	 * @spec exclude Composition setup: wires four Pinia stores onto the component, no logic.
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
			// 'relay' (this Nextcloud, pairing code) or 'file' (another provider).
			transport: 'relay',
			// 'vault', 'selection' or a folder id.
			scopeChoice: 'vault',
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
			/** Whether the user chose to send without the skipped secrets. */
			skippedAcknowledged: false,
		}
	},

	computed: {
		/**
		 * What the send side can send: the whole vault, the items selected on
		 * the list when there are any, and each folder.
		 *
		 * @return {Array<{label: string, value: string}>}
		 * @spec openspec/changes/portability-cxp-cross-provider-transfer/specs/portability-cxp/spec.md#requirement-choose-what-to-send
		 */
		scopeOptions() {
			const options = [{ label: this.t('keepiq', 'Entire vault'), value: 'vault' }]
			if (this.selectedIds.length > 0) {
				options.push({
					label: this.t('keepiq', 'Selected items ({count})', { count: this.selectedIds.length }),
					value: 'selection',
				})
			}
			for (const folder of this.folders) {
				options.push({ label: folder.name, value: folder.id })
			}
			return options
		},

		/**
		 * The scope handed to the sealed export.
		 *
		 * @return {object} `{mode: 'vault'}`, `{mode: 'selection', secretIds}` or `{mode: 'folders', folderIds}`.
		 * @spec openspec/changes/portability-cxp-cross-provider-transfer/specs/portability-cxp/spec.md#requirement-choose-what-to-send
		 */
		sendScope() {
			if (this.scopeChoice === 'selection' && this.selectedIds.length > 0) {
				return { mode: 'selection', secretIds: [...this.selectedIds] }
			}
			if (this.scopeChoice !== 'vault' && this.folders.some((f) => f.id === this.scopeChoice)) {
				return { mode: 'folders', folderIds: [this.scopeChoice] }
			}
			return { mode: 'vault' }
		},

		/**
		 * Whether the send side knows whom to seal for.
		 *
		 * @return {boolean}
		 */
		requestReady() {
			return this.transport === 'file' ? this.fetchedRequest !== null : Boolean(this.sendPairingId)
		},

		/**
		 * Type names keyed by id, for the CXF mapping.
		 *
		 * @spec openspec/specs/cxf-import-export/spec.md#requirement-cxf-entity-to-keepiq-type-mapping
		 */
		typeNamesById() {
			const map = {}
			for (const type of this.typeStore.types || []) {
				map[type.id] = type.name
			}
			return map
		},
	},

	watch: {
		/**
		 * A report made for one choice says nothing about another.
		 *
		 * @return {void}
		 */
		scopeChoice() {
			this.cxpReport = null
		},
	},

	methods: {
		/**
		 * Switch between the relay and files, forgetting the other's request.
		 *
		 * @param {string} transport 'relay' or 'file'.
		 * @return {void}
		 * @spec openspec/changes/portability-cxp-cross-provider-transfer/specs/portability-cxp/spec.md#requirement-cross-provider-transfer
		 */
		setTransport(transport) {
			if (transport === this.transport) {
				return
			}
			this.stopWaiting()
			this.transport = transport
			this.session = null
			this.pairingId = null
			this.fetchedRequest = null
			this.cxpReport = null
			this.sent = false
			this.error = null
		},

		/**
		 * Stop polling the relay.
		 *
		 * @return {void}
		 */
		stopWaiting() {
			if (this.pollTimer) {
				clearTimeout(this.pollTimer)
				this.pollTimer = null
			}
			this.waiting = false
		},

		/**
		 * Receive from another provider, step 1: make a request and download
		 * it as a file. The private key stays in this window.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portability-cxp-cross-provider-transfer/specs/portability-cxp/spec.md#requirement-cross-provider-transfer
		 */
		async downloadRequest() {
			this.error = null
			this.busy = true
			try {
				const { request, session } = await createImportRequest()
				this.session = session
				downloadBlob(
					'keepiq-cxp-request.json',
					JSON.stringify(request, null, 2),
					'application/json',
				)
			} catch (e) {
				this.error = e.message || this.t('keepiq', 'Could not start the transfer')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Read the text of a chosen file.
		 *
		 * @param {Event} event The input's change event.
		 * @return {Promise<string|null>} The text, or null when no file was chosen.
		 */
		async fileText(event) {
			const file = event && event.target && event.target.files ? event.target.files[0] : null
			if (!file) {
				return null
			}
			return typeof file.text === 'function'
				? file.text()
				: new Response(file).text()
		},

		/**
		 * Receive from another provider, step 2: load the sealed file.
		 *
		 * @param {Event} event The input's change event.
		 * @return {Promise<void>}
		 * @spec openspec/changes/portability-cxp-cross-provider-transfer/specs/portability-cxp/spec.md#requirement-cross-provider-transfer
		 */
		async onResponseFile(event) {
			const text = await this.fileText(event)
			if (text !== null) {
				await this.openResponseText(text)
			}
		},

		/**
		 * Open a sealed response with this window's request and import it. A
		 * file that does not open — altered, for another request, or not a
		 * sealed transfer — imports nothing.
		 *
		 * @param {string} text The file's text.
		 * @return {Promise<void>}
		 * @spec openspec/changes/portability-cxp-cross-provider-transfer/specs/portability-cxp/spec.md#requirement-cross-provider-transfer
		 */
		async openResponseText(text) {
			this.error = null
			if (!this.session) {
				this.error = this.t('keepiq', 'Could not open the sealed transfer')
				return
			}
			this.busy = true
			let cxfBytes
			try {
				cxfBytes = await openEnvelope(this.session, JSON.parse(text))
			} catch {
				this.error = this.t('keepiq', 'The file could not be verified. Nothing was imported.')
				this.busy = false
				return
			}
			try {
				this.session = null
				await this.importCxf(cxfBytes)
			} catch (e) {
				this.error = e.message || this.t('keepiq', 'Could not open the sealed transfer')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Send to another provider, step 1: load its request file.
		 *
		 * @param {Event} event The input's change event.
		 * @return {Promise<void>}
		 * @spec openspec/changes/portability-cxp-cross-provider-transfer/specs/portability-cxp/spec.md#requirement-cross-provider-transfer
		 */
		async onRequestFile(event) {
			const text = await this.fileText(event)
			if (text !== null) {
				this.loadRequestText(text)
			}
		},

		/**
		 * Keep a loaded request when it has the parts a seal needs.
		 *
		 * @param {string} text The file's text.
		 * @return {boolean} Whether it was loaded.
		 * @spec openspec/changes/portability-cxp-cross-provider-transfer/specs/portability-cxp/spec.md#requirement-cross-provider-transfer
		 */
		loadRequestText(text) {
			this.error = null
			this.fetchedRequest = null
			this.cxpReport = null
			this.sent = false
			let request
			try {
				request = JSON.parse(text)
			} catch {
				request = null
			}
			const ok = request !== null
				&& typeof request === 'object'
				&& typeof request.v === 'string'
				&& typeof request.requesterPublicKey === 'string'
				&& typeof request.nonce === 'string'
			if (!ok) {
				this.error = this.t('keepiq', 'This is not a transfer request file.')
				return false
			}
			this.fetchedRequest = request
			return true
		},

		/**
		 * Hand a received CXF payload to the existing import wizard.
		 *
		 * @param {Uint8Array} cxfBytes The opened payload.
		 * @return {Promise<void>}
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-importing-provider
		 */
		async importCxf(cxfBytes) {
			const cxfText = new TextDecoder().decode(cxfBytes)
			// Feed the EXISTING import pipeline — no plaintext file written.
			await this.importStore.parseFile(cxfText, 'cxf', {})
			this.importStore.goToStep('mapping')
			this.$emit('open-import')
			this.onUpdateOpen(false)
		},

		/**
		 * Absolute relay URL.
		 *
		 * @param {string} path Optional sub-path appended to the relay route.
		 * @return {string}
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-v1-scope-is-the-browser-session-flow
		 */
		relayUrl(path = '') {
			return generateUrl('/apps/keepiq/api/v1/cxp/relay' + path)
		},

		/**
		 * Receive flow: publish a CXP request and poll for the sealed envelope.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-importing-provider
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-v1-scope-is-the-browser-session-flow
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
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-client-side-hpke-seal-and-open
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-importing-provider
		 */
		pollForEnvelope() {
			this.pollTimer = setTimeout(() => this.pollOnce(), POLL_INTERVAL)
		},

		/**
		 * One poll of the relay, while still waiting for the envelope.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-importing-provider
		 */
		async pollOnce() {
			if (!this.waiting) {
				return
			}
			this.pollCount += 1
			try {
				await this.receiveEnvelope()
			} catch (e) {
				this.onPollFailure(e)
			}
		},

		/**
		 * Fetch the sealed response envelope, open it in-memory and hand the
		 * CXF payload to the existing import wizard.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-client-side-hpke-seal-and-open
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-importing-provider
		 */
		async receiveEnvelope() {
			const res = await axios.get(
				this.relayUrl('/' + this.pairingId + '/response'),
			)
			const envelope = JSON.parse(res.data.payload)
			this.waiting = false
			const cxfBytes = await openEnvelope(this.session, envelope)
			// Discard the ephemeral key material now that the open succeeded.
			this.session = null
			await this.importCxf(cxfBytes)
		},

		/**
		 * A 404 means not yet delivered: keep polling until the cap. Anything
		 * else, or reaching the cap, ends the wait with why.
		 *
		 * @param {Error|object} e The failure.
		 * @return {void}
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-importing-provider
		 */
		onPollFailure(e) {
			if (
				e.response
				&& e.response.status === 404
				&& this.pollCount < POLL_MAX
			) {
				this.pollForEnvelope()
				return
			}
			this.showReceiveFailure(e)
		},

		/**
		 * @param {Error|object} e The failure.
		 * @return {void}
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-importing-provider
		 */
		showReceiveFailure(e) {
			if (this.pollCount >= POLL_MAX) {
				this.waiting = false
				this.error = this.t(
					'keepiq',
					'Timed out waiting for the sealed transfer',
				)
				return
			}
			this.error =
				e.message
				|| this.t('keepiq', 'Could not open the sealed transfer')
		},

		/**
		 * Send flow: fetch the peer's request, gate on re-auth, assemble + seal
		 * the CXF payload, and publish only the sealed envelope.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-exporting-provider
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-client-side-hpke-seal-and-open
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-cxp-transfer-emits-an-export-event-with-mode-cxp
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-nothing-is-left-out-of-an-export-in-silence
		 */
		async doSend() {
			this.error = null
			// Nothing is sent until the user chose to continue without the
			// secrets that could not be decrypted (keepiq#794).
			if (this.skipped > 0 && !this.skippedAcknowledged) {
				return
			}
			this.busy = true
			try {
				// Fresh master-password re-auth (client-side proof of knowledge).
				if (!(await this.masterPasswordVerified())) {
					return
				}
				const request = await this.peerRequest()
				// First pass surfaces the unmapped-item report before sending.
				if (this.cxpReport === null && (await this.showCxpReport(request))) {
					return
				}
				await this.sendSealed(request)
			} catch (e) {
				this.error =
					e.message
					|| this.t('keepiq', 'Could not send the sealed transfer')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Fetch the peer's CXP request from the relay ONCE and cache it: the
		 * relay GET is one-shot (consumed on read), so the two-pass send
		 * (dry-run report, then confirm) must reuse the same request rather
		 * than re-fetch it.
		 *
		 * @return {Promise<object>} The peer's request.
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-exporting-provider
		 */
		async peerRequest() {
			if (this.transport === 'file') {
				if (this.fetchedRequest === null) {
					throw new Error(this.t('keepiq', 'Could not send the sealed transfer'))
				}
				return this.fetchedRequest
			}
			if (this.fetchedRequest === null) {
				const reqRes = await axios.get(
					this.relayUrl('/' + this.sendPairingId + '/request'),
				)
				this.fetchedRequest = JSON.parse(reqRes.data.payload)
			}
			return this.fetchedRequest
		},

		/**
		 * Build the sealed payload's report without sending, and keep it to
		 * show when it lists items it cannot map.
		 *
		 * @param {object} request The peer's request.
		 * @return {Promise<boolean>} Whether the report is shown.
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-exporting-provider
		 */
		async showCxpReport(request) {
			const report = await this.exportStore.exportCxpSealed(
				request,
				this.secrets,
				this.folders,
				this.sendScope,
				{ typeNamesById: this.typeNamesById, dryRun: true },
			)
			if (report.unmapped.length > 0) {
				this.cxpReport = report
				return true
			}
			return false
		},

		/**
		 * Seal the CXF payload for the peer and publish only the sealed
		 * envelope.
		 *
		 * @param {object} request The peer's request.
		 * @return {Promise<void>}
		 * @spec openspec/specs/cxp-transfer/spec.md#requirement-client-side-hpke-seal-and-open
		 */
		async sendSealed(request) {
			const { envelope } = await this.exportStore.exportCxpSealed(
				request,
				this.secrets,
				this.folders,
				this.sendScope,
				{ typeNamesById: this.typeNamesById },
			)
			if (this.transport === 'file') {
				// Another provider: the sealed envelope is the file.
				downloadBlob(
					'keepiq-cxp-transfer.json',
					JSON.stringify(envelope),
					'application/json',
				)
				this.sent = true
				this.masterPassword = ''
				return
			}
			await axios.post(this.relayUrl(), {
				pairingId: this.sendPairingId,
				slot: 'response',
				payload: JSON.stringify(envelope),
			})
			this.sent = true
			this.masterPassword = ''
		},

		/**
		 * Close and reset all transient state (releases ephemeral key material),
		 * including the acknowledgement of secrets left out (keepiq#794).
		 *
		 * @param {boolean} value The open state.
		 * @return {void}
		 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-nothing-is-left-out-of-an-export-in-silence
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
				this.skippedAcknowledged = false
				this.error = null
				this.scopeChoice = 'vault'
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

	&__skipped {
		display: flex;
		flex-direction: column;
		gap: 4px;
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
