<?php

namespace App\Filament\TradeIn\Pages;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Pos\Services\CartService;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\TradeIn\Drafts\TradeInDraft;
use App\Domain\TradeIn\Services\TradeInGrid;
use App\Filament\Pos\Pages\Sale as SalePage;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Iin\Iin;
use App\Support\Imei\Imei;
use App\Support\Money\Money;
use App\Support\Phone\Phone;
use App\Support\Ui\Access;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;

/**
 * Э6 «Акт trade-in» (/tradein/new, from the cash register /tradein/new?tab=N): one page, 5 steps — device and IMEI,
 * checklist, grade, the sum (grid hint), who hands it in and how we pay. «Провести» (buy-back for money) or
 * «Добавить в чек» (offset into the current check; the act is posted together with the sale). Over the grid — a
 * request to the owner first.
 */
class NewAct extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = 'tradein.create';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'tradein/new';

    protected static string $view = 'tradein.pages.new';

    public const CHECKS = [
        'icloud' => 'iCloud / «Локатор» (Android — FRP) выключен', 'screen' => 'Экран без дефектов', 'body' => 'Корпус без повреждений',
        'water' => 'Индикатор влаги чистый', 'biometrics' => 'Face ID / Touch ID', 'cameras' => 'Камеры', 'audio' => 'Динамики и микрофон',
        'charging' => 'Зарядка', 'buttons' => 'Кнопки', 'wireless' => 'Wi-Fi / Bluetooth', 'sim' => 'SIM', 'not_pledged' => 'Не в залоге / рассрочке (со слов)',
        'owner' => 'Сдающий — собственник (со слов)',
    ];

    public ?int $tab = null;

    public string $variantQuery = '';

    public ?int $variantId = null;

    public string $imei1 = '';

    public string $imei2 = '';

    public bool $imeiVerified = false;

    public string $imeiCheck = '';

    /** @var array<string, string> */
    public array $checklist = [];

    public string $battery = '';

    public array $replaced = [];

    public array $kit = [];

    public string $grade = 'B';

    public string $amount = '';

    public array $person = ['phone' => '', 'name' => '', 'iin' => '', 'doc' => '', 'consent' => false];

    public string $mode = 'cash';   // offset | cash | kaspi | split

    public string $cashPart = '';

    public string $kaspiRef = '';

    public ?int $actId = null;

    public ?int $requestId = null;

    public ?int $postedId = null;

    public ?string $error = null;

    public function getTitle(): string
    {
        return __('Акт trade-in');
    }

    public function mount(): void
    {
        $this->tab = request()->integer('tab') ?: null;
        $this->mode = $this->tab ? 'offset' : 'cash';
    }

    public function updated(string $name): void
    {
        $this->actId = $this->requestId = null; // a request covers exactly the act it was asked for
    }

    public function pickVariant(int $id): void
    {
        $v = Variant::query()->findOrFail($id);
        $this->variantId = $v->id;
        $this->variantQuery = $v->title();
    }

    /** «Этот телефон мы продавали …» (TZ 04 Э6 шаг 1). */
    public function knownUnit(): ?array
    {
        $imei = Imei::normalize($this->imei1);
        if (! preg_match('/^\d{15}$/', $imei)) {
            return null;
        }
        $unit = StockUnit::findByIdentifier($imei);
        if (! $unit) {
            return null;
        }
        $line = SaleLine::query()->where('stock_unit_id', $unit->id)->latest('id')->first();
        $doc = $line ? Document::query()->find($line->document_id) : null;
        $customer = $doc ? Customer::query()->find(Sale::query()->where('document_id', $doc->id)->value('customer_id')) : null;

        return ['unit' => $unit, 'sold_at' => $doc?->posted_at, 'customer' => $customer?->name, 'sold' => $unit->status?->value === 'sold'];
    }

    public function quote(): ?array
    {
        if (! $this->variantId) {
            return null;
        }

        return app(TradeInGrid::class)->quote($this->variantId, ['grade' => $this->grade, 'battery' => $this->battery === '' ? null : (int) $this->battery,
            'replaced' => $this->replaced, 'kit' => $this->kit, 'body_damage' => ($this->checklist['body'] ?? null) === 'no']);
    }

    /** What still blocks «Провести» (one reason, the first). */
    public function blocker(): ?string
    {
        return match (true) {
            ! $this->variantId => __('Выберите модель'),
            ! Imei::isValid($this->imei1) => __('Введите IMEI 1 (15 цифр, контрольная цифра)'),
            ! $this->imeiVerified => __('Сверьте IMEI с *#06# на телефоне клиента'),
            in_array($this->imeiCheck, ['not_registered', 'blocked'], true) => __('Принимать нельзя: телефон не прошёл госбазу'),
            $this->imeiCheck !== 'registered' => __('Проверьте телефон в госбазе'),
            ($this->checklist['icloud'] ?? null) === 'no' => __('С включённым iCloud телефон не принимаем'),
            count(array_filter($this->checklist, fn ($v) => in_array($v, ['yes', 'no', 'skip'], true))) < count(self::CHECKS) => __('Отметьте все пункты чек-листа'),
            (int) preg_replace('/\D/', '', $this->amount) <= 0 => __('Введите сумму'),
            trim($this->person['name']) === '' || ! Iin::isValid((string) $this->person['iin']) => __('ФИО и ИИН (12 цифр, с контрольной цифрой)'),
            ! $this->person['consent'] => __('Нужно согласие на обработку данных'),
            default => null,
        };
    }

    protected function customer(): Customer
    {
        try {
            $phone = Phone::normalize((string) $this->person['phone']);
        } catch (\InvalidArgumentException) {
            throw \App\Domain\Ledger\Exceptions\PostingException::because('bad_phone', __('Телефон в формате +7 7XX XXX XX XX'));
        }
        $customer = Customer::query()->firstOrNew(['phone' => $phone]);
        if (! $customer->exists) {
            $customer->fill(['name' => trim($this->person['name']), 'source' => 'tradein', 'created_by' => auth()->id()]);
        }
        if (! $customer->iin) {
            $customer->iin = Iin::normalize((string) $this->person['iin']);
        }
        $customer->pd_consent_at ??= now();
        $customer->pd_consent_method ??= 'tradein_signature';
        $customer->save();

        return $customer;
    }

    protected function draft(): Document
    {
        if ($this->actId && ($d = Document::query()->find($this->actId)) && $d->isDraft()) {
            return $d;
        }
        $store = WorkingStore::get(auth()->user());
        $amount = (int) preg_replace('/\D/', '', $this->amount);
        $offset = 0;
        if ($this->mode === 'offset' && $this->tab) {
            $cart = app(CartService::class)->cart(auth()->user(), $store, $this->tab);
            $t = app(CartService::class)->totals($cart);
            $offset = min($amount, intdiv($t['total'] - (int) collect($cart->get('payments'))->sum('amount'), Money::TENGE));
            if ($offset <= 0) {
                throw \App\Domain\Ledger\Exceptions\PostingException::because('empty_cart', __('В чеке нечего зачитывать — это выкуп за деньги'));
            }
        }
        $quote = $this->quote();
        $d = TradeInDraft::make($store, $this->customer(), $this->variantId, Imei::normalize($this->imei1), Money::kzt($amount))
            ->imei2($this->imei2 !== '' ? Imei::normalize($this->imei2) : null)->grade($this->grade)
            ->battery($this->battery === '' ? null : (int) $this->battery)->checklist($this->checklist)->replaced($this->replaced)->kit($this->kit)
            ->imeiCheck($this->imeiCheck, $this->imeiVerified)->gridPrice($quote['price'] ?? null)->idDocument(trim($this->person['doc']) ?: null)
            ->author(auth()->user());
        $rest = $amount - $offset;
        if ($offset > 0) {
            $d->offset(Money::kzt($offset));
        }
        if ($rest > 0) {
            $cash = $this->mode === 'split' ? min($rest, (int) preg_replace('/\D/', '', $this->cashPart)) : ($this->mode === 'kaspi' ? 0 : $rest);
            if ($cash > 0) {
                $d->payout('cash', Money::kzt($cash));
            }
            if ($rest - $cash > 0) {
                $d->payout('kaspi_transfer', Money::kzt($rest - $cash), trim($this->kaspiRef) ?: null);
            }
        }
        $doc = $d->save();
        $this->actId = $doc->id;

        return $doc;
    }

    /** Buy-back for money: post now. Offset: check the approvals, then put it into the check. */
    public function submit(): void
    {
        $this->error = $this->blocker();
        if ($this->error) {
            return;
        }
        try {
            $act = $this->draft();
            if ($this->mode === 'offset') {
                $this->toCheck($act);

                return;
            }
            $this->postedId = app(Poster::class)->post($act, auth()->user())->id;
        } catch (\App\Domain\Tenancy\Access\Exceptions\ApprovalRequired $e) {
            $this->error = $e->getMessage();
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function toCheck(Document $act): void
    {
        try {
            // over the grid / above a limit: the request comes first; nothing needed — straight into the check
            $this->requestId = app(ApprovalService::class)->requestForDocument($act, auth()->user(), __('Trade-in выше сетки'))->id;
            $this->error = __('Нужна заявка владельцу — отправлена. Ждём ответа…');
        } catch (ApprovalException $e) {
            if ($e->reason !== 'not_needed') {
                throw $e;
            }
            $this->attach($act);
        }
    }

    protected function attach(Document $act): void
    {
        $user = auth()->user();
        $svc = app(CartService::class);
        $svc->attachTradeIn($svc->cart($user, WorkingStore::get($user), $this->tab), $act);
        $this->redirect(SalePage::getUrl(['tab' => $this->tab]));
    }

    public function sendRequest(): void
    {
        try {
            $this->requestId = app(ApprovalService::class)->requestForDocument($this->draft(), auth()->user(), __('Trade-in выше сетки'))->id;
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function poll(): void
    {
        $r = $this->requestId ? ApprovalRequest::query()->find($this->requestId) : null;
        if (! $r || $r->status !== ApprovalStatus::Approved) {
            return;
        }
        $act = Document::query()->findOrFail($this->actId);
        $this->mode === 'offset' ? $this->attach($act) : $this->submit();
    }

    protected function getViewData(): array
    {
        $vq = trim($this->variantQuery);

        return [
            'variants' => mb_strlen($vq) >= 2 && ! $this->variantId ? Variant::query()->with(['productModel', 'memory', 'color'])
                ->whereHas('productModel', fn ($m) => $m->where('name_ru', 'ilike', '%'.addcslashes($vq, '%_').'%'))->limit(12)->get() : collect(),
            'known' => $this->knownUnit(),
            'quote' => $this->quote(),
            'blocker' => $this->blocker(),
            'request' => $this->requestId ? ApprovalRequest::query()->find($this->requestId) : null,
            'posted' => $this->postedId ? Document::query()->find($this->postedId) : null,
            'iinOk' => $this->person['iin'] === '' || Iin::isValid((string) $this->person['iin']),
            'canHint' => Access::allows('tradein.hint'),
        ];
    }
}
