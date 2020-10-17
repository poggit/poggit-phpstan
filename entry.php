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

echo "[Info] -> Extracting plugin from plugin.zip, pluginPath: {$_ENV["PLUGIN_PATH"]}...\n";

try {
    @mkdir("/source/tmpExtractionDir");
    $zip = new ZipArchive();
    $zip->open("/source/plugin.zip");
    $zip->extractTo("/source/tmpExtractionDir");
    $zip->close();
    @unlink("/source/plugin.zip");
    $folder = (scandir("/source/tmpExtractionDir/"))[2]; //Thanks github.
    passthru("mv /source/tmpExtractionDir/${folder}{$_ENV["PLUGIN_PATH"]}* /source/");
    rrmdir("/source/tmpExtractionDir");
} catch (Throwable $e){
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(3);
}

echo "[Info] -> Extracting dependency's...\n";

try {
    $folder = array_slice(scandir("/deps/"), 2);
    foreach($folder as $file) {
        $phar = new Phar("/deps/${file}");
        $phar2 = $phar->convertToExecutable(Phar::TAR, Phar::NONE); // Create uncompressed archive
        $phar2->extractTo("/deps/", null, true);
        unlink("/deps/${file}");
        unlink($phar2->getPath());
    }
} catch(Throwable $e){
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(4);
}

echo "[Info] -> Starting prerequisite checks...\n";

$DEFAULT_INSTALLED = false;
$DEFAULT_PMMP_V = "^3.0.0";
$DEFAULT_PHPSTAN_V = "^0.12.0";
$_ENV["PHPSTAN_CONFIG"] = $_ENV["DEFAULT_PHPSTAN_CONFIG"];

if(is_file("/source/phpstan.neon.dist")) $_ENV["PHPSTAN_CONFIG"] = "/source/phpstan.neon.dist";
if(is_file("/source/phpstan.neon")) $_ENV["PHPSTAN_CONFIG"] = "/source/phpstan.neon";
if(!is_file("/source/composer.json")){
    $DEFAULT_INSTALLED = true;
    echo "[Info] -> Installing pocketmine/pocketmine-mp:{$DEFAULT_PMMP_V} & phpstan/phpstan:{$DEFAULT_PHPSTAN_V}\n";
	myShellExec("composer require phpstan/phpstan:{$DEFAULT_PHPSTAN_V} pocketmine/pocketmine-mp:{$DEFAULT_PMMP_V} --no-suggest --no-progress", $stdout, $null, $code);
    if($code !== 0){
        // Should never happen.
		fwrite(STDERR, "[Error] -> Failed to install default packages!".PHP_EOL);
		exit(5);
    }
}

if(is_file("/source/plugin.yml")) {
    if(!is_dir("/source/src")) {
        fwrite(STDERR, "[Error] -> src directory not found. Did the container setup correctly ?".PHP_EOL);
        exit(3);
    }
} else {
    fwrite(STDERR, "[Error] -> plugin.yml not found. Did the container setup correctly ?".PHP_EOL);
    exit(3);
}

if(!$DEFAULT_INSTALLED) {
	passthru("composer install --no-suggest --no-progress -q", $exitCode);
	if ($exitCode !== 0) {
		fwrite(STDERR, "[Error] -> Failed to run initial composer install !" . PHP_EOL);
		exit(5);
	}
}

function get_composer_status(){
	myShellExec("composer show --format=json", $stdout, $stderr, $exitCode);

	if($exitCode !== 0){
		fwrite(STDERR, "[Error] -> Failed to query composer packages installed.".PHP_EOL);
		fwrite(STDERR, $stderr);
		exit(5);
	}

	$data = json_decode($stdout, true);
	if($data === []) return [false, null, null];
	$phpstan = null;
	$pmmp = null;
	for($i = 0; $i < sizeof($data["installed"]); $i++){
		$d = $data["installed"][$i];
		if($d["name"] === "phpstan/phpstan"){
			$phpstan = $d["version"];
		}
		if($d["name"] === "pocketmine/pocketmine-mp"){
			$pmmp = $d["version"];
		}
	}

	return [($pmmp !== null and $phpstan !== null), $pmmp, $phpstan];
}

$d = get_composer_status();

if($d[0]){
	echo "[Info] -> Using pmmp v{$d[1]} and phpstan v{$d[2]}\n";
	goto phpstan;
} else {
	if($d[1] === null){
		echo "[Info] -> Installing pmmp as it was not found.\n";
		passthru("composer require pocketmine/pocketmine-mp:{$DEFAULT_PMMP_V} --no-suggest --no-progress -q");
	}
	if($d[2] === null){
		echo "[Info] -> Installing phpstan as it was not found.\n";
		passthru("composer require phpstan/phpstan:{$DEFAULT_PHPSTAN_V} --no-suggest --no-progress -q");
	}
}

$d = get_composer_status();
if($d[0]){
	echo "[Info] -> Using pmmp v{$d[1]} and phpstan v{$d[2]}\n";
} else {
	// This should never happen...
	fwrite(STDERR, "[Error] -> Composer failed to install default requirements.\n");
	exit(5);
}

phpstan:
echo "[Info] -> Starting phpstan...\n";

myShellExec("php vendor/bin/phpstan.phar analyze --error-format=json --no-progress --memory-limit=2G -c {$_ENV["PHPSTAN_CONFIG"]} > /source/phpstan-results.json", $stdout, $stderr, $exitCode);
fwrite(STDOUT, $stdout);
fwrite(STDERR, $stderr); //Pass on back to poggit.
if($exitCode === 1){
	if($stderr !== "") exit(8);
	echo "[Warning] -> Analysis failed/found problems.";
	exit(6);
}
if($exitCode === 255){
	//Phpstan unable to parse, shouldn't happen...
	fwrite(STDERR, "[Error] -> PHPStan (255) - Unable to parse.".PHP_EOL);
	exit(7);
}
if($exitCode !== 0) {
	fwrite(STDERR, "[Error] -> Unhandled exit status: $code.".PHP_EOL);
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