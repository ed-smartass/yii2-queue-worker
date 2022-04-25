Queue Worker Behavior
=====================
Adding ability to start and manage yii2-queue workers

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

1. Run

```
composer require ed-smartass/yii2-queue-worker-behavior
```

2. Apply migrations
```
php yii migrate --migrationPath=@vendor/ed-smartass/yii2-queue-worker-behavior/migrations
```
or add to console config and run migration
```
return [
    // ...
    'controllerMap' => [
        // ...
        'migrate' => [
            'class' => 'yii\console\controllers\MigrateController',
            'migrationPath' => [
                '@console/migrations', // Default migration folder
                '@vendor/ed-smartass/yii2-queue-worker-behavior/migrations'
            ]
        ]
        // ...
    ]
    // ...
];
```

3. Add behavior to queue config
```
return [
    // ...
    'components' => [
        // ...
        'queue' => [
            'class' => 'yii\queue\db\Queue',
            'mutex' => 'yii\mutex\MysqlMutex',
            // ...
            'as worker' => 'Smartass\Yii2QueueWorkerBehavior\QueueWorkerBehavior'
        ]
        // ...
    ]
    // ...
];
```


Usage
-----

All started workers you cant find at `queue_worker` table.

To start worker

1) Old way
```
php yii queue/listen
```

2) In yours Yii2 code
```
Yii::$app->queue->start($timeout = 3, $yiiPath = '@app/../yii', $params = '--verbose --color');
```
or
```
QueueWorkerBehavior::startComponent($component = 'queue', $timeout = 3, $yiiPath = '@app/../yii', $params = '--verbose --color');
```
