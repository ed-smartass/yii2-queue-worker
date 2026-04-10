<?php

namespace Smartass\Yii2QueueWorker\tests;

use Smartass\Yii2QueueWorker\module\forms\QueueWorkerForm;
use Yii;

class QueueWorkerFormTest extends TestCase
{
    public function testRules(): void
    {
        $model = new QueueWorkerForm();
        $rules = $model->rules();

        $this->assertIsArray($rules);
        $this->assertNotEmpty($rules);
    }

    public function testValidationRequiresComponent(): void
    {
        $model = new QueueWorkerForm();
        $model->total = 1;
        $model->component = '';

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('component', $model->getErrors());
    }

    public function testValidationRequiresTotal(): void
    {
        $model = new QueueWorkerForm();
        $model->total = null;
        $model->component = 'queue';

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('total', $model->getErrors());
    }

    public function testValidationRejectsInvalidComponent(): void
    {
        $model = new QueueWorkerForm();
        $model->total = 1;
        $model->component = 'nonexistent-component';

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('component', $model->getErrors());
    }

    public function testValidationAcceptsValidComponent(): void
    {
        $model = new QueueWorkerForm();
        $model->total = 1;
        $model->component = 'queue';

        $this->assertTrue($model->validate());
    }

    public function testGetComponentOptionsReturnsQueueComponents(): void
    {
        $options = QueueWorkerForm::getComponentOptions();

        $this->assertIsArray($options);
        $this->assertContains('queue', $options);
    }

    public function testGetComponentOptionNamesReturnsFormattedNames(): void
    {
        $options = QueueWorkerForm::getComponentOptionNames();

        $this->assertIsArray($options);
        $this->assertArrayHasKey('queue', $options);
        $this->assertEquals('queue', $options['queue']);
    }

    public function testStartWithInvalidDataReturnsFalse(): void
    {
        $model = new QueueWorkerForm();
        $model->total = 1;
        $model->component = 'nonexistent';

        $this->assertFalse($model->start());
    }

    public function testStartStopsExcessWorkers(): void
    {
        // Insert 3 running workers
        $this->insertWorkerRecord(['component' => 'queue', 'stopped' => false]);
        $this->insertWorkerRecord(['component' => 'queue', 'stopped' => false]);
        $this->insertWorkerRecord(['component' => 'queue', 'stopped' => false]);

        $model = new QueueWorkerForm();
        $model->total = 1;
        $model->component = 'queue';

        $result = $model->start();
        $this->assertTrue($result);

        // 2 workers should be marked as stopped
        $this->assertEquals(2, $this->countWorkerRecords(['stopped' => true]));
        $this->assertGreaterThanOrEqual(1, $this->countWorkerRecords(['stopped' => false]));
    }

    public function testGetComponentOptionsDoesNotIncludeNonQueueComponents(): void
    {
        $options = QueueWorkerForm::getComponentOptions();

        // 'db' is not a Queue component
        $this->assertNotContains('db', $options);
    }
}
