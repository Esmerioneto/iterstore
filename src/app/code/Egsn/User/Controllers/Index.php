<?php

namespace Egsn\User\Controllers;

use Iter\Core\View\Layout;

class Index {


    public function Index():void {
        echo Layout::render('user_index');
    }
}