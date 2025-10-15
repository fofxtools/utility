<?php

declare(strict_types=1);

namespace FOfX\Utility\Tests\Unit;

use FOfX\Utility\AmazonBrowseNodeImporter;
use FOfX\Utility\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AmazonBrowseNodeImporterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper method to put CSV content in storage
     */
    private function putCsvInStorage(string $filename, string $content): void
    {
        // Storage::fake('local') is called in TestCase::setUp()
        Storage::disk('local')->put('amazon/' . $filename, $content);
    }

    public function testGetSetTableName(): void
    {
        $importer = new AmazonBrowseNodeImporter();
        $this->assertSame('amazon_browse_nodes', $importer->getTableName());
        $importer->setTableName('test_table');
        $this->assertSame('test_table', $importer->getTableName());
    }

    public function testGetSetTableMigrationPath(): void
    {
        $importer = new AmazonBrowseNodeImporter();
        $this->assertStringEndsWith('database/migrations/2025_10_14_184118_create_amazon_browse_nodes_table.php', $importer->getTableMigrationPath());
        $importer->setTableMigrationPath('/tmp/test_migration.php');
        $this->assertSame('/tmp/test_migration.php', $importer->getTableMigrationPath());
    }

    public function testGetSetFolderName(): void
    {
        $importer = new AmazonBrowseNodeImporter();
        $this->assertSame('amazon', $importer->getFolderName());
        $importer->setFolderName('test_folder');
        $this->assertSame('test_folder', $importer->getFolderName());
    }

    public function testGetSetFileName(): void
    {
        $importer = new AmazonBrowseNodeImporter();
        $this->assertSame('amazon_product_categories.csv', $importer->getFileName());
        $importer->setFileName('test_file.csv');
        $this->assertSame('test_file.csv', $importer->getFileName());
    }

    public function testGetSetHardcodedMappings(): void
    {
        $importer = new AmazonBrowseNodeImporter();
        $expected = [
            'digital-music-album' => 324381011,
            'digital-music-track' => 324382011,
        ];
        $this->assertSame($expected, $importer->getHardcodedMappings());

        $newMappings = [
            'test-key' => 123456,
        ];
        $importer->setHardcodedMappings($newMappings);
        $this->assertSame($newMappings, $importer->getHardcodedMappings());
    }

    public function testCollectNodesFromCsvThrowsWhenFileMissing(): void
    {
        $importer = new AmazonBrowseNodeImporter();
        $importer->setFolderName('nonexistent');
        $importer->setFileName('missing.csv');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found');
        $importer->collectNodesFromCsv();
    }

    public function testCollectNodesFromCsvWithValidData(): void
    {
        $csv = <<<CSV
Department,Node1,Node2
"Books (1000)","Fiction (17)","Science Fiction (25)"
"Electronics (172282)","Computers (541966)","Laptops (565108)"
CSV;

        $this->putCsvInStorage('test.csv', $csv);

        $importer = new AmazonBrowseNodeImporter();
        $importer->setFileName('test.csv');

        $result = $importer->collectNodesFromCsv();

        $this->assertArrayHasKey('nodes', $result);
        $this->assertArrayHasKey('stats', $result);
        $this->assertSame(6, $result['stats']['nodes_to_insert']);
        $this->assertSame(2, $result['stats']['rows_processed']);
    }

    public function testCollectNodesFromCsvHandlesHardcodedMappings(): void
    {
        $csv = <<<CSV
Department,Node1
"Digital Music (digital-music-album)","Pop (digital-music-track)"
CSV;

        $this->putCsvInStorage('test.csv', $csv);

        $importer = new AmazonBrowseNodeImporter();
        $importer->setFileName('test.csv');

        $result = $importer->collectNodesFromCsv();

        $this->assertSame(2, $result['stats']['nodes_to_insert']);
        $this->assertSame(324381011, $result['nodes'][0]['browse_node_id']);
        $this->assertSame(324382011, $result['nodes'][1]['browse_node_id']);
    }

    public function testCollectNodesFromCsvSkipsEmptyRows(): void
    {
        $csv = <<<CSV
Department,Node1
"Books (1000)","Fiction (17)"
,
CSV;

        $this->putCsvInStorage('test.csv', $csv);

        $importer = new AmazonBrowseNodeImporter();
        $importer->setFileName('test.csv');

        $result = $importer->collectNodesFromCsv();

        $this->assertSame(1, $result['stats']['rows_skipped_empty']);
        $this->assertSame(2, $result['stats']['nodes_to_insert']);
    }

    public function testCollectNodesFromCsvSkipsDuplicateNodes(): void
    {
        $csv = <<<CSV
Department,Node1
"Books (1000)","Fiction (17)"
"Books (1000)","Mystery (18)"
CSV;

        $this->putCsvInStorage('test.csv', $csv);

        $importer = new AmazonBrowseNodeImporter();
        $importer->setFileName('test.csv');

        $result = $importer->collectNodesFromCsv();

        $this->assertSame(1, $result['stats']['nodes_skipped_duplicate']);
        $this->assertSame(3, $result['stats']['nodes_to_insert']);
    }

    public function testCollectNodesFromCsvBuildsCorrectParentChildRelationships(): void
    {
        $csv = <<<CSV
Department,Node1,Node2
"Books (1000)","Fiction (17)","Science Fiction (25)"
CSV;

        $this->putCsvInStorage('test.csv', $csv);

        $importer = new AmazonBrowseNodeImporter();
        $importer->setFileName('test.csv');

        $result = $importer->collectNodesFromCsv();
        $nodes  = $result['nodes'];

        $this->assertCount(3, $nodes);

        // Level 0: Books (no parent)
        $this->assertSame(1000, $nodes[0]['browse_node_id']);
        $this->assertSame('Books', $nodes[0]['name']);
        $this->assertNull($nodes[0]['parent_id']);
        $this->assertSame(0, $nodes[0]['level']);

        // Level 1: Fiction (parent is Books)
        $this->assertSame(17, $nodes[1]['browse_node_id']);
        $this->assertSame('Fiction', $nodes[1]['name']);
        $this->assertSame(1000, $nodes[1]['parent_id']);
        $this->assertSame(1, $nodes[1]['level']);

        // Level 2: Science Fiction (parent is Fiction)
        $this->assertSame(25, $nodes[2]['browse_node_id']);
        $this->assertSame('Science Fiction', $nodes[2]['name']);
        $this->assertSame(17, $nodes[2]['parent_id']);
        $this->assertSame(2, $nodes[2]['level']);
    }

    public function testInsertNodesByLevelInsertsNodesInCorrectOrder(): void
    {
        $importer = new AmazonBrowseNodeImporter();

        $nodes = [
            [
                'browse_node_id'   => 1000,
                'name'             => 'Books',
                'parent_id'        => null,
                'path'             => 'Books (1000)',
                'level'            => 0,
                'created_at'       => now(),
                'updated_at'       => now(),
                'processed_at'     => null,
                'processed_status' => null,
            ],
            [
                'browse_node_id'   => 17,
                'name'             => 'Fiction',
                'parent_id'        => 1000,
                'path'             => 'Books (1000),Fiction (17)',
                'level'            => 1,
                'created_at'       => now(),
                'updated_at'       => now(),
                'processed_at'     => null,
                'processed_status' => null,
            ],
            [
                'browse_node_id'   => 25,
                'name'             => 'Science Fiction',
                'parent_id'        => 17,
                'path'             => 'Books (1000),Fiction (17),Science Fiction (25)',
                'level'            => 2,
                'created_at'       => now(),
                'updated_at'       => now(),
                'processed_at'     => null,
                'processed_status' => null,
            ],
        ];

        $result = $importer->insertNodesByLevel($nodes);

        $this->assertSame(3, $result['inserted']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0, $result['errors']);

        $count = DB::table('amazon_browse_nodes')->count();
        $this->assertSame(3, $count);

        // Verify hierarchy
        $level0 = DB::table('amazon_browse_nodes')->where('level', 0)->first();
        $this->assertSame('Books', $level0->name);
        $this->assertNull($level0->parent_id);

        $level1 = DB::table('amazon_browse_nodes')->where('level', 1)->first();
        $this->assertSame('Fiction', $level1->name);
        $this->assertSame(1000, $level1->parent_id);

        $level2 = DB::table('amazon_browse_nodes')->where('level', 2)->first();
        $this->assertSame('Science Fiction', $level2->name);
        $this->assertSame(17, $level2->parent_id);

        $this->assertLessThan($level1->id, $level0->id, 'Level 0 should be inserted before level 1');
        $this->assertLessThan($level2->id, $level1->id, 'Level 1 should be inserted before level 2');
    }

    public function testInsertNodesByLevelWithCustomBatchSize(): void
    {
        $importer = new AmazonBrowseNodeImporter();

        $nodes = [];
        for ($i = 1; $i <= 10; $i++) {
            $nodes[] = [
                'browse_node_id'   => $i,
                'name'             => "Node {$i}",
                'parent_id'        => null,
                'path'             => "Node {$i} ({$i})",
                'level'            => 0,
                'created_at'       => now(),
                'updated_at'       => now(),
                'processed_at'     => null,
                'processed_status' => null,
            ];
        }

        $result = $importer->insertNodesByLevel($nodes, 3);

        $this->assertSame(10, $result['inserted']);
        $count = DB::table('amazon_browse_nodes')->count();
        $this->assertSame(10, $count);
    }

    public function testInsertNodesByLevelWithEmptyArray(): void
    {
        $importer = new AmazonBrowseNodeImporter();

        $result = $importer->insertNodesByLevel([]);

        $this->assertSame(0, $result['inserted']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0, $result['errors']);
    }

    public function testInsertNodesByLevelSkipsDuplicates(): void
    {
        $importer = new AmazonBrowseNodeImporter();

        $nodes = [
            [
                'browse_node_id'   => 5000,
                'name'             => 'Test Category',
                'parent_id'        => null,
                'path'             => 'Test Category (5000)',
                'level'            => 0,
                'created_at'       => now(),
                'updated_at'       => now(),
                'processed_at'     => null,
                'processed_status' => null,
            ],
            [
                'browse_node_id'   => 5001,
                'name'             => 'Test Subcategory',
                'parent_id'        => 5000,
                'path'             => 'Test Category (5000),Test Subcategory (5001)',
                'level'            => 1,
                'created_at'       => now(),
                'updated_at'       => now(),
                'processed_at'     => null,
                'processed_status' => null,
            ],
        ];

        // First insert
        $result1 = $importer->insertNodesByLevel($nodes);
        $this->assertSame(2, $result1['inserted']);
        $this->assertSame(0, $result1['skipped']);
        $this->assertSame(0, $result1['errors']);

        // Second insert (duplicates should be skipped)
        $result2 = $importer->insertNodesByLevel($nodes);
        $this->assertSame(0, $result2['inserted']);
        $this->assertSame(2, $result2['skipped']);
        $this->assertSame(0, $result2['errors']);

        // Verify only 2 rows exist
        $count = DB::table('amazon_browse_nodes')->whereIn('browse_node_id', [5000, 5001])->count();
        $this->assertSame(2, $count);
    }

    public function testImportBrowseNodesFromCsvCompleteWorkflow(): void
    {
        $csv = <<<CSV
Department,Node1,Node2
"Books (1000)","Fiction (17)","Science Fiction (25)"
"Electronics (172282)","Computers (541966)"
CSV;

        $this->putCsvInStorage('test.csv', $csv);

        $importer = new AmazonBrowseNodeImporter();
        $importer->setFileName('test.csv');

        $result = $importer->importBrowseNodesFromCsv();

        $this->assertArrayHasKey('inserted', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertSame(5, $result['inserted']);
        $this->assertSame(0, $result['errors']);

        $count = DB::table('amazon_browse_nodes')->count();
        $this->assertSame(5, $count);
    }

    public function testImportBrowseNodesFromCsvWithCustomBatchSize(): void
    {
        $csv = <<<CSV
Department,Node1
"Books (1000)","Fiction (17)"
"Electronics (172282)","Computers (541966)"
CSV;

        $this->putCsvInStorage('test.csv', $csv);

        $importer = new AmazonBrowseNodeImporter();
        $importer->setFileName('test.csv');

        $result = $importer->importBrowseNodesFromCsv(1);

        $this->assertSame(4, $result['inserted']);
    }
}
