<?php

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
if (isset($keys) && is_array($keys)) {
    $_LANG = array_merge($keys, $_LANG ?? []);
}

include_once dirname(__FILE__) . '/helper.php';

/*
 * Module PART
 */

function petrox_MetaData()
{
    return [
        "DisplayName" => "PetroX",
        "APIVersion" => "2.0",
        "RequiresServer" => true,
    ];
}

function petrox_loadLocations($params): array
{
    $list = [];
    try {
        $data = petroXApplicationApiPaginated($params, 'locations');
        if ($data['status_code'] == 200 && !empty($data['data'])) {
            foreach ($data['data'] as $location) {
                $attr = $location['attributes'];
                $label = !empty($attr['long']) ? ($attr['short'] . ' - ' . $attr['long']) : ucfirst($attr['short']);
                $list[$attr['id']] = $label;
            }
        }
    } catch (Throwable $e) {
        // Log silently
    }
    return $list;
}

function petrox_loadEggs($params)
{
    $eggs = [];
    try {
        // In Pterodactyl v2, eggs are top-level resources under /api/application/eggs
        $res = petroXApplicationApiPaginated($params, 'eggs?include=tags');
        if ($res['status_code'] === 200 && !empty($res['data'])) {
            foreach ($res['data'] as $egg) {
                $attr = $egg['attributes'];
                $tags = [];
                if (!empty($egg['relationships']['tags']['data'])) {
                    foreach ($egg['relationships']['tags']['data'] as $tag) {
                        $tags[] = $tag['attributes']['name'] ?? ($tag['attributes']['slug'] ?? '');
                    }
                }
                $tagLabel = !empty($tags) ? ' (' . implode(', ', array_filter($tags)) . ')' : '';
                $eggs[$attr['id']] = $attr['name'] . $tagLabel;
            }
            return $eggs;
        }
    } catch (Throwable $e) {
        // Fallback to legacy v1 nests
    }

    // Legacy v1 fallback using $_SESSION['nests']
    if (isset($_SESSION['nests'])) {
        $nests = $_SESSION['nests'];
        foreach ($nests as $nest) {
            $attr = $nest['attributes'];
            $nestId = $attr['id'];
            if (!empty($attr['relationships']['eggs']['data'])) {
                foreach ($attr['relationships']['eggs']['data'] as $egg) {
                    $eggAttr = $egg['attributes'];
                    $eggs[$eggAttr['id']] = $eggAttr['name'] . ' (' . $nestId . ')';
                }
            }
        }
    }
    return $eggs;
}

function petrox_loadNests($params)
{
    $list = [];
    try {
        // Try v1 nests first
        $data = petroXApplicationApi($params, 'nests?include=eggs');
        if ($data['status_code'] == 200 && !empty($data['data'])) {
            $nests = $data['data'];
            foreach ($nests as $nest) {
                $attr = $nest['attributes'];
                $list[$attr['id']] = $attr['name'];
            }
            $_SESSION['nests'] = $nests;
            return $list;
        }
    } catch (Throwable $e) {
        // Fall through
    }

    // In Pterodactyl v2, nests are removed and replaced by tags
    try {
        $tagsData = petroXApplicationApiPaginated($params, 'tags');
        if ($tagsData['status_code'] === 200 && !empty($tagsData['data'])) {
            foreach ($tagsData['data'] as $tag) {
                $attr = $tag['attributes'];
                $list[$attr['id']] = 'Tag: ' . $attr['name'];
            }
            return $list;
        }
    } catch (Throwable $e) {
        // Fall through
    }

    $list[0] = 'None / Optional (Pterodactyl v2 uses Tags)';
    return $list;
}

