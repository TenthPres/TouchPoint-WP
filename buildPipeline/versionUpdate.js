

const fs = require('fs');

// get the version in composer.json
let version = JSON.parse(fs.readFileSync('./composer.json', 'utf8')).version;

// update the version in package.json
let packageJson = JSON.parse(fs.readFileSync('./package.json', 'utf8'));
packageJson.version = version;
fs.writeFileSync('./package.json', JSON.stringify(packageJson, null, 2));

// update the version in all blocks/*/block.json
const blockFiles = fs.readdirSync('./blocks');

blockFiles.forEach((blockFile) => {
    const blockPath = `./blocks/${blockFile}/block.json`;
    if (fs.existsSync(blockPath)) {
        let blockJson = JSON.parse(fs.readFileSync(blockPath, 'utf8'));
        blockJson.version = version;
        fs.writeFileSync(blockPath, JSON.stringify(blockJson, null, 2));
    }

    const blockAssetPath = `./blocks/${blockFile}/block.asset.php`;
    if (fs.existsSync(blockAssetPath)) {
        let blockAssetContent = fs.readFileSync(blockAssetPath, 'utf8');
        blockAssetContent = blockAssetContent.replaceAll("\"VERSION\"", `"${version}"`);
        fs.writeFileSync(blockAssetPath, blockAssetContent);
    }
});