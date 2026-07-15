<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Core\Models\UserNavigationSearch;

class NavigationSearchService
{
    public function __construct(
        private readonly MenuService $menu,
    ) {}

    /**
     * @return array{section: string, results: list<array<string, mixed>>}
     */
    public function search(User $user, ?string $query, int $limit = 10): array
    {
        $query = trim((string) $query);

        if ($query === '') {
            return [
                'section' => 'recent',
                'results' => $this->recent($user, $limit),
            ];
        }

        $terms = $this->terms($query);

        return [
            'section' => 'results',
            'results' => $this->permittedItems($user)
                ->filter(fn (array $item): bool => $this->matches($item, $terms))
                ->take($limit)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{route_name?: string|null, url?: string|null}  $data
     */
    public function storeRecent(User $user, array $data): ?UserNavigationSearch
    {
        $item = $this->findPermittedItem($user, $data['route_name'] ?? null, $data['url'] ?? null);

        if ($item === null) {
            return null;
        }

        return UserNavigationSearch::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'url' => $item['url'],
            ],
            [
                'title' => $item['title'],
                'route_name' => $item['route_name'],
                'icon' => $item['icon'],
                'parent_path' => $item['parent_path'],
                'last_used_at' => now(),
            ],
        );
    }

    public function clearRecent(User $user): int
    {
        return UserNavigationSearch::query()
            ->forUser($user)
            ->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recent(User $user, int $limit): array
    {
        $permittedByUrl = $this->permittedItems($user)->keyBy('url');

        return UserNavigationSearch::query()
            ->forUser($user)
            ->latest('last_used_at')
            ->limit($limit * 2)
            ->get()
            ->map(fn (UserNavigationSearch $recent): ?array => $permittedByUrl->get($recent->url))
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function permittedItems(User $user): Collection
    {
        return collect($this->flatten($this->menu->getMenu($user)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  list<string>  $parents
     * @return list<array<string, mixed>>
     */
    private function flatten(array $items, array $parents = []): array
    {
        $results = [];

        foreach ($items as $item) {
            $title = (string) ($item['text'] ?? $this->translatedLabel($item, app()->getLocale()));
            $routeName = $item['route'] ?? null;
            $url = (string) ($item['url'] ?? '#!');
            $parentPath = implode(' / ', $parents);
            $children = is_array($item['children'] ?? null) ? $item['children'] : [];

            if (is_string($routeName) && $routeName !== '' && Route::has($routeName) && $this->isSafeLocalUrl($url)) {
                $results[] = [
                    'title' => $title,
                    'url' => $url,
                    'route_name' => $routeName,
                    'icon' => (string) ($item['icon_class'] ?? 'fas fa-circle'),
                    'parent_path' => $parentPath,
                    'search_text' => $this->searchText($item, $title, $parentPath),
                ];
            }

            if ($children !== []) {
                $results = array_merge($results, $this->flatten($children, [...$parents, $title]));
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function searchText(array $item, string $title, string $parentPath): string
    {
        $locales = array_keys((array) config('languages.available', [])) ?: [app()->getLocale(), config('app.fallback_locale')];
        $labels = collect($locales)
            ->map(fn (string $locale): string => $this->translatedLabel($item, $locale))
            ->filter()
            ->all();
        $keywords = Arr::wrap($item['keywords'] ?? $item['search_terms'] ?? []);

        return $this->normalize(implode(' ', array_filter([
            $title,
            $parentPath,
            (string) ($item['label'] ?? ''),
            (string) ($item['route'] ?? ''),
            implode(' ', $labels),
            implode(' ', $keywords),
        ])));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function translatedLabel(array $item, string $locale): string
    {
        $label = (string) ($item['label'] ?? '');
        $key = "menu.{$label}";

        if ($label !== '') {
            $translated = Lang::get($key, [], $locale);

            if ($translated !== $key) {
                return (string) $translated;
            }
        }

        return (string) ($item['title'] ?? Str::headline($label));
    }

    /**
     * @param  list<string>  $terms
     */
    private function matches(array $item, array $terms): bool
    {
        $haystack = (string) ($item['search_text'] ?? '');

        foreach ($terms as $term) {
            if (! str_contains($haystack, $term)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function terms(string $query): array
    {
        return collect(preg_split('/\s+/u', $this->normalize($query)) ?: [])
            ->filter()
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function findPermittedItem(User $user, mixed $routeName, mixed $url): ?array
    {
        $routeName = is_string($routeName) ? trim($routeName) : '';
        $url = is_string($url) ? trim($url) : '';

        if ($url !== '' && ! $this->isSafeLocalUrl($url)) {
            return null;
        }

        return $this->permittedItems($user)
            ->first(function (array $item) use ($routeName, $url): bool {
                if ($routeName !== '' && $item['route_name'] === $routeName) {
                    return true;
                }

                return $url !== '' && $item['url'] === $url;
            });
    }

    private function isSafeLocalUrl(string $url): bool
    {
        return $url !== ''
            && str_starts_with($url, url('/'))
            && ! str_contains($url, "\n")
            && ! str_contains($url, "\r");
    }
}
