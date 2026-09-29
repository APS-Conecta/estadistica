const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// entry 'main' → js/estadistica-main.js (PageController), 'admin' →
// js/estadistica-admin.js (AdminSettings::getForm) — territorio's two-entry shape.
webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
	admin: path.join(__dirname, 'src', 'admin.js'),
}

module.exports = webpackConfig
