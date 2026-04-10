<?php

namespace Smartass\Yii2QueueWorker\tests;

use Smartass\Yii2QueueWorker\QueueWorkerBehavior;
use Yii;
use yii\base\InvalidCallException;
use yii\db\Connection;
use yii\db\Query;
use yii\queue\cli\Queue;
use yii\queue\cli\WorkerEvent;
use yii\queue\ExecEvent;

class QueueWorkerBehaviorTest extends TestCase
{
    public function testInit(): void
    {
        $behavior = $this->getBehavior();
        $this->assertInstanceOf(Connection::class, $behavior->db);
    }

    public function testEventsRegistered(): void
    {
        $behavior = $this->getBehavior();
        $events = $behavior->events();

        $this->assertArrayHasKey(Queue::EVENT_WORKER_START, $events);
        $this->assertArrayHasKey(Queue::EVENT_WORKER_LOOP, $events);
        $this->assertArrayHasKey(Queue::EVENT_WORKER_STOP, $events);
        $this->assertArrayHasKey(Queue::EVENT_BEFORE_EXEC, $events);
        $this->assertArrayHasKey(Queue::EVENT_AFTER_EXEC, $events);
    }

    public function testOnWorkerStartInsertsRecord(): void
    {
        $behavior = $this->getBehavior();

        $event = new WorkerEvent();
        $behavior->onWorkerStart($event);

        $this->assertNull($event->exitCode);
        $this->assertEquals(1, $this->countWorkerRecords());

        $worker = (new Query())->from('{{%queue_worker}}')->one(Yii::$app->db);
        $this->assertEquals(getmypid(), $worker['pid']);
        $this->assertEquals('queue', $worker['component']);
        $this->assertEquals(0, (int) $worker['stopped']);
        $this->assertNotNull($worker['started_at']);
    }

    public function testOnWorkerLoopUpdatesHeartbeat(): void
    {
        $behavior = $this->getBehavior();

        // Start the worker first
        $workerEvent = new WorkerEvent();
        $behavior->onWorkerStart($workerEvent);

        // Now loop
        $loopEvent = new WorkerEvent();
        $behavior->onWorkerLoop($loopEvent);

        $this->assertNull($loopEvent->exitCode);

        $worker = (new Query())->from('{{%queue_worker}}')->one(Yii::$app->db);
        $this->assertNotNull($worker['looped_at']);
    }

    public function testOnWorkerLoopStoppedExits(): void
    {
        $behavior = $this->getBehavior();

        // Start the worker
        $workerEvent = new WorkerEvent();
        $behavior->onWorkerStart($workerEvent);

        // Mark as stopped in DB
        Yii::$app->db->createCommand()->update('{{%queue_worker}}', [
            'stopped' => true,
        ])->execute();

        // Loop should set exit code
        $loopEvent = new WorkerEvent();
        $behavior->onWorkerLoop($loopEvent);

        $this->assertEquals(200, $loopEvent->exitCode);
    }

    public function testOnWorkerLoopDeletedRecordExits(): void
    {
        $behavior = $this->getBehavior();

        // Start the worker
        $workerEvent = new WorkerEvent();
        $behavior->onWorkerStart($workerEvent);

        // Delete the record
        Yii::$app->db->createCommand()->delete('{{%queue_worker}}')->execute();

        // Loop should set exit code
        $loopEvent = new WorkerEvent();
        $behavior->onWorkerLoop($loopEvent);

        $this->assertEquals(200, $loopEvent->exitCode);
    }

    public function testOnWorkerStopDeletesRecord(): void
    {
        $behavior = $this->getBehavior();

        // Start the worker
        $workerEvent = new WorkerEvent();
        $behavior->onWorkerStart($workerEvent);
        $this->assertEquals(1, $this->countWorkerRecords());

        // Mark as stopped so it doesn't try to restart
        Yii::$app->db->createCommand()->update('{{%queue_worker}}', [
            'stopped' => true,
        ])->execute();

        // Stop the worker
        $behavior->onWorkerStop();
        $this->assertEquals(0, $this->countWorkerRecords());
    }

    public function testOnWorkerStopNoRestartWhenManuallyStopped(): void
    {
        $behavior = $this->getBehavior();

        // Start the worker
        $workerEvent = new WorkerEvent();
        $behavior->onWorkerStart($workerEvent);

        // Mark as stopped
        Yii::$app->db->createCommand()->update('{{%queue_worker}}', [
            'stopped' => true,
        ])->execute();

        // Stop should NOT restart (record deleted, no new record created)
        $behavior->onWorkerStop();
        $this->assertEquals(0, $this->countWorkerRecords());
    }

    public function testOnBeforeExecSetsQueueId(): void
    {
        $behavior = $this->getBehavior();

        // Insert a record manually with our PID
        $workerId = $this->insertWorkerRecord(['pid' => getmypid()]);

        // Create ExecEvent
        $event = new ExecEvent();
        $event->id = '42';
        $event->sender = Yii::$app->queue;

        // Set the workerPid on the queue via cli\Queue parent class
        $ref = new \ReflectionClass(\yii\queue\cli\Queue::class);
        $prop = $ref->getProperty('_workerPid');
        $prop->setAccessible(true);
        $prop->setValue($event->sender, getmypid());

        $behavior->onBeforeExec($event);

        $worker = $this->getWorkerRecord($workerId);
        $this->assertEquals('42', $worker['queue_id']);
    }

