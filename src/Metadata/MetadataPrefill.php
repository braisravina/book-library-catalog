<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata;

use DigitalLibrary\Catalogue\Input\EditionInput;
use DigitalLibrary\Catalogue\Input\WorkInput;
use DigitalLibrary\Catalogue\Isbn;

/**
 * Maps looked-up metadata onto the new-book form inputs. The user reviews
 * and can change every value before anything is saved.
 */
final class MetadataPrefill
{
    /**
     * @return array{WorkInput, EditionInput}
     */
    public static function inputs(BookMetadata $m, Isbn $isbn): array
    {
        $work = new WorkInput(
            $m->title, $m->subtitle, '', '', '', $m->description,
            array_map(static fn (string $name): array => ['name' => $name, 'role' => 'author'], $m->authors),
            [],
        );
        $edition = new EditionInput(
            $m->title, $m->subtitle, $m->publisher,
            $m->publicationYear === null ? '' : (string) $m->publicationYear,
            '', $m->language,
            $m->pageCount === null ? '' : (string) $m->pageCount,
            '', $isbn->isbn13, '', '', [],
        );

        return [$work, $edition];
    }
}
