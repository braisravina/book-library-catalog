<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Input;

use DigitalLibrary\Catalogue\Repository\AuthorRepository;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Validation\Validator;

/**
 * Parallel name[]/role[] form fields for authors and edition contributors.
 */
final class People
{
    /**
     * @return list<array{name: string, role: string}> blank names dropped, order kept
     */
    public static function fromRequest(Request $request, string $namesField, string $rolesField, string $defaultRole): array
    {
        $names = $request->inputList($namesField);
        $roles = $request->inputList($rolesField);
        $people = [];
        foreach ($names as $i => $name) {
            $name = AuthorRepository::normalizeName($name);
            if ($name === '') {
                continue;
            }
            $people[] = ['name' => $name, 'role' => trim($roles[$i] ?? '') !== '' ? trim($roles[$i]) : $defaultRole];
        }

        return $people;
    }

    /**
     * @param list<array{name: string, role: string}> $people
     * @param list<string>                            $roles
     */
    public static function validate(array $people, array $roles, string $field, Validator $validator): void
    {
        if (count($people) > WorkInput::MAX_PEOPLE) {
            $validator->addError($field, 'validation.too_many');

            return;
        }
        foreach ($people as $person) {
            if (mb_strlen($person['name']) > 255 || preg_match('/[\x00-\x1F\x7F]/', $person['name'])) {
                $validator->addError($field, 'validation.person_name');
            }
            if (!in_array($person['role'], $roles, true)) {
                $validator->addError($field, 'validation.in');
            }
        }
    }
}
