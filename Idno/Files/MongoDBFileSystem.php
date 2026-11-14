<?php

namespace Idno\Files {

    class MongoDBFileSystem extends FileSystem
    {

        private $manager;
        private $dbname;

        private $gridfs_object;

        public function __construct($manager, $dbname)
        {
            $this->manager = $manager;
            $this->dbname = $dbname;

            $this->gridfs_object = new \MongoDB\GridFS\Bucket($this->manager, $this->dbname);
        }

        public function findOne($_id)
        {

            if (is_array($_id)) {
                $_id = $_id['_id'];
            }

            $result = $this->gridfs_object->find(['_id' => new \MongoDB\BSON\ObjectId($_id)], ['limit' => 1]);
            if ($array = iterator_to_array($result)) {

                $data = \Idno\Core\site()->db()->unsanitizeFields($array[0]);

                $file = new \Idno\Files\MongoDBFile();
                $file->setBucket($this->gridfs_object);

                $file->_id               = $_id;

                // Normalise new object storage
                if (isset($data['metadata'])) {
                    foreach ($data['metadata'] as $k => $v) {
                        if ($k != '_id') { // Prevent objects from clobbering the ID
                            $file->metadata[$k] = $v;
                        }
                    }

                    unset($data['metadata']);
                }

                //$file->file['length'] = filesize($upload_file);

                foreach ($data as $k => $v) {
                    if ($k != '_id') { // Prevent objects from clobbering the ID
                        $file->metadata[$k] = $v;
                    }

                    if ($k == 'filename') {
                        $file->metadata[$k] = basename($v);
                    }

                    if ($v instanceof \MongoDB\BSON\UTCDateTime) { // Handle MongoDB dates
                        $file->metadata[$k] = $v->__toString();
                    }
                }

                $file->file = $file->metadata;
                $file->file['_id']    = $_id;

                return $file;

            }

            return false;
        }

        public function updateMetadata($id, array $metadata)
        {
            if (empty($metadata)) {
                return true;
            }

            try {
                $set = [];
                foreach ($metadata as $key => $value) {
                    $set['metadata.' . $key] = $value;
                }
                $collection = new \MongoDB\Collection($this->manager, $this->dbname, 'fs.files');
                $result     = $collection->updateOne(
                    ['_id' => new \MongoDB\BSON\ObjectId((string) $id)],
                    ['$set' => $set]
                );

                return $result->getMatchedCount() > 0;
            } catch (\Exception $ex) {
                \Idno\Core\site()->logging()->debug($ex->getMessage());
            }

            return false;
        }

        public function storeFile($file_path, $metadata, $options = [])
        {

            $bucket = $this->gridfs_object;

            try {

                if ($source = fopen($file_path, 'rb')) {

                    $id = $bucket->uploadFromStream(
                        $metadata['filename'], $source, [
                        'metadata' => $metadata//new \MongoDB\Model\BSONDocument($metadata)
                        ]
                    );

                    fclose($source);

                    return "$id";
                }
            } catch (\Exception $ex) {
                \Idno\Core\site()->logging()->debug($ex->getMessage());
            }

            return false;
        }

        public function storeContent($content, $metadata, $options = [])
        {
            $bucket = $this->gridfs_object;

            try {

                if ($source = fopen('php://memory', 'r+')) {

                    fwrite($source, $content);
                    rewind($source);

                    $id = $bucket->uploadFromStream(
                        $metadata['filename'], $source, [
                        'metadata' => $metadata//new \MongoDB\Model\BSONDocument($metadata)
                        ]
                    );

                    fclose($source);

                    return "$id";
                }
            } catch (\Exception $ex) {
                \Idno\Core\site()->logging()->debug($ex->getMessage());
            }

            return false;
        }

    }

}

