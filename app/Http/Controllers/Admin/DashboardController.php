<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\OperationsSnapshot;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(OperationsSnapshot $snapshot): View
    {
        return view('admin.dashboard', [
            'snapshot' => $snapshot->forDate(),
        ]);
    }
}
