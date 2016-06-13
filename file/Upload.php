<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\mongodb\file;

use MongoDB\BSON\Binary;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDatetime;
use yii\base\InvalidParamException;
use yii\base\Object;
use yii\helpers\StringHelper;

/**
 * Upload represents the GridFS upload operation.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 2.1
 */
class Upload extends Object
{
    /**
     * @var Collection file collection to be used.
     */
    public $collection;
    /**
     * @var string filename to be used for file storage.
     */
    public $filename;
    /**
     * @var array additional file document contents.
     * Common GridFS columns:
     *
     * - metadata: array, additional data associated with the file.
     * - aliases: array, an array of aliases.
     * - contentType: string, content type to be stored with the file.
     */
    public $document = [];
    /**
     * @var integer chunk size in bytes.
     */
    public $chunkSize = 261120;
    /**
     * @var integer total upload length in bytes.
     */
    public $length = 0;
    /**
     * @var integer file chunk counts.
     */
    public $chunkCount = 0;

    /**
     * @var ObjectID file document ID.
     */
    protected $documentId;

    /**
     * @var resource has context for collecting md5 hash
     */
    private $hashContext;
    /**
     * @var string internal data buffer
     */
    private $buffer;


    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->hashContext = hash_init('md5');

        if (isset($this->document['_id'])) {
            $this->documentId = $this->document['_id'] instanceof ObjectID ? $this->document['_id'] : ObjectID($this->document['_id']);
        } else {
            $this->documentId = new ObjectID();
        }

        $this->collection->ensureIndexes();
    }

    /**
     * Adds string content to the upload.
     * This method can invoked several times before [[complete()]] is called.
     * @param string $content binary content.
     * @return $this self reference.
     */
    public function addContent($content)
    {
        $freeBufferLength = $this->chunkSize - strlen($this->buffer);
        $contentLength = StringHelper::byteLength($content);
        if ($contentLength > $freeBufferLength) {
            $this->buffer .= StringHelper::byteSubstr($content, 0, $freeBufferLength);
            $this->flushBuffer(true);
            return $this->addContent(StringHelper::byteSubstr($content, $freeBufferLength));
        } else {
            $this->buffer .= $content;
            $this->flushBuffer();
        }

        return $this;
    }

    /**
     * Adds stream content to the upload.
     * This method can invoked several times before [[complete()]] is called.
     * @param resource $stream data source stream.
     * @return $this self reference.
     */
    public function addStream($stream)
    {
        while (!feof($stream)) {
            $freeBufferLength = $this->chunkSize - StringHelper::byteLength($this->buffer);

            $streamChunk = fread($stream, $freeBufferLength);
            if ($streamChunk === false) {
                break;
            }
            $this->buffer .= $streamChunk;
            $this->flushBuffer();
        }

        return $this;
    }

    /**
     * Adds a file content to the upload.
     * This method can invoked several times before [[complete()]] is called.
     * @param string $filename source file name.
     * @return $this self reference.
     */
    public function addFile($filename)
    {
        if ($this->filename === null) {
            $this->filename = basename($filename);
        }

        $stream = fopen($filename, 'r+');
        if ($stream === false) {
            throw new InvalidParamException("Unable to read file '{$filename}'");
        }
        return $this->addStream($stream);
    }

    /**
     * Completes upload.
     * @return array saved document.
     */
    public function complete()
    {
        $this->flushBuffer(true);

        return $this->insertFile();
    }

    /**
     * Cancels the upload.
     */
    public function cancel()
    {
        $this->buffer = null;

        $this->collection->getChunkCollection()->remove(['files_id' => $this->documentId], ['limit' => 0]);
        $this->collection->remove(['_id' => $this->documentId], ['limit' => 1]);
    }

    /**
     * Flushes [[buffer]] to the chunk if it is full.
     * @param boolean $force whether to enforce flushing.
     */
    private function flushBuffer($force = false)
    {
        if ($this->buffer === null) {
            return;
        }

        if ($force || StringHelper::byteLength($this->buffer) == $this->chunkSize) {
            $this->insertChunk($this->buffer);
            $this->buffer = null;
        }
    }

    /**
     * Inserts file chunk.
     * @param string $data chunk binary content.
     */
    private function insertChunk($data)
    {
        $chunkDocument = [
            'files_id' => $this->documentId,
            'n' => $this->chunkCount,
            'data' => new Binary($data, Binary::TYPE_GENERIC),
        ];

        hash_update($this->hashContext, $data);

        $this->collection->getChunkCollection()->insert($chunkDocument);
        $this->length += StringHelper::byteLength($data);
        $this->chunkCount++;
    }

    /**
     * Inserts [[document]] into file collection.
     * @return array inserted file document data.
     */
    private function insertFile()
    {
        $fileDocument = [
            '_id' => $this->documentId,
            'uploadDate' => new UTCDateTime(round(microtime(true) * 1000)),
        ];
        if ($this->filename === null) {
            $fileDocument['filename'] = $this->documentId . '.dat';
        } else {
            $fileDocument['filename'] = $this->filename;
        }

        $fileDocument = array_merge(
            $fileDocument,
            $this->document,
            [
                'chunkSize' => $this->chunkSize,
                'length' => $this->length,
                'md5' => hash_final($this->hashContext),
            ]
        );

        $this->collection->insert($fileDocument);
        return $fileDocument;
    }
}