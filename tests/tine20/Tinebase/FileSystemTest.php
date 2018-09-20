<?php
/**
 * Tine 2.0 - http://www.tine20.org
 * 
 * @package     Tinebase
 * @license     http://www.gnu.org/licenses/agpl.html
 * @copyright   Copyright (c) 2010-2018 Metaways Infosystems GmbH (http://www.metaways.de)
 * @author      Lars Kneschke <l.kneschke@metaways.de>
 */

/**
 * Test class for Tinebase_FileSystem
 */
class Tinebase_FileSystemTest extends TestCase
{
    /**
     * @var array test objects
     */
    protected $objects = array();

    /**
     * @var Tinebase_FileSystem
     */
    protected $_controller;

    protected $_oldModLog;
    protected $_oldIndexContent;
    protected $_oldCreatePreview;
    protected $_oldNotification;
    protected $_oldQuota;

    protected $_rmDir = array();

    protected $_transactionId = null;

    /**
     * Sets up the fixture.
     * This method is called before a test is executed.
     *
     * @access protected
     */
    protected function setUp()
    {
        if (empty(Tinebase_Core::getConfig()->filesdir)) {
            $this->markTestSkipped('filesystem base path not found');
        }

        parent::setUp();

        $this->_rmDir = array();
        $this->_oldNotification = Tinebase_Core::getConfig()->{Tinebase_Config::FILESYSTEM}->{Tinebase_Config::FILESYSTEM_ENABLE_NOTIFICATIONS};
        $this->_oldModLog = Tinebase_Core::getConfig()->{Tinebase_Config::FILESYSTEM}->{Tinebase_Config::FILESYSTEM_MODLOGACTIVE};
        Tinebase_Core::getConfig()->{Tinebase_Config::FILESYSTEM}->{Tinebase_Config::FILESYSTEM_MODLOGACTIVE} = true;
        $this->_oldIndexContent = Tinebase_Core::getConfig()->{Tinebase_Config::FILESYSTEM}->{Tinebase_Config::FILESYSTEM_INDEX_CONTENT};
        $this->_oldCreatePreview = Tinebase_Config::getInstance()->{Tinebase_Config::FILESYSTEM}->{Tinebase_Config::FILESYSTEM_CREATE_PREVIEWS};
        $this->_oldQuota = Tinebase_Config::getInstance()->{Tinebase_Config::QUOTA};

        $this->_controller = new Tinebase_FileSystem();
        $this->_basePath   = '/' . Tinebase_Application::getInstance()->getApplicationByName('Tinebase')->getId() . '/folders/' . Tinebase_Model_Container::TYPE_SHARED;
        
        $this->_controller->initializeApplication(Tinebase_Application::getInstance()->getApplicationByName('Tinebase'));
    }

    /**
     * Tears down the fixture
     * This method is called after a test is executed.
     *
     * @access protected
     */
    protected function tearDown()
    {
        $fsConfig = Tinebase_Core::getConfig()->get(Tinebase_Config::FILESYSTEM);
        $fsConfig->{Tinebase_Config::FILESYSTEM_MODLOGACTIVE} = $this->_oldModLog;
        $fsConfig->{Tinebase_Config::FILESYSTEM_INDEX_CONTENT} = $this->_oldIndexContent;
        $fsConfig->{Tinebase_Config::FILESYSTEM_CREATE_PREVIEWS} = $this->_oldCreatePreview;
        $fsConfig->{Tinebase_Config::FILESYSTEM_ENABLE_NOTIFICATIONS} = $this->_oldNotification;
        Tinebase_Config::getInstance()->{Tinebase_Config::QUOTA} = $this->_oldQuota;

        Tinebase_FileSystem::getInstance()->resetBackends();

        parent::tearDown();

        Tinebase_FileSystem::getInstance()->clearStatCache();

        if (!empty($this->_rmDir)) {
            try {
                foreach($this->_rmDir as $rmDir) {
                    Tinebase_FileSystem::getInstance()->rmdir($rmDir, true);
                }
            } catch (Exception $e) {
            }
            Tinebase_FileSystem::getInstance()->clearStatCache();
        }

        Tinebase_FileSystem::getInstance()->clearDeletedFilesFromFilesystem(false);
    }
    
    public function testMkdir()
    {
        $basePathNode = $this->_controller->stat($this->_basePath);
        
        $testPath = $this->_basePath . '/PHPUNIT';
        $node = $this->_controller->mkdir($testPath);
        
        $this->assertInstanceOf('Tinebase_Model_Tree_Node', $node);
        $this->assertTrue($this->_controller->fileExists($testPath), 'path created by mkdir not found');
        $this->assertTrue($this->_controller->isDir($testPath),      'path created by mkdir is not a directory');
        $this->assertEquals(1, $node->revision);
        $this->assertNotEquals($basePathNode->hash, $this->_controller->stat($this->_basePath)->hash);
        static::assertNull($node->deleted_time, 'deleted_time should be null');
        
        return $testPath;
    }

    public function testMkdirFailAppId()
    {
        try {
            $this->_controller->mkdir('/shalalala');
            static::fail('it should not be possible to create a path like /shalalala');
        } catch (Tinebase_Exception_InvalidArgument $teia) {
            static::assertEquals('path needs to start with /appId/folders/...', $teia->getMessage());
        }
    }

