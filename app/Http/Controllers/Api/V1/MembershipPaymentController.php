<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Membership;
use App\Models\MembershipCard;
use App\Models\MembershipPlan;
use App\Models\MembershipSessionUsage;
use App\Models\Payment;
use App\Models\PaymentRefund;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MembershipPaymentController extends Controller
{
    public function providers()
    {
        return response()->json(['data' => [
            ['name' => 'paymongo', 'methods' => ['gcash', 'maya', 'card'], 'configured' => filled(config('services.paymongo.secret_key')) && filled(config('services.paymongo.webhook_secret')) && filled(config('services.paymongo.return_url'))],
            ['name' => 'xendit', 'methods' => [], 'configured' => false],
        ]]);
    }

    public function updatePlan(Request $request, int $plan)
    {
        $model = $this->plan($request, $plan);
        $model->update($request->validate(['name' => ['sometimes', 'string', 'max:120'], 'price' => ['sometimes', 'numeric', 'min:0'], 'duration_days' => ['sometimes', 'integer', 'min:1'], 'discount_percent' => ['sometimes', 'numeric', 'between:0,100'], 'priority_booking' => ['sometimes', 'boolean'], 'session_count' => ['nullable', 'integer', 'min:1'], 'is_active' => ['sometimes', 'boolean']]));

        return response()->json(['data' => $model]);
    }

    public function destroyPlan(Request $request, int $plan)
    {
        $this->plan($request, $plan)->delete();

        return response()->noContent();
    }

    public function showMembership(Request $request, int $membership)
    {
        return response()->json(['data' => $this->membership($request, $membership)]);
    }

    public function freeze(Request $request, int $membership)
    {
        $model = $this->membership($request, $membership);
        abort_if($model->status !== 'active', 422, 'Only active memberships can be frozen.');
        $model->update(['status' => 'frozen', 'frozen_at' => now()]);

        return response()->json(['data' => $model]);
    }

    public function unfreeze(Request $request, int $membership)
    {
        $model = $this->membership($request, $membership);
        abort_unless($model->status === 'frozen', 422, 'Only frozen memberships can be resumed.');
        $model->update(['status' => 'active', 'frozen_at' => null]);

        return response()->json(['data' => $model]);
    }

    public function cancelMembership(Request $request, int $membership)
    {
        $model = $this->membership($request, $membership);
        $model->update(['status' => 'cancelled', 'auto_renew' => false]);

        return response()->json(['data' => $model]);
    }

    public function renew(Request $request, int $membership)
    {
        $model = $this->membership($request, $membership);
        $plan = $this->plan($request, $model->membership_plan_id);
        $start = Carbon::parse($model->ends_on)->addDay()->startOfDay();
        $model->update(['starts_on' => $start, 'ends_on' => $start->copy()->addDays($plan->duration_days - 1), 'status' => 'active', 'frozen_at' => null, 'remaining_sessions' => $plan->session_count]);

        return response()->json(['data' => $model]);
    }

    public function createCard(Request $request, int $membership)
    {
        $model = $this->membership($request, $membership);
        $card = MembershipCard::create(['tenant_id' => $request->user()->tenant_id, 'membership_id' => $model->id, 'card_number' => 'MC-'.Str::upper(Str::random(12)), 'qr_code' => Str::upper(Str::random(24))]);

        return response()->json(['data' => $card], 201);
    }

    public function useSession(Request $request, int $membership)
    {
        $model = $this->membership($request, $membership);
        abort_if($model->status !== 'active' || $model->remaining_sessions === null || $model->remaining_sessions < 1, 422, 'This membership has no usable sessions.');
        $data = $request->validate(['booking_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:1000']]);
        if (isset($data['booking_id'])) {
            Booking::where('tenant_id', $request->user()->tenant_id)->findOrFail($data['booking_id']);
        }
        $usage = MembershipSessionUsage::create($data + ['tenant_id' => $request->user()->tenant_id, 'membership_id' => $model->id, 'used_by' => $request->user()->id, 'used_at' => now()]);
        $model->decrement('remaining_sessions');

        return response()->json(['data' => $usage], 201);
    }

    public function sessionUsage(Request $request, int $membership)
    {
        $model = $this->membership($request, $membership);

        return MembershipSessionUsage::where('membership_id', $model->id)->latest('used_at')->paginate();
    }

    public function refund(Request $request, int $payment)
    {
        $model = $this->payment($request, $payment);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01', 'max:'.$model->amount], 'reason' => ['nullable', 'string', 'max:1000']]);
        $alreadyRefunded = (float) PaymentRefund::where('payment_id', $model->id)->whereIn('status', ['requested', 'approved', 'completed'])->sum('amount');
        abort_if($alreadyRefunded + (float) $data['amount'] > (float) $model->amount, 422, 'The refund total cannot exceed the payment amount.');

        return response()->json(['data' => PaymentRefund::create($data + ['tenant_id' => $request->user()->tenant_id, 'payment_id' => $model->id, 'requested_by' => $request->user()->id])], 201);
    }

    public function invoice(Request $request, int $payment)
    {
        $model = $this->payment($request, $payment);

        return response()->json(['data' => ['invoice_number' => $model->invoice_number, 'reference' => $model->reference, 'amount' => $model->amount, 'currency' => $model->currency, 'status' => $model->status, 'issued_at' => $model->created_at]]);
    }

    public function reconciliation(Request $request)
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $query = Payment::where('tenant_id', $request->user()->tenant_id);
        if (isset($data['from'])) {
            $query->whereDate('created_at', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('created_at', '<=', $data['to']);
        }
        $payments = $query->get();

        return response()->json(['data' => ['payment_count' => $payments->count(), 'paid_amount' => $payments->where('status', 'paid')->sum('amount'), 'pending_amount' => $payments->where('status', 'pending')->sum('amount'), 'refunded_amount' => PaymentRefund::where('tenant_id', $request->user()->tenant_id)->where('status', 'completed')->sum('amount')]]);
    }

    private function plan(Request $request, int $id): MembershipPlan
    {
        return MembershipPlan::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function membership(Request $request, int $id): Membership
    {
        return Membership::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function payment(Request $request, int $id): Payment
    {
        $payment = Payment::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        abort_unless($payment->user_id === $request->user()->id || $request->user()->hasAnyRole(['court-owner', 'facility-manager', 'front-desk']), 403);

        return $payment;
    }
}
