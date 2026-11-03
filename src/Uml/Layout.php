<?php

declare(strict_types=1);

namespace UbModePhp\Uml;

/**
 * Layered left-to-right layout. Classes that depend on others sit left of their dependencies; long relations are
 * routed through lanes between cards. Integer arithmetic only: assets/uml-viewer.html ports this algorithm and a
 * browser test requires identical coordinates.
 */
final class Layout
{
    public const CARD_WIDTH = 240;
    public const COLUMN_GAP = 120;
    public const ROW_GAP = 26;
    public const MARGIN_X = 40;
    public const MARGIN_TOP = 60;
    public const LANE_HEIGHT = 12;
    public const MEMBER_ROWS = 4;

    private const HEADER_HEIGHT = 70;
    private const ROW_HEIGHT = 15;
    private const RANKS = ['extends' => 0, 'implements' => 0, 'trait-use' => 0, 'type' => 1, 'new' => 2, 'static-call' => 2];
    private const GROUPS = [0 => 'inherit', 1 => 'type', 2 => 'calls'];

    /**
     * @param array<int, array<string, mixed>> $nodes shown nodes
     * @param array<int, array<string, mixed>> $edges every relation of the report
     * @param array<string, bool> $groups which of inherit, type and calls are drawn
     * @return array{cards: array<string, array<string, mixed>>, edges: list<array<string, mixed>>, width: int, height: int, layers: int}
     */
    public static function compute(array $nodes, array $edges, array $groups = ['inherit' => true, 'type' => true, 'calls' => true]): array
    {
        $ids = array_column($nodes, 'id');
        $byId = array_combine($ids, $nodes);
        $pairs = self::pairs($byId, $edges, $groups);
        $layer = self::layers($ids, $pairs);
        $count = $layer === [] ? 0 : max($layer) + 1;
        [$columns, $chains] = self::columns($byId, $layer, $count, $pairs);
        $columns = self::order($columns, $chains, $count);
        $cards = self::place($columns, $chains);
        return self::result($cards, $chains, $byId, $count);
    }

    /**
     * @param array $node
     * @return array<int, string> text lines shown on a card below its header
     */
    public static function rows(array $node): array
    {
        if (($node['definitions'] ?? []) !== []) {
            return array_map(
                static fn (array $definition): string => $definition['file'] . ':' . $definition['start_line'],
                array_slice($node['definitions'], 0, 3),
            );
        }
        $public = array_values(array_filter($node['members'], static fn (array $member): bool => $member['visibility'] === 'public' && $member['kind'] !== 'property'));
        $rows = array_map(
            static fn (array $member): string => '+ ' . $member['name'] . ($member['kind'] === 'method' ? '()' : '') . ($member['type'] !== '' ? ': ' . $member['type'] : ''),
            array_slice($public, 0, self::MEMBER_ROWS),
        );
        if (count($public) > self::MEMBER_ROWS) {
            $rows[] = '+' . (count($public) - self::MEMBER_ROWS) . ' more';
        }
        return $rows;
    }

    public static function cardHeight(array $node): int
    {
        $rows = count(self::rows($node));
        return self::HEADER_HEIGHT + ($rows > 0 ? 4 + $rows * self::ROW_HEIGHT : 0);
    }

    /**
     * One entry per ordered pair of shown classes; kinds are merged so parallel relations draw as one line.
     *
     * @param array $byId
     * @param array $edges
     * @param array $groups
     * @return list<array{from: string, to: string, kinds: list<string>, drawn: list<string>, group: ?string}>
     */
    private static function pairs(array $byId, array $edges, array $groups): array
    {
        $pairs = [];
        foreach ($edges as $edge) {
            if (!isset($byId[$edge['from']], $byId[$edge['to']])) {
                continue;
            }
            $key = $edge['from'] . "\0" . $edge['to'];
            $pairs[$key] ??= ['from' => $edge['from'], 'to' => $edge['to'], 'kinds' => []];
            $pairs[$key]['kinds'][] = $edge['kind'];
        }
        ksort($pairs, SORT_STRING);
        $result = [];
        foreach ($pairs as $pair) {
            $kinds = array_values(array_unique($pair['kinds']));
            sort($kinds, SORT_STRING);
            $drawn = array_values(array_filter($kinds, static fn (string $kind): bool => ($groups[self::GROUPS[self::RANKS[$kind]]] ?? false) === true));
            $rank = $drawn === [] ? null : min(array_map(static fn (string $kind): int => self::RANKS[$kind], $drawn));
            $result[] = ['from' => $pair['from'], 'to' => $pair['to'], 'kinds' => $kinds, 'drawn' => $drawn, 'group' => $rank === null ? null : self::GROUPS[$rank]];
        }
        return $result;
    }

