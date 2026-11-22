<?php

namespace Idno\Core {

    /**
     * Decides what a visitor is told about an exception that stopped a page from being served.
     *
     * An exception's message is taken to be written for the visitor, and is shown, unless the
     * exception's type says it is a technical one. A technical message is shown only when the
     * site is in debug mode; otherwise the visitor is told only that something went wrong.
     * Either way the message is logged by whatever handles the exception.
     *
     * A type is technical when:
     *
     * - it implements \Idno\Exceptions\Technical (\Idno\Exceptions\TechnicalException does);
     * - it is a PHP error (\Error) or a PHP warning or notice turned into an exception
     *   (\ErrorException);
     * - it, or a class it extends, belongs to a PHP extension or to a library in a vendor
     *   directory (\PDOException, the MongoDB driver's exceptions, Twig's errors): Idno can't
     *   mark those, and their messages describe the server;
     * - it was thrown by the code of a library in a vendor directory, whatever its type.
     *
     * A type that implements \Idno\Exceptions\Friendly is never technical.
     */
    class ExceptionMessage
    {

        /// PHP's general-purpose exception classes, which say nothing about who the message is for
        const GENERAL_PURPOSE = [
            'Exception',
            'RuntimeException',
            'LogicException',
            'BadFunctionCallException',
            'BadMethodCallException',
            'DomainException',
            'InvalidArgumentException',
            'LengthException',
            'OutOfBoundsException',
            'OutOfRangeException',
            'OverflowException',
            'RangeException',
            'UnderflowException',
            'UnexpectedValueException',
        ];

        /**
         * Is this exception's message a technical one, that visitors should not be shown?
         *
         * @param  \Throwable $exception
         * @return boolean
         */
        public static function isTechnical(\Throwable $exception)
        {
            if ($exception instanceof \Idno\Exceptions\Friendly) {
                return false;
            }

            if ($exception instanceof \Idno\Exceptions\Technical) {
                return true;
            }

            if ($exception instanceof \Error || $exception instanceof \ErrorException) {
                return true;
            }

            // An exception class of a PHP extension or of a library, or a class that extends one
            for ($class = new \ReflectionClass($exception); $class; $class = $class->getParentClass()) {
                if ($class->isInternal()) {
                    if (!in_array($class->getName(), self::GENERAL_PURPOSE, true)) {
                        return true;
                    }
                } else if (self::isLibraryFile($class->getFileName())) {
                    return true;
                }
            }

            // An exception thrown by a library's code
            return self::isLibraryFile($exception->getFile());
        }

        /**
         * The message to show a visitor for this exception: the exception's own message, or,
         * when that is a technical one and the site is not in debug mode, only that something
         * went wrong.
         *
         * The text is not HTML: escape it before printing it in a page.
         *
         * @param  \Throwable $exception
         * @return string
         */
        public static function forVisitor(\Throwable $exception)
        {
            $message = $exception->getMessage();

            if ($message !== '') {
                if (!empty(\Idno\Core\Idno::site()->config()->debug) || !self::isTechnical($exception)) {
                    return $message;
                }
            }

            return \Idno\Core\Idno::site()->language()->_('Oh no! Something went wrong.');
        }

        /**
         * Is this file part of a library: is it in a vendor directory?
         *
         * @param  string|false $file
         * @return boolean
         */
        private static function isLibraryFile($file)
        {
            if (empty($file)) {
                return false;
            }

            // Idno may itself be installed in another project's vendor directory: what matters
            // for Idno's own files is where they are inside Idno.
            $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
            if (strpos($file, $root) === 0) {
                $file = substr($file, strlen($root) - 1);
            }

            return strpos($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false;
        }

    }

}
