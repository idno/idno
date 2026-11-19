<?php

/**
 * Captures the fixture of the MongoDB import's end-to-end test from the old MongoDB backend
 * (Idno\Data\Mongo, Idno\Files\MongoDBFileSystem): boots Idno on a private MongoDB database, builds a
 * small site through the entity classes (a site record, two users, a status, an article with a tag
 * and an annotation, a photo with a file in GridFS, a draft, a reader feed item, a field name holding
 * a dot and a dollar sign, and one GridFS file rewritten to the old driver's shape; the users' key
 * pairs, which the ActivityPub plugin generates, are removed), then writes:
 *
 *  - mongo-site.ndjson: every document of every collection (fs.files and fs.chunks included), one
 *    `{"collection": ..., "document": <canonical Extended JSON>}` per line, in collection then _id order;
 *  - expected.php: what the old backend returned: each record as db()->getRecord() gave it, the
 *    configuration db()->getAnyRecord('config') loaded, and each file's ->metadata, length and md5.
 *
 * Run by Tests/rig/mongo-import.sh capture, in a container with the mongodb extension and library,
 * against a throwaway MongoDB: php capture.php <mongodb URL> <output directory>. The site must be
 * configured (a configuration/config.ini); the database it uses is dropped first.
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

if ($argc !== 3 || !is_dir($argv[2])) {
    fwrite(STDERR, "Usage: php capture.php <mongodb URL> <output directory>\n");
    exit(2);
}
[, $url, $out] = $argv;
$dbname = 'idno_import_capture';

$manager = new \MongoDB\Driver\Manager($url);
$manager->executeCommand($dbname, new \MongoDB\Driver\Command(['dropDatabase' => 1]));

$_SERVER['IDNO_DATABASE'] = 'mongo';
$_SERVER['IDNO_DBSTRING'] = $url;
$_SERVER['IDNO_DBNAME'] = $dbname;
$_SERVER['IDNO_URL'] = 'http://capture.example/';
$_SERVER['IDNO_PEDANTIC_MODE'] = '';
$_SERVER['IDNO_FILESYSTEM'] = '';
$_SERVER['SERVER_NAME'] = 'capture.example';

require dirname(__DIR__, 3) . '/Idno/start.php';

$site = \Idno\Core\Idno::site();
if (!$site->db() instanceof \Idno\Data\Mongo) {
    fwrite(STDERR, "The site did not boot on the MongoDB backend.\n");
    exit(1);
}
// The site record (site_details() makes one when there is a session, but does not always store it).
$details = $site->site_details() ?? new \Idno\Core\Site();
$details->domain = $site->config()->host;
if (empty($details->getID()) && !$details->save(true)) {
    throw new \RuntimeException('Could not save the site record');
}

function capture_user(string $handle, string $name): \Idno\Entities\User
{
    $user = new \Idno\Entities\User();
    $user->setHandle($handle);
    $user->email = $handle . '@capture.example';
    $user->setPassword('capture-' . $handle . '-password');
    $user->setTitle($name);
    if (!$user->save(true)) {
        throw new \RuntimeException('Could not save the user ' . $handle);
    }

    return $user;
}

/**
 * A value as PHP source (short arrays, four spaces a level; an ObjectId or a date as the expression
 * that makes it), for expected.php.
 */
function capture_export(mixed $value, int $depth = 0): string
{
    if (is_array($value)) {
        if ($value === []) {
            return '[]';
        }
        $lines = [];
        foreach ($value as $key => $item) {
            $lines[] = str_repeat('    ', $depth + 1) . capture_export($key) . ' => ' . capture_export($item, $depth + 1) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
    }

    return match (true) {
        $value instanceof \MongoDB\BSON\ObjectId => "new \\MongoDB\\BSON\\ObjectId('" . $value . "')",
        $value instanceof \MongoDB\BSON\UTCDateTime => 'new \\MongoDB\\BSON\\UTCDateTime(' . $value . ')',
        is_string($value) && preg_match('/[\x00-\x1f\x7f]/', $value) === 1 => '"' . preg_replace_callback(
            '/[\x00-\x1f\x7f]/',
            static fn (array $m): string => sprintf('\\x%02x', ord($m[0])),
            strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']),
        ) . '"',
        is_string($value) => "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'",
        is_int($value) => (string) $value,
        is_float($value) => json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
        is_bool($value) => $value ? 'true' : 'false',
        $value === null => 'null',
        default => throw new \RuntimeException('Cannot write a ' . get_debug_type($value) . ' into expected.php'),
    };
}

function capture_save(\Idno\Common\Entity $entity, string $what): void
{
    if (!$entity->save(true)) {
        throw new \RuntimeException('Could not save the ' . $what);
    }
}

$alice = capture_user('alice', 'Alice Example');
$bob = capture_user('bob', 'Bob Example');
$site->session()->logUserOn($alice);

$status = new \IdnoPlugins\Status\Status();
$status->setOwner($alice);
$status->body = 'Hello from MongoDB, the status of the import fixture';
$status->setAccess('PUBLIC');
$status->{'odd.field$name'} = 'a field name with a dot and a dollar sign';
capture_save($status, 'status');

$article = new \IdnoPlugins\Text\Entry();
$article->setOwner($alice);
$article->setTitle('An article from MongoDB');
$article->body = '<p>An article about #mongodb and moving house.</p>';
$article->setAccess('PUBLIC');
capture_save($article, 'article');
$article->addAnnotation('like', 'Bob Example', 'https://bob.example/', 'https://bob.example/photo.png', 'liked this', 'https://bob.example/likes/1', 1700000000, '', ['nested' => ['level' => ['deep' => true]]], false);
capture_save($article, 'article with its annotation');

$image = imagecreatetruecolor(4, 3);
imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 40, 90));
$png = tempnam(sys_get_temp_dir(), 'capture') . '.png';
imagepng($image, $png);
$file = \Idno\Entities\File::createFromFile($png, 'pixel.png', 'image/png', true);
unlink($png);
if (!$file) {
    throw new \RuntimeException('Could not store the photo\'s file in GridFS');
}
$photo = new \IdnoPlugins\Photo\Photo();
$photo->setOwner($bob);
$photo->setTitle('A photo in GridFS');
$photo->body = 'A small picture.';
$photo->setAccess('PUBLIC');
$photo->attachFile($file, true);
capture_save($photo, 'photo');

