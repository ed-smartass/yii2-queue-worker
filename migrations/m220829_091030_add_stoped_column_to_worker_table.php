<?php

use yii\db\Migration;


class m220829_091030_add_stoped_column_to_worker_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%queue_worker}}', 'stoped', $this->boolean()->defaultValue(false)->after('pid'));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%queue_worker}}', 'stoped');
    }
}
