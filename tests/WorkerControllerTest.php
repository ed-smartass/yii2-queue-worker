<?php

namespace Smartass\Yii2QueueWorker\tests;

use Smartass\Yii2QueueWorker\controllers\WorkerController;
use Smartass\Yii2QueueWorker\QueueWorkerBehavior;
use Yii;
use yii\db\Connection;

class WorkerControllerTest extends TestCase
{
    private function createController(): WorkerController
    {
        $controller = new WorkerController('worker', Yii::$app);
        $controller->db = Yii::$app->db;
        return $controller;
    }

    public function testBeforeActionResolvesDb(): void
    {
        $controller = new WorkerController('worker', Yii::$app);
        $controller->db = 'db';

        // Simulate action resolution
        $action = $controller->createAction('check');
        $result = $controller->beforeAction($action);

        $this->assertTrue($result);
        $this->assertInstanceOf(Connection::class, $controller->db);
    }

    public function testActionCheckCleansUpDeadWorkers(): void
    {
        // Insert a worker with a PID that doesn't exist
        $this->insertWorkerRecord([
            'pid' => 999999999, // Very unlikely to be a real PID
            'component' => 'queue',
            'stopped' => false,
        ]);

        $this->assertEquals(1, $this->countWorkerRecords());

        $controller = $this->createController();

        // Capture output
        ob_start();
        $controller->actionCheck();
        ob_end_clean();

        // Dead worker should be removed
        $this->assertEquals(0, $this->countWorkerRecords());
    }

    public function testActionCheckDoesNotRestartStoppedWorkers(): void
    {
        // Insert a stopped worker with a dead PID
        $this->insertWorkerRecord([
            'pid' => 999999999,
            'component' => 'queue',
            'stopped' => true,
        ]);

        $controller = $this->createController();

        ob_start();
        $controller->actionCheck();
        ob_end_clean();

        // Record should be cleaned up
        $this->assertEquals(0, $this->countWorkerRecords());
    }

    public function testActionCheckKeepsAliveWorkers(): void
    {
        // Insert a worker with OUR PID (which is definitely alive)
        $this->insertWorkerRecord([
            'pid' => getmypid(),
            'component' => 'queue',
            'stopped' => false,
        ]);

        $controller = $this->createController();

        ob_start();
        $controller->actionCheck();
        ob_end_clean();

        // Worker with our PID should still exist
        $this->assertEquals(1, $this->countWorkerRecords());
    }

    public function testIsProcessRunningWithInvalidPid(): void
    {
        $controller = $this->createController();

        $ref = new \ReflectionMethod($controller, 'isProcessRunning');
        $ref->setAccessible(true);

        $this->assertFalse($ref->invoke($controller, 0));
        $this->assertFalse($ref->invoke($controller, -1));
    }

    public function testIsProcessRunningWithCurrentPid(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX functions not available on Windows');
        }

        $controller = $this->createController();

        $ref = new \ReflectionMethod($controller, 'isProcessRunning');
        $ref->setAccessible(true);

        $this->assertTrue($ref->invoke($controller, getmypid()));
    }

    public function testIsProcessRunningWithDeadPid(): void
    {
        $controller = $this->createController();

        $ref = new \ReflectionMethod($controller, 'isProcessRunning');
        $ref->setAccessible(true);

        // Use a very high PID that doesn't exist
        $this->assertFalse($ref->invoke($controller, 999999999));
    }

    public function testResolveWorkerBehaviorFindsAttachedBehavior(): void
    {
        $controller = $this->createController();

        $ref = new \ReflectionMethod($controller, 'resolveWorkerBehavior');
        $ref->setAccessible(true);

        $behavior = $ref->invoke($controller, Yii::$app->queue);
        $this->assertInstanceOf(QueueWorkerBehavior::class, $behavior);
    }

    public function testResolveWorkerBehaviorReturnsNullWhenNoBehaviorAttached(): void
    {
        // Create a queue component WITHOUT the behavior
        Yii::$app->set('queueNoBehavior', [
            'class' => \yii\queue\file\Queue::class,
            'path' => '@runtime/queue-no-behavior',
        ]);

        $controller = $this->createController();

        $ref = new \ReflectionMethod($controller, 'resolveWorkerBehavior');
        $ref->setAccessible(true);

        $this->assertNull($ref->invoke($controller, Yii::$app->queueNoBehavior));
    }

    public function testActionCheckSkipsMissingComponents(): void
    {
        // Insert a worker pointing at a component that doesn't exist
        $this->insertWorkerRecord([
            'pid' => 999999999,
            'component' => 'nonexistent-queue',
            'stopped' => false,
        ]);

        $controller = $this->createController();

        ob_start();
        $controller->actionCheck();
        ob_end_clean();

        // Record should still be cleaned up even if the component is missing
        $this->assertEquals(0, $this->countWorkerRecords());
    }
}
