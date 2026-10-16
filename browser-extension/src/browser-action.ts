/**
 * The toolbar-action API renamed between manifest versions: `browser.action` on
 * MV3 (Chrome), `browser.browserAction` on MV2 (Firefox). Resolve it once here so
 * nothing else in the codebase has to care which target it's building for.
 *
 * `BrowserAction.Static` extends `Action.Static`, so the shared surface
 * (`setIcon`, `setBadgeText`, `setTitle`, …) is identically typed on both.
 */
export const action = browser.action ?? browser.browserAction
