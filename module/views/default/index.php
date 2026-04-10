<?php

/** @var \yii\web\View $this */

use yii\widgets\ActiveForm;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\widgets\Pjax;

/** @var \yii\data\ActiveDataProvider $dataProvider */
/** @var \Smartass\Yii2QueueWorker\module\forms\QueueWorkerForm $model */
/** @var array $componentOptions */

$this->title = 'Queue Workers';
$this->params['breadcrumbs'][] = $this->title;

?>

<div class="queue-worker-index">
    <?php $form = ActiveForm::begin(); ?>
    <div class="card mb-4">
        <h5 class="card-header">
            Add workers
        </h5>
        <div class="card-body">
            <?= $form->field($model, 'total')->textInput(['type' => 'number', 'min' => 1, 'step' => 1]) ?>
            <?= $form->field($model, 'component')->dropdownList($componentOptions) ?>
        </div>
        <div class="card-footer text-muted">
            <div class="d-flex align-items-center">
                <div class="mr-auto">
                    <?= Html::submitButton('Create', ['class' => 'btn btn-success']) ?>
                </div>
                <div>
                    <?= Html::a('Delete all', ['delete-all'], [
                        'class' => 'btn btn-danger',
                        'data' => [
                            'confirm' => 'Are you sure?',
                            'method' => 'post',
                        ]
                    ]) ?>
                </div>
            </div>
        </div>
    </div>
    <?php ActiveForm::end(); ?>

    <?php $pjax = Pjax::begin(); ?>
    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'columns' => [
            [
                'class' => 'yii\grid\SerialColumn',
                'headerOptions' => [
                    'style' => 'width: 1%',
                    'class' => 'text-nowrap text-right'
                ],
                'contentOptions' => [
                    'style' => 'width: 1%',
                    'class' => 'text-nowrap text-right'
                ]
            ],
            [
                'attribute' => 'pid'
            ],
            [
                'attribute' => 'component'
            ],
            [
                'attribute' => 'queue_id',
                'label' => 'Current Job',
            ],
            [
                'attribute' => 'stopped',
                'label' => 'Status',
                'format' => 'raw',
                'value' => function ($model) {
                    if ($model['stopped']) {
                        return '<span class="badge badge-warning">Stopping</span>';
                    }
                    return '<span class="badge badge-success">Running</span>';
                },
                'headerOptions' => [
                    'style' => 'width: 1%',
                    'class' => 'text-nowrap'
                ],
                'contentOptions' => [
                    'style' => 'width: 1%',
                    'class' => 'text-nowrap'
                ]
            ],
            [
                'attribute' => 'started_at',
                'format' => 'relativeTime',
                'headerOptions' => [
                    'style' => 'width: 1%',
                    'class' => 'text-nowrap'
                ],
                'contentOptions' => [
                    'style' => 'width: 1%',
                    'class' => 'text-nowrap'
                ]
            ],
            [
                'attribute' => 'looped_at',
                'label' => 'Last Heartbeat',
                'format' => 'relativeTime',
                'headerOptions' => [
                    'style' => 'width: 1%',
                    'class' => 'text-nowrap'
                ],
                'contentOptions' => [
                    'style' => 'width: 1%',
                    'class' => 'text-nowrap'
                ]
            ],
            [
                'class' => 'yii\grid\ActionColumn',
                'template' => '{delete}',
                'contentOptions' => [
                    'style' => 'width: 1%',
                    'class' => 'text-nowrap'
                ]
            ]
        ]
    ]) ?>
    <?php Pjax::end(); ?>
</div>

<?php
$containerId = $pjax->id;
$this->registerJs(<<<JS
    if (window.queueWorkerRefreshInterval) {
        clearInterval(window.queueWorkerRefreshInterval);
    }

    window.queueWorkerRefreshInterval = setInterval(function() {
        if ($.pjax) {
            $.pjax.reload({container: '#{$containerId}'});
        }
    }, 3000);
JS, \yii\web\View::POS_READY, 'queue-worker-refresh-interval');
?>
