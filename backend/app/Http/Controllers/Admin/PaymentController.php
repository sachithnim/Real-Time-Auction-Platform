<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListPaymentsRequest;
use App\Http\Resources\Admin\AdminPaymentResource;
use App\Models\Payment;
use App\Support\ServerTime;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Every winner's payment: who has paid, who still owes, and declined attempts.
 */
class PaymentController extends Controller
{
    public function index(ListPaymentsRequest $request): AnonymousResourceCollection
    {
        $payments = Payment::query()
            ->with(['auction:id,title', 'user:id,name,email'])
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->validated('search'), function ($query, $search) {
                $like = '%'.addcslashes(trim($search), '\\%_').'%';
                $query->where(fn ($match) => $match
                    ->where('reference', 'like', $like)
                    ->orWhereRelation('auction', 'title', 'like', $like)
                    ->orWhereRelation('user', 'name', 'like', $like)
                    ->orWhereRelation('user', 'email', 'like', $like));
            })
            // Still owed first (declined, then waiting), then paid, newest first.
            ->orderByRaw("FIELD(status, 'FAILED', 'PENDING', 'PAID')")
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return AdminPaymentResource::collection($payments)->additional([
            'counts' => $this->counts(),
            ...ServerTime::meta(),
        ]);
    }

    /**
     * How many payments are in each status, and the totals paid and still owed.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $byStatus = Payment::query()
            ->selectRaw('status, COUNT(*) AS payments, SUM(amount) AS total')
            ->groupBy('status')
            ->get()
            ->keyBy(fn (Payment $row) => $row->status->value);

        $count = fn (PaymentStatus $status) => (int) ($byStatus[$status->value]->payments ?? 0);
        $sum = fn (PaymentStatus $status) => (int) ($byStatus[$status->value]->total ?? 0);

        return [
            'all' => $byStatus->sum('payments'),
            'pending' => $count(PaymentStatus::Pending),
            'failed' => $count(PaymentStatus::Failed),
            'paid' => $count(PaymentStatus::Paid),
            'paid_total' => $sum(PaymentStatus::Paid),
            'owed_total' => $sum(PaymentStatus::Pending) + $sum(PaymentStatus::Failed),
        ];
    }
}
