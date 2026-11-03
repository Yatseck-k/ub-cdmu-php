<?php
namespace Demo;

trait Shared {
    public function shared(int $value): int {
        return $value + 1;
    }
}

interface Contract {
    public function run(int $value): int;
}

enum State: string { case Ready = 'ready'; }

class Example implements Contract {
    use Shared;

    public function run(int $value): int {
        if ($value > 0 && $value < 10) {
            return $value;
        }
        return 0;
    }
}

function helper(int $value): int {
    return $value > 0 ? $value : 0;
}

function choose(int $value): int {
    return match ($value) { 1 => 1, 2 => 2, default => 0 };
}

$closure = function (int $value): int { return $value + 1; };
$arrow = fn (int $value): int => $value + 1;
$anonymous = [new class { public function value(): int { return 1; } }, new class { public function value(): int { return 2; } }];
