<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use Illuminate\Contracts\View\View;

class WelcomeController extends Controller
{
    /**
     * Show the landing page with the active period on its status badge.
     */
    public function __invoke(): View
    {
        return view('welcome', ['period' => AcademicPeriod::current()]);
    }
}
