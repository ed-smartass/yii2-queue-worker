<?php

namespace Smartass\Yii2QueueWorker\module\forms;

use Yii;
use yii\base\Model;
use yii\db\Query;
use yii\helpers\Inflector;
use yii\queue\cli\Queue;

class QueueWorkerForm extends Model
{
    /**
     * @var int
     */
    public $total = 1;

    /**
     * @var string
     */
    public $component;

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            ['total', 'required'],
            ['total', 'integer',
                'min' => 0
            ],

            ['component', 'required'],
            ['component', 'string'],
            ['component', 'in',
                'range' => static::getComponentOptions()
            ],
        ];
    }

    /**
     * @return array
     */
    public static function getComponentOptions()
    {
        $options = [];

        foreach(array_keys(Yii::$app->components) as $id) {
            if (Yii::$app->$id instanceof Queue) {
                $options[] = $id;
            } 
        }

        return $options;
    }

    /**
     * @return array
     */
    public static function getComponentOptionNames()
    {
        $options = static::getComponentOptions();

        return array_map(function($id) {
            return Inflector::camel2id($id);
        }, array_combine($options, $options));
    }

    /**
     * @return boolean
     */
    public function start()
    {
        if (!$this->validate()) {
            return false;
        }

        $component = Yii::$app->get($this->component);

        $count = (int)(new Query())
            ->from($component->table)
            ->andWhere(['component' => $this->component])
            ->andWhere(['stoped' => false])
            ->count('*', $component->db);

        if ($this->total >= $count) {
            for ($i = $count; $i < $this->total; $i++) {
                $component->start();
            }
        } else {
            $worker_ids = (new Query())
                ->from($component->table)
                ->select('worker_id')
                ->andWhere(['component' => $this->component])
                ->andWhere(['stoped' => false])
                ->limit($count - $this->total)
                ->column();

            $component->stop($worker_ids);
        }

        return true;
    }
}
