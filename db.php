<?php

/**
 * Class db
 *
 * @property \mysqli link
 * @property string last_query
 * @property string last_error
 */
class db
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
    function __construct(string $database, string $username, string $password, string $host = null, int $port = null)
    {
        if (!$host) {
            $this->link = new mysqli('localhost', $username, $password, $database);
        } else {
            if (!$port) {
                $this->link = new mysqli($host, $username, $password, $database);
            } else {
                $this->link = new mysqli($host, $username, $password, $database, $port);
            }
        }

        if ($this->link) {
            $this->link->set_charset("utf8");
        } else {
            $this->last_error = 'No database connection';
            trigger_error('Database fel: ' . $this->last_error);
        }
    }

    /**
     * db destructor
     */
    function __destruct()
    {
        unset($this->link);
    }

    /**
     * @param string $query
     *
     * @return mysqli_result|false
     */
    function query(string $query)
    {
        if (!$this->link or !$this->link->ping()) {
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
    function write(string $query)
    {
        $result = $this->query($query);

        if (!$result) {
            return false;
        }

        if ($result === true) {
            return true;
        }

        if (is_a($result, "mysqli_result")) {
            $result->free();
        }

        return false;
    }

    /**
     * @param string $query
     *
     * @return int|string|false
     */
    function insert(string $query)
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
    function update(string $query)
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
    function read(string $query, string $index = null, string $column = null)
    {
        $resource = $this->query($query);

        if (!is_a($resource, "mysqli_result")) {
            return false;
        }

        $result = $resource->fetch_all(MYSQLI_ASSOC);
        $resource->free();

        if ($index and $column) {
            $result = array_column($result, $column, $index);
        } else {
            if ($index) {
                $result = array_column($result, null, $index);
            } else {
                if ($column) {
                    $result = array_column($result, $column);
                }
            }
        }

        return $result;
    }

    /**
     * @param string $query
     * @param string $index
     * @param string $column
     *
     * @return Generator|string[][]|int[][]|string[]|int[]
     * @throws Exception
     */
    function g_read(string $query, string $index = null, string $column = null)
    {
        $resource = $this->query($query);

        if (!is_a($resource, "mysqli_result")) {
            throw new \Exception("Query Failed");
        }

        while (null !== ($row = $resource->fetch_array(MYSQLI_ASSOC))) {
            if ($index and $column) {
                yield $row[$index] => $row[$column];
            } else {
                if ($index) {
                    yield $row[$index] => $row;
                } else {
                    if ($column) {
                        yield $row[$column];
                    } else {
                        yield $row;
                    }
                }
            }
        }

        $resource->free();
    }

    /**
     * @param string $query
     * @param string $index
     * @param string $class_name
     *
     * @return false|stdClass[]
     */
    function objects(string $query, string $index = null, string $class_name = null)
    {
        $result = [];
        $resource = $this->query($query);

        if (!is_a($resource, "mysqli_result")) {
            return false;
        }

        while (null !== ($row = $resource->fetch_object($class_name ?: 'stdClass'))) {
            if ($index) {
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
     * @return Generator|stdClass[]
     * @throws Exception
     */
    function g_objects(string $query, string $index = null, string $class_name = null)
    {
        $resource = $this->query($query);

        if (!is_a($resource, "mysqli_result")) {
            throw new \Exception("Query Failed");
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
    function get(string $query, $default = false)
    {
        $resource = $this->query($query);

        if (!is_a($resource, "mysqli_result")) {
            return false;
        }

        $row = $resource->fetch_array(MYSQLI_ASSOC);

        $resource->free();

        if (!$row) {
            return $default;
        }

        if (is_array($row) and count($row) == 1) {
            return array_values($row)[0];
        }

        return $row;
    }

    /**
     * @param string $query
     * @param bool|stdClass $default
     * @param string $class_name
     *
     * @return bool|stdClass
     */
    function object(string $query, $default = false, string $class_name = null)
    {
        $resource = $this->query($query);

        if (!is_a($resource, "mysqli_result")) {
            return false;
        }

        $row = $resource->fetch_object($class_name ?: 'stdClass');

        $resource->free();

        if (!$row) {
            return $default;
        }

        if (is_array($row) and count($row) == 1) {
            return array_values($row)[0];
        }

        return $row;
    }

    /**
     * @param $resource
     *
     * @return mixed[]
     */
    function fetch($resource)
    {
        return $resource->fetch_array(MYSQLI_ASSOC);
    }

    /**
     * @param $resource
     *
     * @return mixed
     */
    function close($resource)
    {
        return $resource->free();
    }

    /**
     * @param string $string
     *
     * @return string
     */
    function quote(string $string)
    {
        return "'" . $this->link->real_escape_string($string) . "'";
    }
}
