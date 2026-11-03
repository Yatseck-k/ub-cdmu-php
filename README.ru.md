# ub-cdmu-php

**Инструменты анализа качества PHP-кода — метрики CRAP, поиск дублирования, мутационное тестирование и UML.**

[English](README.md) · [Быстрый старт](docs/GUIDE.ru.md) · [Полное руководство](docs/README.ru.md) · [Лицензия](LICENSE)

![PHP](https://img.shields.io/badge/PHP-8.4.27%2B%20%7C%208.5.12%2B-777BB4?logo=php&logoColor=white) ![Composer](https://img.shields.io/badge/Composer-package-885630?logo=composer&logoColor=white) ![License](https://img.shields.io/badge/License-MIT-blue)

## Назначение

`ub-cdmu-php` — самостоятельный Composer-пакет для проверки качества PHP-кода. Основные команды **разбирают исходный код без его загрузки и выполнения**, оценивают сложность с учётом готового отчёта PHPUnit Clover и находят потенциальное структурное дублирование. Отдельно доступны мутационное тестирование через Infection и автономная UML-визуализация. **Python и Docker не требуются.**

## Возможности

| Команда | Назначение |
| --- | --- |
| `check` | Проверка CRAP и DRY за один запуск |
| `crap` | Метрики сложности с учётом доступного покрытия |
| `dry` | Поиск кандидатов на структурное дублирование |
| `doctor` | Диагностика конфигурации и дополнительных инструментов |
| `mutate` | Отдельный запуск Infection для мутационного анализа |
| `uml` | Генерация автономного HTML-представления структуры кода |

**Результаты требуют корректной интерпретации:** отсутствие покрытия означает неизвестный результат, а не успешное прохождение CRAP. Кандидаты на дублирование требуют ревью. По умолчанию `check` выполняет только CRAP и DRY.

## Требования

- PHP **8.4.27+ (до 8.5)** или **8.5.12+ (до 9.0)**.
- Composer и расширения DOM, JSON, mbstring, PCNTL и POSIX.
- Необязательно: готовый отчёт PHPUnit Clover, Git для `--changed`, совместимый установленный Infection для `mutate`.

## Быстрый старт

Пока пакет не опубликован, добавьте локальную копию как Composer path repository **в анализируемом PHP-проекте**:

```bash
composer config repositories.ub-cdmu-php path /path/to/ub-cdmu-php
composer require --dev yatseck-k/ub-cdmu-php:@dev
vendor/bin/ub-php doctor
vendor/bin/ub-php check
```

В проекте должен быть `.ub-mode.yaml` либо каталог `src/` или `app/`. По умолчанию отчёты записываются в `.metrics/`.

### Примеры запуска

```bash
vendor/bin/ub-php check --format json
vendor/bin/ub-php check --changed
vendor/bin/ub-php dry --threshold 0.8
vendor/bin/ub-php doctor --tools --format json

# Необязательно: запускает тесты проекта через установленный Infection
vendor/bin/ub-php mutate --changed --min-msi 80

# Создаёт .metrics/uml.html — откройте файл в браузере
vendor/bin/ub-php uml
```

Для `--changed` требуется Git; `uml` этот параметр не поддерживает. `--verbose` показывает подробности, `--progress never` отключает прогресс. Дополнительные инструменты не устанавливаются автоматически.

## Документация

- [Быстрый старт](docs/GUIDE.ru.md) · [English](docs/GUIDE.md)
- [CLI, метрики, статусы, коды завершения и архитектура](docs/README.ru.md) · [English](docs/README.md)
- [Пример конфигурации](.ub-mode.yaml)

## Разработка

```bash
composer install
composer test
composer test-consumer
php bin/ub-php --root fixtures check --format json
```

## Связанные проекты

- [ub-mode-php](https://github.com/Yatseck-k/ub-mode-php) — независимый скилл для агентов, который может использовать этот CLI.
- [commit-rules](https://github.com/Yatseck-k/commit-rules) — независимый скилл Conventional Commits.

## Лицензия

[MIT](LICENSE).
