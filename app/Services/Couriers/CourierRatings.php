<?php

namespace App\Services\Couriers;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\WhatsAppTemplate;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierRating;
use App\Models\CourierReview;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\CourierRatingAlert;
use App\Services\Messaging\Messenger;
use App\Support\PhoneNumber;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Notation des livreurs : avis du destinataire après la livraison (WhatsApp ou lien),
 * évaluation du mois par un responsable, note globale lissée sur 90 jours.
 */
class CourierRatings
{
    /** Avis possible pendant 7 jours après la livraison */
    public const RATING_DAYS = 7;

    /** Fenêtre de calcul de la note */
    public const WINDOW_DAYS = 90;

    /** Poids de chaque source dans la note globale (répartis entre les sources présentes) */
    public const WEIGHTS = ['recipient' => 0.4, 'merchant' => 0.3, 'manager' => 0.3];

    /** Lissage : une note neutre de 4 compte comme 5 avis, pour qu'un seul avis ne fasse pas la note */
    private const PRIOR_SCORE = 4.0;

    private const PRIOR_WEIGHT = 5;

    /** Le mot du client après sa note est accepté pendant 30 minutes */
    private const COMMENT_MINUTES = 30;

    public function __construct(private readonly Messenger $messenger) {}

    /* ---------- Demande d'avis au destinataire ---------- */

    /**
     * Après la livraison : message au destinataire avec la question et le lien.
     */
    public function requestFromRecipient(Order $order): ?OutboundMessage
    {
        $order->loadMissing(['company', 'merchant', 'deliveryCourier.user']);
        $company = $order->company;

        if (! $company?->ask_recipient_rating || $order->is_shipping || $order->delivery_courier_id === null
            || $order->rating_requested_at !== null || $order->status !== OrderStatus::Delivered) {
            return null;
        }

        $order->forceFill(['rating_token' => Str::random(24), 'rating_requested_at' => now()])->save();

        return $this->messenger->toRecipient($order, 'order.rating', WhatsAppTemplate::RatingRequest, [
            $order->recipient_name ?: 'cher client',
            $order->merchant?->business_name ?? $company->name,
            $this->firstName($order->deliveryCourier),
            $company->url('/avis/'.$order->rating_token),
        ]);
    }

    /**
     * Réponse WhatsApp d'un destinataire : un chiffre de 1 à 5 juste après la demande d'avis,
     * puis éventuellement un mot. Renvoie false si le message n'est pas un avis, sinon la réponse
     * à envoyer (null : le merci est déjà parti).
     */
    public function handleWhatsApp(Company $company, string $phone, ?string $text): string|false|null
    {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }

        $last = OutboundMessage::forCompany($company->id)->where('to', $phone)->latest('id')->first();
        if ($last === null || $last->order_id === null || $last->recipient_type !== 'recipient') {
            return false;
        }

        // Juste après la demande : « 4 », « 5 étoiles », « 3 il était en retard »
        if ($last->event === 'order.rating' && preg_match('/^\s*([1-5])(?:\s*(?:\/\s*5|étoiles?|etoiles?|⭐+))?(?:[\s.,!:\-]+(.*))?$/isu', $text, $m)) {
            $order = Order::forCompany($company->id)->find($last->order_id);
            if ($order === null) {
                return false;
            }
            try {
                $this->record($order, CourierRating::SOURCE_RECIPIENT, (int) $m[1], [], trim($m[2] ?? '') ?: null, 'whatsapp', phone: $phone);
            } catch (BusinessRuleException $e) {
                return $e->getMessage();
            }
            $this->thank($company, $phone, $order);

            return null;
        }

        // Juste après le merci : le mot du client complète son avis
        if ($last->event === 'conversation' && $last->created_at?->gt(now()->subMinutes(self::COMMENT_MINUTES))) {
            $rating = CourierRating::forCompany($company->id)->where('order_id', $last->order_id)
                ->where('source', CourierRating::SOURCE_RECIPIENT)->where('rater_phone', $phone)->first();
            if ($rating === null || $rating->comment !== null) {
                return false;
            }
            $rating->update(['comment' => Str::limit($text, 495)]);

            return 'C\'est noté, merci 🙏';
        }

