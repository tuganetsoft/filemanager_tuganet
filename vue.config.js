module.exports = {
  indexPath: 'main.html',
  filenameHashing: false,
  css: {
        extract: true
  },
  configureWebpack: config => {
    config.entry = {
      app: [
        './frontend/main.js'
      ]
    }
  },
  devServer: {
    host: '0.0.0.0',
    port: 5000,
    allowedHosts: 'all',
    headers: {
      'Cache-Control': 'no-store'
    }
  }
}
