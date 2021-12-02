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
 * 4 - Unable to extract dependencies.
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

echo "[Info] -> Extracting dependencies...\n";

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

if(is_file("/source/phpstan.neon.dist")) $_ENV["PHPSTAN_CONFIG"] = "/source/phpstan.neon.dist";
if(is_file("/source/phpstan.neon")) $_ENV["PHPSTAN_CONFIG"] = "/source/phpstan.neon";

if(is_file("/source/plugin.yml")) {
    if(!is_dir("/source/src")) {
        fwrite(STDERR, "[Error] -> src directory not found. Did the container setup correctly ?".PHP_EOL);
        exit(3);
    }
} else {
    fwrite(STDERR, "[Error] -> plugin.yml not found. Did the container setup correctly ?".PHP_EOL);
    exit(3);
}

if(is_file("/source/composer.json")) {
    passthru("composer install --no-suggest --no-progress -n -o", $result);
    if($result !== 0) {
        fwrite(STDERR, "[Error] -> Failed to install composer dependencies !\n".PHP_EOL);
        exit(5);
    }
}

echo "[Info] -> Starting phpstan...\n";

$proc = proc_open("phpstan analyze --error-format=json --no-progress --memory-limit=2G -c {$_ENV["PHPSTAN_CONFIG"]} > /source/phpstan-results.json", [0 => ["file", "/dev/null", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
if(is_resource($proc)) {
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fwrite(STDOUT, $stdout);
    $stderr = stream_get_contents($pipes[2]); // Go through another pipe so we can catch the data.
    fclose($pipes[2]);
    fwrite(STDERR, $stderr); //Pass on back to poggit.
    $code = proc_close($proc);
    if($code === 1){
    		if($stderr !== "") exit(8);
        echo "[Warning] -> Analysis failed/found problems.";
        exit(6);
    }
    if($code === 255){
        //Phpstan unable to parse, shouldn't happen...
        fwrite(STDERR, "[Error] -> PHPStan (255) - Unable to parse.".PHP_EOL);
        exit(7);
    }
    if($code !== 0) {
        fwrite(STDERR, "[Error] -> Unhandled exit status: $code.".PHP_EOL);
        exit(9);
    }
    echo "[Info] -> No problems found !";
    exit(0);
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
