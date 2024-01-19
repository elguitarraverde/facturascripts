<?php declare(strict_types=1);

namespace FacturaScripts\Test\Core\Lib\ListFilter;

use FacturaScripts\Core\Lib\ListFilter\PeriodTools;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

class PeriodToolsTest extends TestCase
{
    use LogErrorsTrait;

    public function testApplyPeriod(): void
    {
        /** TRIMESTRE ANTERIOR */
        $startDate = date(PeriodTools::DATE_FORMAT, strtotime('19-01-2024'));
        $endDate = date(PeriodTools::DATE_FORMAT, strtotime('19-02-2024'));
        PeriodTools::applyPeriod('previous-quarter', $startDate, $endDate);

        static::assertEquals('01-10-2023', $startDate);
        static::assertEquals('31-12-2023', $endDate);

        /** TRIMESTRE ACTUAL */
        $startDate = date(PeriodTools::DATE_FORMAT, strtotime('19-01-2024'));
        $endDate = date(PeriodTools::DATE_FORMAT, strtotime('19-02-2024'));
        PeriodTools::applyPeriod('current-quarter', $startDate, $endDate);

        static::assertEquals('01-01-2024', $startDate);
        static::assertEquals('31-03-2024', $endDate);
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
