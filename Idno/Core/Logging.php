<?php

/**
 * Allow logging, with toggle support
 *
 * @package    idno
 * @subpackage core
 */

namespace Idno\Core {

    use Idno\Common\Component;
    use Psr\Log\LoggerInterface;
    use Psr\Log\LogLevel;

    class Logging extends Component implements LoggerInterface
    {

        /** Log level constants */
        const LEVEL_OFF     = 0;
        const LEVEL_ERROR   = 1;
        const LEVEL_WARNING = 2;
        const LEVEL_INFO    = 3;
        const LEVEL_DEBUG   = 4;

        public $loglevel_filter = self::LEVEL_DEBUG;
        private $identifier;
        private LoggerInterface $logger;

        /**
         * Create a basic logger to log to the PHP log.
         *
         * @param LoggerInterface $logger       The Monolog channel that writes the lines. Level filtering stays here.
         * @param type $loglevel_filter Log levels to show 0 - off, 1 - errors, 2 - errors & warnings, 3 - errors, warnings and info, 4 - 3 + debug
         * @param type $identifier      Identify this site in the log (defaults to current domain)
         */
        public function __construct(LoggerInterface $logger, $loglevel_filter = 0, $identifier = null)
        {
            $this->logger = $logger;
            if (!$identifier) {
                $identifier = Idno::site()->config()->host;
            }
            if (isset(Idno::site()->config()->loglevel)) {
                $loglevel_filter = Idno::site()->config()->loglevel;
            }

            $this->loglevel_filter = $loglevel_filter;
            $this->identifier = $identifier;

            // Set log to handle PHP errors
            set_error_handler(
                function ($errno, $errstr, $errfile, $errline, $errcontext = []) {

                    if (!(error_reporting() & $errno)) {
                        // This error code is not included in error_reporting
                        return;
                    }

                    $message = "PHP [{$errno}] {$errstr} in {$errfile}:{$errline}";

                    // Pedantic mode
                    if (\Idno\Core\Idno::site()->config()->pedantic_mode) {
                        $this->error($message);
                        throw new \ErrorException($message, 0, $errno, $errfile, $errline);
                    }

                    switch ($errno) {
                        case E_PARSE:
                        case E_ERROR:
                        case E_CORE_ERROR:
                        case E_COMPILE_ERROR:
                        case E_USER_ERROR:
                            $this->error($message);
                            break;

                        case E_WARNING:
                        case E_CORE_WARNING:
                        case E_COMPILE_WARNING:
                        case E_USER_WARNING:
                            $this->warning($message);
                            break;

                        case E_STRICT:
                        case E_NOTICE:
                        case E_DEPRECATED:
                        case E_USER_DEPRECATED:
                        case E_USER_NOTICE:
                            $this->notice($message);
                            break;

                        default:
                            $this->notice("Unknown error type: {$message}");
                            break;
                    }

                }
            );
        }

        /**
         * Sets the log level
         *
         * @param $loglevel
         */
        public function setLogLevel($loglevel)
        {
            $this->loglevel_filter = $loglevel;
        }

        /**
         * Check whether a LogLevel meets the current loglevel_filter.
         *
         * @param  string $level
         * @return boolean true if messages at this level should be logged
         */
        private function passesFilter($level)
        {
            switch ($level) {
                case LogLevel::EMERGENCY:
                case LogLevel::ALERT:
                case LogLevel::CRITICAL:
                case LogLevel::ERROR:
                    return $this->loglevel_filter >= self::LEVEL_ERROR;
                case LogLevel::WARNING:
                    return $this->loglevel_filter >= self::LEVEL_WARNING;
                case LogLevel::NOTICE:
                case LogLevel::INFO:
                    return $this->loglevel_filter >= self::LEVEL_INFO;
                case LogLevel::DEBUG:
                    return $this->loglevel_filter >= self::LEVEL_DEBUG;
            }
            return false;
        }

