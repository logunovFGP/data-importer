<?php

declare(strict_types=1);
/*
 * file.php
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

return [
    'unique_column_options' => [
        'note'               => 'The notes',
        'external-id'        => 'External identifier',
        'description'        => 'Transaction description',
        'internal_reference' => 'Internal reference',
    ],
    'import_types'         => [
        'manual'           => [
            'label'       => 'Generic file import (manual setup)',
            'description' => 'No preset; configure CSV/CAMT settings manually.',
            'defaults'    => [],
        ],
        'tbank_csv_export' => [
            'label'       => 'TBank from CSV export',
            'description' => 'Preset for TBank CSV export with semicolon separator and composite pseudo identifier.',
            'defaults'    => [
                'content_type'                => 'csv',
                'headers'                     => true,
                'delimiter'                   => 'semicolon',
                'date'                        => 'd.m.Y H:i:s',
                'roles'                       => [
                    0  => 'date_transaction',
                    6  => 'amount-comma-separated',
                    9  => 'category-name',
                    10 => 'note',
                    11 => 'description',
                ],
                'do_mapping'                  => [],
                'duplicate_detection_method'  => 'cell',
                'unique_column_index'         => 0,
                'unique_column_type'          => 'internal_reference',
                'pseudo_identifier'           => [
                    'source_columns' => [0, 2, 6, 11],
                    'separator'      => '|',
                    'role'           => 'internal_reference',
                ],
            ],
        ],
    ],
];
