import { startNotificationBar } from './bar'
import { startNotice } from './notice'

const notice = new URLSearchParams(location.search).get('notice')
if (notice === null) startNotificationBar()
else startNotice(notice)
