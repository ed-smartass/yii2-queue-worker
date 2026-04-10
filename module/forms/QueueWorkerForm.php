<?php

namespace Smartass\Yii2QueueWorker\module\forms;

use Smartass\Yii2QueueWorker\QueueWorkerBehavior;
use Yii;
use yii\base\Model;
use yii\db\Query;
use yii\helpers\Inflector;
use yii\queue\cli\Queue;

class QueueWorkerForm extends Model
{
    /**
     * @var int|null Desired total number of workers.
     */
    public int|null $total = 1;

    /**
     * @var string|null Queue component ID.
     */
    public string|null $component = '';

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        return [
            ['total', 'required'],
            ['total', 'integer', 'min' => 0],

            ['component', 'required'],
            ['component', 'string'],
            ['component', 'in', 'range' => static::getComponentOptions()],
        ];
    }

    /**
     * Returns a list of queue component IDs registered in the application.
     *
     * @return string[]
     */
    public static function getComponentOptions(): array
    {
        $options = [];

        // Check both loaded and unloaded components
        foreach (Yii::$app->getComponents(true) as $id => $definition) {
            if ($definition instanceof Queue) {
                // Already loaded instance
                $options[] = $id;
            } elseif (is_array($definition) && isset($definition['class'])) {
                if (is_a($definition['class'], Queue::class, true)) {
                    $options[] = $id;
                }
            } elseif (is_string($definition) && is_a($definition, Queue::class, true)) {
                $options[] = $id;
            }
        }

        return $options;
    }

    /**
     * Returns component options as ID => human-readable name pairs.
     *
     * @return array<string, string>
     */
    public static function getComponentOptionNames(): array
    {
        $options = static::getComponentOptions();

        return array_map(function (string $id): string {
            return Inflector::camel2id($id);
        }, array_combine($options, $options));
    }

    /**
     * Adjusts the number of running workers to match the desired total.
     */
    public function start(): bool
    {
        if (!$this->validate()) {
            return false;
        }

        $component = Yii::$app->get($this->component);

        /** @var QueueWorkerBehavior|null $behavior */
        $behavior = $component->getBehavior('worker');
        if ($behavior === null) {
            return false;
        }

        $count = (int) (new Query())
            ->from($behavior->table)
            ->andWhere(['component' => $this->component])
            ->andWhere(['stopped' => false])
            ->count('*', $behavior->db);

        if ($this->total >= $count) {
            // Need more workers
            for ($i = $count; $i < $this->total; $i++) {
                $behavior->start();
            }
        } else {
            // Need fewer workers — stop the excess
            $workerIds = (new Query())
                ->from($behavior->table)
                ->select('worker_id')
                ->andWhere(['component' => $this->component])
                ->andWhere(['stopped' => false])
                ->limit($count - $this->total)
                ->column($behavior->db);

            if (!empty($workerIds)) {
                $behavior->stop($workerIds);
            }
        }

        return true;
    }
}
