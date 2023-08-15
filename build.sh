#!/usr/bin/env bash

# Assumes PHP CLI, curl, and zip are already installed.

# install NVM, Node, and NPM
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/master/install.sh | bash
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"  # This loads nvm
[ -s "$NVM_DIR/bash_completion" ] && \. "$NVM_DIR/bash_completion"  # This loads nvm bash_completion
nvm install node

npm update

rm -r build
rm touchpoint-wp.zip
mkdir build
mkdir build/assets
mkdir build/assets/js

# install uglify and uglify the JS files.
echo $(npm install -g uglify-js)
uglifyjs -o build/assets/js/base-defer.min.js -- assets/js/base-defer.js
uglifyjs -o build/assets/js/meeting-defer.min.js -- assets/js/meeting-defer.js
uglifyjs -o build/assets/js/partner-defer.min.js -- assets/js/partner-defer.js
cp -r assets build
cd ./build || exit
cd ..


# compile translations
if [ ! -f wp-cli.phar ]; then
    wget -O wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
fi

cp -r ./i18n ./build/i18n

php ./wp-cli.phar i18n make-json ./build/i18n
php ./wp-cli.phar i18n make-mo ./build/i18n

cp -r ./ext ./build/ext
cp -r ./src ./build/src

find . -maxdepth 1 -iname "*.php" -exec cp {} build/ \;
find . -maxdepth 1 -iname "*.md" -exec cp {} build/ \;
find . -maxdepth 1 -iname "*.json" -exec cp {} build/ \;

cd ./build || exit
find . -exec zip ../touchpoint-wp.zip {} \;
cd ..
