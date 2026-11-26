<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Idno\Http\Page\SitePage;
use Symfony\Component\Routing\RouteCollection;

/**
 * The convention a site page's routes follow, as a check over a route collection (no kernel: the routes of the container are
 * checked by Tests\Kernel\PageRoutesTest, the check itself by PageRouteConventionTest).
 */
final class PageRouteConvention
{
    /** Routes that break the convention on purpose, each with why */
    private const EXEMPT = [
        // routes more methods than its page declares, to prove the lifecycle refuses a method the page did not
        'idno_test_site_page_undeclared' => true,
    ];

    /**
     * Routes whose last parameter takes the rest of the path, a trailing slash included, so they have no `{slash}`, each with why and a path the last
     * parameter must be able to take
     */
    private const NO_TRAILING_SLASH = [
        // the tag is what follows `/tag/`: a trailing slash is the tag page's to take off the tag (one slash, as it always did)
        'idno_tag' => 'a/b',
        // what follows a file's id is the name a download is given, a slash and any path: the legacy pattern `(/.*)?` took them all, a trailing slash too
        'idno_file' => '/a/b/',
    ];

    /**
     * @return list<string> what a route of a site page has that the convention does not allow
     */
    public static function violations(RouteCollection $routes): array
    {
        $found = [];
        foreach ($routes as $name => $route) {
            if (isset(self::EXEMPT[$name])) {
                continue;
            }
            $controller = $route->getDefault('_controller');
            $page = is_string($controller) || is_array($controller) ? SitePage::of($controller) : null;
            if ($page === null) {
                continue;
            }
            $path = $route->getPath();
            if (!str_starts_with($path, '/{lead}') && $path !== '/{slash}') {
                $found[] = $name . ': the path starts with {lead} (or is {slash} for the root)';
            }
            $noSlash = isset(self::NO_TRAILING_SLASH[$name]);
            if (!$noSlash && !str_ends_with($path, '{slash}')) {
                $found[] = $name . ': the path ends with {slash}';
            }
            if ($noSlash) {
                // the exemption is for exactly this: no {slash} anywhere, and a last parameter that takes the rest of the path, slashes included
                $variables = $route->compile()->getPathVariables();
                $last = $variables === [] ? '' : $variables[array_key_last($variables)];
                if (str_contains($path, '{slash}')) {
                    $found[] = $name . ': an exempt route has no {slash}';
                }
                if ($last === '' || !str_ends_with($path, '{' . $last . '}')) {
                    $found[] = $name . ': an exempt route ends with its last parameter';
                } elseif (preg_match('~^(?:' . (string) $route->getRequirement($last) . ')$~', self::NO_TRAILING_SLASH[$name]) !== 1) {
                    $found[] = $name . ': the last parameter of an exempt route takes the rest of the path, slashes included';
                }
            }
            foreach (['lead', 'slash'] as $parameter) {
                if ($noSlash && $parameter === 'slash') {
                    continue;
                }
                if (($route->getRequirement($parameter) ?? null) !== '/?') {
                    $found[] = $name . ': the requirement of {' . $parameter . '} is /?';
                }
                if (($route->getDefault($parameter) ?? null) !== '') {
                    $found[] = $name . ': the default of {' . $parameter . '} is empty';
                }
            }
            $methods = $route->getMethods();
            sort($methods);
            $declared = $page->allowedMethods();
            sort($declared);
            if ($methods !== $declared) {
                $found[] = $name . ': the methods are those the page declares plus HEAD and OPTIONS (' . implode(',', $declared) . '), not ' . implode(',', $methods);
            }
            // A free parameter: one whose requirement can match a byte that is not a letter, a digit or one of `_-`
            foreach ($route->getRequirements() as $parameter => $requirement) {
                if (in_array($parameter, ['lead', 'slash'], true)) {
                    continue;
                }
                if (preg_match('~^(?:[A-Za-z0-9_\-|]|\[[A-Za-z0-9_\-\\\\]+\]|\(\?:|\)|[+*?]|\{\d+(?:,\d*)?\})+$~', (string) $requirement) !== 1 && $route->getOption('utf8') !== false) {
                    $found[] = $name . ': the requirement of {' . $parameter . '} may hold any byte, so the route sets utf8: false';
                }
            }
        }

        return $found;
    }
}
