# Yii2 Queue Worker

[![CI](https://github.com/ed-smartass/yii2-queue-worker/actions/workflows/ci.yml/badge.svg)](https://github.com/ed-smartass/yii2-queue-worker/actions)
[![License](https://img.shields.io/github/license/ed-smartass/yii2-queue-worker)](LICENSE)

[Русская версия](README.ru.md)

A Yii2 extension for starting, stopping, and monitoring [yii2-queue](https://github.com/yiisoft/yii2-queue) workers directly from your application — via code or a built-in web UI.

Worker processes are tracked in a database table with PID, component name, heartbeat timestamp, and current job ID. The extension supports graceful shutdown via POSIX signals (Ctrl+C), in-process auto-restart with crash-loop protection, and a health-check console command for cron-based monitoring.

## Requirements

- PHP >= 8.1
- Yii2 >= 2.0.14
- yii2-queue >= 2.0
- `pcntl` extension (recommended, for signal handling)
- `posix` extension (recommended, for process checks)

## Installation

```bash
composer require ed-smartass/yii2-queue-worker
```

### Apply Migrations

```bash
php yii migrate --migrationPath=@vendor/ed-smartass/yii2-queue-worker/migrations
```

Or add to your console config:

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

### Add Behavior to Queue

```php
return [
    'components' => [
        'queue' => [
            'class' => 'yii\queue\db\Queue',
            // ...
            'as worker' => [
                'class' => 'Smartass\Yii2QueueWorker\QueueWorkerBehavior',
                // Optional configuration:
                // 'timeout' => 3,
                // 'phpPath' => '/usr/bin/php',
                // 'yiiPath' => '@app/../yii',
                // 'params' => '--verbose --color',
                // 'minRestartUptime' => 10,
            ],
        ],
    ],
];
```

### Add Web Module (Optional)

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

The web UI will be available at `/queue-worker`.

## Configuration

### Behavior Properties

| Property | Type | Default | Description |
|---|---|---|---|
| `table` | string | `{{%queue_worker}}` | Database table name |
| `db` | string/Connection | `db` | Database connection |
| `yiiPath` | string | `@app/../yii` | Path to Yii console entry script |
| `timeout` | int | `3` | Queue listen timeout (seconds) |
| `params` | string | `--verbose --color` | CLI arguments for queue/listen |
| `phpPath` | string | `php` | Path to PHP binary |
| `minRestartUptime` | int | `10` | Minimum uptime (seconds) before a crashed worker is auto-restarted |

## Usage

### Starting Workers

From code:
```php
// Start a worker using behavior
Yii::$app->queue->start();

// Start with static method
QueueWorkerBehavior::startComponent('queue', timeout: 3);
```

### Stopping Workers

```php
// Stop a specific worker
Yii::$app->queue->stop(workerId: 42);

// Stop multiple workers
Yii::$app->queue->stop([42, 43, 44]);

// Stop all workers for this component
Yii::$app->queue->stop();

// Stop using static method
QueueWorkerBehavior::stopComponent('queue');
```

When stopping, the extension:
1. Marks workers as `stopped` in the database
2. Sends `SIGTERM` to each worker process (if `posix` extension is available)
3. Workers exit gracefully after completing the current job

### Health Check (Cron)

The `WorkerController` checks if registered workers are still alive and restarts dead ones:

```bash
php yii worker/check
```

Add to console config:
```php
return [
    'controllerMap' => [
        'worker' => [
            'class' => 'Smartass\Yii2QueueWorker\controllers\WorkerController',
        ],
    ],
];
```

Recommended cron entry:
```
* * * * * php /path/to/yii worker/check
```

### Signal Handling

If the `pcntl` extension is installed, workers handle these signals:

- **SIGINT** (Ctrl+C) — marks the worker as stopped, exits after the current loop
- **SIGTERM** — same graceful shutdown behavior

On signal, the worker record is updated in the database and the process terminates cleanly — no zombie records left behind.

### Auto-Restart

If a worker exits unexpectedly (not via a manual stop or signal), the behavior launches a new worker process in place. To avoid crash loops, the behavior only restarts workers whose uptime was at least `minRestartUptime` seconds (default `10`). Workers that die sooner than that — and workers with a missing or unparseable `started_at` — are logged as a warning and **not** auto-restarted: the DB record is cleaned up and the worker must be brought back by an external supervisor (`systemd`, `supervisord`) or by re-issuing `start()` manually.

Note that the `worker/check` cron command is designed to recover workers whose process died **without** triggering `onWorkerStop()` (e.g. hard kill, segfault). It does not recover workers skipped by the crash-loop guard above, because those records are deleted as part of the normal stop event. Use an external supervisor if you need tight in-process crash-loop recovery.

This deliberately does not try to count restarts across processes: each spawned worker is a fresh PHP process with its own memory.

## Architecture

```
┌─────────────────────────────────────┐
│         Queue Component             │
│  (yii\queue\db\Queue, etc.)        │
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
│       queue_worker table            │
│  worker_id | pid | component |      │
│  stopped | queue_id | started_at |  │
│  looped_at                          │
└─────────────────────────────────────┘
```

**Event flow:**
1. `EVENT_WORKER_START` → registers PID and signal handlers
2. `EVENT_WORKER_LOOP` → dispatches signals, checks stop flag, updates heartbeat
3. `EVENT_BEFORE_EXEC` → records current job ID
4. `EVENT_AFTER_EXEC` → clears job ID
5. `EVENT_WORKER_STOP` → deletes record, optionally restarts

## Development

```bash
# Install dependencies
composer install

# Run tests
vendor/bin/phpunit

# Run static analysis
vendor/bin/phpstan analyse
```

## License

MIT License. See [LICENSE](LICENSE) for details.