    public function testMkdirFailFoldersPart()
    {
        try {
            $path = '/' . Tinebase_Core::getTinebaseId() . '/shalalala';
            $this->_controller->mkdir($path);
            static::fail('it should not be possible to create a path like ' . $path);
        } catch (Tinebase_Exception_InvalidArgument $teia) {
            static::assertEquals('path needs to start with /appId/folders/...', $teia->getMessage());
        }
    }
    
    public function testDirectoryHashUpdate()
    {
        $testPath = $this->testMkdir();
        
        $basePathNode = $this->_controller->stat($testPath);
        
        $this->assertEquals(1, $basePathNode->revision);
        $this->assertNotEmpty($basePathNode->hash);
        
        $testNode = $this->_controller->mkdir($testPath . '/phpunit');
        
        $this->assertEquals(1, $testNode->revision);
        $this->assertNotEmpty($testNode->hash);
        $this->assertNotEquals($basePathNode->hash, $this->_controller->stat($testPath)->hash);
    }
    
    /**
     * test copying directory to existing directory
     */
    public function testCopyDirectoryToExistingDirectory()
    {
        $sourcePath      = $this->testMkdir();
        $destinationPath = $this->_basePath . '/TESTCOPY2';
        $this->_controller->mkdir($destinationPath);
        
        $createdNode = $this->_controller->copy($sourcePath, $destinationPath);
        
        $this->assertNotEquals($this->_controller->stat($sourcePath)->getId(), $createdNode->getId());
        $this->assertEquals(Tinebase_Model_Tree_FileObject::TYPE_FOLDER, $createdNode->type);
        $this->assertEquals($this->_controller->stat($sourcePath)->name, $createdNode->name);
        $this->assertTrue($this->_controller->fileExists($this->_basePath . '/TESTCOPY2/PHPUNIT'));
    }
    
    /**
     * test copying file to existing directory
     */
    public function testCopyFileToExistingDirectory()
    {
        $sourcePath      = $this->testCreateFile();
        $destinationPath = $this->_basePath . '/TESTCOPY2';
        $this->_controller->mkdir($destinationPath);
        
        $createdNode = $this->_controller->copy($sourcePath, $destinationPath);
        
        $this->assertNotEquals($this->_controller->stat($sourcePath)->getId(), $createdNode->getId());
        $this->assertEquals(Tinebase_Model_Tree_FileObject::TYPE_FILE, $createdNode->type);
        $this->assertEquals($this->_controller->stat($sourcePath)->name, $createdNode->name);
        $this->assertTrue($this->_controller->fileExists($this->_basePath . '/TESTCOPY2/' . basename($sourcePath)));
    }
    
    /**
     * test copying file to existing directory and change name
     */
    public function testCopyFileToExistingDirectoryAndChangeName()
    {
        $sourcePath      = $this->testCreateFile();
        $destinationPath = $this->_basePath . '/TESTCOPY2/phpunit2.txt';

        $this->_controller->mkdir($this->_basePath . '/TESTCOPY2');
        
        $createdNode = $this->_controller->copy($sourcePath, $destinationPath);
        
        $this->assertNotEquals($this->_controller->stat($sourcePath)->getId(), $createdNode->getId());
        $this->assertEquals(Tinebase_Model_Tree_FileObject::TYPE_FILE, $createdNode->type);
        $this->assertEquals(basename($destinationPath), $createdNode->name);
        $this->assertTrue($this->_controller->fileExists($this->_basePath . '/TESTCOPY2/' . basename($destinationPath)));
    }
    
    /**
     * test copying directory to existing directory
     */
    public function testCopySourceAndDestinationTheSame()
    {
        $sourcePath      = $this->testCreateFile();
        $destinationPath = $this->testMkdir();
        
        $this->setExpectedException('Tinebase_Exception_UnexpectedValue');

        $this->_controller->copy($sourcePath, $destinationPath);
    }
    
    public function testRename()
    {
        $testPath = $this->testMkdir();
        $this->testCreateFile();
    
        $testPath2 = $testPath . '/RENAMED';
        Tinebase_FileSystem::getInstance()->mkdir($testPath2);
    
        Tinebase_FileSystem::getInstance()->rename($testPath . '/phpunit.txt', $testPath2 . '/phpunit2.txt');
    
        $nameOfChildren = Tinebase_FileSystem::getInstance()->scanDir($testPath)->name;
        $this->assertFalse(in_array('phpunit.txt', $nameOfChildren));

        $nameOfChildren = Tinebase_FileSystem::getInstance()->scanDir($testPath2)->name;
        $this->assertTrue(in_array('phpunit2.txt', $nameOfChildren));
    }
    
    public function testRmdir()
    {
        $testPath = $this->testMkdir();
    
        $result = $this->_controller->rmdir($testPath);
    
        $this->assertTrue($result,                                    'wrong result for rmdir command');
        $this->assertFalse($this->_controller->fileExists($testPath), 'failed to delete directory');

        return $testPath;
    }

    public function testRecreateDir()
    {
        $testPath = $this->testRmdir();

        $node = $this->_controller->mkdir($testPath);
        $this->assertTrue($this->_controller->isDir($testPath),      'path created by mkdir is not a directory');

        $this->assertEquals(1, $node->revision);
    }
    
