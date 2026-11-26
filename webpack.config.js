const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// entry 'main' → js/estadistica-main.js (PageController), 'admin' →
// js/estadistica-admin.js (AdminSettings::getForm) — territorio's two-entry shape.
webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
	admin: path.join(__dirname, 'src', 'admin.js'),
}

// css-loader follows devtool, and the base config's production 'source-map' made it
// inline each <style> block's whole .vue file, base64, in every bundle (#23).
// The development build's 'cheap-source-map' never turned them on.
for (const rule of webpackConfig.module.rules) {
	if (rule.use) {
		rule.use = rule.use.map((loader) => (loader === 'css-loader' ? { loader, options: { sourceMap: false } } : loader))
	}
}

module.exports = webpackConfig
