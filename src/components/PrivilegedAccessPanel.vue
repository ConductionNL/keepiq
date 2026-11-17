<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Privileged login panel on the secret detail (capability-requirements-from-
  the-matrix task 3.1). The owner marks the login as needing approval and
  answers access requests, optionally narrowing the window and limiting it
  to address ranges; after a window ends the owner is offered to rotate the
  login. A recipient sees their requests and asks for a window with a reason.
  The server decides the role and enforces every window and range.

  @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
-->
<template>
	<div class="privileged-panel" data-testid="privileged-panel">
		<NcNoteCard v-if="error" type="error" data-testid="privileged-error">
			{{ error }}
		</NcNoteCard>

		<template v-if="status && status.role === 'owner'">
			<NcCheckboxRadioSwitch
				:modelValue="status.privileged"
				type="switch"
				:disabled="busy"
				data-testid="privileged-toggle"
				@update:modelValue="onToggle">
				{{
					t(
						'keepiq',
						'Recipients need my approval for a time window before they can use this login',
					)
				}}
			</NcCheckboxRadioSwitch>

			<NcNoteCard
				v-if="offerRotation"
				type="warning"
				data-testid="privileged-rotate">
				{{
					t(
						'keepiq',
						'An approved window has ended. Rotate this login if the recipient should no longer know it.',
					)
				}}
			</NcNoteCard>
		</template>

		<template v-if="status && status.role === 'recipient'">
			<p
				v-if="!status.privileged"
				class="privileged-panel__muted"
				data-testid="privileged-not-needed">
				{{ t('keepiq', 'This login needs no approval.') }}
			</p>
			<template v-else>
				<p class="privileged-panel__muted">
					{{
						t(
							'keepiq',
							'The owner must approve a time window before you can use this login.',
						)
					}}
				</p>
				<form
					class="privileged-panel__form"
					data-testid="privileged-request-form"
					@submit.prevent="submitRequest">
					<label class="privileged-panel__field">
						<span>{{ t('keepiq', 'From') }}</span>
						<input
							v-model="form.start"
							type="datetime-local"
							required
							data-testid="privileged-request-start" />
					</label>
					<label class="privileged-panel__field">
						<span>{{ t('keepiq', 'Until') }}</span>
						<input
							v-model="form.end"
							type="datetime-local"
							required
							data-testid="privileged-request-end" />
					</label>
					<NcTextField
						v-model="form.reason"
						:label="t('keepiq', 'Reason')"
						:maxlength="500"
						data-testid="privileged-request-reason" />
					<NcButton
						type="submit"
						variant="primary"
						:disabled="busy || !canSubmitRequest"
						data-testid="privileged-request-submit">
						{{ t('keepiq', 'Ask for access') }}
					</NcButton>
				</form>
			</template>
		</template>

		<ul
			v-if="status && status.requests.length"
			class="privileged-panel__requests"
			data-testid="privileged-requests">
			<li
				v-for="request in status.requests"
				:key="request.id"
				class="privileged-panel__request"
				:data-testid="`privileged-request-${request.id}`">
				<div>
					<strong v-if="status.role === 'owner'">{{
						request.requesterId
					}}</strong>
					{{ windowText(request) }}
					<span
						class="privileged-panel__state"
						:data-testid="`privileged-state-${request.id}`"
						>{{ stateLabel(request) }}</span
					>
				</div>
				<div class="privileged-panel__muted">
					{{ request.reason }}
				</div>
				<div
					v-if="request.ranges && request.ranges.length"
					class="privileged-panel__muted">
					{{
						t('keepiq', 'Only from {ranges}', {
							ranges: request.ranges.join(', '),
						})
					}}
				</div>

				<div
					v-if="status.role === 'owner' && request.status === 'pending'"
					class="privileged-panel__answer">
					<template v-if="answering === request.id">
						<label class="privileged-panel__field">
							<span>{{
								t('keepiq', 'From (optional, later than asked)')
							}}</span>
							<input
								v-model="approval.start"
								type="datetime-local"
								:data-testid="`privileged-approve-start-${request.id}`" />
						</label>
						<label class="privileged-panel__field">
							<span>{{
								t('keepiq', 'Until (optional, earlier than asked)')
							}}</span>
							<input
								v-model="approval.end"
								type="datetime-local"
								:data-testid="`privileged-approve-end-${request.id}`" />
						</label>
						<NcTextField
							v-model="approval.ranges"
							:label="
								t(
									'keepiq',
									'Address ranges (optional, e.g. 192.0.2.0/24)',
								)
							"
							:data-testid="`privileged-approve-ranges-${request.id}`" />
						<NcButton
							variant="primary"
							:disabled="busy"
							:data-testid="`privileged-approve-confirm-${request.id}`"
							@click="approve(request)">
							{{ t('keepiq', 'Approve') }}
						</NcButton>
					</template>
					<NcButton
						v-else
						variant="secondary"
						:disabled="busy"
						:data-testid="`privileged-approve-${request.id}`"
						@click="startApproval(request)">
						{{ t('keepiq', 'Approve…') }}
					</NcButton>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						:data-testid="`privileged-deny-${request.id}`"
						@click="deny(request)">
						{{ t('keepiq', 'Deny') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { dateTimeOrDash } from '../utils/dateTime.js'
import {
	localInputToIso,
	parseRanges,
	requestState,
	shouldOfferRotation,
} from '../utils/privilegedAccess.js'

export default {
	name: 'PrivilegedAccessPanel',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcTextField,
	},

	props: {
		secretId: {
			type: String,
			required: true,
		},
	},

	emits: ['changed'],

	data() {
		return {
			status: null,
			error: '',
			busy: false,
			form: { start: '', end: '', reason: '' },
			answering: null,
			approval: { start: '', end: '', ranges: '' },
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
		 */
		offerRotation() {
			return shouldOfferRotation(this.status?.requests, new Date())
		},

		/**
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
		 */
		canSubmitRequest() {
			return (
				localInputToIso(this.form.start) !== ''
				&& localInputToIso(this.form.end) !== ''
				&& this.form.reason.trim() !== ''
			)
		},
	},

	watch: {
		/**
		 * Reload when the panel shows another secret.
		 *
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
		 */
		secretId() {
			this.load()
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * The base URL of this secret's privileged state.
		 *
		 * @param {string} path The path after /api/v1.
		 * @return {string}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
		 */
		url(path) {
			return generateUrl(`/apps/keepiq/api/v1${path}`)
		},

		/**
		 * Load the state the caller may see.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
		 */
		async load() {
			await this.run(async () => {
				const response = await axios.get(
					this.url(`/secrets/${this.secretId}/privileged`),
				)
				const data = response?.data ?? {}
				// Anything but a known role shows nothing rather than guessing.
				this.status = ['owner', 'recipient'].includes(data.role)
					? {
							role: data.role,
							privileged: data.privileged === true,
							requests: Array.isArray(data.requests)
								? data.requests
								: [],
						}
					: null
			})
		},

		/**
		 * Mark or unmark the login.
		 *
		 * @param {boolean} privileged The state asked for.
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
		 */
		async onToggle(privileged) {
			await this.run(async () => {
				const response = await axios.put(
					this.url(`/secrets/${this.secretId}/privileged`),
					{ privileged },
				)
				this.status = {
					...this.status,
					privileged: response.data.privileged === true,
				}
			})
		},

		/**
		 * Ask the owner for the window in the form.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
		 */
		async submitRequest() {
			await this.run(async () => {
				await axios.post(
					this.url(`/secrets/${this.secretId}/access-requests`),
					{
						startsAt: localInputToIso(this.form.start),
						endsAt: localInputToIso(this.form.end),
						reason: this.form.reason.trim(),
					},
				)
				this.form = { start: '', end: '', reason: '' }
				await this.load()
			})
		},

		/**
		 * Open the approval form of a request.
		 *
		 * @param {object} request The request.
		 * @return {void}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
		 */
		startApproval(request) {
			this.answering = request.id
			this.approval = { start: '', end: '', ranges: '' }
		},

		/**
		 * Approve a request, narrowed as the owner typed.
		 *
		 * @param {object} request The request.
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
		 */
		async approve(request) {
			await this.run(async () => {
				await axios.post(
					this.url(`/access-requests/${request.id}/approve`),
					{
						startsAt: localInputToIso(this.approval.start),
						endsAt: localInputToIso(this.approval.end),
						ranges: parseRanges(this.approval.ranges),
					},
				)
				this.answering = null
				await this.load()
				this.$emit('changed')
			})
		},

		/**
		 * Deny a request.
		 *
		 * @param {object} request The request.
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
		 */
		async deny(request) {
			await this.run(async () => {
				await axios.post(this.url(`/access-requests/${request.id}/deny`))
				await this.load()
				this.$emit('changed')
			})
		},

		/**
		 * Run one call, keeping the busy flag and the server's message.
		 *
		 * @param {() => Promise<void>} step The call.
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
		 */
		async run(step) {
			this.busy = true
			this.error = ''
			try {
				await step()
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t('keepiq', 'Something went wrong. Please try again.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * The window a request asks for or was approved for.
		 *
		 * @param {object} request The request.
		 * @return {string}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
		 */
		windowText(request) {
			const start = request.approvedStart || request.requestedStart
			const end = request.approvedEnd || request.requestedEnd
			return t('keepiq', '{start} until {end}', {
				start: dateTimeOrDash(start),
				end: dateTimeOrDash(end),
			})
		},

		/**
		 * The label of a request's state.
		 *
		 * @param {object} request The request.
		 * @return {string}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
		 */
		stateLabel(request) {
			const labels = {
				pending: t('keepiq', 'Waiting for approval'),
				denied: t('keepiq', 'Denied'),
				upcoming: t('keepiq', 'Approved, not started yet'),
				active: t('keepiq', 'Approved, in use now'),
				ended: t('keepiq', 'Ended'),
			}
			return labels[requestState(request, new Date())]
		},
	},
}
</script>

<style scoped lang="scss">
.privileged-panel {
	display: flex;
	flex-direction: column;
	gap: 8px;

	&__muted {
		color: var(--color-text-maxcontrast);
	}

	&__form,
	&__answer {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__requests {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding: 0;
		list-style: none;
	}

	&__request {
		padding: 8px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
	}

	&__state {
		margin-inline-start: 8px;
		font-weight: bold;
	}
}
</style>
