<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\ConnectRequest;
use App\Services\Connect\DirectConnect;
use App\Support\Branding;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Lien signé envoyé sur WhatsApp au marchand déjà client (connexion directe, valable 48 h) : il confirme que sa
 * boutique Jibiat Marchand peut confier ses colis à son compte, ou refuse. Rien n'est rattaché sans ce « Oui ».
 */
class ConnectConfirmController extends Controller
{
    public function show(int $connectRequest): View
    {
        [$request, $company] = $this->find($connectRequest);

        return view('connect.confirm', ['request' => $request, 'company' => $company, 'done' => null, 'error' => null]);
    }

    public function decide(Request $http, int $connectRequest, DirectConnect $direct): View
    {
        [$request, $company] = $this->find($connectRequest);
        $yes = $http->input('answer') === 'yes';

        try {
            $request = $direct->confirm($request, $yes);
            $error = null;
        } catch (BusinessRuleException $e) {
            $error = $e->getMessage();
        }

        return view('connect.confirm', ['request' => $request, 'company' => $company, 'done' => $error ? null : $yes, 'error' => $error]);
    }

    /**
     * @return array{0: ConnectRequest, 1: Company}
     */
    private function find(int $id): array
    {
        $request = ConnectRequest::withoutGlobalScopes()->find($id);
        $company = $request ? Company::find($request->company_id) : null;
        $here = Branding::company();
        abort_if($request === null || ! $request->isConfirmation() || $company === null || ($here !== null && $here->id !== $company->id), 404);

        return [$request, $company];
    }
}
