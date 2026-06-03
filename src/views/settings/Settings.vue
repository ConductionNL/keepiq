<template>
	<div class="doriath-settings">
		<PasswordPolicySection />
		<CaHealthSection />
	</div>
</template>

<script>
import PasswordPolicySection from '../../components/settings/PasswordPolicySection.vue'
import CaHealthSection from '../../components/settings/CaHealthSection.vue'

export default {
	name: 'Settings',
	components: {
		PasswordPolicySection,
		CaHealthSection,
	},
}
</script>
