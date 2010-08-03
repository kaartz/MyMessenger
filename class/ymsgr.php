<?php
/**
 * Instant Messenger I-List
 */

class ymsgr 
{
    private $dbh;
    private static $dsn = 'mysql:host=localhost;dbname=im';
    private static $user = 'root';
    private static $pass = '';
    private static $instance;
    private $_consumerkey;
    private $_consumersecret;
    private $_token;
    private $_a_token;
    private $_a_token_secret;
    private $_guid;
    private $_sessid;
    private $_usrn;
    private $_usrp;
    private $_surl = 'http://developer.messenger.yahooapis.com'; 
    private $_aurl = 'https://login.yahoo.com/WSLogin/V1/get_auth_token';
    private $_aaurl = 'https://api.login.yahoo.com/oauth/v2/get_token';
    private $_rurl = 'http://rcore1.messenger.yahooapis.com';
    private $_kurl = 'http://rproxy1.messenger.yahooapis.com';
    private function __construct()
    {
        try {
            $this->dbh = new PDO(self::$dsn,self::$user,self::$pass);
            }
        catch (PDOException $e) {
            echo $e->getMessage();
        }
        
        return $this->dbh;
    }

    public static function getInsta()
    {
        if(!isset(self::$instance)) {
            $object = __CLASS__;
            self::$instance = new $object;
        }
        return self::$instance;
    }

    public function setkeys($cons_key, $cons_sec) 
    {
        $this->_consumerkey = $cons_key;
        $this->_consumersecret = $cons_sec;
    }

    public function gettoken($usrname, $passwd) 
    {
        $this->_usrn = $usrname;
        $this->_usrp = $passwd;
        
        $resu = $this->dbh->prepare("SELECT status FROM yimkeys WHERE status = 0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        if ($resu->execute()) {
            $upa = $this->dbh->prepare("UPDATE yimkeys SET status=1 WHERE status=0");
            $upa->execute();
        } else {
            unset($resu);
        }

        $fields = array(
                        'oauth_consumer_key'=>$this->_consumerkey,
                        'login'=>$this->_usrn,
                        'passwd'=>$this->_usrp
                       );
        $fieldsstr = "";
        $pfields = "";

        foreach ($fields as $key => $value) {
            $fieldsstr .= $key . "=" . $value . "&";
        }
        $fieldsstr = rtrim($fieldsstr, "&");
        
        $response = $this->do_post($this->_aurl, $fieldsstr);
         
        $this->_a_token = explode("=", $response[2], 2);

        $postF = array(
                       'oauth_consumer_key'=>urlencode($this->_consumerkey),
                       'oauth_signature_method'=>'PLAINTEXT',
                       'oauth_nonce'=>mt_rand(),
                       'oauth_timestamp'=>time(),
                       'oauth_version'=>'1.0',
                       'oauth_token'=>urlencode($this->_a_token[1]),
                       'oauth_signature'=>urlencode($this->_consumersecret . '&')
                      );

        $header[] = 'Content-type: application/x-www-form-urlencoded;charset=UTF-8';

        foreach($postF as $k=>$v) {
            $pfields .= $k . "=" . $v . '&';
        }
        $pfields = rtrim($pfields, '&');
        
        $response = $this->do_post($this->_aaurl, $pfields, $header);

        $out = explode('&', $response[2]);
         
        foreach ($out as $val) {
            $sepa = explode('=', $val, 2);
            $dati[$sepa[0]] = urldecode($sepa[1]);
        }

        $query = "INSERT INTO yimkeys (atk, atks, ses, guid) VALUES (\"$dati[oauth_token]\", \"$dati[oauth_token_secret]\", \"$dati[oauth_session_handle]\", \"$dati[xoauth_yahoo_guid]\")";

        $upd = $this->dbh->exec($query);
    }

