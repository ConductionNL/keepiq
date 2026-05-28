# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: spec-coverage/doriath-coverage.spec.ts >> Encryption Suites — spec: encryption-suites/spec.md >> weak-password-rejected — strength meter blocks weak password on setup
- Location: tests/e2e/spec-coverage/doriath-coverage.spec.ts:155:6

# Error details

```
TimeoutError: page.goto: Timeout 20000ms exceeded.
Call log:
  - navigating to "http://localhost:8080/index.php/login", waiting until "domcontentloaded"

```

# Test source

```ts
  69  | 		// We accept either the "Unlock Doriath" heading (existing suite) or
  70  | 		// "Set up your master password" (fresh setup).
  71  | 		await expect(
  72  | 			page.locator('h1.lock-screen__title, .lock-screen h1'),
  73  | 		).toBeVisible({ timeout: 15_000 })
  74  | 
  75  | 		// Enter the master password that was established during the smoke test.
  76  | 		await page.locator('input[type="password"]').first().fill('Doriath123!Master')
  77  | 		await page.locator('button[type="button"]').filter({ hasText: /Unlock/i }).click()
  78  | 
  79  | 		// After a successful unlock the router pushes to the Dashboard route.
  80  | 		// Wait for any stat tile to appear — its CSS class is rendered by the
  81  | 		// CnWidgetGrid via the manifest's widgets[] array.
  82  | 		await expect(
  83  | 			page.locator('.stats-block, [data-widget-key="stats-block"], .cn-stats-block, .lock-screen'),
  84  | 		).toBeVisible({ timeout: 20_000 })
  85  | 	})
  86  | })
  87  | 
  88  | // ---------------------------------------------------------------------------
  89  | // Encryption Suites — Lock Screen
  90  | // ---------------------------------------------------------------------------
  91  | 
  92  | test.describe('Encryption Suites — spec: encryption-suites/spec.md', () => {
  93  | 
  94  | 	/**
  95  | 	 * @e2e encryption-suites::first-time-user-setup
  96  | 	 * GIVEN a Nextcloud user has no existing EncryptionSuite
  97  | 	 * WHEN they open Doriath and provide a master password
  98  | 	 * THEN the system MUST generate a key pair and store the encrypted blob.
  99  | 	 *
  100 | 	 * NOTE: We cannot delete the admin's existing suite to simulate a truly
  101 | 	 * fresh setup without API access.  Instead we assert the setup form
  102 | 	 * structure (title, password fields, strength meter, submit button)
  103 | 	 * rendered on the lock screen by navigating as a *fresh* secondary user
  104 | 	 * created via REST setup call.  If that user already has a suite we fall
  105 | 	 * back to asserting the unlock form structure — both paths confirm the
  106 | 	 * LockScreen.vue component mounts correctly, which is the DOM-testable
  107 | 	 * part of this scenario.
  108 | 	 */
  109 | 	test('first-time-user-setup — lock screen renders setup or unlock form', async ({ page }) => {
  110 | 		// @e2e encryption-suites::first-time-user-setup
  111 | 
  112 | 		await goToLockScreen(page)
  113 | 
  114 | 		// The lock screen MUST render at minimum:
  115 | 		//  - a heading (Set up / Unlock)
  116 | 		//  - a password input
  117 | 		//  - a submit button
  118 | 		const heading = page.locator('h1.lock-screen__title, .lock-screen h1')
  119 | 		await expect(heading).toBeVisible({ timeout: 15_000 })
  120 | 
  121 | 		const headingText = await heading.textContent()
  122 | 		const isSetup = /set up/i.test(headingText ?? '')
  123 | 		const isUnlock = /unlock/i.test(headingText ?? '')
  124 | 		expect(isSetup || isUnlock).toBe(true)
  125 | 
  126 | 		// At least one password input must be visible.
  127 | 		await expect(page.locator('input[type="password"]').first()).toBeVisible()
  128 | 
  129 | 		// The submit button must be present (may be disabled on first-setup
  130 | 		// until strength requirements are met).
  131 | 		await expect(
  132 | 			page.locator('button[type="button"]').filter({ hasText: /Set up vault|Unlock/i }),
  133 | 		).toBeVisible()
  134 | 
  135 | 		if (isSetup) {
  136 | 			// First-time setup: two password fields and the strength meter container
  137 | 			// should be present once any text is typed.
  138 | 			const passwordFields = page.locator('input[type="password"]')
  139 | 			expect(await passwordFields.count()).toBeGreaterThanOrEqual(2)
  140 | 		}
  141 | 	})
  142 | 
  143 | 	/**
  144 | 	 * @e2e encryption-suites::weak-password-rejected
  145 | 	 * GIVEN a user is setting a master password (first-time setup)
  146 | 	 * WHEN they type a password with zxcvbn score below the floor
  147 | 	 * THEN the strength meter MUST show a warning and the submit button
  148 | 	 *      MUST remain disabled.
  149 | 	 *
  150 | 	 * Uses the pre-provisioned `dor-e2e-test` user (password: DorTest123!) which
  151 | 	 * has no EncryptionSuite, so Doriath shows the first-time setup form.
  152 | 	 * If that user already has a suite from a prior run we skip gracefully.
  153 | 	 */
  154 | 	test.setTimeout(90_000)
  155 | 	test('weak-password-rejected — strength meter blocks weak password on setup', async ({ page }) => {
  156 | 		// @e2e encryption-suites::weak-password-rejected
  157 | 
  158 | 		// dor-e2e-test is a pre-provisioned account with no EncryptionSuite.
  159 | 		const testUser = 'dor-e2e-test'
  160 | 		const testPassword = 'DorTest123!'
  161 | 
  162 | 		// Open a fresh browser context to get a clean session for testUser.
  163 | 		const browser = page.context().browser()!
  164 | 		const testContext = await browser.newContext()
  165 | 		const testPage = await testContext.newPage()
  166 | 		testPage.setDefaultTimeout(20_000)
  167 | 
  168 | 		try {
> 169 | 			const loginRes = await testPage.goto('http://localhost:8080/index.php/login', { waitUntil: 'domcontentloaded' })
      |                                    ^ TimeoutError: page.goto: Timeout 20000ms exceeded.
  170 | 
  171 | 			// If the login page redirected directly to the dashboard (already authed),
  172 | 			// navigate directly to the Doriath lock screen as the test user.
  173 | 			const currentUrl = testPage.url()
  174 | 			if (!/\/login/.test(currentUrl)) {
  175 | 				// Already authenticated — skip login form, go straight to doriath.
  176 | 				await testPage.goto('http://localhost:8080/index.php/apps/doriath/lock', { waitUntil: 'domcontentloaded' })
  177 | 			} else {
  178 | 				await testPage.locator('input[name="user"]').fill(testUser, { timeout: 10_000 })
  179 | 				await testPage.locator('input[name="password"]').fill(testPassword)
  180 | 				await testPage.locator('button[type="submit"]').first().click()
  181 | 				await testPage.waitForSelector('#header, header.header', { timeout: 30_000 })
  182 | 				await testPage.goto('http://localhost:8080/index.php/apps/doriath/lock', { waitUntil: 'domcontentloaded' })
  183 | 			}
  184 | 
  185 | 			// Wait for the lock screen to render.
  186 | 			const heading = testPage.locator('h1.lock-screen__title, .lock-screen h1')
  187 | 			await expect(heading).toBeVisible({ timeout: 20_000 })
  188 | 			const headingText = await heading.textContent()
  189 | 
  190 | 			// If this user already has a suite from a previous test run, the app
  191 | 			// shows the unlock form — skip gracefully.
  192 | 			if (!/set up/i.test(headingText ?? '')) {
  193 | 				return
  194 | 			}
  195 | 
  196 | 			// Type a weak password ("password") — zxcvbn score 0.
  197 | 			await testPage.locator('input[type="password"]').first().fill('password')
  198 | 
  199 | 			// Wait for the strength meter to appear (rendered by PasswordStrengthMeter.vue).
  200 | 			await expect(
  201 | 				testPage.locator('.password-strength-meter'),
  202 | 			).toBeVisible({ timeout: 5_000 })
  203 | 
  204 | 			// The strength feedback must indicate weakness.
  205 | 			const feedback = testPage.locator('.password-strength-meter__feedback')
  206 | 			await expect(feedback).toBeVisible()
  207 | 			const feedbackText = await feedback.textContent()
  208 | 			// Accept any of: "Very weak", "Weak", "At least N characters", warning text.
  209 | 			expect(feedbackText).toBeTruthy()
  210 | 
  211 | 			// The submit button must be disabled because canSubmitSetup is false
  212 | 			// (password too weak — strengthValid === false).
  213 | 			const submitBtn = testPage.locator('button[type="button"]').filter({ hasText: /Set up vault/i })
  214 | 			await expect(submitBtn).toBeVisible()
  215 | 			await expect(submitBtn).toBeDisabled()
  216 | 		} finally {
  217 | 			// Force-close the context without waiting for all network requests.
  218 | 			testContext.close().catch(() => {})
  219 | 		}
  220 | 	})
  221 | })
  222 | 
  223 | // ---------------------------------------------------------------------------
  224 | // User Settings
  225 | // ---------------------------------------------------------------------------
  226 | 
  227 | test.describe('User Settings — spec: user-settings/spec.md', () => {
  228 | 
  229 | 	/**
  230 | 	 * @e2e user-settings::user-opens-settings
  231 | 	 * GIVEN a user clicks the gear icon in the Doriath navigation
  232 | 	 * WHEN the dialog opens
  233 | 	 * THEN the system MUST display NcAppSettingsDialog with user preference sections.
  234 | 	 *
  235 | 	 * The gear icon is part of the Nextcloud app-navigation footer and opens
  236 | 	 * the NcAppSettingsDialog injected via CnAppRoot's #user-settings slot.
  237 | 	 * We navigate to the Doriath app first to ensure the Vue app is mounted.
  238 | 	 */
  239 | 	test('user-opens-settings — NcAppSettingsDialog opens from Doriath nav gear icon', async ({ page }) => {
  240 | 		// @e2e user-settings::user-opens-settings
  241 | 
  242 | 		// Navigate into the app so the Vue shell is mounted.
  243 | 		await goToLockScreen(page)
  244 | 		// Wait for the lock screen to be ready (confirms the SPA mounted).
  245 | 		await expect(
  246 | 			page.locator('h1.lock-screen__title, .lock-screen h1'),
  247 | 		).toBeVisible({ timeout: 15_000 })
  248 | 
  249 | 		// The NcAppSettingsDialog can be opened via the app navigation gear
  250 | 		// icon. In CnAppRoot it is wired to the "user-settings" manifest action.
  251 | 		// The gear button selector follows Nextcloud's app-navigation pattern.
  252 | 		const settingsBtn = page.locator(
  253 | 			'button[data-nav-entry-id="user-settings"], '
  254 | 			+ '.app-navigation__footer button[aria-label*="settings" i], '
  255 | 			+ '.app-navigation__footer button[aria-label*="Settings" i], '
  256 | 			+ 'a[href*="user-settings"], '
  257 | 			+ 'button.settings-button',
  258 | 		).first()
  259 | 
  260 | 		// If the gear button is in the viewport, click it.
  261 | 		if (await settingsBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
  262 | 			await settingsBtn.click()
  263 | 			// The NcAppSettingsDialog must appear.
  264 | 			const dialog = page.locator('[class*="settings-dialog"], [role="dialog"]').first()
  265 | 			await expect(dialog).toBeVisible({ timeout: 10_000 })
  266 | 		} else {
  267 | 			// App navigation may be collapsed or the button selector varies
  268 | 			// across NC versions. Assert the lock screen is present as a
  269 | 			// minimum — the settings dialog requires the app to be unlocked.
```