<?php

namespace Smartass\Yii2QueueWorker;

use Yii;
use yii\base\Behavior;
use yii\base\InvalidCallException;
use yii\db\Connection;
use yii\db\Query;
use yii\di\Instance;
use yii\helpers\Inflector;
use yii\queue\cli\Queue;
use yii\queue\cli\WorkerEvent;
use yii\queue\ExecEvent;

class QueueWorkerBehavior extends Behavior
{
    /**
     * @var int|null Current worker ID in the database.
     */
    protected ?int $worker_id = null;

    /**
     * @var string Database table name for storing worker records.
     */
    public string $table = '{{%queue_worker}}';

    /**
     * @var string|Connection Database connection component ID or instance.
     */
    public string|Connection $db = 'db';

    /**
     * @var string Path to the Yii console entry script.
     */
    public string $yiiPath = '@app/../yii';

    /**
     * @var int Queue listen timeout in seconds.
     */
    public int $timeout = 3;

    /**
     * @var string Additional CLI parameters for the queue listen command.
     */
    public string $params = '--verbose --color';

    /**
     * @var string Path to the PHP binary.
     */
    public string $phpPath = 'php';

    /**
     * @var int Maximum number of automatic restarts before giving up.
     */
    public int $maxRestarts = 3;

    /**
     * @var bool Whether the worker should stop (set by signal handler).
     */
    protected bool $shouldStop = false;

    /**
     * @var int Number of restarts performed for this worker instance.
     */
    protected int $restartCount = 0;

    /**
     * @var string|null Cached component ID to avoid repeated lookups.
     */
    protected ?string $cachedComponentId = null;

    /**
     * {@inheritdoc}
     */
    public function init(): void
    {
        parent::init();

        $this->db = Instance::ensure($this->db, Connection::class);
    }

    /**
     * {@inheritdoc}
     */
    public function events(): array
    {
        return [
            Queue::EVENT_WORKER_START => [$this, 'onWorkerStart'],
            Queue::EVENT_WORKER_LOOP => [$this, 'onWorkerLoop'],
            Queue::EVENT_WORKER_STOP => [$this, 'onWorkerStop'],
            Queue::EVENT_BEFORE_EXEC => [$this, 'onBeforeExec'],
            Queue::EVENT_AFTER_EXEC => [$this, 'onAfterExec'],
        ];
    }

    /**
     * Handles worker start: registers in DB and sets up signal handlers.
     */
    public function onWorkerStart(WorkerEvent $event): void
    {
        $this->registerSignalHandlers();

        $pid = getmypid();
        if ($pid === false) {
            Yii::error('Failed to get current process ID', Queue::class);
            $event->exitCode = 200;
            return;
        }

        $success = $this->db->createCommand()->insert($this->table, [
            'pid' => $pid,
            'component' => $this->getComponentId(),
            'queue_id' => null,
            'stopped' => false,
            'started_at' => date('Y-m-d H:i:s'),
            'looped_at' => null,
        ])->execute();

        if (!$success) {
            $event->exitCode = 200;
            return;
        }

        $this->worker_id = (int) $this->db->getLastInsertID();
        $this->restartCount = 0;
    }

    /**
     * Handles worker loop: checks if worker should stop, updates heartbeat.
     */
    public function onWorkerLoop(WorkerEvent $event): void
    {
        try {
            $this->dispatchSignals();

            if ($this->shouldStop) {
                $event->exitCode = 200;
                return;
            }

            if ($this->worker_id) {
                $worker = (new Query())
                    ->from($this->table)
                    ->andWhere(['worker_id' => $this->worker_id])
                    ->one($this->db);

                if (!$worker || $worker['stopped']) {
                    $event->exitCode = 200;
                } else {
                    $this->db->createCommand()->update($this->table, [
                        'looped_at' => date('Y-m-d H:i:s'),
                    ], [
                        'worker_id' => $this->worker_id,
                    ])->execute();
                }
            }
        } catch (\Throwable $th) {
            Yii::error($th, Queue::class);
        }
    }

    /**
     * Handles worker stop: cleans up DB record and optionally restarts.
     */
    public function onWorkerStop(): void
    {
        try {
            if (!$this->worker_id) {
                return;
            }

            $worker = (new Query())
                ->from($this->table)
                ->andWhere(['worker_id' => $this->worker_id])
                ->one($this->db);

            if (!$worker) {
                return;
            }

            $this->db->createCommand()->delete($this->table, [
                'worker_id' => $this->worker_id,
            ])->execute();

            // Auto-restart only if not manually stopped and within restart limits
            if (!$worker['stopped'] && !$this->shouldStop) {
                if ($this->restartCount < $this->maxRestarts) {
                    $this->restartCount++;
                    $delay = min(2 ** $this->restartCount, 30);
                    Yii::info("Worker restarting (attempt {$this->restartCount}/{$this->maxRestarts}) after {$delay}s delay", Queue::class);
                    sleep($delay);
                    $this->start();
                } else {
                    Yii::warning("Worker exceeded max restarts ({$this->maxRestarts}), not restarting", Queue::class);
                }
            }
        } catch (\Throwable $th) {
            Yii::error($th, Queue::class);
        }
    }

