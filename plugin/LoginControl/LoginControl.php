<?php

global $global;
require_once $global['systemRootPath'] . 'plugin/Plugin.abstract.php';

require_once $global['systemRootPath'] . 'plugin/LoginControl/Objects/logincontrol_history.php';

class LoginControl extends PluginAbstract {

    public function getTags() {
        return array(
            PluginTags::$FREE,
            PluginTags::$SECURITY,
            PluginTags::$LOGIN,
        );
    }

    public function getDescription() {
        $desc = "LoginControl Plugin";
        $desc .= "<br><strong>Protect your account with 2-Factor Authentication</strong>: With 2-Factor Authentication, you add an extra layer of security to your account in case your password is stolen. After you set up 2-Step Verification, you'll sign in to your account in two steps using:

        <br> - Something you know, like your password
        <br> - Something you have,access to your email";
        
        $desc .= "<br><strong>Single Device Login Limitation</strong>: If you are logged in on one device and then go to log in on another, your first session will expire and you will be logged out automatically. Only admins users are ignored on this rule";
        //$desc .= $this->isReadyLabel(array('YPTWallet'));
        return $desc;
    }

    public function getName() {
        return "LoginControl";
    }

    public function getUUID() {
        return "LoginControl-5ee8405eaaa16";
    }

    public function getPluginVersion() {
        return "1.0";
    }

    public function updateScript() {
        global $global;
        /*
          if (AVideoPlugin::compareVersion($this->getName(), "2.0") < 0) {
          sqlDal::executeFile($global['systemRootPath'] . 'plugin/PayPerView/install/updateV2.0.sql');
          }
         * 
         */
        return true;
    }

    public function getEmptyDataObject() {
        $obj = new stdClass();

        $obj->singleDeviceLogin = false; // will disconnect other devices 
        $obj->enable2FA = false;
        $o = new stdClass();
        $obj->textFor2FASubject = "Confirm {siteName} log in from a new browser";
        $o->type = "textarea";
        $o->value = "Dear {user},

If you recently tried to log into your {siteName} account from {userIP} using the device {userAgent}, please complete your login by clicking {confirmationLink}.

If the login attempt was NOT done by you, secure your account by changing your {siteName} password immediately.

Best regards,

{siteName}";
        $obj->textFor2FABody = $o;

        /*
          $obj->textSample = "text";
          $obj->checkboxSample = true;
          $obj->numberSample = 5;

          $o = new stdClass();
          $o->type = array(0=>__("Default"))+array(1,2,3);
          $o->value = 0;
          $obj->selectBoxSample = $o;

         */
        return $obj;
    }

    public function getPluginMenu() {
        global $global;
        return '<a href="plugin/LoginControl/View/editor.php" class="btn btn-primary btn-sm btn-xs btn-block"><i class="fa fa-edit"></i> Edit</a>';
    }

    public function onUserSignIn($users_id) {
        if (empty($_REQUEST['confirmation'])) {
            // create the log
            self::createLog($users_id);
        }
        // check if the user confirmed this device before
        if (self::is2FAEnabled($users_id) && !self::is2FAConfirmed($users_id)) {
            header('Content-Type: application/json');
            _error_log("Login_control::onUserSignIn 2FA is required for user {$users_id}");
            if (self::send2FAEmail($users_id)) {
                User::logoff();
                $object = new stdClass();
                $u = new User($users_id);
                $to = $u->getEmail();
                $hiddenemail = self::getHiddenEmail($to);
                $object->error = __("Please check your email for 2FA confirmation ") ."<br>($hiddenemail)";
                die(json_encode($object));
            } else {
                _error_log("Login_control::onUserSignIn 2FA your email could not be sent ({$users_id})", AVideoLog::$ERROR);
                setToastMessage(__("2FA email not sent"));
            }
        }else{
            $row = self::getPreviewsLogin(User::getId());
            if(!empty($row)){
                setToastMessage(__("Last login was on ")." ".$row['ago']." (".$row['device'].")");
            }
        }
    }
    
    private static function getHiddenEmail($email){
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        $parts = explode("@", $email);
        $hiddenemail = "";        
        $part0Len = strlen($parts[0]);
        $hiddenemail = substr($parts[0], 0, 2);
        for($i=2;$i<$part0Len;$i++){
            $hiddenemail.="*";
        }
        $hiddenemail.="@";
        $part1Len = strlen($parts[1]);
        for($i=0;$i<$part1Len-4;$i++){
            $hiddenemail.="*";
        }
        $hiddenemail .= substr($parts[1], $part1Len-4);
        return $hiddenemail;
    }

