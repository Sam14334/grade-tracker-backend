<?php

function templateListController(): void {
    $user = requireProfessor();
    jsonSuccess(['templates' => templateList($user['userId'])]);
}

function templateShowController(array $params): void {
    $user = requireProfessor();
    jsonSuccess(templateDetail((int)$params['id'], $user['userId']));
}

function templateCreateController(): void {
    $user = requireProfessor();
    $data = requestData();
    requireCsrf($data);
    jsonSuccess(templateSave($data, null, $user['userId']));
}

function templateUpdateController(array $params): void {
    $user = requireProfessor();
    $data = requestData();
    requireCsrf($data);
    jsonSuccess(templateSave($data, (int)$params['id'], $user['userId']));
}

function templateDeleteController(array $params): void {
    $user = requireProfessor();
    requireCsrf(requestData());
    templateDelete((int)$params['id'], $user['userId']);
    jsonSuccess();
}
