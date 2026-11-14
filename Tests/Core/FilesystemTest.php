<?php

namespace Tests\Core {

    class FilesystemTest extends \Tests\IdnoTestCase
    {


        function testStoreContent()
        {

            $content = "this is test content";
            $filename = get_class($this) . '_' . time();

            $filesystem = \Idno\Core\Idno::site()->filesystem();

            $id = $filesystem->storeContent(
                $content, [
                'filename' => $filename,
                'meta_type' => 'text/plain'
                ]
            );

            $loaded = $filesystem->findOne($id);

            $this->assertNotEmpty($loaded, 'System should have found one file with ID ' . $id . '.');
            $this->assertEquals($content, $loaded->getBytes(), 'System should have retrieved file contents.');

        }

        function testUpdateMetadata()
        {
            $filesystem = \Idno\Core\Idno::site()->filesystem();

            $id = $filesystem->storeContent(
                'metadata test content', [
                'filename' => get_class($this) . '_meta_' . time(),
                'mime_type' => 'text/plain'
                ]
            );
            $this->assertNotEmpty($id, 'File should have been stored.');

            $this->assertTrue(
                $filesystem->updateMetadata($id, ['attached_entity' => 'https://example.com/view/abc123']),
                'Filesystem should report that metadata was updated.'
            );

            $loaded = $filesystem->findOne($id);
            $this->assertEquals('https://example.com/view/abc123', $loaded->file['attached_entity'], 'New metadata should be stored.');
            $this->assertEquals('text/plain', $loaded->file['mime_type'], 'Existing metadata should be preserved.');
            $this->assertEquals('metadata test content', $loaded->getBytes(), 'File contents should be untouched.');

            $loaded->delete();
        }

        function testEntityLinksAttachedFilesOnSave()
        {
            \Idno\Core\Idno::site()->session()->logUserOn($this->user());
            $filesystem = \Idno\Core\Idno::site()->filesystem();

            $filename = get_class($this) . '_attach_' . time() . '.txt';
            $id = $filesystem->storeContent(
                'attachment test content', [
                'filename' => $filename,
                'mime_type' => 'text/plain'
                ]
            );
            $this->assertNotEmpty($id, 'File should have been stored.');

            $entity = new \Idno\Entities\GenericDataItem();
            $entity->setDatatype('attachment-test');
            $entity->setOwner($this->user());
            $entity->title = 'Entity with an attachment';
            $entity->attachFile($filesystem->findOne($id));

            $this->assertEquals([(string) $id], $entity->getAttachedFileIDs(), 'Entity should report its attached file.');
            $this->assertTrue(empty($filesystem->findOne($id)->file['attached_entity']), 'File should not be linked before the entity is saved.');

            $this->assertNotEmpty($entity->save(), 'Entity should save.');

            $this->assertEquals(
                $entity->getUUID(),
                $filesystem->findOne($id)->file['attached_entity'],
                'Saving an entity should record its UUID on its attached files.'
            );

            $entity->delete();
            \Idno\Core\Idno::site()->session()->logUserOff();
        }
    }

}
