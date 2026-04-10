# Yii2 Queue Worker

[![CI](https://github.com/ed-smartass/yii2-queue-worker/actions/workflows/ci.yml/badge.svg)](https://github.com/ed-smartass/yii2-queue-worker/actions)
[![License](https://img.shields.io/github/license/ed-smartass/yii2-queue-worker)](LICENSE)

[English version](README.md)

Расширение для Yii2, которое позволяет запускать, останавливать и мониторить воркеры [yii2-queue](https://github.com/yiisoft/yii2-queue) прямо из приложения — через код или встроенный веб-интерфейс.

Процессы воркеров отслеживаются в таблице базы данных: PID, имя компонента, таймстемп heartbeat, текущий ID задачи. Расширение поддерживает graceful shutdown через POSIX-сигналы (Ctrl+C), автоматический перезапуск с экспоненциальным бэкоффом и консольную команду проверки здоровья для мониторинга через cron.

## Требования

- PHP >= 8.0
- Yii2 >= 2.0.14
- yii2-queue >= 2.0
- Расширение `pcntl` (рекомендуется, для обработки сигналов)
- Расширение `posix` (рекомендуется, для проверки процессов)

## Установка

```bash
composer require ed-smartass/yii2-queue-worker
```

### Применение миграций

```bash
php yii migrate --migrationPath=@vendor/ed-smartass/yii2-queue-worker/migrations
```

Или добавьте в конфигурацию консольного приложения:

```php
return [
    'controllerMap' => [
        'migrate' => [
            'class' => 'yii\console\controllers\MigrateController',
            'migrationPath' => [
                '@console/migrations',
                '@vendor/ed-smartass/yii2-queue-worker/migrations',
            ],
        ],
    ],
];
```

### Добавление поведения к очереди

```php
return [
    'components' => [
        'queue' => [
            'class' => 'yii\queue\db\Queue',
            // ...
            'as worker' => [
                'class' => 'Smartass\Yii2QueueWorker\QueueWorkerBehavior',
                // Опциональная настройка:
                // 'timeout' => 3,
                // 'phpPath' => '/usr/bin/php',
                // 'yiiPath' => '@app/../yii',
                // 'params' => '--verbose --color',
                // 'maxRestarts' => 3,
            ],
        ],
    ],
];
```

### Добавление веб-модуля (опционально)

```php
return [
    'modules' => [
        'queue-worker' => [
            'class' => 'Smartass\Yii2QueueWorker\module\Module',
            // 'db' => 'db',
            // 'table' => '{{%queue_worker}}',
        ],
    ],
];
```

Веб-интерфейс будет доступен по адресу `/queue-worker`.

## Конфигурация

### Свойства поведения

| Свойство | Тип | По умолчанию | Описание |
|---|---|---|---|
| `table` | string | `{{%queue_worker}}` | Имя таблицы в БД |
| `db` | string/Connection | `db` | Подключение к БД |
| `yiiPath` | string | `@app/../yii` | Путь к консольному скрипту Yii |
| `timeout` | int | `3` | Таймаут прослушивания очереди (секунды) |
| `params` | string | `--verbose --color` | CLI-аргументы для queue/listen |
| `phpPath` | string | `php` | Путь к PHP |
| `maxRestarts` | int | `3` | Максимум автоматических перезапусков |

## Использование

### Запуск воркеров

Из кода:
```php
// Запуск через поведение
Yii::$app->queue->start();

// Запуск статическим методом
QueueWorkerBehavior::startComponent('queue', timeout: 3);
```

### Остановка воркеров

```php
// Остановить конкретного воркера
Yii::$app->queue->stop(workerId: 42);

// Остановить несколько воркеров
Yii::$app->queue->stop([42, 43, 44]);

// Остановить все воркеры компонента
Yii::$app->queue->stop();

// Остановить статическим методом
QueueWorkerBehavior::stopComponent('queue');
```

При остановке расширение:
1. Помечает воркеры как `stopped` в базе данных
2. Отправляет `SIGTERM` каждому процессу (если доступно расширение `posix`)
3. Воркеры завершаются после выполнения текущей задачи

### Проверка здоровья (Cron)

`WorkerController` проверяет, живы ли зарегистрированные воркеры, и перезапускает упавшие:

```bash
php yii worker/check
```

Добавьте в конфигурацию консольного приложения:
```php
return [
    'controllerMap' => [
        'worker' => [
            'class' => 'Smartass\Yii2QueueWorker\controllers\WorkerController',
        ],
    ],
];
```

Рекомендуемая запись в cron:
```
* * * * * php /path/to/yii worker/check
```

### Обработка сигналов

Если установлено расширение `pcntl`, воркеры обрабатывают следующие сигналы:

- **SIGINT** (Ctrl+C) — помечает воркер как остановленный, завершается после текущего цикла
- **SIGTERM** — аналогичное graceful завершение

При получении сигнала запись воркера обновляется в БД и процесс завершается корректно — без зомби-записей.

### Автоматический перезапуск

Если воркер неожиданно останавливается (не вручную), он автоматически перезапускается с экспоненциальным бэкоффом:

- Попытка 1: задержка 2 секунды
- Попытка 2: задержка 4 секунды
- Попытка 3: задержка 8 секунд

После `maxRestarts` (по умолчанию 3) последовательных неудач воркер останавливается окончательно. Успешная итерация цикла сбрасывает счётчик.

## Архитектура

```
┌─────────────────────────────────────┐
│         Queue Component             │
│  (yii\queue\db\Queue и т.д.)       │
│                                     │
│  ┌─────────────────────────────┐    │
│  │   QueueWorkerBehavior       │    │
│  │   - onWorkerStart()         │    │
│  │   - onWorkerLoop()          │    │
│  │   - onWorkerStop()          │    │
│  │   - onBeforeExec()          │    │
│  │   - onAfterExec()           │    │
│  └──────────┬──────────────────┘    │
└─────────────┼───────────────────────┘
              │
              ▼
┌─────────────────────────────────────┐
│       Таблица queue_worker          │
│  worker_id | pid | component |      │
│  stopped | queue_id | started_at |  │
│  looped_at                          │
└─────────────────────────────────────┘
```

**Поток событий:**
1. `EVENT_WORKER_START` → регистрация PID и обработчиков сигналов
2. `EVENT_WORKER_LOOP` → диспатч сигналов, проверка флага остановки, обновление heartbeat
3. `EVENT_BEFORE_EXEC` → запись текущего ID задачи
4. `EVENT_AFTER_EXEC` → очистка ID задачи
5. `EVENT_WORKER_STOP` → удаление записи, опциональный перезапуск

## Разработка

```bash
# Установка зависимостей
composer install

# Запуск тестов
vendor/bin/phpunit

# Статический анализ
vendor/bin/phpstan analyse
```

## Лицензия

MIT License. Подробности в файле [LICENSE](LICENSE).
