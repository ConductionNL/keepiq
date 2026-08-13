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
	<section class="doriath-delegation-manager" data-testid="delegation-manager">
		<header class="doriath-delegation-manager__header">
			<h4>{{ t('doriath', 'Delegations') }}</h4>
			<span
				v-if="store.count > 0"
				class="doriath-delegation-manager__count"
				data-testid="delegation-manager-count">
				{{ store.count }}
			</span>
		</header>

		<p v-if="store.loading" class="doriath-delegation-manager__loading">
			{{ t('doriath', 'Loading…') }}
		</p>

		<p
			v-else-if="store.count === 0"
			class="doriath-delegation-manager__empty"
			data-testid="delegation-manager-empty">
			{{ t('doriath', 'No active delegations for this secret.') }}
		</p>

		<ul v-else class="doriath-delegation-manager__rows">
			<li
				v-for="row in store.delegations"
				:key="row.id"
				class="doriath-delegation-manager__row"
				:data-testid="`delegation-row-${row.id}`">
				<span
					class="doriath-delegation-manager__delegate"
					data-testid="delegation-row-delegate">
					{{ row.delegatedTo }}
				</span>
				<span
					class="doriath-delegation-manager__status"
					:class="{
						'doriath-delegation-manager__status--permanent':
							row.isPermanent === true,
					}"
					data-testid="delegation-row-status">
					{{
						row.isPermanent === true
							? t('doriath', 'Permanent')
							: t('doriath', 'Temporary')
					}}
				</span>
			</li>
		</ul>

		<div v-if="canReclaim" class="doriath-delegation-manager__actions">
			<button
				type="button"
				:disabled="store.hasTemporary === false || store.loading"
				data-testid="delegation-manager-reclaim"
				@click="onReclaim">
				{{ t('doriath', 'Reclaim temporary delegations') }}
			</button>
		</div>

		<p
			v-if="store.error"
			class="doriath-delegation-manager__error"
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
.doriath-delegation-manager {
	margin: 16px 0;
}

.doriath-delegation-manager__header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.doriath-delegation-manager__header h4 {
	margin: 0;
	font-size: 1rem;
}

.doriath-delegation-manager__count {
	background: var(--color-primary-element, #0080ff);
	color: var(--color-primary-element-text, #fff);
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 0.8rem;
}

.doriath-delegation-manager__rows {
	list-style: none;
	padding: 0;
	margin: 0;
}

.doriath-delegation-manager__row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border, #eee);
}

.doriath-delegation-manager__delegate {
	font-weight: 500;
}

.doriath-delegation-manager__status {
	font-size: 0.85rem;
	padding: 2px 8px;
	border-radius: 999px;
	background: var(--color-background-dark, #f0f0f0);
}

.doriath-delegation-manager__status--permanent {
	background: var(--color-warning, #f5a623);
	color: #fff;
}

.doriath-delegation-manager__actions {
	margin-top: 12px;
}

.doriath-delegation-manager__error {
	color: var(--color-error, #c00);
	margin: 8px 0;
}

.doriath-delegation-manager__empty,
.doriath-delegation-manager__loading {
	color: var(--color-text-maxcontrast, #777);
	margin: 8px 0;
}
</style>
