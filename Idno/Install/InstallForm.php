<?php

declare(strict_types=1);

namespace Idno\Install;

/**
 * The fields of the web installer's forms, as a request posted them. A value that is not a string
 * (`title[]=x`) is empty.
 */
final class InstallForm
{
    public const TITLE_LENGTH = 100;

    /**
     * @param array<string|int, mixed> $fields The request's posted values
     */
    public function __construct(private readonly array $fields)
    {
    }

    /**
     * A field, trimmed; '' when it is missing or not a string.
     */
    public function text(string $name): string
    {
        return trim($this->raw($name));
    }

    /**
     * A field exactly as posted (a password); '' when it is missing or not a string.
     */
    public function raw(string $name): string
    {
        $value = $this->fields[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * The site's name: one line, control characters removed, at most TITLE_LENGTH characters.
     */
    public function title(): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', mb_scrub($this->raw('title'), 'UTF-8')) ?? ''), 0, self::TITLE_LENGTH);
    }

    /**
     * @return array<string, string> field => what is wrong
     */
    public function siteProblems(): array
    {
        $problems = [];
        if (!SiteAddress::valid(strtolower($this->text('domain')))) {
            $problems['domain'] = 'Enter the domain alone, for example notes.example.com: no https://, no slash and nothing after it.';
        }

        return $problems;
    }

    /**
     * @return array{title: string, domain: string}
     */
    public function siteValues(): array
    {
        return ['title' => $this->title(), 'domain' => strtolower($this->text('domain'))];
    }

    /**
     * @return array{title: string, domain: string, database: string, mysql_name: string, mysql_user: string, mysql_host: string}
     */
    public function databaseValues(): array
    {
        return $this->siteValues() + [
            'database' => strtolower($this->text('database')) === 'mysql' ? 'mysql' : 'sqlite',
            'mysql_name' => $this->text('mysql_name'),
            'mysql_user' => $this->text('mysql_user'),
            'mysql_host' => $this->text('mysql_host'),
        ];
    }
}
