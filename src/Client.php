<?php
/**
 * 1CRM CRM system REST+JSON client class.
 * PHP Version 5.3
 * @package OneCRM\Client
 * @author Marcus Bointon <marcus@synchromedia.co.uk>
 * @copyright 2015 Synchromedia Limited
 * @license MIT http://opensource.org/licenses/MIT
 * @link https://github.com/Syniah/OneCRMClient
 */

namespace OneCRM;

/**
 * 1CRM CRM system REST+JSON client class.
 * @package OneCRM\Client
 * @author Marcus Bointon <marcus@synchromedia.co.uk>
 * @license MIT http://opensource.org/licenses/MIT
 * @link http://support.sugarcrm.com/02_Documentation/04_Sugar_Developer/Sugar_Developer_Guide_7.5/70_API/Web_Services/40_Legacy_REST/SOAP_APIs/01_REST/
 */
class Client
{
    /**
     * Set to true (via constructor) to enable debug output.
     * @type boolean
     * @access protected
     */
    protected $debug = false;

    /**
     * The URL of the 1CRM service to talk to,
     * usually /service/v4/rest.php in your domain.
     * @type string
     * @access protected
     */
    protected $endpoint = '';

    /**
     * A CURL instance.
     * @type resource
     * @access protected
     */
    protected $curl;

    /**
     * The session ID obtained when logging in, needed for subsequent requests.
     * @type string
     * @access protected
     */
    protected $sessionid = '';

    /**
     * The user name last used for login.
     * @type string
     * @access protected
     */
    protected $username = '';

    /**
     * The password last used for login.
     * @type string
     * @access protected
     */
    protected $password = '';

    /**
     * The login function returns user info which is kept in here.
     * @type array
     * @access protected
     */
    protected $userinfo = array();

    /**
     * The login function returns an array of modules info which is kept in here.
     * @type array
     * @access protected
     */
    protected $modules = array();

    /**
     * @const A version string for this class
     */
    const VERSION = '1.0';

    /**
     * Create a new client instance.
     * @param string  $endpoint The URL of the 1CRM service to talk to
     * @param boolean $debug    Whether to enable debugging output
     * @throws ConnectionException
     */
    public function __construct($endpoint, $debug = false)
    {
        if (!filter_var($endpoint, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED)) {
            throw new ConnectionException('Invalid endpoint URL given.');
        }
        $this->endpoint = $endpoint;
        $this->debug = (boolean)$debug;
    }

    /**
     * Clean up.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Log in to the 1CRM service.
     * This doesn't use call() because logging in is different to all other requests.
     * @param string $username The user name
     * @param string $password A password
     * @return array
     * @throws AuthException
     * @throws ModuleException
     */
    public function login($username, $password)
    {
        //If we're logging in again, perhaps as a different user,
        //make sure we clear up any old connection
        $this->close();
        $params = array(
            'method'        => 'login',
            'input_type'    => 'JSON',
            'response_type' => 'JSON',
            'rest_data'     => json_encode(
                array(
                    'user_auth' => array(
                        'user_name' => $username,
                        'password'  => md5($password),
                    ),
                )
            )
        );
        $result = $this->request($params);
        if (!isset($result->id)) {
            $this->close();
            throw new AuthException(
                "Login failure: $result->name - $result->description."
            );
        }
        $this->sessionid = $result->id;
        $this->username = $username;
        $this->password = $password;
        //name_value_list contains a structure describing
        //modules and actions available to this user
        if (!property_exists($result->name_value_list, 'available_modules')) {
            throw new ModuleException('Module information missing');
        }
        //Translate the modules list returned by the API into something more usable
        $this->modules = array();
        foreach ($result->name_value_list->available_modules as $module) {
            if (property_exists($module, 'module_key')
                and property_exists($module, 'module_label')
            ) {
                $this->modules[$module->module_key] = array(
                    'name'  => $module->module_key,
                    'label' => $module->module_label
                );
            }
        }
        //Remove this list from the user info; no need to store it twice
        unset($result->name_value_list->available_modules);
        $this->userinfo = $result->name_value_list;

        return $result;
    }

    /**
     * Return a formatted list of what modules are available.
     * List module label and name.
     * @return string
     * @throws ModuleException
     */
    public function listModules()
    {
        if (empty($this->modules)) {
            throw new ModuleException('No module information available');
        }
        $out = '';
        foreach ($this->modules as $module) {
            $out .= $module['label'] . ' (' . $module['name'] . ")\n";
        }

        return $out;
    }

    /**
     * Check if we have logged in (i.e. that we have a session ID).
     * @throws AuthException
     * @return void
     */
    protected function checkLogin()
    {
        if (empty($this->sessionid)) {
            throw new AuthException('Not logged in.');
        }
    }

    /**
     * Is a module with this name available?
     * @param string $module This is the module name, not the translatable label
     * @return bool
     * @throws DataException
     */
    public function moduleExists($module)
    {
        return array_key_exists($module, $this->modules);
    }

    /**
     * Call a function in a module in the API.
     * @param string $module The module name
     * @param string $method The method name to call
     * @param array $params Additional parameters to pass to the method
     * @return array
     * @throws AuthException
     * @throws ModuleException
     */
    public function call($module, $method, $params = array())
    {
        $this->checkLogin();
        //Check module
        if (!$this->moduleExists($module)) {
            throw new ModuleException('Requested non-existent module.');
        }
        $params['module_name'] = $module;
        $params['session'] = $this->sessionid;
        $postfields = array(
            'method'        => $method,
            'input_type'    => 'JSON',
            'response_type' => 'JSON',
            'rest_data'     => json_encode($params)
        );

        return $this->request($postfields);
    }

