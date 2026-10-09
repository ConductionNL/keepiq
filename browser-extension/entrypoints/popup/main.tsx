import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App'
import { language } from './i18n'
import { holdPopupPort } from './port'

holdPopupPort()
// Screen readers pick their voice by it.
document.documentElement.lang = language()

createRoot(document.getElementById('root')!).render(
	<StrictMode>
		<App />
	</StrictMode>,
)
