<?php
//TODO handle when egg_id and nest_id is changed
//TODO make it optional to reinstall the server when egg_id or nest_id is changed or both.
//TODO make so the client can change the product from given list.
//Example: Each product will have an option to select products
//Where user can choose from those products to Swap/Change
//This will be usefull if you want to allow users to change to different product with same price.
//TODO add also option for time so they don't spam it.

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use Illuminate\Database\Capsule\Manager as Capsule;

global $_LANG;

$language = $_SESSION['Language'] ?? 'english';
// Load language file based on the client's language preference
if (file_exists(dirname(__FILE__) . '/lang/' . $language . '.php')) {
    include dirname(__FILE__) . '/lang/' . $language . '.php';
} else {
    include dirname(__FILE__) . '/lang/english.php'; // Fallback to English
}
$_LANG = array_merge($keys, $_LANG);


include_once dirname(__FILE__) . '/helper.php';


/*
 * Module PART
 */

function petrox_MetaData()
{
    return [
        "DisplayName" => "PetroX",
        "APIVersion" => "1.1",
        "RequiresServer" => true,
    ];
}

function petrox_loadLocations($params): array
{
    $data = petroXApplicationApi($params, 'locations');
    $list = [];
    if ($data['status_code'] == 200) {
        $locations = $data['data'];
        foreach ($locations as $location) {
            $attr = $location['attributes'];
            $list[$attr['id']] = ucfirst($attr['short']);
        }
    }
    return $list;
}

function petrox_loadEggs($params)
{
    $eggs = [];
    if (isset($_SESSION['nests'])) {
        $nests = $_SESSION['nests'];
        foreach ($nests as $nest) {
            $attr = $nest['attributes'];
            $nestId = $attr['id'];
            foreach ($attr['relationships']['eggs']['data'] as $egg) {
                $attr = $egg['attributes'];
                $eggs[$attr['id']] = $attr['name'] . ' (' . $nestId . ')';
            }
        }
    }
    return $eggs;
}

function petrox_loadNests($params)
{
    $data = petroXApplicationApi($params, 'nests?include=eggs');
    $list = [];
    if ($data['status_code'] == 200) {
        $nests = $data['data'];
        foreach ($nests as $nest) {
            $attr = $nest['attributes'];
            $nestId = $attr['id'];
            $list[$nestId] = $attr['name'];
        }
        $_SESSION['nests'] = $nests;
    }

    return $list;
}

function petroxAddHelpTooltip($message, $link)
{

    if ($link == "ports-ranges") {
        $link = 'https://github.com/wohahobg/petrox/wiki/Ports-Ranges';
    } elseif ($link == "default-variables") {
        $link = 'https://github.com/wohahobg/petrox/wiki/Default-Variables';
    } else {
        $link = 'https://github.com/wohahobg/petrox/wiki/General-Information#' . $link;
    }
    // Use htmlspecialchars to encode special characters
    $encodedMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    return sprintf('<a href="%s" target="_blank" data-toggle="tooltip" data-html="true" title="%s">Help</a>', $link, $encodedMessage);
}

function petrox_ConfigKeys()
{
    return [
        "cpu",
        "disk",
        "memory",
        "swap",
        "location_id",
        "dedicated_ip",
        "nest_id",
        "io",
        "egg_id",
        "startup",
        "image",
        "databases",
        "server_name",
        "oom_disabled",
        "backups",
        "allocations",
        "ports_ranges",
        "default_variables",
        "server_port_offset",
        "feature_limits",
        "threads",
        "allow_server_configuration_edit",
        "dedicated_port",
    ];
}

