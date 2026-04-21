#!/usr/bin/env bash

# Assumes PHP CLI, curl, and zip are already installed.

# get start time
START_TIME=$SECONDS

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

echo "Install NPM packages for build..."
npm install --prefer-offline --no-audit --progress=false
npm update --prefer-offline --progress=false

echo "Update version in various json and js files..."
node ./buildPipeline/versionUpdate.js

echo "Cleaning up old build directory..."
rm -rf build
if [ -f touchpoint-wp.zip ]; then
  rm touchpoint-wp.zip
fi
mkdir build
mkdir build/assets
mkdir build/assets/js

echo "Install uglify and uglify the JS files..."
#npm install -g uglify-js
uglifyjs assets/js/base-defer.js -o build/assets/js/base-defer.min.js --source-map
uglifyjs assets/js/meeting-defer.js -o build/assets/js/meeting-defer.min.js --source-map
uglifyjs assets/js/partner-defer.js -o build/assets/js/partner-defer.min.js --source-map
cp -r assets build
cd ./build || exit
cd ..

echo "Build blocks..."
#npm install -g @wordpress/scripts
npm run build-blocks

echo "Internationalization..."
cp -r ./i18n ./build/i18n
php ./wp-cli.phar i18n make-json ./build/i18n
php ./wp-cli.phar i18n make-mo ./build/i18n
php ./wp-cli.phar i18n make-php ./build/i18n
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

# get end time and calculate duration
END_TIME=$SECONDS
DURATION=$((END_TIME - START_TIME))
echo -e "\e[34mBuild completed in $DURATION seconds.\e[0m"