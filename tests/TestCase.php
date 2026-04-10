<?php

namespace Smartass\Yii2QueueWorker\tests;

use Smartass\Yii2QueueWorker\QueueWorkerBehavior;
use Yii;
use yii\console\Application;
use yii\db\Connection;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mockApplication();
    }

    protected function tearDown(): void
    {
        $this->destroyApplication();
        parent::tearDown();
    }

    protected function mockApplication(array $config = []): Application
    {
        $defaultConfig = [
            'id' => 'test-app',
            'basePath' => __DIR__,
            'components' => [
                'db' => $this->getDbConfig(),
                'queue' => [
                    'class' => \yii\queue\file\Queue::class,
                    'path' => '@runtime/queue',
                    'as worker' => [
                        'class' => QueueWorkerBehavior::class,
                        'db' => 'db',
                        'table' => '{{%queue_worker}}',
                    ],
                ],
            ],
        ];

        $config = array_replace_recursive($defaultConfig, $config);
        $app = new Application($config);

        $this->createTables($app->db);

        return $app;
    }

    protected function destroyApplication(): void
    {
        if (Yii::$app) {
            Yii::$app = null;
        }
    }

    protected function getDbConfig(): array
    {
        return [
            'class' => Connection::class,
            'dsn' => 'sqlite::memory:',
        ];
    }

    protected function createTables(Connection $db): void
    {
        $db->createCommand()->createTable('{{%queue_worker}}', [
            'worker_id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'component' => 'VARCHAR(255)',
            'pid' => 'INTEGER',
            'stopped' => 'BOOLEAN DEFAULT 0',
            'queue_id' => 'INTEGER',
            'started_at' => 'TIMESTAMP NULL',
            'looped_at' => 'TIMESTAMP NULL',
        ])->execute();
    }

    /**
     * Returns the QueueWorkerBehavior attached to the queue component.
     */
    protected function getBehavior(): QueueWorkerBehavior
    {
        return Yii::$app->queue->getBehavior('worker');
    }

    /**
     * Inserts a worker record directly into the database for testing.
     */
    protected function insertWorkerRecord(array $data = []): int
    {
        $defaults = [
            'pid' => getmypid(),
            'component' => 'queue',
            'queue_id' => null,
            'stopped' => false,
            'started_at' => date('Y-m-d H:i:s'),
            'looped_at' => null,
        ];

        Yii::$app->db->createCommand()->insert(
            '{{%queue_worker}}',
            array_merge($defaults, $data)
        )->execute();

        return (int) Yii::$app->db->getLastInsertID();
    }

    /**
     * Gets a worker record from the database.
     */
    protected function getWorkerRecord(int $workerId): ?array
    {
        $result = (new \yii\db\Query())
            ->from('{{%queue_worker}}')
            ->andWhere(['worker_id' => $workerId])
            ->one(Yii::$app->db);

        return $result ?: null;
    }

    /**
     * Counts worker records in the database.
     */
    protected function countWorkerRecords(array $condition = []): int
    {
        $query = (new \yii\db\Query())->from('{{%queue_worker}}');
        if (!empty($condition)) {
            $query->andWhere($condition);
        }
        return (int) $query->count('*', Yii::$app->db);
    }
}
