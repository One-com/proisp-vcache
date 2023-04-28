const defaultConfig = require("@wordpress/scripts/config/webpack.config");
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        'block-register': './src/awp-custom-postmeta-register.js',
    },
    output: {
        path: path.join(__dirname, './onecom-addons/assets/js/blocks'),
        filename: 'block-metabox.js'
    }
}