function petroxAddHelpTooltip($message, $link)
{
    if ($link == "ports-ranges") {
        $link = 'https://github.com/Im-Aditya1/PetroX/wiki/Ports-Ranges';
    } elseif ($link == "default-variables") {
        $link = 'https://github.com/Im-Aditya1/PetroX/wiki/Default-Variables';
    } else {
        $link = 'https://github.com/Im-Aditya1/PetroX/wiki/General-Information#' . $link;
    }
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
    $diskDefault = $diskConfig ? 10 : 10240;

    $memoryConfig = PetroXInstance::get()->memory_as_gb;
    $memoryTitle = $memoryConfig ? "Memory (GB)" : "Memory (MB)";
    $memoryDescription = "Enter the amount of memory to assign to the server. The value will be interpreted as " . ($memoryConfig ? "gigabytes (GB)." : "megabytes (MB).");
    $memoryDefault = $memoryConfig ? 1 : 1024;

    $swapConfig = PetroXInstance::get()->swap_as_gb;
    $swapTitle = $swapConfig ? "Swap (GB)" : "Swap (MB)";
    $swapDescription = "Enter the amount of swap space to assign to the server. The value will be interpreted as " . ($swapConfig ? "gigabytes (GB)." : "megabytes (MB).");
    $swapDefault = $swapConfig ? 0.5 : 512;

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
            "Description" => petroxAddHelpTooltip("Select the positive location ID (min: 1) where the server will be deployed. Location ID 0 is invalid and rejected by Pterodactyl.", 'location-id'),
            "Type" => "text",
            "Size" => 25,
            'SimpleMode' => true,
            'Loader' => 'petrox_loadLocations',
        ],
        "dedicated_ip" => [
            "FriendlyName" => "Dedicated IP",
            "Description" => petroxAddHelpTooltip("Assign dedicated IP to the server (optional)", 'dedicated-ip'),
            "Type" => "yesno",
            "Size" => 25,
            'SimpleMode' => true,
        ],
        "nest_id" => [
            "FriendlyName" => "<span id='cNestId'></span> Nest ID",
            "Description" => petroxAddHelpTooltip("In Pterodactyl v1: Nest ID for grouping. In Pterodactyl v2: Nests are replaced by Tags and this field is optional.", 'nest-id'),
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
            "Description" => petroxAddHelpTooltip("Specify an offset for the Server Port, used for games requiring a specific increment above the SERVER_PORT. Enter '1' for games like ARK: Survival Evolved that need SERVER_PORT +1. To disable, enter '0'.", 'server-port-offset'),
            "Type" => "text",
            "default" => 0,
            "Size" => 25,
            'SimpleMode' => true,
        ],
        "feature_limits" => [
            'FriendlyName' => "Feature Limits",
            "Description" => petroxAddHelpTooltip("Feature limits in JSON format. Example: {\"databases\": 2, \"backups\": 5}.", 'feature-limits'),
            "Type" => "text",
            "default" => '0',
            "Size" => 25,
            'SimpleMode' => true,
        ],
        "threads" => [
            'FriendlyName' => "CPU Pinning",
            "Description" => petroxAddHelpTooltip("Enter specific CPU cores (e.g. 0, 0-1, 0,1,3) or leave blank to allow all cores.", 'cpu-pinning'),
            "Type" => "text",
            "Size" => 25,
            "default" => null,
            'SimpleMode' => true,
        ],
        "allow_server_configuration_edit" => [
            'FriendlyName' => "Server Configuration Access",
            "Description" => petroxAddHelpTooltip("Grant users the ability to edit their server's startup parameters and settings via the Pterodactyl API.", 'server-configuration-access'),
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
            "Description" => petroxAddHelpTooltip("Works like Dedicated IP but reserves only a specific port instead of the entire IP.", 'dedicated-port'),
            "Type" => "yesno",
            "Size" => 25,
            'SimpleMode' => true,
        ]
    ];
}

function petroXGetOption(array $params, $id, $default = NULL)
{
    $options = petrox_ConfigKeys();

    if (isset($options[$id])) {
        $friendlyName = $options[$id];
        if (isset($params['configoptions'][$friendlyName]) && $params['configoptions'][$friendlyName] !== '') {
            return $params['configoptions'][$friendlyName];
        } elseif (isset($params['customfields'][$friendlyName]) && $params['customfields'][$friendlyName] !== '') {
            return $params['customfields'][$friendlyName];
        }
    }

    if (isset($params['configoptions'][$id]) && $params['configoptions'][$id] !== '') {
        return $params['configoptions'][$id];
    } elseif (isset($params['customfields'][$id]) && $params['customfields'][$id] !== '') {
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
    $err = "";
    try {
        $response = petroXApplicationApi($params, 'nodes');

        if ($response['status_code'] !== 200) {
            $err = petroXFormatError($response, "Invalid status code: " . $response['status_code']);
        } else {
            $total = $response['meta']['pagination']['total'] ?? (isset($response['data']) ? count($response['data']) : 0);
            if ($total === 0) {
                $err = "Authentication successful, but no nodes are registered on this panel.";
            }
        }
    } catch (Exception $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall("PetroX-WHMCS", 'TEST CONNECTION', petroXSanitizeForLog($params), $e->getMessage(), $e->getTraceAsString());
        }
        $err = $e->getMessage();
    }

    return [
        "success" => $err === "",
        "error" => $err,
    ];
}

