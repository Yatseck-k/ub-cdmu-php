#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use UbModePhp\Analyzer\Analyzer;

try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input) || ($input['schema_version'] ?? null) !== '1.0') {
        throw new InvalidArgumentException('unsupported schema_version');
    }
    $root = $input['root'] ?? null;
    $files = $input['files'] ?? null;
    if (!is_string($root) || !is_array($files) || !array_is_list($files)) {
        throw new InvalidArgumentException('root must be a string and files must be a list');
    }
    $result = (new Analyzer())->analyze($root, $files);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'analyzer: ' . $error->getMessage() . "\n");
    exit(1);
}
