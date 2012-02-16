<?php

namespace S3;

final class S3Request
{
	private $endpoint, $verb, $bucket, $uri, $resource = '', $parameters = array(),
	$amzHeaders = array(), $headers = array(
		'Host' => '', 'Date' => '', 'Content-MD5' => '', 'Content-Type' => ''
	);
	public $fp = false, $size = 0, $data = false, $response;


	/**
	* Constructor
	*
	* @param string $verb Verb
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @return mixed
	*/
	function __construct($verb, $bucket = '', $uri = '', $endpoint = 's3.amazonaws.com')
	{
		$this->endpoint = $endpoint;
		$this->verb = $verb;
		$this->bucket = $bucket;
		$this->uri = $uri !== '' ? '/'.str_replace('%2F', '/', rawurlencode($uri)) : '/';

		//if ($this->bucket !== '')
		//	$this->resource = '/'.$this->bucket.$this->uri;
		//else
		//	$this->resource = $this->uri;

		if ($this->bucket !== '')
		{
			if ($this->__dnsBucketName($this->bucket))
			{
				$this->headers['Host'] = $this->bucket.'.'.$this->endpoint;
				$this->resource = '/'.$this->bucket.$this->uri;
			}
			else
			{
				$this->headers['Host'] = $this->endpoint;
				$this->uri = $this->uri;
				if ($this->bucket !== '') $this->uri = '/'.$this->bucket.$this->uri;
				$this->bucket = '';
				$this->resource = $this->uri;
			}
		}
		else
		{
			$this->headers['Host'] = $this->endpoint;
			$this->resource = $this->uri;
		}


		$this->headers['Date'] = gmdate('D, d M Y H:i:s T');
		$this->response = new \STDClass;
		$this->response->error = false;
	}


	/**
	* Set request parameter
	*
	* @param string $key Key
	* @param string $value Value
	* @return void
	*/
	public function setParameter($key, $value)
	{
		$this->parameters[$key] = $value;
	}


	/**
	* Set request header
	*
	* @param string $key Key
	* @param string $value Value
	* @return void
	*/
	public function setHeader($key, $value)
	{
		$this->headers[$key] = $value;
	}


	/**
	* Set x-amz-meta-* header
	*
	* @param string $key Key
	* @param string $value Value
	* @return void
	*/
	public function setAmzHeader($key, $value)
	{
		$this->amzHeaders[$key] = $value;
	}


	/**
	* Get the S3 response
	*
	* @return object | false
	*/
	public function getResponse()
	{
		$query = '';
		if (sizeof($this->parameters) > 0)
		{
			$query = substr($this->uri, -1) !== '?' ? '?' : '&';
			foreach ($this->parameters as $var => $value)
				if ($value == null || $value == '') $query .= $var.'&';
				// Parameters should be encoded (thanks Sean O'Dea)
				else $query .= $var.'='.rawurlencode($value).'&';
			$query = substr($query, 0, -1);
			$this->uri .= $query;

			if (array_key_exists('acl', $this->parameters) ||
			array_key_exists('location', $this->parameters) ||
			array_key_exists('torrent', $this->parameters) ||
			array_key_exists('website', $this->parameters) ||
			array_key_exists('logging', $this->parameters))
				$this->resource .= $query;
		}
		$url = (S3::$useSSL ? 'https://' : 'http://') . ($this->headers['Host'] !== '' ? $this->headers['Host'] : $this->endpoint) . $this->uri;

		//var_dump('bucket: ' . $this->bucket, 'uri: ' . $this->uri, 'resource: ' . $this->resource, 'url: ' . $url);

		// Basic setup
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_USERAGENT, 'S3/php');

