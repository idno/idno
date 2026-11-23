<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * Judges the report the Twig port check wrote (Tests\Fixtures\TwigPortCheck): what the equivalence test asserts, as a function
 * of the report's lines, the exceptions and the ported files, so that each rule can be shown to fail.
 */
final class PortReportChecker
{
    /**
     * Twig files whose original's output must have been different in at least two comparisons (project-relative): a comparison
     * that always saw the same output has not tried the branches the template has. Each port that has such a file adds it.
     */
    public const MUST_VARY = [
        'templates/default/account-shell.html.twig',
        'templates/default/admin-shell.html.twig',
        'templates/default/content/access.html.twig',
        'templates/default/content/edit.html.twig',
        'templates/default/content/embed.html.twig',
        'templates/default/content/end.html.twig',
        'templates/default/content/end/annotations.html.twig',
        'templates/default/content/feed/end.html.twig',
        'templates/default/content/syndication.html.twig',
        'templates/default/content/syndication/account.html.twig',
        'templates/default/content/syndication/button.html.twig',
        'templates/default/content/syndication/links.html.twig',
        'templates/default/drafts.html.twig',
        'templates/default/entity/FeedItem.html.twig',
        'templates/default/entity/LinkPreviewCard.html.twig',
        'templates/default/entity/Subscription.html.twig',
        'templates/default/entity/User.html.twig',
        'templates/default/entity/User/edit.html.twig',
        'templates/default/entity/User/profile/contact.html.twig',
        'templates/default/entity/User/profile/fields.html.twig',
        'templates/default/entity/annotations/comment/main.html.twig',
        'templates/default/entity/annotations/image.html.twig',
        'templates/default/entity/annotations/likes.html.twig',
        'templates/default/entity/annotations/mentions.html.twig',
        'templates/default/entity/annotations/replies.html.twig',
        'templates/default/entity/annotations/rsvps.html.twig',
        'templates/default/entity/annotations/shares.html.twig',
        'templates/default/entity/content/embed.html.twig',
        'templates/default/entity/edit/header.html.twig',
        'templates/default/entity/editwrapper.html.twig',
        'templates/default/entity/edit/spinner.html.twig',
        'templates/default/entity/feed.html.twig',
        'templates/default/entity/share.html.twig',
        'templates/default/entity/shell.html.twig',
        'templates/default/file/picker/donejs.html.twig',
        'templates/default/following/confirm.html.twig',
        'templates/default/following/home.html.twig',
        'templates/default/pages/403.html.twig',
        'templates/default/pages/500.html.twig',
        'templates/default/pages/home.html.twig',
        'templates/default/pages/home/blurb.html.twig',
        'templates/default/pages/home/nocontent.html.twig',
        'templates/default/shell.html.twig',
        'templates/default/shell/activitypub.html.twig',
        'templates/default/shell/compose.html.twig',
        'templates/default/shell/dublincore.html.twig',
        'templates/default/shell/identities.html.twig',
        'templates/default/shell/messages.html.twig',
        'templates/default/shell/messages/message.html.twig',
        'templates/default/shell/metatags.html.twig',
        'templates/default/shell/nav.html.twig',
        'templates/default/shell/opengraph.html.twig',
        'templates/default/shell/pagination.html.twig',
        'templates/default/shell/simple.html.twig',
        'templates/default/shell/simple/messages.html.twig',
        'templates/default/shell/structured-data.html.twig',
        'templates/default/shell/syndication.html.twig',
        'templates/default/stream/home.html.twig',
    ];

    /**
     * @param list<array<string, mixed>>                                                    $lines      the report's lines
     * @param array<string, array{reason: string, test: string, differences: list<string>}> $exceptions port-exceptions.php
     * @param list<string>                                                                  $ported     the Twig files (project-relative) that have an original beside them
     * @param list<string>                                                                  $mustVary   the files that must have varied
     *
     * @return array{failures: list<string>, notices: list<string>}
     */
    public static function check(array $lines, array $exceptions, array $ported, array $mustVary = self::MUST_VARY): array
    {
        $failures = [];
        $notices = [];
        $compared = [];
        $produced = [];
        $varied = [];
        foreach ($lines as $line) {
            $file = (string) $line['file'];
            $compared[$file] = true;
            $varied[$file][(string) ($line['original_hash'] ?? '')] = true;
            if ($line['same']) {
                continue;
            }
            $hash = (string) $line['hash'];
            $produced[$file][$hash] = true;
            if (!in_array($hash, $exceptions[$file]['differences'] ?? [], true)) {
                $notes = '';
                if (($line['substitutions'] ?? 0) > 0) {
                    $notes .= ' (compared after ' . $line['substitutions'] . ' substitutions of listed differences of templates it draws)';
                }
                if (($line['unsubstituted'] ?? 0) > 0) {
                    $notes .= ' (' . $line['unsubstituted'] . ' listed difference(s) of templates it draws were not substituted, because their original output is empty or shorter than 16 bytes: this template needs its own entry for what they changed in it)';
                }
                $failures[] = $file . " draws something other than its original" . $notes . ", and port-exceptions.php does not list this difference (add the hash to its `differences` with a reason and a test, or fix the port):\n  hash:     " . $hash . "\n  original: " . self::quote($line['original'] ?? '') . "\n  port:     " . self::quote($line['port'] ?? '');
            }
        }

        foreach ($exceptions as $file => $exception) {
            if (trim($exception['reason']) === '') {
                $failures[] = $file . ': an exception needs its reason';
            }
            if ($exception['differences'] === []) {
                $failures[] = $file . ': an exception lists no difference (its `differences` holds the hash of each)';
            }
            if (!isset($compared[$file])) {
                $failures[] = $file . ' is an exception, and no page or draw compared it';
            }
            foreach ($exception['differences'] as $hash) {
                if (!isset($produced[$file][$hash])) {
                    $failures[] = $file . ': the exception ' . $hash . ' is stale: no comparison produced this difference';
                }
            }
            [$class, $method] = explode('::', $exception['test'] . '::') + [1 => ''];
            if (!class_exists($class) || !method_exists($class, $method)) {
                $failures[] = $file . ': the test ' . $exception['test'] . ' does not exist';
            }
        }

        $uncompared = array_values(array_filter($ported, static fn (string $file): bool => !isset($compared[$file])));
        if ($uncompared !== []) {
            $failures[] = "These Twig files have an original beside them and no page or draw of the test compared them (add a page to EXTRA_PAGES, or a draw to DRAWS):\n" . implode("\n", $uncompared);
        }

        foreach ($ported as $file) {
            if (isset($compared[$file]) && count($varied[$file]) < 2) {
                if (in_array($file, $mustVary, true)) {
                    $failures[] = $file . ': the original\'s output never varied across ' . count(array_filter($lines, static fn (array $line): bool => $line['file'] === $file)) . ' comparisons, and this template must be compared with different output (add a visit that changes what it draws)';
                } else {
                    $notices[] = $file . ': the original\'s output never varied across the comparisons (its other branches were not compared)';
                }
            }
        }

        return ['failures' => $failures, 'notices' => $notices];
    }

    private static function quote(mixed $output): string
    {
        return (string) json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
