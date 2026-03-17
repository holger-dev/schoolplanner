const path = require('path')
const nextcloudWebpackConfig = require('@nextcloud/webpack-vue-config')

module.exports = {
	...nextcloudWebpackConfig,
	entry: {
		schoolplanner: path.join(__dirname, 'src', 'main.js'),
	},
	output: {
		path: path.resolve(__dirname, 'js'),
		filename: '[name].js',
		clean: false,
	},
}