		if (S3::$useSSL)
		{
			// SSL Validation can now be optional for those with broken OpenSSL installations
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, S3::$useSSLValidation ? 1 : 0);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, S3::$useSSLValidation ? 1 : 0);

			if (S3::$sslKey !== null) curl_setopt($curl, CURLOPT_SSLKEY, S3::$sslKey);
			if (S3::$sslCert !== null) curl_setopt($curl, CURLOPT_SSLCERT, S3::$sslCert);
			if (S3::$sslCACert !== null) curl_setopt($curl, CURLOPT_CAINFO, S3::$sslCACert);
		}

		curl_setopt($curl, CURLOPT_URL, $url);

		if (S3::$proxy != null && isset(S3::$proxy['host']))
		{
			curl_setopt($curl, CURLOPT_PROXY, S3::$proxy['host']);
			curl_setopt($curl, CURLOPT_PROXYTYPE, S3::$proxy['type']);
			if (isset(S3::$proxy['user'], S3::$proxy['pass']) && $proxy['user'] != null && $proxy['pass'] != null)
				curl_setopt($curl, CURLOPT_PROXYUSERPWD, sprintf('%s:%s', S3::$proxy['user'], S3::$proxy['pass']));
		}

		// Headers
		$headers = array(); $amz = array();
		foreach ($this->amzHeaders as $header => $value)
			if (strlen($value) > 0) $headers[] = $header.': '.$value;
		foreach ($this->headers as $header => $value)
			if (strlen($value) > 0) $headers[] = $header.': '.$value;

		// Collect AMZ headers for signature
		foreach ($this->amzHeaders as $header => $value)
			if (strlen($value) > 0) $amz[] = strtolower($header).':'.$value;

		// AMZ headers must be sorted
		if (sizeof($amz) > 0)
		{
			sort($amz);
			$amz = "\n".implode("\n", $amz);
		} else $amz = '';

		if (S3::hasAuth())
		{
			// Authorization string (CloudFront stringToSign should only contain a date)
			if ($this->headers['Host'] == 'cloudfront.amazonaws.com')
				$headers[] = 'Authorization: ' . S3::__getSignature($this->headers['Date']);
			else
			{
				$headers[] = 'Authorization: ' . S3::__getSignature(
					$this->verb."\n".
					$this->headers['Content-MD5']."\n".
					$this->headers['Content-Type']."\n".
					$this->headers['Date'].$amz."\n".
					$this->resource
				);
			}
        }

		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
		curl_setopt($curl, CURLOPT_WRITEFUNCTION, array(&$this, '__responseWriteCallback'));
		curl_setopt($curl, CURLOPT_HEADERFUNCTION, array(&$this, '__responseHeaderCallback'));
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

		// Request types
		switch ($this->verb)
		{
			case 'GET': break;
			case 'PUT': case 'POST': // POST only used for CloudFront
				if ($this->fp !== false)
				{
					curl_setopt($curl, CURLOPT_PUT, true);
					curl_setopt($curl, CURLOPT_INFILE, $this->fp);
					if ($this->size >= 0)
						curl_setopt($curl, CURLOPT_INFILESIZE, $this->size);
				}
				elseif ($this->data !== false)
				{
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->verb);
					curl_setopt($curl, CURLOPT_POSTFIELDS, $this->data);
				}
				else
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->verb);
			break;
			case 'HEAD':
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'HEAD');
				curl_setopt($curl, CURLOPT_NOBODY, true);
			break;
			case 'DELETE':
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
			break;
			default: break;
		}

		// Execute, grab errors
		if (curl_exec($curl))
			$this->response->code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		else
			$this->response->error = array(
				'code' => curl_errno($curl),
				'message' => curl_error($curl),
				'resource' => $this->resource
			);

		@curl_close($curl);

		// Parse body into XML
		if ($this->response->error === false && isset($this->response->headers['type']) &&
		$this->response->headers['type'] == 'application/xml' && isset($this->response->body))
		{
			$this->response->body = simplexml_load_string($this->response->body);

			// Grab S3 errors
			if (!in_array($this->response->code, array(200, 204, 206)) &&
			isset($this->response->body->Code, $this->response->body->Message))
			{
				$this->response->error = array(
					'code' => (string)$this->response->body->Code,
					'message' => (string)$this->response->body->Message
				);
				if (isset($this->response->body->Resource))
					$this->response->error['resource'] = (string)$this->response->body->Resource;
				unset($this->response->body);
			}
		}

		// Clean up file resources
		if ($this->fp !== false && is_resource($this->fp)) fclose($this->fp);

		return $this->response;
	}


	/**
	* CURL write callback
	*
	* @param resource &$curl CURL resource
	* @param string &$data Data
	* @return integer
	*/
	private function __responseWriteCallback(&$curl, &$data)
	{
		if (in_array($this->response->code, array(200, 206)) && $this->fp !== false)
			return fwrite($this->fp, $data);
		else
			$this->response->body .= $data;
		return strlen($data);
	}


	/**
	* Check DNS conformity
	*
	* @param string $bucket Bucket name
	* @return boolean
	*/
	private function __dnsBucketName($bucket)
	{
		if (strlen($bucket) > 63 || !preg_match("/[^a-z0-9\.-]/", $bucket)) return false;
		if (strstr($bucket, '-.') !== false) return false;
		if (strstr($bucket, '..') !== false) return false;
		if (!preg_match("/^[0-9a-z]/", $bucket)) return false;
		if (!preg_match("/[0-9a-z]$/", $bucket)) return false;
		return true;
	}


	/**
	* CURL header callback
	*
	* @param resource &$curl CURL resource
	* @param string &$data Data
	* @return integer
	*/
	private function __responseHeaderCallback(&$curl, &$data)
	{
		if (($strlen = strlen($data)) <= 2) return $strlen;
		if (substr($data, 0, 4) == 'HTTP')
			$this->response->code = (int)substr($data, 9, 3);
		else
		{
			$data = trim($data);
			if (strpos($data, ': ') === false) return $strlen;
			list($header, $value) = explode(': ', $data, 2);
			if ($header == 'Last-Modified')
				$this->response->headers['time'] = strtotime($value);
			elseif ($header == 'Content-Length')
				$this->response->headers['size'] = (int)$value;
			elseif ($header == 'Content-Type')
				$this->response->headers['type'] = $value;
			elseif ($header == 'ETag')
				$this->response->headers['hash'] = $value{0} == '"' ? substr($value, 1, -1) : $value;
			elseif (preg_match('/^x-amz-meta-.*$/', $header))
				$this->response->headers[$header] = is_numeric($value) ? (int)$value : $value;
		}
		return $strlen;
	}

}