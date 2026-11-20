<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Idno\Console\ImportMongoCommand;
use PHPUnit\Framework\TestCase;

/**
 * Which passwords of a MongoDB URI the import command keeps out of everything it prints and logs. The
 * command's behaviour against a real server is in Tests/Import/MongoImportEndToEndTest.
 */
final class ImportMongoCommandTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function uris(): array
    {
        return [
            'one host' => ['mongodb://user:secret@host:27017/db', ['secret']],
            'several hosts, which parse_url() cannot read' => ['mongodb://user:secret@h1:27017,h2:27017/db?replicaSet=rs', ['secret']],
            'a seed list with a malformed port' => ['mongodb://user:secret@h1:notaport,h2/db', ['secret']],
            'srv' => ['mongodb+srv://user:secret@cluster.example/db', ['secret']],
            'upper case scheme' => ['MongoDB://user:secret@h1,h2/db', ['secret']],
            'percent-encoded: both forms' => ['mongodb://user:p%40ss%2Fw%3Ard@h1,h2/db', ['p%40ss%2Fw%3Ard', 'p@ss/w:rd']],
            'a raw at sign in the password' => ['mongodb://user:pa@ss@h1,h2/db', ['pa', 'pa@ss']],
            'a colon in the password' => ['mongodb://user:a:b@h1/db', ['a:b']],
            'a user and no password' => ['mongodb://user@h1/db', []],
            'no user-info' => ['mongodb://h1:27017,h2:27017/db', []],
            'not a MongoDB URI' => ['http://user:secret@host/', []],
            'empty' => ['', []],
        ];
    }

    /**
     * @dataProvider uris
     *
     * @param list<string> $expected
     */
    public function testThePasswordsOfAUriAreFoundWhateverItsShape(string $uri, array $expected): void
    {
        $found = ImportMongoCommand::uriPasswords($uri);

        foreach ($expected as $password) {
            self::assertContains($password, $found);
        }
        if ($expected === []) {
            self::assertSame([], $found);
        }
    }
}
