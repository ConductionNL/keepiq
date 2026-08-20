<!-- @visual exclude Zero-knowledge break-glass surface whose behaviour is the client-side envelope crypto (vitest emergencyEnvelope) and the server state machine (PHPUnit EmergencyAccessServiceTest); a live visual-regression baseline is deferred with the DOM e2e run because the worktree is not deployed to the shared instance. -->
<template>
	<div class="emergency-access" data-testid="emergency-access-view">
		<h2 class="emergency-access__title">
			{{ t('doriath', 'Emergency access') }}
		</h2>
		<p class="emergency-access__intro">
			{{
				t(
					'doriath',
					"Designate a trusted person who can gain read access to your vault after a wait period — unless you decline. Your vault stays end-to-end encrypted: your key is escrowed only to the contact's certificate, never to the server.",
				)
			}}
		</p>

		<!-- Designate a new emergency contact (grantor). -->
		<section
			class="emergency-access__panel"
			data-testid="emergency-access-designate">
			<h3>{{ t('doriath', 'Designate an emergency contact') }}</h3>
			<div class="emergency-access__form">
				<NcTextField
					v-model="granteeUserId"
					:label="t('doriath', 'Contact Nextcloud user ID')"
					data-testid="emergency-grantee-input" />
				<NcSelect
					v-model="waitPeriod"
					:options="waitOptions"
					:inputLabel="t('doriath', 'Wait period')"
					:reduce="(opt) => opt.value"
					label="label"
					data-testid="emergency-wait-select" />
				<NcPasswordField
					v-model="masterPassword"
					:label="t('doriath', 'Your master password')"
					data-testid="emergency-master-input" />
				<NcButton
					variant="primary"
					:disabled="!canDesignate || busy"
					data-testid="emergency-designate-submit"
					@click="designate">
					{{ t('doriath', 'Designate') }}
				</NcButton>
			</div>
			<p
				v-if="error"
				class="emergency-access__error"
				data-testid="emergency-error">
				{{ error }}
			</p>
		</section>

		<!-- Contacts the current user has designated (grantor view). -->
		<section
			class="emergency-access__panel"
			data-testid="emergency-access-contacts">
			<h3>{{ t('doriath', 'Your emergency contacts') }}</h3>
			<NcEmptyContent
				v-if="store.contacts.length === 0"
				:name="t('doriath', 'No emergency contacts')" />
			<ul v-else class="emergency-access__list">
				<li
					v-for="c in store.contacts"
					:key="c.id"
					class="emergency-access__item"
					data-testid="emergency-contact-item">
					<span class="emergency-access__grantee">{{
						c.granteeUserId
					}}</span>
					<span class="emergency-access__state" :data-state="c.state">{{
						stateLabel(c.state)
					}}</span>
					<span class="emergency-access__wait">{{
						t('doriath', '{days}d wait', { days: c.waitPeriodDays })
					}}</span>
					<NcButton
						v-if="c.state === 'requested'"
						variant="warning"
						data-testid="emergency-decline"
						@click="decline(c.id)">
						{{ t('doriath', 'Decline request') }}
					</NcButton>
					<NcButton
						v-if="c.state === 'invalidated'"
						variant="secondary"
						data-testid="emergency-reestablish"
						@click="scrollToDesignate(c.granteeUserId)">
						{{ t('doriath', 'Re-establish') }}
					</NcButton>
					<NcButton
						variant="error"
						data-testid="emergency-revoke"
						@click="revoke(c.id)">
						{{ t('doriath', 'Revoke') }}
					</NcButton>
				</li>
			</ul>
		</section>

		<!-- Relationships where the current user is the grantee (incoming). -->
		<section
			class="emergency-access__panel"
			data-testid="emergency-access-incoming">
			<h3>{{ t('doriath', 'Vaults you can recover') }}</h3>
			<NcEmptyContent
				v-if="store.incoming.length === 0"
				:name="t('doriath', 'No incoming emergency access')" />
			<ul v-else class="emergency-access__list">
				<li
					v-for="c in store.incoming"
					:key="c.id"
					class="emergency-access__item"
					data-testid="emergency-incoming-item">
					<span class="emergency-access__grantee">{{
						c.grantorUserId
					}}</span>
					<span class="emergency-access__state" :data-state="c.state">{{
						stateLabel(c.state)
					}}</span>
					<NcButton
						v-if="c.state === 'granted'"
						variant="primary"
						data-testid="emergency-request"
						@click="request(c.id)">
						{{ t('doriath', 'Request access') }}
					</NcButton>
					<NcButton
						v-if="c.state === 'approved'"
						variant="primary"
						data-testid="emergency-recover"
						@click="recover(c.id)">
						{{ t('doriath', 'Recover vault') }}
					</NcButton>
				</li>
			</ul>
			<p
				v-if="recovered"
				class="emergency-access__recovered"
				data-testid="emergency-recovered">
				{{
					t(
						'doriath',
						"Recovered the grantor's key in your browser. You can now read their vault.",
					)
				}}
			</p>
		</section>
	</div>
