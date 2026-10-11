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
    $params = PetroXInstance::get()->getServer();
    if (!$params) return;

    $data = PetroXInstance::get()->hooksData['UserAdd'] ?? [];
    $arr = [
        'username' => petroXGenerateUsername(),
        'id' => $vars['client_id'],
        'firstname' => $vars['firstname'],
        'lastname' => $vars['lastname'],
        'email' => $vars['email'],
    ];
    if (!empty($data['password'])) {
        $arr['password'] = $data['password'];
    }

    try {
        $userResult = PetroXInstance::get()->getPterodactylUser($params, $arr);
        if ($userResult['status_code'] !== 200 && $userResult['status_code'] !== 201) {
            if (function_exists('logModuleCall')) {
                logModuleCall("PetroX-WHMCS", 'HOOK ClientAdd', petroXSanitizeForLog($arr), 'Failed to create user on panel: ' . ($userResult['status_code'] ?? 'unknown'), '');
            }
        }
    } catch (Throwable $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall("PetroX-WHMCS", 'HOOK ClientAdd', petroXSanitizeForLog($arr), $e->getMessage(), $e->getTraceAsString());
        }
    }
});

add_hook('ClientEdit', 1, function ($client) {
    if (PetroXInstance::get()->enable_whmcs_user_sync !== true) return;
    $params = PetroXInstance::get()->getServer();
    if (!$params) return;

    $oldClient = [
        'id' => $client['olddata']['client_id'] ?? ($client['olddata']['id'] ?? ($client['userid'] ?? '')),
        'email' => $client['olddata']['email'] ?? '',
        'firstname' => $client['olddata']['firstname'] ?? '',
        'lastname' => $client['olddata']['lastname'] ?? '',
        'username' => petroXGenerateUsername(),
    ];

    try {
        $userResult = PetroXInstance::get()->getPterodactylUser($params, $oldClient, false);

        if (($userResult['status_code'] ?? 0) === 200 && !empty($userResult['attributes']['id'])) {
            $newData = [
                'username' => $userResult['attributes']['username'],
                'email' => $client['email'],
                'first_name' => $client['firstname'],
                'last_name' => $client['lastname'],
            ];
            PetroXInstance::get()->updatePterodactylUserData($userResult, $params, $newData);
        }
        // If status_code is 404, client does not have a Pterodactyl account; ignore safely.
    } catch (Throwable $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall("PetroX-WHMCS", 'HOOK ClientEdit', petroXSanitizeForLog($client), $e->getMessage(), $e->getTraceAsString());
        }
    }
});

add_hook('PreDeleteClient', 1, function ($vars) {
    if (PetroXInstance::get()->enable_whmcs_user_sync !== true) return;
    $params = PetroXInstance::get()->getServer();
    if (!$params) return;

    $clientId = $vars['userid'];
    try {
        $userResult = PetroXInstance::get()->getPterodactylUser($params, [
            'id' => $clientId
        ], false);

        if (($userResult['status_code'] ?? 0) === 200 && !empty($userResult['attributes']['id'])) {
            //petroXApplicationApi($params, 'users/' . $userResult['attributes']['id'], [], 'DELETE');
        }
    } catch (Throwable $e) {
        // Suppress hook error
    }
});

add_hook('ClientClose', 1, function ($vars) {
    if (PetroXInstance::get()->enable_whmcs_user_sync !== true) return;
    $params = PetroXInstance::get()->getServer();
    if (!$params) return;

    $clientId = $vars['userid'];
    try {
        $userResult = PetroXInstance::get()->getPterodactylUser($params, [
            'id' => $clientId
        ], false);

        if (($userResult['status_code'] ?? 0) === 200 && !empty($userResult['attributes']['id'])) {
            //petroXApplicationApi($params, 'users/' . $userResult['attributes']['id'], [], 'DELETE');
        }
    } catch (Throwable $e) {
        // Suppress hook error
    }
});

add_hook('UserAdd', 1, function ($vars) {
    if (PetroXInstance::get()->enable_whmcs_user_sync !== true) return;
    PetroXInstance::get()->hooksData['UserAdd'] = $vars;
});