    /**
     * Tracks the currently executing job in the worker record.
     */
    public function onBeforeExec(ExecEvent $event): void
    {
        try {
            if ($event->sender && $event->sender->workerPid) {
                $this->db->createCommand()->update($this->table, [
                    'queue_id' => $event->id,
                ], ['pid' => $event->sender->workerPid])->execute();
            }
        } catch (\Throwable $th) {
            Yii::error($th, Queue::class);
        }
    }

    /**
     * Clears the job reference after execution completes.
     */
    public function onAfterExec(ExecEvent $event): void
    {
        try {
            if ($event->sender && $event->sender->workerPid) {
                $this->db->createCommand()->update($this->table, [
                    'queue_id' => null,
                ], ['pid' => $event->sender->workerPid])->execute();
            }
        } catch (\Throwable $th) {
            Yii::error($th, Queue::class);
        }
    }

    /**
     * Starts a new worker process for the given queue component.
     *
     * @param string $component Queue component ID
     * @param int $timeout Listen timeout in seconds
     * @param string $yiiPath Path to Yii console entry script
     * @param string $params Additional CLI parameters
     * @param string $phpPath Path to PHP binary
     */
    public static function startComponent(
        string $component = 'queue',
        int $timeout = 3,
        string $yiiPath = '@app/../yii',
        string $params = '--verbose --color',
        string $phpPath = 'php',
    ): void {
        $yiiRealPath = Yii::getAlias($yiiPath);
        $queueCommand = Inflector::camel2id($component) . '/listen';

        $command = escapeshellarg($phpPath)
            . ' ' . escapeshellarg($yiiRealPath)
            . ' ' . escapeshellarg($queueCommand)
            . ' ' . (int) $timeout;

        if ($params !== '') {
            $command .= ' ' . $params;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B ' . $command, 'r'));
        } else {
            exec($command . ' > /dev/null 2>&1 &');
        }
    }

    /**
     * Marks workers as stopped in the database and optionally sends SIGTERM.
     *
     * @param string|null $component Queue component ID filter
     * @param int|int[]|null $workerIds Worker ID(s) to stop
     * @param string|Connection $db Database connection
     * @param string $table Worker table name
     */
    public static function stopComponent(
        ?string $component = null,
        int|array|null $workerIds = null,
        string|Connection $db = 'db',
        string $table = '{{%queue_worker}}',
    ): void {
        if (is_string($db)) {
            $db = Yii::$app->get($db);
        }

        if (!($db instanceof Connection)) {
            throw new InvalidCallException('db must be instanceof ' . Connection::class);
        }

        $condition = [];

        if ($component !== null) {
            $condition['component'] = $component;
        }

        if ($workerIds !== null) {
            $condition['worker_id'] = $workerIds;
        }

        // Fetch PIDs before marking as stopped (for SIGTERM)
        $pids = [];
        if (function_exists('posix_kill')) {
            $query = (new Query())
                ->select('pid')
                ->from($table)
                ->andWhere($condition)
                ->andWhere(['stopped' => false]);
            $pids = $query->column($db);
        }

        $db->createCommand()->update($table, ['stopped' => true], $condition)->execute();

        // Send SIGTERM for faster shutdown
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                posix_kill($pid, SIGTERM);
            }
        }
    }

    /**
     * Starts a new worker process using this behavior's configuration.
     */
    public function start(): void
    {
        if ($id = $this->getComponentId()) {
            static::startComponent($id, $this->timeout, $this->yiiPath, $this->params, $this->phpPath);
        }
    }

    /**
     * Stops worker(s) for this behavior's component.
     *
     * @param int|int[]|null $workerIds Worker ID(s) to stop, or null for all
     */
    public function stop(int|array|null $workerIds = null): void
    {
        if ($id = $this->getComponentId()) {
            static::stopComponent($id, $workerIds, $this->db, $this->table);
        }
    }

    /**
     * Returns the Yii2 component ID that owns this behavior.
     *
     * @throws InvalidCallException if the owner component is not found
     */
    protected function getComponentId(): string
    {
        if ($this->cachedComponentId !== null) {
            return $this->cachedComponentId;
        }

        foreach (array_keys(Yii::$app->getComponents(true)) as $id) {
            if (Yii::$app->get($id, false) === $this->owner) {
                $this->cachedComponentId = $id;
                return $id;
            }
        }

        throw new InvalidCallException('Component not found');
    }

    /**
     * Registers POSIX signal handlers for graceful shutdown.
     */
    protected function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signal): void {
            Yii::info("Received signal {$signal}, initiating graceful shutdown", Queue::class);
            $this->shouldStop = true;

            if ($this->worker_id) {
                try {
                    $this->db->createCommand()->update($this->table, [
                        'stopped' => true,
                    ], [
                        'worker_id' => $this->worker_id,
                    ])->execute();
                } catch (\Throwable $th) {
                    Yii::error($th, Queue::class);
                }
            }
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    /**
     * Dispatches pending signals (no-op if pcntl is not available).
     */
    protected function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}
