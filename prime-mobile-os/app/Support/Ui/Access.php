<?php

namespace App\Support\Ui;

use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\AccessDecision;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Domain\Tenancy\Access\Limit;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Auth;

/**
 * Screen-level access (TZ 03 §18 p.1–2) on top of AccessChecker, cached for the current request.
 *
 *   Access::sees('stock.view_cost')        — show the menu item / column / button (right exists, maybe «only via approval»)
 *   Access::allows('rate.set')             — may do it right now without approval
 *   Access::authorize('org.settings')      — server-side guard in a Livewire/Filament action: AccessDenied / ApprovalRequired
 *
 * A permission argument may be a list: the user needs ANY of them. Membership status, store scope and the
 * firm context are taken into account (AccessChecker); no context or no user → nothing is visible.
 */
final class Access
{
    /** @var array<string, AccessDecision> */
    private array $cache = [];

    public function __construct(private readonly AccessChecker $checker) {}

    public static function instance(): self
    {
        return app(self::class);
    }

    /** @param string|list<string>|null $permission */
    public static function sees(string|array|null $permission, ?User $user = null): bool
    {
        if ($permission === null) {
            return self::instance()->member($user);
        }
        foreach ((array) $permission as $code) {
            if (! self::instance()->decision($code, $user)->isDenied()) {
                return true;
            }
        }

        return false;
    }

    /** @param string|list<string> $permission */
    public static function allows(string|array $permission, ?User $user = null): bool
    {
        foreach ((array) $permission as $code) {
            if (self::instance()->decision($code, $user)->isAllowed()) {
                return true;
            }
        }

        return false;
    }

    public static function isOwner(?User $user = null): bool
    {
        $user ??= Auth::user();

        return $user instanceof User && CurrentOrganization::instance()->has() && app(AccessChecker::class)->isOwner($user);
    }

    /**
     * Server-side check for an action. Denied → AccessDenied («Нет права: …» or $message);
     * «only via approval» / over the limit → ApprovalRequired (the Approvals module turns it into a request).
     */
    public static function authorize(string $permission, ?string $message = null, ?Limit $limit = null, ?int $value = null, ?User $user = null): void
    {
        $user ??= Auth::user();
        if (! $user instanceof User || ! CurrentOrganization::instance()->has()) {
            throw AccessDenied::because('access_denied', __('Доступ закрыт. Обратитесь к владельцу'));
        }
        $decision = app(AccessChecker::class)->decide($user, $permission, $limit, $value);
        if ($decision->isDenied()) {
            throw AccessDenied::because('access_denied', $message ?? $decision->message);
        }
        if ($decision->needsApproval()) {
            throw new ApprovalRequired($decision, $permission);
        }
    }

    public function decision(string $permission, ?User $user = null): AccessDecision
    {
        $user ??= Auth::user();
        $org = CurrentOrganization::instance()->id();
        if (! $user instanceof User || $org === null) {
            return AccessDecision::denied($permission, __('Доступ закрыт. Обратитесь к владельцу'));
        }
        $key = $org.':'.$user->getKey().':'.$permission;

        return $this->cache[$key] ??= $this->checker->decide($user, $permission);
    }

    /** Forget cached answers (after a role/membership change inside the same request). */
    public function flush(): void
    {
        $this->cache = [];
    }

    private function member(?User $user): bool
    {
        $user ??= Auth::user();
        $org = CurrentOrganization::instance()->id();

        return $user instanceof User && $org !== null && $user->hasActiveMembershipIn($org);
    }
}
