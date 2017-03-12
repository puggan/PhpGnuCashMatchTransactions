<?php

	class db
	{
		public $link;
		public $last_query;

		function __construct($database, $username, $password, $host = NULL, $port = NULL)
		{
			if(!$host)
			{
				$this->link = new mysqli('localhost', $username, $password, $database);
			}
			else if(!$port)
			{
				$this->link = new mysqli($host, $username, $password, $database);
			}
			else
			{
				$this->link = new mysqli($host, $username, $password, $database, $port);
			}

			if($this->link)
			{
				$this->link->set_charset("utf8");
			}
			else
			{
				$this->last_error = 'No database connection';
				trigger_error('Database fel: ' . $this->last_error);
			}
		}

		function __destruct()
		{
			unset($this->link);
		}

		function query($query)
		{
			if(!$this->link OR !$this->link->ping())
			{
				$this->link = NULL;
				$this->last_error = 'No database connection';
				trigger_error('Database fel: ' . $this->last_error);
				return FALSE;
			}

			$this->last_error = NULL;
			$this->last_query = $query;

			return $this->link->query($query);
		}

		function write($query)
		{
			$result = $this->query($query);

			if(!$result) return FALSE;

			if($result === TRUE) return TRUE;

			if(is_a($result, "mysqli_result"))
			{
				$result->free();
			}

			return FALSE;
		}

		function insert($query)
		{
			$result = $this->write($query);

			if($result) return $this->link->insert_id;

			return $result;
		}

		function update($query)
		{
			$result = $this->write($query);

			if($result) return $this->link->affected_rows;

			return $result;
		}

		function read($query, $index = NULL, $column = NULL)
		{
			$resource = $this->query($query);

			if(!is_a($resource, "mysqli_result")) return FALSE;

			$result = $resource->fetch_all(MYSQLI_ASSOC);
			$resource->free();

			if($index AND $column)
			{
				$result = array_column($result, $column, $index);
			}
			else if($index)
			{
				$result = array_column($result, NULL, $index);
			}
			else if($column)
			{
				$result = array_column($result, $column);
			}

			return $result;
		}

		function g_read($query, $index = NULL, $column = NULL)
		{
			$resource = $this->query($query);

			//if(!is_a($resource, "mysqli_result")) return FALSE;
			if(!is_a($resource, "mysqli_result")) return;

			while(NULL !== ($row = $resource->fetch_array(MYSQLI_ASSOC)))
			{
				if($index AND $column)
				{
					yield $row[$index] => $row[$column];
				}
				else if($index)
				{
					yield $row[$index] => $row;
				}
				else if($column)
				{
					yield $row[$column];
				}
				else
				{
					yield $row;
				}
			}

			$resource->free();
		}

		function objects($query, $index = NULL, $class_name = NULL)
		{
			$result = array();
			$resource = $this->query($query);

			if(!is_a($resource, "mysqli_result")) return FALSE;

			while(NULL !== ($row = $resource->fetch_object($class_name ?: 'stdClass')))
			{
				if($index)
				{
					$result[$row->$index] = $row;
				}
				else
				{
					$result[] = $row;
				}
			}

			$resource->free();

			return $result;
		}

		function g_objects($query, $index = NULL, $class_name = NULL)
		{
			$resource = $this->query($query);

			if(!is_a($resource, "mysqli_result")) return FALSE;

			while(NULL !== ($row = $resource->fetch_object($class_name ?: 'stdClass')))
			{
				if($index)
				{
					yield $row->$index => $row;
				}
				else
				{
					yield $row;
				}
			}

			$resource->free();
		}

		function get($query, $default = FALSE)
		{
			$resource = $this->query($query);

			if(!is_a($resource, "mysqli_result")) return FALSE;

			$row = $resource->fetch_array(MYSQLI_ASSOC);

			$resource->free();

			if(!$row)
			{
				return $default;
			}

			if(is_array($row) AND count($row) == 1)
			{
				return array_values($row)[0];
			}

			return $row;
		}

		function object($query, $default = FALSE, $class_name = NULL)
		{
			$resource = $this->query($query);

			if(!is_a($resource, "mysqli_result")) return FALSE;

			$row = $resource->fetch_object($class_name ?: 'stdClass');

			$resource->free();

			if(!$row)
			{
				return $default;
			}

			if(is_array($row) AND count($row) == 1)
			{
				return array_values($row)[0];
			}

			return $row;
		}

		function fetch($resource)
		{
			return $resource->fetch_array(MYSQLI_ASSOC);
		}

		function close($resource)
		{
			return $resource->free();
		}

		function quote($string)
		{
			return "'" . $this->link->real_escape_string($string) . "'";
		}
	}
