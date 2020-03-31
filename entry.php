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
 * 4 - Unable to parse plugin.
 * 5 - Composer failed.
 * 6 - Analysis failed/found problems.
 * 7 - PHPStan failed to parse the plugin.
 * 8 - PHPStan failed, unknown cause (error emitted stderr)
 * ---
 * 9 - Unknown error.
 */

declare(strict_types=1);

echo "[Info] -> Extracting plugin from {$_ENV["PLUGIN_FILE"]}...\n";

$source = "/source/";

try{
    $phar = new Phar("/source/{$_ENV["PLUGIN_FILE"]}");
    $phar->extractTo("/source/");
} catch (Exception $e){
    echo "[Error] -> Failed to extract {$_ENV["PLUGIN_FILE"]}\n\n";
    echo $e->getMessage();
    exit(3);
}

echo "[Info] -> Starting prerequisite checks...\n";

if(is_file($source."phpstan.neon.dist")) $_ENV["PHPSTAN_CONFIG"] = "/source/phpstan.neon.dist";
if(is_file($source."phpstan.neon")) $_ENV["PHPSTAN_CONFIG"] = "/source/phpstan.neon";

if(is_file($source."plugin.yml")) {
    if(!is_dir($source."src")) {
        echo "[Error] -> src not found in '{$source}'. Are the paths set correctly?";
        exit(4);
    }

    $manifest = yaml_parse(file_get_contents($source."plugin.yml"));
    if(!$manifest){
        echo "[Error] -> Failed to parse plugin.yml";
        exit(4);
    }
    $deps = [];
    foreach(["depend", "softdepend", "loadbefore"] as $attr) {
        if(isset($manifest[$attr])) {
            array_push($deps, ...$manifest[$attr]);
        }
    }

    foreach($deps as $dep) {
        if(empty($dep)) {
            continue;
        }

        echo "[Info] -> Attempting to download dependency $dep from Poggit...\n";
        $code = pclose(popen("wget -q -O /deps/$dep.phar https://poggit.pmmp.io/get/$dep", "r"));
        if($code !== 0) {
            echo "[Warning] -> Failed to downloading dependency $dep\n";
            // still continue executing
        }
    }
}

if(is_file($source."composer.json")) {
    passthru("composer install --no-suggest --no-progress -n -o -q", $result);
    if($result !== 0) {
        echo "[Error] -> Failed to install composer dependencies.\n";
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
        echo "[Error] -> PHPStan (255) - Unable to parse.";
        exit(7);
    }
    if($code !== 0) {
        echo "[Error] -> Unhandled exit status: $code";
        exit(9);
    }
    echo "[Info] -> No problems found !";
    exit(0);
}