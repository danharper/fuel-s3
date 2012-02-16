<?php

namespace S3;

class S3Exception extends \FuelException {
	function __construct($message, $file = null, $line = null, $code = 0)
	{
		parent::__construct($message, $code);
		if ($file)
			$this->file = $file;
		if ($line)
			$this->line = $line;
	}
}