<?php

declare(strict_types=1);

namespace UbModePhp\Mutation;

/** Binds a mutant, known only by file and line, to the analysis unit that owns it. */
final class MutantBinder
{
    /**
     * Returns the id of the unique innermost unit containing the line. Infection reports no column,
     * so units with equal spans stay ambiguous and yield null.
     *
     * @param list<array<string, mixed>> $units
     */
    public static function unitId(array $units, string $file, int $line): ?string
    {
        $containing = array_values(array_filter($units, static fn (array $unit): bool =>
            $unit['file'] === $file && $line >= $unit['start_line'] && $line <= $unit['end_line']));
        $innermost = array_values(array_filter($containing, static function (array $unit) use ($containing): bool {
            foreach ($containing as $other) {
                if (
                    $other['start_line'] >= $unit['start_line'] && $other['end_line'] <= $unit['end_line']
                    && ($other['start_line'] > $unit['start_line'] || $other['end_line'] < $unit['end_line'])
                ) {
                    return false;
                }
            }
            return true;
        }));
        return count($innermost) === 1 ? $innermost[0]['id'] : null;
    }
}
