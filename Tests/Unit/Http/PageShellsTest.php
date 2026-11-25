<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Idno\Legacy\PageShell;
use PHPUnit\Framework\TestCase;

/**
 * A shell is a deprecated legacy page that a controller replaced and that has no logic of its own: the pages that stay shells are
 * listed here, and each is held to the rule that every handler it declares is one delegation to the service the controller calls.
 */
final class PageShellsTest extends TestCase
{
    /** The shells, by class; a page that becomes one is added here, and a page that is removed is taken out */
    private const SHELLS = ['Idno\Pages\Homepage'];

    /** The only methods a shell declares (besides its constant): the handlers a legacy page was served by */
    private const HANDLERS = ['getContent', 'postContent', 'putContent', 'deleteContent', 'webmentionContent'];

    public function testTheListOfShellsIsTheClassesThatExtendTheShellBase(): void
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/Idno/Pages', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('/extends\s+\\\\Idno\\\\Legacy\\\\PageShell\b/', (string) file_get_contents($file->getPathname())) === 1
                && preg_match('/namespace\s+([A-Za-z\\\\]+)/', (string) file_get_contents($file->getPathname()), $namespace) === 1) {
                $found[] = $namespace[1] . '\\' . $file->getBasename('.php');
            }
        }
        sort($found);

        $this->assertSame(self::SHELLS, $found);
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function shells(): array
    {
        $cases = [];
        foreach (self::SHELLS as $class) {
            $cases[$class] = [$class];
        }

        return $cases;
    }

    /**
     * @dataProvider shells
     */
    public function testAShellExtendsTheBaseAndNamesItself(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        $this->assertTrue($reflection->isSubclassOf(PageShell::class));
        $this->assertSame($class, $reflection->getReflectionConstant('SHELL')?->getValue(), 'the SHELL constant is the shell\'s own class');
        $this->assertSame($class, $reflection->getReflectionConstant('SHELL')?->getDeclaringClass()->getName());
    }

    /**
     * @dataProvider shells
     */
    public function testAShellDeclaresOnlyHandlers(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $declared = [];
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $class) {
                $declared[] = $method->getName();
            }
        }
        $this->assertSame([], array_values(array_diff($declared, self::HANDLERS)), 'a shell has no method of its own');
        $this->assertSame([], array_values(array_filter($reflection->getProperties(), static fn (\ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $class)), 'and no property');
        $this->assertNotSame([], $declared);
    }

    /**
     * @dataProvider shells
     */
    public function testEveryHandlerOfAShellIsOneDelegation(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $source = (string) file_get_contents((string) $reflection->getFileName());
        $lines = explode("\n", $source);
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $text = implode("\n", array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            $this->assertSame(1, self::statements($text), $class . '::' . $method->getName() . ' is a single statement');
            $this->assertMatchesRegularExpression('/\{\s*return \$this->(delegate|delegateWebmention)\(/', $text, $class . '::' . $method->getName() . ' returns the delegation');
        }
    }

    /**
     * The number of statements a method's body holds: its semicolons outside parentheses, braces and brackets that the body itself opens.
     */
    private static function statements(string $method): int
    {
        $tokens = token_get_all('<?php ' . $method);
        $depth = 0;
        $bodyDepth = null;
        $count = 0;
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if ($text === '{' || $text === '(' || $text === '[') {
                if ($text === '{' && $bodyDepth === null) {
                    $bodyDepth = $depth;
                }
                ++$depth;
            } elseif ($text === '}' || $text === ')' || $text === ']') {
                --$depth;
            } elseif ($text === ';' && $bodyDepth !== null && $depth === $bodyDepth + 1) {
                ++$count;
            }
        }

        return $count;
    }
}
