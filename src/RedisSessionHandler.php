<?php

namespace UMA;

/**
 * @author Marcel Hernandez
 */
class RedisSessionHandler extends \SessionHandler implements \SessionUpdateTimestampHandlerInterface
{
    /**
     * Wait time (1ms) after first locking attempt. It doubles
     * for every unsuccessful retry until it either reaches
     * MAX_WAIT_TIME or succeeds.
     */
    const MIN_WAIT_TIME = 1000;

    /**
     * Maximum wait time (128ms) between locking attempts.
     */
    const MAX_WAIT_TIME = 128000;

    /**
     * The Redis client.
     *
     * @var \Redis
     */
    private $redis;

    /**
     * The maximum number of seconds that any given
     * session can remain locked. This is only meant
     * as a last resort releasing mechanism if for an
     * unknown reason the PHP engine never
     * calls RedisSessionHandler::close().
     *
     * $lock_ttl is set to the 'max_execution_time'
     * runtime configuration value.
     *
     * @var int
     */
    private $lock_ttl;

    /**
     * The maximum number of seconds that a session
     * will be kept in Redis before it is considered stale
     * and expires.
     *
     * $session_ttl is set to the 'session.gc_maxlifetime'
     * runtime configuration value.
     *
     * @var int
     */
    private $session_ttl;

    /**
     * A collection of every session ID that has been generated
     * in the current thread of execution.
     *
     * This allows the handler to discern whether a given session ID
     * came from the HTTP request or was generated by the PHP engine
     * during the current thread of execution.
     *
     * @var string[]
     */
    private $new_sessions = [];

    /**
     * A collection of every session ID that is being locked by
     * the current thread of execution. When session_write_close()
     * is called the locks on all these IDs are removed.
     *
     * @var string[]
     */
    private $open_sessions = [];

    /**
     * The name of the session cookie.
     *
     * @var string
     */
    private $cookieName;

    /**
     * @throws \RuntimeException When the phpredis extension is not available.
     */
    public function __construct()
    {
        if (false === extension_loaded('redis')) {
            throw new \RuntimeException("the 'redis' extension is needed in order to use this session handler");
        }

        if (PHP_VERSION_ID >= 70000 && !ini_get('session.use_strict_mode')) {
            ini_set('session.use_strict_mode', true);
        }

        $this->redis = new \Redis();
        $this->lock_ttl = (int) ini_get('max_execution_time');
        $this->session_ttl = (int) ini_get('session.gc_maxlifetime');
    }

    /**
     * {@inheritdoc}
     */
    public function open($save_path, $name):bool
    {
        $this->cookieName = $name;

        list(
            $host, $port, $timeout, $prefix, $auth, $database
        ) = SavePathParser::parse($save_path);

        // When $host is a Unix socket path redis->connect() will fail if
        // supplied with any other of the optional parameters, even if they
        // are the default values.
        if (file_exists($host)) {
            if (false === $this->redis->connect($host)) {
                return false;
            }
        } else {
            if (false === $this->redis->connect($host, $port, $timeout)) {
                return false;
            }
        }

        if (SavePathParser::DEFAULT_AUTH !== $auth) {
            $this->redis->auth($auth);
        }

        if (SavePathParser::DEFAULT_DATABASE !== $database) {
            $this->redis->select($database);
        }

        $this->redis->setOption(\Redis::OPT_PREFIX, $prefix);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function create_sid():string
    {
        $id = parent::create_sid();

        $this->new_sessions[$id] = true;

        return $id;
    }

    private function regen():string
    {
        session_id($session_id = $this->create_sid());
        $params = session_get_cookie_params();
        setcookie(
            $this->cookieName,
            $session_id,
            $params['lifetime'] ? time() + $params['lifetime'] : 0,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
        return $session_id;
    }

    public function validateId($sessionId):bool
    {
        return !$this->mustRegenerate($sessionId);
    }

    public function updateTimestamp($sessionId, $sessionData):bool
    {
        // return parent::updateTimestamp($sessionId, $sessionData);
        return $this->redis->expire($sessionId, $this->session_ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function read($session_id):string|false
    {
        if (PHP_VERSION_ID < 70000 && $this->mustRegenerate($session_id)) {
            $session_id = $this->regen();
        }

        $this->acquireLockOn($session_id);

        if ($this->isNew($session_id)) {
            return '';
        }

        return (string)$this->redis->get($session_id);
    }

    /**
     * {@inheritdoc}
     */
    public function write($session_id, $session_data):bool
    {
        return true === $this->redis->setex($session_id, $this->session_ttl, $session_data);
    }

    /**
     * {@inheritdoc}
     */
    public function destroy($session_id):bool
    {
        $this->redis->del($session_id);
        $this->redis->del("{$session_id}_lock");

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close():bool
    {
        $this->releaseLocks();

        $this->redis->close();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc($maxlifetime, $all_sessions = false):bool|int
    {
        // Redis does not need garbage collection, the builtin
        // expiration mechanism already takes care of stale sessions
        return true;
    }

    /**
     * @param string $session_id
     */
    private function acquireLockOn($session_id)
    {
        $options = ['nx'];
        if (0 < $this->lock_ttl) {
            $options = ['nx', 'ex' => $this->lock_ttl];
        }

        $wait = self::MIN_WAIT_TIME;
        while (false === $this->redis->set("{$session_id}_lock", '', $options)) {
            usleep($wait);

            if (self::MAX_WAIT_TIME > $wait) {
                $wait *= 2;
            }
        }

        $this->open_sessions[] = $session_id;
    }

    private function releaseLocks()
    {
        foreach ($this->open_sessions as $session_id) {
            $this->redis->del("{$session_id}_lock");
        }

        $this->open_sessions = [];
    }

    /**
     * A session ID must be regenerated when it came from the HTTP
     * request and can not be found in Redis.
     *
     * When that happens it either means that old session data expired in Redis
     * before the cookie with the session ID in the browser, or a malicious
     * client is trying to pull off a session fixation attack.
     *
     * @param string $session_id
     *
     * @return bool
     */
    private function mustRegenerate($session_id)
    {
        return false === $this->isNew($session_id)
            && false === (bool) $this->redis->exists($session_id);
    }

    /**
     * @param string $session_id
     *
     * @return bool
     */
    private function isNew($session_id):bool
    {
        return isset($this->new_sessions[$session_id]);
    }
}
