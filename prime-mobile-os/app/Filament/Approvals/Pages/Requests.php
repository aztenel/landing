<?php

namespace App\Filament\Approvals\Pages;

use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Approvals\Services\ApprovalQueries;
use App\Domain\Approvals\Services\ApprovalService;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Money;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;

/**
 * Э28.3 «Заявки» (/requests): the owner (and deciders) see waiting requests as big cards — «Разрешить»,
 * «Изменить и разрешить» (a smaller discount / debt), «Отклонить» with a comment; a request above 300 000 ₸ (except
 * discounts) asks for the password first (TZ 03 §6.3 p.3). Everybody sees «Мои заявки».
 */
class Requests extends Page
{
    use HasErpAccess;

    protected static ?string $navigationIcon = 'request';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'requests';

    protected static string $view = 'approvals.pages.requests';

    /** @var array<int, array{comment: string, value: string, password: string}> */
    public array $f = [];

    public ?string $error = null;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function getNavigationLabel(): string
    {
        return __('Заявки');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = auth()->user() ? app(ApprovalQueries::class)->awaitingCount(auth()->user()) : 0;

        return $n ? (string) $n : null;
    }

    public function getTitle(): string
    {
        return __('Заявки');
    }

    public function decide(int $id, string $decision): void
    {
        $this->error = null;
        $r = ApprovalRequest::query()->findOrFail($id);
        $f = $this->f[$id] ?? ['comment' => '', 'value' => '', 'password' => ''];
        try {
            $confirmed = false;
            if ($decision !== 'reject' && $r->needs_confirmation) {
                if (! Hash::check((string) ($f['password'] ?? ''), auth()->user()->password)) {
                    $this->error = __('Введите свой пароль для подтверждения крупной заявки');

                    return;
                }
                $confirmed = true;
            }
            $svc = app(ApprovalService::class);
            if ($decision === 'reject') {
                $svc->reject($r, auth()->user(), trim((string) ($f['comment'] ?? '')) ?: null);
            } else {
                $changes = [];
                $value = (int) preg_replace('/\D/', '', (string) ($f['value'] ?? ''));
                if ($decision === 'change' && $value > 0) {
                    $changes[in_array('discount', $r->kinds(), true) ? 'discount' : 'debt'] = Money::kzt($value);
                }
                $svc->approve($r, auth()->user(), $changes, trim((string) ($f['comment'] ?? '')) ?: null, $confirmed);
            }
            unset($this->f[$id]);
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function cancel(int $id): void
    {
        try {
            app(ApprovalService::class)->cancel(ApprovalRequest::query()->where('requested_by', auth()->id())->findOrFail($id), auth()->user());
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function getViewData(): array
    {
        $q = app(ApprovalQueries::class);

        return [
            'awaiting' => $q->awaiting(auth()->user()),
            'mine' => $q->mine(auth()->user())->take(20),
            'users' => \App\Models\User::query()->pluck('name', 'id'),
            'statuses' => ['pending' => __('ждёт'), 'approved' => __('разрешено'), 'rejected' => __('отклонено'), 'cancelled' => __('отменена'), 'expired' => __('истекла'), 'used' => __('использована'), 'lapsed' => __('не использована вовремя')],
        ];
    }
}
