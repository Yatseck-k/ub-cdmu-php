<?php

declare(strict_types=1);

use UbModePhp\Mutation\InputFingerprint;
use UbModePhp\Uml\MutationOverlay;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support.php';

use UbModePhp\Config;
use UbModePhp\Reports;
use UbModePhp\Uml\Layout;
use UbModePhp\Uml\Topology;
use UbModePhp\Uml\Viewer;

$repository = dirname(__DIR__);
$root = $repository . '/fixtures/uml';
$files = ['src/Architecture.php'];
$graph = Topology::extract($root, $files);
$nodes = array_column($graph['nodes'], null, 'id');
ensure(isset($nodes['Architecture\\Domain\\Customer'], $nodes['DateTimeImmutable']), 'classes and resolved external aliases must exist');
ensure($nodes['Architecture\\Domain\\State']['kind'] === 'enum', 'enums must be distinguished');
ensure($graph['anonymous_classes_omitted'] === 1, 'anonymous omission must be explicit');
$edges = array_map(static fn (array $edge): string => $edge['from'] . '>' . $edge['kind'] . '>' . $edge['to'], $graph['edges']);
foreach (
    [
    'Architecture\\Domain\\Customer>extends>Architecture\\Domain\\Base',
    'Architecture\\Domain\\Customer>implements>Architecture\\Domain\\Named',
    'Architecture\\Domain\\Customer>trait-use>Architecture\\Domain\\Identity',
    'Architecture\\Application\\Service>type>Architecture\\Domain\\Customer',
    'Architecture\\Application\\Service>type>Architecture\\Domain\\Logged',
    'Architecture\\Application\\Service>new>DateTimeImmutable',
    'Architecture\\Application\\Service>static-call>DateTimeImmutable',
    'Architecture\\Cycle\\Left>type>Architecture\\Cycle\\Right',
    'Architecture\\Cycle\\Right>type>Architecture\\Cycle\\Left',
    ] as $edge
) {
    ensure(in_array($edge, $edges, true), 'missing relation: ' . $edge);
}
ensure($graph === Topology::extract($root, $files), 'topology must be deterministic');
$promoted = array_filter($nodes['Architecture\\Application\\Service']['members'], static fn (array $member): bool => ($member['promoted'] ?? false));
ensure(count($promoted) === 1, 'promoted properties must be included');
[$code, $json] = command(['php', $repository . '/bin/ub-php', 'uml', '--format', 'json'], $root);
ensure($code === 0, 'UML CLI must run without Infection');
$report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
ensure($report['tool'] === 'uml' && $report['mutation_overlay']['status'] === 'unavailable', 'UML must distinguish unavailable mutation data');
ensure($json === file_get_contents($root . '/.metrics/uml.json'), 'UML stdout must equal saved JSON');
$svg = Viewer::svg($report);
$document = new DOMDocument();
ensure($document->loadXML($svg, LIBXML_NONET), 'standalone SVG must be valid XML');
ensure($svg === Viewer::svg($report), 'SVG must be deterministic');
ensure(!str_contains($svg, 'data-id="DateTimeImmutable"'), 'external types must be hidden by default');

$shown = Viewer::visible($report['nodes'], '', $report['scope']['view_limit']);
$projectClasses = array_filter($report['nodes'], static fn (array $node): bool => !$node['external']);
ensure(count($shown) === count($projectClasses), 'only project classes are shown by default');
ensure(count(Viewer::visible($report['nodes'], '', 200, true)) === count($report['nodes']), 'external types can be shown on request');
$layout = Layout::compute($shown, $report['edges']);
ensure($layout === Layout::compute($shown, $report['edges']), 'layout must be deterministic');
ensure(count($layout['cards']) === count($shown) && $layout['width'] > 0 && $layout['height'] > 0, 'every shown class gets a card');
$byLayer = [];
foreach ($layout['cards'] as $id => $card) {
    $byLayer[$card['layer']][] = $card;
}
foreach ($byLayer as $cards) {
    usort($cards, static fn (array $a, array $b): int => $a['y'] <=> $b['y']);
    foreach ($cards as $index => $card) {
        ensure($index === 0 || $card['y'] >= $cards[$index - 1]['y'] + $cards[$index - 1]['height'], 'cards in a layer must not overlap');
    }
}
$reversed = 0;
foreach ($layout['edges'] as $edge) {
    $from = $layout['cards'][$edge['from']]['layer'];
    $to = $layout['cards'][$edge['to']]['layer'];
    ensure($from !== $to && $edge['reversed'] === ($from > $to), 'related classes sit in different layers');
    $reversed += (int) $edge['reversed'];
}
ensure($reversed === 1, 'a dependency cycle must produce exactly one reversed relation');
$byGroup = static fn (array $groups): array => array_unique(array_column(Layout::compute($shown, $report['edges'], $groups)['edges'], 'group'));
ensure($byGroup(['inherit' => false, 'type' => false, 'calls' => false]) === [], 'hidden groups draw no relations');
ensure($byGroup(['inherit' => true, 'type' => false, 'calls' => false]) === ['inherit'], 'only the requested group is drawn');
$empty = Layout::compute([], []);
ensure($empty['cards'] === [] && $empty['edges'] === [] && $empty['width'] > 0, 'an empty view must still lay out');
$report['nodes'][0]['name'] = '</script><script>globalThis.injected=true</script>';
$html = Viewer::html($report);
ensure(!str_contains($html, '<script>globalThis.injected=true</script>'), 'source labels must not inject HTML/script');
preg_match('/<script id="graph-data" type="application\/json">(.*?)<\/script>/s', $html, $embedded);
$decoded = json_decode($embedded[1], true, 512, JSON_THROW_ON_ERROR);
ensure($decoded['nodes'][0]['name'] === $report['nodes'][0]['name'], 'safe embedded JSON must preserve source labels');

