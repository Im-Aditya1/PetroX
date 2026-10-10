<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

include_once dirname(__FILE__) . '/helper.php';

/**
 * @class hook
 * @created 18/11/2023
 *
 * @author Wohaho
 * @email support@w-store.org
 * @discord Wohaho#5542
 *
 */


add_hook('ClientAreaProductDetailsPreModuleTemplate', 1, function ($params) {
    if ($params['modulename'] !== 'petrox') return;
    $params['domain'] = '';
    return $params;
});
add_hook('ClientAreaProductDetails', 1, function ($params) {
    if ($params['modulename'] !== 'petrox') return;
    $params['dedicatedip'] = $params['domain'];
    $params['domain'] = '';
    return $params;
});

add_hook('AdminAreaHeadOutput', 1, function ($params) {
    $url = PetroXInstance::get()->cssPath;
    return '<link rel="stylesheet" href="' . $url . '">' . PHP_EOL;
});

add_hook('AdminAreaFooterOutput', 1, function ($params) {

    $url = PetroXInstance::get()->jsPath;
    $urls = '<script src="' . $url . '"></script>' . PHP_EOL;
    $urls .= '<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>';
    return $urls;
});

add_hook('ClientAreaHeadOutput', 1, function ($params) {
    if ($params['modulename'] !== 'petrox') return;
    $cssUrl = PetroXInstance::get()->cssPath;
    $jsUrl = PetroXInstance::get()->jsPath;
    $urls = '<link rel="stylesheet" href="' . $cssUrl . '">' . PHP_EOL;
    $urls .= '<script src="' . $jsUrl . '"></script>' . PHP_EOL;
    return $urls;
});




add_hook('ClientAdd', 1, function ($vars) {
    if (PetroXInstance::get()->enable_whmcs_user_sync !== true) return;
    $data = PetroXInstance::get()->hooksData['UserAdd'] ?? [];
    $arr = [
        'username' => petroXGenerateUsername(),
        'id' => $vars['client_id'],
        'firstname' => $vars['firstname'],
        'lastname' => $vars['lastname'],
        'email' => $vars['email'],
    ];
    if ($data) {
        $arr['password'] = $data['password'];
    }
    $params = PetroXInstance::get()->getServer();
    $userResult = PetroXInstance::get()->getPterodactylUser($params, $arr);
    if ($userResult['status_code'] !== 200 && $userResult['status_code'] !== 201) {
        throw new Exception('Failed to create user, received error code: ' . $userResult['status_code'] . '. Enable module debug log for more info.');
    }
});

add_hook('ClientEdit', 1, function ($client) {

    if (PetroXInstance::get()->enable_whmcs_user_sync !== true) return;

    $oldClient['id'] = $client['olddata']['client_id'];
    $oldClient['email'] = $client['olddata']['email'];
    $oldClient['firstname'] = $client['olddata']['firstname'];
    $oldClient['lastname'] = $client['olddata']['lastname'];

    //just to be save let's add random username
    //in case the user does not exist and need to be created new one.
    $oldClient['username'] = petroXGenerateUsername();

    $params = PetroXInstance::get()->getServer();
    $userResult = PetroXInstance::get()->getPterodactylUser($params, $oldClient, false);

    if ($userResult['status_code'] !== 404) {
        $newData = [
            'username' => $userResult['attributes']['username'],
            'email' => $client['email'],
            'first_name' => $client['firstname'],
            'last_name' => $client['lastname'],
        ];
        PetroXInstance::get()->updatePterodactylUserData($userResult, $params, $newData);
    }
    if ($userResult['status_code'] === 404) {
        throw new Exception('Failed to edit user, received error code: ' . $userResult['status_code'] . '. Enable module debug log for more info.');
    }

});

add_hook('PreDeleteClient', 1, function ($vars) {
    if (PetroXInstance::get()->enable_whmcs_user_sync !== true) return;
    $clientId = $vars['userid'];
    $params = PetroXInstance::get()->getServer();
    $userResult = PetroXInstance::get()->getPterodactylUser($params, [
        'id' => $clientId
    ], false);

    if ($userResult !== 404) {
        //petroXApplicationApi($params, 'users/' . $userResult['attributes']['id'], [], 'DELETE');
    }
});

add_hook('ClientClose', 1, function ($vars) {
    if (PetroXInstance::get()->enable_whmcs_user_sync !== true) return;
    $clientId = $vars['userid'];
    $params = PetroXInstance::get()->getServer();
    $userResult = PetroXInstance::get()->getPterodactylUser($params, [
        'id' => $clientId
    ], false);

    if ($userResult !== 404) {
        //   petroXApplicationApi($params, 'users/' . $userResult['attributes']['id'], [], 'DELETE');
    }
});

add_hook('UserAdd', 1, function ($vars) {
    if (PetroXInstance::get()->enable_whmcs_user_sync !== true) return;
    PetroXInstance::get()->hooksData['UserAdd'] = $vars;
});



