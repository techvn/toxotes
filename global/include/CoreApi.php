<?php
namespace CoreApi;
use Flywheel\Base;

class Server
{
    public $timestampThreshold = 300; // in seconds, five minutes

    /*
     * @var SignatureMethod[]
     */
    protected $_signatureMethods = array();

    /**
     * @var DataStore
     */
    protected $_dataStore;

    public function __construct(DataStore $dataStore)
    {
        $this->_dataStore = $dataStore;
    }

    public function addSignatureMethod(SignatureMethod $signatureMethod)
    {
        $this->_signatureMethods[$signatureMethod->getName()] = $signatureMethod;
    }

    /**
     * figure out the signature with some defaults
     *
     * @param Request $request
     * @throws Exception
     * @return SignatureMethod
     */
    private function _getSignatureMethod(Request &$request) {
        $signature_method =
            @$request->getParameter("signature_method");

        if (!$signature_method) {
            // According to chapter 7 ("Accessing Protected Ressources") the signature-method
            // parameter is required, and we can't just fallback to PLAINTEXT
            throw new Exception('No signature method parameter. This parameter is required', 400);
        }

        if (!in_array($signature_method,
            array_keys($this->_signatureMethods))) {
            throw new Exception("Signature method '$signature_method' not supported try one of the following: " .
                implode(", ", array_keys($this->_signatureMethods)), 400);
        }
        return $this->_signatureMethods[$signature_method];
    }

    public function getConsumer(Request &$request)
    {
        $consumer_key = @$request->getParameter("consumer_key");
        if (!$consumer_key) {
            throw new Exception("Invalid consumer key", 400);
        }

        $consumer = $this->_dataStore->lookupConsumer($consumer_key);
        if (!$consumer) {
            throw new Exception("Invalid consumer", 400);
        }

        return $consumer;
    }

    /**
     * all-in-one function to check the signature on a request
     * should guess the signature method appropriately
     *
     * @param Request $request
     * @param Consumer $consumer
     * @throws Exception
     */
    private function _checkSignature(Request &$request, Consumer $consumer) {
        // this should probably be in a different method
        $timestamp = @$request->getParameter('timestamp');
        $nonce = @$request->getParameter('nonce');

        $this->_checkTimestamp($timestamp);

        $this->_checkNonce($consumer, $nonce, $timestamp);

        $signature_method = $this->_getSignatureMethod($request);

        /* @SignatureMethod_RSA_SHA1 $signature_method */
        $signature = $request->getParameter('signature');

        $valid_sig = $signature_method->checkSignature(
            $request,
            $consumer,
            $signature
        );

        if (!$valid_sig) {
            throw new Exception("Invalid signature", 403);
        }
    }

    /**
     * check that the nonce is not repeated
     */
    private function _checkNonce(Consumer $consumer, $nonce, $timestamp) {
        if(!$nonce) {
            throw new Exception('Missing nonce parameter. The parameter is required.', 400);
        }

        // verify that the nonce is uniqueish
        $found = $this->_dataStore->lookupNonce(
            $consumer,
            $nonce,
            $timestamp
        );

        if ($found) {
            throw new Exception("Nonce already used: {$nonce}", 400);
        }

    }

    /**
     * check that the timestamp is new enough
     */
    private function _checkTimestamp($timestamp) {
        if(!$timestamp) {
            throw new Exception("Missing timestamp parameter. The parameter is required", 400);
        }
        // verify that timestamp is recentish
        $now = time();
        if (abs($now - $timestamp) > $this->timestampThreshold) {
            throw new Exception("Expired timestamp, yours $timestamp, ours $now", 400);
        }
    }

    /**
     * verify an api call, checks all the parameters
     */
    public function verifyRequest(Request &$request) {
        $consumer = $this->getConsumer($request);
        $this->_checkSignature($request, $consumer);
        return array($consumer);
    }
}
class Request
{
    private $_parameters;
    private $_httpMethod;
    private $_httpUrl;
    public $baseString;
    public static $POST_INPUT = 'php://input';

