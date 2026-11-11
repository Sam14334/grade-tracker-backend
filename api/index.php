<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$router = require __DIR__ . '/../routes/api.php';
$router->dispatch(requestMethod(), requestPath());
