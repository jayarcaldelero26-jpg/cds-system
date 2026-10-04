<?php

namespace App\Http\Controllers;

use App\Support\LocalNavigationTrace;

abstract class Controller
{
    /**
     * Keep Laravel's default controller invocation while exposing an opt-in
     * per-action duration for the local navigation trace.
     */
    public function callAction($method, $parameters)
    {
        return LocalNavigationTrace::measure(request(), 'controller_action', fn () => $this->{$method}(...array_values($parameters)));
    }
}