function petrox_CreateAccount(array $params)
{
    if (!empty($params['addonId']) && $params['addonId'] > 0) {
        $params['serviceid'] = $params['serviceid'] . '.' . $params['addonId'];
    }

    try {
        PetroXInstance::get()->service_id = $params['serviceid'];

        // Duplicate-provisioning prevention & idempotency: check if server already exists
        $existingServer = petroXGetServer($params, true);
        if ($existingServer) {
            $customFieldId = petroXGetCustomFieldId($params);
            petroXUpdateCustomField($params, $customFieldId, $existingServer['uuid']);
            if (!empty($existingServer['relationships']['allocations']['data'])) {
                petroX_getServerIPAndPort($existingServer['relationships']['allocations']['data'], $existingServer['allocation']);
                petroX_updateServerDomain($params);
            }
            return 'success';
        }

        // Early validation: Egg ID and Location ID must be valid positive integers
        $eggId = petroXGetOption($params, 'egg_id');
        if (empty($eggId) || (int)$eggId <= 0) {
            throw new Exception('Missing or invalid Egg ID. Please specify a valid Egg ID in the product configuration or configurable options.');
        }

        $location_id = petroXGetOption($params, 'location_id');
        if ((string)$location_id === '0' || $location_id === 0) {
            throw new Exception('Location ID 0 is rejected by Pterodactyl. Please specify a valid positive Location ID (min: 1).');
        }
        $locationIds = array_values(array_filter(
            array_map('intval', explode(',', (string)$location_id)),
            fn($locId) => $locId > 0
        ));
        if (empty($locationIds)) {
            throw new Exception('Invalid or missing Location ID (' . ($location_id ?: 'none') . '). Automatic deployment requires a valid positive Location ID (min: 1). Location ID 0 is rejected by Pterodactyl.');
        }

        $portsJson = petroXGetOption($params, 'ports_ranges');
        $portsJson = trim($portsJson ?? '');
        $portsArray = [];
        if ($portsJson !== '') {
            $pattern = '/^(\d+-\d+)(,\d+-\d+)*$/';
            if (!preg_match_all($pattern, $portsJson, $matches)) {
                $portsArray = json_decode($portsJson, true);
                if (!is_array($portsArray)) {
                    throw new Exception('Failed to create server: ports_ranges is not in valid JSON format.');
                }
            }
        }

        $customFieldId = petroXGetCustomFieldId($params);

        // User lookup or synchronization
        $userResult = PetroXInstance::get()->getPterodactylUser($params, [
            'username' => petroXGetOption($params, 'username', petroXGenerateUsername()),
            'id' => $params['clientsdetails']['client_id'],
            'email' => $params['clientsdetails']['email'],
            'firstname' => $params['clientsdetails']['firstname'],
            'lastname' => $params['clientsdetails']['lastname'],
        ]);

        if ($userResult['status_code'] === 200 || $userResult['status_code'] === 201) {
            if (!isset($userResult['attributes']['id'])) {
                throw new Exception("Failed to retrieve the client's Pterodactyl account. Enable module debug log for details.");
            }
            $userId = (int)$userResult['attributes']['id'];
        } else {
            throw new Exception('Failed to retrieve or create user: ' . petroXFormatError($userResult));
        }

        $nestId = petroXGetOption($params, 'nest_id');
        $eggData = petroXGetEggData($params, $eggId, $nestId);
        if ($eggData['status_code'] !== 200) {
            throw new Exception('Failed to get egg data: ' . petroXFormatError($eggData));
        }

        // Environment variables
        $environment = [];
        $default_variables = petroXGetOption($params, 'default_variables');
        $default_variables = json_decode($default_variables ?? '', true) ?: [];

        $eggVariables = $eggData['attributes']['relationships']['variables']['data'] ?? [];
        foreach ($eggVariables as $val) {
            $attr = $val['attributes'];
            $var = $attr['env_variable'];
            $default = $attr['default_value'] ?? '';
            $friendlyName = petroXGetOption($params, $attr['name']);
            $envName = petroXGetOption($params, $attr['env_variable']);

            if (isset($friendlyName)) {
                $environment[$var] = (string)$friendlyName;
            } elseif (isset($envName)) {
                $environment[$var] = (string)$envName;
            } elseif (isset($default_variables[$var]) && !in_array($default_variables[$var], PetroXInstance::get()->dynamic_variables, true)) {
                $environment[$var] = (string)$default_variables[$var];
            } else {
                $environment[$var] = (string)$default;
            }
        }

        if ($default_variables) {
            foreach ($default_variables as $default_variable => $default_variable_value) {
                if (in_array($default_variable_value, PetroXInstance::get()->dynamic_variables, true)) {
                    PetroXInstance::get()->dynamic_environment_array[$default_variable] = $default_variable_value;
                }
            }
        }

        $id = (string)$params['serviceid'];
        $name = petroXGetOption($params, 'server_name', petroXGenerateUsername() . '_' . $id);
        [$memory, $swap, $disk] = petroXGetMemorySwapAndDisk($params);

        $io = (int)petroXGetOption($params, 'io', 500);
        if ($io < 10 || $io > 1000) {
            $io = 500; // Pterodactyl IO limits are 10 - 1000
        }
        $cpu = (int)petroXGetOption($params, 'cpu', 100);

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

        if ($portsArray) {
            $port_range = isset($portsArray['SERVER_PORT']) ? explode(',', $portsArray['SERVER_PORT']) : [];
        } else {
            $port_range = !empty($portsJson) ? explode(',', $portsJson) : [];
        }

        // Docker image fallback (v2 uses docker_images array or docker_image string)
        $defaultImage = $eggData['attributes']['docker_image'] ?? '';
        if (empty($defaultImage) && !empty($eggData['attributes']['docker_images']) && is_array($eggData['attributes']['docker_images'])) {
            $defaultImage = reset($eggData['attributes']['docker_images']);
        }
        $image = petroXGetOption($params, 'image', $defaultImage);
        $startup = petroXGetOption($params, 'startup', $eggData['attributes']['startup'] ?? '');
        $databases = petroXGetOption($params, 'databases');
        $maximumAllocations = (int)petroXGetOption($params, 'allocations', 0);
        $backups = petroXGetOption($params, 'backups');
        $oom_disabled = (bool)petroXGetOption($params, 'oom_disabled');

        $threadsRaw = petroXGetOption($params, 'threads');
        $threads = !empty($threadsRaw) ? petrox_validateThreads($threadsRaw) : null;

        $serverData = [
            'name' => $name,
            'user' => $userId,
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
                'threads' => $threads,
            ],
            'feature_limits' => [
                'databases' => ($databases !== '' && $databases !== null) ? (int)$databases : 0,
                'allocations' => $maximumAllocations,
                'backups' => ($backups !== '' && $backups !== null) ? (int)$backups : 0,
            ],
            'deploy' => [
                'locations' => $locationIds,
                'dedicated_ip' => $dedicated_ip,
                'port_range' => array_values(array_map('strval', $port_range)),
            ],
            'environment' => $environment,
            'start_on_completion' => true,
            'skip_scripts' => false,
            'external_id' => (string)$params['serviceid'],
        ];

        // Only include nest for v1 if explicitly configured and present in session
        if (!empty($nestId) && (int)$nestId > 0 && isset($_SESSION['nests'])) {
            $serverData['nest'] = (int)$nestId;
        }

        $feature_limits = petroXGetOption($params, 'feature_limits');
        $feature_limits = json_decode($feature_limits ?? '', true);
        if ($feature_limits && is_array($feature_limits)) {
            foreach ($feature_limits as $featureName => $default) {
                $value = petroXGetOption($params, $featureName, $default);
                $feature_limits[$featureName] = $value;
            }
            $serverData['feature_limits'] = array_merge($serverData['feature_limits'], $feature_limits);
        }

        $server = petroXApplicationApi($params, 'servers?include=allocations', $serverData, 'POST');

        if ($server['status_code'] !== 201) {
            throw new Exception('Failed to create server on Pterodactyl: ' . petroXFormatError($server));
        }

        $serverId = $server['attributes']['id'];
        $_SERVER_ID = $server['attributes']['uuid'];

        $serverAllocations = $server['attributes']['relationships']['allocations']['data'] ?? [];
        if (empty($serverAllocations)) {
            $fetchServer = petroXApplicationApi($params, 'servers/' . $serverId . '?include=allocations');
            if ($fetchServer['status_code'] === 200 && !empty($fetchServer['attributes']['relationships']['allocations']['data'])) {
                $serverAllocations = $fetchServer['attributes']['relationships']['allocations']['data'];
            }
        }
        $allocation = $server['attributes']['allocation'];
        petroX_getServerIPAndPort($serverAllocations, $allocation);

        $serverNode = $server['attributes']['node'];
        $foundPorts = [];

        if ($portsArray) {
            petroXGetNodeAllocations($params, $serverNode);
            petroXProcessAllocations($eggData, $portsArray);
        }

        if (!PetroXInstance::get()->variables) {
            petroXLog('VARIABLES', 'No variables found.', $portsArray);
        }

        if (!PetroXInstance::get()->node_allocations) {
            petroXLog('NODE ALLOCATIONS', 'Node allocations not found.', [$serverNode]);
        }

        if (PetroXInstance::get()->variables && PetroXInstance::get()->node_allocations) {
            $ips = petroXMakeIParray();
            $foundPorts = petroXfindPorts($portsArray, $ips);
        }

        if (!$foundPorts && PetroXInstance::get()->variables && PetroXInstance::get()->node_allocations) {
            petroXLog('Ports not found', 'Ports not found.', [
                'results' => PetroXInstance::get()->fetchedResults,
                'variables' => PetroXInstance::get()->variables
            ]);
        }

        if ($foundPorts) {
            petroXLog('Found ports', 'Found ports.', $foundPorts);
            $_SERVER_PORT_ID = $serverAllocations[0]['attributes']['id'] ?? $allocation;

            $allocationArray = [];
            $allocationArray['allocation'] = $_SERVER_PORT_ID;
            if (isset($foundPorts['SERVER_PORT'])) {
                $allocationArray['allocation'] = (int)$foundPorts['SERVER_PORT']['id'];
                $allocationArray['remove_allocations'] = [(int)$_SERVER_PORT_ID];
            }

            $portEnv = [];
            $additional = [];
            foreach ($foundPorts as $key => $var) {
                $portEnv[$key] = (string)$var['port'];
                $additional[] = (int)$var['id'];
                $maximumAllocations++;
            }

            if (PetroXInstance::get()->getDynamicEnvironmentArray()) {
                PetroXInstance::get()->addFileLog(PetroXInstance::get()->getDynamicEnvironmentArray(), 'Setting Dynamic Environment');
                foreach (PetroXInstance::get()->getDynamicEnvironmentArray() as $environmentName => $variableName) {
                    if (isset($portEnv[$variableName])) {
                        $portEnv[$environmentName] = $portEnv[$variableName];
                    }
                }
            }
            if (isset($portEnv['SERVER_PORT'])) {
                unset($portEnv['SERVER_PORT']);
            }
            $allocationArray['add_allocations'] = $additional;

            $newServerArray = array_merge([
                'allocation' => $allocationArray['allocation'],
                'memory' => (int)$memory,
                'swap' => (int)$swap,
                'io' => (int)$io,
                'cpu' => (int)$cpu,
                'disk' => (int)$disk,
                'threads' => $threads,
                'oom_disabled' => $oom_disabled,
                'feature_limits' => [
                    'databases' => ($databases !== '' && $databases !== null) ? (int)$databases : 0,
                    'allocations' => (int)$maximumAllocations,
                    'backups' => ($backups !== '' && $backups !== null) ? (int)$backups : 0,
                ],
            ], $allocationArray);

            if ($feature_limits && is_array($feature_limits)) {
                $newServerArray['feature_limits'] = array_merge($newServerArray['feature_limits'], $feature_limits);
            }

            $updateResult = petroXApplicationApi($params, 'servers/' . $serverId . '/build?include=allocations', $newServerArray, 'PATCH');
            if ($updateResult['status_code'] !== 200) {
                throw new Exception('Failed to update build of the server: ' . petroXFormatError($updateResult));
            }

            $allocation = $updateResult['attributes']['allocation'];
            $serverAllocations = $updateResult['attributes']['relationships']['allocations']['data'] ?? [];
            petroX_getServerIPAndPort($serverAllocations, $allocation);

            $startupCommand = $server['attributes']['container']['startup_command'] ?? ($server['attributes']['container']['environment']['STARTUP'] ?? $startup);
            petroXApplicationApi($params, 'servers/' . $serverId . '/startup', [
                'startup' => $startupCommand,
                'egg' => $server['attributes']['egg'] ?? (int)$eggId,
                'image' => $server['attributes']['container']['image'] ?? $image,
                'environment' => array_merge($serverData['environment'], $portEnv),
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
    if (!empty($params['addonId']) && $params['addonId'] > 0) {
        $params['serviceid'] = $params['serviceid'] . '.' . $params['addonId'];
    }

    try {
        $serverId = petroXGetServer($params);
        if (!$serverId) {
            throw new Exception('Failed to suspend server because it does not exist on the panel.');
        }

        $suspendResult = petroXApplicationApi($params, 'servers/' . $serverId . '/suspend', [], 'POST');
        if ($suspendResult['status_code'] !== 204 && $suspendResult['status_code'] !== 200) {
            throw new Exception('Failed to suspend the server: ' . petroXFormatError($suspendResult));
        }
    } catch (Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function petrox_UnsuspendAccount(array $params)
{
    if (!empty($params['addonId']) && $params['addonId'] > 0) {
        $params['serviceid'] = $params['serviceid'] . '.' . $params['addonId'];
    }

    try {
        $serverId = petroXGetServer($params);
        if (!$serverId) {
            throw new Exception('Failed to unsuspend server because it does not exist on the panel.');
        }

        $unsuspendResult = petroXApplicationApi($params, 'servers/' . $serverId . '/unsuspend', [], 'POST');
        if ($unsuspendResult['status_code'] !== 204 && $unsuspendResult['status_code'] !== 200) {
            throw new Exception('Failed to unsuspend the server: ' . petroXFormatError($unsuspendResult));
        }
    } catch (Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function petrox_TerminateAccount(array $params)
{
    if (!empty($params['addonId']) && $params['addonId'] > 0) {
        $params['serviceid'] = $params['serviceid'] . '.' . $params['addonId'];
    }

    try {
        $serverId = petroXGetServer($params);
        if (!$serverId) {
            return 'success'; // Idempotent: already terminated
        }

        $deleteResult = petroXApplicationApi($params, 'servers/' . $serverId, [], 'DELETE');
        if ($deleteResult['status_code'] !== 204 && $deleteResult['status_code'] !== 200) {
            // Attempt force deletion if supported
            $forceResult = petroXApplicationApi($params, 'servers/' . $serverId . '/force', [], 'DELETE');
            if ($forceResult['status_code'] !== 204 && $forceResult['status_code'] !== 200) {
                throw new Exception('Failed to terminate the server: ' . petroXFormatError($deleteResult));
            }
        }
    } catch (Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function petrox_ChangePackage(array $params)
{
    if (!empty($params['addonId']) && $params['addonId'] > 0) {
        $params['serviceid'] = $params['serviceid'] . '.' . $params['addonId'];
    }

    try {
        $serverData = petroXGetServer($params, true, 'node,allocations');
        if (!$serverData) {
            throw new Exception('Failed to change package: server does not exist on the panel.');
        }
        $serverId = $serverData['id'];

        $currentLocationId = $serverData['relationships']['node']['attributes']['location_id'] ?? null;
        $newLocationId = (int)petroXGetOption($params, 'location_id');
        $needsTransfer = $newLocationId && $currentLocationId && ($newLocationId !== $currentLocationId);

        [$memory, $swap, $disk] = petroXGetMemorySwapAndDisk($params);

        $io = (int)petroXGetOption($params, 'io', 500);
        if ($io < 10 || $io > 1000) {
            $io = 500;
        }
        $cpu = (int)petroXGetOption($params, 'cpu', 100);
        $databases = petroXGetOption($params, 'databases');
        $allocations = petroXGetOption($params, 'allocations');
        $backups = petroXGetOption($params, 'backups');
        $oom_disabled = (bool)petroXGetOption($params, 'oom_disabled');

        $threadsRaw = petroXGetOption($params, 'threads');
        $threads = !empty($threadsRaw) ? petrox_validateThreads($threadsRaw) : null;

        // ── Step 1: Update build configuration (resources) ──
        $updateData = [
            'allocation' => $serverData['allocation'],
            'memory' => (int)$memory,
            'swap' => (int)$swap,
            'io' => (int)$io,
            'cpu' => (int)$cpu,
            'disk' => (int)$disk,
            'threads' => $threads,
            'oom_disabled' => $oom_disabled,
            'feature_limits' => [
                'databases' => ($databases !== '' && $databases !== null) ? (int)$databases : 0,
                'allocations' => (int)$allocations,
                'backups' => ($backups !== '' && $backups !== null) ? (int)$backups : 0,
            ],
        ];

        $feature_limits = petroXGetOption($params, 'feature_limits');
        $feature_limits = json_decode($feature_limits ?? '', true);
        if ($feature_limits && is_array($feature_limits)) {
            $updateData['feature_limits'] = array_merge($updateData['feature_limits'], $feature_limits);
        }

        $updateResult = petroXApplicationApi($params, 'servers/' . $serverId . '/build?include=allocations', $updateData, 'PATCH');
        if ($updateResult['status_code'] !== 200) {
            throw new Exception('Failed to update server build: ' . petroXFormatError($updateResult));
        }
        $allocation = $updateResult['attributes']['allocation'];
        $serverAllocations = $updateResult['attributes']['relationships']['allocations']['data'] ?? [];

        petroX_getServerIPAndPort($serverAllocations, $allocation);

        // ── Step 2: Update startup configuration ──
        $nestId = petroXGetOption($params, 'nest_id');
        $eggId = petroXGetOption($params, 'egg_id');
        if (empty($eggId)) {
            $eggId = $serverData['egg'];
        }

        $eggData = petroXGetEggData($params, $eggId, $nestId);
        if ($eggData['status_code'] !== 200) {
            throw new Exception('Failed to get egg data for package change: ' . petroXFormatError($eggData));
        }

        $default_variables = petroXGetOption($params, 'default_variables');
        $default_variables = json_decode($default_variables ?? '', true) ?: [];
        $environment = [];
        $eggVariables = $eggData['attributes']['relationships']['variables']['data'] ?? [];
        foreach ($eggVariables as $val) {
            $attr = $val['attributes'];
            $var = $attr['env_variable'];
            $default = $attr['default_value'] ?? '';
            $friendlyName = petroXGetOption($params, $attr['name']);
            $envName = petroXGetOption($params, $attr['env_variable']);

            if (isset($friendlyName)) {
                $environment[$var] = (string)$friendlyName;
            } elseif (isset($envName)) {
                $environment[$var] = (string)$envName;
            } elseif (isset($default_variables[$var]) && !in_array($default_variables[$var], PetroXInstance::get()->dynamic_variables, true)) {
                $environment[$var] = (string)$default_variables[$var];
            } else {
                $environment[$var] = (string)$default;
            }
        }

        $defaultImage = $eggData['attributes']['docker_image'] ?? '';
        if (empty($defaultImage) && !empty($eggData['attributes']['docker_images']) && is_array($eggData['attributes']['docker_images'])) {
            $defaultImage = reset($eggData['attributes']['docker_images']);
        }
        $image = petroXGetOption($params, 'image', $defaultImage);
        $startup = petroXGetOption($params, 'startup', $eggData['attributes']['startup'] ?? '');
        $startupUpdateData = [
            'environment' => $environment,
            'startup' => $startup,
            'egg' => (int)$eggId,
            'image' => $image,
            'skip_scripts' => false,
        ];
        $startupResult = petroXApplicationApi($params, 'servers/' . $serverId . '/startup', $startupUpdateData, 'PATCH');
        if ($startupResult['status_code'] !== 200) {
            throw new Exception('Failed to update startup configuration: ' . petroXFormatError($startupResult));
        }

        // ── Step 3: Transfer server if location changed ──
        if ($needsTransfer) {
            $portsJson = petroXGetOption($params, 'ports_ranges');
            $portsJson = trim($portsJson ?? '');
            $portsArray = [];
            if ($portsJson !== '') {
                $pattern = '/^(\d+-\d+)(,\d+-\d+)*$/';
                if (!preg_match_all($pattern, $portsJson, $matches)) {
                    $portsArray = json_decode($portsJson, true) ?: [];
                }
            }

            $transferTarget = petroXFindTransferTarget($params, $newLocationId, $portsArray);
            if (!$transferTarget) {
                throw new Exception('Failed to transfer server: could not find any nodes with available allocations in location ' . $newLocationId . '.');
            }

            $transferResult = petroXApplicationApi($params, 'servers/' . $serverId . '/transfer', [
                'node_id' => (int)$transferTarget['node_id'],
                'allocation_id' => (int)$transferTarget['allocation_id'],
                'additional_allocations' => [],
            ], 'POST');

            // If Application API returns 404 (endpoint removed/relocated in v2), try Admin API if key is present
            if ($transferResult['status_code'] === 404 && !empty($params['serveraccesshash'])) {
                $transferResult = petroXClientApi($params, '../admin/servers/' . $serverId . '/transfer', [
                    'node_id' => (int)$transferTarget['node_id'],
                    'allocation_id' => (int)$transferTarget['allocation_id'],
                    'additional_allocations' => [],
                ], 'POST');
            }

            if ($transferResult['status_code'] !== 202 && $transferResult['status_code'] !== 200) {
                throw new Exception('Failed to transfer server to new location: ' . petroXFormatError($transferResult));
            }

            PetroXInstance::get()->server_ip = $transferTarget['allocation']['ip'];
            PetroXInstance::get()->server_port = $transferTarget['allocation']['port'];
            if (PetroXInstance::get()->use_alias_ip && !empty($transferTarget['allocation']['alias'])) {
                PetroXInstance::get()->server_alias_ip = $transferTarget['allocation']['alias'];
            }
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
}

function petrox_LoginLink(array $params)
{
    if ($params['moduletype'] !== 'petrox') return;
    try {
        $server = petroXGetServer($params, true);
        if (!$server) return;

        $hostname = petroXGetHostname($params);
        $identifier = $server['identifier'] ?? ($server['uuid'] ?? '');
        $button1 = '<a class="btn btn-info text-uppercase" href="' . $hostname . '/server/' . $identifier . '" target="_blank"><i class="fas fa-eye fa-fw"></i> View Server</a>';
        $button2 = '<a class="btn btn-primary text-uppercase" href="' . $hostname . '/admin/servers/view/' . $server['id'] . '" target="_blank"><i class="fas fa-sign-in fa-fw"></i> Admin View</a>';

        echo '<script>
            const link1 = `' . $button1 . '`;
            const link2 = `' . $button2 . '`;
          </script>';

        echo '<script>
            jQuery(document).ready(function($) {
                $("#btnLoginLinkTrigger").hide();
                var buttonGroup = $("#btnLoginLinkTrigger").parent();
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
        $serverId = $params['customfields']['UUID (Server ID)'] ?? '';
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

        $serverAllocations = $serverData['relationships']['allocations']['data'] ?? [];
        petroX_getServerIPAndPort($serverAllocations, $serverData['allocation']);

        if ($serverId == '') {
            $serverId = $serverData['uuid'];
            $customFieldId = petroXGetCustomFieldId($params);
            petroXUpdateCustomField($params, $customFieldId, $serverData['uuid']);
        }

        if (empty($params['domain'])) {
            petroX_updateServerDomain($params);
        }

        $serverIp = $params['domain'] ?? '';
        $actionUrl = "https://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '');
        $identifier = $serverData['identifier'] ?? ($serverData['uuid'] ?? '');
        $vars = [
            'moduleDir' => __DIR__,
            'serviceId' => $params['serviceid'],
            'serverData' => $serverData,
            'serverIp' => $serverIp,
            'serverId' => $serverId,
            'isAdmin' => $isAdmin,
            'allowStartUpEdit' => $allowStartUpEdit,
            'allowSettingsEdit' => $allowSettingsEdit,
            'serviceUrl' => $hostname . '/server/' . $identifier,
        ];

        [$variables, $meta] = petroXGetServerVariables($params, $serverData['uuid']);

        $environment = $serverData['container']['environment'] ?? [];
        $editableVariables = [];
        if (!empty($variables) && is_array($variables)) {
            foreach ($variables as $variable) {
                $attr = $variable['attributes'] ?? [];
                if (!empty($attr['is_editable'])) {
                    $pattern = '/(?<!<a href=")(https?:\/\/[^\s"]+)(?!")/i';
                    $replacement = '<a href="$1" target="_blank">$1</a>';
                    $attr['description'] = preg_replace($pattern, $replacement, $attr['description'] ?? '');

                    $rules = $attr['rules'] ?? '';
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