function petrox_ConfigOptions()
{
    $diskConfig = PetroXInstance::get()->disk_as_gb;
    $diskTitle = $diskConfig ? "Disk Space (GB)" : "Disk Space (MB)";
    $diskDescription = "Enter the amount of disk space to assign to the server. The value will be interpreted as " . ($diskConfig ? "gigabytes (GB)." : "megabytes (MB).");
    $diskDefault = $diskConfig ? 10 : 10240; // 10 GB or 10240 MB

    $memoryConfig = PetroXInstance::get()->memory_as_gb;
    $memoryTitle = $memoryConfig ? "Memory (GB)" : "Memory (MB)";
    $memoryDescription = "Enter the amount of memory to assign to the server. The value will be interpreted as " . ($memoryConfig ? "gigabytes (GB)." : "megabytes (MB).");
    $memoryDefault = $memoryConfig ? 1 : 1024; // 1 GB or 1024 MB

    $swapConfig = PetroXInstance::get()->swap_as_gb;
    $swapTitle = $swapConfig ? "Swap (GB)" : "Swap (MB)";
    $swapDescription = "Enter the amount of swap space to assign to the server. The value will be interpreted as " . ($swapConfig ? "gigabytes (GB)." : "megabytes (MB).");
    $swapDefault = $swapConfig ? 0.5 : 512; // 1 GB or 1024 MB

    $portDescription = "Specify port ranges for various server functions. The system will automatically search for available ports within these ranges under the same IP address. Ensure the ranges do not overlap. Note: 'SERVER_PORT' is required. Format: {\"SERVER_PORT\": \"start-end\", \"QUERY_PORT\": \"start-end\", \"RCON_PORT\": \"start-end\"}. Example: {\"SERVER_PORT\": \"7777-7780\", \"QUERY_PORT\": \"27015-27020\", \"RCON_PORT\": \"27020-27030\"}.";

    return [
        "cpu" => [
            "FriendlyName" => "<style></style> CPU Limit (%)",
            "Description" => petroxAddHelpTooltip('Amount of CPU to assign to the created server.', 'cpu-limit-'),
            "Type" => "text",
            "Size" => 25,
            "Default" => 100,
            'SimpleMode' => true,
        ],
        "disk" => [
            "FriendlyName" => $diskTitle,
            "Description" => petroxAddHelpTooltip($diskDescription, 'disk-space'),
            "Type" => "text",
            "Size" => 25,
            "Default" => $diskDefault,
            'SimpleMode' => true,
        ],
        "memory" => [
            "FriendlyName" => $memoryTitle,
            "Description" => petroxAddHelpTooltip($memoryDescription, 'memory'),
            "Type" => "text",
            "Size" => 25,
            "Default" => $memoryDefault,
            'SimpleMode' => true,
        ],
        "swap" => [
            "FriendlyName" => $swapTitle,
            "Description" => petroxAddHelpTooltip($swapDescription, 'swap'),
            "Type" => "text",
            "Default" => $swapDefault,
            "Size" => 25,
            'SimpleMode' => true,
        ],
        "location_id" => [
            "FriendlyName" => "Location ID",
            "Description" => petroxAddHelpTooltip("Select the location where the server will be deployed. Each location ID corresponds to a specific geographical data center.", 'location-id'),
            "Type" => "text",
            "Size" => 25,
            'SimpleMode' => true,
            'Loader' => 'petrox_loadLocations',
        ],
        "dedicated_ip" => [
            "FriendlyName" => "Dedicated IP",
            "Description" => petroxAddHelpTooltip("Assign dedicated ip to the server (optional)", 'dedicated-ip'),
            "Type" => "yesno",
            "Size" => 25,
            'SimpleMode' => true,
        ],
        "nest_id" => [
            "FriendlyName" => "<span id='cNestId'></span> Nest ID",
            "Description" => petroxAddHelpTooltip("Choose a Nest ID that categorizes the type of server you wish to deploy. Nests are used to group similar servers.", 'nest-id'),
            "Type" => "text",
            "Size" => 25,
            'SimpleMode' => true,
            'Loader' => 'petrox_loadNests',
        ],
        "io" => [
            "FriendlyName" => "Block IO Weight",
            "Description" => petroxAddHelpTooltip("Block IO Adjustment number (10-1000)", 'block-io-weight'),
            "Type" => "text",
            "Size" => 25,
            "Default" => "500",
            'SimpleMode' => true,
        ],
        "egg_id" => [
            "FriendlyName" => "<span id='cEggId'></span> Egg ID",
            "Description" => petroxAddHelpTooltip("Select the Egg ID to specify the software environment and settings for your server. Eggs define the application running on the server.", 'egg-id'),
            "Type" => "text",
            "Size" => 10,
            'SimpleMode' => true,
            'Loader' => 'petrox_loadEggs',
        ],
        "startup" => [
            "FriendlyName" => "Startup",
            "Description" => petroxAddHelpTooltip("Custom startup command to assign to the created server (optional)", 'startup-command'),
            "Type" => "text",
            "Size" => 25,
            'SimpleMode' => true,
        ],
        "image" => [
            "FriendlyName" => "Image",
            "Description" => petroxAddHelpTooltip("Custom Docker image to assign to the created server (optional)", 'custom-docker-image'),
            "Type" => "text",
            "Size" => 25,
            'SimpleMode' => true,
        ],
        "databases" => [
            "FriendlyName" => "Databases",
            "Description" => petroxAddHelpTooltip("Client will be able to create this amount of databases for their server (optional)", 'databases'),
            "Type" => "text",
            "Size" => 25,
            "Default" => 1,
            'SimpleMode' => true,
        ],
        "server_name" => [
            "FriendlyName" => "Server Name",
            "Description" => petroxAddHelpTooltip("The name of the server as shown on the panel (optional)", 'server-name'),
            "Type" => "text",
            "Size" => 25,
            "Default" => 'PetroX Server',
            'SimpleMode' => true,
        ],
        "oom_disabled" => [
            "FriendlyName" => "Disable OOM Killer",
            "Description" => petroxAddHelpTooltip("Should the Out Of Memory Killer be disabled (optional)", 'disable-oom-killer'),
            "Type" => "yesno",
            "Size" => 25,
            'SimpleMode' => true,
        ],
        "backups" => [
            "FriendlyName" => "Backups",
            "Description" => petroxAddHelpTooltip("Client will be able to create this amount of backups for their server (optional)", 'backups'),
            "Type" => "text",
            "Size" => 25,
            'SimpleMode' => true,
        ],
        "allocations" => [
            "FriendlyName" => "Allocations",
            "Description" => petroxAddHelpTooltip("Client will be able to create this amount of allocations for their server (optional)", 'allocations'),
            "Type" => "text",
            "Size" => 25,
            'SimpleMode' => true,
        ],
        "ports_ranges" => [
            "FriendlyName" => "Ports Ranges",
            "Description" => petroxAddHelpTooltip($portDescription, 'ports-ranges'),
            "Type" => "textarea",
            "Size" => 10,
            "default" => '{"SERVER_PORT": "25565-25669"}',
            'SimpleMode' => true,
        ],
        "default_variables" => [
            "FriendlyName" => "Default Variables",
            "Description" => petroxAddHelpTooltip("Define default values for server variables in JSON format. For instance, set MAX_PLAYERS to 30 with {\"MAX_PLAYERS\": 30}. This is useful for consistent server settings and quick configuration.", 'default-variables'),
            "Type" => "textarea",
            "default" => '{"MAX_PLAYERS": "30"}',
            "Size" => 25,
            'SimpleMode' => true,
        ],
        'server_port_offset' => [
            'FriendlyName' => "Server Port Offset",
            "Description" => petroxAddHelpTooltip("Specify an offset for the Server Port, used for games requiring a specific increment above the SERVER_PORT. Enter '1' for games like ARK: Survival Evolved that need SERVER_PORT +1, or '123' for games like MTA requiring a larger increment. To disable this feature, simply input '0'", 'server-port-offset'),
            "Type" => "text",
            "default" => 0,
            "Size" => 25,
            'SimpleMode' => true,
        ],
        "feature_limits" => [
            'FriendlyName' => "Feature Limits",
            "Description" => petroxAddHelpTooltip("Feature limits are ideal for overriding add-ons that are integrated into your Pterodactyl panel. Ensure that the input is valid JSON. For more information, please refer to our Wiki page.", 'feature-limits'),
            "Type" => "text",
            "default" => '0',
            "Size" => 25,
            'SimpleMode' => true,
        ],
        //        'threads' => "Enter the specific CPU cores that this process can run on, or leave blank to allow all cores. This can be a single number, or a comma seperated list. Example: 0, 0-1,3, or 0,1,3,4."
        "threads" => [
            'FriendlyName' => "CPU Pinning",
            "Description" => petroxAddHelpTooltip("Enter the specific CPU cores that this process can run on, or leave blank to allow all cores. This can be a single number, or a comma seperated list. Example: 0, 0-1,3, or 0,1,3,4.", 'cpu-pinning'),
            "Type" => "text",
            "Size" => 25,
            "default" => null,
            'SimpleMode' => true,
        ],
        "allow_server_configuration_edit" => [
            'FriendlyName' => "Server Configuration Access",
            "Description" => petroxAddHelpTooltip("Grant users the ability to edit their server's startup parameters and node configuration settings via the Pterodactyl API. This setting controls the level of access users have to critical server configurations.", 'server-configuration-access'),
            "Type" => "dropdown",
            "Default" => "disabled",
            "Options" => [
                'disabled' => 'Do not allow',
                'both' => 'Allow both startup and settings modifications',
                'startup' => 'Allow only startup modifications',
                'settings' => 'Allow only settings modifications',
            ],
            "Size" => 25,
            'SimpleMode' => true,
        ],
        "dedicated_port" => [
            "FriendlyName" => "Dedicated Port",
            "Description" => petroxAddHelpTooltip("Works like Dedicated IP but reserves only a specific port instead of the entire IP. It ensures that the first port from the configured Ports Range (e.g., SERVER_PORT = 27015-27020, which means 27015 will always be used) is assigned to the server, guaranteeing a fixed port for that client.", 'dedicated-port'),
            "Type" => "yesno",
            "Size" => 25,
            'SimpleMode' => true,
        ]

    ];
}

