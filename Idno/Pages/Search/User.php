<?php

namespace Idno\Pages\Search {

    class User extends \Idno\Common\Page
    {

        public function getContent()
        {
            \Idno\Core\Idno::site()->template()->setTemplateType('json');

            $this->gatekeeper();

            $limit = $this->getInput('limit', 100);
            $offset = $this->getInput('offset', 0);
            $query = trim($this->getInput('query'));
            $sort = $this->getInput('sort', 'created');
            $order = $this->getInput('order', 'desc');
            $template = $this->getInput('template', 'forms/components/usersearch/user');

            // Sanitise
            if ($sort != 'created' && $sort != 'name') {
                throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("Invalid sort type %s", [$sort]));
            }

            if ($order != 'asc' && $order != 'desc') {
                throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("Invalid sort order %s", [$order]));
            }

            $search = [];
            if (!empty($query)) {
                //                $search['$or'][] = ['handle' => $query];
                //                $search['$or'][] = ['title' => $query];
                //                $search['$or'][] = ['email' => $query];
                if (filter_var($query, FILTER_VALIDATE_EMAIL)) {
                    $search['email'] = $query;  // Fudge email search within the limits of what we can do right now
                } else {
                    $search = \Idno\Core\Idno::site()->db()->createSearchArray($query);
                }
            }

            $users = \Idno\Entities\User::getFromX(["Idno\\Entities\\User", "Idno\\Entities\\RemoteUser"], $search, [], $limit, $offset);
            $count = \Idno\Entities\User::countFromX(["Idno\\Entities\\User", "Idno\\Entities\\RemoteUser"], $search);

            $results = [
                'count' => $count,
                'rendered' => ''
            ];

            $t = new \Idno\Core\DefaultTemplate();
            if (!empty($users)) {
                foreach ($users as $user) {
                    $results['rendered'] .= $t->__(['user' => $user])->draw($template);
                }
            }

            // The rows draw their action links on the JSON template type this page sets, whose `forms/link` (the copy in `templates/classic/`)
            // keeps each link's form in this global when the request is an XHR one (the search's script sends X-Requested-With); the Twig
            // `forms/link` of a web page returns the form inline instead, through form_data(). So the forms of the links come here.
            global $template_postponed_link_actions;
            if (!empty($template_postponed_link_actions)) {
                $results['rendered'] .= $template_postponed_link_actions;
            }

            echo json_encode($results); exit;
        }

    }

}
