<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string $id
 * @property string $account_set_id
 * @property string $connected_account_id
 */
class AccountSetMember extends Pivot
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'account_set_members';
}
