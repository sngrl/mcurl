<?php

namespace MCurl;

use Exception;

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
     * Stream for CURLOPT_WRITEHEADER; by default: self::STREAM_MEMORY
     * @var string
     */
    protected $defaultStream;

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
     * @var float
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

    /**
     * @var bool
     */
    private $debug = false;

    /**
     * Float value for second argument on calling curl_multi_select() function
     * At some work cases with high values of the threads probably must be decreased
     * @see https://www.php.net/manual/ru/function.curl-multi-select.php
     * @var float
     */
    private $curlMultiSelectTimeout = 0.01;

    public function __construct()
    {
        $this->mh = curl_multi_init();
        $this->setDefaultStream(self::STREAM_MEMORY);
    }

    /**
     * This parallel request if maxRequest > 1
     * $client->get('http://example.com/1.php') => return Result
     * Or $client->get('http://example.com/1.php', 'http://example.com/2.php') => return Result[]
     * Or $client->get(['http://example.com/1.php', 'http://example.com/2.php'], [CURLOPT_MAX_RECV_SPEED_LARGE => 1024]) => return Result[]
     *
     * @param string|array $url
     * @param array        $opts @see http://www.php.net/manual/ru/function.curl-setopt.php
     * @param array        $params
     *
     * @return Result|Result[]|null
     * @throws Exception
     */
    public function get($url, $opts = [], $params = [])
    {
        $urls = (array)$url;

        foreach ($urls as $id => $u) {
            $opts[CURLOPT_URL] = $u;
            $this->add($opts, $params, $id);
        }

        return is_array($url) ? $this->all() : $this->next();
    }

    /**
     * @param string|array $url
     * @param array        $data post data
     * @param array        $opts
     * @param array        $params
     *
     * @return Result|Result[]|null
     * @throws Exception
     * @see $this->get
     */
    public function post($url, $data = [], $opts = [], $params = [])
    {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $data;

        return $this->get($url, $opts, $params);
    }

    /**
     * Add request
     *
     * @param array                     $opts   Options curl. Example: [CURLOPT_URL => 'http://example.com'];
     * @param array|string              $params All data, require binding to the request or if string: identity request
     * @param null|string|integer|float $id
     *
     * @return bool
     */
    public function add($opts = [], $params = [], $id = null)
    {
        //if (is_string($params)) {
        //    $id = $params;
        //    $params = [];
        //}

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
            /**
             * v1: create file handler right now
             * The problem here is that if we use multi-threading with setSleep()
             * opened "file handlers" (in php://) may be closed/erased
             */
            //$opts[CURLOPT_WRITEHEADER] = fopen($this->getDefaultStream(), 'r+');
            //if (!$opts[CURLOPT_WRITEHEADER]) {
            //    return false;
            //}
            /**
             * v2: just save string path instead of creating file handler;
             * We will create him directly on the adding to $this->queriesQueue
             * @see \MCurl\Client::processedQuery
             */
            //
            $opts[CURLOPT_WRITEHEADER] = $this->getDefaultStream();
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
     * Backward compatibility
     * @return int
     */
    public function getCountQuery()
    {
        return $this->getQueriesCount();
    }

    /**
     * Exec cURL resource
     * @return bool
     * @throws Exception
     */
    public function run()
    {
        if ($this->isRunMh) {
            $this->exec();
            $this->infoRead();

            return ($this->processedQuery() || $this->queriesQueueCount > 0) ? true : ($this->isRunMh = false);
        }

        if (!$this->startWorkTime) {
            $this->startWorkTime = microtime(true);
        }

        $this->lastSleepTime = null;

        return $this->processedQuery();
    }

    /**
     * Return all results; wait all request
     * @return Result[]|null[]
     * @throws Exception
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
     * @throws Exception
     */
    public function next()
    {
        while (empty($this->results) && $this->run()) {
            // do nothing...
        }

        if ($this->isDebug()) {
            $this->this_info('->next() while cycle was ended, threads: ' . $this->maxRequest . ', count($this->results) = ' . count($this->results));
        }

        return array_pop($this->results);
    }

    /**
     * Run all queries async & wait until queue is not empty. Must return false when stack is empty
     *
     * // Usage example:
     * while ($this->client->allAsync(function (\MCurl\Result $result) {
     *     var_dump($result);
     * )) {
     *     // do nothing, just wait until all queries are finished...
     * };
     *
     * @param callable|null $callback     Callback which will called for each curl response. \MCurl\Result object will passed as argument
     *                                    If null passed - all queries will be just runned by curl without any postprocessing
     * @param int|null      $return_limit Number of the items which will return in one chunk, if you code need to process them.
     *                                    If null passed -
     *
     * @return bool
     * @throws Exception
     */
    public function allAsync($callback = null, $return_limit = null)
    {
        if ($return_limit) {
            while (null != ($results = $this->runAsync($callback, $return_limit))) {
                return $results;
            }
        } else {
            while ($results = $this->runAsync($callback)) {
                // do nothing...
            }
        }

        return false;
    }

    /**
     * Run query stack async. Must return false when stack is empty
     *
     * @param callable|null $callback
     * @param int|null      $return_limit
     *
     * @return array|bool|void
     * @throws Exception
     * @see \MCurl\Client::allAsync
     */
    public function runAsync($callback = null, $return_limit = null)
    {
        if (!$this->isRunMh) {
            if (!$this->startWorkTime) {
                $this->startWorkTime = microtime(true);
            }

            $this->lastSleepTime = null;
            $this->processedQuery();
        }

        if (!$this->getQueriesQueueCount()) {
            $this->isRunMh = false;

            return false;
        }

        if ($return_limit) {
            return $this->execAsync($callback, $return_limit);
        } else {
            $this->execAsync($callback);
            $this->isRunMh = false;

            return false;
        }
    }

    /**
     * @param callable|null $callback
     * @param int|null      $return_limit
     *
     * @return array|void
     * @throws Exception
     */
    protected function execAsync($callback = null, $return_limit = null)
    {
        $lastActive = 0;

        /**
         * Process queries while queue is not empty
         */
        do {
            curl_multi_exec($this->mh, $active);

            if ($lastActive - $active > 0) {
                //if ($this->isDebug()) {
                //    $this->this_info('Call Client::infoRead(), possible allowed results: ' . ($lastActive - $active));
                //}

                /**
                 * Get responses for finished requests
                 */
                $this->infoRead();

                /**
                 * Process responses by callback
                 * @var Result $result
                 */
                if (is_callable($callback)) {
                    //while (!empty($this->results)) {
                    //    $result = array_pop($this->results);
                    //    $callback($result);
                    //}
                    foreach ($this->results as $r => $result) {
                        if (@!$result->callback_processed) {
                            call_user_func($callback, $result);
                        }
                        if ($return_limit) {
                            $result->callback_processed = true;
                            $this->results[$r] = $result;
                        } else {
                            /**
                             * Do not store results if return limit not passed
                             */
                            unset($this->results[$r]);
                        }
                    }
                }

                /**
                 * If return limit passed...
                 */
                if ($return_limit && count($this->results) >= $return_limit) {
                    /**
                     * Return results & clear results array
                     */
                    $results = $this->results;
                    $this->results = [];

                    return $results;
                }

                /**
                 * Fill queue from pre-queue queries stack
                 */
                $this->processedQuery();
            }

            $lastActive = $active;
        } while ($active > 0 || ($this->isSelect && curl_multi_select($this->mh, $this->curlMultiSelectTimeout) > 0));

        /**
         * Return results if return limit passed
         */
        if ($return_limit && count($this->results)) {
            $results = $this->results;
            $this->results = [];

            return $results;
        }
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
        if (isset($query['id']) && $query['id'] !== null) {
            $this->results[$query['id']] = $result;
        } else {
            $this->results[] = $result;
        }

        curl_multi_remove_handle($this->mh, $query['ch']);
        unset($this->queriesQueue[$id]);

        /**
         * Limiting the number of requests per unit of time, if this feature is enabled
         */
        $this->doSleep();

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
     * @throws Exception
     */
    protected function processedQuery()
    {
        /**
         * No queries
         */
        if ($this->queriesCount == 0) {
            $this->isRunMh = false;
            return $this->isRunMh;
        }

        /**
         * If free slots are available at queue...
         */
        $queueFreeSlotsCount = $this->maxRequest - $this->queriesQueueCount;
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
                $t = 0;
                do {
                    ++$t;
                    $query['ch'] = curl_init();
                } while (!is_resource($query['ch']) || $t < 3);
                if (!is_resource($query['ch'])) {
                    throw new Exception('Can not create valid File-Handle resource via curl_init()', 0);
                }
                $opts = $this->curlOptions + $query['opts'];
                /**
                 * Create file handler right now if string path provided instead of resource
                 * @see \MCurl\Client::add
                 */
                if (isset($opts[CURLOPT_WRITEHEADER]) && is_string($opts[CURLOPT_WRITEHEADER])) {
                    $opts[CURLOPT_WRITEHEADER] = fopen($opts[CURLOPT_WRITEHEADER], 'r+');
                }
                $query['opts'] = $opts;
                try {
                    curl_setopt_array($query['ch'], $query['opts']);
                } catch (Exception $e) {
                    $this->d($query);
                    throw $e;
                }
                curl_multi_add_handle($this->mh, $query['ch']);
                $id = $this->getResourceId($query['ch']);
                $this->queriesQueue[$id] = $query;
            } while (--$limit);
        }

        if ($this->queriesQueueCount) {
            $this->isRunMh = true;
            return $this->isRunMh;
        }

        $this->isRunMh = false;
        return $this->isRunMh;
    }

    protected function doSleep()
    {
        /**
         * Initial values
         */
        if ($this->sleepSeconds !== 0) {
            if (!$this->lastSleepTime) {
                //$this->queriesLimitPerSleepCycle = (int)($this->maxRequest * $this->sleepNext);
                $this->queriesLimitPerSleepCycle = $this->sleepNext;
                $this->lastSleepTime = microtime(true);
            }
        }
        if ($this->sleepSeconds !== 0 /*&& $this->isRunMh*/) {
            /**
             * Reached limit of the queries at one cycle (for example: only 5 queries per 1 second)
             */
            if ($this->processedQueriesCountPerSleepCycle >= $this->queriesLimitPerSleepCycle) {
                $current_time = microtime(true);

                /**
                 * Calculate how much time has passed since last cycle limit reaching
                 */
                $currentCycleTimeRemains = $this->sleepSeconds - ($current_time - $this->lastSleepTime);

                if ($this->isDebug()) {
                    $this->this_info('CURRENT CYCLE QUERIES LIMIT REACHED!! $currentCycleTimeRemains = ' . $currentCycleTimeRemains);
                }

                /**
                 * This value is show how many time left in the current cycle
                 * If this value is greater than 0 it means that we need to wait this time, until the current cycle ends
                 */
                if ($currentCycleTimeRemains > 0) {
                    $currentCycleTimeRemainsMs = $currentCycleTimeRemains * 1000000;

                    if ($this->isDebug()) {
                        $this->this_info('SLEEP ' . round($currentCycleTimeRemains, 4) . ' sec(s)... Cycle queries limit: ' . $this->queriesLimitPerSleepCycle . ' per ' . $this->sleepSeconds . ' sec(s)');
                    }

                    /**
                     * Set/update timeout correction after each request
                     *
                     * If our requests finished so quick that there is an excess of time, we can do small timeout after each request
                     * to evenly distribute all single cycle requests over the time of the current cycle.
                     * Get time remains of the current cycle, divide on requests limit per cycle & multiply on coefficient (0.0 - 1.0)
                     */
                    if ($this->afterRequestTimeoutEnabled) {
                        $this->afterRequestTimeout = $currentCycleTimeRemainsMs / $this->queriesLimitPerSleepCycle * $this->afterRequestTimeoutCoefficient;
                        if ($this->isDebug()) {
                            $this->this_info('Correction = ' . $this->afterRequestTimeout);
                        }
                    }

                    usleep($currentCycleTimeRemainsMs);

                    /**
                     * Start new cycle timer - simple current time
                     */
                    $this->lastSleepTime = microtime(true);
                } else {
                    /**
                     * If this value is less then 0 it means thar reaching the queries limit took more time than one cycle lasts
                     * It mean that some time waiting is not required here, but we need to drop cycle timer below at the code
                     * It seems that there is no way to get into here, BUT it can happen!
                     */

                    /**
                     * Start new cycle timer - current time "plus" a negative value of the current cycle time remains
                     */
                    //$this->lastSleepTime = microtime(true) + $currentCycleTimeRemains;
                    $this->lastSleepTime = microtime(true);
                }

                if ($this->isDebug()) {
                    $this->d([
                        '__event__'                                 => '$this->processedQueriesCountPerSleepCycle >= $this->queriesLimitPerSleepCycle',
                        '$this->processedQueriesCountPerSleepCycle' => $this->processedQueriesCountPerSleepCycle,
                        '$this->queriesLimitPerSleepCycle'          => $this->queriesLimitPerSleepCycle,
                        '$currentCycleTimeRemains'                  => $currentCycleTimeRemains,
                        '$this->afterRequestTimeoutCorrection'      => $this->afterRequestTimeout,
                    ]);
                }

                /**
                 * Drop single cycle processed queries counter
                 */
                $this->processedQueriesCountPerSleepCycle = 0;
            } elseif ($this->lastSleepTime + $this->sleepSeconds <= microtime(true)) {
                /**
                 * Current cycle was ended early than single cycle queries limit was reached
                 */
                if ($this->isDebug()) {
                    $this->this_info('DROP CYCLE TIMER!! Processed queries at the current cycle: ' . $this->processedQueriesCountPerSleepCycle . ' of ' . $this->queriesLimitPerSleepCycle);
                }

                /**
                 * Set/update timeout correction after each request
                 *
                 * First: get count requests which have been completed at the current cycle,
                 * multiple on current value of the afterRequestTimeout - now we know how many time script was sleeping.
                 * Second: divide this value on the current limit of the requests per one cycle - now we know
                 * how many time script must be sleeping (reduced value compared to the previous one).
                 * Third: multiple this value on the coefficient (0.0 - 1.0)
                 */
                if ($this->afterRequestTimeoutEnabled) {
                    $this->afterRequestTimeout = $this->afterRequestTimeout
                        ? $this->processedQueriesCountPerSleepCycle * $this->afterRequestTimeout / $this->queriesLimitPerSleepCycle * $this->afterRequestTimeoutCoefficient
                        : null;

                    if ($this->isDebug()) {
                        $this->this_info('New value for $this->afterRequestTimeoutCorrection: ' . $this->afterRequestTimeout);
                    }
                }

                /**
                 * Start new cycle timer, drop single cycle processed queries counter,
                 */
                $this->lastSleepTime = microtime(true);
                $this->processedQueriesCountPerSleepCycle = 0;
            }
        }
    }

    protected function exec()
    {
        do {
            curl_multi_exec($this->mh, $active);
        } while ($active > 0 || ($this->isSelect && curl_multi_select($this->mh, $this->curlMultiSelectTimeout) > 0));
    }

    protected function infoRead()
    {
        while (($info = curl_multi_info_read($this->mh, $active)) !== false) {
            $this->infoReadProcessResponse($info);
        }
    }

    protected function infoReadProcessResponse($info)
    {
        if ($info['msg'] === CURLMSG_DONE) {
            $id = $this->getResourceId($info['handle']);
            $this->processedResponse($id);
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
        if ($this->getCurrentWorkTime() === null) {
            return null;
        } elseif ($this->getCurrentWorkTime() === 0) {
            return $this->getTotalExecutedRequests();
        } else {
            return round($this->getTotalExecutedRequests() / $this->getCurrentWorkTime(), $precision);
        }
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

    /**
     * @return int
     */
    public function getSleepNext()
    {
        return $this->sleepNext;
    }

    /**
     * @return int
     */
    public function getSleepSeconds()
    {
        return $this->sleepSeconds;
    }

    /**
     * @return string
     */
    public function getDefaultStream(): string
    {
        return $this->defaultStream;
    }

    /**
     * @param string $defaultStream
     *
     * @return self
     */
    public function setDefaultStream(string $defaultStream)
    {
        $this->defaultStream = $defaultStream;

        return $this;
    }

    /**
     * @return bool
     */
    public function isDebug()
    {
        return $this->debug;
    }

    /**
     * @param bool $debug
     *
     * @return self
     */
    public function setDebug(bool $debug)
    {
        $this->debug = $debug;

        return $this;
    }

    /**
     * @return self
     */
    public function enableDebug()
    {
        return $this->setDebug(true);
    }

    /**
     * @return self
     */
    public function disableDebug()
    {
        return $this->setDebug(false);
    }

    /**
     * Just print string/array/object/etc. variable with current date-time
     *
     * @param mixed $line
     */
    protected function this_info($line)
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . (is_string($line) ? $line : print_r($line, true)) . "\n";
    }

    /**
     * Dump passed variables, works pretty well in Laravel
     */
    protected function d()
    {
        foreach (func_get_args() as $v) {
            if (null != ($class_name = '\Symfony\Component\VarDumper\VarDumper') && class_exists($class_name)) {
                $class_name::dump($v);
            } elseif (null != ($class_name = '\Illuminate\Support\Debug\Dumper') && class_exists($class_name)) {
                (new $class_name())->dump($v);
            } else {
                var_dump($v);
            }
        }
    }

    /**
     * @return float
     */
    public function getCurlMultiSelectTimeout(): float
    {
        return $this->curlMultiSelectTimeout;
    }

    /**
     * @param float $curlMultiSelectTimeout
     *
     * @return self
     */
    public function setCurlMultiSelectTimeout(float $curlMultiSelectTimeout)
    {
        $this->curlMultiSelectTimeout = $curlMultiSelectTimeout;

        return $this;
    }
}
