<?php

namespace IdnoPlugins\Atmosphere;

/**
 * A PDS (or an authorization server) refused a request. The message holds the HTTP
 * status and the name of the error only: never what the server wrote in its answer,
 * and never a token, so it can be logged as it is.
 */
class XrpcException extends \Idno\Exceptions\TechnicalException
{
    private $status;
    private $errorName;

    /**
     * @param string $what   What was being done ("XRPC error", "Token refresh failed")
     * @param int    $status The HTTP status of the answer
     * @param mixed  $error  The "error" field of the answer, if it had one
     */
    public function __construct(string $what, int $status, $error = null)
    {
        $this->status = $status;
        $this->errorName = is_string($error) && preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/', $error) ? $error : '';

        parent::__construct($what . ' (' . $status . ')' . ($this->errorName !== '' ? ': ' . $this->errorName : ''));
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return string The name of the error ("InvalidSwap", "RecordNotFound", "invalid_grant"); may be empty
     */
    public function getErrorName(): string
    {
        return $this->errorName;
    }
}
