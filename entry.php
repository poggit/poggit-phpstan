#!/usr/bin/env php
<?php

/**
 * Entry.php
 *
 * Specific Exit Codes:
 * 0 - N/A (no errors, analysis complete OK).
 * 1 - 'Catchall for general errors'.
 * 2 - 'Misuse of shell builtins'.
 * ---
 * 3 - Unable to extract plugin.
 * 4 - Unable to extract dependency's.
 * 5 - Composer failed.
 * 6 - Analysis failed/found problems.
 * 7 - PHPStan failed to parse the plugin.
 * 8 - PHPStan failed, unknown cause (error emitted stderr)
 * ---
 * 9 - Unknown error.
 */

declare(strict_types=1);

//WorkingDir = /source/

echo "[Info] -> Getting ENV Variables...\n";

$PLUGIN_PATH = $_ENV["PLUGIN_PATH"] ?? "/";
$PHPSTAN_CONFIG = $_ENV["PHPSTAN_CONFIG"] ?? "default.phpstan.neon";
$DEFAULT_PHPSTAN_VERSION = $_ENV["DEFAULT_PHPSTAN_VERSION"] ?? "0.12.76";
$DEFAULT_POCKETMINE_VERSION = $_ENV["DEFAULT_POCKETMINE_VERSION"] ?? "3.17.0";

echo "[Info] -> Extracting plugin from plugin.zip, pluginPath: {$PLUGIN_PATH}...\n";

try {
	@mkdir("tmpExtractionDir");
	$zip = new ZipArchive();
	$zip->open("plugin.zip");
	$zip->extractTo("tmpExtractionDir");
	$zip->close();
	@unlink("plugin.zip");
	$folder = (scandir("tmpExtractionDir/"))[2]; //Thanks github.
	passthru("mv tmpExtractionDir/${folder}{$PLUGIN_PATH}* .");
	rrmdir("tmpExtractionDir");
} catch (Throwable $e){
	fwrite(STDERR,$e->getMessage().PHP_EOL);
	exit(3);
}

echo "[Info] -> Extracting dependency's...\n";

try {
	$folder = array_slice(scandir("poggit_deps/"), 2);
	foreach($folder as $file) {
		$phar = new Phar("poggit_deps/${file}");
		$phar2 = $phar->convertToExecutable(Phar::TAR, Phar::NONE); // Create uncompressed archive
		$phar2->extractTo("poggit_deps/", null, true);
		unlink("poggit_deps/${file}");
		unlink($phar2->getPath());
	}
} catch(Throwable $e){
	fwrite(STDERR,$e->getMessage().PHP_EOL);
	exit(4);
}

echo "[Info] -> Starting prerequisite checks...\n";

if(!is_file("plugin.yml")) {
	fwrite(STDERR, "[Error] -> plugin.yml not found. Did the container setup correctly ?".PHP_EOL);
	exit(3);
}
if(!is_dir("src")) {
	fwrite(STDERR, "[Error] -> src directory not found. Did the container setup correctly ?".PHP_EOL);
	exit(3);
}

if(is_file("phpstan.neon")) $_ENV["PHPSTAN_CONFIG"] = "phpstan.neon";
if(is_file("phpstan.neon.dist")) $_ENV["PHPSTAN_CONFIG"] = "phpstan.neon.dist";
if(is_file("poggit.phpstan.neon")) $_ENV["PHPSTAN_CONFIG"] = "poggit.phpstan.neon";
if(is_file("poggit.phpstan.neon.dist")) $_ENV["PHPSTAN_CONFIG"] = "poggit.phpstan.neon.dist";

echo "[Info] -> Checking for composer deps...\n";

