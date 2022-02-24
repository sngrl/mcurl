<?php

namespace MCurl;

class Result
{
    /**
     * @see get{Name}()
     *
     * @var mixed          $id
     * @var resource       $ch
     * @var array          $info
     * @var array          $options
     * @var int            $httpCode
     * @var string         $body
     * @var resource       $bodyStream
     * @var \stdClass|null $json
     * @var array          $headers
     * @var array          $params
     * @var bool           $hasError
     * @var string         $errorType
     * @var string         $error
     * @var int            $errorCode
     *
     */

    /**
     * @var array
     */
    protected $query;

    /**
     * @var array
     */
    protected $rawHeaders;

    /**
     * @var bool
     */
    public $callback_processed = false;

    public function __construct($query)
    {
        $this->query = $query;
    }

    /**
     * Return id in request
     * @return null|mixed
     */
    public function getId()
    {
        return isset($this->query['id']) ? $this->query['id'] : null;
    }

    /**
     * cURL session: curl_init()
     * @return resource
     */
    public function getCh()
    {
        return $this->query['ch'];
    }

    /**
     * @return mixed
     * @see curl_getinfo();
     */
    public function getInfo()
    {
        return curl_getinfo($this->query['ch']);
    }

    /**
     * Return curl option in request
     * @return array
     */
    public function getOptions()
    {
        $opts = $this->query['opts'];
        unset($opts[CURLOPT_FILE]);
        if (isset($opts[CURLOPT_WRITEHEADER])) {
            unset($opts[CURLOPT_WRITEHEADER]);
        }

        return $opts;
    }

    /**
     * @param mixed $option
     *
     * @return mixed|null
     */
    public function getOption($option)
    {
        return $this->hasOption($option) ? $this->query['opts'][$option] : null;
    }

    /**
     * @param mixed $option
     *
     * @return bool
     */
    public function hasOption($option)
    {
        return isset($this->query['opts'][$option]);
    }

    /**
     * Return curl option full list in request
     * @return array
     */
    public function getOptionsFull()
    {
        $opts = $this->query['opts'];
        unset($opts[CURLOPT_FILE]);

        return $opts;
    }

    /**
     * Result http code
     * @return int
     * @see curl_getinfo($ch, CURLINFO_HTTP_CODE)
     */
    public function getHttpCode()
    {
        return (int)curl_getinfo($this->query['ch'], CURLINFO_HTTP_CODE);
    }

    /**
     * Example:
     * $this->getHeaders() =>
     * return [
     *  'result' => 'HTTP/1.1 200 OK',
     *  'content-type' => 'text/html',
     *  'content-length' => '1024'
     *  ...
     * ];
     *
     * Or $this->headers['content-type'] => return 'text/html' @return array
     * @see $this->__get()
     */
    public function getHeaders()
    {
        if (!isset($this->rawHeaders) && isset($this->query['opts'][CURLOPT_WRITEHEADER])) {
            rewind($this->query['opts'][CURLOPT_WRITEHEADER]);
            $headersRaw = stream_get_contents($this->query['opts'][CURLOPT_WRITEHEADER]);
            $headers = explode("\n", rtrim($headersRaw));
            $this->rawHeaders['result'] = trim(array_shift($headers));

            foreach ($headers as $header) {
                list($name, $value) = array_map('trim', explode(':', $header, 2));
                $name = strtolower($name);
                if ($name == 'set-cookie') {
                    $this->rawHeaders[$name][] = trim($value);
                } else {
                    $this->rawHeaders[$name] = trim($value);
                }
            }
        }

        return $this->rawHeaders;
    }

    /**
     * @return string
     */
    public function getBody()
    {
        if (isset($this->query['opts'][CURLOPT_FILE])) {
            rewind($this->query['opts'][CURLOPT_FILE]);

            return stream_get_contents($this->query['opts'][CURLOPT_FILE]);
        } else {
            return curl_multi_getcontent($this->query['ch']);
        }
    }