    /**
     * Longest-path layering over all relations. Back edges of cycles are ignored; sources move next to their dependencies.
     *
     * @param list<string> $ids
     * @param array $pairs
     * @return array<string, int>
     */
    private static function layers(array $ids, array $pairs): array
    {
        $out = [];
        foreach ($pairs as $pair) {
            $out[$pair['from']][] = $pair['to'];
        }
        $state = [];
        $back = [];
        $visit = static function (string $node) use (&$visit, &$state, &$back, $out): void {
            $state[$node] = 1;
            foreach ($out[$node] ?? [] as $next) {
                if (($state[$next] ?? 0) === 1) {
                    $back[$node . "\0" . $next] = true;
                } elseif (!isset($state[$next])) {
                    $visit($next);
                }
            }
            $state[$node] = 2;
        };
        foreach ($ids as $id) {
            if (!isset($state[$id])) {
                $visit($id);
            }
        }
        $preds = [];
        $succs = [];
        foreach ($pairs as $pair) {
            if (!isset($back[$pair['from'] . "\0" . $pair['to']])) {
                $preds[$pair['to']][] = $pair['from'];
                $succs[$pair['from']][] = $pair['to'];
            }
        }
        $layer = [];
        $depth = static function (string $node) use (&$depth, &$layer, $preds): int {
            if (!isset($layer[$node])) {
                $layer[$node] = 0;
                foreach ($preds[$node] ?? [] as $previous) {
                    $layer[$node] = max($layer[$node], $depth($previous) + 1);
                }
            }
            return $layer[$node];
        };
        foreach ($ids as $id) {
            $depth($id);
        }
        foreach ($ids as $id) {
            if (!isset($preds[$id]) && isset($succs[$id])) {
                $layer[$id] = min(array_map(static fn (string $next): int => $layer[$next], $succs[$id])) - 1;
            }
        }
        $used = array_values(array_unique($layer));
        sort($used);
        $rank = array_flip($used);
        return array_map(static fn (int $value): int => $rank[$value], $layer);
    }

    /**
     * @param array $byId
     * @param array $layer
     * @param int $count
     * @param array $pairs
     * @return array{0: array<int, array<int, array<string, mixed>>>, 1: array<int, array<string, mixed>>} columns of items and one chain per drawn relation
     */
    private static function columns(array $byId, array $layer, int $count, array $pairs): array
    {
        $columns = array_fill(0, $count, []);
        $sorted = array_keys($byId);
        usort($sorted, static fn (string $a, string $b): int => [$byId[$a]['namespace'], $a] <=> [$byId[$b]['namespace'], $b]);
        foreach ($sorted as $id) {
            $columns[$layer[$id]][] = ['key' => 'n:' . $id, 'id' => $id, 'height' => self::cardHeight($byId[$id]), 'real' => true];
        }
        $chains = [];
        foreach ($pairs as $pair) {
            if ($pair['drawn'] === []) {
                continue;
            }
            $index = count($chains);
            $low = min($layer[$pair['from']], $layer[$pair['to']]);
            $high = max($layer[$pair['from']], $layer[$pair['to']]);
            $keys = [];
            for ($l = $low; $l <= $high; $l++) {
                if ($l === $low) {
                    $keys[] = 'n:' . ($layer[$pair['from']] === $low ? $pair['from'] : $pair['to']);
                } elseif ($l === $high) {
                    $keys[] = 'n:' . ($layer[$pair['from']] === $high ? $pair['from'] : $pair['to']);
                } else {
                    $keys[] = 'd:' . $index . ':' . $l;
                    $columns[$l][] = ['key' => end($keys), 'id' => '', 'height' => self::LANE_HEIGHT, 'real' => false];
                }
            }
            $chains[] = $pair + ['keys' => $keys, 'low' => $low, 'reversed' => $layer[$pair['from']] > $layer[$pair['to']]];
        }
        return [$columns, $chains];
    }

