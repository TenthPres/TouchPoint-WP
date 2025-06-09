const path = require('path');

module.exports = {
    entry: './blocks/inv-list/index.js',
    output: {
        path: path.resolve(__dirname, 'build/blocks/inv-list'),
        filename: 'inv-list/index.min.js',
    },
    module: {
        rules: [
            {
                test: /\.js$/,
                exclude: /node_modules/,
                use: {
                    loader: 'babel-loader',
                    options: {
                        presets: ['@babel/preset-env', '@babel/preset-react'],
                    },
                },
            },
            {
                test: /\.css$/,
                use: ['style-loader', 'css-loader'],
            },
        ],
    },
    mode: 'production',
};
