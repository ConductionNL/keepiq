import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { POPUP_PORT } from '@/src/messages'
import App from './App'

// Held open for the popup's lifetime; its disconnect is the "Immediately" timeout.
browser.runtime.connect({ name: POPUP_PORT })

createRoot(document.getElementById('root')!).render(
	<StrictMode>
		<App />
	</StrictMode>,
)
