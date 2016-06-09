<?php

namespace yiiunit\extensions\mongodb\file;

use MongoDB\BSON\ObjectID;
use yiiunit\extensions\mongodb\TestCase;

class UploadTest extends TestCase
{
    protected function tearDown()
    {
        $this->dropFileCollection('fs');
        parent::tearDown();
    }

    // Tests :

    public function testAddContent()
    {
        $collection = $this->getConnection()->getFileCollection();

        $upload = $collection->createUpload();
        $document = $upload->addContent('content line 1')
            ->addContent('content line 2')
            ->complete();

        $this->assertTrue($document['_id'] instanceof ObjectID);
        $this->assertEquals(1, $collection->count());
        $this->assertEquals(1, $collection->getChunkCollection()->count());
    }

    /**
     * @depends testAddContent
     */
    public function testAddContentChunk()
    {
        $collection = $this->getConnection()->getFileCollection();

        $upload = $collection->createUpload();
        $upload->chunkSize = 10;
        $document = $upload->addContent('0123456789-tail')->complete();

        $this->assertTrue($document['_id'] instanceof ObjectID);
        $this->assertEquals(1, $collection->count());
        $this->assertEquals(2, $collection->getChunkCollection()->count());
    }

    public function testAddStream()
    {
        $collection = $this->getConnection()->getFileCollection();

        $upload = $collection->createUpload();

        $resource = fopen(__FILE__, 'r');

        $document = $upload->addStream($resource)->complete();

        $this->assertTrue($document['_id'] instanceof ObjectID);
        $this->assertEquals(1, $collection->count());
        $this->assertEquals(1, $collection->getChunkCollection()->count());
    }
}