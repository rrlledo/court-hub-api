<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformSubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuperAdminController extends Controller
{
    public function overview(): JsonResponse
    {
        return response()->json(['data' => [
            'tenants' => Tenant::count(),
            'active_tenants' => Tenant::where('is_active', true)->count(),
            'users' => User::count(),
            'facilities' => Facility::count(),
            'today_bookings' => Booking::whereDate('starts_at', today())->count(),
            'paid_revenue' => Payment::where('status', 'paid')->sum('amount'),
            'active_memberships' => Membership::where('status', 'active')->count(),
            'simulated_platform_mrr' => Tenant::whereIn('subscription_status', ['trial', 'active'])->sum('subscription_amount'),
            'subscriptions_by_status' => Tenant::selectRaw('subscription_status, count(*) as total')
                ->groupBy('subscription_status')
                ->pluck('total', 'subscription_status'),
            'simulated_platform_invoice_total' => PlatformSubscriptionInvoice::where('status', 'paid')->sum('amount'),
            'simulated_platform_overdue_total' => PlatformSubscriptionInvoice::where('status', 'overdue')->sum('amount'),
        ]]);
    }

    public function tenants(): JsonResponse
    {
        $tenants = Tenant::query()
            ->withCount(['users', 'facilities'])
            ->latest()
            ->paginate();

        return response()->json($tenants);
    }

    public function show(int $tenant): JsonResponse
    {
        $model = Tenant::query()->withCount(['users', 'facilities'])->findOrFail($tenant);

        return response()->json(['data' => [
            'tenant' => $model,
            'metrics' => [
                'bookings' => Booking::where('tenant_id', $model->id)->count(),
                'paid_revenue' => Payment::where('tenant_id', $model->id)->where('status', 'paid')->sum('amount'),
                'active_memberships' => Membership::where('tenant_id', $model->id)->where('status', 'active')->count(),
                'simulated_platform_subscription' => [
                    'plan' => $model->subscription_plan,
                    'status' => $model->subscription_status,
                    'amount' => $model->subscription_amount,
                    'renews_at' => $model->subscription_renews_at,
                ],
            ],
            'users' => User::where('tenant_id', $model->id)
                ->with('roles:id,name')
                ->orderBy('name')
                ->get(['id', 'tenant_id', 'name', 'email', 'email_verified_at', 'created_at']),
        ]]);
    }

    public function update(Request $request, int $tenant): JsonResponse
    {
        $model = Tenant::findOrFail($tenant);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'timezone' => ['sometimes', 'timezone'],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'is_active' => ['sometimes', 'boolean'],
            'subscription_plan' => ['sometimes', 'in:starter,growth,enterprise'],
            'subscription_status' => ['sometimes', 'in:trial,active,past_due,cancelled'],
            'subscription_amount' => ['sometimes', 'numeric', 'min:0'],
            'subscription_renews_at' => ['sometimes', 'nullable', 'date'],
        ]);
        if (isset($data['country_code'])) {
            $data['country_code'] = strtoupper($data['country_code']);
        }
        $model->update($data);

        return response()->json(['data' => $model]);
    }

    public function revokeUserSessions(int $tenant, int $user): JsonResponse
    {
        $target = User::where('tenant_id', $tenant)->findOrFail($user);
        $target->tokens()->delete();

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function invoices(Request $request, int $tenant): JsonResponse
    {
        Tenant::findOrFail($tenant);

        return response()->json(['data' => PlatformSubscriptionInvoice::where('tenant_id', $tenant)->latest()->paginate()]);
    }

    public function createInvoice(Request $request, int $tenant): JsonResponse
    {
        $model = Tenant::findOrFail($tenant);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0'], 'tax_amount' => ['nullable', 'numeric', 'min:0'], 'due_on' => ['nullable', 'date'], 'status' => ['nullable', 'in:open,paid,overdue,void'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $status = $data['status'] ?? 'open';
        $invoice = PlatformSubscriptionInvoice::create([
            'tenant_id' => $model->id,
            'reference' => 'SIM-'.strtoupper(uniqid()),
            'status' => $status,
            'amount' => $data['amount'],
            'tax_amount' => $data['tax_amount'] ?? 0,
            'due_on' => $data['due_on'] ?? now()->addMonth()->toDateString(),
            'paid_at' => $status === 'paid' ? now() : null,
            'metadata' => ['mock' => true, 'notes' => $data['notes'] ?? null],
        ]);

        return response()->json(['data' => $invoice], 201);
    }

    public function settleInvoice(int $tenant, int $invoice): JsonResponse
    {
        $model = PlatformSubscriptionInvoice::where('tenant_id', Tenant::findOrFail($tenant)->id)->findOrFail($invoice);
        $model->update(['status' => 'paid', 'paid_at' => now()]);

        return response()->json(['data' => $model->fresh()]);
    }
}