    public function testScandir()
    {
        $this->testMkdir();
        
        $children = $this->_controller->scanDir($this->_basePath)->name;
        
        $this->assertTrue(in_array('PHPUNIT', $children));
    }
    
    public function testStat()
    {
        $this->testCreateFile();
    
        $node = $this->_controller->stat($this->_basePath . '/PHPUNIT/phpunit.txt');
    
        $this->assertEquals(Tinebase_Model_Tree_FileObject::TYPE_FILE, $node->type);
        $this->assertEquals('phpunit.txt', $node->name);
        $this->assertEquals(7, $node->size);
    }
    
    /**
     * test for isDir with existing directory 
     */
    public function testIsDir()
    {
        $this->testMkdir();
        
        $result = $this->_controller->isDir($this->_basePath . '/PHPUNIT');
        
        $this->assertTrue($result);

        $result = $this->_controller->isFile($this->_basePath . '/PHPUNIT');
    
        $this->assertFalse($result);
    }
    
    /**
     * test for isDir with non existing directory
     */
    public function testIsDirNotExisting()
    {
        $result = $this->_controller->isDir($this->_basePath . '/PHPUNITNotExisting');
        
        $this->assertFalse($result);
    }
    
    public function testCreateFile($_name = 'phpunit.txt')
    {
        $testDir  = $this->testMkdir();
        $testFile = $_name;
        $testPath = $testDir . '/' . $testFile;

        $basePathNode = $this->_controller->stat($testDir);
        $this->assertEquals(1, $basePathNode->revision);

        $handle = $this->_controller->fopen($testPath, 'x');
        
        $this->assertEquals('resource', gettype($handle), 'opening file failed');
        
        $written = fwrite($handle, 'phpunit');
        
        $this->assertEquals(7, $written);
        
        $this->_controller->fclose($handle);

        $children = $this->_controller->scanDir($testDir)->name;

        $updatedBasePathNode = $this->_controller->stat($testDir);

        $this->assertContains($testFile, $children);
        $this->assertEquals(1, $updatedBasePathNode->revision);
        $this->assertNotEquals($basePathNode->hash, $updatedBasePathNode->hash);
        
        return $testPath;
    }

