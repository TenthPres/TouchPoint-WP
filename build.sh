#!/usr/bin/env bash

# Assumes PHP CLI, curl, and zip are already installed.

# install NVM, Node, and NPM
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/master/install.sh | bash
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"  # This loads nvm
[ -s "$NVM_DIR/bash_completion" ] && \. "$NVM_DIR/bash_completion"  # This loads nvm bash_completion
nvm install node

# update version in various json files
node ./buildPipeline/versionUpdate.js

# update NPM packages
npm update

rm -r build
rm touchpoint-wp.zip
mkdir build
mkdir build/assets
mkdir build/assets/js

# install uglify and uglify the JS files.
echo $(npm install -g uglify-js)
uglifyjs assets/js/base-defer.js -o build/assets/js/base-defer.min.js --source-map
uglifyjs assets/js/meeting-defer.js -o build/assets/js/meeting-defer.min.js --source-map
uglifyjs assets/js/partner-defer.js -o build/assets/js/partner-defer.min.js --source-map
cp -r assets build
cd ./build || exit
cd ..

# build blocks
npm install -g @wordpress/scripts
wp-scripts build --webpack-src-dir=blocks --output-path=build/blocks
wp-scripts build-blocks-manifest --input=blocks --output=build/blocks/blocks-manifest.php

cp -r ./i18n ./build/i18n

php ./wp-cli.phar i18n make-json ./build/i18n
php ./wp-cli.phar i18n make-mo ./build/i18n
cp ./wpml-config.xml ./build/wpml-config.xml
cp ./composer.json ./build/composer.json

cp -r ./ext ./build/ext
cp -r ./src ./build/src

find . -maxdepth 1 -iname "*.php" -exec cp {} build/ \;
find . -maxdepth 1 -iname "*.md" -exec cp {} build/ \;
find . -maxdepth 1 -iname "*.json" -exec cp {} build/ \;

cd ./build || exit
find . -exec zip ../touchpoint-wp.zip {} \;
cd ..
