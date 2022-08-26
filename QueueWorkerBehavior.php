<?php

namespace Smartass\Yii2QueueWorker;

use Yii;
use yii\base\Behavior;
use yii\base\InvalidCallException;
use yii\db\Connection;
use yii\db\Query;
use yii\helpers\Inflector;
use yii\queue\cli\WorkerEvent;
use yii\queue\cli\Queue;
use yii\queue\ExecEvent;

class QueueWorkerBehavior extends Behavior
{
    /**
     * @var int|null
     */
    protected $worker_id;

    /**
     * @var string
     */
    public $table = '{{%queue_worker}}';

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
        $success = $this->owner->db->createCommand()->insert($this->table, [
            'pid' => getmypid(),
            'component' => $this->getComponentId(),
            'queue_id' => null,
            'started_at' => date('Y-m-d H:i:s'),
            'looped_at' => null
        ])->execute();

        if (!$success) {
            $event->exitCode = 200;
            return;
        }

        $this->worker_id = $this->owner->db->getLastInsertID();
    }

    /**
     * @param WorkerEvent $event
     * @return void
     */
    public function onWorkerLoop($event)
    {
        $exists = (new Query())
            ->from($this->table)
            ->andWhere(['worker_id' => $this->worker_id])
            ->exists();

        if (!$exists) {
            $event->exitCode = 200;
            return;
        }

        $this->owner->db->createCommand()->update($this->table, [
            'looped_at' => date('Y-m-d H:i:s')
        ], [
            'worker_id' => $this->worker_id
        ])->execute();
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
    }

    /**
     * @param ExecEvent $event
     * @return void
     */
    public function onBeforeExec($event)
    {
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
        if ($this->worker_id) {
            $this->owner->db->createCommand()->update($this->table, [
                'queue_id' => null
            ], ['worker_id' => $this->worker_id])->execute();
        }
    }

    /**
     * @param string $component
     * @param integer $timeout
     * @param string $yiiPath
     * @param string $params
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
     * @param string|null $component
     * @param int|null $worker_id
     * @param string|Connection $db
     * @return void
     */
    public static function stopComponent($component = null, $worker_id = null, $db = 'db', $table = '{{%queue_worker}}')
    {
        if (is_string($db)) {
            $db = Yii::$app->get($db);
        }

        if (!($db instanceof Connection)) {
            throw new InvalidCallException('db must be instanceof ' . Connection::class);
        }

        $condition = [];

        if ($component) {
            $condition['component'] = $component;
        }

        if ($worker_id) {
            $condition['worker_id'] = $worker_id;
        }

        $db->createCommand()->delete($table, $condition)->execute();
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
     * @param int|null $worker_id
     * @return void
     */
    public function stop($worker_id = null)
    {
        if ($id = $this->getComponentId()) {
            static::stopComponent($id, $worker_id, $this->owner->db, $this->table);
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
