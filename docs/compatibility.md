# Compatibility and provenance

[Русская версия](ru/compatibility.md)

The Composer package needs PHP 8.2+, Composer 2 for installation, and the DOM and JSON PHP extensions. Runtime dependencies include `nikic/php-parser` 5.x and `symfony/yaml` 7.4. Git is needed only for `--changed`. Python and Docker are not required. The repository is MIT; the parser is BSD-3-Clause and Symfony YAML is MIT. Crapper and Dryer algorithms and EDN formats were studied from their upstream 0BSD repositories; no upstream implementation code is included here. Preserve dependency license notices in distributions.

The parser is tested against plain PHP plus small Laravel, Symfony, and Yii-shaped fixtures. These are syntax samples, not full framework applications. A parser's ability to accept newer target PHP syntax is separate from the PHP runtime version. The project does not claim Windows support or exact branch coverage.

Crapper EDN `:entries` fields are `:name`, `:namespace`, `:complexity`, `:coverage`, and `:crap`. It exports named functions and methods; closures, arrows, and anonymous-class methods stay in JSON because their synthetic names cannot be joined by the original viewer. Missing coverage and CRAP use `nil`, unlike upstream's absent-report fallback of 0%; our specification requires this difference. Dryer EDN uses `:candidates` with score, `"php"` language, source spans, and node counts. JSON is the authoritative format for integrations. Infection/Mutator and UML Viewer are not implemented in v0.1.
