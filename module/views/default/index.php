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
    <?php $form = ActiveForm::begin(['layout' => 'horizontal']); ?>
    <div class="card mb-4">
        <div class="card-header">
            <div class="d-flex align-items-center">
                <div class="mr-auto">Add workers</div>
                <div>
                <?= Html::submitButton('Create', ['class' => 'btn btn-success btn-sm']) ?>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?= $form->field($model, 'total')->textInput(['type' => 'number', 'min' => 1, 'step' => 1]) ?>
            <?= $form->field($model, 'component')->dropdownList($componentOptions) ?>
        </div>
    </div>
    <?php ActiveForm::end(); ?>

    <div class="card">
        <div class="card-header">
            <div class="d-flex align-items-center">
                <div class="mr-auto">Running workers</div>
                <div>
                    <?= Html::a('Delete all', ['delete-all'], [
                        'class' => 'btn btn-danger btn-sm',
                        'data' => [
                            'confirm' => 'Are you sure?',
                            'method' => 'post',
                        ]
                    ]) ?>
                </div>
            </div>
        </div>
        <div class="card-body">
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
                        'attribute' => 'queue_id'
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
    </div>
</div>

<?php

$this->registerJs(' 
        setInterval(function() {  
            $.pjax.reload({ container: "#' . $pjax->id . '" })
        }, 3000)
    ', \yii\web\VIEW::POS_HEAD);
?>
