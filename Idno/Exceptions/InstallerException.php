<?php

namespace Idno\Exceptions {

    /**
     * Something the installer found wrong: plain words for the person installing, the shell
     * commands that would fix it (if any), and the field of the settings form it concerns.
     * The message is plain text, never HTML.
     */
    class InstallerException extends \RuntimeException implements Friendly
    {

        private $problem;
        private $field;

        /**
         * @param array  $problem 'text' and 'commands', see \Idno\Core\Installer::problem()
         * @param string $field
         */
        public function __construct(array $problem, $field = '')
        {
            $this->problem = ['text' => (string) ($problem['text'] ?? ''), 'commands' => array_values((array) ($problem['commands'] ?? []))];
            $this->field = (string) $field;
            parent::__construct(trim($this->problem['text'] . "\n" . implode("\n", $this->problem['commands'])));
        }

        public function getProblem()
        {
            return $this->problem;
        }

        public function getField()
        {
            return $this->field;
        }
    }
}
