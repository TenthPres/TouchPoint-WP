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


echo "Correcting links in WordPress Hooks...";
$doc = file_get_contents("docs/wp-Api.md");
$pattern = '/\[(\.[\S-]+)]\(([\S]+)\), \[line (\d+)\]\(([\S-]+)/';
$replace = "[src/TouchPoint-WP/$2](https://github.com/TenthPres/TouchPoint-WP/blob/master/src/TouchPoint-WP/$2), [line $3](https://github.com/TenthPres/TouchPoint-WP/blob/master/src/TouchPoint-WP/$4\n\n";
$doc = preg_replace($pattern, $replace, $doc);
file_put_contents("docs/wp-Api.md", $doc);
echo "    Complete\n\n";

echo "Removing previous documentation files...";
array_map('unlink', glob('docs/tp-*.md'));
echo "    Complete.\n\n";


const MARKDOWN_TEMP_DIR = ".phpdoc/markdown";

echo "Running PHPDoc Analysis...";
if (is_dir(MARKDOWN_TEMP_DIR)) {
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator(MARKDOWN_TEMP_DIR, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($files as $file) {
		$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
	}
}
exec("php " . PHPDOC_PHAR_FILENAME . " -d src -t " . MARKDOWN_TEMP_DIR .
	 " --template=\"vendor/saggre/phpdocumentor-markdown/themes/markdown\"");
echo "    Complete\n\n";


// The markdown template writes one file per class in nested directories (classes/tp/TouchPointWP/Api.md) with relative
// links.  GitHub wikis link pages by name only, so flatten into tp-TouchPointWP-Api.md and rewrite the links to match.
echo "Creating Markdown files...";
$classDir = MARKDOWN_TEMP_DIR . "/classes/";
$classFiles = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($classDir, FilesystemIterator::SKIP_DOTS)) as $file) {
	$path = str_replace('\\', '/', $file->getPathname());
	$classFiles[$path] = substr($path, strlen($classDir), -3); // e.g. tp/TouchPointWP/Api
}
$pageNames = array_flip(str_replace('/', '-', $classFiles));

$index = [];
foreach ($classFiles as $path => $rel) {
	$dir = dirname($rel);
	$md = preg_replace_callback('~\[([^\]]*)\]\((\.{1,2}/[^)#]*)(#[^)]*)?\)~', function ($m) use ($dir, $pageNames) {
		$parts = [];
		foreach (explode('/', $dir . '/' . $m[2]) as $part) {
			if ($part === '..') {
				array_pop($parts);
			} elseif ($part !== '.' && $part !== '') {
				$parts[] = $part;
			}
		}
		$target = implode('-', $parts);

		// Classes outside the plugin (e.g. WP_Post) have no page, so leave them as plain text.
		return isset($pageNames[$target]) ? "[$m[1]]($target" . ($m[3] ?? '') . ")" : $m[1];
	}, file_get_contents($path));

	$page = str_replace('/', '-', $rel);
	file_put_contents("docs/$page.md", str_replace('/', '\\', $rel) . "\n===============\n" . $md);
	$index[str_replace('/', '\\', $dir)][basename($rel)] = $page;
}

uksort($index, 'strnatcasecmp');
$indexMd = "API Index\n=========\n\n";
foreach ($index as $namespace => $pages) {
	uksort($pages, 'strnatcasecmp');
	$indexMd .= "* $namespace\n";
	foreach ($pages as $name => $page) {
		$indexMd .= "    * [$name]($page)\n";
	}
}
file_put_contents("docs/_Sidebar.md", $indexMd);
echo "    Complete.\n\n";


echo "Merging sidebar files...";
$sidebar = file_get_contents("docs/.Sidebar.md");
$indexMd = str_replace("API Index", "PHP API Index",$indexMd);

$sidebar .= $indexMd;

file_put_contents("docs/_Sidebar.md", $sidebar);
echo "    Complete.\n\n";


echo "Generating Footer...";
$footer = "Documentation generated " . date("F j, Y  g:ia.");
file_put_contents("docs/_Footer.md", $footer);
echo "    Complete.\n\n";

echo "Committing and pushing to Repository...";
echo exec("cd " . __DIR__ . "/docs && git add *.md && git commit -m \"Auto-Updated Documentation\" && git push");
echo "    Complete.\n\n";