<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Catalogue;

use DigitalLibrary\Catalogue\SearchCriteria;
use DigitalLibrary\Database\Params;
use DigitalLibrary\Http\Request;
use PHPUnit\Framework\TestCase;

final class SearchCriteriaTest extends TestCase
{
    public function testMalformedValuesFallBackToDefaults(): void
    {
        $c = SearchCriteria::fromRequest(new Request('GET', '/books', [
            'q' => '  rayuela  ', 'category' => '1 OR 1=1', 'language' => 'klingon', 'owner' => '-3',
            'location' => '0', 'sort' => 'DROP', 'view' => 'table', 'page' => '999999999999',
        ]));

        self::assertSame('rayuela', $c->query);
        self::assertNull($c->categoryId);
        self::assertNull($c->language);
        self::assertNull($c->ownerId);
        self::assertNull($c->locationId);
        self::assertSame('title', $c->sort);
        self::assertSame('grid', $c->view);
        self::assertSame(1, $c->page);
    }

    public function testValidValuesAndQueryRoundTrip(): void
    {
        $c = SearchCriteria::fromRequest(new Request('GET', '/books', ['category' => '4', 'language' => 'gl', 'sort' => 'recent', 'view' => 'list', 'page' => '3']));
        self::assertSame(4, $c->categoryId);
        self::assertSame('gl', $c->language);
        self::assertTrue($c->hasFilters());
        self::assertSame(['q' => '', 'category' => 4, 'language' => 'gl', 'owner' => null, 'location' => null, 'sort' => 'recent', 'view' => 'list', 'page' => null], $c->toQuery(['page' => null]));
    }

    public function testQueryIsLengthLimited(): void
    {
        self::assertSame(200, mb_strlen(SearchCriteria::fromRequest(new Request('GET', '/books', ['q' => str_repeat('é', 500)]))->query));
    }

    public function testParamsAreUniqueAndLikeWildcardsAreEscaped(): void
    {
        $p = new Params();
        self::assertSame(':p0', $p->add('a'));
        self::assertSame(':p1, :p2', $p->in([1, 2]));
        self::assertSame('NULL', $p->in([]));
        self::assertSame(['p0' => 'a', 'p1' => 1, 'p2' => 2], $p->all());
        self::assertSame('%100\\%\\_off\\\\%', Params::likeContains('100%_off\\'));
    }
}
