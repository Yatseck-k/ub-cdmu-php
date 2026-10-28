<?php

declare(strict_types=1);

namespace Architecture\Domain {
    interface Named { public function name(): string; }
    interface Logged { public function log(): void; }
    trait Identity { public function id(): int { return 1; } }
    enum State: string { case Active = 'active'; }
    abstract class Base { abstract public function name(): string; }
    final class Customer extends Base implements Named
    {
        use Identity;
        public function __construct(public State $state = State::Active) {}
        public function name(): string { return 'customer'; }
        public function peer(?self $other): self { return $other ?? $this; }
    }
}
namespace Architecture\Application {
    use Architecture\Domain\Customer as Client;
    use Architecture\Domain\Named;
    use Architecture\Domain\Logged;
    use DateTimeImmutable as Clock;

    final class Service
    {
        public function __construct(private Client $client) {}
        public function execute(Named&Logged $both): Client|Clock
        {
            $both->log();
            return new Clock();
        }
        public function now(): Clock { return Clock::createFromFormat('Y', '2026'); }
    }
}
namespace Architecture\Cycle {
    class Left { public function __construct(public Right $right) {} }
    class Right { public function __construct(public Left $left) {} }
    $anonymous = new class { public function work(): void {} };
}