$draft = new \IdnoPlugins\Status\Status();
$draft->setOwner($alice);
$draft->body = 'A draft nobody else may see';
$draft->setAccess('PUBLIC');
$draft->setPublishStatus('draft');
capture_save($draft, 'draft');

$item = new \Idno\Entities\Reader\FeedItem();
$item->setOwner($alice);
$item->setFeedURL('https://feeds.example/rss.xml');
$item->setTitle('A feed item');
$item->body = 'Read in the reader.';
// FeedItem names `reader` as its collection, but Entity::save() writes to the base class's own
// `entities`: write it where the class says it belongs, through the backend, as the reader would.
$item->created = time();
$item->uuid = 'http://capture.example/reader/feed-item-1';
if (!$site->db()->saveRecord('reader', $item->saveToArray())) {
    throw new \RuntimeException('Could not save the feed item');
}

// The ActivityPub plugin gave each user a key pair: no private key goes into the repository.
$bulk = new \MongoDB\Driver\BulkWrite();
$bulk->update(['privateKey' => ['$exists' => true]], ['$unset' => ['privateKey' => '', 'publicKeyPem' => '']], ['multi' => true]);
$manager->executeBulkWrite($dbname . '.entities', $bulk);

// One file as the old driver stored it: its fields at the top level, no metadata sub-document.
$oldId = $site->filesystem()->storeContent('Bytes stored by the old driver.', ['filename' => 'old.txt', 'mime_type' => 'text/plain']);
if (!$oldId) {
    throw new \RuntimeException('Could not store the old-shape file');
}
$bulk = new \MongoDB\Driver\BulkWrite();
$bulk->update(
    ['_id' => new \MongoDB\BSON\ObjectId((string) $oldId)],
    ['$set' => ['filename' => '/var/uploads/old.txt', 'mime_type' => 'text/plain'], '$unset' => ['metadata' => '']],
);
$manager->executeBulkWrite($dbname . '.fs.files', $bulk);

// The dump: every collection, every document, as canonical Extended JSON.
$names = [];
foreach ($manager->executeCommand($dbname, new \MongoDB\Driver\Command(['listCollections' => 1, 'nameOnly' => true])) as $collection) {
    $names[] = $collection->name;
}
sort($names);
$lines = '';
$records = [];
$files = [];
foreach ($names as $name) {
    $cursor = $manager->executeQuery($dbname . '.' . $name, new \MongoDB\Driver\Query([], ['sort' => ['_id' => 1]]));
    $cursor->setTypeMap(['root' => 'bson']);
    foreach ($cursor as $document) {
        $lines .= '{"collection":' . json_encode($name) . ',"document":' . $document->toCanonicalExtendedJSON() . "}\n";
        $id = (string) $document->get('_id');
        if (in_array($name, ['entities', 'reader', 'site', 'config'], true)) {
            $records[$name][$id] = $site->db()->getRecord($id, $name);
        } elseif ($name === 'fs.files') {
            $stored = $site->filesystem()->findOne($id);
            $files[$id] = ['metadata' => $stored->metadata, 'length' => strlen($stored->getBytes()), 'md5' => md5($stored->getBytes())];
        }
    }
}

$expected = [
    'records' => $records,
    'config' => $site->db()->getAnyRecord('config'),
    'files' => $files,
];
file_put_contents($out . '/mongo-site.ndjson', $lines);
file_put_contents($out . '/expected.php', "<?php\n\n// Captured from the old MongoDB backend by capture.php: do not edit.\n\nreturn " . capture_export($expected) . ";\n");
$manager->executeCommand($dbname, new \MongoDB\Driver\Command(['dropDatabase' => 1]));
echo 'Captured ' . substr_count($lines, "\n") . " documents.\n";
