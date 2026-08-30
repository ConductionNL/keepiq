<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Delegation manager (§12.5). Lists every SecretDelegation row for the
  current secret with delegate UID + status (temporary / permanent) and a
  reclaim button for the owner. Permanent rows render the
  "permanent" pill and do NOT contribute to the reclaim button's enabled
  state (per spec the reclaim path only sweeps temporary rows).

  @spec openspec/changes/implement-user-sharing/tasks.md#12.5
-->
<template>
	<section class="keepiq-delegation-manager" data-testid="delegation-manager">
		<header class="keepiq-delegation-manager__header">
			<h4>{{ t('keepiq', 'Delegations') }}</h4>
			<span
				v-if="store.count > 0"
				class="keepiq-delegation-manager__count"
				data-testid="delegation-manager-count">
				{{ store.count }}
			</span>
		</header>

		<p v-if="store.loading" class="keepiq-delegation-manager__loading">
			{{ t('keepiq', 'Loading…') }}
		</p>

		<p
			v-else-if="store.count === 0"
			class="keepiq-delegation-manager__empty"
			data-testid="delegation-manager-empty">
			{{ t('keepiq', 'No active delegations for this secret.') }}
		</p>

		<ul v-else class="keepiq-delegation-manager__rows">
			<li
				v-for="row in store.delegations"
				:key="row.id"
				class="keepiq-delegation-manager__row"
				:data-testid="`delegation-row-${row.id}`">
				<span
					class="keepiq-delegation-manager__delegate"
					data-testid="delegation-row-delegate">
					{{ row.delegatedTo }}
				</span>
				<span
					class="keepiq-delegation-manager__status"
					:class="{
						'keepiq-delegation-manager__status--permanent':
							row.isPermanent === true,
					}"
					data-testid="delegation-row-status">
					{{
						row.isPermanent === true
							? t('keepiq', 'Permanent')
							: t('keepiq', 'Temporary')
					}}
				</span>
			</li>
		</ul>

		<div v-if="canReclaim" class="keepiq-delegation-manager__actions">
			<button
				type="button"
				:disabled="store.hasTemporary === false || store.loading"
				data-testid="delegation-manager-reclaim"
				@click="onReclaim">
				{{ t('keepiq', 'Reclaim temporary delegations') }}
			</button>
		</div>

		<p
			v-if="store.error"
			class="keepiq-delegation-manager__error"
			data-testid="delegation-manager-error">
			{{ store.error }}
		</p>
	</section>
</template>

<script>
import { useDelegationStore } from '../../store/modules/delegation.js'

export default {
	name: 'DelegationManager',

	props: {
		secretId: {
			type: String,
			required: true,
		},

		canReclaim: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['reclaimed'],

	setup() {
		const store = useDelegationStore()
		return { store }
	},

	async mounted() {
		await this.store.fetchDelegations(this.secretId)
	},

	beforeUnmount() {
		this.store.reset()
	},

	methods: {
		async onReclaim() {
			try {
				const removed = await this.store.reclaimDelegation(this.secretId)
				this.$emit('reclaimed', removed)
			} catch (e) {
				// The store already captured the error; let it render.
			}
		},
	},
}
</script>

<style scoped>
.keepiq-delegation-manager {
	margin: 16px 0;
}

.keepiq-delegation-manager__header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.keepiq-delegation-manager__header h4 {
	margin: 0;
	font-size: 1rem;
}

.keepiq-delegation-manager__count {
	background: var(--color-primary-element, #0080ff);
	color: var(--color-primary-element-text, #fff);
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 0.8rem;
}

.keepiq-delegation-manager__rows {
	list-style: none;
	padding: 0;
	margin: 0;
}

.keepiq-delegation-manager__row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border, #eee);
}

.keepiq-delegation-manager__delegate {
	font-weight: 500;
}

.keepiq-delegation-manager__status {
	font-size: 0.85rem;
	padding: 2px 8px;
	border-radius: 999px;
	background: var(--color-background-dark, #f0f0f0);
}

.keepiq-delegation-manager__status--permanent {
	/* The only genuinely hardcoded colour in the app. White is not a safe
	   foreground for --color-warning, which is a lighter yellow in dark mode;
	   --color-warning-text is the paired value that stays readable on it. */
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.keepiq-delegation-manager__actions {
	margin-top: 12px;
}

.keepiq-delegation-manager__error {
	color: var(--color-error-text);
	margin: 8px 0;
}

.keepiq-delegation-manager__empty,
.keepiq-delegation-manager__loading {
	color: var(--color-text-maxcontrast, #777);
	margin: 8px 0;
}
</style>