    public function testModLogAndIndexContent()
    {
        // check for tika installation
        if ('' == Tinebase_Core::getConfig()->get(Tinebase_Config::FULLTEXT)->{Tinebase_Config::FULLTEXT_TIKAJAR}) {
            self::markTestSkipped('no tika.jar found');
        }

        $this->_rmDir[] = $this->_basePath . '/PHPUNIT';

        $fsConfig = Tinebase_Core::getConfig()->get(Tinebase_Config::FILESYSTEM);
        $fsConfig->{Tinebase_Config::FILESYSTEM_MODLOGACTIVE} = true;
        $fsConfig->{Tinebase_Config::FILESYSTEM_INDEX_CONTENT} = true;
        $fsConfig->{Tinebase_Config::FILESYSTEM_MONTHKEEPREVISIONS} = 1;
        $fsConfig->{Tinebase_Config::FILESYSTEM_NUMKEEPREVISIONS} = 1;
        $this->_controller->resetBackends();

        $testPath = $this->testCreateFile();

        $node = $this->_controller->stat($testPath);
        $this->assertEquals(7, $node->size);
        $this->assertEquals(7, $node->revision_size);
        $this->assertEquals(array(1), $node->available_revisions);

        Tinebase_TransactionManager::getInstance()->commitTransaction($this->_transactionId);

        $this->_transactionId = Tinebase_TransactionManager::getInstance()->startTransaction(Tinebase_Core::getDb());

        $filter = new Tinebase_Model_Tree_Node_Filter(array(
            array('field' => 'id',          'operator' => 'equals',     'value' => $node->getId()),
            array('field' => 'content',     'operator' => 'contains',   'value' => 'phpunit'),
            array('field' => 'isIndexed',   'operator' => 'equals',     'value' => 1),
        ), /* $_condition = */ '', /* $_options */ array('ignoreAcl' => true));
        $result = $this->_controller->search($filter);
        $this->assertEquals(1, $result->count(), 'didn\'t find file (is tika.jar installed?)');


        $filter = new Tinebase_Model_Tree_Node_Filter(array(
            array('field' => 'id',          'operator' => 'equals',     'value' => $node->getId()),
            array('field' => 'content',     'operator' => 'contains',   'value' => 'shooo'),
            array('field' => 'isIndexed',   'operator' => 'equals',     'value' => 1),
        ), /* $_condition = */ '', /* $_options */ array('ignoreAcl' => true));
        $result = $this->_controller->search($filter);
        $this->assertEquals(0, $result->count(), 'did find file where non should be found');


        $handle = $this->_controller->fopen($testPath, 'w');
        $written = fwrite($handle, 'abcde');
        $this->assertEquals(5, $written);
        $this->_controller->fclose($handle);

        $node = $this->_controller->stat($testPath);
        $this->assertEquals(5, $node->size);
        $this->assertEquals(12, $node->revision_size);
        $this->assertEquals(array(1,2), $node->available_revisions);
        $this->assertEquals(2, $node->revision);
        $records = Tinebase_Notes::getInstance()->searchNotes(new Tinebase_Model_NoteFilter([
            ['field' => 'record_id', 'operator' => 'equals', 'value' => $node->getId()],
            ['field' => 'record_model', 'operator' => 'equals', 'value' => Tinebase_Model_Tree_Node::class],
        ]));
        static::assertEquals(1, $records->filter('note', '/revision \(0 -> 1\)/', true)->count(),
            'did not find "revision (0 -> 1)" in the notes');
        static::assertEquals(1, $records->filter('note', '/revision \(1 -> 2\)/', true)->count(),
            'did not find "revision (1 -> 2)" in the notes');
        static::assertEquals(0, $records->filter('note', '/hash \(/', true)->count(),
            'shouldn\'t find hash in the notes');


        Tinebase_TransactionManager::getInstance()->commitTransaction($this->_transactionId);
        $this->_transactionId = Tinebase_TransactionManager::getInstance()->startTransaction(Tinebase_Core::getDb());

        $filter = new Tinebase_Model_Tree_Node_Filter(array(
            array('field' => 'id',          'operator' => 'equals',     'value' => $node->getId()),
            array('field' => 'content',     'operator' => 'contains',   'value' => 'abcde'),
            array('field' => 'isIndexed',   'operator' => 'equals',     'value' => 1),
        ), /* $_condition = */ '', /* $_options */ array('ignoreAcl' => true));
        $result = $this->_controller->search($filter);
        $this->assertEquals(1, $result->count(), 'didn\'t find file');
        $this->assertTrue((boolean)$result->getFirstRecord()->isIndexed, 'isIndexed should be true');


        $filter = new Tinebase_Model_Tree_Node_Filter(array(
            array('field' => 'id',          'operator' => 'equals',     'value' => $node->getId()),
            array('field' => 'content',     'operator' => 'contains',   'value' => 'phpunit'),
            array('field' => 'isIndexed',   'operator' => 'equals',     'value' => 1),
        ), /* $_condition = */ '', /* $_options */ array('ignoreAcl' => true));
        $result = $this->_controller->search($filter);
        $this->assertEquals(0, $result->count(), 'did find file where non should be found');

        $streamContext = stream_context_create(array(
            'Tinebase_FileSystem_StreamWrapper' => array(
                'revision' => 1
            )
        ));
        $handle = fopen(Tinebase_Model_Tree_Node_Path::STREAMWRAPPERPREFIX . $testPath, 'r', false, $streamContext);
        $oldContent = fread($handle, 1024);
        $this->assertEquals('phpunit', $oldContent, 'could not properly read revision 1');

        $testPath2 = $this->testCreateFile('phpunittest2.txt');

        $handle = $this->_controller->fopen($testPath2, 'w');
        $written = fwrite($handle, 'abcdef');
        $this->assertEquals(6, $written);
        $this->_controller->fclose($handle);

        $node = $this->_controller->stat($testPath2);
        $this->assertEquals(6, $node->size);
        $this->assertEquals(13, $node->revision_size);
        $this->assertEquals(array(1,2), $node->available_revisions);
        $this->assertEquals(2, $node->revision);

        $db = Tinebase_Core::getDb();
        $db->query('UPDATE ' . $db->quoteIdentifier(SQL_TABLE_PREFIX . 'tree_filerevisions') . ' SET ' . $db->quoteIdentifier('creation_time') . ' = \'' . date('Y-m-d H:i:s', time() - 3600 * 24 * 30 * 3) . '\' WHERE ' . $db->quoteIdentifier('id') . ' = \'' . $node->object_id .'\' and ' . $db->quoteIdentifier('revision') . ' = 1')->closeCursor();

        $this->_controller->clearFileRevisions();
        $this->_controller->recalculateRevisionSize();
        $this->_controller->clearStatCache();

        $node = $this->_controller->stat($testPath);
        $this->assertEquals(5, $node->size);
        $this->assertEquals(5, $node->revision_size);
        $this->assertEquals(array(2), $node->available_revisions);
        $this->assertEquals(2, $node->revision);

        $node = $this->_controller->stat($testPath2);
        $this->assertEquals(6, $node->size);
        $this->assertEquals(6, $node->revision_size);
        $this->assertEquals(array(2), $node->available_revisions);
        $this->assertEquals(2, $node->revision);

        // test queries with dummy content:
        $fileObject = new Tinebase_Tree_FileObject(null, array(
            Tinebase_Config::FILESYSTEM_MODLOGACTIVE => true
        ));
        $fileObject->deleteRevisions('shooBiDuBiDu', array(1,2,3));
        $fileObject->clearOldRevisions('shooBiDuBiDu', 5);
    }

    /**
     * test for isDir with existing directory
     */
    public function testIsFile()
    {
        $this->testCreateFile();
    
        $result = $this->_controller->isFile($this->_basePath . '/PHPUNIT/phpunit.txt');
    
        $this->assertTrue($result);

        $result = $this->_controller->isDir($this->_basePath . '/PHPUNIT/phpunit.txt');
    
        $this->assertFalse($result);
    }
    
