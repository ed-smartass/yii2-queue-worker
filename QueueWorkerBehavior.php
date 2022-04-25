<?php

namespace Smartass\Yii2QueueWorkerBehavior;

use Yii;
use yii\base\Behavior;
use yii\base\InvalidCallException;
use yii\helpers\Inflector;
use yii\queue\cli\WorkerEvent;
use yii\queue\db\Queue;
use yii\queue\ExecEvent;

class QueueWorkerBehavior extends Behavior
{
    /**
     * @var string
     */
    public $table = '{{%queue_worker}}';

    /**
     * @var int|null
     */
    public $worker_id;

    /**
     * @var int|null
     */
    public $queue_id;

    /**
     * {@inheritdoc}
     */
    public function events()
    {
        return [
            Queue::EVENT_WORKER_START => [$this, 'onWorkerStart'],
            Queue::EVENT_WORKER_LOOP => [$this, 'onWorkerLoop'],
            Queue::EVENT_WORKER_STOP => [$this, 'onWorkerStop'],
            Queue::EVENT_BEFORE_EXEC => [$this, 'onBeforeExec'],
            Queue::EVENT_AFTER_EXEC => [$this, 'onAfterExec']
        ];
    }

    /**
     * @param WorkerEvent $event
     * @return void
     */
    public function onWorkerStart($event)
    {
        if ($this->worker_id) {
            $success = $this->owner->db->createCommand()->update($this->table, [
                'pid' => getmypid(),
                'component' => $this->getComponentId(),
                'queue_id' => null,
                'started_at' => date('Y-m-d H:i:s'),
                'looped_at' => null
            ], ['worker_id' => $this->worker_id])->execute();
        } else {
            $success = $this->owner->db->createCommand()->insert($this->table, [
                'pid' => getmypid(),
                'component' => $this->getComponentId(),
                'queue_id' => null,
                'started_at' => date('Y-m-d H:i:s'),
                'looped_at' => null
            ])->execute();
        }

        if (!$success) {
            $event->exitCode = 200;
            return;
        }

        if (!$this->worker_id) {
            $tableSchema = $this->owner->db->getTableSchema($this->table);
            $this->worker_id = $this->owner->db->getLastInsertID($tableSchema->sequenceName);
        }
    }

    /**
     * @param WorkerEvent $event
     * @return void
     */
    public function onWorkerLoop($event)
    {
        if (!$this->worker_id) {
            $event->exitCode = 200;
            return;
        }

        $success = $this->owner->db->createCommand()->update($this->table, [
            'looped_at' => date('Y-m-d H:i:s')
        ], [
            'worker_id' => $this->worker_id
        ])->execute();

        if (!$success) {
            $event->exitCode = 200;
        }
    }

    /**
     * @return void
     */
    public function onWorkerStop()
    {
        if ($this->worker_id) {
            $this->owner->db->createCommand()->delete($this->table, [
                'worker_id' => $this->worker_id
            ])->execute();
        }

        $this->worker_id = null;
    }

    /**
     * @param ExecEvent $event
     * @return void
     */
    public function onBeforeExec($event)
    {
        $this->queue_id = $event->id;

        if ($this->worker_id) {
            $this->owner->db->createCommand()->update($this->table, [
                'queue_id' => $event->id
            ], ['worker_id' => $this->worker_id])->execute();
        }
    }

    /**
     * @return void
     */
    public function onAfterExec()
    {
        $this->queue_id = null;

        if ($this->worker_id) {
            $this->owner->db->createCommand()->update($this->table, [
                'queue_id' => null
            ], ['worker_id' => $this->worker_id])->execute();
        }
    }

    /**
     * @return void
     */
    public static function startComponent($component = 'queue', $timeout = 3, $yiiPath = '@app/../yii', $params = '--verbose --color')
    {
        $command = 'php ' . Yii::getAlias($yiiPath) . ' ' . Inflector::camel2id($component) .'/listen ' . $timeout . ' ' . $params;

        if (substr(php_uname(), 0, 7) == 'Windows'){ 
            pclose(popen('start ' . $command, 'r'));  
        } else { 
            exec($command . ' > /dev/null &');   
        }
    }

    /**
     * @return void
     */
    public function start($timeout = 3, $yiiPath = '@app/../yii', $params = '--verbose --color')
    {
        if ($id = $this->getComponentId()) {
            static::startComponent($id, $timeout, $yiiPath, $params);
        }
    }

    /**
     * @return string|null
     */
    protected function getComponentId()
    {
        foreach(array_keys(Yii::$app->components) as $id) {
            if (Yii::$app->$id === $this->owner) {
                return $id;
            } 
        }

        throw new InvalidCallException('Component not found');
    }
}
