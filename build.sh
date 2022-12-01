#!/usr/bin/env bash

# Assumes PHP CLI, curl, and zip are already installed.

# install NVM, Node, and NPM
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/master/install.sh | bash
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"  # This loads nvm
[ -s "$NVM_DIR/bash_completion" ] && \. "$NVM_DIR/bash_completion"  # This loads nvm bash_completion
nvm install node

rm -r build
rm TouchPoint-WP.zip
mkdir build

# install uglify and uglify the JS files.
echo $(npm install -g uglify-js)
uglifyjs -o build/assets/js/base-defer.min.js -- assets/js/base-defer.js
uglifyjs -o build/assets/js/meeting-defer.min.js -- assets/js/meeting-defer.js
uglifyjs -o build/assets/js/partner-defer.min.js -- assets/js/partner-defer.js
cp -r assets build/assets
zip -r TouchPoint-WP.zip ./build/assets


# compile translations
wget -O wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

cp -r i18n build/i18n

php ./wp-cli.phar i18n make-json build/i18n
php ./wp-cli.phar i18n make-mo build/i18n
find . -maxdepth 1 -iname "build/i18n/*.php" -exec zip TouchPoint-WP.zip {} \;

zip -r TouchPoint-WP.zip ./ext
zip -r TouchPoint-WP.zip ./src

find . -maxdepth 1 -iname "*.php" -exec zip TouchPoint-WP.zip {} \;
find . -maxdepth 1 -iname "*.md" -exec zip TouchPoint-WP.zip {} \;
find . -maxdepth 1 -iname "*.json" -exec zip TouchPoint-WP.zip {} \;

rm -r build