<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlaceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Jibiat\Socle\Places\Places;

/**
 * Quartiers manquants : signalés par les entreprises et leurs e-commerçants (les lieux ne se créent plus à la main),
 * traités dans la console de la plateforme (ajoutés au référentiel du socle, ou refusés).
 */
class PlaceReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $parents = array_column(array_filter(Places::all(), fn (array $p) => $p['type'] !== Places::NEIGHBORHOOD), 'code');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_code' => ['required', 'string', Rule::in($parents)],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['name' => 'quartier', 'parent_code' => 'commune ou ville', 'note' => 'précision']);

        // Déjà dans la liste : on le dit au lieu d'enregistrer un doublon
        $known = Places::named($data['name']);
        if ($known !== null && in_array($data['parent_code'], Places::lineage($known['code']), true)) {
            return response()->json(['message' => '« '.Places::label($known['code']).' » est déjà dans la liste : cherchez-le par son nom.', 'errors' => ['name' => ['Déjà dans la liste.']]], 422);
        }

        $report = PlaceReport::create([...$data, 'company_id' => $request->user()->company_id, 'user_id' => $request->user()->id, 'status' => 'pending']);

        return response()->json(['data' => ['id' => $report->id, 'status' => $report->status]], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $reports = PlaceReport::query()->with(['company:id,name', 'user:id,name'])
            ->when(! $request->boolean('all'), fn ($q) => $q->where('status', 'pending'))
            ->latest()->limit(200)->get();

        return response()->json(['data' => $reports->map(fn (PlaceReport $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'parent' => Places::label((string) $r->parent_code) ?? $r->parent_code,
            'note' => $r->note,
            'status' => $r->status,
            'company' => $r->company?->name,
            'user' => $r->user?->name,
            'created_at' => $r->created_at,
        ])]);
    }

    public function update(Request $request, PlaceReport $placeReport): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(PlaceReport::STATUSES)]]);
        $placeReport->update($data);

        return response()->json(['data' => ['id' => $placeReport->id, 'status' => $placeReport->status]]);
    }
}
