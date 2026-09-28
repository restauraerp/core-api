<?php

namespace App\Models;

use App\Support\Billing\Modules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A custom set of entitlements - modules and an outlet cap - for particular
 * restaurants. See the create_packages_table migration for why this sits
 * beside a tenant's plan rather than replacing it.
 *
 * Platform-level: deliberately not BelongsToTenant.
 */
class Package extends Model
{
    protected $fillable = [
        'name',
        'description',
        'based_on',
        'modules',
        'outlets',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'outlets' => 'integer',
        ];
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    /**
     * The package's modules, known ones only and in the canonical order, so a
     * module removed from the product in a later release simply drops out.
     *
     * @return list<string>
     */
    public function moduleList(): array
    {
        return array_values(array_intersect(Modules::ALL, (array) $this->modules));
    }
}
