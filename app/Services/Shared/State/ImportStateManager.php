<?php

/*
 * ImportStateManager.php
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

namespace App\Services\Shared\State;

use App\Models\ImportJob;
use Illuminate\Support\Facades\Log;

/**
 * Class ImportStateManager
 *
 * Unified state manager for import jobs. Tracks the active import in the session,
 * validates step access based on job state, and provides navigation helpers.
 */
class ImportStateManager
{
    private const string SESSION_ACTIVE_IMPORT = 'active_import_identifier';
    private const string SESSION_ACTIVE_FLOW   = 'active_import_flow';

    // Step ordering for validation
    private const array STEP_ORDER = [
        'authenticate' => 0,
        'upload'       => 1,
        'configure'    => 2,
        'roles'        => 3,
        'mapping'      => 4,
        'convert'      => 5,
        'submit'       => 6,
    ];

    // Job states that are valid for accessing each step.
    // Steps not listed here (authenticate, upload) have no state requirement.
    private const array STEP_REQUIRED_STATE = [
        'configure' => ['contains_content', 'is_parsed', 'is_configured', 'configured_and_roles_defined', 'configured_roles_map_in_place', 'ready_for_submission'],
        'roles'     => ['is_configured', 'configured_and_roles_defined', 'configured_roles_map_in_place', 'ready_for_submission'],
        'mapping'   => ['configured_and_roles_defined', 'configured_roles_map_in_place', 'ready_for_submission'],
        'convert'   => ['configured_and_roles_defined', 'configured_roles_map_in_place', 'ready_for_submission'],
        'submit'    => ['ready_for_submission'],
    ];

    /**
     * Store the active import identifier and flow in the session.
     */
    public static function setActiveImport(string $identifier, string $flow): void
    {
        session()->put(self::SESSION_ACTIVE_IMPORT, $identifier);
        session()->put(self::SESSION_ACTIVE_FLOW, $flow);
    }

    /**
     * Get the active import identifier and flow from the session.
     *
     * @return array{identifier: string, flow: string}|null
     */
    public static function getActiveImport(): ?array
    {
        $identifier = session()->get(self::SESSION_ACTIVE_IMPORT);
        $flow       = session()->get(self::SESSION_ACTIVE_FLOW);
        if (null === $identifier || '' === trim((string) $identifier)) {
            return null;
        }

        return ['identifier' => (string) $identifier, 'flow' => (string) $flow];
    }

    /**
     * Clear the active import from the session.
     */
    public static function clearActiveImport(): void
    {
        session()->forget(self::SESSION_ACTIVE_IMPORT);
        session()->forget(self::SESSION_ACTIVE_FLOW);
    }

    /**
     * Check whether the given step is accessible for the current job state.
     * Steps without an entry in STEP_REQUIRED_STATE (authenticate, upload) are always accessible.
     */
    public static function canAccessStep(string $step, ImportJob $job): bool
    {
        $requiredStates = self::STEP_REQUIRED_STATE[$step] ?? null;
        if (null === $requiredStates) {
            return true; // authenticate, upload have no state requirement
        }

        return in_array($job->getState(), $requiredStates, true);
    }

    /**
     * Determine the current step the import job should be on, based on its state.
     */
    public static function getCurrentStep(ImportJob $job): string
    {
        $state = $job->getState();

        return match (true) {
            'new' === $state                                            => 'upload',
            'contains_content' === $state                               => 'configure',
            in_array($state, ['is_parsed', 'is_configured'], true)      => 'configure',
            'configured_and_roles_defined' === $state                   => 'convert',
            'configured_roles_map_in_place' === $state                  => 'mapping',
            'ready_for_submission' === $state                           => 'submit',
            default                                                     => 'upload',
        };
    }

    /**
     * Check if a job is in a resumable state (not empty/new, and not already submitted).
     */
    public static function isResumable(ImportJob $job): bool
    {
        $state = $job->getState();

        // 'new' jobs have no meaningful data yet — nothing to resume.
        // 'ready_for_submission' jobs are at the final step — show them but they are at the end.
        return 'new' !== $state;
    }

    /**
     * Get the correct route URL for the job's current state.
     */
    public static function getCorrectRouteForState(string $identifier, ImportJob $job): string
    {
        $step = self::getCurrentStep($job);

        return match ($step) {
            'upload'    => route('new-import.index', [$job->getFlow()]),
            'configure' => route('configure-import.index', [$identifier]),
            'roles'     => route('configure-roles.index', [$identifier]),
            'mapping'   => route('data-mapping.index', [$identifier]),
            'convert'   => route('data-conversion.index', [$identifier]),
            'submit'    => route('submit-data.index', [$identifier]),
            default     => route('index'),
        };
    }
}
