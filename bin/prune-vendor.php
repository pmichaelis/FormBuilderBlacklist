<?php
// Removes files of fgribreau/mailchecker that are not needed by PHP (other languages, docs).
// Runs automatically after "composer install" and "composer update".

$dir = dirname(__DIR__) . '/vendor/fgribreau/mailchecker';
if(!is_dir($dir)) exit(0);

$keep = array('platform', 'composer.json', 'LICENSE');
$remove = function($path) use (&$remove) {
	if(is_dir($path) && !is_link($path)) {
		foreach(scandir($path) as $file) {
			if($file !== '.' && $file !== '..') $remove("$path/$file");
		}
		rmdir($path);
	} else {
		unlink($path);
	}
};

foreach(scandir($dir) as $file) {
	if($file === '.' || $file === '..' || in_array($file, $keep)) continue;
	$remove("$dir/$file");
}
foreach(scandir("$dir/platform") as $file) {
	if($file === '.' || $file === '..' || $file === 'php') continue;
	$remove("$dir/platform/$file");
}
