<?php

namespace IdnoPlugins\StandardSiteSync\Tests;

/**
 * Checks a record against Lexicon schema files (https://atproto.com/specs/lexicon), for
 * tests: the files in fixtures/lexicons are copies of the published definitions, so a
 * record with a wrong field name, a wrong type or a value that is too long fails.
 *
 * It is stricter than a PDS: a property the schema does not name is an error, and so is
 * a member of a union the schema does not list, because this plugin never means to
 * write either.
 */
class LexiconValidator
{
    private $directory;
    private $documents = [];

    const FORMATS = [
        'datetime'    => '/\A[0-9]{4}-[01][0-9]-[0-3][0-9]T[0-2][0-9]:[0-6][0-9]:[0-6][0-9](\.[0-9]{1,20})?(Z|([+-][0-2][0-9]:[0-5][0-9]))\z/',
        'uri'         => '/\A[a-zA-Z][a-zA-Z0-9+.-]*:\S+\z/',
        'at-uri'      => '#\Aat://(did:[a-z]+:[a-zA-Z0-9._:%-]*[a-zA-Z0-9._-]|([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(/[a-zA-Z]([a-zA-Z0-9-]{0,62})?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,62})?)+(/[a-zA-Z0-9._:~-]{1,512})?)?\z#',
        'did'         => '/\Adid:[a-z]+:[a-zA-Z0-9._:%-]*[a-zA-Z0-9._-]\z/',
        'cid'         => '/\A[a-zA-Z0-9+=]{8,256}\z/',
        'language'    => '/\A((?<grandfathered>(en-GB-oed|i-ami|i-bnn|i-default|i-enochian|i-hak|i-klingon|i-lux|i-mingo|i-navajo|i-pwn|i-tao|i-tay|i-tsu|sgn-BE-FR|sgn-BE-NL|sgn-CH-DE)|(art-lojban|cel-gaulish|no-bok|no-nyn|zh-guoyu|zh-hakka|zh-min|zh-min-nan|zh-xiang))|((?<language>([A-Za-z]{2,3}(-(?<extlang>[A-Za-z]{3}(-[A-Za-z]{3}){0,2}))?)|[A-Za-z]{4}|[A-Za-z]{5,8})(-(?<script>[A-Za-z]{4}))?(-(?<region>[A-Za-z]{2}|[0-9]{3}))?(-(?<variant>[A-Za-z0-9]{5,8}|[0-9][A-Za-z0-9]{3}))*(-(?<extension>[0-9A-WY-Za-wy-z](-[A-Za-z0-9]{2,8})+))*(-(?<privateUseA>x(-[A-Za-z0-9]{1,8})+))?)|(?<privateUseB>x(-[A-Za-z0-9]{1,8})+))\z/',
        'nsid'        => '/\A[a-zA-Z]([a-zA-Z0-9-]{0,62})?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,62})?)+(\.[a-zA-Z]([a-zA-Z0-9]{0,62})?)\z/',
        'handle'      => '/\A([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\z/',
        'record-key'  => '/\A(?!\.{1,2}\z)[a-zA-Z0-9._:~-]{1,512}\z/',
        'tid'         => '/\A[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}\z/',
    ];

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');
    }

    /**
     * Check a record against the schema its $type names.
     *
     * @param  mixed $record
     * @return string[] What is wrong with it; empty when nothing is
     */
    public function validateRecord($record): array
    {
        if (!is_array($record) || !is_string($record['$type'] ?? null)) {
            return ['The record has no $type'];
        }
        $definition = $this->definition($record['$type'], 'main');
        if (!$definition) {
            return ['No lexicon file for ' . $record['$type']];
        }
        if (($definition['type'] ?? null) !== 'record') {
            return [$record['$type'] . ' is not a record'];
        }

        return $this->check($record, $definition['record'], $record['$type'], '$');
    }

    /**
     * The record key type the schema of a collection asks for ("tid", "any", ...).
     */
    public function keyType(string $nsid): ?string
    {
        return $this->definition($nsid, 'main')['key'] ?? null;
    }

    /**
     * Check the JSON body of a procedure call against its input schema.
     *
     * @return string[]
     */
    public function validateInput(string $nsid, $body): array
    {
        $definition = $this->definition($nsid, 'main');
        if (!$definition || !isset($definition['input']['schema'])) {
            return ['No input schema for ' . $nsid];
        }

        return $this->check($body, $definition['input']['schema'], $nsid, '$');
    }

    private function definition(string $nsid, string $name): ?array
    {
        if (!array_key_exists($nsid, $this->documents)) {
            $file = $this->directory . '/' . $nsid . '.json';
            $this->documents[$nsid] = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        }

        return $this->documents[$nsid]['defs'][$name] ?? null;
    }

    /**
     * @return array [nsid, name] of the definition a reference names, seen from a lexicon
     */
    private function resolve(string $ref, string $nsid): array
    {
        if ($ref[0] === '#') {
            return [$nsid, substr($ref, 1)];
        }
        $parts = explode('#', $ref, 2);

        return [$parts[0], $parts[1] ?? 'main'];
    }

    private function check($value, array $schema, string $nsid, string $path): array
    {
        switch ($schema['type'] ?? null) {
            case 'object':
                return $this->checkObject($value, $schema, $nsid, $path);
            case 'string':
                return $this->checkString($value, $schema, $path);
            case 'integer':
                if (!is_int($value)) {
                    return ["$path must be an integer"];
                }
                if (isset($schema['minimum']) && $value < $schema['minimum']) {
                    return ["$path is less than " . $schema['minimum']];
                }
                if (isset($schema['maximum']) && $value > $schema['maximum']) {
                    return ["$path is more than " . $schema['maximum']];
                }

                return [];
            case 'boolean':
                return is_bool($value) ? [] : ["$path must be a boolean"];
            case 'array':
                if (!is_array($value) || !array_is_list($value)) {
                    return ["$path must be a list"];
                }
                $errors = [];
                if (isset($schema['maxLength']) && count($value) > $schema['maxLength']) {
                    $errors[] = "$path has more than " . $schema['maxLength'] . ' items';
                }
                if (isset($schema['minLength']) && count($value) < $schema['minLength']) {
                    $errors[] = "$path has fewer than " . $schema['minLength'] . ' items';
                }
                foreach ($value as $i => $item) {
                    $errors = array_merge($errors, $this->check($item, $schema['items'], $nsid, $path . '[' . $i . ']'));
                }

                return $errors;
            case 'ref':
                [$target, $name] = $this->resolve($schema['ref'], $nsid);
                $definition = $this->definition($target, $name);
                if (!$definition) {
                    return ["$path: no lexicon file for " . $schema['ref']];
                }

                return $this->check($value, $definition, $target, $path);
            case 'union':
                if (!is_array($value) || !is_string($value['$type'] ?? null)) {
                    return ["$path must be an object with a \$type"];
                }
                [$wanted, $wantedName] = $this->resolve($value['$type'], $nsid);
                foreach ($schema['refs'] as $ref) {
                    [$target, $name] = $this->resolve($ref, $nsid);
                    if ($target === $wanted && $name === $wantedName) {
                        $definition = $this->definition($target, $name);
                        if (!$definition) {
                            return ["$path: no lexicon file for $ref"];
                        }

                        return $this->check($value, $definition, $target, $path);
                    }
                }

                return ["$path: " . $value['$type'] . ' is not one of ' . implode(', ', $schema['refs'])];
            case 'blob':
                return $this->checkBlob($value, $schema, $path);
            case 'unknown':
                return is_array($value) && !($value && array_is_list($value)) ? [] : ["$path must be an object"];
            default:
                return ["$path: schema type " . json_encode($schema['type'] ?? null) . ' is not handled by this validator'];
        }
    }

    private function checkObject($value, array $schema, string $nsid, string $path): array
    {
        if (!is_array($value) || ($value && array_is_list($value))) {
            return ["$path must be an object"];
        }
        $errors = [];
        foreach ($schema['required'] ?? [] as $name) {
            if (!array_key_exists($name, $value)) {
                $errors[] = "$path.$name is required";
            }
        }
        foreach ($value as $name => $item) {
            if ($name === '$type') {
                continue;
            }
            if (!isset($schema['properties'][$name])) {
                $errors[] = "$path.$name is not a property of the schema";
                continue;
            }
            if ($item === null && in_array($name, $schema['nullable'] ?? [], true)) {
                continue;
            }
            $errors = array_merge($errors, $this->check($item, $schema['properties'][$name], $nsid, "$path.$name"));
        }

        return $errors;
    }

    private function checkString($value, array $schema, string $path): array
    {
        if (!is_string($value)) {
            return ["$path must be a string"];
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            return ["$path is not valid UTF-8"];
        }
        $errors = [];
        if (isset($schema['maxLength']) && strlen($value) > $schema['maxLength']) {
            $errors[] = "$path is longer than " . $schema['maxLength'] . ' bytes';
        }
        if (isset($schema['minLength']) && strlen($value) < $schema['minLength']) {
            $errors[] = "$path is shorter than " . $schema['minLength'] . ' bytes';
        }
        if (isset($schema['maxGraphemes']) && self::graphemes($value) > $schema['maxGraphemes']) {
            $errors[] = "$path is longer than " . $schema['maxGraphemes'] . ' graphemes';
        }
        if (isset($schema['format'])) {
            if ($schema['format'] === 'at-identifier') {
                $ok = preg_match(self::FORMATS['did'], $value) || preg_match(self::FORMATS['handle'], $value);
            } elseif (isset(self::FORMATS[$schema['format']])) {
                $ok = preg_match(self::FORMATS[$schema['format']], $value);
            } else {
                return ["$path: format " . $schema['format'] . ' is not handled by this validator'];
            }
            if (!$ok) {
                $errors[] = "$path is not a valid " . $schema['format'] . ': ' . $value;
            }
        }

        return $errors;
    }

    private function checkBlob($value, array $schema, string $path): array
    {
        if (!is_array($value) || ($value['$type'] ?? null) !== 'blob') {
            return ["$path must be a blob"];
        }
        $errors = [];
        if (!is_string($value['ref']['$link'] ?? null) || !preg_match(self::FORMATS['cid'], $value['ref']['$link'])) {
            $errors[] = "$path.ref must be a link to a CID";
        }
        if (!is_int($value['size'] ?? null) || $value['size'] < 0) {
            $errors[] = "$path.size must be an integer";
        } elseif (isset($schema['maxSize']) && $value['size'] > $schema['maxSize']) {
            $errors[] = "$path is larger than " . $schema['maxSize'] . ' bytes';
        }
        if (!is_string($value['mimeType'] ?? null)) {
            $errors[] = "$path.mimeType must be a string";
        } elseif (isset($schema['accept'])) {
            $accepted = false;
            foreach ($schema['accept'] as $pattern) {
                if ($pattern === '*/*' || $pattern === $value['mimeType'] || (substr($pattern, -2) === '/*' && strpos($value['mimeType'], substr($pattern, 0, -1)) === 0)) {
                    $accepted = true;
                }
            }
            if (!$accepted) {
                $errors[] = "$path: " . $value['mimeType'] . ' is not accepted';
            }
        }
        foreach (array_keys($value) as $name) {
            if (!in_array($name, ['$type', 'ref', 'mimeType', 'size'], true)) {
                $errors[] = "$path.$name is not part of a blob";
            }
        }

        return $errors;
    }

    /**
     * The number of graphemes, counted by the intl extension where there is one, so that
     * the count does not come from the code under test.
     */
    public static function graphemes(string $text): int
    {
        if (function_exists('grapheme_strlen')) {
            return (int) grapheme_strlen($text);
        }

        return (int) preg_match_all('/\X/u', $text);
    }
}