        return false;
    }

    private function thank(Company $company, string $phone, Order $order): void
    {
        $name = $this->firstName($order->deliveryCourier);
        $this->messenger->reply($company->id, $phone,
            "Merci ! 🙏 Votre avis aide {$name} et toute l'équipe à mieux vous servir. Un mot à ajouter ? Écrivez-le simplement ici.",
            [], ['order_id' => $order->id, 'merchant_id' => $order->merchant_id, 'recipient_type' => 'recipient']);
    }

    /**
     * Course retrouvée par le lien d'avis (page publique).
     */
    public function orderForToken(string $token): ?Order
    {
        return Order::withoutGlobalScopes()->whereNull('deleted_at')->where('rating_token', $token)
            ->tap(fn ($q) => app(Tenancy::class)->restrict($q))
            ->with(['company', 'merchant' => fn ($q) => $q->withoutGlobalScopes(), 'deliveryCourier' => fn ($q) => $q->withoutGlobalScopes()->with('user')])
            ->first();
    }

    /**
     * @param  list<string>  $tags
     */
    public function record(Order $order, string $source, int $score, array $tags, ?string $comment, string $channel, ?User $user = null, ?string $phone = null): CourierRating
    {
        if ($order->status !== OrderStatus::Delivered || $order->delivery_courier_id === null) {
            throw new BusinessRuleException('Seule une course livrée peut être notée.', 'order');
        }
        if ($order->delivered_at?->lt(now()->subDays(self::RATING_DAYS))) {
            throw new BusinessRuleException('Le délai pour noter cette livraison est passé.', 'order');
        }
        if ($score < 1 || $score > 5) {
            throw new BusinessRuleException('La note va de 1 à 5.', 'score');
        }

        $tags = array_values(array_intersect($tags, array_keys(CourierRating::RECIPIENT_TAGS)));

        $already = fn () => new BusinessRuleException('Vous avez déjà noté cette livraison. Merci !', 'order');
        if (CourierRating::forCompany($order->company_id)->where('order_id', $order->id)->where('source', $source)->exists()) {
            throw $already();
        }

        try {
            // Transaction : en cas de double envoi simultané, seule la base refuse le second
            $rating = DB::transaction(fn () => CourierRating::create([
                'company_id' => $order->company_id,
                'courier_id' => $order->delivery_courier_id,
                'order_id' => $order->id,
                'source' => $source,
                'score' => $score,
                'tags' => $tags ?: null,
                'comment' => $comment !== null ? Str::limit(trim($comment), 495) : null,
                'channel' => $channel,
                'rater_phone' => $phone !== null ? (PhoneNumber::normalize($phone) ?? $phone) : null,
                'rater_user_id' => $user?->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw $already();
        }

        $this->flagIfSerious($rating);

        return $rating;
    }

    /**
     * Avis grave : signalé aux responsables (une seule fois).
     */
    private function flagIfSerious(CourierRating $rating): void
    {
        $serious = CourierRating::isSerious($rating->score, $rating->tags);
        if (! $serious || $rating->flagged) {
            return;
        }

        $rating->update(['flagged' => true]);

        $users = User::forCompany($rating->company_id)->where('status', 'active')->role([Role::Admin->value, Role::Dispatcher->value])->get();
        if ($users->isNotEmpty()) {
            Notification::send($users, new CourierRatingAlert($rating));
        }
    }

    public function cancel(CourierRating $rating, User $by, ?string $reason): CourierRating
    {
        $rating->update(['cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason, 'handled_at' => $rating->handled_at ?? now(), 'handled_by' => $rating->handled_by ?? $by->id]);

        return $rating;
    }

    public function markHandled(CourierRating $rating, User $by): CourierRating
    {
        $rating->update(['handled_at' => now(), 'handled_by' => $by->id]);

        return $rating;
    }

    /* ---------- Évaluation du mois ---------- */

    /**
     * @param  array<string, int>  $criteria
     */
    public function review(Courier $courier, User $reviewer, string $period, array $criteria, ?string $comment, bool $commentVisible): CourierReview
    {
        $criteria = array_intersect_key(array_map('intval', $criteria), CourierReview::CRITERIA);
        if ($criteria === [] || collect($criteria)->contains(fn ($v) => $v < 1 || $v > 5)) {
            throw new BusinessRuleException('Notez chaque critère de 1 à 5.', 'criteria');
        }

        return CourierReview::updateOrCreate(
            ['courier_id' => $courier->id, 'period' => CarbonImmutable::parse($period)->startOfMonth()->toDateString()],
            [
                'company_id' => $courier->company_id,
                'reviewer_id' => $reviewer->id,
                'criteria' => $criteria,
                'score' => round(array_sum($criteria) / count($criteria), 2),
                'comment' => $comment,
                'comment_visible' => $commentVisible,
            ],
        );
    }

    /* ---------- Notes ---------- */

    /**
     * Note globale et détail par source de chaque livreur (classement).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ranking(Company $company): Collection
    {
        $now = CarbonImmutable::now();
        $ratings = $this->ratingsSince($company->id, $now->subDays(self::WINDOW_DAYS + 30))->groupBy('courier_id');
        $reviews = $this->reviewsSince($company->id, $now->subDays(self::WINDOW_DAYS + 30))->groupBy('courier_id');
        $toHandle = CourierRating::forCompany($company->id)->toHandle()->selectRaw('courier_id, COUNT(*) AS n')->groupBy('courier_id')->pluck('n', 'courier_id');

        return Courier::forCompany($company->id)->with('user:id,name,status')->get()
            ->map(function (Courier $courier) use ($ratings, $reviews, $toHandle, $now, $company) {
                $own = $ratings->get($courier->id, collect());
                $mine = $reviews->get($courier->id, collect());
                $current = $this->score($own, $mine, $now);
                $before = $this->score($own, $mine, $now->subDays(30));

                return [
                    'id' => $courier->id,
                    'name' => $courier->user?->name ?? "Livreur n° {$courier->id}",
                    'active' => $courier->user?->status?->value === 'active',
                    ...$current,
                    'trend' => $current['score'] !== null && $before['score'] !== null && $before['count'] > 0 ? round($current['score'] - $before['score'], 1) : null,
                    'to_handle' => (int) ($toHandle[$courier->id] ?? 0),
                    'below_threshold' => $current['score'] !== null && $current['count'] >= self::PRIOR_WEIGHT && $current['score'] < (float) $company->rating_alert_threshold,
                ];
            })
            ->filter(fn (array $r) => $r['active'] || $r['count'] > 0)
            ->sortBy([fn ($a, $b) => ($b['score'] ?? -1) <=> ($a['score'] ?? -1), ['name', 'asc']])
            ->values();
    }

    /**
     * Fiche d'un livreur : note, détail, remarques qui reviennent, derniers avis, évaluations.
     *
     * @return array<string, mixed>
     */
    public function detail(Courier $courier): array
    {
        $now = CarbonImmutable::now();
        $ratings = $this->ratingsSince($courier->company_id, $now->subDays(self::WINDOW_DAYS + 30), $courier->id);
        $reviews = $this->reviewsSince($courier->company_id, $now->subDays(self::WINDOW_DAYS + 30), $courier->id);
        $current = $this->score($ratings, $reviews, $now);
        $before = $this->score($ratings, $reviews, $now->subDays(30));
        $window = $ratings->filter(fn (CourierRating $r) => $r->created_at->gte($now->subDays(self::WINDOW_DAYS)));

        $tags = $window->flatMap(fn (CourierRating $r) => $r->tags ?? [])->countBy()
            ->filter(fn ($n, $code) => isset(CourierRating::RECIPIENT_TAGS[$code]))
            ->map(fn ($n, $code) => ['code' => $code, ...CourierRating::RECIPIENT_TAGS[$code], 'count' => $n])
            ->sortByDesc('count')->values();

        $latest = CourierRating::forCompany($courier->company_id)->where('courier_id', $courier->id)
            ->with('order:id,tracking_code')->latest('id')->limit(30)->get()
            ->map(fn (CourierRating $r) => $this->presentRating($r));

        $history = CourierReview::forCompany($courier->company_id)->where('courier_id', $courier->id)
            ->with('reviewer:id,name')->orderByDesc('period')->limit(12)->get()
            ->map(fn (CourierReview $r) => [
                'id' => $r->id,
                'period' => $r->period->toDateString(),
                'criteria' => $r->criteria,
                'score' => $r->score,
                'comment' => $r->comment,
                'comment_visible' => $r->comment_visible,
                'reviewer' => $r->reviewer?->name,
                'updated_at' => $r->updated_at,
            ]);

        return [
            ...$current,
            'trend' => $current['score'] !== null && $before['score'] !== null && $before['count'] > 0 ? round($current['score'] - $before['score'], 1) : null,
            'tags' => $tags,
            'ratings' => $latest,
            'reviews' => $history,
            'criteria' => CourierReview::CRITERIA,
            'window_days' => self::WINDOW_DAYS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentRating(CourierRating $r): array
    {
        return [
            'id' => $r->id,
            'source' => $r->source,
            'score' => $r->score,
            'tags' => $r->tagList(),
            'comment' => $r->comment,
            'channel' => $r->channel,
            'order_id' => $r->order_id,
            'tracking_code' => $r->order?->tracking_code,
            'flagged' => $r->flagged,
            'handled_at' => $r->handled_at,
            'cancelled_at' => $r->cancelled_at,
            'cancel_reason' => $r->cancel_reason,
            'created_at' => $r->created_at,
        ];
    }

    /**
     * Note sur les 90 jours qui précèdent $at : moyenne de chaque source, puis moyenne pondérée lissée.
     *
     * @param  Collection<int, CourierRating>  $ratings
     * @param  Collection<int, CourierReview>  $reviews
     * @return array{score: float|null, count: int, sources: array<string, array{score: float|null, count: int}>}
     */
    private function score(Collection $ratings, Collection $reviews, CarbonImmutable $at): array
    {
        $from = $at->subDays(self::WINDOW_DAYS);
        $inWindow = $ratings->filter(fn (CourierRating $r) => $r->created_at->gte($from) && $r->created_at->lte($at));
        $managerReviews = $reviews->filter(fn (CourierReview $r) => $r->period->gte($from->startOfMonth()) && $r->period->lte($at));

        $sources = [
            'recipient' => $inWindow->where('source', CourierRating::SOURCE_RECIPIENT)->pluck('score'),
            'merchant' => $inWindow->where('source', CourierRating::SOURCE_MERCHANT)->pluck('score'),
            'manager' => $managerReviews->pluck('score'),
        ];

        $weighted = 0.0;
        $weights = 0.0;
        $count = 0;
        $detail = [];
        foreach ($sources as $source => $scores) {
            $avg = $scores->isEmpty() ? null : (float) $scores->avg();
            $detail[$source] = ['score' => $avg !== null ? round($avg, 1) : null, 'count' => $scores->count()];
            if ($avg !== null) {
                $weighted += self::WEIGHTS[$source] * $avg;
                $weights += self::WEIGHTS[$source];
                $count += $scores->count();
            }
        }

        $score = $weights > 0
            ? (self::PRIOR_WEIGHT * self::PRIOR_SCORE + $count * ($weighted / $weights)) / (self::PRIOR_WEIGHT + $count)
            : null;

        return ['score' => $score !== null ? round($score, 1) : null, 'count' => $count, 'sources' => $detail];
    }

    /**
     * @return Collection<int, CourierRating>
     */
    private function ratingsSince(int $companyId, CarbonImmutable $since, ?int $courierId = null): Collection
    {
        return CourierRating::forCompany($companyId)->counted()->where('created_at', '>=', $since)
            ->when($courierId, fn ($q) => $q->where('courier_id', $courierId))
            ->get(['id', 'courier_id', 'source', 'score', 'tags', 'created_at']);
    }

    /**
     * @return Collection<int, CourierReview>
     */
    private function reviewsSince(int $companyId, CarbonImmutable $since, ?int $courierId = null): Collection
    {
        return CourierReview::forCompany($companyId)->where('period', '>=', $since->startOfMonth()->toDateString())
            ->when($courierId, fn ($q) => $q->where('courier_id', $courierId))
            ->get(['id', 'courier_id', 'period', 'score']);
    }

    private function firstName(?Courier $courier): string
    {
        return strtok((string) $courier?->user?->name, ' ') ?: 'votre livreur';
    }
}