    public function testOpenFile()
    {
        $this->testCreateFile();
        
        $handle = $this->_controller->fopen($this->_basePath . '/PHPUNIT/phpunit.txt', 'r');
        
        $this->assertEquals('phpunit', stream_get_contents($handle), 'file content mismatch');
        
        $this->_controller->fclose($handle);
    }
    
    public function testDeleteFile()
    {
        $this->testCreateFile();
        
        $this->_controller->unlink($this->_basePath . '/PHPUNIT/phpunit.txt');
        
        $children = $this->_controller->scanDir($this->_basePath . '/PHPUNIT')->name;
        
        $this->assertTrue(!in_array('phpunit.txt', $children));
    }

    public function testRecreateFile()
    {
        $this->testDeleteFile();

        $modifications = Tinebase_Timemachine_ModificationLog::getInstance()->getReplicationModificationsByInstanceSeq(0, 10000);
        $instanceSeq = $modifications->getLastRecord()->instance_seq - 2;

        $path = $this->_basePath . '/PHPUNIT/phpunit.txt';

        $handle = $this->_controller->fopen($path, 'w');
        $this->assertEquals('resource', gettype($handle), 'opening file failed');
        $written = fwrite($handle, 'somethingNew');
        $this->assertEquals(12, $written);
        $this->_controller->fclose($handle);

        $children = $this->_controller->scanDir($this->_basePath . '/PHPUNIT')->name;

        $this->assertContains('phpunit.txt', $children);
        $handle = $this->_controller->fopen($path, 'r');
        $contents = stream_get_contents($handle);
        $this->_controller->fclose($handle);
        $this->assertEquals('somethingNew', $contents);

        $node = $this->_controller->stat($path);
        Tinebase_Timemachine_ModificationLog::getInstance()->undo(new Tinebase_Model_ModificationLogFilter(array(
            array('field' => 'record_id', 'operator' => 'in', 'value' => array($node->getId(), $node->object_id)),
            array('field' => 'instance_seq', 'operator' => 'greater', 'value' => $instanceSeq)
        )));

        clearstatcache();
        $this->_controller->clearStatCache();
        $handle = $this->_controller->fopen($path, 'r');
        $contents = stream_get_contents($handle);
        $this->_controller->fclose($handle);
        $this->assertEquals('phpunit', $contents);

        $handle = $this->_controller->fopen($path, 'w');
        $this->assertEquals('resource', gettype($handle), 'opening file failed');
        $written = fwrite($handle, 'somethingVeryNew');
        $this->assertEquals(16, $written);
        $this->_controller->fclose($handle);

        $handle = $this->_controller->fopen($path, 'r');
        $contents = stream_get_contents($handle);
        $this->_controller->fclose($handle);
        $this->assertEquals('somethingVeryNew', $contents);

        $handle = $this->_controller->fopen($path, 'w');
        $this->assertEquals('resource', gettype($handle), 'opening file failed');
        $written = fwrite($handle, 'somethingNew');
        $this->assertEquals(12, $written);
        $this->_controller->fclose($handle);

        $handle = $this->_controller->fopen($path, 'r');
        $contents = stream_get_contents($handle);
        $this->_controller->fclose($handle);
        $this->assertEquals('somethingNew', $contents);
    }

    public function testCopyRecreateFile()
    {
        $destinationPath = $this->_basePath . '/TESTCOPY2/phpunit.txt';
        $this->_controller->mkdir($this->_basePath . '/TESTCOPY2');
        $handle = $this->_controller->fopen($destinationPath, 'w');
        $this->assertEquals('resource', gettype($handle), 'opening file failed');
        $written = fwrite($handle, 'somethingNew');
        $this->assertEquals(12, $written);
        $this->_controller->fclose($handle);
        $node = $this->_controller->stat($destinationPath);
        $this->_controller->deleteFileNode($node);

        $this->testCopyFileToExistingDirectory();
    }
    
    public function testGetFileSize()
    {
        $this->testCreateFile();

        /** @noinspection PhpDeprecationInspection */
        $filesize = $this->_controller->filesize($this->_basePath . '/PHPUNIT/phpunit.txt');
        
        $this->assertEquals(7, $filesize);
    }
    
    /**
     * test get content type
     */
    public function testGetContentType()
    {
        $this->testCreateFile();

        /** @noinspection PhpDeprecationInspection */
        $contentType = $this->_controller->getContentType($this->_basePath . '/PHPUNIT/phpunit.txt');
        
        // finfo_open() for content type detection is only available in php versions >= 5.3.0'
        $expectedContentType = (version_compare(PHP_VERSION, '5.3.0', '>=') && function_exists('finfo_open')) ? 'text/plain' : 'application/octet-stream';
        
        $this->assertEquals($expectedContentType, $contentType);
    }
    
    public function testGetMTime()
    {
        $now = Tinebase_DateTime::now()->getTimestamp();
        
        $this->testCreateFile();
        
        $timestamp = $this->_controller->getMTime($this->_basePath . '/PHPUNIT/phpunit.txt');
        
        $this->assertGreaterThanOrEqual(sprintf('%u', $now), sprintf('%u', $timestamp));
    }
    
