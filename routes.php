<?php

use Iter\Router\Controllers\Index;

$router = new Index();

$router->addRoute('/', 'Iter\Admin\Controllers\Index', 'index');
$router->execute();

