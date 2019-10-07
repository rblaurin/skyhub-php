<?php
/**
 * B2W Digital - Companhia Digital
 *
 * Do not edit this file if you want to update this SDK for future new versions.
 * For support please contact the e-mail bellow:
 *
 * sdk@e-smart.com.br
 *
 * @category  SkyHub
 * @package   SkyHub
 *
 * @copyright Copyright (c) 2018 B2W Digital - BSeller Platform. (http://www.bseller.com.br).
 *
 * @author    Tiago Sampaio <tiago.sampaio@e-smart.com.br>
 * @author    Bruno Gemelli <bruno.gemelli@e-smart.com.br>
 */

namespace SkyHub\Api\Service;

use GuzzleHttp\Client as HttpClient;
use SkyHub\Api;
use SkyHub\Api\Helpers;
use SkyHub\Api\Handler\Response\HandlerDefault;
use SkyHub\Api\Log\Loggerable;
use SkyHub\Api\Log\TypeInterface\Request;
use SkyHub\Api\Log\TypeInterface\Response;
use SkyHub\Api\Handler\Response\HandlerException;

/**
 * Class ServiceAbstract
 *
 * @package SkyHub\Api\Service
 */
abstract class ServiceAbstract implements ServiceInterface
{
    use Loggerable, Helpers;

    /** @var HttpClient */
    protected $client = null;

    /** @var int */
    protected $timeout = 15;

    /** @var int */
    protected $requestId = null;

    /**
     * @var ClientBuilderInterface
     */
    private $clientBuilder;

    /**
     * @var OptionsBuilderInterface
     */
    private $optionsBuilder;

    /**
     * ServiceAbstract constructor.
     *
     * @param null                        $baseUri
     * @param array                       $headers
     * @param array                       $options
     * @param bool                        $log
     * @param ClientBuilderInterface|null $clientBuilder
     */
    public function __construct(
        string $baseUri = null,
        array $headers = [],
        array $options = [],
        ClientBuilderInterface $clientBuilder = null,
        OptionsBuilderInterface $optionsBuilder = null
    ) {
        $this->clientBuilder = $clientBuilder;

        if (null === $clientBuilder) {
            $this->clientBuilder = new ClientBuilder();
        }

        $this->optionsBuilder = $optionsBuilder;

        if (null === $optionsBuilder) {
            $this->optionsBuilder = new OptionsBuilder();
        }

        $this->optionsBuilder
            ->addOptions($options)
            ->getHeadersBuilder()
            ->addHeaders($headers);

        $this->prepareHttpClient($baseUri);

        return $this;
    }

    /**
     * Returns the default base URI.
     *
     * @return string
     */
    public function getDefaultBaseUri()
    {
        return self::DEFAULT_SERVICE_BASE_URI;
    }

    /**
     * @param bool $renew
     *
     * @return int
     */
    public function getRequestId($renew = false)
    {
        if (empty($this->requestId) || $renew) {
            $this->requestId = rand(1000000000000, 9999999999999);
        }

        return $this->requestId;
    }

    /**
     * @param string $method
     * @param string $uri
     * @param null   $body
     * @param array  $options
     *
     * @param bool   $debug
     *
     * @return Api\Handler\Response\HandlerInterfaceException|Api\Handler\Response\HandlerInterfaceSuccess
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function request(string $method, string $uri, $body = null, array $options = [], $debug = false)
    {
        try {
            $this->prepareRequest($method, $uri, $body, $options, $debug);

            /** @var \Psr\Http\Message\ResponseInterface $request */
            $response = $this->httpClient()->request($method, $uri, $this->optionsBuilder->build());

            /** @var Api\Handler\Response\HandlerInterfaceSuccess $responseHandler */
            $responseHandler = new HandlerDefault($response);

