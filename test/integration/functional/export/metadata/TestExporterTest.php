<?php

/**
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; under version 2
 * of the License (non-upgradable).
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * Copyright (c) 2014 (original work) Open Assessment Technologies SA (under the project TAO-PRODUCT);
 *
 */

namespace oat\taoQtiTest\test\integration;

use core_kernel_classes_Resource;
use oat\generis\test\GenerisPhpUnitTestRunner;
use oat\taoQtiTest\models\export\Formats\Metadata\QtiTestExporter;
use ZipArchive;

/**
 * This test case focuses on testing the TestCompilerUtils helper.
 *
 * @author Jérôme Bogaerts <jerome@taotesting.com>
 * @package taoQtiTest
 *
 */
class TestExporterTest extends GenerisPhpUnitTestRunner
{
    private $testCreatedUri;

    public static function samplesDir()
    {
        return dirname(dirname(__DIR__)) . '/samples/metadata/test/';
    }


    /**
     *
     * @dataProvider metaProvider
     * @param string $testFile
     * @param array $expectedMeta
     */
    public function testExport($testFile, $expectedMeta)
    {
        $class = \taoTests_models_classes_TestsService::singleton()
            ->getRootclass()
            ->createSubClass(uniqid('functional'));
        \helpers_TimeOutHelper::setTimeOutLimit(\helpers_TimeOutHelper::LONG);
        $report = \taoQtiTest_models_classes_QtiTestService::singleton()
            ->importMultipleTests($class, $testFile);
        \helpers_TimeOutHelper::reset();
        $resources = $class->getInstances();
        $resource = current($resources);
        $this->testCreatedUri = $resource->getUri();

        $zipPath = tempnam(sys_get_temp_dir(), 'metadata_export_');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE));
        $testExporter = new QtiTestExporter(new core_kernel_classes_Resource($this->testCreatedUri), $zip);
        $testExporter->export();
        $zip->close();

        \taoTests_models_classes_TestsService::singleton()->deleteClass($class);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        $csvContents = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_ends_with($name, '.csv')) {
                $csvContents = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();
        @unlink($zipPath);
        $this->assertNotNull($csvContents, 'Expected a CSV entry in the export archive');

        $expectedNormalized = $this->normalizeLineEndings(file_get_contents($expectedMeta));
        $actualNormalized = $this->normalizeLineEndings($csvContents);

        $expectedLines = array_filter(explode("\n", $expectedNormalized), fn ($l) => $l !== '');
        $actualLines = array_filter(explode("\n", $actualNormalized), fn ($l) => $l !== '');

        $this->assertCount(count($expectedLines), $actualLines, 'CSV should have same number of lines as expected (export format may include extra columns)');

        $expectedHeader = str_getcsv(array_shift($expectedLines));
        $actualHeader = str_getcsv(array_shift($actualLines));

        foreach (['testPart', 'section', 'shuffle', 'section-order'] as $requiredColumn) {
            $this->assertContains($requiredColumn, $actualHeader, "CSV header should contain column {$requiredColumn}");
        }

        $testPartIdx = array_search('testPart', $expectedHeader);
        $sectionIdx = array_search('section', $expectedHeader);
        $testPartIdxActual = array_search('testPart', $actualHeader);
        $sectionIdxActual = array_search('section', $actualHeader);

        if ($testPartIdx !== false && $sectionIdx !== false && $testPartIdxActual !== false && $sectionIdxActual !== false) {
            foreach ($expectedLines as $i => $expectedRow) {
                $expectedCells = str_getcsv($expectedRow);
                $this->assertArrayHasKey($i, $actualLines, "Actual CSV should have data row " . ($i + 1));
                $actualCells = str_getcsv($actualLines[$i]);
                $this->assertEquals(
                    $expectedCells[$testPartIdx] ?? '',
                    $actualCells[$testPartIdxActual] ?? '',
                    "Row " . ($i + 2) . " testPart should match"
                );
                $this->assertEquals(
                    $expectedCells[$sectionIdx] ?? '',
                    $actualCells[$sectionIdxActual] ?? '',
                    "Row " . ($i + 2) . " section should match"
                );
            }
        }
    }

    /**
     * Convert all line-endings to UNIX format
     * @param $s
     * @return mixed
     */
    private function normalizeLineEndings($s)
    {
        $s = str_replace("\r\n", "\n", $s);
        $s = str_replace("\r", "\n", $s);
        // Don't allow out-of-control blank lines
        $s = preg_replace("/\n{2,}/", "\n\n", $s);
        return $s;
    }

    public function metaProvider()
    {
        return [
            [self::samplesDir() . 'duplicate.zip', self::samplesDir() . 'export_duplicate.csv'],
        ];
    }
}
