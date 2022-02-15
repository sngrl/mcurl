<?php

namespace MCurl;

class Client
{

    /**
     * Result write in memory
     */
    const STREAM_MEMORY = 'php://memory';

    /**
     * Result write in temporary files. Dir @see sys_get_temp_dir()
     */
    const STREAM_FILE = 'php://temp/maxmemory:0';

    /**
     * Not exec request
     * @var array
     */
    protected $queries = [];

    /**
     * Not exec count request
     * @var int
     */
    protected $queriesCount = 0;

    /**
     * Exec request
     * @var array
     */
    protected $queriesQueue = [];

    /**
     * Exec count request
     * @var int
     */
    protected $queriesQueueCount = 0;

    /**
     * Curl default  options
     * @see ->addCurlOption(), ->getCurlOption(), ->delCurlOption()
     * @var array
     */
    protected $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => true,
    ];

    /**
     * Max asynchron request
     * @var int
     */
    protected $maxRequest = 10;

    /**
     * Return result class
     * @var int microsecond
     */
    protected $classResult = '\\MCurl\\Result';

    /**
     * Sleep script undo $this->sleepNext request
     * @var int microsecond
     */
    protected $sleep = 0;

    /**
     * @see $this->sleep
     * @var int
     */
    protected $sleepNext;

    protected $sleepBlocking;

    protected $sleepNextTime;

    /**
     * Count executed request
     * @var int
     */
    protected $count = 0;

    /**
     * Save results
     * @var array
     */
    protected $results = [];

    /**
     * @see curl_multi_init()
     * @var null
     */
    protected $mh;

    /**
     * CurlShareHandle from curl_share_init()
     */
    protected $sh;

    /**
     * has Request
     * @var bool
     */
    protected $isRunMh = false;

    /**
     * Has use blocking function curl_multi_select
     * @var bool
     */
    protected $isSelect = true;

    /**
     * @example self::STREAM_MEMORY
     * @see     http://php.net/manual/ru/wrappers.php
     * @var string
     */
    protected $streamResult = null;

    protected $enableHeaders = false;

    /**
     * @see http://php.net/manual/ru/stream.filters.php
     * @var array
     */
    protected $streamFilters = [];

    /**
     * @var string
     */
    protected $baseUrl;

    public function __construct()
    {
        $this->mh = curl_multi_init();
    }

    /**
     * This parallel request if maxRequest > 1
     * $client->get('http://example.com/1.php') => return Result
     * Or $client->get('http://example.com/1.php', 'http://example.com/2.php') => return Result[]
     * Or $client->get(['http://example.com/1.php', 'http://example.com/2.php'], [CURLOPT_MAX_RECV_SPEED_LARGE => 1024]) => return Result[]
     *
     * @param string|array $url
     * @param array        $opts @see http://www.php.net/manual/ru/function.curl-setopt.php
     *
     * @return Result|Result[]|null
     */
    public function get($url, $opts = [])
    {
        $urls = (array)$url;

        foreach ($urls as $id => $u) {
            $opts[CURLOPT_URL] = $u;
            $this->add($opts, $id);
        }

        return is_array($url) ? $this->all() : $this->next();
    }

    /**
     * @param string|array $url
     * @param array        $data post data
     * @param array        $opts
     *
     * @return Result|Result[]|null
     * @see $this->get
     */
    public function post($url, $data = [], $opts = [])
    {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $data;

        return $this->get($url, $opts);
    }

    /**
     * Add request
     *
     * @param array        $opts   Options curl. Example: [CURLOPT_URL => 'http://example.com'];
     * @param array|string $params All data, require binding to the request or if string: identity request
     *
     * @return bool
     */
    public function add($opts = [], $params = [])
    {
        $id = null;

        if (is_string($params)) {
            $id = $params;
            $params = [];
        }

        if (isset($this->baseUrl, $opts[CURLOPT_URL])) {
            $opts[CURLOPT_URL] = $this->baseUrl . $opts[CURLOPT_URL];
        }

        if (isset($this->streamResult) && !isset($opts[CURLOPT_FILE])) {
            $opts[CURLOPT_FILE] = fopen($this->streamResult, 'r+');
            if (!$opts[CURLOPT_FILE]) {
                return false;
            }
        }

        if (!empty($this->streamFilters) && isset($opts[CURLOPT_FILE])) {
            foreach ($this->streamFilters as $filter) {
                stream_filter_append($opts[CURLOPT_FILE], $filter);
            }
        }

        if (!isset($opts[CURLOPT_WRITEHEADER]) && $this->enableHeaders) {
            $opts[CURLOPT_WRITEHEADER] = fopen(self::STREAM_MEMORY, 'r+');
            if (!$opts[CURLOPT_WRITEHEADER]) {
                return false;
            }
        }

        $query = [
            'id'     => $id,
            'opts'   => $opts,
            'params' => $params,
        ];

        $this->queries[] = $query;
        $this->queriesCount++;

        return true;
    }

    /**
     * Set wrappers
     *
     * @param string $stream
     *
     * @return self
     *
     * @see self::STREAM_*
     *      Default: self::STREAM_MEMORY
     * @see http://php.net/manual/ru/wrappers.php
     */
    public function setStreamResult($stream)
    {
        $this->streamResult = $stream;

        return $this;
    }

    /**
     * Set stream filters
     * @see      http://php.net/manual/ru/stream.filters.php
     * @example  ['string.strip_tags', 'string.tolower']
     *
     * @param array $filters Registered Stream Filters
     *
     * @return self
     */
    public function setStreamFilters(array $filters)
    {
        $this->streamFilters = $filters;

        return $this;
    }

    /**
     * Enable headers in result. Default false
     *
     * @param bool $enable
     *
     * @return self
     */
    public function enableHeaders($enable = true)
    {
        $this->enableHeaders = $enable;

        return $this;
    }

    /**
     * Set default curl options
     *
     * @param array $values
     *
     * @return self
     *
     * @example:
     *         [
     *         CURLOPT_TIMEOUT => 10,
     *         CURLOPT_COOKIEFILE => '/path/to/cookie.txt',
     *         CURLOPT_COOKIEJAR => '/path/to/cookie.txt',
     *         //...
     *         ]
     */
    public function setCurlOption(array $values)
    {
        foreach ($values as $key => $value) {
            $this->curlOptions[$key] = $value;
        }

        return $this;
    }

    /**
     * @param $option
     * @param $value
     *
     * @return self
     *
     * @link http://php.net/manual/en/function.curl-share-setopt.php
     * @see  curl_share_setopt
     */
    public function setShareOptions($option, $value)
    {
        if (!isset($this->sh)) {
            $this->sh = curl_share_init();
            $this->setCurlOption([CURLOPT_SHARE => $this->sh]);
        }

        curl_share_setopt($this->sh, $option, $value);

        return $this;
    }

    /**
     * Max request in Asynchron query
     *
     * @param $max int default:10
     *
     * @return self
     */
    public function setMaxRequest($max)
    {
        $this->maxRequest = $max;
        // PHP 5 >= 5.5.0
        if (function_exists('curl_multi_setopt')) {
            curl_multi_setopt($this->mh, CURLMOPT_MAXCONNECTS, $max);
        }

        return $this;
    }

    /**
     * @param int   $next
     * @param float $second
     * @param bool  $blocking
     *
     * @return self
     */
    public function setSleep($next, $second = 1.0, $blocking = true)
    {
        $this->sleep = $second;
        $this->sleepNext = $next;
        $this->sleepBlocking = $blocking;

        return $this;
    }

    /**
     * Return count query
     * @return int
     */
    public function getCountQuery()
    {
        return $this->queriesCount;
    }

    /**
     * Exec cURL resource
     * @return bool
     */
    public function run()
    {
        if ($this->isRunMh) {
            $this->exec();
            $this->execRead();

            return ($this->processedQuery() || $this->queriesQueueCount > 0) ? true : ($this->isRunMh = false);
        }

        return $this->processedQuery();
    }


    /**
     * Return all results; wait all request
     * @return Result[]|null[]
     */
    public function all()
    {
        while ($this->run()) {
            // do nothing...
        }
        $results = $this->results;
        $this->results = [];

        return $results;
    }

    /**
     * Return one next result, wait first exec request
     * @return Result|null
     */
    public function next()
    {
        while (empty($this->results) && $this->run()) {
            // do nothing...
        }

        return array_pop($this->results);
    }

    /**
     * Check has one result
     * @return bool
     */
    public function has()
    {
        return !empty($this->results);
    }


    /**
     * Clear result request
     * @return self
     */
    public function clear()
    {
        $this->results = [];

        return $this;
    }

    /**
     * Set class result
     *
     * @param string $name
     *
     * @return self
     */
    public function setClassResult(string $name)
    {
        $this->classResult = $name;

        return $this;
    }

    /**
     * @param bool $select
     *
     * @return self
     */
    public function setIsSelect($select)
    {
        $this->isSelect = $select;

        return $this;
    }

    /**
     * @return bool
     */
    public function getIsSelect()
    {
        return $this->isSelect;
    }

    /**
     * @param $id
     *
     * @return bool
     */
    protected function processedResponse($id)
    {
        --$this->queriesQueueCount;
        ++$this->count;
        $query = $this->queriesQueue[$id];

        $result = new $this->classResult($query);
        if (isset($query['id'])) {
            $this->results[$query['id']] = $result;
        } else {
            $this->results[] = $result;
        }

        curl_multi_remove_handle($this->mh, $query['ch']);
        unset($this->queriesQueue[$id]);

        return true;
    }

    /**
     * @return bool
     */
    protected function processedQuery()
    {
        // No queries
        if ($this->queriesCount == 0) {
            return false;
        }

        $count = $this->maxRequest - $this->queriesQueueCount;

        if ($this->sleep !== 0) {
            $modulo_begin = $this->count % $this->sleepNext;
            $modulo_end = ($this->count + $count) % $this->sleepNext;

            $current_time = microtime(true);
            if (!isset($this->sleepNextTime)) {
                $this->sleepNextTime = $current_time - $this->sleep;
            }
            $sleep_time = (int)(($this->sleep - ($current_time - $this->sleepNextTime)) * 1000000);

            if ($sleep_time > 0) {
                if ($modulo_begin === 0) {
                    if ($this->sleepBlocking) {
                        usleep($sleep_time);
                        $sleep_time = 0;
                        $current_time = microtime(true);
                    } else {
                        $count = 0;
                    }
                } elseif ($modulo_begin >= $modulo_end) {
                    $count -= $modulo_end;
                }
            }

            if ($sleep_time <= 0 && ($modulo_begin === 0 || $modulo_begin >= $modulo_end)) {
                $this->sleepNextTime = $current_time;
            }
        }

        if ($count > 0) {
            $limit = $this->queriesCount < $count ? $this->queriesCount : $count;

            $this->queriesCount -= $limit;
            $this->queriesQueueCount += $limit;
            while ($limit--) {
                $key = key($this->queries);
                $query = $this->queries[$key];
                unset($this->queries[$key]);

                $query['ch'] = curl_init();
                curl_setopt_array($query['ch'], $query['opts'] + $this->curlOptions);

                curl_multi_add_handle($this->mh, $query['ch']);
                $id = $this->getResourceId($query['ch']);
                $this->queriesQueue[$id] = $query;
            }
        }

        return $this->isRunMh = true;
    }

    protected function exec()
    {
        do {
            $mrc = curl_multi_exec($this->mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM || ($this->isSelect && curl_multi_select($this->mh, 0.01) > 0));
    }

    protected function execRead()
    {
        while (($info = curl_multi_info_read($this->mh, $active)) !== false) {
            if ($info['msg'] === CURLMSG_DONE) {
                $id = $this->getResourceId($info['handle']);
                $this->processedResponse($id);
            }
        }
    }

    /**
     * @param $resource
     *
     * @return int
     */
    protected function getResourceId($resource)
    {
        return intval($resource);
    }

    public function __destruct()
    {
        if (isset($this->sh)) {
            curl_share_close($this->sh);
        }
        curl_multi_close($this->mh);
    }

    /**
     * @param string $baseUrl
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }
}