    /**
     * @return mixed
     */
    public function getBodyStream()
    {
        rewind($this->query['opts'][CURLOPT_FILE]);

        return $this->query['opts'][CURLOPT_FILE];
    }

    /**
     * @return mixed
     */
    public function getJson()
    {
        $args = func_get_args();
        if (empty($args)) {
            return @json_decode($this->getBody(), true);
        } else {
            array_unshift($args, $this->getBody());

            return @call_user_func_array('json_decode', $args);
        }
    }

    /**
     * return params request
     * @return mixed
     */
    public function getParameters()
    {
        return $this->query['params'];
    }

    /**
     * Alias of the getParameters() for backward compatibility
     * @return mixed
     */
    public function getParams()
    {
        return $this->getParameters();
    }

    /**
     * @param mixed $parameter
     *
     * @return mixed|null
     */
    public function getParameter($parameter)
    {
        return $this->hasParameter($parameter) ? $this->query['params'][$parameter] : null;
    }

    /**
     * @param mixed $parameter
     *
     * @return bool
     */
    public function hasParameter($parameter)
    {
        return isset($this->query['params'][$parameter]);
    }

    /**
     * Has error
     *
     * @param null|string $type use: network|http
     *
     * @return bool
     */
    public function hasError($type = null)
    {
        $errorType = $this->getErrorType();

        return (isset($errorType) && ($errorType == $type || !isset($type)));
    }

    /**
     * Return network if has curl error or http if http code >=400
     * @return null|string return string: network|http or null if not error
     */
    public function getErrorType()
    {
        if (curl_error($this->query['ch'])) {
            return 'network';
        }

        if ($this->getHttpCode() >= 400) {
            return 'http';
        }

        return null;
    }

    /**
     * Return message error
     * @return null|string
     */
    public function getError()
    {
        $message = null;
        switch ($this->getErrorType()) {
            case 'network':
                $message = curl_error($this->query['ch']);
                break;
            case 'http':
                $message = 'http error ' . $this->getHttpCode();
                break;
        }

        return $message;
    }

    /**
     * Return code error
     * @return int|null
     */
    public function getErrorCode()
    {
        $number = null;
        switch ($this->getErrorType()) {
            case 'network':
                $number = (int)curl_errno($this->query['ch']);
                break;
            case 'http':
                $number = $this->getHttpCode();
                break;
        }

        return $number;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getBody();
    }

    /**
     * Return info dump
     * @return array
     */
    public function toArray()
    {
        return [
            'error'      => $this->getErrorArray(),
            'headers'    => $this->getHeaders(),
            'body'       => $this->getBody(),
            'options'    => $this->getOptionsFull(),
            'parameters' => $this->getParameters(),
        ];
    }

    /**
     * @return array
     */
    public function getErrorArray()
    {
        return ($this->hasError()
            ? [
                'type'    => $this->getErrorType(),
                'code'    => $this->getErrorCode(),
                'message' => $this->getError(),
            ]
            : null
        );
    }

    /**
     * Simple get result
     * @Example: $this->id, $this->body, $this->error, $this->hasError, $this->headers['content-type'], ...
     *
     * @param $key
     *
     * @return null
     */
    public function __get($key)
    {
        $method = 'get' . $key;

        return method_exists($this, $method) ? $this->$method() : null;
    }

    public function __destruct()
    {
        $this->closeFileHandlers();

        if (is_resource($this->query['ch'])) {
            curl_close($this->query['ch']);
        }
    }

    /**
     * @return self
     */
    public function closeFileHandlers()
    {
        if (isset($this->query['opts'][CURLOPT_FILE]) && is_resource($this->query['opts'][CURLOPT_FILE])) {
            fclose($this->query['opts'][CURLOPT_FILE]);
        }

        if (isset($this->query['opts'][CURLOPT_WRITEHEADER]) && is_resource($this->query['opts'][CURLOPT_WRITEHEADER])) {
            fclose($this->query['opts'][CURLOPT_WRITEHEADER]);
        }

        return $this;
    }
}