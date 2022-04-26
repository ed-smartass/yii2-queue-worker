<?php

namespace Smartass\Yii2QueueWorkerBehavior\module\controllers;

use Smartass\Yii2QueueWorkerBehavior\module\forms\WorkerQueueForm;
use Smartass\Yii2QueueWorkerBehavior\QueueWorkerBehavior;
use Yii;
use yii\data\ActiveDataProvider;
use yii\db\Query;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

class DefaultController extends Controller
{
    /**
     * @return mixed
     */
    public function actionIndex()
    {
        $model = new WorkerQueueForm();

        if ($model->load(Yii::$app->request->post()) && $model->start()) {
            return $this->redirect(['index']);
        }

        $dataProvider = new ActiveDataProvider([
            'db' => $this->module->db,
            'query' => (new Query())->from($this->module->table),
            'key' => 'worker_id'
        ]);

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'model' => $model,
            'componentOptions' => WorkerQueueForm::getComponentOptionNames()
        ]);
    }

    /**
     * @param int $id
     * @return mixed
     */
    public function actionDelete($id)
    {
        $worker = $this->findModel($id);
        QueueWorkerBehavior::stopComponent($worker['component'], $id, $this->module->db, $this->module->table);
        return $this->redirect(['index']);
    }

    /**
     * @return mixed
     */
    public function actionDeleteAll()
    {
        QueueWorkerBehavior::stopComponent(null, null, $this->module->db, $this->module->table);
        return $this->redirect(['index']);
    }

    /**
     * @param int $id
     * @return array 
     * @throws NotFoundHttpException
     */
    protected function findModel($id)
    {
        if (($model = (new Query())->from($this->module->table)->andWhere(['worker_id' => $id])->one($this->module->db)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException();
    }
}