</template>

<script>
import {
	NcButton,
	NcEmptyContent,
	NcPasswordField,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { useEmergencyAccessStore } from '../store/modules/emergencyAccess.js'

/**
 * Break-glass emergency-access surface (add-emergency-access). The grantor
 * designates contacts (building the recovery envelope in-browser) and vetoes
 * requests; the grantee requests access and, after the wait timer approves it,
 * recovers the grantor's key client-side. Nothing here sends a usable key to
 * the server — the vault stays zero-knowledge.
 *
 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
 */
export default {
	name: 'EmergencyAccessView',

	components: {
		NcButton,
		NcTextField,
		NcPasswordField,
		NcSelect,
		NcEmptyContent,
	},

	data() {
		return {
			store: useEmergencyAccessStore(),
			granteeUserId: '',
			waitPeriod: 7,
			masterPassword: '',
			busy: false,
			error: '',
			recovered: false,
		}
	},

	computed: {
		/**
		 * Wait-period options for the select.
		 *
		 * @return {Array<object>} The options.
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
		 */
		waitOptions() {
			return [
				{ value: 1, label: t('doriath', '1 day') },
				{ value: 3, label: t('doriath', '3 days') },
				{ value: 7, label: t('doriath', '7 days') },
				{ value: 30, label: t('doriath', '30 days') },
			]
		},

		/**
		 * Whether the designate form is complete.
		 *
		 * @return {boolean} True when the form can be submitted.
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
		 */
		canDesignate() {
			return this.granteeUserId.trim() !== '' && this.masterPassword !== ''
		},
	},

	async mounted() {
		await Promise.all([this.store.fetchContacts(), this.store.fetchIncoming()])
	},

	methods: {
		t,

		/**
		 * Human-readable label for a lifecycle state.
		 *
		 * @param {string} state The state key.
		 * @return {string} The label.
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
		 */
		stateLabel(state) {
			const map = {
				granted: t('doriath', 'Granted'),
				requested: t('doriath', 'Requested'),
				approved: t('doriath', 'Approved'),
				declined: t('doriath', 'Declined'),
				invalidated: t('doriath', 'Invalidated (re-establish)'),
			}
			return map[state] || state
		},

		/**
		 * Designate the entered contact, building the envelope client-side.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-client-side-recovery-envelope-escrow
		 */
		async designate() {
			this.busy = true
			this.error = ''
			try {
				await this.store.designate({
					granteeUserId: this.granteeUserId.trim(),
					waitPeriodDays: Number(this.waitPeriod),
					masterPassword: this.masterPassword,
				})
				this.granteeUserId = ''
				this.masterPassword = ''
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('doriath', 'Could not designate the contact')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Revoke a designated contact.
		 *
		 * @param {string} id The relationship ID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-revoke-emergency-contact
		 */
		async revoke(id) {
			await this.store.revoke(id)
		},

		/**
		 * Decline a pending break-glass request (veto).
		 *
		 * @param {string} id The relationship ID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-grantor-decline-veto
		 */
		async decline(id) {
			await this.store.decline(id)
		},

		/**
		 * Initiate a break-glass request as the grantee.
		 *
		 * @param {string} id The relationship ID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-break-glass-request-and-wait-timer
		 */
		async request(id) {
			await this.store.request(id)
		},

		/**
		 * Recover the grantor's key in-browser after approval.
		 *
		 * @param {string} id The relationship ID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-approval-by-timeout-and-grantee-view-access
		 */
		async recover(id) {
			this.error = ''
			try {
				await this.store.recover(id)
				this.recovered = true
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('doriath', 'Could not recover access')
			}
		},

		/**
		 * Pre-fill the designate form to re-establish an invalidated contact.
		 *
		 * @param {string} granteeUserId The grantee to re-establish.
		 * @return {void}
		 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
		 */
		scrollToDesignate(granteeUserId) {
			this.granteeUserId = granteeUserId
		},
	},
}
</script>

<style scoped>
.emergency-access {
	padding: 16px;
	max-width: 760px;
	margin: 0 auto;
}

.emergency-access__title {
	margin-bottom: 8px;
}

.emergency-access__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.emergency-access__panel {
	margin-top: 24px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.emergency-access__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 420px;
}

.emergency-access__list {
	list-style: none;
	padding: 0;
	margin: 8px 0 0;
}

.emergency-access__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.emergency-access__grantee {
	font-weight: 600;
	min-width: 140px;
}

.emergency-access__state {
	color: var(--color-text-maxcontrast);
	min-width: 120px;
}

.emergency-access__error {
	color: var(--color-error-text, var(--color-error));
	margin-top: 8px;
}

.emergency-access__recovered {
	color: var(--color-success-text, var(--color-success));
	margin-top: 8px;
}
</style>