    static function createLog($users_id) {
        global $loginControlCreateLog;
        if (empty($users_id)) {
            return false;
        }
        if (empty($loginControlCreateLog)) {
            $ulh = new logincontrol_history(0);
            $ulh->setIp(getRealIpAddr());
            $ulh->setStatus(self::is2FAConfirmed($users_id) ? logincontrol_history_status::$CONFIRMED : logincontrol_history_status::$WAITING_CONFIRMATION);
            $ulh->setUniqidV4(getDeviceID());
            $ulh->setUser_agent(@$_SERVER['HTTP_USER_AGENT']);
            $ulh->setUsers_id($users_id);
            $ulh->setConfirmation_code(self::getConfirmationCode($users_id, getDeviceID()));
            $loginControlCreateLog = $ulh->save();
        }
        return $loginControlCreateLog;
    }

    static function getConfirmationCode($users_id, $uniqidV4) {
        $row = logincontrol_history::getLastLoginAttempt($users_id, $uniqidV4);
        if (!empty($row) && ($row['status'] === logincontrol_history_status::$CONFIRMED || strtotime($row['modified']) > strtotime("-2 hours"))) {
            return $row['confirmation_code'];
        }else if(empty($row)){
            _error_log("LoginControl::getConfirmationCode first login attempt $users_id, $uniqidV4");
        }else{
            _error_log("LoginControl::getConfirmationCode confirmation code is expired $users_id, $uniqidV4");
        }
        return uniqid();
    }

    static function is2FAEnabled($users_id) {
        $obj = AVideoPlugin::getObjectDataIfEnabled("LoginControl");
        //check for 2fa
        if ($obj->enable2FA) {
            // check if the user confirmed this device before
            return self::isUser2FAEnabled($users_id);
        }
        return false;
    }

    static function is2FAConfirmed($users_id) {
        return !empty(logincontrol_history::is2FAConfirmed($users_id, getDeviceID()));
    }

    static function getLastLoginOnDevice($users_id) {
        $row = logincontrol_history::getLastLoginAttempt($users_id, getDeviceID());
        if (empty($row)) {
            _error_log("LoginControl::getLastLoginOnDevice Not found $users_id, " . getDeviceID());
        }
        return $row;
    }

    static function send2FAEmail($users_id) {
        global $config, $global;
        $obj = AVideoPlugin::getObjectDataIfEnabled("LoginControl");

        $u = new User($users_id);
        $to = $u->getEmail();
        if (empty($to)) {
            _error_log("LoginControl::send2FAEmail the user {$users_id} does not have and email");
            return false;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            _error_log("LoginControl::send2FAEmail the email {$to} for user {$users_id} is invalid");
            setToastMessage(__("Your email is invalid"));
            return false;
        }

        $user = $u->getNameIdentificationBd();
        $siteName = $config->getWebSiteTitle();
        $userIP = getRealIpAddr();
        $userAgent = get_browser_name();
        $confirmation = self::getConfirmationCodeHash($users_id);
        if(empty($confirmation)){
            _error_log("LoginControl::send2FAEmail error on generate confirmation code hash {$users_id}");
            return false;
        }
        
        $confirmationLink = self::getConfirmationLink($confirmation);
        $confirmationLinkATag = '<a href="'.$confirmationLink.'">'.__("Here").'</a>';

        $search = array('{user}', '{siteName}', '{userIP}', '{userAgent}', '{confirmationLink}');
        $replace = array($user, $siteName, $userIP, $userAgent, $confirmationLinkATag);

        $subject = str_replace($search, $replace, $obj->textFor2FASubject);
        $message = str_replace($search, $replace, $obj->textFor2FABody->value);

        $message = nl2br($message);
        
        _error_log("LoginControl::send2FAEmail $subject - $message");
        return true;
        return sendSiteEmail($to, $subject, $message);
    }
    
    static private function getConfirmationCodeHash($users_id){
        if(empty($users_id)){
            return false;
        }
        $lastLogin = self::getLastLoginOnDevice($users_id);
        if (empty($lastLogin)) {
            if (self::createLog($users_id)) {
                $lastLogin = self::getLastLoginOnDevice($users_id);
                if (empty($lastLogin)) {
                    _error_log("LoginControl::getConfirmationCodeHash we could not find the last login for the user {$users_id}");
                    return false;
                }
            } else {
                _error_log("LoginControl::getConfirmationCodeHash we could not create the login log for the user {$users_id}");
                return false;
            }
        }
        $confirmationCode = $lastLogin['confirmation_code'];
        return encryptString(json_encode(array('confirmation_code' => $confirmationCode, 'users_id' => $users_id)));
    }
    
