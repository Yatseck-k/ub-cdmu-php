# ub-cdmu-php

[English version](README.md)

Статические проверки качества PHP-проектов в виде Composer-пакета для PHP 8.2+. Исходный код разбирается без загрузки и выполнения. Версия 0.1 вычисляет CRAP по сложности и готовому отчёту PHPUnit о покрытии строк в формате Clover, а также находит кандидатов на структурное дублирование. Python и Docker не требуются.

## Установка в PHP-проект

Пока пакет не опубликован, подключите эту рабочую копию как локальный Composer path repository из проекта, который нужно анализировать:

```sh
composer config repositories.ub-cdmu-php path /path/to/ub-cdmu-php
composer require --dev yatseck-k/ub-cdmu-php:@dev
vendor/bin/ub-php doctor
vendor/bin/ub-php check --format json
```

После публикации локальный репозиторий можно заменить обычной командой `composer require --dev yatseck-k/ub-cdmu-php`. В проекте нужен `.ub-mode.yaml` либо существующий каталог `src/` или `app/`. По умолчанию отчёты записываются в `.metrics/`. Git нужен только для `--changed`; готовый отчёт Clover необязателен.

Доступны команды `check`, `crap`, `dry` и `doctor`. Параметр `--changed` анализирует добавленные в индекс, изменённые и неотслеживаемые PHP-файлы в настроенных каталогах. Для более строгой проверки дублирования по правилам `ub-mode` используйте `dry --threshold 0.8`. Параметр `--format json` выводит тот же канонический результат, который записывается в `.metrics/`.

Для разработки в этой рабочей копии:

```sh
composer install
composer test
composer test-consumer
php bin/ub-php --root fixtures check --format json
```

Подробнее: [CLI](docs/ru/cli.md), [правила метрик](docs/ru/metrics.md), [архитектура](docs/ru/architecture.md) и [совместимость](docs/ru/compatibility.md). Infection и UML Viewer запланированы на следующие версии.

Необязательный скилл `ub-mode` поддерживается отдельно в `ub-mode-php` (в этой рабочей области — `../ub-mode-php`). Он работает и без этого CLI.
