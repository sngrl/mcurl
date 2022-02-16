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
     * Count of the requests which are not executed yet - waiting for going to active queue
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
     * Max asynchronous requests
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
    protected $sleepSeconds = 0;

    /**
     * Count of the queries which was processed in one cycle
     * @var int
     */
    protected $sleepNext;

    /**
     * @var boolean
     * @deprecated
     */
    protected $sleepBlocking;

    /**
     * Total executed requests count
     * @var int
     */
    protected $totalExecutedRequests = 0;

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

    /**
     * @var bool
     */
    protected $enableHeaders = false;

    /**
     * @var array
     * @see http://php.net/manual/ru/stream.filters.php
     */
    protected $streamFilters = [];

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var null|float
     */
    protected $startWorkTime = null;

    /**
     * @var null|integer
     */
    protected $queriesLimitPerSleepCycle = null;

    /**
     * @var int
     */
    protected $processedQueriesCountPerSleepCycle = 0;

    /**
     * @var null|float
     */
    protected $lastSleepTime = null;

    /**
     * @var bool
     */
    private $afterRequestTimeoutEnabled = false;

    /**
     * @var float
     */
    private $afterRequestTimeoutCoefficient = 0.25;

    /**
     * @var float|int
     */
    private $afterRequestTimeout = null;

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
     * @return array
     */
    public function getCurlOptions()
    {
        return $this->curlOptions;
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
     * @return int
     */
    public function getMaxRequest()
    {
        return $this->maxRequest;
    }

    /**
     * @param int   $next
     * @param float $seconds
     * @param bool  $blocking
     *
     * @return self
     */
    public function setSleep($next, $seconds = 1.0, $blocking = true)
    {
        $this->sleepNext = $next;
        $this->sleepSeconds = $seconds;
        $this->sleepBlocking = $blocking;

        return $this;
    }

    /**
     * @return int
     */
    public function getQueriesCount()
    {
        return $this->queriesCount;
    }

    /**
     * Leave for backward compatibility
     * @return int
     */
    public function getCountQuery()
    {
        return $this->getQueriesCount();
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

        $this->startWorkTime = microtime(true);

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
        ++$this->totalExecutedRequests;
        $query = $this->queriesQueue[$id];

        $result = new $this->classResult($query);
        if (isset($query['id'])) {
            $this->results[$query['id']] = $result;
        } else {
            $this->results[] = $result;
        }

        curl_multi_remove_handle($this->mh, $query['ch']);
        unset($this->queriesQueue[$id]);

        /**
         * Current cycle calculations & actions
         */
        ++$this->processedQueriesCountPerSleepCycle;
        if ($this->afterRequestTimeoutEnabled) {
            if ($this->afterRequestTimeout > 0) {
                usleep($this->afterRequestTimeout);
            }
        }

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

        /**
         * Initial values
         */
        if (!$this->lastSleepTime) {
            //$this->queriesLimitPerSleepCycle = (int)($this->maxRequest * $this->sleepNext);
            $this->queriesLimitPerSleepCycle = $this->sleepNext;
            $this->lastSleepTime = microtime(true);
        }

        $queueFreeSlotsCount = $this->maxRequest - $this->queriesQueueCount;

        if ($this->sleepSeconds !== 0 && $this->isRunMh) {
            /**
             * Reached limit of the queries at one cycle (for example: only 5 queries per 1 second)
             */
            if ($this->processedQueriesCountPerSleepCycle >= $this->queriesLimitPerSleepCycle) {
                $current_time = microtime(true);
                /**
                 * Calculate how much time has passed since last cycle limit reaching
                 */
                $currentCycleTimeRemains = $this->sleepSeconds - ($current_time - $this->lastSleepTime);

                /**
                 * This value is show how many time left in the current cycle
                 * If this value is greater than 0 it means that we need to wait this time, until the current cycle ends
                 */
                if ($currentCycleTimeRemains > 0) {
                    $currentCycleTimeRemainsMs = $currentCycleTimeRemains * 1000000;
                    //this_info('SLEEP ' . round($currentCycleTimeRemains, 4) . ' sec(s)... Cycle queries limit: ' . $this->queriesLimitPerSleepCycle . ' per ' . $this->sleepSeconds . ' sec(s)');

                    /**
                     * Set/update timeout correction after each request
                     * If our requests finished so quick that there is an excess of time, we can do small timeout after each request
                     * to evenly distribute all single cycle requests over the time of the current cycle.
                     * We can correct this value in future
                     */
                    if ($this->afterRequestTimeoutEnabled) {
                        $this->afterRequestTimeout = $currentCycleTimeRemainsMs / $this->queriesLimitPerSleepCycle * $this->afterRequestTimeoutCoefficient;
                        //this_info('Correction = ' . $this->afterRequestTimeout);
                    }

                    usleep($currentCycleTimeRemainsMs);
                } else {
                    /**
                     * If this value is less then 0 it means thar reaching the queries limit took more time than one cycle lasts
                     * It mean that some time waiting is not required here, but we need to drop cycle timer below at the code
                     * It seems that there is no way to get into here
                     */
                    // do nothing...
                }

                /**
                 * Start new cycle timer & drop single cycle processed queries counter
                 */
                $this->lastSleepTime = microtime(true);
                /*
                d([
                    '__event__'                                 => '$this->processedQueriesCountPerSleepCycle >= $this->queriesLimitPerSleepCycle',
                    '$this->processedQueriesCountPerSleepCycle' => $this->processedQueriesCountPerSleepCycle,
                    '$this->queriesLimitPerSleepCycle'          => $this->queriesLimitPerSleepCycle,
                    '$currentCycleTimeRemains'                  => $currentCycleTimeRemains,
                    '$this->afterRequestTimeoutCorrection'      => $this->afterRequestTimeout,
                ]);
                //*/
                $this->processedQueriesCountPerSleepCycle = 0;
            } elseif ($this->lastSleepTime + $this->sleepSeconds <= microtime(true)) {
                /**
                 * Current cycle was ended early than single cycle queries limit was reached
                 * Start new cycle timer, drop single cycle processed queries counter,
                 * make correction of the timeout correction after each request
                 */
                //this_info('DROP CYCLE TIMER!! Processed queries at the current cycle = ' . $this->processedQueriesCountPerSleepCycle . ' of ' . $this->queriesLimitPerSleepCycle);
                if ($this->afterRequestTimeoutEnabled) {
                    $this->afterRequestTimeout = $this->processedQueriesCountPerSleepCycle * $this->afterRequestTimeout / $this->queriesLimitPerSleepCycle * $this->afterRequestTimeoutCoefficient;
                }
                /*
                d([
                    '$this->processedQueriesCountPerSleepCycle' => $this->processedQueriesCountPerSleepCycle,
                    '$this->queriesLimitPerSleepCycle'          => $this->queriesLimitPerSleepCycle,
                    '$this->afterRequestTimeoutCorrection'      => $this->afterRequestTimeout,
                ]);
                //*/
                $this->lastSleepTime = microtime(true);
                $this->processedQueriesCountPerSleepCycle = 0;
            }
        }

        /**
         * If free slots are available at queue...
         */
        if ($queueFreeSlotsCount > 0) {
            $limit = $this->queriesCount < $queueFreeSlotsCount ? $this->queriesCount : $queueFreeSlotsCount;

            /**
             * Fill queue for limit was reached
             */
            $this->queriesCount -= $limit;
            $this->queriesQueueCount += $limit;
            do {
                /**
                 * Get first pre-queue query from the stack
                 */
                $key = key($this->queries);
                $query = $this->queries[$key];
                unset($this->queries[$key]);

                /**
                 * Create curl handler & add query to queue
                 */
                $query['ch'] = curl_init();
                curl_setopt_array($query['ch'], $query['opts'] + $this->curlOptions);
                curl_multi_add_handle($this->mh, $query['ch']);
                $id = $this->getResourceId($query['ch']);
                $this->queriesQueue[$id] = $query;
            } while (--$limit);
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
     *
     * @return self
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    /**
     * @return int
     */
    public function getTotalExecutedRequests()
    {
        return $this->totalExecutedRequests;
    }

    /**
     * @return int
     */
    public function getQueriesQueueCount()
    {
        return $this->queriesQueueCount;
    }

    /**
     * Get current rate of the requests per second from the start of the work
     *
     * @param int $precision
     *
     * @return float
     */
    public function getRequestsPerSeconds($precision = 2)
    {
        return round($this->getTotalExecutedRequests() / $this->getCurrentWorkTime(), $precision);
    }

    /**
     * @return bool
     */
    public function isAfterRequestTimeoutEnabled()
    {
        return $this->afterRequestTimeoutEnabled;
    }

    /**
     * @param null|float|integer $afterRequestTimeoutCoefficient
     *
     * @return self
     */
    public function enableAfterRequestTimeout($afterRequestTimeoutCoefficient = null)
    {
        $this->afterRequestTimeoutEnabled = true;

        if (is_float($afterRequestTimeoutCoefficient) || is_integer($afterRequestTimeoutCoefficient)) {
            $this->afterRequestTimeoutCoefficient = $afterRequestTimeoutCoefficient;
        }

        return $this;
    }

    /**
     * @return self
     */
    public function disableAfterRequestTimeout()
    {
        $this->afterRequestTimeoutEnabled = false;

        return $this;
    }

    /**
     * @return float
     */
    public function getAfterRequestTimeoutCoefficient()
    {
        return $this->afterRequestTimeoutCoefficient;
    }

    /**
     * @param float $afterRequestTimeoutCoefficient
     *
     * @return self
     */
    public function setAfterRequestTimeoutCoefficient(float $afterRequestTimeoutCoefficient)
    {
        $this->afterRequestTimeoutCoefficient = $afterRequestTimeoutCoefficient;

        return $this;
    }

    /**
     * @return array
     */
    public function getQueries()
    {
        return $this->queries;
    }

    /**
     * @return array
     */
    public function getQueriesQueue()
    {
        return $this->queriesQueue;
    }

    /**
     * @return float|null
     */
    public function getStartWorkTime()
    {
        return $this->startWorkTime;
    }

    /**
     * @return float|null
     */
    public function getCurrentWorkTime()
    {
        return $this->startWorkTime ? microtime(true) - $this->startWorkTime : null;
    }
}
