<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%worker}}`.
 */
class m210828_183946_create_worker_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%queue_worker}}', [
            'worker_id' => $this->primaryKey(),
            'component' => $this->string(),
            'pid' => $this->integer(),
            'queue_id' => $this->integer(),
            'started_at' => $this->timestamp()->defaultValue(null),
            'looped_at' => $this->timestamp()->defaultValue(null)
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%queue_worker}}');
    }
}