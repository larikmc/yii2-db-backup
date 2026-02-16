<?php

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use soft2soft\yii2dbbackup\models\BackupJob;
use yii\grid\GridView;
use yii\helpers\Html;

$this->title = 'Резервные копии БД';
?>

<div class="dbbackup-index">
    <h1><?= Html::encode($this->title) ?></h1>

    <?php foreach (Yii::$app->session->getAllFlashes() as $type => $message): ?>
        <div class="alert alert-<?= $type === 'error' ? 'danger' : Html::encode($type) ?>">
            <?= is_array($message) ? implode('<br>', array_map('strval', $message)) : Html::encode((string)$message) ?>
        </div>
    <?php endforeach; ?>

    <p>
        <?= Html::beginForm(['start'], 'post', ['style' => 'display:inline-block;margin-right:8px;']) ?>
        <?= Html::submitButton('Создать backup', ['class' => 'btn btn-primary']) ?>
        <?= Html::endForm() ?>
        <?= Html::a('Обновить', ['index'], ['class' => 'btn btn-default']) ?>
    </p>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'columns' => [
            'id',
            [
                'attribute' => 'status',
                'label' => 'Статус',
                'value' => static function (BackupJob $model): string {
                    if ($model->status === BackupJob::STATUS_SUCCESS) {
                        return 'Готово';
                    }

                    $map = [
                        BackupJob::STATUS_QUEUED => 'В очереди',
                        BackupJob::STATUS_RUNNING => 'Выполняется',
                        BackupJob::STATUS_FAILED => 'Ошибка',
                        BackupJob::STATUS_PRUNED => 'Удален ротацией',
                    ];

                    $status = $map[$model->status] ?? $model->status;
                    return $model->phase ? ($status . ' (' . $model->phase . ')') : $status;
                },
            ],
            [
                'attribute' => 'file_path',
                'label' => 'Путь к файлу',
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
                    return trim($out) ?: '—';
                },
            ],
        ],
    ]) ?>
</div>

