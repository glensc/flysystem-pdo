<?php

namespace Phlib\Flysystem\Pdo\Tests;

use League\Flysystem\AdapterInterface;
use Phlib\Flysystem\Pdo\PdoAdapter;
use League\Flysystem\Config;
use PHPUnit_Extensions_Database_DataSet_IDataSet;
use PHPUnit_Extensions_Database_DB_IDatabaseConnection;
use PHPUnit_Extensions_Database_DataSet_ArrayDataSet as ArrayDataSet;

/**
 * @group integration
 */
class IntegrationTest extends \PHPUnit_Extensions_Database_TestCase
{
    use MemoryTestTrait;

    /**
     * @var \PDO
     */
    protected static $pdo;

    /**
     * @var string
     */
    protected static $driver;

    /**
     * @var array
     */
    protected static $tempFiles = [];

    /**
     * @var PdoAdapter
     */
    protected $adapter;

    /**
     * @var Config
     */
    protected $emptyConfig;

    /**
     * @var array
     */
    protected $tempHandles = [];

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        if (!isset($GLOBALS['PDO_DSN']) || !isset($GLOBALS['PDO_USER']) || !isset($GLOBALS['PDO_PASS']) || !isset($GLOBALS['PDO_DBNAME'])) {
            // insufficient values to work with
            return;
        }

        $dsn            = $GLOBALS['PDO_DSN'];
        static::$driver = substr($dsn, 0, strpos($dsn, ':'));
        static::$pdo    = new \PDO($dsn, $GLOBALS['PDO_USER'], $GLOBALS['PDO_PASS']);