    function __construct($http_method, $http_url, $parameters=NULL) {
        @$parameters or $parameters = array();
        $parameters = array_merge( Util::parseParameters(parse_url($http_url, PHP_URL_QUERY)), $parameters);
        $this->_parameters = $parameters;
        $this->_httpMethod = $http_method;
        $this->_httpUrl = $http_url;
    }

    /**
     * attempt to build up a request from what was passed to the server
     */
    public static function fromRequest($http_method=NULL, $http_url=NULL, $parameters=NULL) {
        $scheme = (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != "on")
            ? 'http'
            : 'https';
        @$http_url or $http_url = $scheme .
            '://' . $_SERVER['HTTP_HOST'] .
            ':' .
            $_SERVER['SERVER_PORT'] .
            $_SERVER['REQUEST_URI'];
        @$http_method or $http_method = $_SERVER['REQUEST_METHOD'];

        // We weren't handed any parameters, so let's find the ones relevant to
        // this request.
        // If you run XML-RPC or similar you should use this to provide your own
        // parsed parameter-list
        if (!$parameters) {
            // Find request headers
            $request_headers = Util::getHeaders();

            // Parse the query-string to find GET parameters
            $parameters = Util::parseParameters($_SERVER['QUERY_STRING']);

            // It's a POST request of the proper content-type, so parse POST
            // parameters and add those overriding any duplicates from GET
            if (($http_method == "POST" && @strstr($request_headers["Content-Type"], "application/x-www-form-urlencoded"))
                || $http_method == "PUT" || $http_method == "DELETE") {
                $post_data = Util::parseParameters(
                    file_get_contents(self::$POST_INPUT)
                );
                $parameters = array_merge($parameters, $post_data);
            }

            // We have a Authorization-header with OAuth data. Parse the header
            // and add those overriding any duplicates from GET or POST
            if (@substr($request_headers['Authorization'], 0, 6) == "OAuth ") {
                $header_parameters = Util::splitHeader(
                    $request_headers['Authorization']
                );
                $parameters = array_merge($parameters, $header_parameters);
            }

        }

        return new self($http_method, $http_url, $parameters);
    }

    /**
     * pretty much a helper function to set up the request
     */
    public static function fromConsumer($consumer, $http_method, $http_url, $parameters=NULL) {
        @$parameters or $parameters = array();
        $defaults = array(
            "nonce" => self::_generateNonce($consumer->key),
            "timestamp" => self::_generateTimestamp(),
            "consumer_key" => $consumer->key);

        $parameters = array_merge($defaults, $parameters);


        return new self($http_method, $http_url, $parameters);
    }

    public function signRequest(SignatureMethod $signature_method, $consumer) {
        /* @var SignatureMethod_RSA_SHA1 $signature */
        $this->setParameter(
            "signature_method",
            $signature_method->getName(),
            false
        );

        $signature = $this->buildSignature($signature_method, $consumer);
        $this->setParameter("signature", $signature, false);
    }

    public function buildSignature($signature_method, $consumer) {
        /* @var SignatureMethod_RSA_SHA1 $signature_method */
        $signature = $signature_method->buildSignature($this, $consumer);
        return $signature;
    }

    /**
     * Returns the base string of this request
     *
     * The base string defined as the method, the url
     * and the parameters (normalized), each urlencoded
     * and the concated with &.
     */
    public function getSignatureBaseString() {
        $parts = array(
            $this->getNormalizedHttpMethod(),
            $this->getNormalizedHttpUrl(),
            $this->getSignableParameters()
        );

        $parts = Util::urlEncodeRfc3986($parts);

        return implode('&', $parts);
    }

    /**
     * just uppercases the http method
     */
    public function getNormalizedHttpMethod() {
        return strtoupper($this->_httpMethod);
    }

