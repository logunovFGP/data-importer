<?php

/*
 * IndexController.php
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

namespace App\Http\Controllers;

use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Session\Constants;
use App\Services\Shared\Authentication\SecretManager;
use App\Services\Shared\Conversion\ConversionStatus;
use App\Services\Shared\Import\Status\SubmissionStatus;
use App\Services\Shared\State\ImportStateManager;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Class IndexController
 */
class IndexController extends Controller
{
    /**
     * IndexController constructor.
     */
    public function __construct()
    {
        parent::__construct();
        app('view')->share('pageTitle', 'Index');
    }

    public function flush(): RedirectResponse
    {
        Log::debug(sprintf('Now at %s', __METHOD__));

        // Capture Firefly III auth state BEFORE flushing the session.
        // These methods read from session first, then fall back to config,
        // so we capture the effective value regardless of source.
        $baseUrl      = SecretManager::getBaseUrl();
        $accessToken  = SecretManager::getAccessToken();
        $vanityUrl    = SecretManager::getVanityUrl();
        $refreshToken = session()->get(Constants::SESSION_REFRESH_TOKEN, '');

        // Flush the entire session and regenerate the session ID.
        session()->flush();
        session()->regenerate(true);
        Artisan::call('cache:clear');

        // Restore Firefly III auth state so the user does not have to
        // re-enter their connection details after starting over.
        if ('' !== $baseUrl) {
            SecretManager::saveBaseUrl($baseUrl);
        }
        if ('' !== $accessToken) {
            SecretManager::saveAccessToken($accessToken);
        }
        if ('' !== $vanityUrl) {
            SecretManager::saveVanityUrl($vanityUrl);
        }
        if ('' !== (string) $refreshToken) {
            SecretManager::saveRefreshToken((string) $refreshToken);
        }

        return redirect(route('index'));
    }

