<?php

declare(strict_types=1);

namespace UbModePhp;

final class Metrics
{
    public static function crap(array $units, array $coverage, float $threshold): array
    {
        $entries = [];
        foreach ($units as $unit) {
            $percent = Clover::forUnit($unit, $coverage);
            $score = $percent === null
                ? null
                : (float) ($unit['complexity'] ** 2 * (1 - $percent / 100) ** 3 + $unit['complexity']);
            $entries[] = $unit + [
                'coverage' => $percent,
                'coverage_type' => 'line',
                'crap' => $score,
                'exceeded_threshold' => $score !== null && $score >= $threshold,
            ];
        }
        usort($entries, static fn (array $a, array $b): int =>
            [$a['file'], $a['start_line'], $a['id']] <=> [$b['file'], $b['start_line'], $b['id']]);
        return $entries;
    }

    /** @param (callable(int, int): void)|null $progress */
    public static function dry(
        array $units,
        float $threshold,
        int $minLines,
        int $minNodes,
        ?callable $progress = null,
    ): array
    {
        $eligible = array_values(array_filter(
            $units,
            static fn (array $unit): bool => in_array($unit['kind'], ['function', 'method'], true)
                && $unit['end_line'] - $unit['start_line'] + 1 >= $minLines
                && $unit['node_count'] >= $minNodes,
        ));
        $total = count($eligible);
        if ($progress !== null) {
            $progress(0, $total);
        }
        $result = [];
        foreach ($eligible as $index => $left) {
            for ($other = $index + 1; $other < count($eligible); $other++) {
                $right = $eligible[$other];
                if ($left['kind'] !== $right['kind'] || $left['id'] === $right['id']) {
                    continue;
                }
                $a = array_unique($left['features']);
                $b = array_unique($right['features']);
                $union = array_unique(array_merge($a, $b));
                if ($union === []) {
                    continue;
                }
                $score = (float) (count(array_intersect($a, $b)) / count($union));
                if ($score >= $threshold) {
                    $result[] = [
                        'score' => $score,
                        'left' => self::span($left),
                        'right' => self::span($right),
                        'left_nodes' => $left['node_count'],
                        'right_nodes' => $right['node_count'],
                    ];
                }
            }
            if ($progress !== null && $index + 1 < $total) {
                $progress($index + 1, $total);
            }
        }
        usort($result, static fn (array $a, array $b): int => ($b['score'] <=> $a['score'])
            ?: ([$a['left']['file'], $a['left']['start_line'], $a['right']['file'], $a['right']['start_line']]
                <=> [$b['left']['file'], $b['left']['start_line'], $b['right']['file'], $b['right']['start_line']]));
        if ($progress !== null && $total > 0) {
            $progress($total, $total);
        }
        return $result;
    }

    private static function span(array $unit): array
    {
        return array_intersect_key($unit, array_flip(['id', 'file', 'start_line', 'end_line', 'name']));
    }
}
