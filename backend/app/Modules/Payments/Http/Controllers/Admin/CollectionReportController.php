<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\BuildCollectionReport;
use App\Modules\Payments\Http\Requests\CollectionReportRequest;
use App\Modules\Payments\Http\Resources\CollectionRowResource;
use App\Modules\Payments\Models\PaymentTransaction;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the platform collected in a period, and the same thing as a file (US5).
 *
 * ⚠️ A PLATFORM PERMISSION, WHICH MEANS THE WORKSPACE OWNER IS REFUSED. FR-033
 * forbids a teacher or their assistant reaching what any student paid or any
 * collection total, and the highest tenant role is the strongest form of that
 * assertion — `CollectionAccessTest` makes it on both routes.
 */
class CollectionReportController extends Controller
{
    public function __construct(private readonly BuildCollectionReport $report) {}

    public function show(CollectionReportRequest $request): JsonResponse
    {
        ['summary' => $summary, 'rows' => $rows] = $this->report->handle($request->filter(), $request->perPage());

        return response()->json([
            'data' => $summary,
            // The page's rows in the envelope the collection would have produced
            // on its own — `response()->json(Resource::collection(...))` never
            // calls `toResponse()`, so the `data` key silently disappears and
            // every client reads an empty list. That bug shipped once already,
            // on `OrderController::index`.
            'rows' => CollectionRowResource::collection($rows)->response()->getData(true),
        ]);
    }

    /**
     * The same period, the same filter, the same query — as a file (FR-034).
     *
     * ⚠️ STREAMED AND CHUNKED. The sizing the spec gives is ten thousand rows; a
     * `->get()` here holds all of them, their orders and their purchases in
     * memory at once, and the export is the one request nobody watches.
     */
    public function export(CollectionReportRequest $request): StreamedResponse
    {
        $filter = $request->filter();
        $rows = $this->report->stream($filter);

        $columns = [
            'uuid', 'occurred_at', 'settled_at', 'status', 'method', 'provider',
            'amount_minor', 'currency', 'order_uuid', 'source', 'student_uuid',
            'student_name', 'credits', 'operating_fee_minor',
            'gateway_fee_minor', 'total_minor',
        ];

        return response()->streamDownload(function () use ($rows, $columns): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            // A BOM, because the file is opened in Excel and its columns carry
            // Arabic names. Without it every name arrives as mojibake and the
            // report reads as broken rather than as mis-decoded.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            foreach ($rows as $row) {
                fputcsv($handle, $this->line($row, $columns));
            }

            fclose($handle);
        }, 'collection-'.$filter->from->toDateString().'-'.$filter->to->subDay()->toDateString().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * One CSV line, built from the SAME Resource the screen renders.
     *
     * ⚠️ NOT A SECOND FIELD LIST. "The same data and the same restrictions"
     * (FR-034) is a promise a hand-written export breaks the first time a field
     * is added to one side only — and the side that gets forgotten is the file,
     * because nobody looks at it until an auditor does.
     *
     * @param  list<string>  $columns
     * @return list<string|int|null>
     */
    private function line(PaymentTransaction $row, array $columns): array
    {
        $payload = (new CollectionRowResource($row))->resolve();

        /** @var array<string, mixed> $pricing */
        $pricing = is_array($payload['pricing'] ?? null) ? $payload['pricing'] : [];

        $flat = array_merge($payload, $pricing);
        unset($flat['pricing']);

        return array_map(function (string $column) use ($flat): string|int|null {
            $value = $flat[$column] ?? null;

            return is_string($value) || is_int($value) ? $value : null;
        }, $columns);
    }
}