function petroXGetOption(array $params, $id, $default = NULL)
{
    $options = petrox_ConfigKeys();

    $friendlyName = $options[$id];
    if (isset($params['A'][$friendlyName]) && $params['configoptions'][$friendlyName] !== '') {
        return $params['configoptions'][$friendlyName];
    } else if (isset($params['configoptions'][$id]) && $params['configoptions'][$id] !== '') {
        return $params['configoptions'][$id];
    } else if (isset($params['customfields'][$friendlyName]) && $params['customfields'][$friendlyName] !== '') {
        return $params['customfields'][$friendlyName];
    } else if (isset($params['customfields'][$id]) && $params['customfields'][$id] !== '') {
        return $params['customfields'][$id];
    }

    $found = false;
    $i = 0;
    foreach ($options as $key) {
        $i++;
        if ($key === $id) {
            $found = true;
            break;
        }
    }
    if ($found && isset($params['configoption' . $i]) && $params['configoption' . $i] !== '') {
        return $params['configoption' . $i];
    }
    return $default;
}

function petrox_TestConnection(array $params)
{
    $solutions = [
        0 => "Check module debug log for more detailed error.",
        401 => "Authorization header either missing or not provided.",
        403 => "Double check the password (which should be the Application Key).",
        404 => "Result not found.",
        422 => "Validation error.",
        500 => "Panel errored, check panel logs.",
    ];

    $err = "";
    try {
        $response = petroXApplicationApi($params, 'nodes');

        if ($response['status_code'] !== 200) {
            $status_code = $response['status_code'];
            $err = "Invalid status_code received: " . $status_code . ". Possible solutions: " . $solutions[$status_code] ?? "None.";
        } else {
            if ($response['meta']['pagination']['count'] === 0) {
                $err = "Authentication successful, but no nodes are available.";
            }
        }
    } catch (Exception $e) {
        logModuleCall("PetroX-WHMCS", 'TEST CONNECTION', $params, $e->getMessage(), $e->getTraceAsString());
        $err = $e->getMessage();
    }

    return [
        "success" => $err === "",
        "error" => $err,
    ];
}


