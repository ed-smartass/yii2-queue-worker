<?php

namespace Smartass\Yii2QueueWorker\controllers;

use Yii;
use yii\console\Controller;
use yii\db\Connection;
use yii\db\Query;
use yii\di\Instance;

class WorkerController extends Controller
{
    /**
     * @var Connection|array|string
     */
    public $db = 'db';

    /**
     * @var string
     */
    public $table = '{{%queue_worker}}';

    /**
     * {@inheritdoc}
     */
    public function beforeAction($action)
    {
        if (parent::beforeAction($action)) {
            $this->db = Instance::ensure($this->db, Connection::class);
            return true;
        }

        return false;
    }

    /**
     * @return void
     */
    public function actionCheck()
    {
        $workerQuery = (new Query())
            ->from($this->table);

            foreach($workerQuery->each() as $worker) {
                if (substr(php_uname(), 0, 7) == 'Windows') {
                    $process = shell_exec('wmic process where (processid=' . $worker['pid'] . ') get parentprocessid');
                    $process = explode("\n", $process);
                    $process = intval($process[1]);
                    if (!$process) {
                        if ($component = Yii::$app->get($worker['component'])) {
                            $component->start();
                            $this->db->createCommand()->delete($this->table, [
                                'worker_id' => $worker['worker_id']
                            ])->execute();
                        }
                    }
                } else {
                    if (function_exists('posix_getpgid')) {
                        if (!$worker['stoped'] && @posix_getpgid($worker['pid']) === false) {
                            if ($component = Yii::$app->get($worker['component'])) {
                                $component->start();
                                $this->db->createCommand()->delete($this->table, [
                                    'worker_id' => $worker['worker_id']
                                ])->execute();
                            }
                        }
                    }
                }
            }
    }
}