    /**
     * parses the url and rebuilds it to be
     * scheme://host/path
     */
    public function getNormalizedHttpUrl() {
        $parts = parse_url($this->_httpUrl);

        $port = @$parts['port'];
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $path = @$parts['path'];

        $port or $port = ($scheme == 'https') ? '443' : '80';

        if (($scheme == 'https' && $port != '443')
            || ($scheme == 'http' && $port != '80')) {
            $host = "$host:$port";
        }
        return "$scheme://$host$path";
    }

    /**
     * The request parameters, sorted and concatenated into a normalized string.
     * @return string
     */
    public function getSignableParameters() {
        // Grab all parameters
        $params = $this->_parameters;

        // Remove oauth_signature if present
        // Ref: Spec: 9.1.1 ("The oauth_signature parameter MUST be excluded.")
        if (isset($params['signature'])) {
            unset($params['signature']);
        }

        return Util::buildRawHttpQuery($params);
    }

    private static function _generateNonce($consumerKey) {
        $mt = microtime() .uniqid($consumerKey, true);
        $rand = mt_rand();
        return md5($mt . $rand); // md5s look nicer than numbers
    }

    private static function _generateTimestamp() {
        return time();
    }

    public function setParameter($name, $value, $allow_duplicates = true) {
        if ($allow_duplicates && isset($this->_parameters[$name])) {
            // We have already added parameter(s) with this name, so add to the list
            if (is_scalar($this->_parameters[$name])) {
                // This is the first duplicate, so transform scalar (string)
                // into an array so we can add the duplicates
                $this->_parameters[$name] = array($this->_parameters[$name]);
            }

            $this->_parameters[$name][] = $value;
        } else {
            $this->_parameters[$name] = $value;
        }
    }

    public function getParameter($name) {
        return isset($this->_parameters[$name]) ? $this->_parameters[$name] : null;
    }

    public function getParameters() {
        return $this->_parameters;
    }

    public function unsetParameter($name) {
        unset($this->_parameters[$name]);
    }

    /**
     * builds the data one would send in a POST request
     */
    public function toPostData() {
        return Util::buildHttpQuery($this->_parameters);
    }

    /**
     * builds a url usable for a GET request
     */
    public function toUrl() {
        $post_data = $this->toPostData();
        $out = $this->getNormalizedHttpUrl();
        if ($post_data) {
            $out .= '?'.$post_data;
        }
        return $out;
    }

    public function __toString() {
        return $this->toUrl();
    }
}

class Consumer
{
    public $key;
    public $secret;

    function __construct($key, $secret) {
        $this->key = $key;
        $this->secret = $secret;
    }

    function __toString() {
        return "MApiConsumer[key=$this->key,secret=$this->secret]";
    }
}

class Util {
    public static function urlEncodeRfc3986($input) {
        if (is_array($input)) {
            return array_map('self::urlEncodeRfc3986', $input);
        } else if (is_scalar($input)) {
            return str_replace(
                '+',
                ' ',
                str_replace('%7E', '~', rawurlencode($input))
            );
        } else {
            return '';
        }
    }


    // This decode function isn't taking into consideration the above
    // modifications to the encoding process. However, this method doesn't
    // seem to be used anywhere so leaving it as is.
    public static function urlDecodeRfc3986($string) {
        return urldecode($string);
    }

    // Utility function for turning the Authorization: header into
    // parameters, has to do some unescaping
    // Can filter out any non-oauth parameters if needed (default behaviour)
    public static function splitHeader($header, $only_allow_oauth_parameters = true) {
        $pattern = '/(([-_a-z]*)=("([^"]*)"|([^,]*)),?)/';
        $offset = 0;
        $params = array();
        while (preg_match($pattern, $header, $matches, PREG_OFFSET_CAPTURE, $offset) > 0) {
            $match = $matches[0];
            $header_name = $matches[2][0];
            $header_content = (isset($matches[5])) ? $matches[5][0] : $matches[4][0];
            if (preg_match('/^oauth_/', $header_name) || !$only_allow_oauth_parameters) {
                $params[$header_name] = self::urlDecodeRfc3986($header_content);
            }
            $offset = $match[1] + strlen($match[0]);
        }

        if (isset($params['realm'])) {
            unset($params['realm']);
        }

        return $params;
    }