function petrox_CreateAccount(array $params)
{

    //if addonId is not 0 , add it to extrnal_id
    if ($params['addonId'] > 0) {
        $params['serviceid'] = $params['serviceid'] . '.' . $params['addonId'];
    }

    try {
        PetroXInstance::get()->service_id = $params['serviceid'];
        $portsJson = petroXGetOption($params, 'ports_ranges');
        $portsJson = trim($portsJson);
        $portsArray = [];
        if ($portsJson !== '') {
            $pattern = '/^(\d+-\d+)(,\d+-\d+)*$/';
            if (!preg_match_all($pattern, $portsJson, $matches)) {
                $portsArray = json_decode($portsJson, true);
                if (!is_array($portsArray)) {
                    throw new Exception('Failed to create server because ports is not in valid JSON format.');
                }
            }
        }

        $serverId = petroXGetServer($params);
        if ($serverId) throw new Exception('Failed to create server because it is already created.');
        $customFieldId = petroXGetCustomFieldId($params);

        $userResult = PetroXInstance::get()->getPterodactylUser($params, [
            'username' => petroXGetOption($params, 'username', petroXGenerateUsername()),
            'id' => $params['clientsdetails']['client_id'],
            'email' => $params['clientsdetails']['email'],
            'firstname' => $params['clientsdetails']['firstname'],
            'lastname' => $params['clientsdetails']['lastname'],
        ]);

        if ($userResult['status_code'] === 200 || $userResult['status_code'] === 201) {
            if (!isset($userResult['attributes']['id'])) {
                throw new Exception("Failed to get the client pterodactyl's account.Enable module debug log for more info.");
            }
            $userId = $userResult['attributes']['id'];
        } else {
            throw new Exception('Failed to create user, received error code: ' . $userResult['status_code'] . '. Enable module debug log for more info.');
        }

        $nestId = petroXGetOption($params, 'nest_id');
        $eggId = petroXGetOption($params, 'egg_id');

        $eggData = petroXApplicationApi($params, 'nests/' . $nestId . '/eggs/' . $eggId . '?include=variables');
        if ($eggData['status_code'] !== 200) throw new Exception('Failed to get egg data, received error code: ' . $eggData['status_code'] . '. Enable module debug log for more info.');

        $environment = [];
        $default_variables = petroXGetOption($params, 'default_variables');
        $default_variables = json_decode($default_variables, true);

        foreach ($eggData['attributes']['relationships']['variables']['data'] as $key => $val) {
            $attr = $val['attributes'];
            $var = $attr['env_variable'];
            $default = $attr['default_value'];
            $friendlyName = petroXGetOption($params, $attr['name']);
            $envName = petroXGetOption($params, $attr['env_variable']);

            if (isset($friendlyName)) {
                $environment[$var] = "" . $friendlyName . "";
            } elseif (isset($envName)) {
                $environment[$var] = "" . $envName . "";
            } elseif (isset($default_variables[$var]) && !in_array($default_variables[$var], PetroXInstance::get()->dynamic_variables)) {
                $environment[$var] = "" . $default_variables[$var] . "";
            } else {
                $environment[$var] = "" . $default . "";
            }
        }

        if ($default_variables) {
            foreach ($default_variables as $default_variable => $default_variable_value) {
                if (in_array($default_variable_value, PetroXInstance::get()->dynamic_variables)) {
                    PetroXInstance::get()->dynamic_environment_array[$default_variable] = $default_variable_value;
                }
            }
        }

        $id = (string)$params['serviceid'];
        $name = petroXGetOption($params, 'server_name', petroXGenerateUsername() . '_' . $id);
        [$memory, $swap, $disk] = petroXGetMemorySwapAndDisk($params);

        $io = petroXGetOption($params, 'io');
        $cpu = petroXGetOption($params, 'cpu');

        $location_id = petroXGetOption($params, 'location_id');
        $dedicated_ip = (bool)petroXGetOption($params, 'dedicated_ip');
        $dedicated_port = (bool)petroXGetOption($params, 'dedicated_port');
        PetroXInstance::get()->dedicated_ip = $dedicated_ip;

        if ($dedicated_port) {
            if (isset($portsArray['SERVER_PORT'])) {
                $portsData = $portsArray;
                $explode = explode('-', $portsArray['SERVER_PORT']);
                $portsData['SERVER_PORT'] = $explode[0] ?? null;
                $portsArray = $portsData;
            }
        }

        //PetroXInstance::get()->server_port_offset = petroXGetOption($params, 'server_port_offset');

        if ($portsArray) {
            $port_range = isset($portsArray['SERVER_PORT']) ? explode(',', $portsArray['SERVER_PORT']) : [];
        } else {
            $port_range = !empty($portsJson) ? explode(',', $portsJson) : [];
        }

        $image = petroXGetOption($params, 'image', $eggData['attributes']['docker_image']);
        $startup = petroXGetOption($params, 'startup', $eggData['attributes']['startup']);
        $databases = petroXGetOption($params, 'databases');
        $maximumAllocations = petroXGetOption($params, 'allocations');
        $backups = petroXGetOption($params, 'backups');
        $oom_disabled = (bool)petroXGetOption($params, 'oom_disabled');

        $threads = petrox_validateThreads(petroXGetOption($params, 'threads'));

        $serverData = [
            'name' => $name,
            'user' => (int)$userId,
            'nest' => (int)$nestId,
            'egg' => (int)$eggId,
            'docker_image' => $image,
            'startup' => $startup,
            'oom_disabled' => $oom_disabled,
            'limits' => [
                'memory' => (int)$memory,
                'swap' => (int)$swap,
                'io' => (int)$io,
                'cpu' => (int)$cpu,
                'disk' => (int)$disk,
                'threads' => (string)$threads,
            ],
            'feature_limits' => [
                'databases' => $databases ? (int)$databases : null,
                'allocations' => (int)$maximumAllocations,
                'backups' => (int)$backups,
            ],
            'deploy' => [
                'locations' => [(int)$location_id],
                'dedicated_ip' => $dedicated_ip,
                'port_range' => $port_range,
            ],
            'environment' => $environment,
            'start_on_completion' => true,
            'external_id' => (string)$params['serviceid'],
        ];

        $feature_limits = petroXGetOption($params, 'feature_limits');
        $feature_limits = json_decode($feature_limits, true);
        if ($feature_limits) {
            foreach ($feature_limits as $featureName => $default) {
                $value = petroXGetOption($params, $featureName, $default);
                $feature_limits[$featureName] = $value;
            }
            $serverData['feature_limits'] = array_merge($serverData['feature_limits'], $feature_limits);
        }

        $server = petroXApplicationApi($params, 'servers?include=allocations', $serverData, 'POST');

        if ($server['status_code'] === 400) throw new Exception('Couldn\'t find any nodes satisfying the request.');
        if ($server['status_code'] !== 201) throw new Exception('Failed to create the server, received the error code: ' . $server['status_code'] . '. Enable module debug log for more info.');

        $serverId = $server['attributes']['id'];
        $_SERVER_ID = $server['attributes']['uuid'];


        $serverAllocations = $server['attributes']['relationships']['allocations']['data'];
        $allocation = $server['attributes']['allocation'];
        petroX_getServerIPAndPort($serverAllocations, $allocation);

        $serverNode = $server['attributes']['node'];
        $foundPorts = [];

        if ($portsArray) {
            petroXGetNodeAllocations($params, $serverNode);
            petroXProcessAllocations($eggData, $portsArray);
        }

        if (!PetroXInstance::get()->variables) {
            petroXLog('VARIABLES', 'No variables founds.', $portsArray);
        }

        if (!PetroXInstance::get()->node_allocations) {
            petroXLog('NODE ALLOCATIONS', 'Node allocations not found.', [$serverNode]);
        }

        if (PetroXInstance::get()->variables && PetroXInstance::get()->node_allocations) {
            $ips = petroXMakeIParray();
            $foundPorts = petroXfindPorts($portsArray, $ips);
        }

        if (!$foundPorts && PetroXInstance::get()->variables && PetroXInstance::get()->node_allocations) {
            petroXLog('Ports not founds', 'Ports not founds.', [
                'results' => PetroXInstance::get()->fetchedResults,
                'variables' => PetroXInstance::get()->variables
            ]);
        }

        if ($foundPorts) {
            petroXLog('Found ports', 'Found ports.', $foundPorts);
            $_SERVER_PORT_ID = $serverAllocations[0]['attributes']['id'];

            $allocationArray['allocation'] = $_SERVER_PORT_ID;
            if (isset($foundPorts['SERVER_PORT'])) {
                $allocationArray['allocation'] = $foundPorts['SERVER_PORT']['id'];
                $allocationArray['remove_allocations'] = [$_SERVER_PORT_ID];
            }

            $environment = [];
            $additional = [];
            foreach ($foundPorts as $key => $var) {
                $environment[$key] = "" . $var['port'] . "";
                $additional[] = $var['id'];
                $maximumAllocations++;
            }

            if (PetroXInstance::get()->getDynamicEnvironmentArray()) {
                PetroXInstance::get()->addFileLog(PetroXInstance::get()->getDynamicEnvironmentArray(), 'Setting Dynamic Environment');
                foreach (PetroXInstance::get()->getDynamicEnvironmentArray() as $environmentName => $variableName) {
                    if (isset($environment[$variableName])) {
                        $environment[$environmentName] = $environment[$variableName];
                    }
                }
            }
            if (isset($environment['SERVER_PORT'])) {
                unset($environment['SERVER_PORT']);
            }
            $allocationArray['add_allocations'] = $additional;

            $newServerArray = array_merge([
                'memory' => (int)$memory,
                'swap' => (int)$swap,
                'io' => (int)$io,
                'cpu' => (int)$cpu,
                'disk' => (int)$disk,
                'oom_disabled' => $oom_disabled,
                'feature_limits' => [
                    'databases' => (int)$databases,
                    'allocations' => (int)$maximumAllocations,
                    'backups' => (int)$backups,
                ],
            ], $allocationArray);

            if ($feature_limits) {
                $newServerArray['feature_limits'] = array_merge($serverData['feature_limits'], $feature_limits);
            }
            $updateResult = petroXApplicationApi($params, 'servers/' . $serverId . '/build?include=allocations', $newServerArray, 'PATCH');

            if ($updateResult['status_code'] !== 200) throw new Exception('Failed to update build of the server, received error code: ' . $updateResult['status_code'] . '. Enable module debug log for more info.');


            $allocation = $updateResult['attributes']['allocation'];
            $serverAllocations = $updateResult['attributes']['relationships']['allocations']['data'];
            petroX_getServerIPAndPort($serverAllocations, $allocation);
            petroXApplicationApi($params, 'servers/' . $serverId . '/startup', [
                'startup' => $server['attributes']['container']['environment']['STARTUP'],
                'egg' => $server['attributes']['egg'],
                'image' => $server['attributes']['container']['image'],
                'environment' => array_merge($serverData['environment'], $environment),
                'skip_scripts' => false,
            ], 'PATCH');

        }

        unset($params['password']);
        petroX_updateServerDomain($params);
        petroXUpdateCustomField($params, $customFieldId, $_SERVER_ID);
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
            'username' => '',
            'password' => '',
        ]);

    } catch (Exception $err) {
        return $err->getMessage();
    }
    return 'success';
}

