<?php

class reolinkAPI {
    private $ip;
    private $port;
    private $token;
    private $tokenexp;
    private $user;
    private $password;

    private $is_loggedin;


    public function __construct(array $cnxinfo) {
        $this->ip = trim($cnxinfo['adresseIP']);
        $this->port = trim($cnxinfo['port']);

        if ($cnxinfo['token'] == NULL) {
          $this->$is_loggedin = false;
          $this->token = NULL;
          $this->user = trim($cnxinfo['username']);
          $this->password = trim($cnxinfo['password']);
        } else {
          $this->$is_loggedin = true;
          $this->token = trim($cnxinfo['token']);
        }
    }


    /*public function __destruct() {
        if ($this->is_loggedin) {
            $this->logout();
        }
    }*/

    private function request($cmd, $payload) {
        $ch = curl_init();
        $url = "http://$this->ip/cgi-bin/api.cgi?cmd=$cmd";
        log::add('reolink', 'debug', 'Request URL => ' . $url );
        curl_setopt($ch, CURLOPT_URL, $url );
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
           'Content-Type: application/json',
           'Content-Length: ' . strlen($payload))
         );
        $response = curl_exec($ch);
        curl_close($ch);
        log::add('reolink', 'debug', 'Réponse caméra  = ' . print_r($response, true));
        return $response;
    }

    /**
     * Login to the camera.
     * @return boolean a boolean indicating if the login was successful
     */
    public function login() {
        log::add('reolink', 'debug', 'Camera login...');

        if ($this->is_loggedin) {
            log::add('reolink', 'debug', "OK > Déjà connecté");
            return true;
        }

        $loginParameters = array( "User" =>
                                array('userName' => $this->user,
                                      'password' => $this->password)
                                );
        // query camera with parameters and return true if successful else false
        $response = $this->SendCMD('Login', $loginParameters);

        log::add('reolink', 'debug', 'Retour SENDCMD = ' . print_r($response, true));

        if (is_array($response)) {
            $this->token = $response['name'];
            return $response;
        } else {
            return false;
        }
    }

    /**
    * Logout form camera.
    * @return boolean a boolean indicating if the logout was successful
    */
    public function logout() {
        if (!$this->is_loggedin) {
            log::add('reolink', 'debug', "Logout déjà OK");
            return true;
        }

        $response = $this->SendCMD('Logout', array());

        if (is_array($response) && $response['rspCode'] == 200) {
            $this->is_loggedin = false;
            $this->token = '';
            $this->$tokenexp = '';
            log::add('reolink', 'debug', 'Logout OK');
            return true;
        } else {
            return false;
        }

        if ($this->checkResponse($this->SendCMD('Logout', $logoutParameters))) {
        } else {
            return false;
        }
    }

    public function SendCMD($APIRequest, $parameters) {
        // if the request is not of type login and login as not been called yet, return false
        /*if (!strcmp($APIRequest, 'Login') && !$this->is_loggedin) {
            log::add('reolink', 'debug', "Commande non envoyé > Non connecté");
            return false;
        }*/

        $action = 0;
        $params = [
                'cmd' => $APIRequest,
                'action' => $action,
                'param' => $parameters
            ];

        $paramtoSend = json_encode([0 => $params]);
        log::add('reolink', 'debug', 'SendCMD '.print_r($paramtoSend, true));

        // Send Request
        $response = $this->request($APIRequest.'&token='.$this->token, $paramtoSend);
        return $this->checkResponse($response, $APIRequest);
    }

    public function ReadFile($file) {
      $filetoread = realpath(dirname(__FILE__) . '/../resources/data').'/'.$file;

      log::add('reolink', 'debug', 'Lecture du fichier : '.$filetoread);
        // Save Folder/Files structures to file
        try {
          $filecontent = file_get_contents($filetoread);
          return $filecontent;
        } catch (Exception $e) {
            log::add('reolink', 'debug', 'Erreur : '.$e->getMessage());
        }
    }

    public function SaveFile($file, $content) {
      $filetosave = realpath(dirname(__FILE__) . '/../resources/data').'/'.$file;

      //log::add('reolink', 'debug', 'Chemin de la sauvegarde : '.$filetosave);
      //log::add('reolink', 'debug', 'Content to save : '.print_r($content, true));
        // Save Folder/Files structures to file
        try {
            if (file_put_contents($filetosave, $content)) {
              log::add('reolink', 'debug', 'Ecriture du fichier OK '.$filetosave);
            } else {
              log::add('reolink', 'debug', 'Ecriture du fichier NOK '.$filetosave);
            }
            chmod($filetosave, 0770);
        } catch (Exception $e) {
            log::add('reolink', 'debug', 'Erreur : '.$e->getMessage());
        }
    }

    /*
    Check Response validity
    */
    private function checkResponse($response, $command)  {
        $data = json_decode($response, true);

        // General Case
        if (!$data) {
            log::add('reolink', 'error', 'Erreur lecture réponse');
            return false;
        }

        if (!isset($data[0])) {
            log::add('reolink', 'error', 'Erreur réponse vide');
            return false;
        }

        if (isset($data[0]['error']['detail'])) {
            log::add('reolink', 'debug', 'Echec de la requête (détail : '.$data[0]['error']['detail'].')');
            return false;
        }

        // Depending Command Reponse is different
        switch ($command) {
            case 'Login':
                return $data[0]['value']['Token'];
            // Caméra Informations
            case 'GetDevInfo':
                return $data[0]['value']['DevInfo'];
            case 'GetDevName':
                return $data[0]['value']['DevName'];
            case 'GetAbility':
                return $data[0]['value']['Ability'];
            // Camera PTZ
            case 'GetPtzPreset':
                return $data[0]['value']['PtzPreset'];
            // Caméra Lights
            case 'GetIrLights':
                return $data[0]['value']['IrLights'];
            case 'GetPowerLed':
                return $data[0]['value']['GetPowerLed'];
            case 'GetWhiteLed ':
                return $data[0]['value']['GetWhiteLed'];
            // System
            case 'CheckFirmware ':
                return $data[0]['value']['newFirmware'];
            // 68x API Set Identical Reponse with => "value" : "rspCode" : 200
            default:
                return json_decode($response)[0]->value->rspCode;
        }
    }
}