    public function testOnAfterExecClearsQueueId(): void
    {
        $behavior = $this->getBehavior();

        // Insert a record with queue_id set
        $workerId = $this->insertWorkerRecord([
            'pid' => getmypid(),
            'queue_id' => 42,
        ]);

        $event = new ExecEvent();
        $event->id = '42';
        $event->sender = Yii::$app->queue;

        $ref = new \ReflectionClass(\yii\queue\cli\Queue::class);
        $prop = $ref->getProperty('_workerPid');
        $prop->setAccessible(true);
        $prop->setValue($event->sender, getmypid());

        $behavior->onAfterExec($event);

        $worker = $this->getWorkerRecord($workerId);
        $this->assertNull($worker['queue_id']);
    }

    public function testStopComponentSetsStoppedFlag(): void
    {
        $workerId = $this->insertWorkerRecord(['stopped' => false]);

        QueueWorkerBehavior::stopComponent('queue', null, Yii::$app->db, '{{%queue_worker}}');

        $worker = $this->getWorkerRecord($workerId);
        $this->assertEquals(1, (int) $worker['stopped']);
    }

    public function testStopComponentWithWorkerId(): void
    {
        $workerId1 = $this->insertWorkerRecord(['stopped' => false]);
        $workerId2 = $this->insertWorkerRecord(['stopped' => false]);

        QueueWorkerBehavior::stopComponent('queue', $workerId1, Yii::$app->db, '{{%queue_worker}}');

        $worker1 = $this->getWorkerRecord($workerId1);
        $worker2 = $this->getWorkerRecord($workerId2);

        $this->assertEquals(1, (int) $worker1['stopped']);
        $this->assertEquals(0, (int) $worker2['stopped']);
    }

    public function testStopComponentWithArrayOfIds(): void
    {
        $workerId1 = $this->insertWorkerRecord(['stopped' => false]);
        $workerId2 = $this->insertWorkerRecord(['stopped' => false]);
        $workerId3 = $this->insertWorkerRecord(['stopped' => false]);

        QueueWorkerBehavior::stopComponent(
            'queue',
            [$workerId1, $workerId2],
            Yii::$app->db,
            '{{%queue_worker}}'
        );

        $worker1 = $this->getWorkerRecord($workerId1);
        $worker2 = $this->getWorkerRecord($workerId2);
        $worker3 = $this->getWorkerRecord($workerId3);

        $this->assertEquals(1, (int) $worker1['stopped']);
        $this->assertEquals(1, (int) $worker2['stopped']);
        $this->assertEquals(0, (int) $worker3['stopped']);
    }

    public function testStopComponentWithInvalidDb(): void
    {
        $this->expectException(\TypeError::class);

        QueueWorkerBehavior::stopComponent('queue', null, new \stdClass());
    }

    public function testGetComponentIdReturnsCorrectId(): void
    {
        $behavior = $this->getBehavior();

        // Use reflection to test the protected method
        $ref = new \ReflectionMethod($behavior, 'getComponentId');
        $ref->setAccessible(true);

        $this->assertEquals('queue', $ref->invoke($behavior));
    }

    public function testGetComponentIdCachesResult(): void
    {
        $behavior = $this->getBehavior();

        $ref = new \ReflectionMethod($behavior, 'getComponentId');
        $ref->setAccessible(true);

        // Call twice — second call should use cache
        $result1 = $ref->invoke($behavior);
        $result2 = $ref->invoke($behavior);

        $this->assertEquals($result1, $result2);
        $this->assertEquals('queue', $result1);
    }

    public function testBehaviorStopMethod(): void
    {
        $workerId = $this->insertWorkerRecord(['stopped' => false]);

        $behavior = $this->getBehavior();
        $behavior->stop($workerId);

        $worker = $this->getWorkerRecord($workerId);
        $this->assertEquals(1, (int) $worker['stopped']);
    }

    public function testBehaviorStopWithArrayOfIds(): void
    {
        $workerId1 = $this->insertWorkerRecord(['stopped' => false]);
        $workerId2 = $this->insertWorkerRecord(['stopped' => false]);

        $behavior = $this->getBehavior();
        $behavior->stop([$workerId1, $workerId2]);

        $this->assertEquals(1, (int) $this->getWorkerRecord($workerId1)['stopped']);
        $this->assertEquals(1, (int) $this->getWorkerRecord($workerId2)['stopped']);
    }

    public function testBehaviorStopAllWorkers(): void
    {
        $this->insertWorkerRecord(['stopped' => false]);
        $this->insertWorkerRecord(['stopped' => false]);

        $behavior = $this->getBehavior();
        $behavior->stop();

        $this->assertEquals(2, $this->countWorkerRecords(['stopped' => true]));
    }

    public function testDefaultPropertyValues(): void
    {
        $behavior = $this->getBehavior();

        $this->assertEquals('{{%queue_worker}}', $behavior->table);
        $this->assertEquals('@app/../yii', $behavior->yiiPath);
        $this->assertEquals(3, $behavior->timeout);
        $this->assertEquals('--verbose --color', $behavior->params);
        $this->assertEquals('php', $behavior->phpPath);
        $this->assertEquals(3, $behavior->maxRestarts);
    }

    public function testSignalHandlerRegistration(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl extension is not available');
        }

        $behavior = $this->getBehavior();

        // Start should register signal handlers
        $event = new WorkerEvent();
        $behavior->onWorkerStart($event);

        // Verify signal handler was set (SIGTERM)
        $handler = pcntl_signal_get_handler(SIGTERM);
        $this->assertIsCallable($handler);
    }
}
