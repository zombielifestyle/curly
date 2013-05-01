<?php

class Curly {

    protected $events = array();
    public $cookieFile = '/tmp/cookiejar';
    public $options = array();

    function __construct($options = array()) {
        $this->options = array(
            CURLOPT_AUTOREFERER => true,
            CURLOPT_FOLLOWLOCATION => true,
            //CURLOPT_COOKIESESSION => true,
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER, array('Expect:'),
        ) + $options;
    }

    function parseHeaders($str) {
        $headers = array();
        $rows = explode("\r\n", $str);
        $headers['status'] = array(array_shift($rows));
        foreach ($rows as $row) {
            list($key, $value) = explode(':', $row, 2);
            $key = trim(strtolower($key));
            $value = trim($value);
            if (isset($headers[$key])){
                $headers[$key][] = $value;
            } else {
                $headers[$key] = array($value);
            }
        }
        foreach ($headers as &$row) {
            if (count($row) == 1) {
                $row = reset($row);
            }
        }
        return $headers;
    }

    function parseCookies($cookieData) {
        $keys = array('domain','flag','path','secure','expiration','name','value');
        $cookies = array();
        $rows = explode("\n", $cookieData);
        foreach ($rows as $row) {
            $row = preg_replace('~#HttpOnly_~i', '', $row, -1, $httpOnly);
            if (!empty($row) && !preg_match('%^\s*#%', $row)) {
                $cols = explode("\t", $row);
                $data = array_combine($keys, $cols);
                $data['httponly'] = (bool)$httpOnly;
                $cookies[$data['name']] = $data;
            }
        }
        return $cookies;
    }

    function parseResponse($response) {
        if (!$response || !strpos($response, "\r\n\r\n")) {
            return array();
        }
        $splitResponse = explode("\r\n\r\n", $response);
        $redirects = array();
        do {
            if (isset($headers)) {
                $redirects[] = $headers;
            }
            $headers = $this->parseHeaders(array_shift($splitResponse));
        } while (strpos($splitResponse[0], 'HTTP') === 0);
        $body = implode("\r\n\r\n", $splitResponse);
        return array(
            'headers' => $headers,
            'redirects' => $redirects,
            'body' => $body,
        );
    }

    function request($options = array()) {
        $c = curl_init();
        curl_setopt_array($c, $this->options + $options);
        $response = curl_exec($c);
        if (curl_errno($c)) {
            throw new Exception(curl_error($c), curl_errno($c));
        }
        $parsedResponse = $this->parseResponse($response);
        $parsedResponse['info'] = curl_getinfo($c);
        curl_close($c);
        $parsedResponse['cookies'] = array();
        if (is_file($this->cookieFile)) {
            $parsedResponse['cookies'] = $this->parseCookies(file_get_contents($this->cookieFile));
        }
        $this->emit('response', array($parsedResponse));
        return $parsedResponse;
    }

    function get($url) {
        return $this->request(array(
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'GET',
        ));
    }

    function post($url, $data = array()) {
        return $this->request(array(
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $data,
        ));
    }

    function put($url, $data = array()) {
        return $this->request(array(
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $data,
        ));
    }

    function on($event, $f) {
        $this->events[$event] = $f;
    }

    function emit($event, $args) {
        if (isset($this->events[$event])) {
            call_user_func_array($this->events[$event], $args);
        }
    }

}
