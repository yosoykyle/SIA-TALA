<?php

use Illuminate\Http\Request;

$app = require __DIR__.'/../bootstrap/app.php';
$app->handleRequest(Request::capture());