    public function reftoken()
    {
        $pfields ='';

        $query = "SELECT atk, atks, ses FROM yimkeys WHERE status=0";
        $resu = $this->dbh->query($query);
        $resu->setFetchMode(PDO::FETCH_ASSOC);
        $toks = $resu->fetch();

        $postF = array(
                       'oauth_consumer_key'=>urlencode($this->_consumerkey),
                       'oauth_signature_method'=>'PLAINTEXT',
                       'oauth_nonce'=>mt_rand(),
                       'oauth_timestamp'=>time(),
                       'oauth_version'=>'1.0',
                       'oauth_token'=>urlencode($toks['atk']),
                       'oauth_session_handle'=>urlencode($toks['ses']),
                       'oauth_signature'=>urlencode($this->_consumersecret . '&' . $toks['atks'])
                      );

        $header[] = 'Content-type: application/x-www-form-urlencoded;charset=UTF-8';

        foreach($postF as $k=>$v) {
            $pfields .= $k . "=" . $v . '&';
        }
        $pfields = rtrim($pfields, '&');
         
        $response = $this->do_post($this->_aaurl, $pfields, $header);
        
        $out = explode('&', $response[2]);
         
        foreach ($out as $val) {
            $sepa = explode('=', $val, 2);
            $dati[$sepa[0]] = urldecode($sepa[1]);
        }
        
        $resu = $this->dbh->prepare('UPDATE yimkeys SET status=1 WHERE atks = ?');
        $resu->execute(array($toks['atks']));
        unset($resu);

        $resu = $this->dbh->prepare('INSERT INTO yimkeys (atk, atks, ses, guid) VALUES (:atk, :atks, :ses, :guid)');
        $resu->bindParam(':atk', $dati['oauth_token']);
        $resu->bindParam(':atks', $dati['oauth_token_secret']);
        $resu->bindParam(':ses', $dati['oauth_session_handle']);
        $resu->bindParam(':guid', $dati['xoauth_yahoo_guid']);
        $resu->execute();
        unset($resu);
    }