    static function validateConfirmationCodeHash($code){
        if(empty($code)){
            return false;
        }
        $decryptedCode = decryptString($code);
        if(empty($decryptedCode)){
            _error_log("LoginControl::validateConfirmationCodeHash we could not decrypt code {$code}");
            return false;
        }
        
        $json = json_decode($decryptedCode);
        if(empty($json)){
            _error_log("LoginControl::validateConfirmationCodeHash we could not decrypt json {$json}");
            return false;
        }
        
        return self::confirmCode($json->users_id, $json->confirmation_code);
        
    }

    public function getStart() {
        $obj = $this->getDataObject();
        if ($obj->singleDeviceLogin) {
            // check if the user is logged somewhere else and log him off
            if (!User::isAdmin() && !self::isLoggedFromSameDevice()) {
                _error_log("LoginControl::getStart the user logged somewhere else");
                $row = self::getLastConfirmedLogin(User::getId());
                User::logoff();
                if (!empty($row)) {
                    AVideoPlugin::loadPlugin('User_Location');
                    $location = IP2Location::getLocation($row['ip']);
                    $loc = "";
                    if (!empty($location)) {
                        $loc = "$location[country_name], $location[region_name], $location[city_name]";
                    }
                    if(!empty($row['created'])){
                        $msg = "You were disconected by ({$row['device']}) <br>IP: {$row['ip']} <br>{$loc} <br>{$row['ago']}";
                        setAlertMessage($msg);
                    }
                }
            }
        }
    }

    static function getLastLogin($users_id) {
        return logincontrol_history::getLastLogin($users_id);
    }

    static function getPreviewsLogin($users_id) {
        if(self::isUser2FAEnabled($users_id)){
            return logincontrol_history::getPreviewsConfirmedLogin($users_id);
        }else{
            return logincontrol_history::getPreviewsLogin($users_id);
        }
    }
    
    static function getLastConfirmedLogin($users_id) {
        return logincontrol_history::getLastConfirmedLogin($users_id);
    }

    static function isLoggedFromSameDevice() {
        if (!User::isLogged()) {
            return true;
        }
        return self::isSameDeviceAsLastLogin(User::getId(), getDeviceID());
    }

    static function isSameDeviceAsLastLogin($users_id, $uniqidV4) {
        if(self::isUser2FAEnabled($users_id)){
            $row = self::getLastConfirmedLogin($users_id);
        }else{
            $row = self::getLastLogin($users_id);
        }
        if (!empty($row) && $row['uniqidV4'] === $uniqidV4) {
            return true;
        }else if(empty($row)){
            _error_log("LoginControl::isSameDeviceAsLastLogin that is the user first login at all {$users_id} ");
            return true;
        }
        return false;
    }

    static function confirmCode($users_id, $code) {
        $lastLogin = self::getLastLoginOnDevice($users_id);
        if (empty($lastLogin)) {
            return false;
        }
        $confirmationCode = $lastLogin['confirmation_code'];
        if ($confirmationCode === $code) {
            $ulh = new logincontrol_history($lastLogin['id']);
            $ulh->setStatus(logincontrol_history_status::$CONFIRMED);
            return $ulh->save();
        } else {
            _error_log("LoginControl::confirmCode the code does not match $users_id, sent: $code, expected: {$confirmationCode}");
            return false;
        }
    }

    public function getMyAccount($users_id) {
        global $global;

        $obj = AVideoPlugin::getObjectDataIfEnabled("LoginControl");

        if (!empty($obj) && !empty($obj->enable2FA)) {
            echo '<div class="form-group">
    <label class="col-md-4 control-label">' . __("Enable 2FA Login") . '</label>
    <div class="col-md-8 inputGroupContainer">';
            include $global['systemRootPath'] . 'plugin/LoginControl/switchUser2FA.php';
            echo '</div></div>';
        }
    }

    static function isUser2FAEnabled($users_id) {
        global $config;
        $obj = AVideoPlugin::getObjectDataIfEnabled("LoginControl");
        if (!empty($obj) && !empty($obj->enable2FA)) {
            $user = new User($users_id);
            return !empty($user->getExternalOption('2FAEnabled'));
        }
        return false;
    }

    static function setUser2FA($users_id, $value = true) {
        $obj = AVideoPlugin::getObjectDataIfEnabled("LoginControl");
        if (!empty($obj) && !empty($obj->enable2FA)) {
            $user = new User($users_id);
            return $user->addExternalOptions('2FAEnabled', $value);
        }
        return false;
    }

    static function getConfirmationLink($confirmation) {
        global $global;
        return "{$global['webSiteRootURL']}plugin/LoginControl/confirm.php?confirmation={$confirmation}";
    }
    
    public static function profileTabName($users_id) {
        global $global;
        include $global['systemRootPath'] . 'plugin/LoginControl/profileTabName.php';
    }

    public static function profileTabContent($users_id) {
        global $global;
        include $global['systemRootPath'] . 'plugin/LoginControl/profileTabContent.php';
    }


}
