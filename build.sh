#!/usr/bin/env bash

# Assumes PHP CLI, curl, and zip are already installed.

# check if node is installed and install it if not
if ! command -v node &> /dev/null; then
  echo "Node.js is not installed. Installing Node.js and NPM using NVM..."

  # install NVM, Node, and NPM
  curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/master/install.sh | bash
  export NVM_DIR="$HOME/.nvm"
  [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"  # This loads nvm
#  [ -s "$NVM_DIR/bash_completion" ] && \. "$NVM_DIR/bash_completion"  # This loads nvm bash_completion
  nvm install node
fi

echo "Update version in various json files..."
node ./buildPipeline/versionUpdate.js

echo "Update NPM packages..."
npm update --no-audit --prefer-offline

echo "Cleaning up old build directory..."
rm -r build
if [ -f touchpoint-wp.zip ]; then
  rm touchpoint-wp.zip
fi
mkdir build
mkdir build/assets
mkdir build/assets/js

echo "Install uglify and uglify the JS files..."
#echo $(npm install -g uglify-js)
uglifyjs assets/js/base-defer.js -o build/assets/js/base-defer.min.js --source-map
uglifyjs assets/js/meeting-defer.js -o build/assets/js/meeting-defer.min.js --source-map
uglifyjs assets/js/partner-defer.js -o build/assets/js/partner-defer.min.js --source-map
cp -r assets build
cd ./build || exit
cd ..

echo "Build blocks..."
#npm install -g @wordpress/scripts
npx wp-scripts build --webpack-src-dir=blocks --output-path=build/blocks
npx wp-scripts build-blocks-manifest --input=blocks --output=build/blocks/blocks-manifest.php

echo "Internationalization..."

cp -r ./i18n ./build/i18n

php ./wp-cli.phar i18n make-json ./build/i18n
php ./wp-cli.phar i18n make-mo ./build/i18n
cp ./wpml-config.xml ./build/wpml-config.xml
cp ./composer.json ./build/composer.json

echo "Copy stuff..."

cp -r ./ext ./build/ext
cp -r ./src ./build/src

find . -maxdepth 1 -iname "*.php" -exec cp {} build/ \;
find . -maxdepth 1 -iname "*.md" -exec cp {} build/ \;
find . -maxdepth 1 -iname "*.json" -exec cp {} build/ \;

cd ./build || exit
find . -exec zip ../touchpoint-wp.zip {} \;
cd ..
