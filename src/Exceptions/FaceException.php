<?php

namespace Csgt\Face\Exceptions;

use RuntimeException;
use Throwable;

class FaceException extends RuntimeException
{
    /**
     * The response returned by the FACE provider.
     *
     * @var mixed
     */
    protected $payload;

    /**
     * @var int
     */
    protected $status;

    /**
     * @param string         $message
     * @param mixed          $payload
     * @param int            $status
     * @param Throwable|null $previous
     */
    public function __construct($message, $payload = null, $status = 400, Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->payload = $payload;
        $this->status = $status;
    }

    /**
     * Get the response returned by the FACE provider.
     *
     * @return mixed
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * Render the exception as a frontend-friendly JSON response.
     *
     * @param mixed $request
     * @return mixed
     */
    public function render($request)
    {
        return response()->json([
            'message' => $this->getMessage(),
            'payload' => $this->payload,
        ], $this->status);
    }
}
