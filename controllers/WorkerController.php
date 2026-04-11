<?php

namespace Smartass\Yii2QueueWorker\controllers;

use Smartass\Yii2QueueWorker\QueueWorkerBehavior;
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

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $output = shell_exec('ps -p ' . escapeshellarg((string) $pid) . ' -o pid= 2>/dev/null');

        return $output !== null && trim($output) === (string) $pid;
    }

    /**
     * Restarts a worker by launching a new process for its component.
     *
     * Resolves the attached {@see QueueWorkerBehavior} explicitly rather than relying on
     * `method_exists($component, 'start')`, because Yii2 behavior methods are injected
     * via the `__call` magic method and are not visible to `method_exists()`.
     */
    protected function restartWorker(array $worker): void
    {
        $componentId = $worker['component'];

        try {
            if (!Yii::$app->has($componentId)) {
                Yii::warning("Component '{$componentId}' is not registered, skipping restart", Queue::class);
                return;
            }

            $component = Yii::$app->get($componentId);
            $behavior = $this->resolveWorkerBehavior($component);

            if ($behavior === null) {
                Yii::warning(
                    "Component '{$componentId}' has no QueueWorkerBehavior attached, skipping restart",
                    Queue::class
                );
                return;
            }

            $behavior->start();
            $this->stdout("Restarted dead worker for component '{$componentId}' (was PID {$worker['pid']})\n");
        } catch (\Throwable $th) {
            Yii::error("Failed to restart worker for component '{$componentId}': {$th->getMessage()}", Queue::class);
            $this->stderr("Failed to restart worker for component '{$componentId}': {$th->getMessage()}\n");
        }
    }

    /**
     * Finds the {@see QueueWorkerBehavior} attached to the given component, if any.
     *
     * Scans all attached behaviors rather than relying on a hard-coded behavior name,
     * so users are free to attach the behavior under any key.
     */
    protected function resolveWorkerBehavior(object $component): ?QueueWorkerBehavior
    {
        if (!method_exists($component, 'getBehaviors')) {
            return null;
        }

        foreach ($component->getBehaviors() as $behavior) {
            if ($behavior instanceof QueueWorkerBehavior) {
                return $behavior;
            }
        }

        return null;
    }
}
