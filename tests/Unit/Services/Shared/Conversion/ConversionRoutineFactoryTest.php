<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Conversion;

use App\Exceptions\ImporterErrorException;
use App\Models\ImportJob;
use App\Services\EnableBanking\Conversion\RoutineManager as EnableBankingRoutineManager;
use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Conversion\ConversionRoutineFactory;
use App\Services\Spectre\Conversion\RoutineManager as SpectreRoutineManager;
use Tests\TestCase;

/**
 * Tests for Fix #15: Spectre and Enable Banking (eb) were missing from ConversionRoutineFactory.
 *
 * @internal
 *
 * @coversNothing
 */
final class ConversionRoutineFactoryTest extends TestCase
{
    private function createImportJobWithFlow(string $flow): ImportJob
    {
        $importJob = ImportJob::createNew();
        $importJob->setFlow($flow);
        $importJob->setConfiguration(
            Configuration::fromArray(['flow' => $flow])
        );

        return $importJob;
    }

    public function testCreateManagerReturnsSpectreRoutineManager(): void
    {
        $importJob = $this->createImportJobWithFlow('spectre');
        $factory   = new ConversionRoutineFactory($importJob);
        $manager   = $factory->createManager();

        $this->assertInstanceOf(SpectreRoutineManager::class, $manager);
    }

    public function testCreateManagerReturnsEnableBankingRoutineManager(): void
    {
        $importJob = $this->createImportJobWithFlow('eb');
        $factory   = new ConversionRoutineFactory($importJob);
        $manager   = $factory->createManager();

        $this->assertInstanceOf(EnableBankingRoutineManager::class, $manager);
    }

    public function testCreateManagerThrowsForUnknownFlow(): void
    {
        $importJob = $this->createImportJobWithFlow('nonexistent_flow');
        $factory   = new ConversionRoutineFactory($importJob);

        $this->expectException(ImporterErrorException::class);
        $this->expectExceptionMessage('ConversionRoutineFactory cannot create a routine for import flow "nonexistent_flow"');

        $factory->createManager();
    }
}
