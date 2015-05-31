<?php
namespace Ratchet\Http;
use Ratchet\Server\DataComponentInterface;
use Ratchet\ConnectionInterface;
use Psr\Http\Message\RequestInterface;

interface HttpServerInterface extends DataComponentInterface {
    /**
     * @param \Ratchet\ConnectionInterface          $conn
     * @param \Psr\Http\Message\RequestInterface    $request null is default because PHP won't let me overload; don't pass null!!!
     * @throws \UnexpectedValueException if a RequestInterface is not passed
     */
    public function onOpen(ConnectionInterface $conn, RequestInterface $request = null);
}
