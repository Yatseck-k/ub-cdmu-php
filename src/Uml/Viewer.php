<?php

declare(strict_types=1);

namespace UbModePhp\Uml;

use UbModePhp\Reports;

final class Viewer
{
    private const PALETTE = ['#0ea5e9', '#8b5cf6', '#10b981', '#f59e0b', '#ec4899', '#14b8a6', '#6366f1', '#84cc16', '#f97316', '#06b6d4'];
    private const STATUS_COLORS = ['PASS' => '#16875a', 'killed' => '#16875a', 'FAIL' => '#dc2626', 'survived' => '#d97706', 'incomplete' => '#d97706'];
    private const EDGE_STYLES = [
        'inherit' => ['#0f172a', 2, '', 1],
        'type' => ['#2563eb', 1.5, '6 4', 0.9],
        'calls' => ['#94a3b8', 1, '', 0.7],
    ];
    private const EDGE_LABELS = ['inherit' => 'extends / implements', 'type' => 'type hint', 'calls' => 'new / static call'];

    /**
     * @param array $nodes
     * @param string $namespace
     * @param int $limit
     * @param bool $external
     * @return array<int, array<string, mixed>>
     */
    public static function visible(array $nodes, string $namespace, int $limit, bool $external = false): array
    {
        return array_slice(array_values(array_filter($nodes, static fn (array $node): bool =>
            ($external || !$node['external'])
            && ($namespace === '' || $node['namespace'] === $namespace || str_starts_with($node['namespace'], $namespace . '\\')))), 0, $limit);
    }

    public static function svg(array $report): string
    {
        $nodes = self::visible($report['nodes'], $report['scope']['namespace'], $report['scope']['view_limit']);
        $layout = Layout::compute($nodes, $report['edges']);
        $colors = self::namespaceColors($report['nodes']);
        $legend = self::legend($layout['width'], $layout['height'], array_values(array_unique(array_column($nodes, 'namespace'))), $colors);
        $height = $layout['height'] + $legend['height'];
        $lines = ['<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="PHP class diagram" viewBox="0 0 ' . max(360, $layout['width']) . ' ' . $height . '">',
            '<defs>' . implode('', array_map(
                static fn (string $group): string => '<marker id="arrow-' . $group . '" markerWidth="10" markerHeight="10" refX="9" refY="3" orient="auto-start-reverse"><path d="M0,0 L0,6 L9,3 z" fill="' . self::EDGE_STYLES[$group][0] . '"/></marker>',
                array_keys(self::EDGE_STYLES),
            )) . '</defs>',
            '<rect width="100%" height="' . $height . '" fill="#f8fafc"/>',
            '<text x="40" y="34" font-family="sans-serif" font-size="18" fill="#0f172a">PHP UML</text>'];
        foreach ($layout['edges'] as $edge) {
            $lines[] = self::edge($edge);
        }
        foreach ($layout['cards'] as $id => $card) {
            $node = $nodes[array_search($id, array_column($nodes, 'id'), true)];
            $lines[] = self::card($node, $card, $colors[$node['namespace']] ?? '#94a3b8');
        }
        array_push($lines, ...$legend['lines']);
        $lines[] = '</svg>';
        return implode("\n", $lines) . "\n";
    }

    public static function html(array $report): string
    {
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return strtr(Reports::read(dirname(__DIR__, 2) . '/assets/uml-viewer.html'), ['{{DATA}}' => $json, '{{SVG}}' => self::svg($report)]);
    }

    /**
     * @param array $nodes
     * @return array<string, string> namespace => colour, stable for the whole report
     */
    private static function namespaceColors(array $nodes): array
    {
        $names = array_values(array_unique(array_column(array_filter($nodes, static fn (array $node): bool => !$node['external']), 'namespace')));
        sort($names, SORT_STRING);
        $colors = [];
        foreach ($names as $index => $name) {
            $colors[$name] = self::PALETTE[$index % count(self::PALETTE)];
        }
        return $colors;
    }

    private static function edge(array $edge): string
    {
        [$color, $width, $dash, $opacity] = self::EDGE_STYLES[$edge['group']];
        return sprintf(
            '<g class="edge edge-%s" data-from="%s" data-to="%s" data-kinds="%s"><path d="%s" fill="none" stroke="%s" stroke-width="%s" stroke-opacity="%s"%s %s="url(#arrow-%s)"/><title>%s</title></g>',
            $edge['group'],
            self::escape($edge['from']),
            self::escape($edge['to']),
            self::escape(implode(',', $edge['kinds'])),
            $edge['path'],
            $color,
            $width,
            $opacity,
            $dash === '' ? '' : ' stroke-dasharray="' . $dash . '"',
            $edge['reversed'] ? 'marker-start' : 'marker-end',
            $edge['group'],
            self::escape($edge['from'] . ' → ' . $edge['to'] . ' (' . implode(', ', $edge['kinds']) . ')'),
        );
    }