        // create files
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('flysystempdo-test-', true);
        $tmpDir             = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        $emptyFilename      = $tmpDir . uniqid('flysystempdo-test-00B-', true);
        $tenByteFilename    = $tmpDir . uniqid('flysystempdo-test-10B-', true);
        $tenKayFilename     = $tmpDir . uniqid('flysystempdo-test-10K-', true);
        $fifteenMegFilename = $tmpDir . uniqid('flysystempdo-test-15M-', true);
        static::fillFile($emptyFilename, 0);
        static::fillFile($tenByteFilename, 10);
        static::fillFile($tenKayFilename, 10 * 1024);
        static::fillFile($fifteenMegFilename, 15 * 1024 * 1024);
        static::$tempFiles = [
            '00B' => $emptyFilename,
            '10B' => $tenByteFilename,
            '10K' => $tenKayFilename,
            '15M' => $fifteenMegFilename
        ];
    }

    public static function tearDownAfterClass()
    {
        static::$driver = null;
        static::$pdo    = null;
        foreach (static::$tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDownAfterClass();
    }

    public function setUp()
    {
        if (!static::$pdo instanceof \PDO) {
            $this->markTestSkipped();
            return;
        }

        parent::setUp();

        $this->adapter = new PdoAdapter(static::$pdo);

        $config = [];
        if (static::$driver == 'mysql') {
            $config['disable_mysql_buffering'] = true;
        }
        $this->emptyConfig = new Config($config);
    }

    public function tearDown()
    {
        foreach ($this->tempHandles as $tempHandle) {
            if (is_resource($tempHandle)) {
                fclose($tempHandle);
            }
        }

        $this->emptyConfig = null;
        $this->adapter = null;
        parent::tearDown();
    }

    /**
     * @return PHPUnit_Extensions_Database_DB_IDatabaseConnection
     */
    public function getConnection()
    {
        return $this->createDefaultDBConnection(static::$pdo, $GLOBALS['PDO_DBNAME']);
    }

    /**
     * mysqldump -hdhost --xml -t -uroot -p dbname flysystem_chunk flysystem_path > tests/_files/mysql-integration.xml
     * @return PHPUnit_Extensions_Database_DataSet_IDataSet
     * @throws \Exception
     */
    protected function getDataSet()
    {
        switch (static::$driver) {
            case 'mysql':
                $dataSetFile = dirname(__FILE__) . '/_files/mysql-integration.xml';
                return $this->createMySQLXMLDataSet($dataSetFile);
            case 'sqlite':
                $dataSetFile = dirname(__FILE__) . '/_files/sqlite-integration.xml';
                return $this->createXMLDataSet($dataSetFile);
            default:
                $driver = static::$driver;
                throw new \Exception("Missing dataset for '{$driver}'");
        }
    }

    public function testWritingEmptyFile()
    {
        $filename = static::$tempFiles['00B'];
        $handle   = fopen($filename, 'r');
        $this->adapter->writeStream('/path/to/file.txt', $handle, $this->emptyConfig);
        $this->assertEquals(0, $this->getConnection()->getRowCount('flysystem_chunk'));
    }

    /**
     * @param callable $fileCallback
     * @param string $writeMethod
     * @param string $readMethod
     * @param Config $config
     * @dataProvider writtenAndReadAreTheSameFileDataProvider
     */
    public function testWrittenAndReadAreTheSameFile($fileCallback, $writeMethod, $readMethod, $config)
    {
        $filename = static::$tempFiles['10K'];
        $file     = call_user_func($fileCallback, $filename);

        $path = '/path/to/file.txt';
        $this->adapter->$writeMethod($path, $file, $config);
        $meta = $this->adapter->$readMethod($path);

        if (is_resource($file)) {
            rewind($file);
            $file = stream_get_contents($file);
        }
        if (isset($meta['stream'])) {
            $meta['contents'] = stream_get_contents($meta['stream']);
        }

        $this->assertEquals($file, $meta['contents']);
    }

    public function writtenAndReadAreTheSameFileDataProvider()
    {
        $compressionConfig  = new Config(['enable_compression' => true]);
        $uncompressedConfig = new Config(['enable_compression' => false]);
        return [
            [[$this, 'createResource'], 'writeStream', 'readStream', $compressionConfig],
            [[$this, 'createResource'], 'writeStream', 'read', $compressionConfig],
            ['file_get_contents', 'write', 'readStream', $compressionConfig],
            ['file_get_contents', 'write', 'read', $compressionConfig],
            [[$this, 'createResource'], 'writeStream', 'readStream', $uncompressedConfig],
            [[$this, 'createResource'], 'writeStream', 'read', $uncompressedConfig],
            ['file_get_contents', 'write', 'readStream', $uncompressedConfig],
            ['file_get_contents', 'write', 'read', $uncompressedConfig],
        ];
    }

    /**
     * @param callable $fileCallback
     * @param string $updateMethod
     * @param string $readMethod
     * @param Config $config
     * @dataProvider updatedAndReadAreTheSameFileDataProvider
     */
    public function testUpdatedAndReadAreTheSameFile($fileCallback, $updateMethod, $readMethod, $config)
    {
        $path = '/path/to/file.txt';
        $this->adapter->write($path, file_get_contents(static::$tempFiles['10B']), $this->emptyConfig);

        $filename = static::$tempFiles['10K'];
        $file     = call_user_func($fileCallback, $filename);

        $this->adapter->$updateMethod($path, $file, $config);
        $meta = $this->adapter->$readMethod($path);

        if (is_resource($file)) {
            rewind($file);
            $file = stream_get_contents($file);
        }
        if (isset($meta['stream'])) {
            $meta['contents'] = stream_get_contents($meta['stream']);
        }
        $this->assertEquals($file, $meta['contents']);
    }

    public function updatedAndReadAreTheSameFileDataProvider()
    {
        $compressionConfig  = new Config(['enable_compression' => true]);
        $uncompressedConfig = new Config(['enable_compression' => false]);
        return [
            [[$this, 'createResource'], 'updateStream', 'readStream', $compressionConfig],
            [[$this, 'createResource'], 'updateStream', 'read', $compressionConfig],
            ['file_get_contents', 'update', 'readStream', $compressionConfig],
            ['file_get_contents', 'update', 'read', $compressionConfig],
            [[$this, 'createResource'], 'updateStream', 'readStream', $uncompressedConfig],
            [[$this, 'createResource'], 'updateStream', 'read', $uncompressedConfig],
            ['file_get_contents', 'update', 'readStream', $uncompressedConfig],
            ['file_get_contents', 'update', 'read', $uncompressedConfig],
        ];
    }

    public function testCopyingFile()
    {
        $path1 = '/first.txt';
        $path2 = '/second.txt';
        $this->adapter->write($path1, file_get_contents(static::$tempFiles['10B']), $this->emptyConfig);
        $this->adapter->copy($path1, $path2);

        $meta1 = $this->adapter->read($path1);
        $meta2 = $this->adapter->read($path2);

        $this->assertEquals($meta1['contents'], $meta2['contents']);
    }

    public function testCompressionIsSetOnThePath()
    {
        $filename = static::$tempFiles['10B'];
        $file     = $this->createResource($filename);
        $path     = '/path/to/file.txt';
        $meta     = $this->adapter->writeStream($path, $file, new Config(['enable_compression' => true]));

        $rows     = [['is_compressed' => 1]];
        $sql      = "SELECT is_compressed FROM flysystem_path WHERE path_id = {$meta['path_id']}";
        $expected = (new ArrayDataSet(['flysystem_path' => $rows]))->getTable('flysystem_path');
        $actual   = $this->getConnection()->createQueryTable('flysystem_path', $sql);

        $this->assertTablesEqual($expected, $actual);
    }

    public function testCopyingPathMakesAccurateCopy()
    {
        $origPath = '/path/to/file.txt';
        $content  = file_get_contents(static::$tempFiles['10B']);
        $origMeta = $this->adapter->write($origPath, $content, $this->emptyConfig);

        $copyPath = '/path/to/copy.txt';
        $copyMeta = $this->adapter->copy($origPath, $copyPath);

        $connection  = $this->getConnection();
        $select      = 'SELECT type, path, mimetype, visibility, size, is_compressed FROM flysystem_path WHERE path_id = %d';
        $origDataSet = $connection->createQueryTable('flysystem_path', sprintf($select, [$origMeta['path_id']]));
        $copyDataSet = $connection->createQueryTable('flysystem_path', sprintf($select, [$copyMeta['path_id']]));

        $this->assertTablesEqual($origDataSet, $copyDataSet);
    }

    public function testCopyingPathMakesAccurateCopyOfChunks()
    {
        $origPath = '/path/to/file.txt';
        $content  = file_get_contents(static::$tempFiles['10B']);
        $origMeta = $this->adapter->write($origPath, $content, $this->emptyConfig);

        $copyPath = '/path/to/copy.txt';
        $copyMeta = $this->adapter->copy($origPath, $copyPath);

        $connection  = $this->getConnection();
        $select      = 'SELECT chunk_no, content FROM flysystem_chunk WHERE path_id = %d';
        $origDataSet = $connection->createQueryTable('flysystem_chunk', sprintf($select, [$origMeta['path_id']]));
        $copyDataSet = $connection->createQueryTable('flysystem_chunk', sprintf($select, [$copyMeta['path_id']]));

        $this->assertTablesEqual($origDataSet, $copyDataSet);
    }

    public function testMemoryUsageOnWritingStream()
    {
        $filename = static::$tempFiles['15M'];
        $file     = fopen($filename, 'r');
        $path     = '/path/to/file.txt';

        $variation = 2 * 1024 * 1024; // 2MB
        $this->memoryTest(function() use ($path, $file) {
            $this->adapter->writeStream($path, $file, $this->emptyConfig);
        }, $variation);
    }

    public function testMemoryUsageOnReadingStreamWithBuffering()
    {
        $config = $this->emptyConfig;
        if (static::$driver == 'mysql') {
            $config = new Config(['enable_mysql_buffering' => true]);
        }
        $adapter = new PdoAdapter(static::$pdo, $config);

        $filename = static::$tempFiles['15M'];
        $file     = fopen($filename, 'r');
        $path     = '/path/to/file.txt';

        $adapter->writeStream($path, $file, $this->emptyConfig);

        $variation = 2 * 1024 * 1024; // 2MB
        $this->memoryTest(function () use ($adapter, $path) {
            $adapter->readStream($path);
        }, $variation);
    }

    public function testMemoryUsageOnReadingStreamWithoutBuffering()
    {
        if (static::$driver != 'mysql') {
            $this->markTestSkipped('Cannot test buffering on non mysql driver.');
            return;
        }

        $config  = new Config(['enable_mysql_buffering' => false]);
        $adapter = new PdoAdapter(static::$pdo, $config);

        $filename = static::$tempFiles['15M'];
        $file     = fopen($filename, 'r');
        $path     = '/path/to/file.txt';

        $adapter->writeStream($path, $file, $this->emptyConfig);

        $variation = 2 * 1024 * 1024; // 2MB
        $this->memoryTest(function () use ($adapter, $path) {
            $adapter->readStream($path);
        }, $variation);
    }

    public function testMemoryUsageOnUpdateStream()
    {
        $path = '/path/to/file.txt';
        $file = fopen(static::$tempFiles['10K'], 'r');
        $this->adapter->writeStream($path, $file, $this->emptyConfig);
        fclose($file);

        $file = fopen(static::$tempFiles['15M'], 'r');

        $variation = 2 * 1024 * 1024; // 2MB
        $this->memoryTest(function() use ($path, $file) {
            $this->adapter->updateStream($path, $file, $this->emptyConfig);
        }, $variation);
    }

    /**
     * @param array $paths
     * @param int $expectedRows
     * @dataProvider pathsDataProvider
     */
    public function testAddingPaths(array $paths, $expectedRows)
    {
        foreach ($paths as $path) {
            if ($path['type'] == 'dir') {
                $this->adapter->createDir($path['name'], $this->emptyConfig);
            } else {
                $this->adapter->write($path['name'], '', $this->emptyConfig);
            }
        }
        $this->assertEquals($expectedRows, $this->getConnection()->getRowCount('flysystem_path'));
    }

    /**
     * @param array $paths
     * @param int $expectedRows
     * @dataProvider pathsDataProvider
     */
    public function testListContentsMeetsExpectedOutput(array $paths, $expectedRows)
    {
        foreach ($paths as $path) {
            if ($path['type'] == 'dir') {
                $this->adapter->createDir($path['name'], $this->emptyConfig);
            } else {
                $this->adapter->write($path['name'], '', $this->emptyConfig);
            }
        }

        // extraneous data to throw off the listings
        $this->adapter->createDir('/not/this', $this->emptyConfig);
        $this->adapter->createDir('/not/that', $this->emptyConfig);
        $this->adapter->write('/not/this/too.txt', '', $this->emptyConfig);

        $this->assertCount($expectedRows, $this->adapter->listContents('/test', true));
    }

    public function pathsDataProvider()
    {
        $dir1  = ['type' => 'dir', 'name' => '/test'];
        $dir2  = ['type' => 'dir', 'name' => '/test/sub1'];
        $dir3  = ['type' => 'dir', 'name' => '/test/sub2'];
        $file1 = ['type' => 'file', 'name' => '/test/file1.txt'];
        $file2 = ['type' => 'file', 'name' => '/test/file2.txt'];
        $file3 = ['type' => 'file', 'name' => '/test/file3.txt'];

        return [
            [[$dir1], 1],
            [[$dir1, $dir2], 2],
            [[$dir1, $dir2, $dir3], 3],
            [[$file1], 1],
            [[$file1, $file2], 2],
            [[$file1, $file2, $file3], 3],
            [[$dir1, $file1], 2],
            [[$dir1, $file1, $file2], 3],
            [[$dir1, $dir2, $file1], 3],
            [[$dir1, $dir2, $dir3, $file1, $file2, $file3], 6]
        ];
    }

    public function testDeletingDirectoryClearsAllFiles()
    {
        $this->adapter->createDir('/test', $this->emptyConfig);
        $this->adapter->write('/test/file.txt', '', $this->emptyConfig);

        $this->assertEquals(2, $this->getConnection()->getRowCount('flysystem_path'));
        $this->adapter->deleteDir('/test');
        $this->assertEquals(0, $this->getConnection()->getRowCount('flysystem_path'));
    }

    public function testDeletingFileClearsAllChunks()
    {
        $file = file_get_contents(static::$tempFiles['15M']);
        $this->adapter->write('/test.txt', $file, $this->emptyConfig);

        $this->assertGreaterThan(0, $this->getConnection()->getRowCount('flysystem_chunk'));
        $this->adapter->delete('/test.txt');
        $this->assertEquals(0, $this->getConnection()->getRowCount('flysystem_chunk'));
    }

    public function testReadingNonExistentPath()
    {
        $this->assertFalse($this->adapter->read('/path/does/not/exist.txt'));
    }

    public function testReadingStreamForNonExistentPath()
    {
        $this->assertFalse($this->adapter->readStream('/path/does/not/exist.txt'));
    }

    public function testHasForNonExistentPath()
    {
        $this->assertFalse($this->adapter->has('/path/does/not/exist.txt'));
    }

    public function testHasForExistingPath()
    {
        $path = '/this/path/does/exist.txt';
        $this->adapter->write($path, 'some text', $this->emptyConfig);
        $this->assertTrue($this->adapter->has($path));
    }

    public function testCopyingNonExistentPath()
    {
        $this->assertFalse($this->adapter->copy('/this/does/not/exist.txt', '/my/new/path.txt'));
    }

    public function testCopyingProducesTheSameFile()
    {
        $path = '/this/path.txt';
        $file = $this->createResource(static::$tempFiles['10B']);
        $this->adapter->writeStream($path, $file, $this->emptyConfig);
    }

    public function testSettingVisibility()
    {
        $path = '/test.txt';
        $config = new Config(['visibility' => AdapterInterface::VISIBILITY_PUBLIC]);
        $meta = $this->adapter->write($path, 'Some Content', $config);

        $this->adapter->setVisibility($path, AdapterInterface::VISIBILITY_PRIVATE);

        $rows     = [['visibility' => AdapterInterface::VISIBILITY_PRIVATE]];
        $expected = (new ArrayDataSet(['flysystem_path' => $rows]))->getTable('flysystem_path');
        $select   = "SELECT visibility FROM flysystem_path WHERE path_id = {$meta['path_id']}";
        $actual   = $this->getConnection()->createQueryTable('flysystem_path', $select);

        $this->assertTablesEqual($expected, $actual);
    }

    protected static function fillFile($filename, $sizeKb)
    {
        $chunkSize = 1024;
        $handle    = fopen($filename, 'wb+');
        for ($i = 0; $i < $sizeKb; $i += $chunkSize) {
            fwrite($handle, static::randomString($chunkSize), $chunkSize);
        }
        fclose($handle);
    }

    protected static function randomString($length)
    {
        static $characters;
        static $charLength;
        if (!$characters) {
            $characters = array_merge(range(0, 9), range('a', 'z'), range('A', 'Z'), [',', '.', ' ', "\n"]);
            $charLength = count($characters);
            shuffle($characters);
        }

        $string = '';
        $end = ($charLength - 1);
        for ($i = 0; $i < $length; $i++) {
            $string .= $characters[rand(0, $end)];
        }
        return $string;
    }

    protected function createResource($filename)
    {
        $handle = fopen($filename, 'r');
        $this->tempHandles[] = $handle;
        return $handle;
    }
}
