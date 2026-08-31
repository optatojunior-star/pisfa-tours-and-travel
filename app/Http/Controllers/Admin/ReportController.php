<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportPeriodRequest;
use App\Services\Reports\CsvExporter;
use App\Services\Reports\OperationsReport;
use App\Services\Reports\RevenueReport;
use App\Support\Export\ExportDataset;
use App\Support\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly RevenueReport $revenue,
        private readonly OperationsReport $operations,
    ) {}

    public function index(ReportPeriodRequest $request): View
    {
        $user = $request->user();
        abort_unless($user?->canAccessAdministration() ?? false, 403);

        $period = $request->period();

        // Revenue is accounting data. Staff get the operational half of the
        // page and nothing else, rather than a blocked page.
        $showsMoney = $user->hasAnyRole(UserRole::Manager, UserRole::SuperAdmin);

        return view('admin.reports.index', [
            'period' => $period,
            'showsMoney' => $showsMoney,
            'revenue' => $showsMoney ? $this->revenue->summary($period) : null,
            'operations' => $this->operations->summary($period),
            'datasets' => ExportDataset::availableTo($user),
        ]);
    }

    public function export(
        Request $request,
        string $dataset,
        CsvExporter $exporter,
    ): StreamedResponse {
        $user = $request->user();
        abort_unless($user?->canAccessAdministration() ?? false, 403);

        // Matched against the allowlist rather than resolved: a table name from
        // a query string would export any row in the database.
        $selected = ExportDataset::tryFrom($dataset);
        abort_if($selected === null, 404);

        try {
            $period = ReportPeriod::between(
                $request->query('from') === null ? null : (string) $request->query('from'),
                $request->query('to') === null ? null : (string) $request->query('to'),
            );
        } catch (InvalidArgumentException) {
            abort(422, 'That date range cannot be exported.');
        }

        return $exporter->stream($user, $selected, $period);
    }
}
