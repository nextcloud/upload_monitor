/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const browserslistConfig = require('@nextcloud/browserslist-config')
const { defineConfig } = require('@rspack/cli')
const { DefinePlugin, ProgressPlugin, SwcJsMinimizerRspackPlugin } = require('@rspack/core')
const browserslist = require('browserslist')
const path = require('node:path')
const { VueLoaderPlugin } = require('vue-loader')

const browsers = browserslist(browserslistConfig)
const minBrowserVersion = browsers
	.map((str) => str.split(' '))
	.reduce((minVersion, [browser, version]) => {
		minVersion[browser] = minVersion[browser] ? Math.min(minVersion[browser], parseFloat(version)) : parseFloat(version)
		return minVersion
	}, {})
const targets = Object.entries(minBrowserVersion).map(([browser, version]) => `${browser} >=${version}`).join(',')

module.exports = defineConfig((env) => {
	const appName = process.env.npm_package_name

	const mode = (env.development && 'development') || (env.production && 'production') || process.env.NODE_ENV || 'production'
	const isDev = mode === 'development'
	process.env.NODE_ENV = mode

	return {
		target: 'web',
		mode,
		devtool: isDev ? 'cheap-source-map' : 'source-map',

		entry: {
			main: path.join(__dirname, 'src', 'main.js'),
		},

		output: {
			path: path.resolve('./js'),
			filename: `${appName}-[name].js?v=[contenthash]`,
			chunkFilename: `${appName}-[name].js?v=[contenthash]`,
			publicPath: 'auto',
			clean: true,
			devtoolNamespace: appName,
			devtoolModuleFilenameTemplate(info) {
				const rootDir = process.cwd()
				const rel = path.relative(rootDir, info.absoluteResourcePath)
				return `webpack:///${appName}/${rel}`
			},
		},

		optimization: {
			chunkIds: 'named',
			splitChunks: {
				automaticNameDelimiter: '-',
				cacheGroups: {
					defaultVendors: {
						reuseExistingChunk: true,
					},
				},
			},
			minimize: !isDev,
			minimizer: [
				new SwcJsMinimizerRspackPlugin({
					minimizerOptions: {
						targets,
					},
				}),
			],
		},

		module: {
			rules: [
				{
					test: /\.vue$/,
					loader: 'vue-loader',
					options: {
						experimentalInlineMatchResource: true,
					},
				},
				{
					test: /\.css$/,
					use: ['style-loader', 'css-loader'],
				},
				{
					test: /\.scss$/,
					use: ['style-loader', 'css-loader', 'sass-loader'],
				},
				{
					test: /\.(png|jpe?g|gif|svg|webp)$/i,
					type: 'asset',
				},
			],
		},

		plugins: [
			new ProgressPlugin(),
			new VueLoaderPlugin(),
			new DefinePlugin({
				__VUE_OPTIONS_API__: true,
				__VUE_PROD_DEVTOOLS__: false,
				__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
			}),
		],

		resolve: {
			extensions: ['*', '.js', '.vue'],
			symlinks: false,
			fallback: {
				stream: false,
			},
		},

		cache: true,
	}
})
