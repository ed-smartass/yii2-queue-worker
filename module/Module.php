<?php

namespace Smartass\Yii2QueueWorkerBehavior\module;

use yii\base\Module as BaseModule;
use yii\db\Connection;
use yii\di\Instance;

class Module extends BaseModule
{
    /**
     * @var string
     */
    public $table = '{{%queue_worker}}';

    /**
     * @var string
     */
    public $db = 'db';

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();

        $this->db = Instance::ensure($this->db, Connection::class);
    }
}