    public function login() {
        
        $resu = $this->dbh->query("SELECT atk, atks FROM yimkeys WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();

        $params['oauth_version'] = '1.0';
        $params['oauth_consumer_key'] = $this->_consumerkey;
        $params['oauth_nonce'] = mt_rand();
        $params['oauth_timestamp'] = time();
        $params['oauth_token'] = $toks[0];
        $params['oauth_signature_method'] = 'PLAINTEXT';
        $params['oauth_signature'] = $this->plaintext_sig($this->_consumersecret, $toks[1]);
        
        $header = $this->build_oauth_header($params, "yahooapis.com");
         
        $headers[] = $header;
        $req_url = $this->_surl . '/v1/session?notifyServerToken=1';
        $headers[] = 'Content-Type: application/json;charset=utf-8';
        
        $pbody = '{"presenceState":0,"presenceMessage":"Testing the BOT"}';
        
        $response = $this->do_post($req_url, $pbody, $headers);

        $sesi = json_decode($response[2], true);
        
        $resu = $this->dbh->prepare("UPDATE login SET status=1 WHERE status=0");
        $resu->execute();
        unset($resu);

        $resu = $this->dbh->prepare("INSERT INTO login (sess) VALUE (:sess)");
        $resu->bindParam(":sess", $sesi['sessionId']);
        $toks = $resu->execute();
        print_r($response);
    }

    public function logoff()
    {
        $resu = $this->dbh->query("SELECT sess FROM login WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();
        $this->_sessid = $toks[0];
        unset($toks);
        
        $resu = $this->dbh->query("SELECT atk, atks FROM yimkeys WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();
        
        $params['oauth_version'] = '1.0';
        $params['oauth_consumer_key'] = $this->_consumerkey;
        $params['oauth_nonce'] = mt_rand();
        $params['oauth_timestamp'] = time();
        $params['oauth_token'] = $toks[0];
        $params['oauth_signature_method'] = 'PLAINTEXT';
        $params['oauth_signature'] = $this->plaintext_sig($this->_consumersecret, $toks[1]);
        
        $header = $this->build_oauth_header($params, "yahooapis.com");
         
        $headers[] = $header;
        $req_url = $this->_rurl . '/v1/session?_method=delete&sid=' . $this->_sessid ;
        $headers[] = 'Content-Type: application/json;charset=utf-8';
        
        $post = ''; 
        $response = $this->do_post($req_url, $post, $headers);
        
        //return $response[0]['http_code'];
        return $response;
    } 

    public function online()
    {
        $resu = $this->dbh->query("SELECT sess FROM login WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();
        $this->_sessid = $toks[0];
        unset($toks);
        
        $resu = $this->dbh->query("SELECT atk, atks FROM yimkeys WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();
        
        $params['oauth_version'] = '1.0';
        $params['oauth_consumer_key'] = $this->_consumerkey;
        $params['oauth_nonce'] = mt_rand();
        $params['oauth_timestamp'] = time();
        $params['oauth_token'] = $toks[0];
        $params['oauth_signature_method'] = 'PLAINTEXT';
        $params['oauth_signature'] = $this->plaintext_sig($this->_consumersecret, $toks[1]);
        
        $header = $this->build_oauth_header($params, "yahooapis.com");
         
        $headers[] = $header;
        $req_url = $this->_rurl . '/v1/contacts?sid=' . $this->_sessid . '&fields=%2Bpresence' ;
        $headers[] = 'Content-Type: application/json;charset=utf-8';
        
        $post = ''; 
        $response = $this->do_get($req_url, 80, $headers);
        
        //return $response[0]['http_code'];
        return $response;
    } 
    
    public function get()
    {
        $rmsg = array();

        $resu = $this->dbh->query("SELECT sess FROM login WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();
        $this->_sessid = $toks[0];
        unset($toks);

        $resu = $this->dbh->query("SELECT atk, atks FROM yimkeys WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();

        $params['oauth_version'] = '1.0';
        $params['oauth_consumer_key'] = $this->_consumerkey;
        $params['oauth_nonce'] = mt_rand();
        $params['oauth_timestamp'] = time();
        $params['oauth_token'] = $toks[0];
        $params['oauth_signature_method'] = 'PLAINTEXT';
        $params['oauth_signature'] = $this->plaintext_sig($this->_consumersecret, $toks[1]);
        $params['sid'] = $this->_sessid;
        $params['seq'] = 0;
        $params['count'] = 100;

        $qstr = $this->oauth_http_build_query($params);

        $header = $this->build_oauth_header($params, "yahooapis.com");
        
        $headers[] = $header;
        $req_url = $this->_rurl . '/v1/notifications?' . $qstr;
        
        $headers[] = 'Content-Type: application/json;charset=utf-8';
        
        $response = $this->do_get($req_url, 80);
                 
        $respi = get_object_vars(json_decode($response[2]));
        
        if ($response[0]['http_code'] == "200") {
            $i = 1;
            foreach ($respi['responses'] as $obj) {
                $obi = get_object_vars($obj);
                if (array_key_exists('message', $obi)) {
                    $msg = $obi['message'];
                    $rmsg['msg' . $i] = get_object_vars($msg);
                    $i++;
                } else if (array_key_exists('buddyAuthorize', $obi)) {
                    $baut = $obi['buddyAuthorize'];
                    $rmsg['br' . $i] = get_object_vars($baut);
                    $i++;
                }
            }
            if ($rmsg) { return $rmsg; } else { return $response[0]['http_code'];} 
        } else {
            return $response[0]['http_code']; 
        }
    }

    public function send($to, $msg)
    {
        $resu = $this->dbh->query("SELECT sess FROM login WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();
        $this->_sessid = $toks[0];
        unset($toks);

        $resu = $this->dbh->query("SELECT atk, atks FROM yimkeys WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();

        $params['oauth_version'] = '1.0';
        $params['oauth_consumer_key'] = $this->_consumerkey;
        $params['oauth_nonce'] = mt_rand();
        $params['oauth_timestamp'] = time();
        $params['oauth_token'] = $toks[0];
        $params['oauth_signature_method'] = 'PLAINTEXT';
        $params['oauth_signature'] = $this->plaintext_sig($this->_consumersecret, $toks[1]);

        $header = $this->build_oauth_header($params, "yahooapis.com");
         
        $headers[] = $header;
        $req_url = $this->_rurl . '/v1/message/yahoo/' . $to . '?sid=' . $this->_sessid;
        $posF = '{"message":"' . $msg . '"}';
         
        $headers[] = 'Content-Type: application/json;charset=utf-8';
        
        $response = $this->do_post($req_url, $posF, $headers);
        
        //return $response[0]['http_code'];
        return $response;
    }

    public function buddy_add($budd)
    {
        $resu = $this->dbh->query("SELECT sess FROM login WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();
        $this->_sessid = $toks[0];
        unset($toks);

        $resu = $this->dbh->query("SELECT atk, atks FROM yimkeys WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();

        $params['oauth_version'] = '1.0';
        $params['oauth_consumer_key'] = $this->_consumerkey;
        $params['oauth_nonce'] = mt_rand();
        $params['oauth_timestamp'] = time();
        $params['oauth_token'] = $toks[0];
        $params['oauth_signature_method'] = 'PLAINTEXT';
        $params['oauth_signature'] = $this->plaintext_sig($this->_consumersecret, $toks[1]);
        
        $header = $this->build_oauth_header($params, "yahooapis.com");
               
        $headers[] = $header;

        $req_url = $this->_rurl . '/v1/buddyrequest/yahoo/' . $budd . '?sid=' . $this->_sessid;
        
        $headers[] = 'Content-Type: application/json;charset=utf-8';
        
        $pbody = '{"authReason":"Hi' . $budd . ', welcome to ESP, Bangalore"}';
        
        $response = $this->do_post($req_url, $pbody, $headers);

        print_r($response);
    }

    public function buddy_auth($budd)
    {
        $resu = $this->dbh->query("SELECT sess FROM login WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();
        $this->_sessid = $toks[0];
        unset($toks);

        $resu = $this->dbh->query("SELECT atk, atks FROM yimkeys WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();

        $params['oauth_version'] = '1.0';
        $params['oauth_consumer_key'] = $this->_consumerkey;
        $params['oauth_nonce'] = mt_rand();
        $params['oauth_timestamp'] = time();
        $params['oauth_token'] = $toks[0];
        $params['oauth_signature_method'] = 'PLAINTEXT';
        $params['oauth_signature'] = $this->plaintext_sig($this->_consumersecret, $toks[1]);
        
        $header = $this->build_oauth_header($params, "yahooapis.com");
               
        $headers[] = $header;

        $req_url = $this->_rurl . '/v1/buddyauthorization/yahoo/'. $budd . '?sid=' . $this->_sessid;
        
        $headers[] = 'Content-Type: application/json;charset=utf-8';
        
        $pbody = '{"authReason":"Hi' . $budd . ', welcome to ESP, Bangalore"}';
        
        $response = $this->do_post($req_url, $pbody, $headers);

        print_r($response);
    }

    public function presence()
    {
        $resu = $this->dbh->query("SELECT sess FROM login WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();
        $this->_sessid = $toks[0];
        unset($toks);

        $resu = $this->dbh->query("SELECT atk, atks FROM yimkeys WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();

        $params['oauth_version'] = '1.0';
        $params['oauth_consumer_key'] = $this->_consumerkey;
        $params['oauth_nonce'] = mt_rand();
        $params['oauth_timestamp'] = time();
        $params['oauth_token'] = $toks[0];
        $params['oauth_signature_method'] = 'PLAINTEXT';
        $params['oauth_signature'] = $this->plaintext_sig($this->_consumersecret, $toks[1]);
        
        $header = $this->build_oauth_header($params, "yahooapis.com");
               
        $headers[] = $header;

        $req_url = $this->_rurl . '/v1/presence?_method=put&sid=' . $this->_sessid;
        
        $headers[] = 'Content-Type: application/json;charset=utf-8';
        
        $pbody = '{"presenceState":0,"presenceMessage":"Good Morning"}';
        
        $response = $this->do_post($req_url, $pbody, $headers);

        print_r($response);
    }

    public function keepalive()
    {
        $resu = $this->dbh->query("SELECT sess FROM login WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();
        $this->_sessid = $toks[0];
        unset($toks);

        $resu = $this->dbh->query("SELECT atk, atks FROM yimkeys WHERE status=0");
        $resu->setFetchMode(PDO::FETCH_NUM);
        $toks = $resu->fetch();

        $params['oauth_version'] = '1.0';
        $params['oauth_consumer_key'] = $this->_consumerkey;
        $params['oauth_nonce'] = mt_rand();
        $params['oauth_timestamp'] = time();
        $params['oauth_token'] = $toks[0];
        $params['oauth_signature_method'] = 'PLAINTEXT';
        $params['oauth_signature'] = $this->plaintext_sig($this->_consumersecret, $toks[1]);
        
        $header = $this->build_oauth_header($params, "yahooapis.com");
               
        $headers[] = $header;

        $req_url = $this->_kurl . '/v1/pushchannel/esp_blr?sid=' . $this->_sessid . '&seq=0&format=json';
        
        $headers[] = 'Connection: keep-alive';        
        $headers[] = 'Content-Type: application/json;charset=utf-8';
        $headers[] = 'Cookie: IM=2arc.LQX9SsZIP3aCUlEhufve9I70HAOnBui8S3JtyrVWM8_xgt_jEi9l0zT5CA_NAwLTD5O58syHNhc-|JZ_oASTkxNcvM3geELVy5w--';
        
        $response = $this->do_get($req_url, 80, $headers, true);

        print_r($response);
    }

    private function do_get($url, $port=80, $headers=NULL, $to=false)
    {
        $retarr = array();  // Return value

        $curl_opts = array(CURLOPT_URL => $url,
                           CURLOPT_PORT => $port,
                           CURLOPT_POST => false,
                           CURLOPT_SSL_VERIFYHOST => false,
                           CURLOPT_SSL_VERIFYPEER => false,
                           CURLOPT_RETURNTRANSFER => true);
        
        if ($headers) { $curl_opts[CURLOPT_HTTPHEADER] = $headers; }
        if ($to) { $curl_opts[CURLOPT_TIMEOUT] = 120; }
        
        $response = $this->do_curl($curl_opts);

        if (! empty($response)) { $retarr = $response; }

        return $retarr;
    }

    private function plaintext_sig($consumer_secret, $token_secret)
    {
          return $consumer_secret . '&' . $token_secret;
    }

    private function hmac_sig($http_method, $url, $params, $consumer_secret, $token_secret)
    {
        $base_string = $this->signature_base_string($http_method, $url, $params);
        $signature_key = $this->rfc_encode($consumer_secret) . '&' . rfc_encode($token_secret);
        $sig = base64_encode(hash_hmac('sha1', $base_string, $signature_key, true));
        return $sig;
    }

    private function signature_base_string($http_method, $url, $params)
    {
        // Decompose and pull query params out of the url
        $query_str = parse_url($url, PHP_URL_QUERY);
        if ($query_str) {
            $parsed_query = $this->oauth_parse_str($query_str);
            // merge params from the url with params array from caller
            $params = array_merge($params, $parsed_query);
        }

        // Remove oauth_signature from params array if present
        if (isset($params['oauth_signature'])) {
            unset($params['oauth_signature']);
        }

        // Create the signature base string. Yes, the $params are double encoded.
        $base_string = $this->rfc_encode(strtoupper($http_method)) . '&' .
                    $this->rfc_encode(normalize_url($url)) . '&' .
                    $this->rfc_encode(oauth_http_build_query($params));


        return $base_string;
    }

    private function build_oauth_header($params, $realm='')
    {
        $header = 'Authorization: OAuth realm="' . $realm . '"';
        foreach ($params as $k => $v) {
            if (substr($k, 0, 5) == 'oauth') {
                $header .= ',' . urlencode($k) . '="' . urlencode($v) . '"';
            }
        }
    return $header;
    }

    private function oauth_parse_str($query_string)
    {
        $query_array = array();

        if (isset($query_string)) {

        // Separate single string into an array of "key=value" strings
        $kvpairs = explode('&', $query_string);

        // Separate each "key=value" string into an array[key] = value
        foreach ($kvpairs as $pair) {
            list($k, $v) = explode('=', $pair, 2);

        // Handle the case where multiple values map to the same key
        // by pulling those values into an array themselves
            if (isset($query_array[$k])) {
            // If the existing value is a scalar, turn it into an array
                if (is_scalar($query_array[$k])) {
                    $query_array[$k] = array($query_array[$k]);
                }
                    array_push($query_array[$k], $v);
                } else {
                    $query_array[$k] = $v;
                }
            }
        }
        return $query_array;
    }

    private function oauth_http_build_query($params, $excludeOauthParams=false)
    {
        $query_string = '';
        if (! empty($params)) {

            // rfc3986 encode both keys and values
            $keys = $this->rfc_encode(array_keys($params));
            $values = $this->rfc_encode(array_values($params));
            $params = array_combine($keys, $values);
            
            // Parameters are sorted by name, using lexicographical byte value ordering.
            // http://oauth.net/core/1.0/#rfc.section.9.1.1
            uksort($params, 'strcmp');

            // Turn params array into an array of "key=value" strings
            $kvpairs = array();
            foreach ($params as $k => $v) {
                if ($excludeOauthParams && substr($k, 0, 5) == 'oauth') {
                    continue;
                }
                if (is_array($v)) {
                    // If two or more parameters share the same name,
                    // they are sorted by their value. OAuth Spec: 9.1.1 (1)
                    natsort($v);
                    foreach ($v as $value_for_same_key) {
                        array_push($kvpairs, ($k . '=' . $value_for_same_key));
                    }
                } else {
                    // For each parameter, the name is separated from the corresponding
                    // value by an '=' character (ASCII code 61). OAuth Spec: 9.1.1 (2)
                    array_push($kvpairs, ($k . '=' . $v));
                }
            }

            
            // Each name-value pair is separated by an '&' character, ASCII code 38.
            // OAuth Spec: 9.1.1 (2)
            $query_string = implode('&', $kvpairs);
        }
    return $query_string;
    }

    private function normalize_url($url)
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? $parts['port'] : "";
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        // $port = $parts['port'];
        $path = $parts['path'];

        if (! $port) {
            $port = ($scheme == 'https') ? '443' : '80';
        }
        if (($scheme == 'https' && $port != '443')
            || ($scheme == 'http' && $port != '80')) {
            $host = "$host:$port";
        }

        return "$scheme://$host$path";
    }

    private function do_post($url, $postbody=NULL, $headers=NULL)
    {
        $retarr = array();  // Return value

        $curl_opts = array(CURLOPT_URL => $url,
                           CURLOPT_POST => true,
                           CURLOPT_SSL_VERIFYHOST => false,
                           CURLOPT_SSL_VERIFYPEER => false,
                           CURLOPT_POSTFIELDS => ($postbody == NULL) ? false : $postbody,
                           CURLOPT_RETURNTRANSFER => true);

        if ($headers) { $curl_opts[CURLOPT_HTTPHEADER] = $headers; }

        $response = $this->do_curl($curl_opts);

        if (! empty($response)) { $retarr = $response; }

        return $retarr;
    }   

    private function do_curl($curl_opts)
    {
        $retarr = array();

        if (!$curl_opts) {
            return $retarr;
        }

        $ch = curl_init();

        // Set curl options that were passed in
        curl_setopt_array($ch, $curl_opts);

        //Full header
        curl_setopt($ch, CURLOPT_HEADER, true); 
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_VERBOSE, true);

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $header_sent = curl_getinfo($ch, CURLINFO_HEADER_OUT);

        //Parse
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        
        // Close curl session
        curl_close($ch);
        unset($ch);

        //Combine
        array_push($retarr, $info, $header, $body);

        //return $info;
        return $retarr;
    }

    private function rfc_encode($inp) 
    {
        if (is_array($inp)) {
            return array_map(array($this, 'rfc_encode'), $inp);
        } else if (is_scalar($inp))  {
            return str_replace('%7E', '~', rawurlencode($inp));
        } else {
            return '';
        }
    }    
}