    /**
     *  Barycenter sweeps reduce crossings; ties keep the previous order.
     *
     * @param array $columns
     * @param array $chains
     * @param int $count
     * @return array
     */
    private static function order(array $columns, array $chains, int $count): array
    {
        $down = [];
        $up = [];
        foreach ($chains as $chain) {
            for ($i = 1, $length = count($chain['keys']); $i < $length; $i++) {
                $down[$chain['keys'][$i]][] = $chain['keys'][$i - 1];
                $up[$chain['keys'][$i - 1]][] = $chain['keys'][$i];
            }
        }
        for ($round = 0; $round < 4; $round++) {
            for ($l = 1; $l < $count; $l++) {
                $columns[$l] = self::sweep($columns[$l], $columns[$l - 1], $down);
            }
            for ($l = $count - 2; $l >= 0; $l--) {
                $columns[$l] = self::sweep($columns[$l], $columns[$l + 1], $up);
            }
        }
        return $columns;
    }

    private static function sweep(array $column, array $reference, array $neighbours): array
    {
        $position = [];
        foreach ($reference as $index => $item) {
            $position[$item['key']] = $index;
        }
        $keyed = [];
        foreach ($column as $index => $item) {
            $near = array_map(static fn (string $key): int => $position[$key], $neighbours[$item['key']] ?? []);
            $keyed[] = [$near === [] ? $index * 1000 : intdiv(array_sum($near) * 1000, count($near)), $index, $item];
        }
        usort($keyed, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_map(static fn (array $entry): array => $entry[2], $keyed);
    }

    /**
     * Stacks every column, then pulls items towards the centre of their neighbours in alternating passes.
     *
     * @param array $columns
     * @param array $chains
     * @return array<string, array{x: int, y: int, height: int, layer: int, index: int}> by item key
     */
    private static function place(array $columns, array $chains): array
    {
        $before = [];
        $after = [];
        foreach ($chains as $chain) {
            for ($i = 1, $length = count($chain['keys']); $i < $length; $i++) {
                $before[$chain['keys'][$i]][] = $chain['keys'][$i - 1];
                $after[$chain['keys'][$i - 1]][] = $chain['keys'][$i];
            }
        }
        $height = [];
        $top = [];
        foreach ($columns as $column) {
            $y = 0;
            foreach ($column as $item) {
                $height[$item['key']] = $item['height'];
                $top[$item['key']] = $y;
                $y += $item['height'] + self::ROW_GAP;
            }
        }
        $count = count($columns);
        for ($pass = 0; $pass < 4; $pass++) {
            for ($l = 1; $l < $count; $l++) {
                self::pull($columns[$l], $before, $top, $height);
            }
            for ($l = $count - 2; $l >= 0; $l--) {
                self::pull($columns[$l], $after, $top, $height);
            }
        }
        $minimum = $top === [] ? 0 : min($top);
        $cards = [];
        foreach ($columns as $l => $column) {
            foreach ($column as $index => $item) {
                $cards[$item['key']] = [
                    'x' => self::MARGIN_X + $l * (self::CARD_WIDTH + self::COLUMN_GAP),
                    'y' => $top[$item['key']] - $minimum + self::MARGIN_TOP,
                    'height' => $item['height'], 'layer' => $l, 'index' => $index,
                ];
            }
        }
        return $cards;
    }

    /**
     * @param array $column
     * @param array<string, list<string>> $links neighbours in the reference column
     * @param array $top
     * @param array $height
     */
    private static function pull(array $column, array $links, array &$top, array $height): void
    {
        $floor = null;
        foreach ($column as $item) {
            $key = $item['key'];
            $centres = array_map(static fn (string $other): int => $top[$other] + intdiv($height[$other], 2), $links[$key] ?? []);
            $wanted = $centres === [] ? $top[$key] + intdiv($height[$key], 2) : intdiv(array_sum($centres), count($centres));
            $y = $wanted - intdiv($height[$key], 2);
            if ($floor !== null) {
                $y = max($y, $floor);
            }
            $top[$key] = $y;
            $floor = $y + $height[$key] + self::ROW_GAP;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $cards
     * @param array<int, array<string, mixed>> $chains
     * @param array<string, array<string, mixed>> $byId
     * @param int $count
     * @return array{cards: array<string, array<string, mixed>>, edges: list<array<string, mixed>>, width: int, height: int, layers: int}
     */
    private static function result(array $cards, array $chains, array $byId, int $count): array
    {
        $ports = self::ports($cards, $chains);
        $edges = [];
        foreach ($chains as $index => $chain) {
            $keys = $chain['keys'];
            $x = $cards[$keys[0]]['x'] + self::CARD_WIDTH;
            $y = $ports['out'][$index];
            $path = 'M' . $x . ' ' . $y;
            foreach (array_slice($keys, 1) as $step => $key) {
                $card = $cards[$key];
                if ($step === count($keys) - 2) {
                    $path .= self::curve($x, $y, $card['x'], $ports['in'][$index]);
                    break;
                }
                $lane = $card['y'] + intdiv($card['height'], 2);
                $path .= self::curve($x, $y, $card['x'], $lane) . ' L' . ($card['x'] + self::CARD_WIDTH) . ' ' . $lane;
                $x = $card['x'] + self::CARD_WIDTH;
                $y = $lane;
            }
            $edges[] = ['from' => $chain['from'], 'to' => $chain['to'], 'kinds' => $chain['kinds'], 'drawn' => $chain['drawn'],
                'group' => $chain['group'], 'reversed' => $chain['reversed'], 'path' => $path];
        }
        $shown = [];
        $bottom = 0;
        foreach ($byId as $id => $node) {
            $shown[$id] = $cards['n:' . $id] + ['rows' => self::rows($node)];
        }
        foreach ($cards as $card) {
            $bottom = max($bottom, $card['y'] + $card['height']);
        }
        return [
            'cards' => $shown, 'edges' => $edges, 'layers' => $count,
            'width' => 2 * self::MARGIN_X + max(0, $count * self::CARD_WIDTH + ($count - 1) * self::COLUMN_GAP),
            'height' => $bottom + 40,
        ];
    }

    private static function curve(int $x0, int $y0, int $x1, int $y1): string
    {
        $half = intdiv($x1 - $x0, 2);
        return ' C' . ($x0 + $half) . ' ' . $y0 . ' ' . ($x1 - $half) . ' ' . $y1 . ' ' . $x1 . ' ' . $y1;
    }

    /**
     * Spreads the attachment points of relations that share a card side.
     *
     * @param array $cards
     * @param array $chains
     * @return array{out: array<int, int>, in: array<int, int>}
     */
    private static function ports(array $cards, array $chains): array
    {
        $out = [];
        $in = [];
        foreach ($chains as $index => $chain) {
            $first = $chain['keys'][0];
            $second = $chain['keys'][1];
            $out[$first][] = [$cards[$second]['y'] + intdiv($cards[$second]['height'], 2), $index];
            $penultimate = $chain['keys'][count($chain['keys']) - 2];
            $last = $chain['keys'][count($chain['keys']) - 1];
            $in[$last][] = [$cards[$penultimate]['y'] + intdiv($cards[$penultimate]['height'], 2), $index];
        }
        $result = ['out' => [], 'in' => []];
        foreach (['out' => $out, 'in' => $in] as $side => $groups) {
            foreach ($groups as $key => $list) {
                usort($list, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
                $card = $cards[$key];
                $total = count($list);
                foreach ($list as $position => [, $index]) {
                    $y = $card['y'] + intdiv($card['height'], 2) + ($position * 2 - ($total - 1)) * 4;
                    $result[$side][$index] = max($card['y'] + 10, min($card['y'] + $card['height'] - 10, $y));
                }
            }
        }
        return $result;
    }
}
