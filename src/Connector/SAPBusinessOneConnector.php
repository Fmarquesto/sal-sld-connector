<?php
namespace App\Connector;

use Exception;

class SAPBusinessOneConnector
{
    private $sessionID = '';
    private $routeID = '';
    private $curl;
    private $headers = [];
    private $sldUser = '';
    private $sldPassword = '';
    private $sldCompanyDB = '';
    private $sldHost = '';
    private $sldPort = '';
    private $loggedIn = false;
    private $loginMsg = '';

    /**
     * SAPBusinessOneConnector constructor.
     * @param $sldUser
     * @param $sldPassword
     * @param $sldCompanyDB
     * @param $sldHost
     * @param int $sldPort
     */
    function __construct($sldUser, $sldPassword, $sldCompanyDB, $sldHost, $sldPort = 50000)
    {
        $this->sldUser = $sldUser;
        $this->sldPassword = $sldPassword;
        $this->sldCompanyDB = $sldCompanyDB;
        $this->sldHost = $sldHost;
        $this->sldPort = $sldPort;
    }

    /**
     * Release curl
     */
    function __destruct()
    {
        if(!is_null($this->curl))
            curl_close($this->curl);
    }

    /**
     * @return bool
     * return true on succes login
     * return false on error login in
     * if false, a msg in $loginMsg will be saved
     */
    public function login()
    {
        try{
            $this->setCurl();
            curl_setopt($this->curl,  CURLOPT_POST,  true);
            curl_setopt($this->curl,  CURLOPT_POSTFIELDS,  json_encode($this->getConnectionParam()));
            curl_setopt($this->curl,  CURLOPT_HEADERFUNCTION,  function($curl,  $string)  use  (&$routeId){
                $len  =  strlen($string);
                if(substr($string,  0,  10)  ==  "Set-Cookie"){

                    preg_match("/ROUTEID=(.+);/",  $string,  $match);
                    if(count($match)  ==  2){
                        $routeId  =  $match[1];
                    }
                }
                return  $len;
            });

            $response = $this->makeRequest('Login');
            $this->routeID = $routeId;
            if($response === false)
                throw new Exception('Can\'t connect with SAP B1',400);

            $response = json_decode($response,true);
            $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
            $this->processLogin($response,$httpCode);
        }catch (Exception $e){
            $this->loggedIn = false;
            $this->loginMsg = $e->getMessage();
        }
        return $this->loggedIn;
    }

    /**
     * @return string
     */
    public function getLoginErrorMsg()
    {
        return $this->loginMsg;
    }

    /**
     * Just send a request to logout of SAPB1
     */
    public function logout()
    {
        $this->setCurl();
        $response = $this->makeRequest('Logout');
        $this->resetConnector();
    }

    /**
     * @param $url
     * @return array|mixed
     * @throws Exception
     * $url must be a valid url from SAPB1 service layer
     */
    public function get($url)
    {
        $this->setCurl();
        $response = $this->makeRequest($url);
        return $this->processResponse($response);
    }

    /**
     * @param $url
     * @param $params
     * @param false $trueResponse
     * @return array|mixed
     * @throws Exception
     */
    public function post($url,$params, $trueResponse = false)
    {
        $this->setCurl();
        curl_setopt($this->curl,  CURLOPT_POST,  true);
        if(!empty($params))
            curl_setopt($this->curl,  CURLOPT_POSTFIELDS,  $params);

        if($trueResponse)
            curl_setopt($this->curl, CURLOPT_HEADER, 1);

        $response = $this->makeRequest($url);
        return $this->processResponse($response,$trueResponse);
    }

    /**
     * @param $url
     * @param $params
     * @return array|mixed
     * @throws Exception
     */
    public function patch($url,$params)
    {
        $this->setCurl();
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($this->curl,  CURLOPT_POSTFIELDS,  json_encode($params));
        $response = $this->makeRequest($url);
        return $this->processResponse($response);
    }

    /**
     * @param $header
     * Potencially to add header to manipulate request to SAPB1
     */
    public function addHeader($header)
    {
        $this->headers[] = $header;
    }

    /**
     * Init curl and set basic options
     */
    private function setCurl()
    {
        if(!is_null($this->curl)){
            curl_reset($this->curl);
        }else{
            $this->curl  =  curl_init();
        }
        curl_setopt($this->curl,  CURLOPT_RETURNTRANSFER,  true);
        curl_setopt($this->curl,  CURLOPT_SSL_VERIFYPEER,  false);
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($this->curl,  CURLOPT_VERBOSE,  FALSE);
        curl_setopt($this->curl, CURLINFO_HEADER_OUT, true);
    }

    /**
     * @return array
     * basic login json body
     */
    private function getConnectionParam()
    {
        return [
            'UserName'=>$this->sldUser,
            'Password'=>$this->sldPassword,
            'CompanyDB'=>$this->sldCompanyDB
        ];
    }

    /**
     * @param $url
     * @return bool|string
     */
    private function makeRequest($url)
    {
        curl_setopt($this->curl,  CURLOPT_URL,  $this->sldHost  .  ($this->sldPort!=''? ":$this->sldPort" : "") . "/b1s/v1/" .$url);
        if(!empty($this->headers))
            curl_setopt($this->curl,  CURLOPT_HTTPHEADER,  $this->headers);
        $response = curl_exec($this->curl);
        return $response;
    }

    /**
     * @param $response
     * @param $httpCode
     * @throws Exception
     */
    private function processLogin($response,$httpCode){

        if($httpCode !=200){
            if(isset($response['error']['message']['value'])){
                $error = $response['error']['message']['value'];
            }else{
                $error = "Unexpected error while login in to SAPB1: ".print_r($response,true);
            }
            throw new Exception($error,$httpCode);
        }else{
            if(isset($response['SessionId'])){
                $this->sessionID =$response['SessionId'];
                $this->headers[] =  "Cookie: B1SESSION="  .  $this->sessionID  .  "; ROUTEID="  .  $this->routeID  .  ";";
                $this->headers[] = "Expect:";
                $this->loggedIn = true;
            }else{
                $error = "An error occurs while getting SessionId: ".print_r($response,true);
                throw new Exception($error,$httpCode);
            }
        }
    }

    /**
     * @param $response
     * @param false $trueResponse
     * @return array|mixed
     * @throws Exception
     */
    private function processResponse($response,$trueResponse = false)
    {
        $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
        if(strpos($httpCode,'2')===0){
            if($trueResponse){
                $header_size = curl_getinfo($this->curl, CURLINFO_HEADER_SIZE);
                $header = substr($response, 0, $header_size);
                $headers = explode("\n",$header);
                $body = substr($response, $header_size);
                return ['STATUS_CODE'=>$httpCode,'RESPONSE'=>$body,'HEADERS'=>$headers];
            }
            $response = json_decode($response,true);
            return $response;
        }else{
            $error = $this->processResponseError($response);
            throw new Exception($error,$httpCode);
        }
    }

    /**
     * @param $response
     * @return mixed|string
     */
    private function processResponseError($response)
    {
        $reponseError = json_decode($response,true);
        $error = 'An error has occurred';
        if(isset($reponseError['error']['message']['value']))
            $error = $reponseError['error']['message']['value'];
        return $error;
    }

    /**
     * Reset of all important things about connection with SAPB1
     */
    private function resetConnector()
    {
        $this->headers = [];
        $this->routeID = '';
        $this->sessionID = '';
        $this->loggedIn = false;
        $this->loginMsg = '';
    }
}