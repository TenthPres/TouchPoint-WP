<?php

const PHPDOC_PHAR_URL = "https://phpdoc.org/phpDocumentor.phar";
const PHPDOC_PHAR_FILENAME = "phpdoc.phar";

if (!file_exists(PHPDOC_PHAR_FILENAME) || (time() - filemtime(PHPDOC_PHAR_FILENAME) > 86400)) {
	echo "Downloading updated PHPDoc PHAR...";
	file_put_contents(PHPDOC_PHAR_FILENAME, fopen(PHPDOC_PHAR_URL, 'r'));
	echo "    Complete.\n\n";
}


echo "Removing previous WP docs...";
array_map('unlink', glob('docs/wp-*.md'));
echo "    Complete.\n\n";


echo "Indexing WordPress Hooks...";
exec("php ./vendor/bin/wp-documentor parse --output=docs/wp-Api.md --prefix=tp_ --format=markdown ./src/TouchPoint-WP/");
echo "    Complete\n\n";


echo "Removing previous documentation files...";
array_map('unlink', glob('docs/tp-*.md'));
echo "    Complete.\n\n";


echo "Running PHPDoc Analysis...";
exec("php " . PHPDOC_PHAR_FILENAME . " -d src -t docs --template=\"xml\"");
echo "    Complete\n\n";

echo "Creating Markdown files...";
$argv[1] = "docs/structure.xml";
$argv[2] = "docs/";
$argv[3] = "--lt";
$argv[4] = "%c";
$argv[5] = "--index";
$argv[6] = "_Sidebar.md";
include "vendor/skayo/phpdoc-md/bin/phpdocmd";
echo "    Complete.\n\n";


echo "Removing xml files...";
array_map('unlink', glob('docs/*.xml'));
echo "    Complete.\n\n";


echo "Merging sidebar files...";
$sidebar = file_get_contents("docs/.Sidebar.md");
$automaticSidebar = file_get_contents("docs/_Sidebar.md");
$automaticSidebar = str_replace("API Index", "PHP API Index",$automaticSidebar);

$sidebar .= $automaticSidebar;

file_put_contents("docs/_Sidebar.md", $sidebar);
echo "    Complete.\n\n";


echo "Generating Footer...";
$footer = "Documentation generated " . date("F j, Y  g:ia.");
file_put_contents("docs/_Footer.md", $footer);
echo "    Complete.\n\n";

echo "Committing and pushing to Repository...";
echo exec("cd " . __DIR__ . "/docs && git add *.md && git commit -m \"Auto-Updated Documentation\" && git push");
echo "    Complete.\n\n";