<?php

namespace Smartass\Yii2QueueWorkerBehavior\module\forms;

use Smartass\Yii2QueueWorkerBehavior\QueueWorkerBehavior;
use Yii;
use yii\base\Model;
use yii\helpers\Inflector;
use yii\queue\cli\Queue;

class WorkerQueueForm extends Model
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
                'min' => 1
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

        for ($i = 0; $i < $this->total; $i++) { 
            QueueWorkerBehavior::startComponent($this->component);
        }

        return true;
    }
}
