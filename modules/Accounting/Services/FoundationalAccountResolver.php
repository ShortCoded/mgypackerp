<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;

class FoundationalAccountResolver
{
    public function resolve(
        int $companyId,
        string $classificationCode,
        string $missingMessageKey,
        string $ambiguousMessageKey,
    ): Account {
        $candidates = $this->topmostCandidates($companyId, $classificationCode);

        if ($candidates->isEmpty()) {
            throw new DomainException(__($missingMessageKey));
        }

        if ($candidates->count() > 1) {
            throw new DomainException(__($ambiguousMessageKey, [
                'classification' => $classificationCode,
                'candidates' => $candidates
                    ->map(fn (Account $candidate): string => $this->candidateSummary($candidate))
                    ->implode('; '),
            ]));
        }

        return $candidates->firstOrFail();
    }

    public function resolveConfiguredCode(
        int $companyId,
        string $classificationCode,
        string $accountCode,
        string $missingMessageKey,
        string $ambiguousMessageKey,
    ): Account {
        $candidates = Account::query()
            ->with('classification')
            ->forCompany($companyId)
            ->active()
            ->where('account_code', $accountCode)
            ->where('is_group', true)
            ->where('is_postable', false)
            ->whereHas('classification', fn ($query) => $query
                ->where('code', $classificationCode)
                ->where('status', 'active'))
            ->oldest('id')
            ->get();

        if ($candidates->isEmpty()) {
            throw new DomainException(__($missingMessageKey));
        }

        if ($candidates->count() > 1) {
            throw new DomainException(__($ambiguousMessageKey, [
                'classification' => $classificationCode,
                'candidates' => $candidates
                    ->map(fn (Account $candidate): string => $this->candidateSummary($candidate))
                    ->implode('; '),
            ]));
        }

        return $candidates->firstOrFail();
    }

    /**
     * @return Collection<int, Account>
     */
    public function topmostCandidates(int $companyId, string $classificationCode): Collection
    {
        $classification = AccountClassification::query()
            ->where('code', $classificationCode)
            ->where('status', 'active')
            ->first();

        if (! $classification instanceof AccountClassification) {
            return collect();
        }

        $eligibleGroups = Account::query()
            ->with('classification')
            ->forCompany($companyId)
            ->active()
            ->where('is_group', true)
            ->where('is_postable', false)
            ->whereHas('classification', fn ($query) => $query
                ->where('code', $classificationCode)
                ->where('status', 'active'))
            ->oldest('id')
            ->get()
            ->filter(fn (Account $account): bool => $this->matchesFoundationalLabel(
                $account,
                $classification,
                $classificationCode,
            ))
            ->values();
        $eligibleIds = $eligibleGroups
            ->map(fn (Account $account): int => (int) $account->getKey())
            ->all();
        $parentIds = Account::withTrashed()
            ->forCompany($companyId)
            ->pluck('parent_id', 'id');

        return $eligibleGroups
            ->reject(fn (Account $account): bool => $this->hasEligibleAncestor($account, $eligibleIds, $parentIds))
            ->values();
    }

    /**
     * @return array{id: int, doc_num: string|null, code: string, name: string, name_en: string|null, parent_path: string}
     */
    public function candidateDetails(Account $account): array
    {
        return [
            'id' => (int) $account->getKey(),
            'doc_num' => $account->doc_num,
            'code' => (string) $account->account_code,
            'name' => (string) $account->name,
            'name_en' => $account->name_en,
            'parent_path' => $this->path($account),
        ];
    }

    public function path(Account $account): string
    {
        $accounts = Account::withTrashed()
            ->forCompany((int) $account->company_id)
            ->get(['id', 'account_code', 'name', 'parent_id'])
            ->keyBy(fn (Account $candidate): int => (int) $candidate->getKey());
        $segments = [];
        $current = $accounts->get((int) $account->getKey(), $account);
        $visitedIds = [];

        while ($current instanceof Account && ! in_array((int) $current->getKey(), $visitedIds, true)) {
            $visitedIds[] = (int) $current->getKey();
            array_unshift($segments, Account::codeNameLabelFor($current->account_code, $current->name));
            $current = $current->parent_id === null
                ? null
                : $accounts->get((int) $current->parent_id);
        }

        return implode(' > ', $segments);
    }

    public function hasFoundationalLabel(Account $root, string $label): bool
    {
        $normalizedLabel = $this->normalizeLabel($label);

        if ($normalizedLabel === '') {
            return false;
        }

        $root->loadMissing('classification');
        $labels = [$root->name, $root->name_en];

        if ($root->classification instanceof AccountClassification) {
            $labels[] = $root->classification->name;
            $labels[] = $root->classification->name_en;

            if ($root->classification->code === AccountClassification::FixedAssets) {
                $labels = [...$labels, 'Fixed Assets', 'أصول ثابتة', 'الأصول الثابتة'];
            }
        }

        return collect($labels)
            ->filter(fn (mixed $candidate): bool => is_string($candidate) && trim($candidate) !== '')
            ->contains(fn (string $candidate): bool => $this->normalizeLabel($candidate) === $normalizedLabel);
    }

    /**
     * @param  list<int>  $eligibleIds
     * @param  Collection<int|string, int|null>  $parentIds
     */
    private function hasEligibleAncestor(Account $account, array $eligibleIds, Collection $parentIds): bool
    {
        $parentId = $account->parent_id;
        $visitedIds = [];

        while ($parentId !== null && ! in_array((int) $parentId, $visitedIds, true)) {
            $parentId = (int) $parentId;
            $visitedIds[] = $parentId;

            if (in_array($parentId, $eligibleIds, true)) {
                return true;
            }

            $parentId = $parentIds->get($parentId);
        }

        return false;
    }

    private function candidateSummary(Account $account): string
    {
        $details = $this->candidateDetails($account);

        return sprintf(
            'id=%d, doc=%s, code=%s, name=%s, path=%s',
            $details['id'],
            $details['doc_num'] ?: '-',
            $details['code'],
            $details['name'],
            $details['parent_path'],
        );
    }

    private function normalizeLabel(string $label): string
    {
        return Str::of($label)
            ->squish()
            ->lower()
            ->replaceMatches('/[^\pL\pN]+/u', '')
            ->toString();
    }

    private function matchesFoundationalLabel(
        Account $account,
        AccountClassification $classification,
        string $classificationCode,
    ): bool {
        $referenceLabels = [$classification->name, $classification->name_en];

        if ($classificationCode === AccountClassification::FixedAssets) {
            $referenceLabels = [...$referenceLabels, 'Fixed Assets', 'أصول ثابتة', 'الأصول الثابتة'];
        }

        return collect([$account->name, $account->name_en])
            ->filter(fn (mixed $label): bool => is_string($label) && trim($label) !== '')
            ->contains(fn (string $label): bool => collect($referenceLabels)
                ->filter(fn (mixed $reference): bool => is_string($reference) && trim($reference) !== '')
                ->contains(fn (string $reference): bool => $this->normalizeLabel($reference) === $this->normalizeLabel($label)));
    }
}
