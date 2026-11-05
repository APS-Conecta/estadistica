import App from './App.vue'
import { generateFilePath } from '@nextcloud/router'
import { createApp } from 'vue'

// The tabs' chunks load from wherever Nextcloud serves this app's js/
// (custom_apps/ on a suite install), not the build's default /apps/estadistica/js/.
__webpack_public_path__ = generateFilePath('estadistica', '', 'js/')

createApp(App).mount('#estadistica')
