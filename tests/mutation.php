<?php

declare(strict_types=1);

use UbModePhp\Analyzer\Analyzer;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support.php';

use Symfony\Component\Process\Process;
use UbModePhp\Config;
use UbModePhp\Mutation\InfectionReport;
use UbModePhp\Mutation\InputFingerprint;
use UbModePhp\Mutation\ProcessSupervisor;
use UbModePhp\ReportRetention;
use UbModePhp\Reports;

$repository = dirname(__DIR__);
$root = sys_get_temp_dir() . '/cdmu-mutation-' . bin2hex(random_bytes(6));
mkdir($root . '/src', 0777, true);
mkdir($root . '/bin');
file_put_contents($root . '/src/Example.php', "<?php\nclass Example { public function value(int \$a): int { return \$a + 1; } }\n");
$cli = ['php', $repository . '/bin/ub-php'];
function rawMutation(array $groups): array
{
    $data = ['stats' => ['totalMutantsCount' => 0, 'skippedCount' => 0, 'msi' => 50.0, 'coveredCodeMsi' => 50.0, 'mutationCodeCoverage' => 100.0]];
    foreach (['killed' => 'killedCount', 'escaped' => 'escapedCount', 'uncovered' => 'notCoveredCount', 'timeouted' => 'timeOutCount', 'errored' => 'errorCount', 'syntaxErrors' => 'syntaxErrorCount', 'ignored' => 'ignoredCount'] as $group => $counter) {
        $data[$group] = $groups[$group] ?? [];
        $data['stats'][$counter] = count($data[$group]);
        $data['stats']['totalMutantsCount'] += count($data[$group]);
    }
    return $data;
}
function infectionSettings(string $root, string $mode, array $raw, string $version = '0.29.14'): void
{
    file_put_contents($root . '/infection.json5', json_encode(['source' => ['directories' => ['src']], 'fakeMode' => $mode, 'fakeVersion' => $version, 'fakeRaw' => $raw], JSON_THROW_ON_ERROR));
}
$mutant = ['mutator' => ['originalFilePath' => $root . '/src/Example.php', 'originalStartLine' => 2, 'mutatorName' => 'Plus'], 'diff' => '- +\n+ -'];
$raw = rawMutation(['escaped' => [$mutant]]);
file_put_contents($root . '/bin/infection', <<<'FAKE'
<?php
$original = json_decode(file_get_contents('infection.json5'), true);
if (in_array('--version', $argv, true)) { echo 'Infection ' . $original['fakeVersion']; exit(0); }
$path = null;
foreach ($argv as $argument) { if (str_starts_with($argument, '--configuration=')) { $path = substr($argument, 16); } }
$config = json_decode(file_get_contents($path), true);
$mode = $config['fakeMode'];
if ($mode === 'baseline') { fwrite(STDERR, 'Baseline tests failed'); exit(1); }
if ($mode === 'no-report') { exit(0); }
if ($mode === 'orphan') {
    $pid = pcntl_fork();
    if ($pid === 0) { sleep(30); exit(0); }
    file_put_contents('child.pid', (string) $pid);
    exit(1);
}
if ($mode === 'concurrent') { usleep(300000); }
if ($mode === 'large') { fwrite(STDOUT, str_repeat('O', 1048576) . '!STDOUT-END!'); fwrite(STDERR, str_repeat('E', 1048576) . '!STDERR-END!'); }
if (in_array($mode, ['config-change', 'ui-change'], true)) {
    file_put_contents($config['fakeConfigPath'] ?? '.ub-mode.yaml', "mutator:\n  executable: bin/infection\n"
        . ($mode === 'config-change' ? "  input_paths: [src]\n" : "uml:\n  view_limit: 77\n"));
}
if (in_array($mode, ['cleanup-tail', 'cleanup-fail', 'supervisor-error', 'kill-supervisor'], true)) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void {
            fwrite(STDOUT, 'CLEANUP_STDOUT_END');
            fwrite(STDERR, 'CLEANUP_STDERR_END');
            exit(0);
        });
        file_put_contents('child.pid', (string) getmypid());
        sleep(10);
        exit(0);
    }
    while (!is_file('child.pid')) { usleep(1000); }
    if ($mode === 'supervisor-error') { mkdir(dirname($config['logs']['json']) . '/engine-exit.json.tmp'); }
    if ($mode === 'kill-supervisor') { posix_kill(posix_getppid(), SIGKILL); }
}
if ($mode === 'change') { file_put_contents('src/Example.php', "\n// changed during run\n", FILE_APPEND); }
if ($mode === 'timeout' || $mode === 'interrupt') {
    $child = proc_open([PHP_BINARY, '-r', 'sleep(30);'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    file_put_contents('child.pid', (string) proc_get_status($child)['pid']);
    sleep(30);
}
file_put_contents($config['logs']['json'], json_encode($config['fakeRaw']));
if ($mode === 'cleanup-fail') { exit(1); }
FAKE);
file_put_contents($root . '/.ub-mode.yaml', "mutator:\n  executable: bin/infection\n  process_timeout: 10\n");
try {
    $all = rawMutation(array_fill_keys(['killed', 'escaped', 'uncovered', 'timeouted', 'errored', 'syntaxErrors', 'ignored'], [$mutant]));
    $normalized = InfectionReport::normalize(Reports::json($all), $root, [], ['src/Example.php']);
    ensure(count($normalized['mutants']) === 7 && $normalized['summary']['timeout'] === 1
        && $normalized['summary']['killed'] === 1 && $normalized['summary']['survived'] === 1, 'statuses must remain distinct');
    foreach (['counter', 'outside', 'line', 'metric'] as $failure) {
        $bad = $raw;
        if ($failure === 'counter') {
            $bad['stats']['totalMutantsCount']++;
        }
        if ($failure === 'outside') {
            $bad['escaped'][0]['mutator']['originalFilePath'] = '/etc/passwd';
        }
        if ($failure === 'line') {
            $bad['escaped'][0]['mutator']['originalStartLine'] = 999;
        }
        if ($failure === 'metric') {
            $bad['stats']['msi'] = 'not a metric';
        }
        try {
            InfectionReport::normalize(Reports::json($bad), $root, [], ['src/Example.php']);
            throw new RuntimeException('invalid report accepted: ' . $failure);
        } catch (RuntimeException $error) {
            ensure(!str_starts_with($error->getMessage(), 'invalid report accepted'), $error->getMessage());
        }
    }
    infectionSettings($root, 'ok', $raw);
    [$code, $json, $stderr] = command([...$cli, 'mutate', '--format', 'json'], $root);
    $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    ensure($code === 0 && $stderr === '' && $report['status'] === 'WARN', 'survivors warn without gate');
    [$code, $json] = command([...$cli, 'mutate', '--min-msi', '80', '--format', 'json'], $root);
    ensure($code === 2 && json_decode($json, true)['status'] === 'FAIL', 'known MSI below threshold must fail');
    [$code, $json] = command([...$cli, 'mutate', '--min-msi', '50', '--format', 'json'], $root);
    ensure($code === 0 && json_decode($json, true)['summary']['gate_evaluated'], 'MSI equal to the threshold must pass its gate');
    $lastMeasured = file_get_contents($root . '/.metrics/mutate.json');
    $empty = rawMutation([]);
    infectionSettings($root, 'ok', $empty);
    [$code, $json] = command([...$cli, 'mutate', '--min-msi', '80', '--format', 'json'], $root);
    ensure($code === 3 && json_decode($json, true)['exit_code'] === 3, 'required MSI gate cannot pass without a score');
    ensure(file_get_contents($root . '/.metrics/mutate.json') === $lastMeasured, 'unknown required score must preserve the previous measurement');
    file_put_contents($root . '/.ub-mode.yaml', "mutator:\n  executable: bin/infection\n  min_msi: 80\n");
    [$code, $json] = command([...$cli, 'mutate', '--format', 'json'], $root);
    ensure($code === 3 && json_decode($json, true)['status'] === 'FAIL' && file_get_contents($root . '/.metrics/mutate.json') === $lastMeasured, 'configured MSI gate must also reject an unknown score');
    file_put_contents($root . '/.ub-mode.yaml', "mutator:\n  executable: bin/infection\n  process_timeout: 10\n");
    $failures = glob($root . '/.metrics/.mutation-runs/*/failure.json');
    ensure($json === file_get_contents(end($failures)), 'failure JSON stdout must equal the retained artifact');
    $failure = json_decode($json, true);
    ensure($failure['status'] === 'FAIL' && !$failure['summary']['gate_evaluated'] && $failure['summary']['msi'] === null, 'failure artifact must keep the unknown score');
    [$code, $json] = command([...$cli, 'mutate', '--format', 'json'], $root);
    ensure($code === 0 && json_decode($json, true)['status'] === 'WARN', 'unknown MSI without a gate remains a warning');
    $skipped = rawMutation([]);
    $skipped['stats']['totalMutantsCount'] = $skipped['stats']['skippedCount'] = 2;
    $none = InfectionReport::normalize(Reports::json($skipped), $root, [], ['src/Example.php']);
    ensure($none['summary']['msi'] === null, 'skipped mutants cannot imply a known MSI');
    file_put_contents($root . '/coverage.xml', '<coverage><project><file name="src/Example.php"><line num="2" type="stmt" count="1"/></file></project></coverage>');
    $ignored = rawMutation(['ignored' => [$mutant]]);
    infectionSettings($root, 'ok', $ignored);
    [$code, $json] = command([...$cli, 'mutate', '--min-msi', '80', '--format', 'json'], $root);
    ensure($code === 3 && json_decode($json, true)['summary']['msi'] === null, 'ignored-only run must fail a required score');
    [$code, $json] = command([...$cli, 'mutate', '--format', 'json'], $root);
    ensure($code === 0 && json_decode($json, true)['summary']['msi'] === null, 'ungated ignored-only score stays unknown');
    [$code, $uml] = command([...$cli, 'uml', '--format', 'json'], $root);
    $view = json_decode($uml, true, 512, JSON_THROW_ON_ERROR);
    ensure($code === 0 && $view['mutation_overlay']['status'] === 'available'
        && $view['nodes'][0]['metrics']['mutation_status'] === 'unknown'
        && $view['nodes'][0]['metrics']['crap_status'] === 'PASS' && $view['status'] === 'WARN', 'ignored mutants cannot paint a killed status or an overall pass');
    infectionSettings($root, 'large', $raw);
    [$code, $json, $error] = command([...$cli, 'mutate', '--format', 'json'], $root);
    $largeLog = file_get_contents($root . '/.metrics/mutate.log');
    $payload = str_replace(['!STDOUT-END!', '!STDERR-END!'], '', $largeLog);
    ensure(
        $code === 0 && str_contains($largeLog, '!STDOUT-END!') && str_contains($largeLog, '!STDERR-END!')
        && strlen($payload) === 2097152 && substr_count($payload, 'O') === 1048576 && substr_count($payload, 'E') === 1048576,
        'large output must drain both streams completely without polluting JSON: ' . $error
    );
    infectionSettings($root, 'concurrent', $raw);
    $beforeRuns = glob($root . '/.metrics/.mutation-runs/*');
    $first = new Process([...$cli, 'mutate', '--format', 'json'], $root);
    $second = new Process([...$cli, 'mutate', '--format', 'json'], $root);
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();
    clearstatcache(true);
    ensure($first->isSuccessful() && $second->isSuccessful(), 'concurrent runs must own independent temporary files');
    ensure(count(glob($root . '/.metrics/.mutation-runs/*')) === count($beforeRuns), 'successful runs must remove their redundant working directories');
    $saved = file_get_contents($root . '/.metrics/mutate.json');
    foreach (['baseline', 'no-report', 'timeout', 'orphan', 'change'] as $mode) {
        file_put_contents($root . '/.ub-mode.yaml', "mutator:\n  executable: bin/infection\n  process_timeout: " . ($mode === 'timeout' ? '1' : '10') . "\n");
        infectionSettings($root, $mode, $raw);
        $source = file_get_contents($root . '/src/Example.php');
        [$code, $output, $stderr] = command([...$cli, 'mutate'], $root);
        ensure($code === 3 && $output === '' && !str_contains($stderr, 'Completed')
            && str_contains($stderr, 'Result: FAIL'), $mode . ' must not publish success');
        ensure(file_get_contents($root . '/.metrics/mutate.json') === $saved, $mode . ' must preserve old snapshot');
        ensure(glob($root . '/.ub-infection-*') === [], $mode . ' must remove temporary config');
        if (in_array($mode, ['timeout', 'orphan'], true) && function_exists('posix_kill')) {
            $child = (int) file_get_contents($root . '/child.pid');
            $stat = @file_get_contents('/proc/' . $child . '/stat');
            ensure(!posix_kill($child, 0) || ($stat !== false && str_contains($stat, ') Z ')), 'failed/timeout engine must not leave children running');
        }
        if ($mode !== 'change') {
            ensure($source === file_get_contents($root . '/src/Example.php'), 'runner modified source');
        }
        file_put_contents($root . '/src/Example.php', $source);
    }
    file_put_contents($root . '/.ub-mode.yaml', "mutator:\n  executable: bin/infection\n  process_timeout: 10\n");
    if (function_exists('pcntl_signal') && function_exists('posix_kill')) {
        foreach ([SIGTERM, SIGINT] as $interruptSignal) {
            infectionSettings($root, 'interrupt', $raw);
            @unlink($root . '/child.pid');
            $process = new Process([...$cli, 'mutate', '--format', 'json'], $root);
            $process->start();
            $deadline = microtime(true) + 5;
            while (!is_file($root . '/child.pid') && $process->isRunning() && microtime(true) < $deadline) {
                usleep(50000);
            }
            ensure(is_file($root . '/child.pid'), 'interrupt fixture must reach engine');
            $process->signal($interruptSignal);
            $process->wait();
            ensure($process->getExitCode() === 3 && $process->getOutput() === '' && glob($root . '/.ub-infection-*') === [], 'interruption must clean up and preserve JSON contract');
            $child = (int) file_get_contents($root . '/child.pid');
            $stat = @file_get_contents('/proc/' . $child . '/stat');
            ensure(!posix_kill($child, 0) || ($stat !== false && str_contains($stat, ') Z ')), 'engine child must be stopped');
        }
    }
    infectionSettings($root, 'ok', $raw);
    [$code] = command(['git', 'init', '-q'], $root);
    [$code] = command(['git', 'add', 'src/Example.php'], $root);
    file_put_contents($root . '/src/Example.php', "\n// unstaged edit\n", FILE_APPEND);
    file_put_contents($root . '/src/Other.php', '<?php function other() { return 1; }');
    [$code, $json] = command([...$cli, 'mutate', '--changed', '--format', 'json'], $root);
    ensure($code === 0 && json_decode($json, true)['scope']['files'] === ['src/Example.php', 'src/Other.php'], 'mutation must share C/D changed selection');
    file_put_contents($root . '/src/comma,file.php', '<?php function comma() {}');
    [$code, $output, $error] = command([...$cli, 'mutate', '--format', 'json'], $root);
    ensure($code === 3 && $output === '' && str_contains($error, 'comma'), 'unrepresentable filter path must not broaden scope');
    unlink($root . '/src/comma,file.php');
    symlink('/etc/passwd', $root . '/outside');
    try {
        Config::projectPath($root, 'outside');
        throw new RuntimeException('outside symlink accepted');
    } catch (InvalidArgumentException $error) {
        ensure(str_contains($error->getMessage(), 'outside'), 'symlink traversal must fail');
    }
    infectionSettings($root, 'ok', $raw);
    $settings = json_decode(file_get_contents($root . '/infection.json5'), true);
    $settings['bootstrap'] = '/etc/passwd';
    file_put_contents($root . '/infection.json5', json_encode($settings));
    [$code, $output] = command([...$cli, 'mutate', '--format', 'json'], $root);
    ensure($code === 3 && $output === '', 'engine bootstrap path must stay inside the project');
    infectionSettings($root, 'ok', $raw, '99.0.0');
    [$code, $json] = command([...$cli, 'doctor', '--tools', '--format', 'json'], $root);
    ensure($code === 0 && !json_decode($json, true)['tools']['mutation']['available'], 'optional unsupported engine must not fail doctor');
    [$code] = command([...$cli, 'mutate'], $root);
    ensure($code === 3, 'unsupported engine must fail explicit mutation');
    [$code] = command([...$cli, 'mutate', '--min-msi', '101'], $root);
    ensure($code === 4, 'invalid MSI is usage error');
    infectionSettings($root, 'ok', $raw);
    $config = Config::load($root);
    $before = InputFingerprint::compute($root, $config);
    mkdir($root . '/tests');
    file_put_contents($root . '/tests/NewTest.php', '<?php // new test');
    ensure($before !== InputFingerprint::compute($root, $config), 'new tests must invalidate mutation snapshot');
    $before = InputFingerprint::compute($root, $config);
    file_put_contents($root . '/tests/cases.csv', 'input,output');
    ensure($before !== InputFingerprint::compute($root, $config), 'non-PHP test data must invalidate snapshot');
    mkdir($root . '/empty/src', 0777, true);
    [$code] = command(['git', 'init', '-q'], $root . '/empty');
    [$code, $json] = command([...$cli, 'mutate', '--changed', '--format', 'json'], $root . '/empty');
    ensure($code === 0 && json_decode($json, true)['engine']['version'] === null, 'empty changes must not require or run Infection');
    [$code, $json] = command([...$cli, '--root', $root . '/empty', 'mutate', '--changed', '--min-msi', '80', '--format', 'json'], $root . '/empty');
    ensure($code === 3 && json_decode($json, true)['engine']['version'] === null, 'empty changed scope cannot satisfy an explicitly requested MSI gate');

    file_put_contents($root . '/.ub-mode.yaml', "mutator:\n  executable: bin/infection\n  input_exclude: [tests/tmp]\nreports:\n  directory: build/cdmu\n");
    infectionSettings($root, 'ok', $raw);
    $config = Config::load($root);
    $cwd = getcwd();
    chdir(dirname($root));
    $before = InputFingerprint::compute(basename($root), $config);
    mkdir($root . '/build/cdmu', 0777, true);
    file_put_contents($root . '/build/cdmu/generated.json', '{}');
    ensure($before === InputFingerprint::compute(basename($root), $config), 'custom report directory must stay outside a relative-root fingerprint');
    chdir($cwd);
    mkdir($root . '/tests/tmp');
    file_put_contents($root . '/tests/tmp/runtime.txt', 'temporary');
    ensure($before === InputFingerprint::compute($root, $config), 'explicit temporary exclusion must be honored');
    file_put_contents($root . '/phpunit.xml', '<phpunit cacheDirectory="cache"><testsuites><testsuite name="tests"><directory>tests</directory></testsuite></testsuites><logging><junit outputFile="tests/junit.xml"/></logging></phpunit>');
    $before = InputFingerprint::compute($root, $config);
    file_put_contents($root . '/tests/junit.xml', '<testsuites/>');
    mkdir($root . '/cache');
    file_put_contents($root . '/cache/results.json', '{}');
    file_put_contents($root . '/unrelated.json', '{}');
    ensure($before === InputFingerprint::compute($root, $config), 'PHPUnit outputs and unrelated root data must not alter inputs');
    file_put_contents($root . '/tests/cases.csv', 'changed data');
    ensure($before !== InputFingerprint::compute($root, $config), 'real test data remains protected');
    symlink($root, $root . '-link');
    ensure(InputFingerprint::compute($root, $config) === InputFingerprint::compute($root . '-link', $config), 'symlink root must be canonical');
    unlink($root . '-link');
    foreach (['src', './src', 'src/', '.'] as $directory) {
        infectionSettings($root, 'ok', $raw);
        $settings = json_decode(file_get_contents($root . '/infection.json5'), true);
        $settings['source']['directories'] = [$directory];
        file_put_contents($root . '/infection.json5', json_encode($settings));
        [$code, $json, $error] = command([...$cli, 'mutate', '--format', 'json'], $root);
        ensure($code === 0, 'valid Infection path rejected: ' . $directory . ' ' . $error);
    }
    foreach ([123, []] as $bootstrap) {
        file_put_contents($root . '/infection.json5', json_encode(['source' => ['directories' => ['src']], 'bootstrap' => $bootstrap]));
        [$code, $json, $error] = command([...$cli, 'mutate', '--format', 'json'], $root);
        ensure($code === 3 && str_contains($error, 'bootstrap') && !str_contains($error, 'TypeError'), 'bootstrap must have field-specific validation');
    }
    $done = new Process(['php', '-r', 'exit(0);']);
    $done->run();
    $signals = [];
    $signalSpy = static function (int $pid, int $signal) use (&$signals): bool {
        $signals[] = [$pid, $signal];
        return true;
    };
    ensure(!ProcessSupervisor::stop($done, $signalSpy) && $signals === [], 'reaped/stale process identity must never be signaled');
    $ordinaryChild = new Process(['php', '-r', 'sleep(10);']);
    $ordinaryChild->start();
    ensure(!ProcessSupervisor::stop($ordinaryChild, $signalSpy) && $signals === [], 'non-owned session must not receive group signals');
    $ordinaryChild->stop(0);

    $history = $root . '/history';
    mkdir($history);
    for ($i = 0; $i < 8; $i++) {
        Reports::bundle($history, '.metrics', 'uml', ['uml.json' => Reports::json(['n' => $i])]);
    }
    $generations = glob($history . '/.metrics/.cdmu/uml/*', GLOB_ONLYDIR);
    $numbers = array_map(static fn (string $directory): int => json_decode(file_get_contents($directory . '/uml.json'), true)['n'], $generations);
    sort($numbers);
    ensure($numbers === [3, 4, 5, 6, 7] && json_decode(file_get_contents($history . '/.metrics/uml.json'), true)['n'] === 7, 'generation retention must preserve current and the newest five');
    Reports::bundle($history, '.metrics', 'uml', ['uml.json' => Reports::json(['n' => 8])], 0);
    ensure(count(glob($history . '/.metrics/.cdmu/uml/*', GLOB_ONLYDIR)) === 6, 'zero retention limit must keep all generations');
    $historyConfig = Config::load($history);
    for ($i = 0; $i < 8; $i++) {
        $run = ReportRetention::startRun($history, $historyConfig);
        ReportRetention::finishRun($history, $historyConfig, $run, false);
    }
    ensure(count(glob($history . '/.metrics/.mutation-runs/*', GLOB_ONLYDIR)) === 5, 'failed run history must be bounded');
    $unlimited = $historyConfig;
    $unlimited['mutator']['keep_failed_runs'] = 0;
    $unlimitedRun = ReportRetention::startRun($history, $unlimited);
    ReportRetention::finishRun($history, $unlimited, $unlimitedRun, false);
    ensure(count(glob($history . '/.metrics/.mutation-runs/*', GLOB_ONLYDIR)) === 6, 'zero failed-run limit disables pruning');
    ReportRetention::failedRuns($history, $historyConfig);
    $active = ReportRetention::startRun($history, $historyConfig);
    ReportRetention::failedRuns($history, $historyConfig);
    ensure(is_dir($active['directory']), 'active locked run must not be pruned');
    ReportRetention::finishRun($history, $historyConfig, $active, true);
    ensure(!file_exists($active['directory']), 'published run data must be removed');
    $abandoned = ReportRetention::startRun($history, $historyConfig);
    touch($abandoned['directory'] . '/run.lock', time() - 120);
    ReportRetention::failedRuns($history, $historyConfig);
    ensure(json_decode(file_get_contents($abandoned['directory'] . '/run.json'), true)['status'] === 'active', 'a live old run is protected by its lock');
    flock($abandoned['lock'], LOCK_UN);
    fclose($abandoned['lock']);
    ReportRetention::failedRuns($history, $historyConfig);
    ensure(!is_dir($abandoned['directory']) || json_decode(file_get_contents($abandoned['directory'] . '/run.json'), true)['status'] === 'failed', 'an abandoned stale lock must rejoin bounded failure retention');
    $foreign = $history . '/.metrics/.cdmu/uml/' . str_repeat('f', 24);
    mkdir($foreign);
    file_put_contents($foreign . '/keep.txt', 'unmanaged');
    $linked = $history . '/.metrics/.cdmu/uml/' . str_repeat('e', 24);
    symlink($root . '/tests', $linked);
    Reports::bundle($history, '.metrics', 'uml', ['uml.json' => Reports::json(['n' => 9])]);
    ensure(is_file($foreign . '/keep.txt') && is_link($linked) && is_file($root . '/tests/cases.csv'), 'retention must not traverse symlinks or erase unmanaged data');
    foreach (glob($history . '/.metrics/.cdmu/uml/*', GLOB_ONLYDIR) as $directory) {
        if (!is_link($directory) && $directory !== $foreign) {
            chmod($directory, 0555);
        }
    }
    file_put_contents($history . '/publish.php', <<<'PUBLISH'
<?php
require $argv[2];
\UbModePhp\Reports::bundle($argv[1], '.metrics', 'uml', ['uml.json' => \UbModePhp\Reports::json(['n' => 10])], 1);
echo 'published';
PUBLISH);
    [$code, $output, $error] = command(['php', $history . '/publish.php', $history, $repository . '/vendor/autoload.php'], $root);
    foreach (glob($history . '/.metrics/.cdmu/uml/*', GLOB_ONLYDIR) as $directory) {
        if (!is_link($directory)) {
            chmod($directory, 0777);
        }
    }
    ensure($code === 0 && $output === 'published' && str_contains($error, 'cleanup warning')
        && json_decode(file_get_contents($history . '/.metrics/uml.json'), true)['n'] === 10, 'prune failure must preserve publication and report a warning');
    file_put_contents($history . '/reader.php', <<<'READER'
<?php
$lock = fopen($argv[1] . '/.metrics/.cdmu/uml.lock', 'r');
flock($lock, LOCK_SH);
file_put_contents($argv[1] . '/reader.ready', 'ready');
usleep(300000);
echo json_decode(file_get_contents($argv[1] . '/.metrics/uml.json'), true)['n'];
flock($lock, LOCK_UN);
fclose($lock);
READER);
    $reader = new Process(['php', $history . '/reader.php', $history]);
    $reader->start();
    $deadline = microtime(true) + 3;
    while (!is_file($history . '/reader.ready') && microtime(true) < $deadline) {
        usleep(10000);
    }
    ensure(is_file($history . '/reader.ready'), 'reader must hold its shared lock');
    Reports::bundle($history, '.metrics', 'uml', ['uml.json' => Reports::json(['n' => 11])], 1);
    $reader->wait();
    ensure($reader->getOutput() === '10' && json_decode(file_get_contents($history . '/.metrics/uml.json'), true)['n'] === 11, 'writer/pruner must respect a snapshot reader');
    symlink('.metrics', $history . '/history-alias');
    Reports::bundle($history, 'history-alias', 'uml', ['uml.json' => Reports::json(['n' => 12])], 1);
    ensure(Reports::readBundle($history, 'history-alias', 'uml', 'uml.json')['n'] === 12, 'retention must protect current generation through an internal report-directory symlink');
    $nested = $root . '/nested';
    mkdir($nested . '/src', 0777, true);
    mkdir($nested . '/bin');
    copy($root . '/bin/infection', $nested . '/bin/infection');
    $nestedConfig = "mutator:\n  executable: bin/infection\n";
    file_put_contents($nested . '/.ub-mode.yaml', $nestedConfig);
    file_put_contents($nested . '/src/Nested.php', <<<'NESTED'
<?php
class Nested {
    public function run($x) {
        $outer = function ($a) {
            $inner = function ($b) {
                return fn ($c) => $c + 1;
            };
            return $inner($a);
        };
        return $outer($x);
    }
}
NESTED);
    $units = (new Analyzer())->analyze($nested, ['src/Nested.php'])['units'];
    $rows = [];
    foreach ($units as $unit) {
        if (in_array($unit['kind'], ['closure', 'arrow'], true)) {
            $rows[] = ['mutator' => ['originalFilePath' => $nested . '/src/Nested.php', 'originalStartLine' => $unit['start_line'], 'mutatorName' => 'Plus'], 'diff' => $unit['name']];
        }
    }
    $nestedRaw = rawMutation(['escaped' => $rows]);
    infectionSettings($nested, 'ok', $nestedRaw);
    $normalized = InfectionReport::normalize(Reports::json($nestedRaw), $nested, $units, ['src/Nested.php']);
    ensure(count(array_filter($normalized['mutants'], static fn (array $m): bool => $m['unit_id'] !== null)) === 3
        && count(array_unique(array_column($normalized['mutants'], 'unit_id'))) === 3, 'deeply nested closures/arrows must bind to distinct innermost units');
    [$code, $json, $error] = command([...$cli, 'mutate', '--format', 'json'], $nested);
    ensure($code === 0, 'nested mutation must run: ' . $error);
    [$code, $json] = command([...$cli, 'uml', '--format', 'json'], $nested);
    $node = json_decode($json, true)['nodes'][0];
    ensure($node['metrics']['survived'] === 3 && $node['metrics']['mutants'] === 3
        && count($node['members'][0]['mutations']) === 3, 'class and containing method must count nested survivors once');
    $baseConfig = Config::load($nested);
    $fingerprints = InputFingerprint::compute($nested, $baseConfig);
    foreach (
        [['uml', 'view_limit', 17], ['reports', 'keep_generations', 1], ['reports', 'formats', ['json']],
        ['mutator', 'keep_failed_runs', 1], ['mutator', 'threads', 2], ['mutator', 'process_timeout', 8],
        ['mutator', 'min_msi', 99], ['crapper', 'threshold', 20], ['dryer', 'threshold', 0.9]] as [$group, $key, $value]
    ) {
        $changed = $baseConfig;
        $changed[$group][$key] = $value;
        ensure($fingerprints === InputFingerprint::compute($nested, $changed), 'operational settings must not invalidate measurements: ' . $group . '.' . $key);
    }
    file_put_contents($nested . '/.ub-mode.yaml', $nestedConfig . "# presentation only\numl:\n  view_limit: 17\n");
    [$code, $json] = command([...$cli, 'uml', '--format', 'json'], $nested);
    ensure(json_decode($json, true)['mutation_overlay']['status'] === 'available', 'YAML presentation edits must preserve overlay');
    file_put_contents($nested . '/.ub-mode.yaml', $nestedConfig . "  input_paths: [src]\n");
    [$code, $json] = command([...$cli, 'uml', '--format', 'json'], $nested);
    ensure(json_decode($json, true)['mutation_overlay']['status'] === 'stale', 'semantic selection edits must invalidate overlay');
    file_put_contents($nested . '/.ub-mode.yaml', $nestedConfig);
    infectionSettings($nested, 'ui-change', $nestedRaw);
    [$code, , $error] = command([...$cli, 'mutate', '--format', 'json'], $nested);
    ensure($code === 0, 'presentation change during run should not reject result: ' . $error);
    foreach ([null, $root . '/alternate.yaml'] as $configPath) {
        file_put_contents($configPath ?? $nested . '/.ub-mode.yaml', $nestedConfig);
        infectionSettings($nested, 'config-change', $nestedRaw);
        if ($configPath !== null) {
            $settings = json_decode(file_get_contents($nested . '/infection.json5'), true);
            $settings['fakeConfigPath'] = $configPath;
            file_put_contents($nested . '/infection.json5', json_encode($settings));
        }
        [$code, $json, $error] = command([...$cli, ...($configPath === null ? [] : ['--config', $configPath]), 'mutate', '--format', 'json'], $nested);
        ensure($code === 3 && $json === '' && str_contains($error, 'changed during mutation run'), 'reload active configuration and reject semantic edits during run');
    }
    file_put_contents($nested . '/.ub-mode.yaml', $nestedConfig);
    // Two same-line sibling arrows cannot be distinguished from Infection line data.
    file_put_contents($nested . '/src/Nested.php', "<?php\nclass Nested {\n public function run() {\n  return [fn() => 1, fn() => 2];\n }\n}\n");
    $sameLine = $rows[0];
    $sameLine['mutator']['originalStartLine'] = 4;
    infectionSettings($nested, 'ok', rawMutation(['escaped' => [$sameLine]]));
    [$code, $json] = command([...$cli, 'mutate', '--format', 'json'], $nested);
    ensure(json_decode($json, true)['mutants'][0]['unit_id'] === null, 'same-line siblings must remain ambiguous');
    [$code, $json] = command([...$cli, 'uml', '--format', 'json'], $nested);
    $sameLineReport = json_decode($json, true);
    ensure($sameLineReport['nodes'][0]['metrics']['survived'] === 1 && count($sameLineReport['nodes'][0]['members'][0]['mutations']) === 1
        && $sameLineReport['diagnostics'] !== [], 'ambiguous unit can still belong to a unique containing class and method');

    [$code, $normalJson] = command([...$cli, 'check', '--format', 'json'], $nested);
    $crapEdn = file_get_contents($nested . '/.metrics/crap.edn');
    $external = $root . '/external-reports';
    mkdir($external);
    rename($nested . '/.metrics', $nested . '/old-metrics');
    symlink($external, $nested . '/.metrics');
    [$code, $externalJson] = command([...$cli, 'check', '--format', 'json'], $nested);
    ensure($code === 0 && $externalJson === $normalJson && file_get_contents($external . '/crap.edn') === $crapEdn, 'external report symlink must preserve C/D JSON and EDN');
    foreach (['mutate', 'uml'] as $tool) {
        [$code] = command([...$cli, $tool, '--format', 'json'], $nested);
        ensure($code === 3, 'M/U output must remain inside project');
    }
    unlink($nested . '/.metrics');
    rename($nested . '/old-metrics', $nested . '/.metrics');

    foreach (['cleanup-tail', 'cleanup-fail', 'supervisor-error', 'kill-supervisor'] as $mode) {
        @unlink($nested . '/child.pid');
        infectionSettings($nested, $mode, rawMutation([]));
        [$code, , $error] = command([...$cli, 'mutate', '--format', 'json'], $nested);
        ensure($code === ($mode === 'cleanup-tail' ? 0 : 3), 'unexpected process outcome: ' . $mode . ' ' . $error);
        $child = trim(file_get_contents($nested . '/child.pid'));
        $deadline = microtime(true) + 2;
        do {
            $stat = @file_get_contents('/proc/' . $child . '/stat');
            if ($stat === false || str_contains($stat, ') Z ')) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        ensure($stat === false || str_contains($stat, ') Z '), 'guardian/cleanup must stop worker: ' . $mode);
        if ($mode === 'cleanup-tail') {
            $log = file_get_contents($nested . '/.metrics/mutate.log');
            ensure(str_contains($log, 'CLEANUP_STDOUT_END') && str_contains($log, 'CLEANUP_STDERR_END'), 'published log must include both cleanup streams');
        } elseif ($mode === 'cleanup-fail') {
            ensure(str_contains($error, 'CLEANUP_STDOUT_END') && str_contains($error, 'CLEANUP_STDERR_END'), 'error tail must be built after cleanup');
            $failedLogs = glob($nested . '/.metrics/.mutation-runs/*/engine.log');
            $failedLog = file_get_contents(end($failedLogs));
            ensure(str_contains($failedLog, 'CLEANUP_STDOUT_END') && str_contains($failedLog, 'CLEANUP_STDERR_END'), 'retained failed log must include cleanup output');
        }
    }
    // A killed CLI leaves cleanup to its still-owned supervisor/guardian.
    @unlink($nested . '/child.pid');
    infectionSettings($nested, 'interrupt', rawMutation([]));
    $owner = new Process([...$cli, 'mutate', '--format', 'json'], $nested);
    $owner->start();
    $deadline = microtime(true) + 3;
    while (!is_file($nested . '/child.pid') && microtime(true) < $deadline) {
        usleep(10000);
    }
    ensure(is_file($nested . '/child.pid'), 'parent-death fixture must start');
    $owner->signal(SIGKILL);
    $owner->wait();
    $child = trim(file_get_contents($nested . '/child.pid'));
    $deadline = microtime(true) + 2;
    do {
        $stat = @file_get_contents('/proc/' . $child . '/stat');
        if ($stat === false || str_contains($stat, ') Z ')) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    ensure($stat === false || str_contains($stat, ') Z '), 'parent disappearance must stop workers');
    $deadline = microtime(true) + 2;
    while (glob($nested . '/.ub-infection-*') !== [] && microtime(true) < $deadline) {
        usleep(10000);
    }
    ensure(glob($nested . '/.ub-infection-*') === [], 'parent disappearance must remove the temporary Infection config');

    file_put_contents($root . '/fault-start.php', <<<'FAULT'
<?php
namespace UbModePhp;
function fopen($path, $mode) {
    if (str_ends_with($path, '/run.lock') && in_array($GLOBALS['failure'], ['open', 'rollback'], true)) { return false; }
    return \fopen($path, $mode);
}
function flock($stream, $operation) { return $GLOBALS['failure'] === 'lock' ? false : \flock($stream, $operation); }
function tempnam($path, $prefix) { return $GLOBALS['failure'] === 'atomic' ? false : \tempnam($path, $prefix); }
function rmdir($path) { return $GLOBALS['failure'] === 'rollback' ? false : \rmdir($path); }
require $argv[1];
$GLOBALS['failure'] = $argv[3];
$before = count(get_resources('stream'));
try { ReportRetention::startRun($argv[2], Config::load($argv[2])); exit(1); }
catch (\Throwable $error) {
    echo json_encode(['error' => $error->getMessage(), 'streams' => count(get_resources('stream')) - $before,
        'directories' => count(glob($argv[2] . '/.metrics/.mutation-runs/*', GLOB_ONLYDIR))]);
}
FAULT);
    foreach (['open', 'lock', 'atomic', 'rollback'] as $failure) {
        $faultRoot = $root . '/fault-' . $failure;
        mkdir($faultRoot);
        [$code, $json, $error] = command(['php', $root . '/fault-start.php', $repository . '/vendor/autoload.php', $faultRoot, $failure], $root);
        $fault = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        ensure($code === 0 && $fault['streams'] === 0 && $fault['directories'] === ($failure === 'rollback' ? 1 : 0), 'failed initialization must close resources and roll back: ' . $failure);
        ensure($failure !== 'rollback' || str_contains($error, 'cannot roll back mutation run directory: ' . $faultRoot), 'rollback failure must identify the path');
    }
    file_put_contents($root . '/guardian-failure.php', <<<'GUARD'
<?php
namespace UbModePhp\Mutation;
function stream_socket_pair(...$args) { return false; }
require $argv[1];
ProcessSupervisor::main(['supervisor', $argv[2], $argv[3]]);
GUARD);
    [$code, , $error] = command(['php', $root . '/guardian-failure.php', $repository . '/src/Mutation/ProcessSupervisor.php', $nested . '/bin/infection', $nested . '/never.json'], $nested);
    ensure($code === 125 && str_contains($error, 'guardian channel') && !is_file($nested . '/never.json'), 'guardian startup failure must not start engine');
    echo "Mutation adapter, process and CLI tests passed\n";
} finally {
    removeTree($root);
}

if (in_array('--integration', $argv, true)) {
    $fixture = $repository . '/fixtures/mutation';
    ensure(is_file($fixture . '/vendor/bin/infection') && extension_loaded('xdebug'), 'integration needs fixture composer install and Xdebug coverage');
    $project = sys_get_temp_dir() . '/cdmu-real-mutation-' . bin2hex(random_bytes(6));
    mkdir($project);
    try {
        foreach (['src', 'tests', 'vendor'] as $directory) {
            [$code] = command(['cp', '-R', $fixture . '/' . $directory, $project . '/' . $directory], $repository);
            ensure($code === 0, 'cannot copy integration fixture');
        }
        foreach (['composer.json', 'composer.lock', 'infection.json5', 'phpunit.xml'] as $file) {
            copy($fixture . '/' . $file, $project . '/' . $file);
        }
        mkdir($project . '/tests/tmp');
        file_put_contents($project . '/.ub-mode.yaml', "mutator:\n  input_exclude: [tests/tmp]\n");
        file_put_contents($project . '/phpunit.xml', str_replace('</phpunit>', '<logging><junit outputFile="tests/junit.xml"/></logging></phpunit>', file_get_contents($project . '/phpunit.xml')));
        $testFile = $project . '/tests/CalculatorTest.php';
        file_put_contents($testFile, str_replace('        self::assertSame', "        file_put_contents(__DIR__ . '/tmp/runtime.txt', 'test output');\n        self::assertSame", file_get_contents($testFile)));
        $source = hash_file('sha256', $project . '/src/Calculator.php');
        [$code, $json, $error] = command([...$cli, 'mutate', '--min-msi', '80', '--format', 'json'], $project);
        $killed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        ensure($code === 0 && $killed['summary']['killed'] === 1 && $killed['summary']['msi'] === 100.0, 'real test must kill Plus mutant: ' . $error);
        ensure(is_file($project . '/tests/tmp/runtime.txt'), 'runtime output during mutation must not invalidate fingerprints');
        // Infection replaces PHPUnit logging with its own temporary JUnit target.
        // Run PHPUnit directly to exercise the configured persistent output too.
        $beforeOutputs = InputFingerprint::compute($project, Config::load($project));
        [$testCode, , $testError] = command(['php', $project . '/vendor/bin/phpunit', '--configuration', $project . '/phpunit.xml'], $project);
        ensure($testCode === 0 && is_file($project . '/tests/junit.xml'), 'real PHPUnit must create its configured JUnit output: ' . $testError);
        ensure($beforeOutputs === InputFingerprint::compute($project, Config::load($project)), 'real PHPUnit outputs must not invalidate fingerprints');
        [$code, $uml] = command([...$cli, 'uml', '--format', 'json'], $project);
        ensure($code === 0 && json_decode($uml, true)['mutation_overlay']['status'] === 'available', 'fresh real mutation must join UML');
        $test = $project . '/tests/CalculatorTest.php';
        file_put_contents($test, str_replace('assertSame(5, (new Calculator())->add(2, 3))', 'assertIsInt((new Calculator())->add(2, 3))', file_get_contents($test)));
        [$code, $uml] = command([...$cli, 'uml', '--format', 'json'], $project);
        ensure(json_decode($uml, true)['mutation_overlay']['status'] === 'stale', 'changed tests must invalidate real overlay');
        [$code, $json, $error] = command([...$cli, 'mutate', '--min-msi', '80', '--format', 'json'], $project);
        $survived = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        ensure($code === 2 && $survived['summary']['survived'] === 1 && $survived['summary']['msi'] === 0.0, 'weak real test must leave survivor: ' . $error);
        [$code, $uml] = command([...$cli, 'uml', '--format', 'json'], $project);
        $joined = json_decode($uml, true);
        ensure($joined['mutation_overlay']['status'] === 'available' && $joined['nodes'][0]['metrics']['mutation_status'] === 'survived', 'survivor must show on real UML');
        file_put_contents($project . '/src/Calculator.php', "\n// changed\n", FILE_APPEND);
        [$code, $uml] = command([...$cli, 'uml', '--format', 'json'], $project);
        ensure(json_decode($uml, true)['mutation_overlay']['status'] === 'stale', 'changed source must invalidate overlay');
        file_put_contents($project . '/src/Calculator.php', str_replace("\n// changed\n", '', file_get_contents($project . '/src/Calculator.php')));
        ensure(hash_file('sha256', $project . '/src/Calculator.php') === $source, 'real engine must preserve source');
        file_put_contents($project . '/.ub-mode.yaml', "mutator:\n  input_exclude: [tests/tmp]\numl:\n  view_limit: 55\n");
        [$code, $uml] = command([...$cli, 'uml', '--format', 'json'], $project);
        ensure(json_decode($uml, true)['mutation_overlay']['status'] === 'available', 'view settings must preserve overlay');

        file_put_contents($project . '/src/Calculator.php', <<<'CALCULATOR'
<?php
namespace MutationFixture;
final class Calculator {
    public function add(int $a, int $b): int {
        $add = function (int $x, int $y): int {
            return (fn (int $left, int $right): int => $left + $right)($x, $y);
        };
        return $add($a, $b);
    }
}
CALCULATOR);
        $nestedSource = hash_file('sha256', $project . '/src/Calculator.php');
        [$code, $json, $error] = command([...$cli, 'mutate', '--min-msi', '80', '--format', 'json'], $project);
        $nestedMutation = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        ensure($code === 2 && $nestedMutation['summary']['survived'] === 1
            && str_contains($nestedMutation['mutants'][0]['unit_id'] ?? '', ':arrow@'), 'real nested arrow survivor must bind to its unit: ' . $error);
        [$code, $uml] = command([...$cli, 'uml', '--format', 'json'], $project);
        $nestedNode = json_decode($uml, true)['nodes'][0];
        ensure($code === 0 && $nestedNode['metrics']['survived'] === 1 && count($nestedNode['members'][0]['mutations']) === 1
            && hash_file('sha256', $project . '/src/Calculator.php') === $nestedSource, 'real nested mutant must appear in class and method without editing source');

        if (getenv('CDMU_INTEGRATION_RESULTS')) {
            Reports::atomic(getenv('CDMU_INTEGRATION_RESULTS'), Reports::json(['killed' => $killed, 'survived' => $survived, 'nested_survived' => $nestedMutation]));
        }
        echo "Real Infection 0.29.14 / PHPUnit integration passed: killed=1 then survived=1\n";
    } finally {
        removeTree($project);
    }
}
