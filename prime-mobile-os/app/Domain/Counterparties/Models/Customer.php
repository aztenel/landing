<?php

namespace App\Domain\Counterparties\Models;

use App\Support\Crypto\BlindIndex;
use App\Support\Iin\Iin;
use App\Support\Phone\Phone;
use App\Support\Tenancy\BelongsToOrganization;
use App\Support\Tenancy\TracksCreator;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Customer of the organization. Phone unique in the organization. IIN (only for trade-in sellers) is stored
 * encrypted (APP_KEY, outside the DB) with a blind index for search and the last 4 digits for the mask
 * (TZ 03 §17). Never put `iin` into arrays sent to the browser of a role without customer.view_iin.
 *
 * @property string|null $iin decrypted on read
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToOrganization, HasFactory, TracksCreator;

    protected $fillable = ['organization_id', 'phone', 'name', 'iin', 'pd_consent_at', 'pd_consent_method', 'pd_consent_version', 'source', 'comment', 'created_by'];

    protected $hidden = ['iin', 'iin_hash'];

    protected function casts(): array
    {
        return [
            'iin' => 'encrypted',
            'pd_consent_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            if ($customer->isDirty('phone') && $customer->phone !== null) {
                $customer->phone = Phone::normalize($customer->phone);
            }
            if ($customer->isDirty('iin')) {
                $iin = $customer->iin === null ? null : Iin::normalize($customer->iin);
                if ($iin !== null && ! Iin::isValid($iin)) {
                    throw new InvalidArgumentException('ИИН неверный: 12 цифр и контрольная цифра');
                }
                $customer->iin = $iin;
                $customer->iin_hash = $iin ? BlindIndex::make($iin) : null;
                $customer->iin_last4 = $iin ? substr($iin, -4) : null;
            }
        });
    }

    public function scopeWhereIin(Builder $query, string $iin): Builder
    {
        return $query->where('iin_hash', BlindIndex::make(Iin::normalize($iin)));
    }

    public function scopeWherePhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone', Phone::normalize($phone));
    }

    public function maskedIin(): ?string
    {
        return $this->iin_last4 ? Iin::mask($this->iin_last4) : null;
    }

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }
}
