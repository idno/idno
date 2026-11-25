<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Common\Page;
use Idno\Core\Idno;

/**
 * Test-environment only (registered by TestEventHooks): a legacy page that draws, answers and forwards the way the pages of
 * the site do, for each `case` of the request. The fixture controller `SitePageFixtureController` does the same for each case
 * through `PageRenderer`, and `SitePageDrawingTest` compares the two answers. Keep each case here and there identical. The directories
 * that have a shell of their own have a class of their own (LegacyAdminDrawPage, LegacyAccountDrawPage): a page is a settings page by its class.
 */
class LegacyDrawPage extends Page
{
    public function getContent()
    {
        $t = Idno::site()->template();
        $case = (string) $this->getInput('case', 'draw');
        switch ($case) {
            case 'drawmessage':
                Idno::site()->session()->addMessage('A message to be shown once');
                // no break
            case 'draw':
                $t->__(['title' => 'A drawn page', 'description' => 'A page drawn for a test.', 'body' => $t->__(['offset' => 0])->draw('pages/home/blurb')])->drawPage();
                break;
            case 'permalink':
                $this->setPermalink();
                $user = Idno::site()->session()->currentUser();
                if ($user) {
                    $this->setOwner($user);
                }
                $t->__(['title' => 'A drawn page', 'description' => 'A page drawn for a test.', 'body' => $t->__(['offset' => 0])->draw('pages/home/blurb')])->drawPage();
                break;
            case 'notfound':
                $this->noContent();
                break;
            case 'gone':
                $this->goneContent();
                break;
            case 'denied':
                $this->deniedContent((string) $this->getInput('title', ''));
                break;
            case 'error':
                $this->exception(self::brokenPage());
                break;
            case 'technical':
                $this->exception(self::technicalPage());
                break;
            case 'redirect201':
                $this->setResponse(201);
                $this->forward((string) $this->getInput('to', ''));
                break;
            case 'redirect':
                $this->forward((string) $this->getInput('to', ''));
                break;
        }
    }

    /**
     * The exception of the `error` case, made in one place so that both pages show the same file and line.
     */
    public static function brokenPage(): \RuntimeException
    {
        return new \RuntimeException('Broken <b>page</b>');
    }

    /**
     * The exception of the `technical` case: one whose message is for whoever maintains the site, not for a visitor.
     */
    public static function technicalPage(): \Idno\Exceptions\TechnicalException
    {
        return new \Idno\Exceptions\TechnicalException('Broken <b>query</b> on idno_entities');
    }
}
