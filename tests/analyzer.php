<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use UbModePhp\Analyzer\Analyzer;

$root = realpath(dirname(__DIR__) . '/fixtures');
if ($root === false) {
    throw new RuntimeException('fixtures missing');
}
$analyzer = new Analyzer();
$result = $analyzer->analyze($root, ['plain-php/Example.php', 'laravel/OrderService.php']);
if ($result['diagnostics'] !== []) {
    throw new RuntimeException('valid fixtures produced diagnostics');
}
$byName = [];
foreach ($result['units'] as $unit) {
    $byName[$unit['name']][] = $unit;
}
if (($byName['run'][0]['complexity'] ?? null) !== 3) {
    throw new RuntimeException('if and boolean branch complexity must be 3');
}
if (($byName['helper'][0]['complexity'] ?? null) !== 2) {
    throw new RuntimeException('ternary complexity must be 2');
}
if (($byName['choose'][0]['complexity'] ?? null) !== 3) {
    throw new RuntimeException('two non-default match arms must add two decisions');
}
if (!isset($byName['shared'], $byName['total']) || count($result['units']) < 6) {
    throw new RuntimeException('expected trait, method, function, closure, and arrow units');
}
$ids = array_column($result['units'], 'id');
if (count($ids) !== count(array_unique($ids))) {
    throw new RuntimeException('same-line anonymous class methods must have unique IDs');
}
if ($analyzer->analyze($root, ['plain-php/Example.php', 'laravel/OrderService.php']) !== $result) {
    throw new RuntimeException('repeated analysis must have stable IDs and order');
}
$invalid = $analyzer->analyze($root, ['plain-php/invalid/Invalid.php']);
if (count($invalid['diagnostics']) !== 1 || $invalid['units'] !== []) {
    throw new RuntimeException('invalid PHP must produce a diagnostic, not units');
}
echo "PHP analyzer tests passed\n";