    // helper to try to sort out headers for people who aren't running apache
    public static function getHeaders() {
        if (function_exists('apache_request_headers')) {
            // we need this to get the actual Authorization: header
            // because apache tends to tell us it doesn't exist
            $headers = apache_request_headers();

            // sanitize the output of apache_request_headers because
            // we always want the keys to be Cased-Like-This and arh()
            // returns the headers in the same case as they are in the
            // request
            $out = array();
            foreach( $headers AS $key => $value ) {
                $key = str_replace(
                    " ",
                    "-",
                    ucwords(strtolower(str_replace("-", " ", $key)))
                );
                $out[$key] = $value;
            }
        } else {
            // otherwise we don't have apache and are just going to have to hope
            // that $_SERVER actually contains what we need
            $out = array();
            if( isset($_SERVER['CONTENT_TYPE']) )
                $out['Content-Type'] = $_SERVER['CONTENT_TYPE'];
            if( isset($_ENV['CONTENT_TYPE']) )
                $out['Content-Type'] = $_ENV['CONTENT_TYPE'];

            foreach ($_SERVER as $key => $value) {
                if (substr($key, 0, 5) == "HTTP_") {
                    // this is chaos, basically it is just there to capitalize the first
                    // letter of every word that is not an initial HTTP and strip HTTP
                    // code from przemek
                    $key = str_replace(
                        " ",
                        "-",
                        ucwords(strtolower(str_replace("_", " ", substr($key, 5))))
                    );
                    $out[$key] = $value;
                }
            }
        }
        return $out;
    }

    // This function takes a input like a=b&a=c&d=e and returns the parsed
    // parameters like this
    // array('a' => array('b','c'), 'd' => 'e')
    public static function parseParameters( $input ) {
        if (!isset($input) || !$input) return array();

        $pairs = explode('&', $input);

        $parsed_parameters = array();
        foreach ($pairs as $pair) {
            $split = explode('=', $pair, 2);
            $parameter = self::urlDecodeRfc3986($split[0]);
            $value = isset($split[1]) ? self::urlDecodeRfc3986($split[1]) : '';

            if (isset($parsed_parameters[$parameter])) {
                // We have already recieved parameter(s) with this name, so add to the list
                // of parameters with this name

                if (is_scalar($parsed_parameters[$parameter])) {
                    // This is the first duplicate, so transform scalar (string) into an array
                    // so we can add the duplicates
                    $parsed_parameters[$parameter] = array($parsed_parameters[$parameter]);
                }

                $parsed_parameters[$parameter][] = $value;
            } else {
                $parsed_parameters[$parameter] = $value;
            }
        }
        return $parsed_parameters;
    }

    public static function buildHttpQuery($params) {
        if (!$params) return '';

        // Urlencode both keys and values
        $keys = self::urlEncodeRfc3986(array_keys($params));
        $values = self::urlEncodeRfc3986(array_values($params));
        $params = array_combine($keys, $values);

        // Parameters are sorted by name, using lexicographical byte value ordering.
        // Ref: Spec: 9.1.1 (1)
        uksort($params, 'strcmp');

        $pairs = array();
        foreach ($params as $parameter => $value) {
            if (is_array($value)) {
                // If two or more parameters share the same name, they are sorted by their value
                // Ref: Spec: 9.1.1 (1)
                natsort($value);
                foreach ($value as $duplicate_value) {
                    $pairs[] = $parameter . '=' . $duplicate_value;
                }
            } else {
                $pairs[] = $parameter . '=' . $value;
            }
        }
        // For each parameter, the name is separated from the corresponding value by an '=' character (ASCII code 61)
        // Each name-value pair is separated by an '&' character (ASCII code 38)
        return implode('&', $pairs);
    }

