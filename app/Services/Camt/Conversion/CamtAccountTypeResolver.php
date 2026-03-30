<?php

/*
 * CamtAccountTypeResolver.php
 * Copyright (c) 2025 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Services\Camt\Conversion;

use Illuminate\Support\Facades\Log;

/**
 * Extracted from TransactionMapper to keep that file under 800 lines (fix #36).
 *
 * Resolves an account type (asset, expense, revenue) by matching a field value
 * against the known Firefly III accounts.
 */
class CamtAccountTypeResolver
{
    /**
     * @param array  $allAccounts   All known Firefly III accounts.
     * @param string $field         The field being searched (id, iban, number, name).
     * @param string $value         The value to look up.
     * @param bool   $lessThanZero  Whether the transaction amount is negative.
     */
    public function resolve(array $allAccounts, string $field, string $value, bool $lessThanZero): ?string
    {
        $count    = 0;
        $result   = null;
        $hitField = null; // the field on which we found a match.
        foreach ($allAccounts as $account) {
            // we have a match!
            if ((string) $account->{$field} === (string) $value) {
                // never found a match before!
                if (0 === $count) {
                    Log::debug(sprintf('Recognized "%s" as a "%s"-account by its "%s".', $value, $account->type, $field));
                    $result   = $account->type;
                    $hitField = $field;
                    ++$count;
                }
                // we found a match before, and it's different too.
                if (0 !== $count && $account->type !== $result) {
                    Log::warning(sprintf(
                        'Recognized "%s" as a "%s"-account (on the "%s"-field) but ALSO as a "%s"-account (previous match was on the "%s"-field)!',
                        $value,
                        $result,
                        $field,
                        $account->type,
                        $hitField
                    ));
                    // the previous result always trumps the current result because the order of accountIdentificationSuffixes
                    Log::debug(sprintf('System will keep the previous match and assume account with %s "%s" is a "%s" account', $field, $value, $result));
                    ++$count;
                }
                // we found a match before and it's different. But the data importer has found both "revenue" AND "expense" accounts. What to do?
                $set = [$account->type, $result];
                if (0 !== $count && $account->type !== $result && in_array('revenue', $set, true) && in_array('expense', $set, true) && $lessThanZero) {
                    Log::warning(sprintf(
                        'Recognized "%s" as a "%s"-account (on the "%s"-field) but ALSO as a "%s"-account (previous match was on the "%s"-field)!',
                        $value,
                        $result,
                        $field,
                        $account->type,
                        $hitField
                    ));
                    Log::debug('Because amount is less than zero, we assume "expense" is the correct type.');
                    $result = 'expense';

                    ++$count;
                }
                // we found a match before and it's different. But: previous result was "expense", current result is "revenue"
                if (0 !== $count && $account->type !== $result && in_array('revenue', $set, true) && in_array('expense', $set, true) && !$lessThanZero) {
                    Log::warning(sprintf(
                        'Recognized "%s" as a "%s"-account (on the "%s"-field) but ALSO as a "%s"-account (previous match was on the "%s"-field)!',
                        $value,
                        $result,
                        $field,
                        $account->type,
                        $hitField
                    ));
                    Log::debug('Because amount is more than zero, we assume "revenue" is the correct type.');
                    $result = 'revenue';

                    ++$count;
                }
            }
        }
        if (null === $result) {
            Log::debug(sprintf('Unable to recognize the account type of "%s" "%s", or skipped because unsure.', $field, $value));
        }

        return $result;
    }
}
