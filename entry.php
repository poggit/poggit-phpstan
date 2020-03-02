#!/usr/bin/env php
<?php

declare(strict_types=1);

echo "Extracting plugin from {$_ENV["PLUGIN_FILE"]}...\n";

$source = "/source/";

try{
	$phar = new Phar("/source/{$_ENV["PLUGIN_FILE"]}");
	$phar->extractTo("/source/");
} catch (\Exception $e){
	echo "[ERROR] -> Failed to extract {$_ENV["PLUGIN_FILE"]}\n\n";
	echo $e->getMessage();
	exit(1);
}

echo "Starting prerequisite checks...\n";

if(is_file($source."phpstan.neon.dist")) $_ENV["PHPSTAN_CONFIG"] = "/source/phpstan.neon.dist";

if(is_file($source."phpstan.neon")) $_ENV["PHPSTAN_CONFIG"] = "/source/phpstan.neon";

if(is_file($source."plugin.yml")) {
    if(!is_dir($source."src")) {
        echo "src not found in '{$source}'. Are the paths set correctly?\n";
        exit(1);
    }

    $manifest = yaml_parse(file_get_contents($source."plugin.yml"));
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

        echo "Attempting to download dependency $dep from Poggit...\n";
        $code = pclose(popen("wget -O /deps/$dep.phar https://poggit.pmmp.io/get/$dep", "r"));
        if($code !== 0) {
            echo "Warning: Failed to downloading dependency $dep\n";
            // still continue executing
        }
    }
}

if(is_file($source."composer.json")) {
    passthru("composer install --no-suggest --no-progress -n -o", $result);
    if($result !== 0) {
        echo "Failed to install composer dependencies.\n";
        exit(1);
    }
}

echo "Starting phpstan...\n";

$proc = proc_open("phpstan analyze --error-format=json --no-progress --memory-limit=2G -c {$_ENV["PHPSTAN_CONFIG"]} > /source/phpstan-results.json", [["file", "/dev/null", "r"], STDOUT, STDERR], $pipes);
if(is_resource($proc)) {
    $code = proc_close($proc);
    exit($code);
}