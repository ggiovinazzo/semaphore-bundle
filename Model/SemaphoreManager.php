<?php

namespace Avtonom\SemaphoreBundle\Model;

use Avtonom\SemaphoreBundle\Exception\SemaphoreAcquireException;
use Avtonom\SemaphoreBundle\Exception\SemaphoreReleaseException;

/**
 * Semaphore manager extender
 *
 * @todo How view info about key dead by time
 */
class SemaphoreManager extends SemaphoreManagerBase implements SemaphoreManagerInterface
{
    /**
     * Acquire semaphore and return handle
     *
     * @param mixed $srcKey
     * @param string|null $path
     * @param integer|null $maxLockTime time to leave in seconds
     *
     * @return mixed handle
     *
     * @throws SemaphoreAcquireException
     * @throws \Exception
     */
    public function acquire($srcKey, $path = null, $maxLockTime = null)
    {
        $key = null;
        $result = null;
        try {
            $key = $this->getKey($srcKey);
            if($this->isDemoMode()){
                $this->logDebug(sprintf('Success (ttl %d s.)', $maxLockTime), $path, __FUNCTION__, $srcKey);
                return $key;
            }
            if(array_key_exists($key, $this->handlers)){
                if($this->isExceptionRepeatBlockKey){
                    $this->logError('Retrying to acquire key-lock', $path, __FUNCTION__, $srcKey);
                    $this->acquireException('Retrying to acquire key-lock', $srcKey);
                } else {
                    $this->logger->warning(sprintf('[%d] %s->%s: Retrying to acquire key-lock: %s', getmypid(), $path, __FUNCTION__, $this->getDataToString($srcKey)));
                    $this->adapter->expire($key, $maxLockTime);
                }
            }
            $this->logDebug('Start', $path, __FUNCTION__, $srcKey);
            $result = $this->parentAcquire($srcKey, $path, $maxLockTime);

        } catch(SemaphoreAcquireException $e){
            $this->acquireException($e->getMessage(), $srcKey);

        } catch(\ErrorException $e){
            $this->logException($e, $path, __FUNCTION__, $srcKey);
            $this->acquireException('Key lock time expired', $srcKey);

        } catch(\Exception $e){
            $this->logException($e, $path, __FUNCTION__, $srcKey);
            throw $e;
        }
        $this->logDebug(sprintf('Success (ttl %d s.)', $maxLockTime), $path, __FUNCTION__, $srcKey);
        return $result;
    }

    /**
     * @param mixed $srcKey
     * @param string $path
     * @param integer|null $maxLockTime
     *
     * @return string
     *
     * @throws \ErrorException
     */
    protected function parentAcquire($srcKey, $path = null, $maxLockTime = null)
    {
        $key = $this->getKey($srcKey);
        $tryCount = $this->tryCount;
        $maxLockTime = (is_numeric($maxLockTime) && $maxLockTime) ? $maxLockTime : $this->maxLockTime;
        $ok      = null;

        while ($tryCount > 0 && !$ok = $this->acquireFactory($key, $maxLockTime)) {
            $tryCount--;
            usleep($this->sleepTime);
        }

        $keyValue = sprintf('%s %s', $path, $this->getDataToString($srcKey));
        if (!$ok) {
            throw new \ErrorException(sprintf('Can\'t acquire lock for %s', $key));

        } elseif(array_key_exists($key, $this->handlers)){
            if(is_array($this->handlers[$key])){
                array_push($this->handlers[$key], $keyValue);
            } else {
                $this->handlers[$key] = $keyValue;
            }

        } else {
            $this->handlers[$key] = $keyValue;
        }

        return $key;
    }

    /**
     * Release semaphore
     *
     * @param mixed $srcKey
     * @param string|null $path
     *
     * @return void
     *
     * @throws SemaphoreReleaseException
     * @throws \Exception
     */
    public function release($srcKey, $path = null)
    {
        try {
            $this->logDebug('Start', $path, __FUNCTION__, $srcKey);
            if($this->isDemoMode()){
                $this->logDebug('Success', $path, __FUNCTION__, $srcKey);
                return;
            }
            $res = $this->parentRelease($srcKey);
            if($res === false){
                $this->logError('Trying to release a non existent key', $path, __FUNCTION__, $srcKey);
                $this->releaseException('Trying to release a non existent key', $srcKey);
            }
        } catch(\LogicException $e) {
            $this->logException($e, $path, __FUNCTION__, $srcKey);
            $this->releaseException('Retrying to release key-lock', $srcKey);

        } catch(\UnexpectedValueException $e) {
            $this->logException($e, $path, __FUNCTION__, $srcKey);
            $this->releaseException('Trying to release a non existent key', $srcKey);

        } catch(\Exception $e){
            $this->logException($e, $path, __FUNCTION__, $srcKey);
            throw $e;
        }
        $this->logDebug('Success', $path, __FUNCTION__, $srcKey);
    }

    /**
     * @param mixed $srcKey
     *
     * @return bool
     *
     * @throws \LogicException
     */
    protected function parentRelease($srcKey)
    {
        $key = $this->getKey($srcKey);
        if (!array_key_exists($key, $this->handlers)) {
            throw new \LogicException(sprintf('Call ::acquire(\'%s\') first', $key));

        } elseif($this->isExceptionRepeatBlockKey && is_array($this->handlers[$key])){
            if(count($this->handlers[$key]) >= 2){
                array_pop($this->handlers[$key]);
            } else {
                unset($this->handlers[$key]);
            }

        } else {
            unset($this->handlers[$key]);
        }
        return $this->adapter->releaseEx($key, getmypid());
    }

    /**
     * @return SemaphoreKeyStorageInterface
     */
    public function getKeyStorage()
    {
        return $this->keyStorage;
    }

    public function __destruct()
    {
        if(!empty($this->handlers)){
            $this->logger->error(sprintf('[%d] %s: %s. Key: %s', getmypid(), __FUNCTION__, 'Handlers is not empty',  $this->getDataToString($this->handlers)));
        }
    }

    protected function isDemoMode()
    {
        $res = ($this->mode == 'demo');
        if($res){
            $this->logger->addDebug('Use demo mode');
        }
        return $res;
    }
}
