<?php

declare(strict_types=1);

namespace UbModePhp\Uml;

use UbModePhp\Reports;

final class Viewer
{
    public static function visible(array $nodes, string $namespace, int $limit): array
    {
        return array_slice(array_values(array_filter($nodes, static fn (array $node): bool =>
            $namespace === '' || $node['namespace'] === $namespace || str_starts_with($node['namespace'], $namespace . '\\'))), 0, $limit);
    }

    public static function svg(array $report): string
    {
        $nodes = self::visible($report['nodes'], $report['scope']['namespace'], $report['scope']['view_limit']);
        $positions = [];
        foreach ($nodes as $index => $node) {
            $positions[$node['id']] = [40 + ($index % 3) * 400, 100 + intdiv($index, 3) * 260];
        }
        $height = max(360, 120 + (int) ceil(count($nodes) / 3) * 260);
        $lines = ['<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="PHP class diagram" viewBox="0 0 1240 ' . $height . '">',
            '<defs><marker id="arrow" markerWidth="10" markerHeight="10" refX="9" refY="3" orient="auto"><path d="M0,0 L0,6 L9,3 z" fill="#64748b"/></marker></defs>',
            '<rect width="1240" height="' . $height . '" fill="#f8fafc"/>',
            '<text x="40" y="30" font-family="sans-serif" font-size="18" fill="#0f172a">PHP UML — C: CRAP, M: mutation; gray means unknown/unavailable</text>'];
        foreach ($report['edges'] as $edge) {
            if (!isset($positions[$edge['from']], $positions[$edge['to']])) {
                continue;
            }
            [$x, $y] = $positions[$edge['from']];
            [$tx, $ty] = $positions[$edge['to']];
            [$path, $labelX, $labelY] = self::connection($x, $y, $tx, $ty);
            $lines[] = sprintf(
                '<g class="edge" data-from="%s" data-to="%s"><path d="%s" fill="none" stroke="#64748b" stroke-width="1.5" marker-end="url(#arrow)"/><text x="%g" y="%g" font-family="sans-serif" font-size="9" text-anchor="middle" fill="#475569">%s</text><title>%s</title></g>',
                self::escape($edge['from']),
                self::escape($edge['to']),
                $path,
                $labelX,
                $labelY,
                self::escape($edge['kind']),
                self::escape($edge['from'] . ' → ' . $edge['to'] . ' (' . $edge['kind'] . ')')
            );
        }
        foreach ($nodes as $node) {
            [$x, $y] = $positions[$node['id']];
            $color = $node['kind'] === 'ambiguous' ? '#d97706' : ($node['metrics']['crap_status'] === 'FAIL' ? '#dc2626' : '#94a3b8');
            $lines[] = sprintf('<g class="class-card" data-id="%s" tabindex="0" role="button" aria-label="%s" transform="translate(%d,%d)">', self::escape($node['id']), self::escape($node['id']), $x, $y);
            $lines[] = '<rect width="340" height="210" rx="10" fill="white" stroke="' . $color . '" stroke-width="2"/>';
            $labels = [$node['namespace'] ?: '(global)', $node['name'] . ' [' . $node['kind'] . ']',
                'C: ' . $node['metrics']['crap_status'] . ' | M: ' . $node['metrics']['mutation_status']];
            foreach (array_slice($node['definitions'] ?? [], 0, 3) as $definition) {
                $labels[] = $definition['file'] . ':' . $definition['start_line'];
            }
            foreach (array_slice($node['members'], 0, 5) as $member) {
                $labels[] = ($member['visibility'] === 'private' ? '-' : ($member['visibility'] === 'protected' ? '#' : '+')) . ' '
                    . $member['name'] . ($member['kind'] === 'method' ? '()' : '') . ($member['type'] !== '' ? ': ' . $member['type'] : '');
            }
            foreach ($labels as $index => $label) {
                $characters = preg_split('//u', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [$label];
                $short = count($characters) > 43 ? implode('', array_slice($characters, 0, 40)) . '...' : $label;
                $lines[] = sprintf('<text x="14" y="%d" font-family="sans-serif" font-size="%d" fill="#0f172a">%s</text>', 24 + $index * 23, $index === 1 ? 16 : 12, self::escape($short));
            }
            $lines[] = '<title>' . self::escape($node['id']) . '</title></g>';
        }
        $lines[] = '</svg>';
        return implode("\n", $lines) . "\n";
    }

    public static function html(array $report): string
    {
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return strtr(Reports::read(dirname(__DIR__, 2) . '/assets/uml-viewer.html'), ['{{DATA}}' => $json, '{{SVG}}' => self::svg($report)]);
    }

    private static function connection(int $x, int $y, int $tx, int $ty): array
    {
        if ($x === $tx) {
            $sx = $x + 170;
            $sy = $ty > $y ? $y + 210 : $y;
            $ex = $tx + 170;
            $ey = $ty > $y ? $ty : $ty + 210;
            $mid = ($sy + $ey) / 2;
            return [sprintf('M%g %g C%g %g %g %g %g %g', $sx, $sy, $sx, $mid, $ex, $mid, $ex, $ey), $sx + 25, $mid];
        }
        $sx = $tx > $x ? $x + 340 : $x;
        $ex = $tx > $x ? $tx : $tx + 340;
        $sy = $y + 105;
        $ey = $ty + 105;
        $mid = ($sx + $ex) / 2;
        return [sprintf('M%g %g C%g %g %g %g %g %g', $sx, $sy, $mid, $sy, $mid, $ey, $ex, $ey), $mid, ($sy + $ey) / 2 - 6];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