    private static function card(array $node, array $card, string $stripe): string
    {
        $border = $node['kind'] === 'ambiguous' ? '#d97706' : ($node['metrics']['crap_status'] === 'FAIL' ? '#dc2626' : '#cbd5e1');
        $title = self::shorten($node['name'] . ($node['kind'] === 'class' ? '' : ' ‹' . $node['kind'] . '›'), 26);
        $lines = [
            sprintf('<g class="class-card" data-id="%s" data-x="%d" data-y="%d" data-layer="%d" data-index="%d" tabindex="0" role="button" aria-label="%s" transform="translate(%d,%d)">', self::escape($node['id']), $card['x'], $card['y'], $card['layer'], $card['index'], self::escape($node['id']), $card['x'], $card['y']),
            sprintf('<rect width="%d" height="%d" rx="8" fill="white" stroke="%s" stroke-width="2"%s/>', Layout::CARD_WIDTH, $card['height'], $border, $node['external'] ? ' stroke-dasharray="5 4"' : ''),
            sprintf('<rect width="6" height="%d" rx="3" fill="%s"/>', $card['height'], $stripe),
            sprintf('<text x="16" y="18" font-family="sans-serif" font-size="11" fill="#64748b">%s</text>', self::escape(self::shorten($node['namespace'] ?: '(global)', 34))),
            sprintf('<text x="16" y="40" font-family="sans-serif" font-size="14" font-weight="600" fill="#0f172a">%s</text>', self::escape($title)),
        ];
        foreach ([['CRAP', $node['metrics']['crap_status'], 22], ['mutation', $node['metrics']['mutation_status'], 118]] as [$label, $status, $x]) {
            $lines[] = sprintf('<circle cx="%d" cy="56" r="4" fill="%s"/><text x="%d" y="60" font-family="sans-serif" font-size="10" fill="#64748b">%s %s</text>', $x, self::STATUS_COLORS[$status] ?? '#94a3b8', $x + 9, $label, self::escape($status));
        }
        $color = ($node['definitions'] ?? []) !== [] ? '#92400e' : '#334155';
        foreach (Layout::rows($node) as $index => $row) {
            $lines[] = sprintf('<text x="16" y="%d" font-family="sans-serif" font-size="11" fill="%s">%s</text>', 82 + $index * 15, $color, self::escape(self::shorten($row, 36)));
        }
        $lines[] = '<title>' . self::escape($node['id']) . '</title></g>';
        return implode("\n", $lines);
    }

    /**
     * @param int $width
     * @param int $top
     * @param list<string> $namespaces
     * @param array<string, string> $colors
     * @return array{lines: list<string>, height: int}
     */
    private static function legend(int $width, int $top, array $namespaces, array $colors): array
    {
        $items = [];
        foreach (self::EDGE_STYLES as $group => [$color, $strokeWidth, $dash]) {
            $items[] = [sprintf('<line x1="0" y1="-4" x2="26" y2="-4" stroke="%s" stroke-width="%s"%s/>', $color, $strokeWidth, $dash === '' ? '' : ' stroke-dasharray="' . $dash . '"'), self::EDGE_LABELS[$group], 34];
        }
        sort($namespaces, SORT_STRING);
        foreach ($namespaces as $name) {
            $items[] = [sprintf('<rect y="-10" width="10" height="10" rx="2" fill="%s"/>', $colors[$name] ?? '#94a3b8'), $name ?: '(global)', 16];
        }
        $lines = [];
        $x = 40;
        $y = $top + 16;
        foreach ($items as [$mark, $label, $offset]) {
            $size = $offset + 7 * strlen($label) + 22;
            if ($x > 40 && $x + $size > max(360, $width) - 40) {
                $x = 40;
                $y += 22;
            }
            $lines[] = sprintf('<g transform="translate(%d,%d)">%s<text x="%d" y="0" font-family="sans-serif" font-size="11" fill="#334155">%s</text></g>', $x, $y, $mark, $offset, self::escape($label));
            $x += $size;
        }
        $lines[] = sprintf('<text x="40" y="%d" font-family="sans-serif" font-size="11" fill="#64748b">Dots: CRAP and mutation status, gray means unknown or unavailable.</text>', $y + 24);
        return ['lines' => $lines, 'height' => $y + 24 - $top + 14];
    }

    private static function shorten(string $text, int $limit): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
