<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$repository = dirname(__DIR__);
$project = sys_get_temp_dir() . '/ub-cdmu-test-' . bin2hex(random_bytes(6));
mkdir($project . '/src', 0777, true);
copy($repository . '/fixtures/plain-php/Example.php', $project . '/src/Example.php');

try {
    [$status, $output] = command(['php', $repository . '/bin/ub-php', 'doctor', '--format', 'json'], $project);
    ensure($status === 0, 'doctor must pass in a Composer installation');
    $doctor = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    ensure(isset($doctor['checks']['php_version_id']), 'doctor must report PHP');

    [$status, $output] = command(['php', $repository . '/bin/ub-php', 'check', '--format', 'json'], $project);
    ensure($status === 0, 'check must pass without coverage');
    $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    ensure($report['schema_version'] === '1.0' && $report['tool'] === 'check', 'report contract changed');
    ensure(count($report['entries']) >= 3, 'analyzer returned too few units');
    ensure(
        $report['entries'][0]['coverage'] === null && $report['entries'][0]['crap'] === null,
        'missing coverage must be unknown',
    );
    ensure(file_get_contents($project . '/.metrics/check.json') === $output, 'printed and saved JSON must match');
    ensure(
        str_contains(file_get_contents($project . '/.metrics/crap.edn'), ':coverage nil :crap nil'),
        'EDN unknown coverage changed',
    );
    [$status, $second] = command(['php', $repository . '/bin/ub-php', 'check', '--format', 'json'], $project);
    ensure($status === 0 && $second === $output, 'report must be deterministic');

    $clover = '<coverage><project><file name="src/Example.php">'
        . '<line num="19" type="stmt" count="0"/>'
        . '<line num="20" type="stmt" count="0"/>'
        . '</file></project></coverage>';
    file_put_contents($project . '/coverage.xml', $clover);
    [$status, $output] = command(['php', $repository . '/bin/ub-php', 'crap', '--format', 'json'], $project);
    $crap = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    $run = array_values(array_filter($crap['entries'], static fn (array $entry): bool => $entry['name'] === 'run'))[0];
    ensure(
        $status === 2 && $run['crap'] == 12 && $run['exceeded_threshold'],
        'known zero coverage must fail CRAP gate',
    );
    ensure(
        str_contains(file_get_contents($project . '/.metrics/crap.edn'), ':crap 12.0'),
        'whole CRAP float must keep EDN shape',
    );
    [$status] = command(['php', $repository . '/bin/ub-php', 'crap', '--threshold', '13'], $project);
    ensure($status === 0, 'threshold override must apply to crap');

    copy($repository . '/fixtures/laravel/OrderService.php', $project . '/src/Laravel.php');
    copy($repository . '/fixtures/symfony/OrderService.php', $project . '/src/Symfony.php');
    [$status, $output] = command(['php', $repository . '/bin/ub-php', 'dry', '--format', 'json'], $project);
    $dry = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    ensure(
        $status === 0 && count($dry['candidates']) === 1 && str_contains($output, '"score":1.0'),
        'Dryer candidate or float JSON changed',
    );
    ensure(
        str_contains(file_get_contents($project . '/.metrics/dry.edn'), ':score 1.0 :language "php"'),
        'Dryer EDN float shape changed',
    );

    // Text presentation must not change canonical reports or exit codes.
    $canonical = [];
    foreach (['dry.json', 'crap.edn', 'dry.edn'] as $name) {
        $canonical[$name] = file_get_contents($project . '/.metrics/' . $name);
    }
    [$status, $text, $progress] = command(['php', $repository . '/bin/ub-php', 'dry'], $project);
    ensure($status === 0 && str_contains($text, 'Result: WARN'), 'DRY is a warning, not a failure');
    ensure(!str_contains($text, '\\n') && str_contains($text, 'Reports:'), 'text needs real newlines and paths');
    ensure(str_contains($progress, '1/4 Parsing PHP') && str_contains($progress, '3/4 Comparing duplicates')
        && str_contains($progress, '4/4 Saving reports') && str_contains($progress, 'Completed'), 'all stages need progress');
    ensure(!str_contains($progress, "\r") && !str_contains($progress, "\033"), 'pipe progress must be plain lines');
    [$status, $verbose, $error] = command(['php', $repository . '/bin/ub-php', 'dry', '--verbose', '--progress', 'never'], $project);
    ensure($status === 0 && $error === '' && str_contains($verbose, 'Result: WARN'), 'never must silence progress');
    foreach ($canonical as $name => $bytes) {
        ensure(file_get_contents($project . '/.metrics/' . $name) === $bytes, 'presentation changed ' . $name);
    }
    [$status, $json, $error] = command(['php', $repository . '/bin/ub-php', 'dry', '--format', 'json'], $project);
    ensure($error === '' && $json === $canonical['dry.json'], 'default JSON must stay quiet and identical');
    [$status, $json, $error] = command(['php', $repository . '/bin/ub-php', 'dry', '--format', 'json', '--progress', 'always'], $project);
    ensure($json === $canonical['dry.json'] && str_contains($error, 'Completed'), 'forced progress must not pollute JSON');
    [$status] = command(['php', $repository . '/bin/ub-php', 'check', '--progress', 'sometimes'], $project);
    ensure($status === 4, 'invalid progress mode must return usage failure');
    [$status, $text] = command(['php', $repository . '/bin/ub-php', 'check', '--progress', 'never'], $project);
    ensure($status === 2 && str_contains($text, 'Result: FAIL') && str_contains($text, 'Generate Clover'), 'known CRAP failure must dominate warnings');
    file_put_contents($project . '/.ub-mode.yaml', "quality:\n  fail_on: []\n");
    [$status, $text] = command(['php', $repository . '/bin/ub-php', 'check', '--progress', 'never'], $project);
    ensure($status === 0 && str_contains($text, 'Result: WARN'), 'disabled failure gate must warn');
    file_put_contents($project . '/.ub-mode.yaml', "crapper:\n  enabled: false\ndryer:\n  enabled: false\nreports:\n  formats: []\n");
    [$status, $text, $error] = command(['php', $repository . '/bin/ub-php', 'check'], $project);
    ensure($status === 0 && str_contains($text, 'Result: WARN') && str_contains($text, 'No checks enabled')
        && str_contains($text, 'Reports: none') && str_contains($error, 'skipped'), 'disabled checks must not imply a pass');
    unlink($project . '/.ub-mode.yaml');

    // A known, fully covered simple function passes; many duplicates stay compact.
    mkdir($project . '/presentation/src', 0777, true);
    $presentation = $project . '/presentation';
    file_put_contents($presentation . '/src/Simple.php', "<?php\nfunction simple() {\n    return 1;\n}\n");
    file_put_contents($presentation . '/coverage.xml', '<coverage><project><file name="src/Simple.php"><line num="3" type="stmt" count="1"/></file></project></coverage>');
    [$status, $text] = command(['php', $repository . '/bin/ub-php', 'check'], $presentation);
    ensure($status === 0 && str_contains($text, 'Result: PASS'), 'covered simple code must pass');
    file_put_contents($presentation . '/.ub-mode.yaml', "reports:\n  formats: [edn]\n");
    [$status, $text] = command(['php', $repository . '/bin/ub-php', 'crap', '--progress', 'never'], $presentation);
    ensure($status === 0 && str_contains($text, '/crap.edn') && !str_contains($text, '/check.json'), 'only reports saved this run may be listed');
    unlink($presentation . '/.ub-mode.yaml');
    unlink($presentation . '/coverage.xml');
    [$status, $text] = command(['php', $repository . '/bin/ub-php', 'crap'], $presentation);
    ensure($status === 0 && str_contains($text, 'Result: WARN') && str_contains($text, 'unknown'), 'missing coverage is unknown');
    for ($index = 0; $index < 25; $index++) {
        copy($repository . '/fixtures/laravel/OrderService.php', $presentation . '/src/Copy' . $index . '.php');
    }
    [$status, $compact] = command(['php', $repository . '/bin/ub-php', 'check', '--progress', 'never'], $presentation);
    [$status, $full] = command(['php', $repository . '/bin/ub-php', 'check', '--verbose', '--progress', 'never'], $presentation);
    ensure(substr_count($compact, "\n") <= 80 && strlen($compact) < 20480, 'default report must stay compact');
    ensure(str_contains($compact, 'hidden') && str_contains($compact, '--verbose') && strlen($full) > strlen($compact), 'verbose must reveal hidden findings');
    mkdir($presentation . '/blocked');
    file_put_contents($presentation . '/blocked/check.json', 'old report');
    file_put_contents($presentation . '/.ub-mode.yaml', "reports:\n  directory: blocked/check.json\n");
    [$status, $text, $error] = command(['php', $repository . '/bin/ub-php', 'check'], $presentation);
    ensure($status === 3 && $text === '' && str_contains($error, 'Result: FAIL') && !str_contains($error, 'Completed'), 'write failure must not claim success');
    ensure(file_get_contents($presentation . '/blocked/check.json') === 'old report', 'write failure must preserve old file');

    mkdir($presentation . '/partial/crap.edn', 0777, true);
    file_put_contents($presentation . '/.ub-mode.yaml', "reports:\n  directory: partial\n");
    [$status, $text, $error] = command(['php', $repository . '/bin/ub-php', 'check'], $presentation);
    ensure($status === 3 && $text === '' && !str_contains($error, 'Completed')
        && str_contains($error, 'Result: FAIL') && is_file($presentation . '/partial/check.json'), 'partial save must not claim completion');
    [$status, $text, $error] = command(['php', $repository . '/bin/ub-php', 'check', '--format', 'json', '--progress', 'always'], $presentation);
    ensure($status === 3 && $text === '' && !str_contains($error, 'Result:') && !str_contains($error, 'Completed'), 'JSON errors must preserve stdout contract');

    mkdir($project . '/src/vendor');
    file_put_contents($project . '/src/vendor/Hidden.php', '<?php function hidden() {}');
    symlink($repository . '/fixtures/plain-php/Example.php', $project . '/src/External.php');
    [$status, $text, $error] = command(['php', $repository . '/bin/ub-php', 'check', '--changed', '--progress', 'never'], $project);
    ensure(
        $status === 3 && $text === '' && str_contains($error, 'requires a Git repository')
            && !str_contains($error, 'usage:') && substr_count($error, "\n") <= 3,
        '--changed outside Git must fail with one short message',
    );
    [$status, $output] = command(['git', 'init', '-q'], $project);
    ensure($status === 0, 'cannot initialize Git fixture');
    [$status, $output] = command(
        ['php', $repository . '/bin/ub-php', 'dry', '--changed', '--format', 'json'],
        $project,
    );
    $changed = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    ensure(
        $status === 0 && count($changed['candidates']) === 1,
        'changed-file selection or exclusions changed',
    );

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
    ensure(
        $status === 3 && str_contains($error, 'Example.php') && str_contains($error, 'Result: FAIL') && !str_contains($error, 'Completed')
            && file_get_contents($project . '/.metrics/check.json') === $saved,
        'parse error must preserve old report',
    );

    mkdir($project . '/empty/src', 0777, true);
    [$status] = command(['git', 'init', '-q'], $project . '/empty');
    ensure($status === 0, 'cannot initialize empty Git fixture');
    [$status, $output] = command(
        ['php', $repository . '/bin/ub-php', 'check', '--changed', '--format', 'json'],
        $project . '/empty',
    );
    $empty = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    ensure($status === 0 && $empty['entries'] === [], 'empty changed scan must pass');
    [$status, $text] = command(['php', $repository . '/bin/ub-php', 'check', '--changed'], $project . '/empty');
    ensure($status === 0 && str_contains($text, 'Result: WARN') && str_contains($text, 'No changed PHP files'), 'empty changed scan must explain scope');
    [$status] = command(['php', $repository . '/bin/ub-php', 'check'], $project . '/empty');
    ensure($status === 3, 'empty full scan must fail');
    echo "PHP CLI tests passed\n";
} finally {
    removeTree($project);
}
