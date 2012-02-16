<?php

/**
 * Fuel is a fast, lightweight, community driven PHP5 framework.
 *
 * @package    Fuel-S3
 * @version    1.0
 * @author     Dan Harper
 * @link       http://github.com/danharper/fuel-s3
 */

Autoloader::add_core_namespace('S3');

Autoloader::add_classes(array(
	'S3\\S3'          => __DIR__.'/classes/s3.php',
	'S3\\S3Request'   => __DIR__.'/classes/s3/request.php',
	'S3\\S3Exception' => __DIR__.'/classes/s3/exception.php',
));

/* End of file bootstrap.php */