    public static function buildRawHttpQuery($params) {
        if (!$params) return '';

        // Urlencode both keys and values
        $keys = array_keys($params);
        $values = array_values($params);
        $params = array_combine($keys, $values);

        // Parameters are sorted by name, using lexicographical byte value ordering.
        // Ref: Spec: 9.1.1 (1)
        uksort($params, 'strcmp');

        $pairs = array();
        foreach ($params as $parameter => $value) {
            if (is_array($value)) {
                // If two or more parameters share the same name, they are sorted by their value
                // Ref: Spec: 9.1.1 (1)
                natsort($value);
                foreach ($value as $duplicate_value) {
                    $pairs[] = $parameter . '=' . $duplicate_value;
                }
            } else {
                $pairs[] = $parameter . '=' . $value;
            }
        }
        // For each parameter, the name is separated from the corresponding value by an '=' character (ASCII code 61)
        // Each name-value pair is separated by an '&' character (ASCII code 38)
        return implode('&', $pairs);
    }
}

abstract class SignatureMethod
{
    abstract function getName();

    abstract public function buildSignature(Request $request, Consumer $consumer);

    /**
     * @param $request
     * @param $consumer
     * @param $signature
     * @return bool
     */
    public function checkSignature(Request $request, Consumer $consumer, $signature) {
        $built = $this->buildSignature($request, $consumer);
        return $built == $signature;
    }
}

class SignatureMethod_HMAC_SHA1 extends SignatureMethod {
    function getName() {
        return "HMAC-SHA1";
    }

    public function buildSignature(Request $request, Consumer $consumer) {
        $base_string = $request->getSignatureBaseString();
        $request->baseString = $base_string;
        $key_parts = array(
            $consumer->secret
        );
        $key_parts = Util::urlEncodeRfc3986($key_parts);
        $key = implode('&', $key_parts);

        $result = base64_encode(hash_hmac('sha1', $base_string, $key, true));
        return $result;
    }
}

abstract class SignatureMethod_RSA_SHA1 extends SignatureMethod {
    public function getName() {
        return "RSA-SHA1";
    }

    // Up to the SP to implement this lookup of keys. Possible ideas are:
    // (1) do a lookup in a table of trusted certs keyed off of consumer
    // (2) fetch via http using a url provided by the requester
    // (3) some sort of specific discovery code based on request
    //
    // Either way should return a string representation of the certificate
    protected abstract function fetchPublicCert(&$request, $consumer);

    // Up to the SP to implement this lookup of keys. Possible ideas are:
    // (1) do a lookup in a table of trusted certs keyed off of consumer
    //
    // Either way should return a string representation of the certificate
    protected abstract function fetchPrivateCert(&$request, $consumer);

    public function buildSignature(Request $request, Consumer $consumer) {
        $baseString = $request->getSignatureBaseString();
        $request->baseString = $baseString;

        // Fetch the private key cert based on the request
        $cert = $this->fetchPrivateCert($request, $consumer);

        // Pull the private key ID from the certificate
        $privateKey = openssl_get_privatekey($cert);

        // Sign using the key
        $ok = openssl_sign($baseString, $signature, $privateKey);

        // Release the key resource
        openssl_free_key($privateKey);

        return base64_encode($signature);
    }

    public function checkSignature(Request $request, Consumer $consumer, $signature) {
        $decoded_sig = base64_decode($signature);

        $base_string = $request->getSignatureBaseString();

        // Fetch the public key cert based on the request
        $cert = $this->fetchPublicCert($request, $consumer);

        // Pull the public key ID from the certificate
        $publicKey = openssl_get_publickey($cert);

        // Check the computed signature against the one passed in the query
        $ok = openssl_verify($base_string, $decoded_sig, $publicKey);

        // Release the key resource
        openssl_free_key($publicKey);

        return $ok == 1;
    }
}


abstract class DataStore{
    abstract function lookupConsumer($consumer_key);

    abstract function lookupNonce($consumer, $nonce, $timestamp);
}

class Exception extends \Exception {}

class ErrorHandler {
    static private $_format = 'json';

