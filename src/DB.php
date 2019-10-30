<?php
/** @noinspection PhpUnused */
/** @noinspection PhpUnused */
/** @noinspection PhpVariableNamingConventionInspection */
/** @noinspection PhpMethodNamingConventionInspection */
/** @noinspection PhpClassNamingConventionInspection */
declare(strict_types=1);

namespace Puggan\GnuCashMatcher;

/**
 * Class db
 *
 * @property \mysqli link
 * @property string last_query
 * @property string last_error
 */
class DB
{
    /**
     * db constructor.
     *
     * @param string $database
     * @param string $username
     * @param string $password
     * @param string|NULL $host
     * @param int|NULL $port
     */
    public function __construct(string $database, string $username, string $password, string $host = null, int $port = null)
    {
        if (!$host) {
            $this->link = new \mysqli('localhost', $username, $password, $database);
        } elseif (!$port) {
            $this->link = new \mysqli($host, $username, $password, $database);
        } else {
            $this->link = new \mysqli($host, $username, $password, $database, $port);
        }

        if ($this->link) {
            $this->link->set_charset('utf8');
        } else {
            $this->last_error = 'No database connection';
            trigger_error('Database fel: ' . $this->last_error);
        }
    }

    /**
     * db destructor
     */
    public function __destruct()
    {
        unset($this->link);
    }

    /**
     * @param string $query
     *
     * @return \mysqli_result|false
     */
    public function query(string $query)
    {
        if (!$this->link || !$this->link->ping()) {
            $this->link = null;
            $this->last_error = 'No database connection';
            trigger_error('Database fel: ' . $this->last_error);
            return false;
        }

        $this->last_error = null;
        $this->last_query = $query;

        return $this->link->query($query);
    }

    /**
     * @param string $query
     *
     * @return bool
     */
    public function write(string $query): bool
    {
        $result = $this->query($query);

        if (!$result) {
            return false;
        }

        if ($result === true) {
            return true;
        }

        if ($result instanceof \mysqli_result) {
            $result->free();
        }

        return false;
    }

    /**
     * @param string $query
     *
     * @return int|string|false
     */
    public function insert(string $query)
    {
        $result = $this->write($query);

        if ($result) {
            return $this->link->insert_id;
        }

        return $result;
    }

    /**
     * @param string $query
     *
     * @return int|false
     */
    public function update(string $query)
    {
        $result = $this->write($query);

        if ($result) {
            return $this->link->affected_rows;
        }

        return $result;
    }

    /**
     * @param string $query
     * @param string $index
     * @param string $column
     *
     * @return false|string[][]|int[][]|string[]|int[]
     */
    public function read(string $query, string $index = null, string $column = null)
    {
        $resource = $this->query($query);

        if (!$resource instanceof \mysqli_result) {
            return false;
        }

        $result = $resource->fetch_all(MYSQLI_ASSOC);
        $resource->free();

        if ($index && $column) {
            $result = array_column($result, $column, $index);
        } elseif ($index) {
            $result = array_column($result, null, $index);
        } elseif ($column) {
            $result = array_column($result, $column);
        }

        return $result;
    }

    /**
     * @param string $query
     * @param string $index
     * @param string $column
     *
     * @return \Generator|string[][]|int[][]|string[]|int[]
     * @throws \RuntimeException
     */
    public function g_read(string $query, string $index = null, string $column = null)
    {
        $resource = $this->query($query);

        if (!$resource instanceof \mysqli_result) {
            throw new \RuntimeException('Query Failed');
        }

        while (null !== ($row = $resource->fetch_array(MYSQLI_ASSOC))) {
            if ($index && $column) {
                yield $row[$index] => $row[$column];
            } elseif ($index) {
                yield $row[$index] => $row;
            } elseif ($column) {
                yield $row[$column];
            } else {
                yield $row;
            }
        }

        $resource->free();
    }

    /**
     * @param string $query
     * @param string $index
     * @param string $class_name
     *
     * @return false|\stdClass[]
     */
    public function objects(string $query, string $index = null, string $class_name = null)
    {
        $result = [];
        $resource = $this->query($query);

        if (!$resource instanceof \mysqli_result) {
            return false;
        }

        while (null !== ($row = $resource->fetch_object($class_name ?: 'stdClass'))) {
            if ($index) {
                /** @noinspection PhpVariableVariableInspection */
                $result[$row->$index] = $row;
            } else {
                $result[] = $row;
            }
        }

        $resource->free();

        return $result;
    }

    /**
     * @param string $query
     * @param string $index
     * @param string $class_name
     *
     * @return \Generator|\stdClass[]
     * @throws \RuntimeException
     * @noinspection PhpVariableVariableInspection
     */
    public function g_objects(string $query, string $index = null, string $class_name = null)
    {
        $resource = $this->query($query);

        if (!$resource instanceof \mysqli_result) {
            throw new \RuntimeException('Query Failed');
        }

        while (null !== ($row = $resource->fetch_object($class_name ?: 'stdClass'))) {
            if ($index) {
                yield $row->$index => $row;
            } else {
                yield $row;
            }
        }

        $resource->free();
    }

    /**
     * @param string $query
     * @param bool $default
     *
     * @return false|string[]|int[]|string|int
     */
    public function get(string $query, $default = false)
    {
        $resource = $this->query($query);

        if (!$resource instanceof \mysqli_result) {
            return false;
        }

        $row = $resource->fetch_array(MYSQLI_ASSOC);

        $resource->free();

        if (!$row) {
            return $default;
        }

        if (is_array($row) && count($row) === 1) {
            return array_values($row)[0];
        }

        return $row;
    }

    /**
     * @param string $query
     * @param bool|\stdClass $default
     * @param string $class_name
     *
     * @return bool|\stdClass
     */
    public function object(string $query, $default = false, string $class_name = null)
    {
        $resource = $this->query($query);

        if (!$resource instanceof \mysqli_result) {
            return false;
        }

        $row = $resource->fetch_object($class_name ?: 'stdClass');

        $resource->free();

        if (!$row) {
            return $default;
        }

        if (is_array($row) && count($row) === 1) {
            return array_values($row)[0];
        }

        return $row;
    }

    /**
     * @param \mysqli_result $resource
     *
     * @return mixed[]|null
     */
    public static function fetch($resource): ?array
    {
        return $resource->fetch_array(MYSQLI_ASSOC);
    }

    /**
     * @param \mysqli_result $resource
     */
    public static function close($resource): void
    {
        $resource->free();
    }

    /**
     * @param string $string
     *
     * @return string
     */
    public function quote(string $string): string
    {
        return "'" . $this->link->real_escape_string($string) . "'";
    }
}
