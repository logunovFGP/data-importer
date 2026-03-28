<?php

declare(strict_types=1);

namespace App\Services\TBank\Conversion;

use App\Exceptions\ImporterErrorException;
use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\LunchFlow\Conversion\Routine\GenerateTransactions;
use App\Services\Shared\Conversion\RoutineManagerInterface;
use App\Services\TBank\Conversion\Routine\TransactionProcessor;
use GrumpyDictator\FFIIIApiSupport\Exceptions\ApiHttpException;
use Illuminate\Support\Facades\Log;

class RoutineManager implements RoutineManagerInterface
{
    private GenerateTransactions $transactionGenerator;
    private TransactionProcessor $transactionProcessor;
    private ImportJobRepository $repository;
    private array $downloaded;
    private ImportJob $importJob;

    public function __construct(ImportJob $importJob)
    {
        $this->downloaded           = [];
        $this->repository           = new ImportJobRepository();
        $this->importJob            = $importJob;
        $this->importJob->refreshInstanceIdentifier();

        $this->transactionProcessor = new TransactionProcessor();
        $this->transactionGenerator = new GenerateTransactions();
        $this->setConfiguration();
    }

    public function getServiceAccounts(): array
    {
        return $this->transactionProcessor->getAccounts();
    }

    /**
     * @throws ImporterErrorException
     */
    private function setConfiguration(): void
    {
        $this->transactionProcessor->setImportJob($this->importJob);
        $this->transactionGenerator->setImportJob($this->importJob);
    }

    /**
     * @throws ImporterErrorException
     */
    public function start(): array
    {
        Log::debug(sprintf('[%s] TBank conversion started in %s', config('importer.version'), __METHOD__));

        $this->downloadFromTBank();
        $this->collectTargetAccounts();

        $this->importJob = $this->transactionProcessor->getImportJob();
        $this->transactionGenerator->setImportJob($this->importJob);

        if (true === $this->breakOnDownload()) {
            return [];
        }

        $transactions = $this->transactionGenerator->getTransactions($this->downloaded);
        Log::debug(sprintf('TBank: generated %d Firefly III transaction payload(s).', count($transactions)));

        return $transactions;
    }

    /**
     * @throws ImporterErrorException
     */
    private function downloadFromTBank(): void
    {
        try {
            $this->downloaded = $this->transactionProcessor->download();
        } catch (ImporterErrorException $e) {
            $this->importJob->conversionStatus->addError(0, sprintf('Could not download from TBank: %s', $e->getMessage()));
            $this->repository->saveToDisk($this->importJob);

            throw $e;
        }
    }

    private function collectTargetAccounts(): void
    {
        try {
            $this->transactionGenerator->collectTargetAccounts();
        } catch (ApiHttpException $e) {
            $this->importJob->conversionStatus->addError(0, sprintf('Error while collecting target accounts: %s', $e->getMessage()));
            $this->repository->saveToDisk($this->importJob);

            throw new ImporterErrorException($e->getMessage(), 0, $e);
        }
    }

    private function breakOnDownload(): bool
    {
        $total = 0;
        foreach ($this->downloaded as $transactions) {
            $total += count($transactions);
        }
        if (0 === $total) {
            $this->importJob->conversionStatus->addError(0, 'No transactions were downloaded from TBank.');
            $this->repository->saveToDisk($this->importJob);

            return true;
        }

        return false;
    }

    public function getImportJob(): ImportJob
    {
        return $this->importJob;
    }
}