    public function testGetEtag()
    {
        $this->testCreateFile();
        
        $node = $this->_controller->stat($this->_basePath . '/PHPUNIT/phpunit.txt');

        /** @noinspection PhpDeprecationInspection */
        $etag = $this->_controller->getETag($this->_basePath . '/PHPUNIT/phpunit.txt');
        
        $this->assertEquals($node->hash, $etag);
    }
    
    /**
     * @return Tinebase_Model_Tree_Node
     */
    public static function getTestRecord()
    {
        $object  = new Tinebase_Model_Tree_Node(array(
            'name'     => 'PHPUnit test node',
        ), true);
        
        return $object;
    }

    /**
     * testGetAllChildFolderIds
     *
     * @see 0012788: allow acl for all folder nodes
     */
    public function testGetAllChildIds()
    {
        $scleverPath = $this->_getPersonalPath();
        $node = $this->_createAclNode($scleverPath);
        $subdir = $node->path . '/sub';
        $childNode = $this->_controller->mkdir($subdir);

        $result = $this->_controller->getAllChildIds(array($node->getId()));
        self::assertEquals(1, count($result));
        self::assertEquals($childNode->getId(), $result[0]);
    }

    /**
     * testCreateAclNodeWithGrants
     *
     * @see 0012788: allow acl for all folder nodes
     */
    public function testCreateAclNodeWithGrants()
    {
        // create acl node for another user
        $scleverPath = $this->_getPersonalPath();
        $node = $this->_createAclNode($scleverPath);
        $this->_testNodeAcl($node, $scleverPath);
    }

    /**
     * @param $parentPath
     * @return Tinebase_Model_Tree_Node
     * @throws Tinebase_Exception_SystemGeneric
     */
    protected function _createAclNode($parentPath)
    {
        $path = $parentPath . '/test';
        return $this->_controller->createAclNode($path);
    }

    /**
     * @param Tinebase_Model_Tree_Node $node
     * @param string $parentPath
     * @param string $persona
     * @throws Tinebase_Exception_InvalidArgument
     */
    protected function _testNodeAcl($node, $parentPath, $persona = 'sclever')
    {
        // check grant with hasGrant()
        self::assertFalse(Tinebase_Core::getUser()->hasGrant($node, Tinebase_Model_Grants::GRANT_READ),
            'unittest user should not have access to ' . $node->name . ' node');

        // try sclever
        /** @noinspection PhpUndefinedMethodInspection */
        self::assertTrue($this->_personas[$persona]->hasGrant($node, Tinebase_Model_Grants::GRANT_READ),
            $persona . ' user should have access to ' . $node->name . ' node');

        // test acl filter
        $filter = new Tinebase_Model_Tree_Node_Filter(array(
            array('field' => 'path', 'operator' => 'equals', 'value' => $parentPath)
        ));
        $result = $this->_controller->search($filter);
        self::assertEquals(0, count($result));

        // try $persona
        Tinebase_Core::set(Tinebase_Core::USER, $this->_personas[$persona]);
        $result = $this->_controller->search($filter);
        self::assertGreaterThanOrEqual(1, count($result), $persona . ' should see the node');
    }

    /**
     * testUpdateAclNodeChildren
     *
     * @see 0012788: allow acl for all folder nodes
     */
    public function testUpdateAclNodeChildren()
    {
        // create acl node and children
        $scleverPath = $this->_getPersonalPath();
        $node = $this->_createAclNode($scleverPath);
        $subdir = $node->path . '/sub';
        $childNode = $this->_controller->mkdir($subdir);

        self::assertEquals($node->acl_node, $childNode->acl_node);

        // check if grants are applied to children
        $this->_testNodeAcl($childNode, $node->path);
    }

    /**
     * testMakeExistingNodeAclNode
     *
     * @see 0012788: allow acl for all folder nodes
     */
    public function testMakeExistingNodeAclNode()
    {
        // create pwulf acl node and 2 children
        $path = $this->_getPersonalPath('pwulf');
        $node = $this->_createAclNode($path);
        $subdir = $node->path . '/sub';
        $childNode = $this->_controller->mkdir($subdir);
        $subsubdir = $subdir . '/subsub';
        $childChildNode = $this->_controller->mkdir($subsubdir);

        $this->_testNodeAcl($childNode, $node->path, 'pwulf');

        // make middle child acl node for sclever
        $this->_controller->setGrantsForNode($childNode, Tinebase_Model_Grants::getPersonalGrants($this->_personas['sclever']));

        // check sclever acl in third child
        $this->_testNodeAcl($childChildNode, $subdir);
        return array($node, $childNode, $childChildNode);
    }

    /**
     * testRemoveAclFromNode
     *
     * @see 0012788: allow acl for all folder nodes
     */
    public function testRemoveAclFromNode()
    {
        // create acl node and 2 children
        // make middle child acl node
        // check acl in third child
        // remove acl from middle child
        // check acl in third child again
        list(, $middleChildNode, $childChildNode) = $this->testMakeExistingNodeAclNode();
        Tinebase_Core::set(Tinebase_Core::USER, $this->_originalTestUser);

        $this->_controller->removeAclFromNode($middleChildNode);

        $middleChildNodePath = $this->_getPersonalPath('pwulf'). '/test/sub';
        $this->_testNodeAcl($childChildNode, $middleChildNodePath, 'pwulf');
    }

