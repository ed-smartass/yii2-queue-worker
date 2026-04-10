<?php

namespace Smartass\Yii2QueueWorker\controllers;

use Yii;
use yii\console\Controller;
use yii\db\Connection;
use yii\db\Query;
use yii\di\Instance;
use yii\queue\cli\Queue;

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
    public function beforeAction($action): bool
    {
        if (parent::beforeAction($action)) {
            $this->db = Instance::ensure($this->db, Connection::class);
            return true;
        }

        return false;
    }

    /**
     * Checks all registered workers and restarts dead ones.
     * Should be run periodically via cron.
     */
    public function actionCheck(): void
    {
        $workerQuery = (new Query())->from($this->table);

        foreach ($workerQuery->each(100, $this->db) as $worker) {
            $pid = (int) $worker['pid'];
            $isRunning = $this->isProcessRunning($pid);

            if (!$isRunning) {
                // Process is dead — clean up the record
                $this->db->createCommand()->delete($this->table, [
                    'worker_id' => $worker['worker_id'],
                ])->execute();

                // Restart only if it wasn't manually stopped
                if (!$worker['stopped']) {
                    $this->restartWorker($worker);
                }
            }
        }
    }

    /**
     * Checks if a process with the given PID is still running.
     */
    protected function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
            return $output !== null && stripos($output, (string) $pid) !== false;
        }

        if (function_exists('posix_getpgid')) {
            return @posix_getpgid($pid) !== false;
        }

        // Fallback: check /proc filesystem
        return file_exists('/proc/' . $pid);
    }

    /**
     * Restarts a worker by launching a new process for its component.
     */
    protected function restartWorker(array $worker): void
    {
        try {
            $component = Yii::$app->get($worker['component']);

            if ($component instanceof Queue) {
                /** @var Queue|\Smartass\Yii2QueueWorker\QueueWorkerBehavior $component */
                $component->start();
                $this->stdout("Restarted dead worker for component '{$worker['component']}' (was PID {$worker['pid']})\n");
            }
        } catch (\Throwable $th) {
            Yii::error("Failed to restart worker for component '{$worker['component']}': {$th->getMessage()}", Queue::class);
            $this->stderr("Failed to restart worker for component '{$worker['component']}': {$th->getMessage()}\n");
        }
    }
}
