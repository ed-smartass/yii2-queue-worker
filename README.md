Queue Worker Behavior
=====================
Adding ability to start and manage yii2-queue workers

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

1. Run

```
composer require ed-smartass/yii2-queue-worker
```


2. Apply migrations

```
php yii migrate --migrationPath=@vendor/ed-smartass/yii2-queue-worker/migrations
```
or add to console config and run migration
```php
return [
    // ...
    'controllerMap' => [
        // ...
        'migrate' => [
            'class' => 'yii\console\controllers\MigrateController',
            'migrationPath' => [
                '@console/migrations', // Default migration folder
                '@vendor/ed-smartass/yii2-queue-worker/migrations'
            ]
        ]
        // ...
    ]
    // ...
];
```


3. Add behavior to queue config

```php
return [
    // ...
    'components' => [
        // ...
        'queue' => [
            'class' => 'yii\queue\db\Queue',
            'mutex' => 'yii\mutex\MysqlMutex',
            // ...
            'as worker' => 'Smartass\Yii2QueueWorker\QueueWorkerBehavior'
        ]
        // ...
    ]
    // ...
];
```

4. Add module to your application (optional)

```php
return [
    // ...
    'modules' => [
        // ...
        'queue-worker' => [
            'class' => 'Smartass\Yii2QueueWorker\module\Module',
            // If you want change view files just copy it from `vendor/ed-smartass/yii2-queue-worker/module/views`
            // to `@app/views/queue-worke` and set:
            // 'viewPath' => '@app/views/queue-worker'
        ]
        // ...
    ]
    // ...
];
```


Usage
-----

All started workers you cant find at `queue_worker` table.

Manage workers (if you config module): `https:://your-site.com/queue-workers`.

To start worker
```
Yii::$app->queue->start($timeout = 3, $yiiPath = '@app/../yii', $params = '--verbose --color');
```
or
```
QueueWorkerBehavior::startComponent($component = 'queue', $timeout = 3, $yiiPath = '@app/../yii', $params = '--verbose --color');
```


To stop worker
```
Yii::$app->queue->stop($worker_id = null);
```
or
```
QueueWorkerBehavior::stopComponent($component = null, $worker_id = null, $db = 'db', $table = '{{%queue_worker}}');
```

If `worker_id` is `null` all workers will stop.
