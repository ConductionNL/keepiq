import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import LockScreen from '../views/LockScreen.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'
import { useSessionStore } from '../store/modules/session.js'

Vue.use(Router)

const router = new Router({
	mode: 'history',
	base: generateUrl('/apps/doriath'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		{ path: '/lock', name: 'Lock', component: LockScreen },
		{ path: '/settings', name: 'Settings', component: AdminRoot },
		{ path: '*', redirect: '/' },
	],
})

// Lock screen navigation guard.
router.beforeEach((to, from, next) => {
	const session = useSessionStore()

	// Public routes skip the lock screen.
	if (to.meta?.public === true || to.name === 'Lock') {
		// If already unlocked and going to lock, redirect to dashboard.
		if (to.name === 'Lock' && !session.isLocked) {
			return next({ name: 'Dashboard' })
		}
		return next()
	}

	// If locked, redirect to lock screen with return URL.
	if (session.isLocked) {
		return next({
			name: 'Lock',
			query: { returnUrl: to.fullPath },
		})
	}

	// Update activity on route change.
	session.updateActivity()
	next()
})

export default router
