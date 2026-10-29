# Contributing

This repository contains the PHP analyzer and CLI. The optional agent workflow is maintained separately in [ub-mode-php](https://github.com/Yatseck-k/ub-mode-php).

## Development

- Use PHP 8.2+ and Composer 2.
- Keep AST inspection read-only and preserve report schema `1.0` unless a deliberate compatibility change is documented.
- Follow PSR-12 and keep metric calculations deterministic.
- Add focused behavioral tests for parser, metric, CLI, or report changes.

```sh
composer install
composer test
composer test-consumer
php bin/ub-php --root fixtures check --format json
```

Submit focused pull requests with a clear summary, tests actually run, and sample JSON when report structures change. Do not commit generated `.metrics/` or `vendor/` files.
