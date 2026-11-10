<?php

namespace App\Domain\TradeIn\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class TradeInAct extends Model
{
    use BelongsToOrganization;

    protected $table = 'tradein_acts';

    protected $guarded = ['id', 'organization_id'];

    protected $hidden = ['id_document_no'];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'replaced' => 'array', 'kit' => 'array', 'amount' => 'integer', 'grid_price' => 'integer',
            'offset_amount' => 'integer', 'imei_verified' => 'boolean', 'id_document_no' => 'encrypted', 'signed_at' => 'datetime'];
    }
}
