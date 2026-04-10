<?php

use yii\db\Migration;

/**
 * Renames `stoped` column to `stopped` in `{{%queue_worker}}` table.
 */
class m260410_000000_rename_stoped_to_stopped extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->renameColumn('{{%queue_worker}}', 'stoped', 'stopped');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->renameColumn('{{%queue_worker}}', 'stopped', 'stoped');
    }
}
