<?php
declare(strict_types=1);

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function command(array $arguments, string $directory): array
{
    $parts = array_map('escapeshellarg', $arguments);
    $process = proc_open(implode(' ', $parts), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    ensure(is_resource($process), 'cannot start CLI');
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output, $error];
}

function removeTree(string $directory): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

$repository = dirname(__DIR__);
$project = sys_get_temp_dir() . '/ub-cdmu-test-' . bin2hex(random_bytes(6));
mkdir($project . '/src', 0777, true);
copy($repository . '/fixtures/plain-php/Example.php', $project . '/src/Example.php');

try {
    [$status, $output] = command(['php', $repository . '/bin/ub-php', 'doctor', '--format', 'json'], $project);
    ensure($status === 0, 'doctor must pass in a Composer installation');
    ensure(isset(json_decode($output, true, 512, JSON_THROW_ON_ERROR)['checks']['php_version_id']), 'doctor must report PHP');

    [$status, $output] = command(['php', $repository . '/bin/ub-php', 'check', '--format', 'json'], $project);
    ensure($status === 0, 'check must pass without coverage');
    $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    ensure($report['schema_version'] === '1.0' && $report['tool'] === 'check', 'report contract changed');
    ensure(count($report['entries']) >= 3, 'analyzer returned too few units');
    ensure($report['entries'][0]['coverage'] === null && $report['entries'][0]['crap'] === null, 'missing coverage must be unknown');
    ensure(file_get_contents($project . '/.metrics/check.json') === $output, 'printed and saved JSON must match');
    ensure(str_contains(file_get_contents($project . '/.metrics/crap.edn'), ':coverage nil :crap nil'), 'EDN unknown coverage changed');
    [$status, $second] = command(['php', $repository . '/bin/ub-php', 'check', '--format', 'json'], $project);
    ensure($status === 0 && $second === $output, 'report must be deterministic');

    file_put_contents($project . '/coverage.xml', '<coverage><project><file name="src/Example.php"><line num="19" type="stmt" count="0"/><line num="20" type="stmt" count="0"/></file></project></coverage>');
    [$status, $output] = command(['php', $repository . '/bin/ub-php', 'crap', '--format', 'json'], $project);
    $crap = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    $run = array_values(array_filter($crap['entries'], static fn (array $entry): bool => $entry['name'] === 'run'))[0];
    ensure($status === 2 && $run['crap'] == 12 && $run['exceeded_threshold'], 'known zero coverage must fail CRAP gate');
    ensure(str_contains(file_get_contents($project . '/.metrics/crap.edn'), ':crap 12.0'), 'whole CRAP float must keep EDN shape');
    [$status] = command(['php', $repository . '/bin/ub-php', 'crap', '--threshold', '13'], $project);
    ensure($status === 0, 'threshold override must apply to crap');

    copy($repository . '/fixtures/laravel/OrderService.php', $project . '/src/Laravel.php');
    copy($repository . '/fixtures/symfony/OrderService.php', $project . '/src/Symfony.php');
    [$status, $output] = command(['php', $repository . '/bin/ub-php', 'dry', '--format', 'json'], $project);
    $dry = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    ensure($status === 0 && count($dry['candidates']) === 1 && str_contains($output, '"score":1.0'), 'Dryer candidate or float JSON changed');
    ensure(str_contains(file_get_contents($project . '/.metrics/dry.edn'), ':score 1.0 :language "php"'), 'Dryer EDN float shape changed');

    mkdir($project . '/src/vendor');
    file_put_contents($project . '/src/vendor/Hidden.php', '<?php function hidden() {}');
    symlink($repository . '/fixtures/plain-php/Example.php', $project . '/src/External.php');
    [$status, $output] = command(['git', 'init', '-q'], $project);
    ensure($status === 0, 'cannot initialize Git fixture');
    [$status, $output] = command(['php', $repository . '/bin/ub-php', 'dry', '--changed', '--format', 'json'], $project);
    ensure($status === 0 && count(json_decode($output, true, 512, JSON_THROW_ON_ERROR)['candidates']) === 1, 'changed-file selection or exclusions changed');

    [$status] = command(['php', $repository . '/bin/ub-php', 'check', '--threshold', '2'], $project);
    ensure($status === 4, 'invalid CLI usage must return 4');
    file_put_contents($project . '/.ub-mode.yaml', 'project: [invalid');
    [$status, , $error] = command(['php', $repository . '/bin/ub-php', 'check'], $project);
    ensure($status === 3 && str_contains($error, 'YAML'), 'malformed YAML must return analysis error');
    file_put_contents($project . '/.ub-mode.yaml', "reports:\n  formats: [[json]]\n");
    [$status, , $error] = command(['php', $repository . '/bin/ub-php', 'check'], $project);
    ensure($status === 3 && str_contains($error, 'reports.formats'), 'nested report format must be rejected cleanly');
    unlink($project . '/.ub-mode.yaml');
    [$status] = command(['php', $repository . '/bin/ub-php', '--config', $project . '/src', 'check'], $project);
    ensure($status === 3, 'configuration directory must not be silently ignored');
    file_put_contents($project . '/coverage.xml', '<coverage>');
    [$status, , $error] = command(['php', $repository . '/bin/ub-php', 'crap'], $project);
    ensure($status === 3 && str_contains($error, 'Clover'), 'malformed Clover must be an analysis error');
    file_put_contents($project . '/src/Example.php', '<?php function bad( {');
    $saved = file_get_contents($project . '/.metrics/check.json');
    [$status, , $error] = command(['php', $repository . '/bin/ub-php', 'check'], $project);
    ensure($status === 3 && str_contains($error, 'Example.php') && file_get_contents($project . '/.metrics/check.json') === $saved, 'parse error must preserve old report');

    mkdir($project . '/empty/src', 0777, true);
    [$status] = command(['git', 'init', '-q'], $project . '/empty');
    ensure($status === 0, 'cannot initialize empty Git fixture');
    [$status, $output] = command(['php', $repository . '/bin/ub-php', 'check', '--changed', '--format', 'json'], $project . '/empty');
    ensure($status === 0 && json_decode($output, true, 512, JSON_THROW_ON_ERROR)['entries'] === [], 'empty changed scan must pass');
    [$status] = command(['php', $repository . '/bin/ub-php', 'check'], $project . '/empty');
    ensure($status === 3, 'empty full scan must fail');
    echo "PHP CLI tests passed\n";
} finally {
    removeTree($project);
}
