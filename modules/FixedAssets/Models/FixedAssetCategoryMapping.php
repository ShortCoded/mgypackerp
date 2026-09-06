<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Company;

class FixedAssetCategoryMapping extends Model
{
    protected $fillable = [
        'company_id',
        'asset_group_account_id',
        'accumulated_depreciation_account_id',
        'depreciation_expense_account_id',
        'disposal_gain_account_id',
        'disposal_loss_account_id',
        'disposal_clearing_account_id',
        'created_by',
        'updated_by',
    ];

    public const DepreciationAccounts = ['accumulated_depreciation_account_id', 'depreciation_expense_account_id'];

    /** @return array<string, array{0: ?string, 1: ?string, 2: list<string>}> */
    private static function accountRules(): array
    {
        return [
            'accumulated_depreciation_account_id' => ['asset', 'credit', ['accumulated_depreciation']],
            'depreciation_expense_account_id' => ['expense', 'debit', ['depreciation_expense', 'factory_depreciation_expense']],
            'disposal_gain_account_id' => ['revenue', 'credit', ['gain_on_asset_disposal']],
            'disposal_loss_account_id' => ['expense', 'debit', ['loss_on_asset_disposal']],
            'disposal_clearing_account_id' => [null, null, []],
        ];
    }

    /** @param list<string>|null $fields */
    public function assertValidAccounts(?array $fields = null): void
    {
        foreach ($fields ?? array_keys(array_filter($this->only(array_keys(self::accountRules())))) as $field) {
            $account = Account::query()->where('company_id', $this->company_id)->whereKey($this->{$field})->with('classification')->first();
            if (! $account || ! self::validAccount($account, $field)) {
                throw new \DomainException(__('fixed_assets.prerequisites.account_required', ['account' => self::accountLabel($field)]));
            }
        }
    }

    public static function accountLabel(string $field): string
    {
        return __('fixed_assets.lifecycle.mapping_fields.'.str_replace('_id', '_doc_num', $field));
    }

    private static function validAccount(Account $account, string $field): bool
    {
        [$type, $balance, $codes] = self::accountRules()[$field];

        return ! $account->trashed() && $account->status === 'active' && ! $account->is_group && $account->is_postable
            && (! $type || ($account->account_type === $type && $account->normal_balance === $balance && $account->classification?->account_type === $type))
            && ($field !== 'accumulated_depreciation_account_id' || in_array($account->classification?->code, $codes, true));
    }

    /**
     * Resolve only accounts required by the operation. Returned defaults are never persisted.
     *
     * @param  list<string>  $fields
     * @param  Collection<int, Account>|null  $chart
     */
    public static function resolveForAsset(FixedAsset $asset, array $fields, ?Collection $chart = null): self
    {
        $resolved = $asset->categoryMapping ? clone $asset->categoryMapping : new self(['company_id' => $asset->company_id, 'asset_group_account_id' => $asset->asset_group_account_id]);
        if ($fields === []) {
            return $resolved;
        }
        $chart ??= Account::query()->where('company_id', $asset->company_id)->with('classification')->get()->keyBy('id');
        $chart = $chart->filter(fn (Account $account): bool => (int) $account->company_id === (int) $asset->company_id);
        $asset->loadMissing('costMovements', 'postedDepreciations');
        foreach ($fields as $field) {
            $historicalId = $field === 'accumulated_depreciation_account_id'
                ? ($asset->costMovements->where('status', 'posted')->pluck('snapshot.accumulated_account_id')->filter()->first()
                    ?: $asset->postedDepreciations->sortByDesc('period_end')->pluck('accumulated_account_id')->filter()->first()) : null;
            $id = $historicalId ?: $resolved->{$field};
            if ($id) {
                $account = $chart->get((int) $id);
                if (! $account || ! self::validAccount($account, $field)) {
                    throw new \DomainException(__('fixed_assets.prerequisites.account_required', ['account' => self::accountLabel($field)]));
                }
            } else {
                $codes = self::accountRules()[$field][2];
                $candidates = $chart->filter(function (Account $candidate) use ($chart, $codes, $field): bool {
                    if (! self::validAccount($candidate, $field)) {
                        return false;
                    }
                    $node = $candidate;
                    $visited = [];
                    while ($node && ! isset($visited[$node->getKey()])) {
                        $visited[$node->getKey()] = true;
                        if (in_array($node->classification?->code, $codes, true)) {
                            return true;
                        }
                        $node = $chart->get((int) $node->parent_id);
                    }

                    return false;
                });
                $categoryCandidates = $candidates->filter(function (Account $candidate) use ($asset, $chart): bool {
                    $visited = [];
                    $node = $candidate;
                    while ($node && ! isset($visited[$node->getKey()])) {
                        $visited[$node->getKey()] = true;
                        if ((int) $node->getKey() === (int) $asset->asset_group_account_id) {
                            return true;
                        }
                        $node = $chart->get((int) $node->parent_id);
                    }

                    return false;
                });
                if ($categoryCandidates->isNotEmpty()) {
                    $candidates = $categoryCandidates;
                }
                if ($candidates->count() !== 1) {
                    throw new \DomainException(__($candidates->isEmpty() ? 'fixed_assets.prerequisites.account_required' : 'fixed_assets.prerequisites.account_ambiguous', ['account' => self::accountLabel($field)]));
                }
                $account = $candidates->first();
            }
            $resolved->{$field} = $account->getKey();
            $relation = match ($field) {
                'accumulated_depreciation_account_id' => 'accumulatedDepreciationAccount',
                'depreciation_expense_account_id' => 'depreciationExpenseAccount',
                'disposal_gain_account_id' => 'disposalGainAccount',
                'disposal_loss_account_id' => 'disposalLossAccount',
                'disposal_clearing_account_id' => 'disposalClearingAccount',
            };
            $resolved->setRelation($relation, $account);
        }

        return $resolved;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assetGroupAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_group_account_id')->withTrashed();
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id')->withTrashed();
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id')->withTrashed();
    }

    public function disposalGainAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_gain_account_id')->withTrashed();
    }

    public function disposalLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_loss_account_id')->withTrashed();
    }

    public function disposalClearingAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_clearing_account_id')->withTrashed();
    }
}
