# ub-mode-php

[English version](README.md)

Статические проверки качества для PHP-проектов. CLI на Python 3.12+ управляет анализатором AST на PHP 8.2+: исходный код проекта разбирается, но не загружается и не выполняется. Версия 0.1 вычисляет CRAP по сложности и готовому отчёту PHPUnit о покрытии строк в формате Clover, а также находит кандидатов на структурное дублирование.

## Быстрый старт

Соберите образ из этого репозитория и подключите PHP-проект для анализа:

```sh
docker build -t ub-mode-php:dev .
PHP_PROJECT=/path/to/php-project
docker run --rm --user "$(id -u):$(id -g)" -v "$PHP_PROJECT:/project" ub-mode-php:dev --root /project doctor
docker run --rm --user "$(id -u):$(id -g)" -v "$PHP_PROJECT:/project" ub-mode-php:dev --root /project check --format json
```

В анализируемом проекте нужен файл `.ub-mode.yaml` либо существующий каталог `src/` или `app/`. Docker записывает отчёты в `.metrics/` внутри подключённого проекта. Чтобы проверить встроенные примеры синтаксиса без подключения другого проекта, выполните `docker run --rm ub-mode-php:dev --root /app/fixtures check --format json`.

Для локальной разработки нужны Python 3.12+, PHP 8.2+ и Composer:

```sh
python -m pip install -e '.[dev]'
composer install --working-dir=php/analyzer
ub-php doctor
python -m pytest
composer test --working-dir=php/analyzer
```

Доступны команды `ub-php check`, `ub-php crap`, `ub-php dry` и `ub-php doctor`. Добавьте `--changed` к команде проверки, чтобы анализировать добавленные в индекс, изменённые и неотслеживаемые PHP-файлы в настроенных каталогах исходного кода. Для более строгой проверки дублирования по правилам `ub-mode` используйте `ub-php dry --threshold 0.8`. Параметр `--format json` выводит тот же канонический результат, который записывается в `.metrics/`.

После локальной установки CLI запускайте `ub-php check` прямо из корня PHP-проекта. Копировать этот репозиторий в проект не нужно.

Подробнее: [CLI](docs/ru/cli.md), [правила метрик](docs/ru/metrics.md), [архитектура](docs/ru/architecture.md) и [совместимость](docs/ru/compatibility.md). Infection и UML Viewer запланированы на следующие версии.

Руководство для участников: [AGENTS.ru.md](AGENTS.ru.md). Необязательный скилл `ub-mode` поддерживается отдельно в `ub-mode-php-skill` (в этой рабочей области — `../ub-mode-php-skill`). Он работает с тестами проекта и просмотром кода даже без этого CLI; при установленном CLI агент может использовать измеренные результаты CRAP и Dryer.