    public function abortImport(string $identifier): RedirectResponse
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $identifier)) {
            return redirect(route('index'));
        }

        Log::debug(sprintf('Aborting import job: %s', $identifier));

        $disk = Storage::disk('import-jobs');
        $file = sprintf('%s.json', $identifier);
        if (!$disk->exists($file)) {
            session()->flash('warning', 'Import job not found.');

            return redirect(route('index'));
        }

        if (!$disk->delete($file)) {
            Log::error(sprintf('Failed to delete import job file: %s', $file));
            session()->flash('warning', 'Could not delete import job file.');

            return redirect(route('index'));
        }
        Log::info(sprintf('Deleted import job file: %s', $file));
        session()->flash('success', 'Import job deleted.');

        return redirect(route('index'));
    }

    public function index(Request $request): mixed
    {
        Log::debug(sprintf('[%s] Now in %s', config('importer.version'), __METHOD__));

        // global methods to get these values, from cookies or configuration.
        // it's up to the manager to provide them.
        // if invalid values, redirect to token index.

        $validInfo         = SecretManager::hasValidSecrets();
        if (!$validInfo) {
            Log::debug('No valid secrets, redirect to token.index');

            return redirect(route('token.index'));
        }

        $path              = storage_path('import-jobs');
        $warning           = '';
        if (!is_dir($path)) {
            $warning = sprintf('The data import needs the folder <code>%s</code> to exist. Please fix this manually.', $path);
        }
        if (!is_writable($path)) {
            $warning = sprintf('The data import needs the folder <code>%s</code> to be writeable. Please fix this manually.', $path);
        }
        if ('' === $warning) {
            $this->clearOldJobs();
        }


        // display to user the method of authentication
        $clientId          = (string)config('importer.client_id');
        $url               = (string)config('importer.url');
        $accessTokenConfig = (string)config('importer.access_token');

        Log::debug('IndexController authentication detection', [
            'client_id'           => $clientId,
            'url'                 => $url,
            'access_token_config' => substr($accessTokenConfig, 0, 25).'...',
            'access_token_empty'  => '' === $accessTokenConfig,
        ]);

        $pat               = false;
        if ('' !== $accessTokenConfig) {
            $pat = true;
        }
        $clientIdWithURL   = false;
        if ('' !== $url && '' !== $clientId) {
            $clientIdWithURL = true;
        }
        $URLonly           = false;
        if ('' !== $url && '' === $clientId && '' === $accessTokenConfig) {
            $URLonly = true;
        }
        $flexible          = false;
        if ('' === $url && '' === $clientId) {
            $flexible = true;
        }

        Log::debug('IndexController authentication type flags', ['pat' => $pat, 'clientIdWithURL' => $clientIdWithURL, 'URLonly' => $URLonly, 'flexible' => $flexible]);

        $isDocker          = config('importer.docker.is_docker', false);
        $identifier        = substr(session()->getId(), 0, 10);

        // Scan for recent resumable import jobs (created within the last 24 hours).
        $recentJobs        = $this->getRecentJobs();

        return view('index', compact('pat', 'warning', 'clientIdWithURL', 'URLonly', 'flexible', 'identifier', 'isDocker', 'recentJobs'));
    }

    /**
     * Scan storage/import-jobs/ for recent jobs (created within the last 24 hours)
     * that are in a resumable state. Returns an array of job summaries for the view.
     */
    private function getRecentJobs(): array
    {
        $recentJobs = [];
        $disk       = Storage::disk('import-jobs');
        $files      = $disk->files();
        $cutoff     = time() - 86400; // 24 hours
        $repository = new ImportJobRepository();

        foreach ($files as $file) {
            if (!str_ends_with($file, '.json')) {
                continue;
            }
            $lastModified = $disk->lastModified($file);
            if ($lastModified < $cutoff) {
                continue;
            }
            $jobIdentifier = pathinfo($file, PATHINFO_FILENAME);

            try {
                $job = $repository->find($jobIdentifier, restoreAuth: false);
            } catch (\Throwable $e) {
                Log::debug(sprintf('Could not load job "%s" for recent jobs listing: %s', $jobIdentifier, $e->getMessage()));

                continue;
            }

            if (!ImportStateManager::isResumable($job)) {
                continue;
            }

            $recentJobs[] = [
                'identifier' => $job->identifier,
                'flow'       => $job->getFlow(),
                'state'      => $job->getState(),
                'step'       => ImportStateManager::getCurrentStep($job),
                'status'     => $this->deriveJobStatus($job),
                'created'    => date('Y-m-d H:i', $lastModified),
                'url'        => ImportStateManager::getCorrectRouteForState($job->identifier, $job),
            ];
        }

        // Sort by creation time descending (most recent first).
        usort($recentJobs, static fn(array $a, array $b): int => strcmp($b['created'], $a['created']));

        return $recentJobs;
    }

    /**
     * Derive a human-readable status with badge info from the job's conversion/submission state.
     *
     * @return array{label: string, badge: string}
     */
    private function deriveJobStatus(ImportJob $job): array
    {
        $convStatus = $job->conversionStatus->getStatus();
        $subStatus  = $job->submissionStatus->getStatus();

        return match (true) {
            SubmissionStatus::SUBMISSION_DONE === $subStatus       => ['label' => 'Done', 'badge' => 'bg-success'],
            SubmissionStatus::SUBMISSION_RUNNING === $subStatus    => ['label' => 'Submitting…', 'badge' => 'bg-primary'],
            SubmissionStatus::SUBMISSION_ERRORED === $subStatus    => ['label' => 'Submit error', 'badge' => 'bg-danger'],
            ConversionStatus::CONVERSION_DONE === $convStatus      => ['label' => 'Converted', 'badge' => 'bg-info text-dark'],
            ConversionStatus::CONVERSION_RUNNING === $convStatus   => ['label' => 'Converting…', 'badge' => 'bg-primary'],
            ConversionStatus::CONVERSION_ERRORED === $convStatus   => ['label' => 'Convert error', 'badge' => 'bg-danger'],
            default                                                 => ['label' => 'In Progress', 'badge' => 'bg-secondary'],
        };
    }

    private function clearOldJobs(): void
    {
        $disk  = Storage::disk('import-jobs');
        $now   = now();
        $files = $disk->files();
        foreach ($files as $file) {
            if (!str_ends_with($file, 'json')) {
                continue;
            }
            $content = $disk->get($file);
            $json    = json_decode($content, true);
            if (is_array($json)) {
                $createdAt = Carbon::parse($json['createdAt'] ?? date('Y-m-d H:i:s'));
                if ($now->diffInDays($createdAt, true) > 90) {
                    $disk->delete($file);
                }
            }
        }
    }
}
