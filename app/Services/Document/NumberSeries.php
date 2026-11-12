<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services\Document;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Per-company, per-type, per-year document numbers: <PREFIX>-<TYPE>-YYYY-NNNNNN.
 * The counter row is locked inside the caller's transaction, so concurrent issues never share a
 * number and a rolled-back document returns its number. A company without a prefix keeps the
 * legacy number formats.
 */
class NumberSeries
{
    public const SETTING = 'number_series_prefix';

    /** model basename (lower case) => type code in the number */
    public const TYPES = ['estimate' => 'EST', 'invoice' => 'INV', 'creditnote' => 'CN'];

    public const PREFIX_PATTERN = '/^[A-Z][A-Z0-9]{1,9}$/';

    public static function prefix(int|string $company): ?string
    {
        $prefix = CompanySetting::getSetting(self::SETTING, $company);

        return $prefix ? (string) $prefix : null;
    }

    public static function currentYear(): int
    {
        return (int) now()->format('Y');
    }

    public static function format(string $prefix, string $type, int $year, int $number): string
    {
        return sprintf('%s-%s-%d-%06d', $prefix, $type, $year, $number);
    }

    public static function reserve(int|string $company, string $type, int $year): int
    {
        return DB::transaction(function () use ($company, $type, $year) {
            $row = self::lockedRow($company, $type, $year);
            $next = (int) $row->last_sequence + 1;

            DB::table('document_number_series')->where('id', $row->id)->update(['last_sequence' => $next, 'updated_at' => now()]);

            return $next;
        });
    }

    public static function peek(int|string $company, string $type, int $year): int
    {
        return 1 + (int) DB::table('document_number_series')
            ->where(['company_id' => $company, 'document_type' => $type, 'year' => $year])
            ->value('last_sequence');
    }

    /**
     * Continue an existing sequence: the next document of this type and year gets $next. Never
     * lower than what was already issued, so a number is never reused.
     */
    public static function setNext(int|string $company, string $type, int $year, int $next): void
    {
        DB::transaction(function () use ($company, $type, $year, $next) {
            $row = self::lockedRow($company, $type, $year);

            if ($next < 1 || $next - 1 < (int) $row->last_sequence) {
                throw ValidationException::withMessages(['next' => ['The next number must be above the last issued number ('.(int) $row->last_sequence.').']]);
            }

            DB::table('document_number_series')->where('id', $row->id)->update(['last_sequence' => $next - 1, 'updated_at' => now()]);
        });
    }

    /** @return array<string, array{last_issued: int, next: int, next_number: string}> keyed by type code, for $year */
    public static function status(int|string $company, int $year): array
    {
        $prefix = self::prefix($company);
        $out = [];

        foreach (self::TYPES as $type) {
            $next = self::peek($company, $type, $year);
            $out[$type] = [
                'last_issued' => $next - 1,
                'next' => $next,
                'next_number' => $prefix ? self::format($prefix, $type, $year, $next) : null,
            ];
        }

        return $out;
    }

    private static function lockedRow(int|string $company, string $type, int $year): object
    {
        $where = ['company_id' => $company, 'document_type' => $type, 'year' => $year];

        DB::table('document_number_series')->insertOrIgnore($where + ['last_sequence' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('document_number_series')->where($where)->lockForUpdate()->first();
    }
}