    /**
     * testMoveNodeAclUpdate
     *
     * @see 0012788: allow acl for all folder nodes
     */
    public function testMoveNodeAclUpdate()
    {
        list(, $middleChildNode, $childChildNode) = $this->testMakeExistingNodeAclNode();
        Tinebase_Core::set(Tinebase_Core::USER, $this->_originalTestUser);

        // create folder for sclever and move middlechild there
        $path = $this->_getPersonalPath('sclever');
        $node = $this->_createAclNode($path);
        $this->_controller->rename($this->_getPersonalPath('pwulf') . '/test/sub', $node->path . '/sub');

        // check sclever acl in middle and third child
        $this->_testNodeAcl($middleChildNode, $path . '/test');

        Tinebase_Core::set(Tinebase_Core::USER, $this->_originalTestUser);
        $middleChildNodePath = $path . '/test/sub';
        $this->_testNodeAcl($childChildNode, $middleChildNodePath);
    }

    public function testPreviewImageGeneration()
    {
        Tinebase_Config::getInstance()->{Tinebase_Config::FILESYSTEM}->{Tinebase_Config::FILESYSTEM_CREATE_PREVIEWS} = true;
        $previewController = Tinebase_FileSystem_Previews::getInstance();
        try {
            $oldService = $previewController->setPreviewService(new Tinebase_FileSystem_TestPreviewService());

            $path = $this->testCreateFile('test.pdf');
            $fileNode = $this->_controller->stat($path);

            static::assertEquals(3, $fileNode->preview_count, 'preview count wrong');

            $previewController->getPreviewForNode($fileNode, 'thumbnail', 0);
            $previewController->getPreviewForNode($fileNode, 'previews', 0);
            $previewController->getPreviewForNode($fileNode, 'previews', 1);
            $previewController->getPreviewForNode($fileNode, 'previews', 2);

        } finally {
            $previewController->setPreviewService($oldService);
        }
    }

    /**
     * test that an invalid folder size (< 0) will be set to 0 instead
     */
    public function testInvalidFolderSize()
    {
        $testFile = $this->testCreateFile();
        $testDir = dirname($testFile);
        $fileObjectController = new Tinebase_Tree_FileObject();

        $dirNode = $this->_controller->stat($testDir);
        /** @var Tinebase_Model_Tree_FileObject $dirObject */
        $dirObject = $fileObjectController->get($dirNode->object_id);
        static::assertEquals(7, $dirObject->size, 'direcotry size wrong');

        $dirObject->size = 3;
        $fileObjectController->update($dirObject);
        $dirObject = $fileObjectController->get($dirNode->object_id);
        static::assertEquals(3, $dirObject->size, 'direcotry size update did not work');

        $this->_controller->unlink($testFile);

        $dirObject = $fileObjectController->get($dirNode->object_id);
        static::assertEquals(0, $dirObject->size, 'direcotry size should not become negative, it should be set to 0 instead');
    }

    public function testNotification()
    {
        $smtpConfig = Tinebase_Config::getInstance()->get(Tinebase_Config::SMTP, new Tinebase_Config_Struct())->toArray();
        if (empty($smtpConfig)) {
            $this->markTestSkipped('No SMTP config found: this is needed to send notifications.');
        }

        $mailer = Tinebase_Smtp::getDefaultTransport();
        $mailer->flush();

        Tinebase_Core::getConfig()->{Tinebase_Config::FILESYSTEM}->{Tinebase_Config::FILESYSTEM_ENABLE_NOTIFICATIONS} = true;
        $this->_controller = new Tinebase_FileSystem();
        Tinebase_FileSystem::getInstance()->resetBackends();

        $testDir = '/shared/test';
        $baseFolder = Filemanager_Controller_Node::getInstance()->createNodes(array($testDir), Tinebase_Model_Tree_FileObject::TYPE_FOLDER)->getFirstRecord();
        $baseFolder->{Tinebase_Model_Tree_Node::XPROPS_NOTIFICATION} = array(array(
            Tinebase_Model_Tree_Node::XPROPS_NOTIFICATION_ACCOUNT_ID => Tinebase_Core::getUser()->getId(),
            Tinebase_Model_Tree_Node::XPROPS_NOTIFICATION_ACCOUNT_TYPE => Tinebase_Acl_Rights::ACCOUNT_TYPE_USER,
            Tinebase_Model_Tree_Node::XPROPS_NOTIFICATION_ACTIVE => true
        ));
        Filemanager_Controller_Node::getInstance()->update($baseFolder);

        $handle = $this->_controller->fopen($this->_controller->getPathOfNode($baseFolder, true) . '/test.file', 'x');
        $this->assertEquals('resource', gettype($handle), 'opening file failed');
        $written = fwrite($handle, 'phpunit');
        $this->assertEquals(7, $written);
        $this->_controller->fclose($handle);

        // mail foo?
        // check mail
        $messages = $mailer->getMessages();
        $this->assertEquals(1, count($messages));
        $headers = $messages[0]->getHeaders();
        $this->assertEquals('filemanager notification', $headers['Subject'][0]);
        $this->assertTrue(strpos($headers['To'][0], Tinebase_Core::getUser()->accountEmailAddress) !== false);
    }

