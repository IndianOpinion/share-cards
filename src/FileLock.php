<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * A lock held through `flock` on an open file handle.
 */
final class FileLock implements Lock
{
    /** @var resource|null */
    private $handle;

    /**
     * @param resource $handle
     */
    public function __construct($handle)
    {
        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
