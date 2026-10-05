// Sets the plugin's version everywhere it's written, other than the git tag.
//
//   npm version 0.0.98                         Set the version.  (npm runs this as the "version" script.)
//   node buildPipeline/setVersion.js 0.0.98    Set the version, without npm.
//   node buildPipeline/setVersion.js           Show the version in each place, and fail if they differ.

const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');

// Each pattern has three groups: the text before the version, the version, and the text after it.
const jsonVersion = /^(\s*"version"\s*:\s*")([^"]*)(")/m;

const targets = [
    {file: 'composer.json', pattern: jsonVersion},
    {file: 'package.json', pattern: jsonVersion},
    {file: 'package-lock.json', pattern: /("name"\s*:\s*"touchpoint-wp",\s*"version"\s*:\s*")([^"]*)(")/g},
    {file: 'touchpoint-wp.php', pattern: /^(Version:\s+)(\S+)()/m},
    {file: 'src/TouchPoint-WP/TouchPointWP.php', pattern: /(public const VERSION\s*=\s*")([^"]*)(")/},
    {file: 'src/python/WebApi.py', pattern: /^(VERSION\s*=\s*")([^"]*)(")/m},
];

for (const dir of fs.readdirSync(path.join(root, 'blocks'))) {
    if (fs.existsSync(path.join(root, 'blocks', dir, 'block.json'))) {
        targets.push({file: `blocks/${dir}/block.json`, pattern: jsonVersion});
    }
}

// When npm runs this as its "version" script, package.json already has the new version.
const newVersion = process.argv[2] ??
    (process.env.npm_lifecycle_event === 'version' ? process.env.npm_package_version : undefined);

if (newVersion !== undefined && !/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/.test(newVersion)) {
    console.error(`"${newVersion}" isn't a version number, such as 0.0.98.`);
    process.exit(1);
}

let failed = false;
const found = new Set();

for (const {file, pattern} of targets) {
    const filePath = path.join(root, file);
    const text = fs.readFileSync(filePath, 'utf8');
    const match = text.match(new RegExp(pattern.source, pattern.flags.replace('g', '')));

    if (match === null) {
        console.error(`${file}: version not found.`);
        failed = true;
        continue;
    }

    found.add(match[2]);

    if (newVersion === undefined) {
        console.log(`${file}: ${match[2]}`);
    } else if (match[2] === newVersion) {
        console.log(`${file}: already ${newVersion}`);
    } else {
        // A function is used so that the version can't be read as a group reference.
        fs.writeFileSync(filePath, text.replace(pattern, (m, before, old, after) => before + newVersion + after));
        console.log(`${file}: ${match[2]} -> ${newVersion}`);
    }
}

if (newVersion === undefined && found.size > 1) {
    console.error('The versions differ.');
    failed = true;
}

process.exit(failed ? 1 : 0);
