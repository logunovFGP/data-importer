<?php

/*
 * ImportStepGuard.php
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

namespace App\Http\Middleware;

use App\Exceptions\ImporterErrorException;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Shared\State\ImportStateManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Class ImportStepGuard
 *
 * Middleware that validates whether the user can access the requested import step.
 * If the job state does not permit the step, redirects to the correct step with a flash message.
 * Also attaches the loaded ImportJob to the request so controllers don't need to re-load it.
 */
class ImportStepGuard
{
    public function handle(Request $request, Closure $next, string $step = ''): mixed
    {
        $identifier = $request->route('identifier');
        if (null === $identifier || '' === trim((string) $identifier)) {
            return $next($request); // No identifier = no validation needed
        }

        $repository = new ImportJobRepository();

        try {
            $job = $repository->find((string) $identifier);
        } catch (ImporterErrorException $e) {
            Log::warning(sprintf('ImportStepGuard: import job "%s" not found: %s', $identifier, $e->getMessage()));

            return redirect(route('index'))->with('error', 'Import job not found. It may have expired.');
        }

        if ('' !== $step && !ImportStateManager::canAccessStep($step, $job)) {
            $correctRoute = ImportStateManager::getCorrectRouteForState((string) $identifier, $job);
            $currentStep  = ImportStateManager::getCurrentStep($job);
            Log::info(sprintf(
                'ImportStepGuard: blocked access to "%s" step, job state is "%s". Redirecting to "%s".',
                $step,
                $job->getState(),
                $currentStep
            ));

            return redirect($correctRoute)->with('error', sprintf('Please complete the "%s" step first.', $currentStep));
        }

        // Make job available to controller without re-loading
        $request->attributes->set('importJob', $job);

        return $next($request);
    }
}
