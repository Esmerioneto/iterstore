<?php

namespace Iter\Router\Controllers;

use Iter\Traits\Router;

class Index
{
    use Router;

    public function index()
    {
        return $this;
    }
}