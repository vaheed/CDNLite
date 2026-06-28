<?php

namespace App\Http\Controllers;

class LegacyFrontController extends Controller
{
    public function __invoke(): never
    {
        require base_path('public_index.php');
        exit;
    }
}