    public function testGetNotIndexedObjectIds()
    {
        Tinebase_Core::getConfig()->{Tinebase_Config::FILESYSTEM}->{Tinebase_Config::FILESYSTEM_INDEX_CONTENT} = false;
        $this->_controller->resetBackends();

        $file1 = $this->_controller->stat($this->testCreateFile());
        $file2 = $this->_controller->stat($this->testCreateFile('shoo'));
        static::assertEquals($file1->hash, $file2->hash);
        static::assertNotEquals($file1->hash, $file1->indexed_hash);

        $ids = $this->_controller->getFileObjectBackend()->getNotIndexedObjectIds();
        static::assertGreaterThanOrEqual(2, count($ids));

        // check for tika installation
        if ('' == Tinebase_Core::getConfig()->get(Tinebase_Config::FULLTEXT)->{Tinebase_Config::FULLTEXT_TIKAJAR}) {
            return;
        }

        Tinebase_Core::getConfig()->{Tinebase_Config::FILESYSTEM}->{Tinebase_Config::FILESYSTEM_INDEX_CONTENT} = true;
        $this->_controller->resetBackends();

        static::assertTrue($this->_controller->indexFileObject($ids[0]));
        $ids1 = $this->_controller->getFileObjectBackend()->getNotIndexedObjectIds();
        static::assertEquals(count($ids) - 1, count($ids1));
    }

    public function testQuotaNotifications()
    {
        $imapConfig = Tinebase_Config::getInstance()->get(Tinebase_Config::IMAP, new Tinebase_Config_Struct())->toArray();
        if (empty($imapConfig)) {
            static::markTestSkipped('no mail configuration');
        }

        $this->flushMailer();
        $this->_controller->notifyQuota();
        $messages = $this->getMessages();
        static::assertEquals(0, count($messages), 'should not have received any notification email');

        /** @var Tinebase_Model_Tree_Node $node */
        $node = $this->_controller->_getTreeNodeBackend()->search(new Tinebase_Model_Tree_Node_Filter(array(
            array('field' => 'type', 'operator' => 'equals', 'value' => Tinebase_Model_Tree_FileObject::TYPE_FOLDER),
            array('field' => 'size', 'operator' => 'greater', 'value' => 2)
        )), new Tinebase_Model_Pagination(['limit' => 1]))->getFirstRecord();
        $node->quota = 1;
        $this->_controller->update($node);
        $this->_controller->notifyQuota();
        $messages = $this->getMessages();
        static::assertEquals(1, count($messages), 'should have received one notification email');
        /** @var Tinebase_Mail $message */
        $message = $messages[0];
        static::assertEquals('filemanager quota notification', $message->getSubject());
        static::assertEquals($this->_controller->getPathOfNode($node, true) . ' exceeded quota', $message->getBodyText()
            ->getRawContent());
    }

    public function testAclAdjustDuringMove()
    {
        /** @var Tinebase_Model_Tree_Node $aclSharedFolder */
        $aclSharedFolder = Filemanager_Controller_Node::getInstance()->createNodes(['/shared/testAcl'],
            Tinebase_Model_Tree_FileObject::TYPE_FOLDER)->getFirstRecord();
        static::assertEquals($aclSharedFolder->getId(), $aclSharedFolder->acl_node,
            'expected that new folder gets default acls');
        /** @var Tinebase_Model_Tree_Node $aclPersonalFolder */
        $aclPersonalFolder = Filemanager_Controller_Node::getInstance()->createNodes(
            ['/personal/' . Tinebase_Core::getUser()->getId() . '/testAcl'],
            Tinebase_Model_Tree_FileObject::TYPE_FOLDER)->getFirstRecord();
        static::assertEquals($aclPersonalFolder->getId(), $aclPersonalFolder->acl_node,
            'expected that new folder gets default acls');

        $noAclSharedFolder = $this->_controller->createFileTreeNode($aclSharedFolder->parent_id, 'testNoAcl',
            Tinebase_Model_Tree_FileObject::TYPE_FOLDER);
        static::assertEquals(null, $noAclSharedFolder->acl_node, 'new folder should have no acls');
        $noAclPersonalFolder = $this->_controller->createFileTreeNode($aclPersonalFolder->parent_id, 'testNoAcl',
            Tinebase_Model_Tree_FileObject::TYPE_FOLDER);
        static::assertEquals(null, $noAclPersonalFolder->acl_node, 'new folder should have no acls');

        $sharedPath = dirname($this->_controller->getPathOfNode($noAclSharedFolder, true));
        $personalPath = dirname($this->_controller->getPathOfNode($noAclPersonalFolder, true));

        $this->_controller->rename($sharedPath . '/testNoAcl', $personalPath . '/movedTest');
        $this->_controller->rename($personalPath . '/testNoAcl', $sharedPath . '/movedTest');

        $noAclSharedFolder = $this->_controller->get($noAclSharedFolder->getId());
        static::assertEquals($noAclSharedFolder->getId(), $noAclSharedFolder->acl_node,
            'expected that moved folder gets default acls');
        $noAclPersonalFolder = $this->_controller->get($noAclPersonalFolder->getId());
        static::assertEquals($noAclPersonalFolder->getId(), $noAclPersonalFolder->acl_node,
            'expected that moved folder gets default acls');
    }
}