function petrox_SuspendAccount(array $params)
{

    if ($params['addonId'] > 0) {
        $params['serviceid'] = $params['serviceid'] . '.' . $params['addonId'];
    }

    try {
        $serverId = petroXGetServer($params);
        if (!$serverId) throw new Exception('Failed to suspend server because it doesn\'t exist.');

        $suspendResult = petroXApplicationApi($params, 'servers/' . $serverId . '/suspend', [], 'POST');
        if ($suspendResult['status_code'] !== 204) throw new Exception('Failed to suspend the server, received error code: ' . $suspendResult['status_code'] . '. Enable module debug log for more info.');
    } catch (Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function petrox_UnsuspendAccount(array $params)
{

    if ($params['addonId'] > 0) {
        $params['serviceid'] = $params['serviceid'] . '.' . $params['addonId'];
    }

    try {
        $serverId = petroXGetServer($params);
        if (!$serverId) throw new Exception('Failed to unsuspend server because it doesn\'t exist.');

        $suspendResult = petroXApplicationApi($params, 'servers/' . $serverId . '/unsuspend', [], 'POST');
        if ($suspendResult['status_code'] !== 204) throw new Exception('Failed to unsuspend the server, received error code: ' . $suspendResult['status_code'] . '. Enable module debug log for more info.');
    } catch (Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function petrox_TerminateAccount(array $params)
{
    if ($params['addonId'] > 0) {
        $params['serviceid'] = $params['serviceid'] . '.' . $params['addonId'];
    }


    try {
        $serverId = petroXGetServer($params);
        if (!$serverId) throw new Exception('Failed to terminate server because it doesn\'t exist.');

        $deleteResult = petroXApplicationApi($params, 'servers/' . $serverId, [], 'DELETE');
        if ($deleteResult['status_code'] !== 204) throw new Exception('Failed to terminate the server, received error code: ' . $deleteResult['status_code'] . '. Enable module debug log for more info.');
    } catch (Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}


function petrox_ChangePackage(array $params)
{

    if ($params['addonId'] > 0) {
        $params['serviceid'] = $params['serviceid'] . '.' . $params['addonId'];
    }

    try {

        // Fetch server with node relationship to determine current location
        $serverData = petroXGetServer($params, true, 'node,allocations');
        if (!$serverData) throw new Exception('Failed to change package of server because it doesn\'t exist.');
        $serverId = $serverData['id'];

        // Determine if a server transfer is needed (location changed)
        $currentLocationId = $serverData['relationships']['node']['attributes']['location_id'] ?? null;
        $newLocationId = (int)petroXGetOption($params, 'location_id');
        $needsTransfer = $newLocationId && $currentLocationId && ($newLocationId !== $currentLocationId);

        [$memory, $swap, $disk] = petroXGetMemorySwapAndDisk($params);

        $io = petroXGetOption($params, 'io');
        $cpu = petroXGetOption($params, 'cpu');
        $databases = petroXGetOption($params, 'databases');
        $allocations = petroXGetOption($params, 'allocations');
        $backups = petroXGetOption($params, 'backups');
        $oom_disabled = (bool)petroXGetOption($params, 'oom_disabled');

        $threads = petrox_validateThreads(petroXGetOption($params, 'threads'));

        // ── Step 1: Update build configuration (resources) ──
        // This updates the panel database; the target node picks up the
        // correct limits once the transfer completes.
        $updateData = [
            'allocation' => $serverData['allocation'],
            'memory' => (int)$memory,
            'swap' => (int)$swap,
            'io' => (int)$io,
            'cpu' => (int)$cpu,
            'disk' => (int)$disk,
            'threads' => (string)$threads,
            'oom_disabled' => $oom_disabled,
            'feature_limits' => [
                'databases' => (int)$databases,
                'allocations' => (int)$allocations,
                'backups' => (int)$backups,
            ],
        ];

        $feature_limits = petroXGetOption($params, 'feature_limits');
        $feature_limits = json_decode($feature_limits, true);
        if ($feature_limits) {
            $updateData['feature_limits'] = array_merge($updateData['feature_limits'], $feature_limits);
        }

        $updateResult = petroXApplicationApi($params, 'servers/' . $serverId . '/build?include=allocations', $updateData, 'PATCH');
        if ($updateResult['status_code'] !== 200) throw new Exception('Failed to update build of the server, received error code: ' . $updateResult['status_code'] . '. Enable module debug log for more info.');
        $allocation = $updateResult['attributes']['allocation'];
        $serverAllocations = $updateResult['attributes']['relationships']['allocations']['data'];

        petroX_getServerIPAndPort($serverAllocations, $allocation);

        // ── Step 2: Update startup configuration ──
        $nestId = petroXGetOption($params, 'nest_id');
        $eggId = petroXGetOption($params, 'egg_id');
        $eggData = petroXApplicationApi($params, 'nests/' . $nestId . '/eggs/' . $eggId . '?include=variables');
        if ($eggData['status_code'] !== 200) throw new Exception('Failed to get egg data, received error code: ' . $eggData['status_code'] . '. Enable module debug log for more info.');

        $default_variables = petroXGetOption($params, 'default_variables');
        $default_variables = json_decode($default_variables, true);
        $environment = [];
        foreach ($eggData['attributes']['relationships']['variables']['data'] as $key => $val) {
            $attr = $val['attributes'];
            $var = $attr['env_variable'];
            $default = $attr['default_value'];
            $friendlyName = petroXGetOption($params, $attr['name']);
            $envName = petroXGetOption($params, $attr['env_variable']);

            if (isset($friendlyName)) {
                $environment[$var] = "" . $friendlyName . "";
            } elseif (isset($envName)) {
                $environment[$var] = "" . $envName . "";
            } elseif (isset($default_variables[$var]) && !in_array($default_variables[$var], PetroXInstance::get()->dynamic_variables)) {
                $environment[$var] = "" . $default_variables[$var] . "";
            } else {
                $environment[$var] = "" . $default . "";
            }
        }


        $image = petroXGetOption($params, 'image', $eggData['attributes']['docker_image']);
        $startup = petroXGetOption($params, 'startup', $eggData['attributes']['startup']);
        $updateData = [
            'environment' => $environment,
            'startup' => $startup,
            'egg' => (int)$eggId,
            'image' => $image,
            'skip_scripts' => false,
        ];
        $updateResult = petroXApplicationApi($params, 'servers/' . $serverId . '/startup', $updateData, 'PATCH');
        if ($updateResult['status_code'] !== 200) throw new Exception('Failed to update startup of the server, received error code: ' . $updateResult['status_code'] . '. Enable module debug log for more info.');

        if ($eggId !== $serverData['egg']) {
            //TODO Option to re install the egg
            //TODO what if the egg id is not same ?
            //TOOD should we looking for new ports ?
            //TOOD what should we do ?
            //petroXApplicationApi($params, 'servers/' . $serverId . '/reinstall', [], 'POST');
        }

        // ── Step 3: Transfer server if location changed ──
        if ($needsTransfer) {
            // Parse port ranges config for allocation matching on the target node
            $portsJson = petroXGetOption($params, 'ports_ranges');
            $portsJson = trim($portsJson ?? '');
            $portsArray = [];
            if ($portsJson !== '') {
                $pattern = '/^(\d+-\d+)(,\d+-\d+)*$/';
                if (!preg_match_all($pattern, $portsJson, $matches)) {
                    $portsArray = json_decode($portsJson, true);
                    if (!is_array($portsArray)) {
                        $portsArray = [];
                    }
                }
            }

            // Find a node with a free allocation in the target location
            $transferTarget = petroXFindTransferTarget($params, $newLocationId, $portsArray);
            if (!$transferTarget) {
                throw new Exception('Failed to transfer server: could not find any nodes with available allocations in location ' . $newLocationId . '.');
            }

            // Initiate the transfer via Pterodactyl Application API
            $transferResult = petroXApplicationApi($params, 'servers/' . $serverId . '/transfer', [
                'node_id' => (int)$transferTarget['node_id'],
                'allocation_id' => (int)$transferTarget['allocation_id'],
                'additional_allocations' => [],
            ], 'POST');

            if ($transferResult['status_code'] !== 202) {
                throw new Exception('Failed to transfer server to new location, received error code: ' . $transferResult['status_code'] . '. Enable module debug log for more info.');
            }

            // Point WHMCS domain record at the new allocation's IP:port
            PetroXInstance::get()->server_ip = $transferTarget['allocation']['ip'];
            PetroXInstance::get()->server_port = $transferTarget['allocation']['port'];
            if (PetroXInstance::get()->use_alias_ip && !empty($transferTarget['allocation']['alias'])) {
                PetroXInstance::get()->server_alias_ip = $transferTarget['allocation']['alias'];
            }

            PetroXInstance::get()->addFileLog([
                'from_location' => $currentLocationId,
                'to_location' => $newLocationId,
                'from_node' => $serverData['node'],
                'to_node' => $transferTarget['node_id'],
                'allocation_id' => $transferTarget['allocation_id'],
                'new_ip' => $transferTarget['allocation']['ip'],
                'new_port' => $transferTarget['allocation']['port'],
            ], 'Server Transfer Initiated');
        }

        $_SERVER_ID = $serverData['uuid'];
        $customFieldId = petroXGetCustomFieldId($params);

        petroX_updateServerDomain($params);
        petroXUpdateCustomField($params, $customFieldId, $_SERVER_ID);
    } catch (Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function petrox_AdminCustomButtonArray()
{
//    return array(
//        "Button 1 Display Value" => "buttonOneFunction",
//        "Button 2 Display Value" => "buttonTwoFunction",
//    );
}

function petrox_LoginLink(array $params)
{
    if ($params['moduletype'] !== 'petrox') return;
    try {
        $server = petroXGetServer($params, true);
        if (!$server) return;

        $hostname = petroXGetHostname($params);
        $button1 = '<a class="btn btn-info text-uppercase"  
                    href="' . $hostname . '/server/' . $server['identifier'] . '" target="_blank">
                 <i class="fas fa-eye fa-fw"></i>
                View Server
              </a>';
        $button2 = '<a class="btn btn-primary text-uppercase"  
                 href="' . $hostname . '/admin/servers/view/' . $server['id'] . '" 
                 target="_blank">
                 <i class="fas fa-sign-in fa-fw"></i>
                Admin View
                </a>';
        // JavaScript to add buttons and hide the existing button
        echo '<script>
            const link1 = `' . $button1 . '`;
            const link2 = `' . $button2 . '`;
          </script>';

        echo '<script>
            jQuery(document).ready(function($) {
                // Hide the existing button
                $("#btnLoginLinkTrigger").hide();
               
                var buttonGroup = $("#btnLoginLinkTrigger").parent(); // Assuming buttons are in the same group

                // Add new buttons
                $(buttonGroup).prepend(link1);
                $(buttonGroup).prepend(link2);

            });
          </script>';
        return $button2;
    } catch (Exception $err) {

    }
}

function petrox_AdminServicesTabFields($params)
{

}


function petrox_ClientArea(array $params)
{
    if ($params['moduletype'] !== 'petrox') return;

    global $_LANG;

    try {
        $isAdmin = $_SESSION['adminid'] ?? 0;
        $hostname = petroXGetHostname($params);
        $serverId = $params['customfields']['UUID (Server ID)'];
        $allowServerConfigurationEdit = petroXGetOption($params, 'allow_server_configuration_edit');
        $allowStartUpEdit = false;
        $allowSettingsEdit = false;

        if ($allowServerConfigurationEdit == 'both') {
            $allowStartUpEdit = true;
            $allowSettingsEdit = true;
        }

        if ($allowServerConfigurationEdit == 'startup' && PetroXInstance::get()->allow_startup_edit === true) {
            $allowStartUpEdit = true;
        }
        if ($allowServerConfigurationEdit == 'settings' && PetroXInstance::get()->allow_variables_edit === true) {
            $allowSettingsEdit = true;
        }
        //hide this for now ?
        $allowStartUpEdit = false;
        $params['allowSettingsEdit'] = $allowSettingsEdit;
        $params['allowStartUpEdit'] = $allowStartUpEdit;

        $serverData = petroXGetServer($params, true, 'user,node,allocations');

        if (!$serverData) {
            return [
                'templatefile' => 'templates/error.tpl',
                'vars' => []
            ];
        }

        // Handle custom AJAX actions (settings save)
        if (isset($_GET['modop']) && $_GET['modop'] == 'custom' && isset($_GET['a'])) {

            $action = match ($_GET['a']) {
                'saveServerVariables' => 'petroXSaveServerVariables',
                'saveServerStartup' => 'petroXSaveServerStartup',
                default => false,
            };

            if ($action !== false) {
                $action($params, $serverData, null);
                exit(200);
            }

            petroXreturnJsonMessage('ACTION_NOT_FOUND');
        }

        // Set server IP and port from allocations
        $serverAllocations = $serverData['relationships']['allocations']['data'];
        petroX_getServerIPAndPort($serverAllocations, $serverData['allocation']);

        // Update server UUID if empty
        if ($serverId == '') {
            $serverId = $serverData['uuid'];
            $customFieldId = petroXGetCustomFieldId($params);
            petroXUpdateCustomField($params, $customFieldId, $serverData['uuid']);
        }

        // Update server IP if empty
        if ($params['domain'] == '') {
            petroX_updateServerDomain($params);
        }

        $serverIp = $params['domain'];

        $actionUrl = "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $vars = [
            'moduleDir' => __DIR__,
            'serviceId' => $params['serviceid'],
            'serverData' => $serverData,
            'serverIp' => $serverIp,
            'serverId' => $serverId,
            'isAdmin' => $isAdmin,
            'allowStartUpEdit' => $allowStartUpEdit,
            'allowSettingsEdit' => $allowSettingsEdit
        ];

        $overviewVars = [
            'serviceUrl' => $hostname . '/server/' . $serverData['identifier'],
        ];

        $vars = array_merge($overviewVars, $vars);
        [$variables, $meta] = petroXGetServerVariables($params, $serverData['uuid']);

        $environment = $serverData['container']['environment'];
        $editableVariables = [];
        if ($variables) {
            foreach ($variables as $variable) {
                $attr = $variable['attributes'];
                if ($attr['is_editable']) {

                    $pattern = '/(?<!<a href=")(https?:\/\/[^\s"]+)(?!")/i';
                    $replacement = '<a href="$1" target="_blank">$1</a>';
                    $attr['description'] = preg_replace($pattern, $replacement, $attr['description']);


                    $rules = $attr['rules'];
                    $arr = $attr;
                    $arr['options'] = [];
                    $arr['rule'] = 'input';
                    $arr['max_input'] = 255;

                    if (preg_match('/\bin:0,1\b/', $rules) && preg_match('/in:0,1/', $rules) && !preg_match('/in:0,1,/', $rules)) {
                        $arr['rule'] = 'switch';
                    }

                    if (str_contains($rules, 'numeric') && $arr['rule'] != 'switch') {
                        $arr['rule'] = 'number';
                    }

                    if (str_contains($rules, 'in:') && $arr['rule'] != 'switch') {
                        $arr['rule'] = 'select';
                        $explode = explode('in:', $rules);
                        if (isset($explode[1])) {
                            $arr['options'] = explode(',', $explode[1]);
                        }
                    }
                    if (str_contains($rules, 'max:')) {
                        $arr['max_input'] = str_replace('max:', '', $rules);
                    }


                    $arr['required'] = str_contains($rules, 'required');


                    $editableVariables[] = $arr;
                }
            }

        }
        $vars = array_merge([
            'editableVariables' => $editableVariables,
            'environment' => $environment,
            'saveSettingUrl' => $actionUrl . '&modop=custom&a=saveServerVariables',
            'saveStartupUrl' => $actionUrl . '&modop=custom&a=saveServerStartup',
        ], $vars);


        return [
            'templatefile' => 'clientarea.tpl',
            'vars' => $vars
        ];
    } catch (Exception $err) {
        echo $err->getMessage();
        die(400);
    }
}
