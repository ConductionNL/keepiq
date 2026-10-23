import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App'
import { language } from './i18n'
import { holdPopupPort } from './port'
import { applyTheme, mirroredTheme } from './theme'

holdPopupPort()
// Screen readers pick their voice by it.
document.documentElement.lang = language()
applyTheme(mirroredTheme())

createRoot(document.getElementById('root')!).render(
	<StrictMode>
		<App />
	</StrictMode>,
)
