<?php

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use larikmc\yii2dbbackup\models\BackupJob;
use yii\grid\GridView;
use yii\helpers\Html;

$this->title = 'Резервные копии БД';

$this->registerCss(<<<CSS
.dbbackup-index .dbbackup-title {
    margin: 0 0 20px;
}

.dbbackup-index .dbbackup-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0 0 20px;
    padding: 0;
}

.dbbackup-index .dbbackup-start-form {
    margin: 0;
}

.dbbackup-index .dbbackup-actions form,
.dbbackup-index .dbbackup-row-actions form {
    background: transparent !important;
    padding: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
}

.dbbackup-index .dbbackup-actions .btn {
    min-height: 38px;
}

.dbbackup-index .dbbackup-row-actions {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.dbbackup-index .dbbackup-row-actions .btn {
    min-width: 74px;
}
CSS);
?>

<div class="dbbackup-index">
    <h1 class="dbbackup-title"><?= Html::encode($this->title) ?></h1>

    <div class="dbbackup-actions">
        <?= Html::beginForm(['start'], 'post', ['class' => 'dbbackup-start-form']) ?>
        <?= Html::submitButton('Создать backup', ['class' => 'btn btn-primary']) ?>
        <?= Html::endForm() ?>
        <?= Html::a('Обновить', ['index'], ['class' => 'btn btn-warning']) ?>
    </div>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'columns' => [
            'id',
            [
                'attribute' => 'status',
                'label' => 'Статус',
                'value' => static function (BackupJob $model): string {
                    $map = [
                        BackupJob::STATUS_SUCCESS => 'Готово',
                        BackupJob::STATUS_QUEUED => 'В очереди',
                        BackupJob::STATUS_RUNNING => 'Выполняется',
                        BackupJob::STATUS_FAILED => 'Ошибка',
                        BackupJob::STATUS_PRUNED => 'Удален ротацией',
                    ];
                    return $map[$model->status] ?? 'Неизвестно';
                },
            ],
            [
                'attribute' => 'file_path',
                'label' => 'Файл',
                'value' => static function (BackupJob $model): string {
                    if (!$model->file_path) {
                        return '(не задано)';
                    }
                    return basename((string)$model->file_path);
                },
            ],
            [
                'attribute' => 'file_size',
                'label' => 'Размер файла',
                'value' => static function (BackupJob $model): string {
                    if ($model->file_size === null) {
                        return '—';
                    }
                    return number_format(((float)$model->file_size) / 1024, 2, '.', ' ') . ' KB';
                },
            ],
            [
                'attribute' => 'created_at',
                'label' => 'Создано',
                'value' => static fn(BackupJob $m): string => date('d.m.Y H:i:s', (int)$m->created_at),
            ],
            [
                'attribute' => 'finished_at',
                'label' => 'Завершено',
                'value' => static fn(BackupJob $m): string => $m->finished_at ? date('d.m.Y H:i:s', (int)$m->finished_at) : '—',
            ],
            [
                'header' => 'Действия',
                'format' => 'raw',
                'value' => static function (BackupJob $model): string {
                    $out = '';
                    if ($model->status === BackupJob::STATUS_SUCCESS && $model->file_path && is_file($model->file_path)) {
                        $out .= Html::a('Скачать', ['download', 'id' => $model->id], ['class' => 'btn btn-xs btn-success']);
                    }
                    if (in_array($model->status, [BackupJob::STATUS_SUCCESS, BackupJob::STATUS_FAILED], true)) {
                        $out .= ' ' . Html::beginForm(['delete', 'id' => $model->id], 'post', ['style' => 'display:inline-block']);
                        $out .= Html::submitButton('Удалить', ['class' => 'btn btn-xs btn-danger']);
                        $out .= Html::endForm();
                    }
                    return $out ? Html::tag('div', trim($out), ['class' => 'dbbackup-row-actions']) : '—';
                },
            ],
        ],
    ]) ?>
</div>