//Priority goes to poggit.composer.json then composer.json
if(is_file("poggit.composer.json")){
    echo "[Info] -> Using 'poggit.composer.json'\n";
    if(is_file("composer.json")) unlink("composer.json");
    rename("poggit.composer.json", "composer.json");
}
if(is_file("composer.json")){
	echo "[Info] -> Installing dependencies from plugin...\n";
	myShellExec("composer install --no-progress", $stdout, $stderr, $code);
	if($code !== 0){
		fwrite(STDERR, "[Error] -> Failed to install dependencies from plugin composer file.".PHP_EOL);
		fwrite(STDOUT, $stdout);
		fwrite(STDERR, $stderr);
		exit(5);
	}
}
$pmmp = false;
$phpstan = false;
if(is_file("vendor/composer/InstalledVersions.php")){
	include "/source/vendor/composer/InstalledVersions.php";
	$pmmp = \Composer\InstalledVersions::isInstalled("pocketmine/pocketmine-mp");
	$phpstan = \Composer\InstalledVersions::isInstalled("phpstan/phpstan");
}
if(!$pmmp){
    echo "[Info] -> Installing default pocketmine/pocketmine-mp v{$DEFAULT_POCKETMINE_VERSION}\n";
    passthru("composer require pocketmine/pocketmine-mp:{$DEFAULT_POCKETMINE_VERSION} --no-progress -q");
}
if(!$phpstan){
	echo "[Info] -> Installing default phpstan/phpstan v{$DEFAULT_PHPSTAN_VERSION}\n";
	passthru("composer require phpstan/phpstan:{$DEFAULT_PHPSTAN_VERSION} --no-progress -q");
}

//Last check if not installed bail.
include_once "/source/vendor/composer/InstalledVersions.php";
$data = include '/source/vendor/composer/installed.php';
\Composer\InstalledVersions::reload($data);

if(!\Composer\InstalledVersions::isInstalled("pocketmine/pocketmine-mp")){
    echo "[Error] -> Failed to install pocketmine/pocketmine-mp\n";
    exit(5);
}
if(!\Composer\InstalledVersions::isInstalled("phpstan/phpstan")){
    echo "[Error] -> Failed to install phpstan/phpstan\n";
    exit(5);
}

echo "[Info] -> Using pocketmine-mp v".\Composer\InstalledVersions::getVersion("pocketmine/pocketmine-mp")."\n";
echo "[Info] -> Using phpstan v".\Composer\InstalledVersions::getVersion("phpstan/phpstan")."\n";

echo "[Info] -> Starting phpstan...\n";

myShellExec("php vendor/bin/phpstan.phar analyze --error-format=json --no-progress --memory-limit=2G -c {$PHPSTAN_CONFIG} > phpstan-results.json", $stdout, $stderr, $exitCode);
fwrite(STDOUT, $stdout);
fwrite(STDERR, $stderr); //Pass on back to poggit.
if($exitCode === 1){
	if($stderr !== "") exit(8);
	echo "[Warning] -> Analysis failed/found problems.";
	exit(6);
}
if($exitCode === 255){
	//Phpstan unable to parse
	fwrite(STDERR, "[Error] -> PHPStan (255) - Unable to parse.".PHP_EOL);
	exit(7);
}
if($exitCode !== 0) {
	//OOM Etc
	fwrite(STDERR, "[Error] -> Unhandled exit status: $exitCode.".PHP_EOL);
	exit(9);
}
echo "[Info] -> No problems found !";
exit(0);


function myShellExec(string $cmd, &$stdout, &$stderr = null, &$exitCode = null) {
	$proc = proc_open($cmd, [
		1 => ["pipe", "w"],
		2 => ["pipe", "w"]
	], $pipes, getcwd());
	$stdout = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	$exitCode = (int) proc_close($proc);
}

function rrmdir($dir) {
	if (is_dir($dir)) {
		$files = scandir($dir);
		foreach ($files as $file)
			if ($file != "." && $file != "..") rrmdir("$dir/$file");
		rmdir($dir);
	}
	else if (file_exists($dir)) unlink($dir);
}