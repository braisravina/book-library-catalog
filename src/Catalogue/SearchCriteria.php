<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

use DigitalLibrary\Http\Request;

final class SearchCriteria
{
    public const SORTS = ['title', 'recent', 'year'];
    public const VIEWS = ['grid', 'list'];
    public const PER_PAGE = 24;

    public function __construct(
        public readonly string $query = '',
        public readonly ?int $categoryId = null,
        public readonly ?string $language = null,
        public readonly ?int $ownerId = null,
        public readonly ?int $locationId = null,
        public readonly string $sort = 'title',
        public readonly string $view = 'grid',
        public readonly int $page = 1,
    ) {
    }

    /**
     * Unknown or malformed values fall back to defaults rather than erroring:
     * these come from bookmarkable GET URLs.
     */
    public static function fromRequest(Request $request): self
    {
        $int = static function (?string $v): ?int {
            return $v !== null && preg_match('/^[1-9]\d{0,9}$/', $v) ? (int) $v : null;
        };
        $language = $request->query('language');
        $sort = (string) $request->query('sort', 'title');
        $view = (string) $request->query('view', 'grid');

        return new self(
            mb_substr(trim((string) $request->query('q', '')), 0, 200),
            $int($request->query('category')),
            $language !== null && in_array($language, Vocabulary::LANGUAGES, true) ? $language : null,
            $int($request->query('owner')),
            $int($request->query('location')),
            in_array($sort, self::SORTS, true) ? $sort : 'title',
            in_array($view, self::VIEWS, true) ? $view : 'grid',
            min(10_000, $int($request->query('page')) ?? 1),
        );
    }

    public function hasFilters(): bool
    {
        return $this->query !== '' || $this->categoryId !== null || $this->language !== null
            || $this->ownerId !== null || $this->locationId !== null;
    }

    /**
     * @param array<string, scalar|null> $overrides
     *
     * @return array<string, scalar|null>
     */
    public function toQuery(array $overrides = []): array
    {
        return array_merge([
            'q' => $this->query,
            'category' => $this->categoryId,
            'language' => $this->language,
            'owner' => $this->ownerId,
            'location' => $this->locationId,
            'sort' => $this->sort === 'title' ? null : $this->sort,
            'view' => $this->view === 'grid' ? null : $this->view,
            'page' => $this->page > 1 ? $this->page : null,
        ], $overrides);
    }
}
