<?php
namespace Ratchet\WebSocket;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServerInterface;
use Ratchet\Http\CloseResponseTrait;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Psr7 as gPsr;

/**
 * The adapter to handle WebSocket requests/responses
 * This is a mediator between the Server and your application to handle real-time messaging through a web browser
 * @link http://ca.php.net/manual/en/ref.http.php
 * @link http://dev.w3.org/html5/websockets/
 */
class WsServer implements HttpServerInterface {
    use CloseResponseTrait;

    /**
     * Decorated component
     * @var \Ratchet\WebSocket\MessageComponentInterface
     */
    public $component;

    /**
     * @var \SplObjectStorage
     */
    protected $connections;

    /**
     * Holder of accepted protocols, implement through WampServerInterface
     */
    protected $acceptedSubProtocols = [];

    /**
     * Flag if we have checked the decorated component for sub-protocols
     * @var boolean
     */
    private $isSpGenerated = false;

    private $handshakeNegotiator;
    private $messageStreamer;

    /**
     * @param \Ratchet\WebSocket\MessageComponentInterface $component Your application to run with WebSockets
     * If you want to enable sub-protocols have your component implement WsServerInterface as well
     */
    public function __construct(MessageComponentInterface $component) {
        $this->component   = $component;
        $this->connections = new \SplObjectStorage;

        $encodingValidator         = new \Ratchet\RFC6455\Encoding\Validator;
        $this->handshakeNegotiator = new \Ratchet\RFC6455\Handshake\Negotiator($encodingValidator);
        $this->messageStreamer     = new \Ratchet\RFC6455\Messaging\Streaming\MessageStreamer($encodingValidator);
    }

    /**
     * {@inheritdoc}
     */
    public function onOpen(ConnectionInterface $conn, RequestInterface $request = null) {
        if (null === $request) {
            throw new \UnexpectedValueException('$request can not be null');
        }

        $conn->httpRequest = $request; // This will replace ->WebSocket->request

        $conn->WebSocket              = new \StdClass;
        $conn->WebSocket->closing     = false;
        $conn->WebSocket->request     = $request; // deprecated

        $response = $this->handshakeNegotiator->handshake($request)->withHeader('X-Powered-By', \Ratchet\VERSION);

//        Probably moved to RFC lib
//        $subHeader = $conn->WebSocket->request->getHeader('Sec-WebSocket-Protocol');
//        if (count($subHeader) > 0) {
//            if ('' !== ($agreedSubProtocols = $this->getSubProtocolString($subHeader))) {
//                $response = $response->withHeader('Sec-WebSocket-Protocol', $agreedSubProtocols);
//            }
//        }

        $conn->send(gPsr\str($response));

        if (101 != $response->getStatusCode()) {
            return $conn->close();
        }

        $wsConn  = new WsConnection($conn);
        $context = new ConnectionContext($wsConn, $this->component);
        $this->connections->attach($conn, $context);

        return $this->component->onOpen($wsConn);
    }

    /**
     * {@inheritdoc}
     */
    public function onData(ConnectionInterface $from, $chunk) {
        if ($from->WebSocket->closing) {
            return;
        }

        $context = $this->connections[$from];

        $this->messageStreamer->onData($chunk, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function onClose(ConnectionInterface $conn) {
        if ($this->connections->contains($conn)) {
            $decor = $this->connections[$conn];
            $this->connections->detach($conn);

            $conn = $decor->detach();

            $this->component->onClose($conn);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onError(ConnectionInterface $conn, \Exception $e) {
        if ($this->connections->contains($conn)) {
            $context = $this->connections[$conn];
            $context->onError($e);
        } else {
            $conn->close();
        }
    }

    /**
     * Toggle weather to check encoding of incoming messages
     * @param bool
     * @return WsServer
     */
    public function setEncodingChecks($opt) {
//        $this->validator->on = (boolean)$opt;

        return $this;
    }

    /**
     * @param string
     * @return boolean
     */
    public function isSubProtocolSupported($name) {
        if (!$this->isSpGenerated) {
            if ($this->component instanceof WsServerInterface) {
                $this->acceptedSubProtocols = array_flip($this->component->getSubProtocols());
            }

            $this->isSpGenerated = true;
        }

        return array_key_exists($name, $this->acceptedSubProtocols);
    }

    /**
     * @param  \Traversable|null $requested
     * @return string
     */
    protected function getSubProtocolString(\Traversable $requested = null) {
        if (null !== $requested) {
            foreach ($requested as $sub) {
                if ($this->isSubProtocolSupported($sub)) {
                    return $sub;
                }
            }
        }

        return '';
    }
}
