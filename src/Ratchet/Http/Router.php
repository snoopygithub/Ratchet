<?php
namespace Ratchet\Http;
use Ratchet\ConnectionInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class Router implements HttpServerInterface {
    use CloseResponseTrait;

    /**
     * @var \Symfony\Component\Routing\Matcher\UrlMatcherInterface
     */
    protected $_matcher;

    public function __construct(UrlMatcherInterface $matcher) {
        $this->_matcher = $matcher;
    }

    /**
     * {@inheritdoc}
     * @throws \UnexpectedValueException If a controller is not \Ratchet\Http\HttpServerInterface
     */
    public function onOpen(ConnectionInterface $conn, RequestInterface $request = null) {
        if (null === $request) {
            throw new \UnexpectedValueException('$request can not be null');
        }

        $uri = $request->getUri();

        $context = $this->_matcher->getContext();
        $context->setMethod($request->getMethod());
        $context->setHost($uri->getHost());

        try {
            $route = $this->_matcher->match($uri->getPath());
        } catch (MethodNotAllowedException $nae) {
            return $this->close($conn, 403);
        } catch (ResourceNotFoundException $nfe) {
            return $this->close($conn, 404);
        }

        if (is_string($route['_controller']) && class_exists($route['_controller'])) {
            $route['_controller'] = new $route['_controller'];
        }

        if (!($route['_controller'] instanceof HttpServerInterface)) {
            throw new \UnexpectedValueException('All routes must implement Ratchet\Http\HttpServerInterface');
        }

//        TODO: Apply Symfony default params to request
//        $parameters = [];
//        foreach($route as $key => $value) {
//            if ((is_string($key)) && ('_' !== substr($key, 0, 1))) {
//                $parameters[$key] = $value;
//            }
//        }
//        $parameters = array_merge($parameters, gPsr\parse_query($uri->getQuery()));
//
//        $url = Url::factory($request->getPath());
//        $url->setQuery($parameters);
//        $request->setUrl($url);

        $conn->controller = $route['_controller'];
        $conn->controller->onOpen($conn, $request);
    }

    /**
     * {@inheritdoc}
     */
    function onData(ConnectionInterface $from, $chunk) {
        $from->controller->onData($from, $chunk);
    }

    /**
     * {@inheritdoc}
     */
    function onClose(ConnectionInterface $conn) {
        if (isset($conn->controller)) {
            $conn->controller->onClose($conn);
        }
    }

    /**
     * {@inheritdoc}
     */
    function onError(ConnectionInterface $conn, \Exception $e) {
        if (isset($conn->controller)) {
            $conn->controller->onError($conn, $e);
        }
    }
}