<?php

use Symfony\Component\Process\Process;

test('instance self hosted mode defaults to disabled when unset', function () {
    $process = new Process([
        PHP_BINARY,
        '-r',
        'require "vendor/autoload.php"; putenv("SELF_HOSTED"); unset($_ENV["SELF_HOSTED"], $_SERVER["SELF_HOSTED"]); $config = require "config/instance.php"; echo $config["self_hosted"] ? "enabled" : "disabled";',
    ], base_path(), ['SELF_HOSTED' => false]);

    $process->mustRun();

    expect($process->getOutput())->toBe('disabled');
});
