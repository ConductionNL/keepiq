<template>
	<div class="doriath-settings">
		<PasswordPolicySection />
		<CaHealthSection />
		<ApplicationQueueSection />
	</div>
</template>

<script>
import PasswordPolicySection from '../../components/settings/PasswordPolicySection.vue'
import CaHealthSection from '../../components/settings/CaHealthSection.vue'
import ApplicationQueueSection from '../../components/settings/ApplicationQueueSection.vue'

export default {
	name: 'Settings',
	components: {
		PasswordPolicySection,
		CaHealthSection,
		ApplicationQueueSection,
	},
}
</script>