            /** Log the request response. */
            $logResponse = $this->getLoggerResponse()->importResponseHandler($responseHandler);
        } catch (\GuzzleHttp\Exception\ClientException $clientException) {
            /** Service Request Exception */
            $responseHandler = new HandlerException($clientException);

            $logResponse = $this->getLoggerResponse()
                ->importResponseExceptionHandler($responseHandler);
        } catch (\Exception $exception) {
            /** Service Request Exception */
            $responseHandler = new HandlerException($exception);

            $logResponse = $this->getLoggerResponse()
                ->importResponseExceptionHandler($responseHandler);
        }

        $this->clear();
        $this->logger()->logResponse($logResponse);

        return $responseHandler;
    }

    /**
     * @param        $method
     * @param string $uri
     * @param null   $body
     * @param array  $options
     * @param bool   $debug
     *
     * @return $this
     */
    private function prepareRequest($method, string $uri, $body = null, array $options = [], $debug = false)
    {
        $this->optionsBuilder
            ->addOptions($options)
            ->setTimeout($this->getTimeout())
            ->setDebug((bool) $debug)
            ->getHeadersBuilder();

        $this->prepareRequestBody($body);

        $protection = new HeadersProtection();
        $protectedFields = [
            Api::HEADER_USER_EMAIL,
            Api::HEADER_API_KEY,
            Api::HEADER_ACCOUNT_MANAGER_KEY
        ];

        $protection->protect($this->optionsBuilder->getHeadersBuilder()->getHeaders(), $protectedFields);
        $options = $this->optionsBuilder->build();
        unset($options['headers']);

        /** Log the request before sending it. */
        $logRequest = new Request(
            $this->getRequestId(),
            $method,
            $uri,
            $body,
            $protection->export(),
            $options
        );

        $this->logger()->logRequest($logRequest);

        return $this;
    }

    /**
     * This method clears the unnecessary information after a request.
     *
     * @return $this
     */
    protected function clear()
    {
        $this->clearRequestId();

        return $this;
    }

    /**
     * @return $this
     */
    protected function clearRequestId()
    {
        $this->requestId = null;

        return $this;
    }

    /**
     * @return $this
     */
    protected function prepareRequestHeaders()
    {
        return $this;
    }

    /**
     * @param string|array $bodyData
     *
     * @return $this
     */
    protected function prepareRequestBody($bodyData)
    {
        $this->getOptionsBuilder()->setBody($bodyData);
        return $this;
    }

    /**
     * @return OptionsBuilderInterface
     */
    public function getOptionsBuilder() : OptionsBuilderInterface
    {
        return $this->optionsBuilder;
    }

    /**
     * A private __clone method prevents this class to be cloned by any other class.
     *
     * @return void
     */
    private function __clone()
    {
    }

    /**
     * A private __wakeup method prevents this object to be unserialized.
     *
     * @return void
     */
    private function __wakeup()
    {
    }

    /**
     * @return HttpClient
     */
    protected function httpClient()
    {
        return $this->client;
    }

    /**
     * @param null  $baseUri
     * @param array $defaults
     *
     * @return HttpClient
     */
    protected function prepareHttpClient($baseUri = null)
    {
        if (empty($baseUri)) {
            $baseUri = $this->getDefaultBaseUri();
        }

        if (null === $this->client) {
            $this->client = $this->clientBuilder->build($baseUri);
        }

        return $this->client;
    }

    /**
     * @return array
     */
    public function getHeaders() : array
    {
        return (array) $this->getOptionsBuilder()
            ->getHeadersBuilder()
            ->getHeaders();
    }

    /**
     * @param array $headers
     * @param bool  $append
     *
     * @return $this|ServiceInterface
     */
    public function setHeaders(array $headers = [])
    {
        $this->optionsBuilder
            ->getHeadersBuilder()
            ->addHeaders($headers);

        return $this;
    }

    /**
     * @return int
     */
    public function getTimeout()
    {
        return (int) $this->timeout;
    }

    /**
     * @return \SkyHub\Api\Log\TypeInterface\TypeResponseInterface
     */
    protected function getLoggerResponse()
    {
        return new Response($this->getRequestId());
    }
}
