<?php

$source = __DIR__;
$output = $argv[1] ?? ($source . '/build/onespace-toolkit.zip');
$folder = 'onespace-toolkit';

$excludeDirs = array('build', '.git', '.vscode', 'node_modules');
$excludeFiles = array('install.ps1', 'build.ps1', 'build.php', '.gitignore');

$outputDir = dirname($output);

if (!is_dir($outputDir)) {
	mkdir($outputDir, 0777, true);
}

if (file_exists($output)) {
	unlink($output);
}

$zip = new ZipArchive();

if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
	fwrite(STDERR, 'Cannot create archive: ' . $output . PHP_EOL);
	exit(1);
}

$sourceBase = str_replace('\\', '/', $source);
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
	$path = str_replace('\\', '/', $file->getPathname());
	$relative = ltrim(substr($path, strlen($sourceBase)), '/');

	if ($relative === '') {
		continue;
	}

	$top = explode('/', $relative)[0];

	if (in_array($top, $excludeDirs, true)) {
		continue;
	}

	if ($file->isDir()) {
		continue;
	}

	if (in_array($relative, $excludeFiles, true)) {
		continue;
	}

	if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'zip') {
		continue;
	}

	$zip->addFile($file->getPathname(), $folder . '/' . $relative);
}

$zip->close();

echo 'Built: ' . $output . PHP_EOL;
