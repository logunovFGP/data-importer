<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Dedup;

use App\Services\BasisBank\Dedup\BasisBankDuplicateDetector;
use App\Services\Nordigen\Dedup\NordigenDuplicateDetector;
use App\Services\Shared\Dedup\DuplicateDetectorFactory;
use App\Services\Shared\Dedup\ExternalIdDuplicateDetector;
use App\Services\TBank\Dedup\TBankDuplicateDetector;
use App\Services\TRC20\Dedup\Trc20DuplicateDetector;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class DuplicateDetectorFactoryTest extends TestCase
{
    public function testCreateTrc20Detector(): void
    {
        $detector = DuplicateDetectorFactory::create('trc20', null, false);

        $this->assertInstanceOf(Trc20DuplicateDetector::class, $detector);
        $this->assertSame('trc20', $detector->sourceName());
    }

    public function testCreateBasisBankDetector(): void
    {
        $detector = DuplicateDetectorFactory::create('basisbank', null, false);

        $this->assertInstanceOf(BasisBankDuplicateDetector::class, $detector);
        $this->assertSame('basisbank', $detector->sourceName());
    }

    public function testCreateTBankDetector(): void
    {
        $detector = DuplicateDetectorFactory::create('tbank', null, false);

        $this->assertInstanceOf(TBankDuplicateDetector::class, $detector);
        $this->assertSame('tbank', $detector->sourceName());
    }

    public function testCreateNordigenDetector(): void
    {
        $detector = DuplicateDetectorFactory::create('nordigen', null, false);

        $this->assertInstanceOf(NordigenDuplicateDetector::class, $detector);
        $this->assertSame('nordigen', $detector->sourceName());
    }

    public function testCreateGenericDetectorForSpectre(): void
    {
        Config::set('importer.dedup.source_names', ['spectre' => 'spectre']);

        $detector = DuplicateDetectorFactory::create('spectre', null, false);

        $this->assertInstanceOf(ExternalIdDuplicateDetector::class, $detector);
        $this->assertSame('spectre', $detector->sourceName());
    }

    public function testCreateGenericDetectorForSimpleFIN(): void
    {
        Config::set('importer.dedup.source_names', ['simplefin' => 'simplefin']);

        $detector = DuplicateDetectorFactory::create('simplefin', null, false);

        $this->assertInstanceOf(ExternalIdDuplicateDetector::class, $detector);
        $this->assertSame('simplefin', $detector->sourceName());
    }

    public function testCreateGenericDetectorForEnableBanking(): void
    {
        Config::set('importer.dedup.source_names', ['eb' => 'enablebanking']);

        $detector = DuplicateDetectorFactory::create('eb', null, false);

        $this->assertInstanceOf(ExternalIdDuplicateDetector::class, $detector);
        $this->assertSame('enablebanking', $detector->sourceName());
    }

    public function testCreateGenericDetectorForCsv(): void
    {
        Config::set('importer.dedup.source_names', ['file' => 'csv']);

        $detector = DuplicateDetectorFactory::create('file', null, false);

        $this->assertInstanceOf(ExternalIdDuplicateDetector::class, $detector);
        $this->assertSame('csv', $detector->sourceName());
    }

    public function testCreateGenericDetectorFallsBackToFlowName(): void
    {
        Config::set('importer.dedup.source_names', []);

        $detector = DuplicateDetectorFactory::create('custom_provider', null, false);

        $this->assertInstanceOf(ExternalIdDuplicateDetector::class, $detector);
        $this->assertSame('custom_provider', $detector->sourceName());
    }
}
