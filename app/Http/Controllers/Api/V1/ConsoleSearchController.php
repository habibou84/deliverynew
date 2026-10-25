<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Order;
use App\Models\PlatformAuditLog;
use App\Models\PlatformInvoice;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Console : recherche dans toute la plateforme (utilisateurs, entreprises, courses, factures), par nom, téléphone,
 * e-mail ou numéro. Chaque recherche est notée au journal des actions.
 */
class ConsoleSearchController extends Controller
{
    private const LIMIT = 5;

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']])['q']);
        $like = '%'.mb_strtolower($q).'%';
        // Numéro : les chiffres seuls (07 07 12… → 070712…), comparés à la fin du numéro enregistré (+225…)
        $digits = preg_replace('/\D/', '', $q);
        $phone = strlen($digits) >= 4 ? '%'.ltrim($digits, '0').'%' : null;

        $users = User::query()->withoutGlobalScopes()->with(['company:id,name', 'roles:id,name'])
            ->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like])
                ->when($phone, fn ($w) => $w->orWhere('phone', 'like', $phone)))
            ->orderBy('name')->limit(self::LIMIT)->get();

        $companies = Company::query()
            ->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhere('slug', 'like', $like)->orWhereRaw('LOWER(email) LIKE ?', [$like])
                ->when($phone, fn ($w) => $w->orWhere('phone', 'like', $phone)))
            ->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'slug', 'phone', 'status']);

        $orders = Order::query()->withoutGlobalScopes()->with('company:id,name')
            ->where(fn ($w) => $w->where('tracking_code', 'like', '%'.mb_strtoupper($q).'%')
                ->when($phone, fn ($w) => $w->orWhere('recipient_phone', 'like', $phone)))
            ->latest('id')->limit(self::LIMIT)->get(['id', 'company_id', 'tracking_code', 'status', 'recipient_name', 'recipient_phone', 'created_at']);

        $invoices = PlatformInvoice::query()->with('company:id,name')->where('number', 'like', '%'.mb_strtoupper($q).'%')
            ->latest('id')->limit(self::LIMIT)->get(['id', 'company_id', 'number', 'total', 'status']);

        PlatformAuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'console.search', 'method' => 'GET', 'path' => '/console/search', 'status' => 200,
            'data' => ['input' => ['q' => Str::limit($q, 80)]], 'ip' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        return response()->json(['data' => [
            'users' => $users->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'phone' => PhoneNumber::display($u->phone), 'email' => $u->email,
                'role' => $u->primaryRole()?->label(), 'status' => $u->status,
                'company' => $u->company ? ['id' => $u->company->id, 'name' => $u->company->name] : null,
            ])->values(),
            'companies' => $companies->map(fn (Company $c) => [
                'id' => $c->id, 'name' => $c->name, 'url' => $c->url(), 'phone' => $c->phone ? PhoneNumber::display($c->phone) : null, 'status' => $c->status,
            ])->values(),
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id, 'tracking_code' => $o->tracking_code, 'status' => $o->status, 'status_label' => $o->status?->label(), 'recipient' => $o->recipient_name,
                'recipient_phone' => $o->recipient_phone ? PhoneNumber::display($o->recipient_phone) : null, 'created_at' => $o->created_at?->toIso8601String(),
                'company' => $o->company ? ['id' => $o->company->id, 'name' => $o->company->name] : null,
            ])->values(),
            'invoices' => $invoices->map(fn (PlatformInvoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'total' => $i->total, 'status' => $i->status,
                'company' => $i->company ? ['id' => $i->company->id, 'name' => $i->company->name] : null,
            ])->values(),
        ]]);
    }
}