    /**
     * Decode a response from the API, simplifying it into an array.
     * This is not especially flexible and may not apply to many calls,
     * but it gives a small example of how to process responses.
     * @param object $response A response object returned by the API
     * @return array
     */
    public function decodeResponse($response)
    {
        $result = array();
        foreach ($response->entry_list as $item) {
            foreach ($item->name_value_list as $field) {
                $result[] = array($field->name => $field->value);
            }
        }
        return $result;
    }

    /**
     * Get the current session ID.
     * @return string
     */
    public function getSessionID()
    {
        return $this->sessionid;
    }

    /**
     * Close the CURL instance.
     * This happens anyway when a script ends, but if we're doing multiple requests
     * over a long period it may be useful to control this manually
     * @return void
     */
    public function close()
    {
        if ($this->curl) {
            curl_close($this->curl);
            $this->curl = null;
        }
        $this->sessionid = '';
    }

    /**
     * Do a generic HTTP request.
     * @param array  $params An array of properties and values to be submitted
     * @param string $type   Which HTTP verb to use: GET, POST, PUT or DELETE, defaults to POST
     * @return mixed
     * @throws DataException
     * @throws ConnectionException
     */
    protected function request($params, $type = 'POST')
    {
        //We can re-use this curl instance without reinitialising it,
        //reducing overhead and permitting keepalive for better performance
        if (!$this->curl) {
            $this->curl = curl_init(); //Note no URL supplied here
            $cookiefile = tempnam(sys_get_temp_dir(), '1crmcookie');
            //These properties remain the same for all requests, so set them now
            curl_setopt_array(
                $this->curl,
                array(
                    CURLOPT_URL            => $this->endpoint,
                    CURLOPT_FORBID_REUSE   => false,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER         => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_ENCODING       => '',
                    CURLOPT_USERAGENT      => '1CRM PHP client version ' . self::VERSION,
                    CURLOPT_AUTOREFERER    => true,
                    CURLOPT_CONNECTTIMEOUT => 120,
                    CURLOPT_TIMEOUT        => 120,
                    CURLOPT_MAXREDIRS      => 10,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_COOKIEJAR      => $cookiefile,
                    CURLOPT_COOKIEFILE     => $cookiefile,
                    CURLOPT_HTTPHEADER     => array('Expect:'),
                    CURLOPT_VERBOSE        => $this->debug
                )
            );
        }

        if ($this->debug) {
            echo "Request params:\n";
            var_dump($params);
        }

        //Select HTTP verb
        switch ($type) {
            case 'GET':
                curl_setopt($this->curl, CURLOPT_HTTPGET, true);
                break;
            case 'PUT':
                curl_setopt($this->curl, CURLOPT_PUT, true);
                break;
            case 'DELETE':
                curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            case 'POST':
            default:
                curl_setopt($this->curl, CURLOPT_POST, true);
                break;
        }

        //Set the request parameters
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $params);

        //Do the request
        $rawresponse = curl_exec($this->curl);
        if (!$rawresponse) {
            throw new ConnectionException(
                'Request error: ' .
                curl_errno($this->curl) .
                ': ' .
                curl_error($this->curl)
            );
        }

        //Decode the entire HTTP response
        $response = self::parseResponse($rawresponse);

        //Check HTTP code
        if ($response['code'] != '200') {
            throw new ConnectionException('Response error: ' . $response['code']);
        }

        //Extract and decode the JSON data in the response body
        if (!empty($response['body'])) {
            $result = @json_decode($response['body']);
            if (!$result) {
                throw new DataException('Error decoding response.');
            }
            if ($this->debug) {
                var_dump($result);
            }

            //Return the complete decoded response
            return $result;
        } else {
            throw new DataException('Empty response.');
        }
    }

    /**
     * Parse an HTTP response into code, headers and body.
     * Returns an array in the following format which varies
     * depending on headers returned
     * @param string $response A full HTTP response including headers and body
     * @return array
     * @author Paul Ebermann <paul.ebermann@esperanto.de>
     * @link http://uk.php.net/manual/en/function.curl-setopt.php
     * @link http://www.webreference.com/programming/php/cookbook/chap11/1/3.html
     */
    protected static function parseResponse($response)
    {
        do {
            // Split response into header and body sections
            list($headers, $body) = explode("\r\n\r\n", $response, 2);
            $header_lines = explode("\r\n", $headers);

            // First line of headers is the HTTP response code
            $matches = array();
            $http_response_line = array_shift($header_lines);
            if (preg_match(
                '@^HTTP/[0-9]\.[0-9] ([0-9]{3})@',
                $http_response_line,
                $matches
            )) {
                $code = (integer)$matches[1];
            } else {
                $code = 'Error';
            }
            //Skip 1xx error codes that some MS IIS servers give
        } while (substr($code, 0, 1) == '1');

        // Put the rest of the headers in an array
        $header_array = array();
        foreach ($header_lines as $header_line) {
            list($header, $value) = explode(': ', $header_line, 2);
            $header_array[$header] = $value;
        }
        return array('code' => $code, 'header' => $header_array, 'body' => $body);
    }

    /**
     * Escape HTML output.
     * @param string $string The string to escape
     * @return string
     */
    protected function escapeoutput($string)
    {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Exception base class.
 */
class Exception extends \Exception
{

}

/**
 * Thrown when curl connections fail: DNS failure, HTTP timeout etc.
 */
class ConnectionException extends Exception
{

}

/**
 * Thrown by calls to modules that don't exist.
 */
class ModuleException extends Exception
{

}

/**
 * Thrown when nonsensical data is encountered,
 * such as when responses are not valid JSON.
 */
class DataException extends Exception
{

}

/**
 * Thrown when login fails or session has expired.
 */
class AuthException extends Exception
{

}
