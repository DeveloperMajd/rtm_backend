<?php

// IDE/static-analysis stub only — never autoloaded or required anywhere.
// RedisException comes from the phpredis PECL extension (a C extension,
// not a Composer package), which Intelephense has no bundled stub for,
// so `catch (RedisException $e)` shows as "Undefined type" without this.

if (! class_exists(RedisException::class, false)) {
    class RedisException extends Exception {}
}
