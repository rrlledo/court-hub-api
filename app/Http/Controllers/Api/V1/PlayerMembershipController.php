<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\MembershipCard;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Services\PayMongoCheckout;
use App\Services\SettleCheckout;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;

class PlayerMembershipController extends Controller
{
    public function plans(Request $request)
    {
        return MembershipPlan::where('tenant_id', $request->user()->tenant_id)
            ->where('is_active', true)->orderBy('price')->orderBy('name')->paginate(20);
    }

    public function index(Request $request)
    {
        return $this->memberships($request)->latest('starts_on')->paginate(20)
            ->through(fn (Membership $membership) => $this->membershipData($membership));
    }

    public function show(Request $request, int $membership)
    {
        return response()->json(['data' => $this->membershipData($this->membership($request, $membership))]);
    }

    public function purchase(Request $request)
    {
        $data = $request->validate(['membership_plan_id' => ['required', 'integer']]);
        $plan = MembershipPlan::where('tenant_id', $request->user()->tenant_id)->where('is_active', true)->findOrFail($data['membership_plan_id']);
        abort_if((float) $plan->price < 1 || $plan->currency !== 'PHP', 422, 'This membership plan cannot use online checkout.');

        $membership = Membership::create([
            'tenant_id' => $request->user()->tenant_id,
            'membership_plan_id' => $plan->id,
            'user_id' => $request->user()->id,
            'starts_on' => today(),
            'ends_on' => today()->addDays($plan->duration_days - 1),
            'status' => 'pending',
            'remaining_sessions' => $plan->session_count,
        ]);

        return response()->json(['data' => $this->membershipData($membership)], 201);
    }

    public function renew(Request $request, int $membership)
    {
        $current = $this->membership($request, $membership);
        abort_if($current->status === 'pending', 422, 'This membership is already awaiting payment.');
        $plan = MembershipPlan::where('tenant_id', $request->user()->tenant_id)->where('is_active', true)->findOrFail($current->membership_plan_id);
        abort_if((float) $plan->price < 1 || $plan->currency !== 'PHP', 422, 'This membership plan cannot use online checkout.');
        $starts = Carbon::parse($current->ends_on)->addDay()->max(today())->startOfDay();
        $renewal = Membership::create([
            'tenant_id' => $request->user()->tenant_id,
            'membership_plan_id' => $plan->id,
            'user_id' => $request->user()->id,
            'starts_on' => $starts,
            'ends_on' => $starts->copy()->addDays($plan->duration_days - 1),
            'status' => 'pending',
            'remaining_sessions' => $plan->session_count,
        ]);

        return response()->json(['data' => $this->membershipData($renewal)], 201);
    }

    public function paymentStatus(Request $request, int $membership, PayMongoCheckout $provider, SettleCheckout $settler)
    {
        $model = $this->membership($request, $membership);
        $payment = Payment::where('membership_id', $model->id)->latest('id')->first();
        if (! $payment) {
            return response()->json(['data' => ['payment' => null, 'checkout_url' => null, 'membership' => $this->membershipData($model)]]);
        }
        if ($payment->provider === 'paymongo' && $payment->provider_reference && ! in_array($payment->status, ['paid', 'paid_review', 'expired'], true)) {
            try {
                $payment = $provider->reconcile($payment);
            } catch (RequestException|ConnectionException $e) {
                abort(503, 'Payment verification is temporarily unavailable. Try checking again.');
            }
        }

        return response()->json(['data' => $this->paymentData($payment->fresh())]);
    }

    private function memberships(Request $request)
    {
        return Membership::where('tenant_id', $request->user()->tenant_id)->where('user_id', $request->user()->id);
    }

    private function membership(Request $request, int $id): Membership
    {
        return $this->memberships($request)->findOrFail($id);
    }

    private function membershipData(Membership $membership): array
    {
        $plan = MembershipPlan::find($membership->membership_plan_id);
        $card = MembershipCard::where('membership_id', $membership->id)->where('is_active', true)->latest('id')->first();

        return $membership->only(['id', 'membership_plan_id', 'starts_on', 'ends_on', 'status', 'auto_renew', 'remaining_sessions']) + [
            'plan' => $plan?->only(['id', 'name', 'plan_type', 'billing_period', 'price', 'currency', 'duration_days', 'session_count', 'discount_percent', 'priority_booking']),
            'card' => $card?->only(['card_number', 'qr_code', 'is_active']),
        ];
    }

    private function paymentData(Payment $payment): array
    {
        $membership = Membership::findOrFail($payment->membership_id);

        return [
            'payment' => $payment->only(['id', 'membership_id', 'reference', 'invoice_number', 'status', 'amount', 'currency', 'paid_at']),
            'checkout_url' => data_get($payment->provider_payload, 'checkout_url'),
            'attempt_failed' => (bool) data_get($payment->provider_payload, 'attempt_failed', false),
            'membership' => $this->membershipData($membership),
        ];
    }
}