    public static function printExceptionInfo(\Exception $e) {
        if (($e instanceof \CoreApi\Exception) || ($e instanceof \Flywheel\Exception\Api)) {
            self::printError($e->getCode(), $e->getMessage());
        } else {
            if ($app = Base::getApp() && Base::ENV_PRO != Base::getEnv()) {
                \Flywheel\Exception::outputStackTrace($e, 'none');
            }

            self::printError($e->getCode());
        }
    }

    private static function _responseError($code, $error) {
        $response = new \stdClass();
        if (!is_array($error)) {
            switch ($code) {
                case 400:
                    $error = array(
                        'code' => 400,
                        'message' => $error,
                    );
                    break;
                case 401:
                    $error = array(
                        'code' => 401,
                        'message' => $error,
                    );
                    break;
                case 403:
                    $error = array(
                        'code' => API_FORBIDDEN,
                        'message' => $error,
                    );
                    break;
                case 404:
                    $error = array(
                        'code' => 404,
                        'message' => $error,
                    );
                    break;
                case 406:
                    $error = array(
                        'code' => 406,
                        'message' => $error,
                    );
                    break;
                case 500:
                    $error = array(
                        'code' => 500,
                        'message' => $error,
                    );
                    break;
                case 502:
                    $error = array(
                        'code' => 502,
                        'message' => $error,
                    );
                    break;
                case 503:
                    $error = array(
                        'code' => 503,
                        'message' => $error,
                    );
                    break;

            }
        }

        $response->hash = array(
            'request' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on'? 'https://':'http://')
                .$_SERVER['HTTP_HOST']
                .$_SERVER['REQUEST_URI'],
            'errors' => array($error),
        );

        if (400 != $code) {
            $response->hash['api'] = $_SERVER['REQUEST_METHOD'] .' ' .self::_getRequestInfo();
            $response->hash['format'] = self::$_format;
        }

        return $response;
    }

    public static function printError($code, $body = null) {
        while (ob_get_level()) {
            if (!ob_end_clean()) {
                break;
            }
        }

        if (!headers_sent()) {
            if (null == $code) {
                $code = '500';
            }

            $headMsg = self::_getHeaderMessage($code);

            header("HTTP/1.1 $code $headMsg");
        }

        $response = self::_responseError($code, $body);
        $format = self::$_format;
        switch ($format) {
            case 'xml' :
                header('Content-type:text/xml');
                break;
            case 'text':
                break;
            default:
                header('Content-type:application/json');
                $response = json_encode($response);

        }

        echo $response;
        exit;
    }

    private static function _getRequestInfo() {
        if (isset($_SERVER['PATH_INFO']) && ($_SERVER['PATH_INFO'] != '')) {
            $pathInfo = $_SERVER['PATH_INFO'];
        }
        else {
            $pathInfo = preg_replace('/^'.preg_quote($_SERVER['SCRIPT_NAME'], '/').'/', '', $_SERVER['REQUEST_URI']);
            $pathInfo = preg_replace('/^'.preg_quote(preg_replace('#/[^/]+$#', '', $_SERVER['SCRIPT_NAME']), '/').'/', '', $pathInfo);
            $pathInfo = preg_replace('/\??'.preg_quote($_SERVER['QUERY_STRING'], '/').'$/', '', $pathInfo);
            if ($pathInfo == '') $pathInfo = '/';
        }

        $segment = explode('/', $pathInfo);
        $_cf = explode('.', end($segment)); //check define format
        if (isset($_cf[1])) {
            self::$_format = $_cf[1];
        }

        return ltrim($pathInfo, '/');
    }

    private static function _getHeaderMessage($code) {
        switch ($code) {
            case 400:
                return 'Bad Request';
            case 401:
                return 'Unauthorized';
            case 403:
                return 'Forbidden';
            case 404:
                return 'Not Found';
            case 406:
                return 'Not Acceptable';
            case 500:
                return 'Internal Server Error';
            case 502:
                return 'Bad Gateway';
            case 503:
                return 'Service Unavailable';
        }
    }
}