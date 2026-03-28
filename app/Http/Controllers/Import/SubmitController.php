<?php

/*
 * SubmitController.php
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

namespace App\Http\Controllers\Import;

use App\Exceptions\ImporterErrorException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessImportSubmissionJob;
use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Shared\Authentication\SecretManager;
use App\Services\Shared\Import\Status\SubmissionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Class SubmitController
 */
class SubmitController extends Controller
{
    protected const string DISK_NAME = 'jobs';

    private ImportJobRepository $repository;

    /**
     * StartController constructor.
     */
    public function __construct()
    {
        parent::__construct();
        view()->share('pageTitle', 'Submit data to Firefly III');
        $this->repository = new ImportJobRepository();
    }

    public function index(string $identifier)
    {
        Log::debug(sprintf('[%s] Now in %s', config('importer.version'), __METHOD__));
        $mainTitle  = 'Submit the data';
        $importJob  = $this->repository->find($identifier);
        $flow       = $importJob->getFlow();

        if ('ready_for_submission' !== $importJob->getState()) {
            $status = $importJob->getState();

            return redirect(route('data-conversion.index', [$identifier]))->with(['error' => sprintf('Job is in state "%s", expected ready_for_submission. Please complete the previous step first.', $status)]);
        }

        // Back URL depends on the flow type:
        // - file flows: back to mapping (006) or conversion (007) depending on what was last visited
        // - non-file flows: back to configuration (004), since conversion is automatic
        $conversionBeforeMapping = config(sprintf('importer.providers.%s.conversion_before_mapping', $flow));
        if ('file' === $flow) {
            $jobBackUrl = route('data-conversion.index', [$identifier]);
        } elseif ($conversionBeforeMapping) {
            $jobBackUrl = route('data-mapping.index', [$identifier]);
        } else {
            $jobBackUrl = route('configure-import.index', [$identifier]);
        }

        // validate flow
        $enabled    = config(sprintf('importer.providers.%s.enabled', $flow));
        if (null === $enabled || false === $enabled) {
            throw new ImporterErrorException(sprintf('[c] Not a supported flow: "%s"', $flow));
        }

        Log::debug(sprintf('Submit (import) routine manager identifier is "%s"', $identifier));

        return view('import.008-submit.index', compact('mainTitle', 'identifier', 'jobBackUrl'));
    }

    public function start(Request $request, string $identifier): JsonResponse
    {
        Log::debug(sprintf('Now at %s', __METHOD__));
        $importJob   = $this->repository->find($identifier);
        Log::debug('Start: Find import job status.');
        if (SubmissionStatus::SUBMISSION_RUNNING === $importJob->submissionStatus->toArray()['status']) {
            Log::info(sprintf('Submission already running for identifier "%s"; skip duplicate dispatch.', $identifier));

            return response()->json(
                ['status' => SubmissionStatus::SUBMISSION_RUNNING, 'identifier' => $identifier, 'alreadyRunning' => true]
            );
        }

        // Retrieve authentication credentials for job
        $accessToken = SecretManager::getAccessToken();
        $baseUrl     = SecretManager::getBaseUrl();
        $vanityUrl   = SecretManager::getVanityUrl();
        $lockKey     = $this->buildSubmissionLockKey($importJob, $baseUrl);

        // Prevent overlapping workers for the same logical import payload.
        if (!cache()->add($lockKey, ['identifier' => $identifier, 'started_at' => time()], now()->addMinutes(120))) {
            Log::warning(sprintf('Submission lock "%s" already held. Skip duplicate dispatch for identifier "%s".', $lockKey, $identifier));
            $importJob->submissionStatus->setStatus(SubmissionStatus::SUBMISSION_RUNNING);
            $importJob->submissionStatus->addWarning(0, '[a130]: Similar import submission is already running. Wait for it to finish before starting another one.');
            $this->repository->saveToDisk($importJob);

            return response()->json(
                ['status' => SubmissionStatus::SUBMISSION_RUNNING, 'identifier' => $identifier, 'alreadyRunning' => true]
            );
        }

        // Set initial running status before dispatching job
        $importJob->submissionStatus->setStatus(SubmissionStatus::SUBMISSION_RUNNING);
        $this->repository->saveToDisk($importJob);

        // Dispatch asynchronous job for processing
        ProcessImportSubmissionJob::dispatch($importJob, $accessToken, $baseUrl, $vanityUrl, $lockKey);

        // Return immediate response indicating job was dispatched
        return response()->json(['status' => SubmissionStatus::SUBMISSION_RUNNING, 'identifier' => $identifier]);
    }

    public function status(Request $request, string $identifier): JsonResponse
    {
        $importJob = $this->repository->find($identifier);
        Log::debug(sprintf('Now at %s(%s)', __METHOD__, $identifier));

        return response()
            ->json($importJob->submissionStatus->toArray())
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    private function buildSubmissionLockKey(ImportJob $importJob, string $baseUrl): string
    {
        $flow        = $importJob->getFlow();
        $transactions = $importJob->getConvertedTransactions();
        $sample      = [];
        $total       = count($transactions);

        foreach ($transactions as $index => $line) {
            if ($index > 49 && $index < ($total - 50)) {
                continue;
            }
            $tx = $line['transactions'][0] ?? [];
            $sample[] = [
                'external_id' => (string)($tx['external_id'] ?? ''),
                'date'        => (string)($tx['date'] ?? $tx['datetime'] ?? ''),
                'amount'      => (string)($tx['amount'] ?? ''),
                'type'        => (string)($tx['type'] ?? ''),
                'currency'    => (string)($tx['currency_code'] ?? ''),
            ];
        }
        $fingerprint = hash(
            'sha256',
            json_encode(
                ['flow' => $flow, 'base_url' => $baseUrl, 'count' => $total, 'sample' => $sample],
                JSON_THROW_ON_ERROR
            )
        );

        return sprintf('importer:submission:lock:%s:%s', $flow, $fingerprint);
    }
}