        /**
         * Write a message to the log.
         *
         * @param string $level
         * @param $message
         * @param array  $context
         */
        public function log($level, $message = '', array $context = array()): void
        {
            // backward compatibility: log($message, $numericLevel) calling convention
            if (is_string($level) && is_int($message)) {
                $temp = $level;
                if ($message === self::LEVEL_ERROR) {
                    $level = LogLevel::ERROR;
                } elseif ($message === self::LEVEL_WARNING) {
                    $level = LogLevel::WARNING;
                } elseif ($message === self::LEVEL_INFO) {
                    $level = LogLevel::INFO;
                } elseif ($message === self::LEVEL_DEBUG) {
                    $level = LogLevel::DEBUG;
                }
                $message = $temp;
            }

            // See if this message isn't filtered out
            if ($this->passesFilter($level)) {

                $stats = \Idno\Core\Idno::site()->statistics();
                if (!empty($stats)) {
                    $stats->increment("log.$level");
                }

                // Construct log message
                // Trace for debug (when filtering is set to debug, always add a trace)
                $trace = "";
                if ($this->loglevel_filter == self::LEVEL_DEBUG) {
                    $backtrace = @debug_backtrace(false, 3);
                    foreach (array_reverse($backtrace) as $frame) {
                        if (isset($frame['class']) && isset($frame['file']) && isset($frame['line']) /*&& $frame['class'] !== 'Idno\Core\Logging'*/) {
                            $trace = " [{$frame['file']}:{$frame['line']}]";
                            break;
                        }
                    }
                }

                if ($context) {
                    // borrowed from Monolog's LineFormatter
                    if (is_bool($context)) {
                        $context = $context ? 'true' : 'false';
                    } else if (is_scalar($context)) {
                        $context = (string) $context;
                    } else {
                        $context = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                    $message .= ' ' . $context;
                }

                $lines = 0;
                $message = explode("\n", $message);
                foreach ($message as $log) {
                    $logline = "Idno ({$this->identifier}): $level - " . rtrim($log);

                    if ($lines == count($message)-1) {
                        $logline.=$trace;
                    }

                    // The line is complete, so the channel gets no context to interpolate.
                    $this->logger->log($level, $logline);

                    $lines ++;
                }
            }
        }

        /**
         * System is unusable.
         *
         * @param $message
         * @param array  $context
         *
         * @return null
         */
        public function emergency($message, array $context = array()): void
        {
            $this->log(LogLevel::EMERGENCY, $message, $context);
        }

        /**
         * Action must be taken immediately.
         *
         * Example: Entire website down, database unavailable, etc. This should
         * trigger the SMS alerts and wake you up.
         *
         * @param $message
         * @param array  $context
         *
         * @return null
         */
        public function alert($message, array $context = array()): void
        {
            $this->log(LogLevel::ALERT, $message, $context);
        }

        /**
         * Critical conditions.
         *
         * Example: Application component unavailable, unexpected exception.
         *
         * @param $message
         * @param array  $context
         *
         * @return null
         */
        public function critical($message, array $context = array()): void
        {
            $this->log(LogLevel::CRITICAL, $message, $context);
        }

        /**
         * Runtime errors that do not require immediate action but should typically
         * be logged and monitored.
         *
         * @param $message
         * @param array  $context
         *
         * @return null
         */
        public function error($message, array $context = array()): void
        {
            $this->log(LogLevel::ERROR, $message, $context);
        }

        /**
         * Exceptional occurrences that are not errors.
         *
         * Example: Use of deprecated APIs, poor use of an API, undesirable things
         * that are not necessarily wrong.
         *
         * @param $message
         * @param array  $context
         *
         * @return null
         */
        public function warning($message, array $context = array()): void
        {
            $this->log(LogLevel::WARNING, $message, $context);
        }

        /**
         * Normal but significant events.
         *
         * @param $message
         * @param array  $context
         *
         * @return null
         */
        public function notice($message, array $context = array()): void
        {
            $this->log(LogLevel::NOTICE, $message, $context);
        }

        /**
         * Interesting events.
         *
         * Example: User logs in, SQL logs.
         *
         * @param $message
         * @param array  $context
         *
         * @return null
         */
        public function info($message, array $context = array()): void
        {
            $this->log(LogLevel::INFO, $message, $context);
        }

        /**
         * Detailed debug information.
         *
         * @param $message
         * @param array  $context
         *
         * @return null
         */
        public function debug($message, array $context = array()): void
        {
            $this->log(LogLevel::DEBUG, $message, $context);
        }


        /**
         * (attempt) to send, if configured, a message when a fatal error occurs, or an exception is caught.
         *
         * @param type $message
         * @param type $title
         */
        public static function oopsAlert($message, $title = "")
        {
            // Called from the exception handler and the fatal-error shutdown function: whatever goes wrong
            // here (a template, the mailer, a missing service) must not escape into a handler that is
            // already handling an error.
            $bufferLevel = ob_get_level();
            try {

                $config = \Idno\Core\idno::site()->config();

                if (!empty($config) && !empty($config->oops_notify)) {

                    $notify = $config->oops_notify;
                    if (!is_array($notify)) {
                        $notify = [$notify];
                    }

                    foreach ($notify as $emailaddress) {
                        if (!empty($emailaddress) && filter_var($emailaddress, FILTER_VALIDATE_EMAIL)) {

                            $vars = [
                                'site' => $config->getDisplayURL(),
                                'title' => $title,
                                'message' => $message,
                                'user' => 'UNKNOWN',

                                'agent' => '',
                                'qs' => '',
                                'referrer' => '',
                            ];

                            $uuid = \Idno\Core\Idno::site()->session()->currentUserUUID();
                            if (!empty($uuid)) {
                                $vars['user'] = \Idno\Core\Idno::site()->session()->currentUserUUID();
                            }

                            if (!empty($_SERVER['HTTP_USER_AGENT'])) {
                                $vars['agent'] = $_SERVER['HTTP_USER_AGENT'];
                            }

                            if (!empty($_SERVER['QUERY_STRING'])) {
                                $vars['qs'] = \Idno\Core\QueryRedactor::query((string) $_SERVER['QUERY_STRING']);
                            }

                            if (!empty($_SERVER['HTTP_REFERER'])) {
                                $vars['referrer'] = \Idno\Core\QueryRedactor::uri((string) $_SERVER['HTTP_REFERER']);
                            }

                            $email = new Email();
                            try {
                                // The template writes the subject: the site's host name and the kind of error
                                $email->setBodyFromTemplate('admin/oops', $vars);
                            } catch (\Throwable $drawing) {
                                // A broken engine or an unloadable template must not cost the owner the alert: a short plain text
                                // body is built here, with the facts the text template prints (the site and the message, which
                                // carries the file and line) and nothing from the request.
                                \Idno\Templating\OutputBuffers::endDownTo($bufferLevel);
                                try {
                                    \Idno\Core\Idno::site()->logging()->error('Could not draw the alert: ' . get_class($drawing));
                                } catch (\Throwable $ignored) {
                                }
                                $email = new Email();
                                $email->setSubject($config->host . ": $title");
                                $email->setTextBody("Ooops!\n======\n\nAn error occured on your Idno site " . $vars['site'] . "!\n\n" . (is_scalar($message) ? (string) $message : '') . "\n");
                            }
                            $email->addTo($emailaddress);
                            $email->send();

                        }
                    }
                }
            } catch (\Throwable $e) {
                // A template that threw leaves its output buffer open: close it, discarding what it drew.
                \Idno\Templating\OutputBuffers::endDownTo($bufferLevel);
                try {
                    \Idno\Core\Idno::site()->logging()->error('Could not send the alert: ' . get_class($e));
                } catch (\Throwable $ignored) {
                }
            }
        }

    }

    // @deprecated Use Logging::LEVEL_* class constants instead. Kept for backward compatibility.
    define('LOGLEVEL_OFF', Logging::LEVEL_OFF);
    define('LOGLEVEL_ERROR', Logging::LEVEL_ERROR);
    define('LOGLEVEL_WARNING', Logging::LEVEL_WARNING);
    define('LOGLEVEL_INFO', Logging::LEVEL_INFO);
    define('LOGLEVEL_DEBUG', Logging::LEVEL_DEBUG);
}
