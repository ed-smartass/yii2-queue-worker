<?php

namespace Smartass\Yii2QueueWorker\tests;

use Smartass\Yii2QueueWorker\module\Module;
use Yii;
use yii\db\Connection;

class ModuleTest extends TestCase
{
    protected function mockApplication(array $config = []): \yii\console\Application
    {
        return parent::mockApplication([
            'modules' => [
                'queue-worker' => [
                    'class' => Module::class,
                ],
            ],
        ]);
    }

    public function testModuleInit(): void
    {
        $module = Yii::$app->getModule('queue-worker');

        $this->assertInstanceOf(Module::class, $module);
        $this->assertInstanceOf(Connection::class, $module->db);
    }

    public function testModuleDefaultTable(): void
    {
        $module = Yii::$app->getModule('queue-worker');

        $this->assertEquals('{{%queue_worker}}', $module->table);
    }

    public function testModuleCustomDb(): void
    {
        $this->destroyApplication();
        $this->mockApplication([
            'components' => [
                'db2' => [
                    'class' => Connection::class,
                    'dsn' => 'sqlite::memory:',
                ],
            ],
            'modules' => [
                'queue-worker' => [
                    'class' => Module::class,
                    'db' => 'db2',
                ],
            ],
        ]);

        $module = Yii::$app->getModule('queue-worker');
        $this->assertInstanceOf(Connection::class, $module->db);
    }
}
