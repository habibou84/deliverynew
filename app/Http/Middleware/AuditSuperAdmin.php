<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\PlatformAuditLog;
use App\Models\SupportSession;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Journal des actions des super administrateurs : toute requête qui modifie quelque chose (pas GET), faite depuis
 * la console ou pendant une session d'assistance dans une entreprise (jeton « support:… »). Les secrets (mots de
 * passe, jetons, codes, clés) ne sont jamais écrits.
 */
class AuditSuperAdmin
{
    private const SECRET = '/pass|token|secret|pin|code|key|otp/i';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe()) {
            try {
                $this->record($request, $response);
            } catch (Throwable $e) {
                // Le journal ne doit jamais faire échouer l'action
                report($e);
            }
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return;
        }

        // Session d'assistance : l'action est attribuée au super administrateur qui l'a ouverte
        $token = $user->currentAccessToken();
        $tokenName = is_object($token) && isset($token->name) ? (string) $token->name : '';
        $support = str_starts_with($tokenName, SupportSession::TOKEN_PREFIX);
        if (! $support && ! $user->isPlatformStaff()) {
            return;
        }

        $data = ['input' => $this->clean($request->except(['_token']))];
        $companyId = $support ? $user->company_id : null;
        foreach ($request->route()?->parameters() ?? [] as $name => $value) {
            if ($value instanceof Model) {
                $data['targets'][$name] = $value->getKey();
                $companyId ??= $value instanceof Company ? $value->getKey() : $value->getAttribute('company_id');
            } elseif (is_scalar($value)) {
                $data['targets'][$name] = $value;
            }
        }
        if ($support) {
            $data['support_as'] = ['id' => $user->id, 'name' => $user->name];
        }

        PlatformAuditLog::create([
            'user_id' => $support ? ((int) Str::after($tokenName, SupportSession::TOKEN_PREFIX) ?: null) : $user->id,
            'company_id' => $companyId,
            'action' => ($name = $request->route()?->getName()) ? Str::after($name, 'v1.') : $request->method().' '.$request->path(),
            'method' => $request->method(),
            'path' => Str::limit('/'.ltrim($request->path(), '/'), 250, ''),
            'status' => $response->getStatusCode(),
            'data' => $data,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    private function clean(array $input, int $depth = 0): array
    {
        $clean = [];
        foreach ($input as $key => $value) {
            $clean[$key] = match (true) {
                is_string($key) && preg_match(self::SECRET, $key) === 1 => filled($value) ? '••••' : $value,
                $value instanceof UploadedFile => 'fichier : '.Str::limit($value->getClientOriginalName(), 80),
                is_array($value) => $depth < 3 ? $this->clean($value, $depth + 1) : '…',
                is_string($value) => Str::limit($value, 300),
                default => $value,
            };
        }

        return $clean;
    }
}
