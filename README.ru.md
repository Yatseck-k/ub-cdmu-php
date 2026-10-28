# ub-cdmu-php

[English version](README.md)

Статические проверки качества PHP-проектов в виде Composer-пакета для PHP 8.2+. Исходный код разбирается без загрузки и выполнения. `check` вычисляет CRAP по сложности и готовому отчёту PHPUnit о покрытии строк в формате Clover, а также находит кандидатов на структурное дублирование. Необязательные `mutate` (Infection) и `uml` (автономный просмотр) — отдельные команды. Python и Docker не требуются.

**Впервые здесь? Начните с [краткой инструкции](docs/GUIDE.ru.md).** Все параметры и контракты — в [руководстве](docs/README.ru.md).

Текстовый запуск показывает стадии в stderr и компактный итог PASS/WARN/FAIL с путями отчётов и дальнейшими действиями. `vendor/bin/ub-php check --verbose` раскрывает все записи и совпадения, `--progress never` отключает прогресс. При перенаправлении текстовые логи получают отдельные строки; JSON не пишет прогресс без `--progress always`. Unknown и кандидаты DRY дают WARN с кодом 0, а не подтверждение соблюдения CRAP-порога. Лимиты и коды описаны в [руководстве CLI](docs/README.ru.md#cli).

## Мутации и UML

```sh
vendor/bin/ub-php doctor --tools --format json
vendor/bin/ub-php mutate --changed --min-msi 80
vendor/bin/ub-php uml
# Откройте .metrics/uml.html в браузере.
```

Явная mutate запускает тесты проекта через необязательный установленный Infection **0.29.14**. Проверенный пример использует PHP 8.2, PHPUnit 11.5 и Xdebug coverage; для мутаций нужны POSIX/PCNTL. Автоматической установки нет. UML статический, работает автономно и не требует Infection. Обычный check выполняет только C+D. Настройки, статусы и артефакты описаны в [CLI](docs/README.ru.md#cli).

## Установка в PHP-проект

Пока пакет не опубликован, подключите эту рабочую копию как локальный Composer path repository из проекта, который нужно анализировать:

```sh
composer config repositories.ub-cdmu-php path /path/to/ub-cdmu-php
composer require --dev yatseck-k/ub-cdmu-php:@dev
vendor/bin/ub-php doctor
vendor/bin/ub-php check --format json
```

После публикации локальный репозиторий можно заменить обычной командой `composer require --dev yatseck-k/ub-cdmu-php`. В проекте нужен `.ub-mode.yaml` либо существующий каталог `src/` или `app/`. По умолчанию отчёты записываются в `.metrics/`. Git нужен только для `--changed`; готовый отчёт Clover необязателен.

Доступны команды `check`, `crap`, `dry`, `doctor`, `mutate` и `uml`. Параметр `--changed` (нужен Git-репозиторий) анализирует добавленные в индекс, изменённые и неотслеживаемые PHP-файлы в настроенных каталогах; `uml` его не принимает. Для более строгой проверки дублирования по правилам `ub-mode` используйте `dry --threshold 0.8`. Параметр `--format json` выводит тот же канонический результат, который записывается в `.metrics/`.

Для разработки в этой рабочей копии:

```sh
composer install
composer test
composer test-consumer
php bin/ub-php --root fixtures check --format json
```

Подробнее: [CLI](docs/README.ru.md#cli), [правила метрик](docs/README.ru.md#metrics), [архитектура](docs/README.ru.md#architecture) и [совместимость](docs/README.ru.md#requirements). Мутационное тестирование через необязательный Infection и автономный UML-просмотр доступны отдельными командами.

Необязательный скилл `ub-mode` поддерживается отдельно в `ub-mode-php` (в этой рабочей области — `../ub-mode-php`). Он работает и без этого CLI.