$temp = sys_get_temp_dir() . '/cdmu-uml-' . bin2hex(random_bytes(6));
mkdir($temp . '/src', 0777, true);
file_put_contents($temp . '/src/Example.php', '<?php class Example {}');
try {
    Reports::bundle($temp, '.metrics', 'uml', ['uml.json' => 'old json', 'uml.svg' => 'old svg']);
    mkdir($temp . '/.metrics/uml.html');
    try {
        Reports::bundle($temp, '.metrics', 'uml', ['uml.json' => 'new json', 'uml.svg' => 'new svg', 'uml.html' => 'new html']);
        throw new RuntimeException('unmanaged artifact should block publication');
    } catch (RuntimeException $error) {
        ensure(str_contains($error->getMessage(), 'unmanaged'), 'publication should fail for unmanaged artifact');
    }
    ensure(file_get_contents($temp . '/.metrics/uml.json') === 'old json'
        && file_get_contents($temp . '/.metrics/uml.svg') === 'old svg', 'failed bundle must preserve previous generation');
    rmdir($temp . '/.metrics/uml.html');
    unlink($temp . '/.metrics/uml.json');
    unlink($temp . '/.metrics/uml.svg');
    [$code, $json] = command(['php', $repository . '/bin/ub-php', 'uml', '--format', 'json'], $temp);
    ensure($code === 0, 'temporary UML should generate');
    $old = file_get_contents($temp . '/.metrics/uml.json');
    file_put_contents($temp . '/src/Example.php', '<?php class Example { syntax error');
    [$code, $output, $error] = command(['php', $repository . '/bin/ub-php', 'uml'], $temp);
    ensure($code === 3 && $output === '' && !str_contains($error, 'Completed')
        && file_get_contents($temp . '/.metrics/uml.json') === $old, 'parse failure must preserve full successful UML');
    file_put_contents($temp . '/src/Example.php', '<?php namespace Many; ' . implode(' ', array_map(static fn (int $i): string => 'class C' . $i . ' {}', range(0, 204))));
    [$code, $json] = command(['php', $repository . '/bin/ub-php', 'uml', '--namespace', 'Many', '--format', 'json'], $temp);
    $large = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    ensure($code === 0 && count($large['nodes']) === 205 && count(Viewer::visible($large['nodes'], 'Many', 200)) === 200, 'view limit must not truncate canonical graph');
    if (getenv('CDMU_BROWSER_FIXTURE')) {
        copy($temp . '/.metrics/uml.html', getenv('CDMU_BROWSER_FIXTURE'));
        file_put_contents(getenv('CDMU_BROWSER_FIXTURE') . '.hostile.html', $html);
    }
    foreach (['--changed', '--namespace', '--min-msi'] as $flag) {
        $arguments = ['php', $repository . '/bin/ub-php', 'uml', $flag];
        if ($flag === '--min-msi') {
            $arguments[] = '10';
        }
        [$code] = command($arguments, $temp);
        ensure($code === 4, 'invalid UML flag must fail usage: ' . $flag);
    }
    file_put_contents($temp . '/src/Example.php', '<?php class A { public function value($x) { return 1; } } class B { public function value($x) { if ($x) { return 2; } return 1; } }');
    [$code, $json] = command(['php', $repository . '/bin/ub-php', 'uml', '--format', 'json'], $temp);
    $sameLine = array_column(json_decode($json, true)['nodes'], null, 'id');
    ensure($sameLine['A']['members'][0]['metrics']['complexity'] === 1 && $sameLine['B']['members'][0]['metrics']['complexity'] === 2, 'member indexes must separate classes with same-line method names');
    file_put_contents($temp . '/src/Example.php', '<?php class RealType {} class User { public realtype $item; }');
    $caseGraph = Topology::extract($temp, ['src/Example.php']);
    ensure(count($caseGraph['nodes']) === 2 && $caseGraph['edges'][0]['to'] === 'RealType', 'PHP type names are case insensitive');
    file_put_contents($temp . '/src/Example.php', '<?php class Duplicate {} class duplicate {}');
    $ambiguous = Topology::extract($temp, ['src/Example.php']);
    ensure(count($ambiguous['nodes']) === 1 && $ambiguous['nodes'][0]['kind'] === 'ambiguous'
        && count($ambiguous['nodes'][0]['definitions']) === 2 && $ambiguous['diagnostics'] !== [], 'duplicates must preserve explicit ambiguity');
    [$code, $json] = command(['php', $repository . '/bin/ub-php', 'uml', '--format', 'json'], $temp);
    $duplicateReport = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    ensure($code === 0 && $duplicateReport['status'] === 'WARN' && $duplicateReport['nodes'][0]['metrics']['crap_status'] === 'unknown', 'ambiguous graph should remain usable without invented metrics');
    [$code, $text] = command(['php', $repository . '/bin/ub-php', 'uml', '--verbose', '--progress', 'never'], $temp);
    ensure($code === 0 && str_contains($text, 'ambiguous') && !str_contains($text, ' | external'), 'ambiguous source must not be labeled external in text');
    if (getenv('CDMU_BROWSER_FIXTURE')) {
        copy($temp . '/.metrics/uml.html', getenv('CDMU_BROWSER_FIXTURE') . '.ambiguous.html');
    }
    file_put_contents($temp . '/src/Example.php', '<?php class Example { public function run() { return 1; } }');
    $externalCoverage = $temp . '-coverage.xml';
    file_put_contents($externalCoverage, '<coverage><project><file name="' . $temp . '/src/Example.php"><line num="1" type="stmt" count="1"/></file></project></coverage>');
    symlink($externalCoverage, $temp . '/coverage.xml');
    [$code, $json] = command(['php', $repository . '/bin/ub-php', 'uml', '--format', 'json'], $temp);
    $externalReport = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    ensure($code === 0 && $externalReport['nodes'][0]['metrics']['crap_status'] === 'PASS', 'explicit external Clover symlink must be usable');
    file_put_contents($externalCoverage, '<broken>');
    [$code, $json] = command(['php', $repository . '/bin/ub-php', 'uml', '--format', 'json'], $temp);
    $badCoverage = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    ensure($code === 0 && $badCoverage['status'] === 'WARN' && $badCoverage['diagnostics'] !== []
        && $badCoverage['nodes'][0]['metrics']['crap_status'] === 'unknown', 'bad optional coverage should explain unknown metrics and preserve graph');
    unlink($temp . '/coverage.xml');
    unlink($externalCoverage);
    $config = Config::load($temp);
    Reports::bundle($temp, '.metrics', 'mutate', ['mutate.json' => '{invalid']);
    [$code, $json] = command(['php', $repository . '/bin/ub-php', 'uml', '--format', 'json'], $temp);
    ensure($code === 0 && json_decode($json, true)['mutation_overlay']['status'] === 'unavailable', 'corrupt snapshot is unavailable data, not a programming error');
    $snapshot = ['schema_version' => '1.0', 'tool' => 'mutate', 'engine' => ['version' => '0.29.14'],
        'scope' => ['files' => ['src/Example.php']], 'status' => 'PASS', 'mutants' => [],
        'fingerprints' => InputFingerprint::compute($temp, $config)];
    Reports::bundle($temp, '.metrics', 'mutate', ['mutate.json' => Reports::json(array_replace($snapshot, ['fingerprints' => null]))]);
    [$code, $json] = command(['php', $repository . '/bin/ub-php', 'uml', '--format', 'json'], $temp);
    ensure($code === 0 && json_decode($json, true)['mutation_overlay']['status'] === 'unavailable', 'invalid snapshot fields must be classified as unavailable data');
    $invalidLocation = array_replace($snapshot, ['mutants' => [['unit_id' => null, 'status' => 'survived']]]);
    Reports::bundle($temp, '.metrics', 'mutate', ['mutate.json' => Reports::json($invalidLocation)]);
    [$code, $json, $error] = command(['php', $repository . '/bin/ub-php', 'uml', '--format', 'json'], $temp);
    ensure($code === 0 && $error === '' && json_decode($json, true)['mutation_overlay']['status'] === 'unavailable', 'missing mutant location must be unavailable data without PHP notices');
    Reports::bundle($temp, '.metrics', 'mutate', ['mutate.json' => Reports::json($snapshot)]);
    try {
        MutationOverlay::load($temp, $config, ['src/Example.php'], static function (): array {
            throw new TypeError('injected fingerprint bug');
        });
        throw new RuntimeException('internal error was hidden');
    } catch (TypeError $error) {
        ensure($error->getMessage() === 'injected fingerprint bug', 'internal exception must propagate, not become stale');
    }
} finally {
    removeTree($temp);
}
echo "UML topology, publication and CLI tests passed\n";
