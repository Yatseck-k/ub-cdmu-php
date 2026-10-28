<?php

declare(strict_types=1);

use MutationFixture\Calculator;
use PHPUnit\Framework\TestCase;

final class CalculatorTest extends TestCase
{
    public function testAddition(): void
    {
        self::assertSame(5, (new Calculator())->add(2, 3));
    }
}
