<?php


use Illuminate\Database\Capsule\Manager as Capsule;

class PetroXInstance
{
    private static PetroXInstance|null $instance = null;
    public $swap_as_gb = false;
    public $disk_as_gb = false;
    public $memory_as_gb = false;
    public $enable_client_area_password_changer = true;
    public $enable_whmcs_user_sync = false;

    public $allow_startup_edit = false;
    public $allow_variables_edit = false;

    public $game_panel = "pterodactyl";

   // public $server_port_offset = 0;

    public $dynamic_variables = [];

    public $dynamic_environment_array = [];

    public $jsPath = '';
    public $cssPath = '';

    public array $panelCurlConfig = [
        'user' => 'PetroX',
        'accept' => 'Application/vnd.pterodactyl.v1+json'
    ];

    public array $curlRequestSettings = [
        'pterodactyl' => [
            'user' => 'PetroX',
            'accept' => 'Application/vnd.pterodactyl.v1+json'
        ],
        'wisp' => [
            'user' => 'WISP',
            'accept' => 'Application/vnd.wisp.v1+json',
        ]
    ];

    public $fetchingPagination = [];
    /**
     * @var true
     */
    public bool $fetching = true;
    public int $fetchingNextPage = 1;
    public array $fetchedResults = [];
    public array $hooksData = [];
    public bool $use_alias_ip = false;
    public $service_id = 0;
    /**
     * @var false|mixed
     */
    public $dedicated_ip = false;
    public string $server_ip = "0";
    public int $server_port = 0;
    public string|bool $server_alias_ip = false;
    /**
     * @var array|mixed
     */
    public array $node = [];
    public array $node_allocations = [];
    public array $variables = [];
    private array|false $server = false;

    public function __construct()
    {

        global $CONFIG;
        $data = file_get_contents(dirname(__FILE__) . '/config.json');
        $data = json_decode($data, true);
        if (!$data) {
            throw new Exception('PetroX Config file not valid json format!');
        }
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
        if (isset($this->curlRequestSettings[$this->game_panel])) {
            $this->panelCurlConfig = $this->curlRequestSettings[$this->game_panel];
        }

        $whmcsBaseUrl = rtrim($CONFIG['SystemURL'], '/');
        $this->jsPath = $whmcsBaseUrl . '/modules/servers/petrox/petrox.js?v=' . time();
        $this->cssPath = $whmcsBaseUrl . '/modules/servers/petrox/petrox.css?v=' . time();
    }


    public static function get(): ?PetroXInstance
    {
        if (self::$instance === null) {
            self::$instance = new PetroXInstance();
        }
        return self::$instance;
    }

    public function getServer()
    {
        if (!$this->server) {
            $server = Capsule::table('tblservers')
                ->where('type', 'petrox')
                ->first();
            $this->server = [
                'serverhostname' => $server->hostname,
                'serverusername' => $server->username,
                'serverpassword' => decrypt($server->password),
                'serveraccesshash' => $server->accesshash,
                'serversecure' => $server->secure,
                'serverport' => $server->port,
            ];
        }
        return $this->server;
    }

    public function getClient($userId)
    {
        if (isset($_SESSION['uid'])) return $this->getClientById($_SESSION['uid']);
        $client = Capsule::table('tblusers_clients')
            ->where('auth_user_id', '=', $userId)
            ->orderBy('last_login', 'desc')
            ->first();
        if ($client) return $this->getClientById($client->client_id);
        logModuleCall("PetroX-WHMCS", 'Could not find valid client for the given user id.', ['userId' => $userId], '', '');
        return false;
    }

    private function getClientById($id)
    {
        try {
            return WHMCS\User\Client::findOrFail($id);
        } catch (Exception $e) {
            logModuleCall("PetroX-WHMCS", 'Could not find valid client data for the given client id.', ['clientId' => $id], '', '');
            return false;
        }

    }

    public function changePterodactylPassword($userResult, $params, $password)
    {
        $updateResult = petroXApplicationApi($params, 'users/' . $userResult['attributes']['id'], [
            'username' => $userResult['attributes']['username'],
            'email' => $userResult['attributes']['email'],
            'first_name' => $userResult['attributes']['first_name'],
            'last_name' => $userResult['attributes']['last_name'],
            'password' => $password
        ], 'PATCH');
        if ($updateResult['status_code'] !== 200) throw new Exception('Failed to change password, received error code: ' . $updateResult['status_code'] . '.');
    }

    public function updatePterodactylUserData($userResult, $params, $column): void
    {
        $defaultAttributes = [
            'username' => $userResult['attributes']['username'],
            'email' => $userResult['attributes']['email'],
            'first_name' => $userResult['attributes']['first_name'],
            'last_name' => $userResult['attributes']['last_name'],
        ];
        $arr = array_merge($defaultAttributes, $column);

        $updateResult = petroXApplicationApi($params, 'users/' . $userResult['attributes']['id'], $arr, 'PATCH');

        if ($updateResult['status_code'] !== 200) {
            $message = 'Failed to change ' . json_encode($column) . ', received error code: ' . $updateResult['status_code'] . '.';
            if (isset($updateResult['errors']) && is_array($updateResult['errors'])) {
                $message = 'Failed to update user details: ';
                foreach ($updateResult['errors'] as $error) {
                    $message .= $error['detail'] . '<br>';
                }
            }
            throw new Exception($message);
        }

    }

    public function getPterodactylUser($params, array $client = [], $create = true)
    {
        $userResult = petroXApplicationApi($params, 'users/external/' . $client['id']);
        if ($userResult['status_code'] === 404) {
            if (!isset($client['email'])) return $userResult;

            if (PetroXInstance::get()->game_panel == 'wisp') {
                $userResult = petroXApplicationApi($params, 'users?search=' . urlencode($client['email']));
            } elseif (PetroXInstance::get()->game_panel == 'pterodactyl') {
                $userResult = petroXApplicationApi($params, 'users?filter[email]=' . urlencode($client['email']));
            }

            if ($create === true && $userResult['meta']['pagination']['total'] === 0) {
                $userResult = $this->createPteroUser($client, $params);
            } else {
                if ($userResult['meta']['pagination']['total'] === 0) {
                    $userResult['status_code'] = 404;
                }
                if ($userResult['status_code'] !== 404) {
                    foreach ($userResult['data'] as $key => $value) {
                        if (strtolower($value['attributes']['email']) == strtolower($client['email'])) {
                            $userResult = $value;
                            $userResult['status_code'] = 200;
                            //Let's update the external_id to match the actual client id.
                            $arr['external_id'] = (string)$client['id'];
                            if (isset($client['password'])) {
                                $arr['password'] = $client['password'];
                            }
                            $this->updatePterodactylUserData($userResult, $params, $arr);
                            break;
                        }
                    }
                }

                if ($userResult['status_code'] === 404) {
                    $userResult = $this->createPteroUser($client, $params);
                }

            }
        }
        return $userResult;
    }


    public function addFileLog($data, $title = 'Log')
    {
        $logDir = __DIR__ . '/log';
        $file = sprintf('%s/.%s-%s.log', $logDir, $this->service_id, date('Y-m-d'));

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
            chown($logDir, fileowner(__FILE__));
            chgrp($logDir, filegroup(__FILE__));
        }
        $content = PHP_EOL;
        $content .= PHP_EOL;
        $content .= 'START: ' . $title . PHP_EOL;
        $content .= 'Time: ' . date('Y-m-d H:i:s');
        $content .= PHP_EOL;
        $content .= print_r($data, true);
        $content .= PHP_EOL;
        $content .= 'END: ' . $title;
        file_put_contents($file, $content, FILE_APPEND);
    }

    public function getDynamicEnvironmentArray(): array
    {
        return $this->dynamic_environment_array;
    }

    /**
     * @param array $client
     * @param array $arr
     * @param $params
     * @return mixed
     */
    private function createPteroUser(array $client, $params): mixed
    {
        $arr = [
            'username' => $client['username'],
            'email' => $client['email'],
            'first_name' => $client['firstname'],
            'last_name' => $client['lastname'],
            'external_id' => (string)$client['id'],
        ];
        if (isset($client['password'])) {
            $arr['password'] = $client['password'];
        }
        return petroXApplicationApi($params, 'users', $arr, 'POST');
    }
}

function petroXLog(string $action, string $string, array $array)
{
    logModuleCall("PetroX-WHMCS", $action, $string, $array);
}

function petroXGetHostname(array $params)
{

    $hostname = $params['serverhostname'];

    if ($hostname === '') throw new Exception('Could not find the panel\'s hostname - did you configure server group for the product?');
    // For whatever reason, WHMCS converts some characters of the hostname to their literal meanings (- => dash, etc) in some cases
    foreach ([
                 'DOT' => '.',
                 'DASH' => '-',
             ] as $from => $to) {
        $hostname = str_replace($from, $to, $hostname);
    }
    if (ip2long($hostname) !== false) $hostname = 'http://' . $hostname;
    else $hostname = ($params['serversecure'] ? 'https://' : 'http://') . $hostname;
    return rtrim($hostname, '/');
}

function petroXApiHandler(mixed $method, array $data, CurlHandle|false $curl, array $headers, mixed $dontLog, string $url): mixed
{
    if ($method === 'POST' || $method === 'PATCH') {
        $jsonData = json_encode($data);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);
        $headers[] = "Content-Type: application/json";
        $headers[] = "Content-Length: " . strlen($jsonData);
    }

    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($curl);
    $responseData = json_decode($response, true);
    $responseData['status_code'] = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($responseData['status_code'] === 0 && !$dontLog) logModuleCall("PetroX-WHMCS", "CURL ERROR", curl_error($curl), "");

    curl_close($curl);
    if (isset($data['password'])) {
        //let's unset that ?
        unset($data['password']);
    }
    if (!$dontLog) logModuleCall("PetroX-WHMCS", $method . " - " . $url,
        isset($data) ? json_encode($data) : "",
        print_r($responseData, true));

    return $responseData;
}

/**
 * @param string $url
 * @param mixed $method
 * @return CurlHandle|false
 */
function getCurl_init(string $url, mixed $method): false|CurlHandle
{
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
    curl_setopt($curl, CURLOPT_USERAGENT, PetroXInstance::get()->panelCurlConfig['user'] . "-WHMCS");
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_POSTREDIR, CURL_REDIR_POST_301);
    curl_setopt($curl, CURLOPT_TIMEOUT, 5);
    return $curl;
}

function petroXApplicationApi(array $params, $endpoint, array $data = [], $method = "GET", $dontLog = false)
{
    $url = petroXGetHostname($params) . '/api/application/' . $endpoint;

    $curl = getCurl_init($url, $method);

    $headers = [
        "Authorization: Bearer " . $params['serverpassword'],
        "Accept: " . PetroXInstance::get()->panelCurlConfig['accept'],
    ];

    return petroXApiHandler($method, $data, $curl, $headers, $dontLog, $url);
}


function petroXClientApi(array $params, $endPoint, array $data = [], $method = "GET", $dontLog = false)
{
    $url = petroXGetHostname($params) . '/api/client/' . $endPoint;
    $curl = getCurl_init($url, $method);

    $headers = [
        "Authorization: Bearer " . $params['serveraccesshash'],
        "Accept: " . PetroXInstance::get()->panelCurlConfig['accept'],
    ];

    return petroXApiHandler($method, $data, $curl, $headers, $dontLog, $url);
}

function petroXGetMemorySwapAndDisk($params)
{

    $memory = petroXGetOption($params, 'memory');
    if (!is_numeric($memory) && ($memory != '0' && $memory != '')) {
        throw new Exception('Invalid memory value provided. Please enter a valid integer. For example: 1, 2, 3, etc. Don\'t forgot to check your Configuration Options also.');
    }
    if (PetroXInstance::get()->memory_as_gb) {
        $memory = petroXConvertToMB($memory);
    }

    $swap = (int)petroXGetOption($params, 'swap');
    if (!is_numeric($swap) && ($swap != '0' && $swap != '')) {
        throw new Exception('Invalid swap value provided. Please enter a valid integer. For example: 1, 2, 3, etc. Don\'t forgot to check your Configuration Options also.');
    }

    if (PetroXInstance::get()->swap_as_gb) {
        $swap = petroXConvertToMB($swap);
    }

    $disk = (int)petroXGetOption($params, 'disk');
    if (!is_numeric($disk) && ($disk != '0' && $disk != '')) {
        throw new Exception('Invalid disk value provided. Please enter a valid integer. For example: 1, 2, 3, etc. Don\'t forgot to check your Configuration Options also.');
    }
    if (PetroXInstance::get()->disk_as_gb) {
        $disk = petroXConvertToMB($disk);
    }

    return [$memory, $swap, $disk];
}

function petroXGetServer(array $params, $raw = false, $include = false)
{
    if ($include) {
        $include = '?include=' . $include;
    }
    $serverResult = petroXApplicationApi($params, 'servers/external/' . $params['serviceid'] . $include, [], 'GET', true);

    if ($serverResult['status_code'] === 200) {
        if ($raw) return $serverResult['attributes'];
        else return $serverResult['attributes']['id'];
    } else if ($serverResult['status_code'] === 500) {
        throw new Exception('Failed to get server, panel errored. Check panel logs for more info.');
    }
    return false;
}

function petroXGetServerVariables(array $params, $serverId)
{
    $data = petroXClientApi($params, 'servers/' . $serverId . '/startup', [], 'GET', true);
    if ($data['status_code'] === 200) {
        return [$data['data'], $data['meta']];
    } else if ($data['status_code'] === 500) {
        throw new Exception('Failed to get server, panel errored. Check panel logs for more info.');
    }
    return false;
}

function petroXGetClientServer($params, $serverId)
{
    $serverResult = petroXClientApi($params, 'servers/' . $serverId . '?include=subusers', [], 'GET', true);

    if ($serverResult['status_code'] === 200) {
        return $serverResult['attributes'];
    } else if ($serverResult['status_code'] === 500) {
        throw new Exception('Failed to get server, panel errored. Check panel logs for more info.');
    }
    return false;
}

function petroXGenerateUsername($length = 8)
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    return $randomString;
}

function petroXConvertToMB($input)
{
    if ($input == 0 || $input == '') return $input;
    return $input * 1024;
}

function petroXGetNodeAllocations($params, $serverNode)
{

    $node = [];
    $allocations = [];
    $nodes = petroXApplicationApi($params, 'nodes?include=allocations');
    if ($nodes) {
        foreach ($nodes['data'] as $nodeData) {
            $node = $nodeData['attributes'];
            if ($node['id'] == $serverNode) {
                $allocations = $node['relationships']['allocations']['data'];
                break;
            }
        }
    }
    if (!$allocations){
        throw new Exception('Cannot find any ports for node with id '.$serverNode .' please ensure you have created ports for that node.');
    }
    PetroXInstance::get()->node_allocations = $allocations;
    PetroXInstance::get()->node = $node;
}

function petroX_calculatePortRange($serverPortRange, $offset)
{
    // Extract the start and end of the SERVER_PORT range
    [$start, $end] = explode('-', $serverPortRange);

    // Calculate new start and end by adding the offset
    $newStart = ($start + $offset);
    $newEnd = $end + $offset;

    // Return the new range as a string
    return $newStart . '-' . $newEnd;
}

function petroXMakeIParray()
{
    $ips = [];
    if (PetroXInstance::get()->dedicated_ip) {
        $serverIp = PetroXInstance::get()->server_ip;
        $ipsToRemove = [];

        foreach (PetroXInstance::get()->node_allocations as $allocation) {
            $attr = $allocation['attributes'];
            if ($attr['assigned'] == 1 && $serverIp != $attr['ip']) {
                $ipsToRemove[] = $attr['ip'];
            }
        }

        $allocations = array_filter(PetroXInstance::get()->node_allocations, function ($allocation) use ($ipsToRemove) {
            return !in_array($allocation['attributes']['ip'], $ipsToRemove);
        });

        PetroXInstance::get()->node_allocations = array_values($allocations);
    }

    foreach (PetroXInstance::get()->node_allocations as $allocation) {
        $attr = $allocation['attributes'];
        $ip = $attr['ip'];
        $ips[$ip][] = [
            'port' => $attr['port'],
            'assigned' => $attr['assigned'],
            'id' => $attr['id']
        ];
    }

    // Sort ports in ascending order for each IP
    foreach ($ips as &$ports) {
        usort($ports, function ($a, $b) {
            return $a['port'] <=> $b['port'];
        });
    }

    return $ips;
}


function petroXProcessAllocations($eggData, $ports)
{
    $variables = [];
    foreach ($eggData['attributes']['relationships']['variables']['data'] as $val) {
        $attr = $val['attributes'];
        $var = $attr['env_variable'];
        if (isset($ports[$var])) {
            $variables[$var] = $ports[$var];
        }
    }

//    if (PetroXInstance::get()->server_port_offset > 0) {
//        $offset = PetroXInstance::get()->server_port + PetroXInstance::get()->server_port_offset;
//        $variables = array_merge(['SERVER_PORT_OFFSET' => $offset . '-' . $offset], $variables);
//    }

    if (isset($ports['EXTRA_ALLOCATION'])) {
        $extra = $ports['EXTRA_ALLOCATION'];
        $parts = explode(":", $extra);

        if (count($parts) > 1) {
            $times = end($parts);
            $lastColonPos = strrpos($extra, ':');
            if ($lastColonPos !== false) {
                $modifiedString = substr($extra, 0, $lastColonPos);
            } else {
                $modifiedString = $extra;
            }
        } else {
            $times = 1;
            $modifiedString = $extra;
        }
        for ($i = 0; $i < $times; $i++) {
            $variables['EXTRA_ALLOCATION' . $i] = $modifiedString;
        }
    }
    PetroXInstance::get()->variables = $variables;
}

function petroXfindFreePortsForVariables($ips_data, &$variables)
{
    foreach ($ips_data as $ip => $ports) {
        $allocatedPorts = []; // Track allocated ports per IP
        $freePorts = [];
        $foundAll = true;

        foreach ($variables as $var => $range) {
            list($start, $end) = explode('-', $range);
            $start = (int) $start;
            $end = (int) $end;
            $foundPort = false;

            foreach ($ports as $port) {
                if ($port['assigned'] == 1) continue; // Skip assigned ports

                if (!isset($allocatedPorts[$port['id']]) && $port['port'] >= $start && $port['port'] <= $end) {
                    $port['ip'] = $ip;
                    $freePorts[$var] = $port;
                    $allocatedPorts[$port['id']] = $port['port'];
                    $foundPort = true;


//                    if ($var === 'SERVER_PORT' && !isset($variables['SERVER_PORT_OFFSET']) && PetroXInstance::get()->server_port_offset > 0) {
//                        $offsetValue = $port['port'] + PetroXInstance::get()->server_port_offset;
//
//                        PetroXInstance::get()->addFileLog([
//                            'offset' => $offsetValue,
//                            'port' => $port['port']
//                        ], 'New server port found!');
//
//                        // Find a matching offset port in the available ports
//                        foreach ($ports as $offsetPort) {
//                            if ($offsetPort['port'] == $offsetValue && !$offsetPort['assigned']) {
//                                $offsetPort['ip'] = $ip;
//                                $freePorts['SERVER_PORT_OFFSET'] = $offsetPort;
//                                break;
//                            }
//                        }
//                    }

                    break; // Stop searching once a valid port is found for this variable
                }
            }

            if (!$foundPort) {
                $foundAll = false;
                break;
            }
        }

        if ($foundAll) {
            return $freePorts;
        }
    }

    return [];
}

function petroXfindFreePortsForAllVariablesOnIP($ports, $variables, $_SERVER_IP)
{
    $freePorts = [];
    $foundAll = true;

    $allocatedPorts = []; // Store allocated ports

    foreach ($variables as $var => $range) {
        list($start, $end) = explode('-', $range);
        $foundPort = false;

        foreach ($ports as $port) {
            if ($port['assigned'] == 1) continue;
            if (!isset($allocatedPorts[$port['id']])) {
                if ($port['port'] >= $start && $port['port'] <= $end) {
                    $port['ip'] = $_SERVER_IP;
                    $freePorts[$var] = $port;
                    $allocatedPorts[$port['id']] = $port['port']; // Store allocated port to prevent reuse
                    $foundPort = true;
                    break;
                }
            }


        }

        if (!$foundPort) {
            $foundAll = false;
            break;
        }
    }

    if ($foundAll) {
        return $freePorts;
    } else {
        return [];
    }
}

function petroXSetServerPortVariables(&$variables, $serverPort, $ips, $isRange = false)
{
    $serverPortValue = $isRange ? $serverPort : $serverPort . '-' . $serverPort;
    $serverPortArray = ['SERVER_PORT' => $serverPortValue];
    $serverPortOffsetArray = [];
//    if (isset($variables['SERVER_PORT_OFFSET'])) {
//        unset($variables['SERVER_PORT_OFFSET']);
//        if (!$isRange) {
//            $offset = $serverPort + PetroXInstance::get()->server_port_offset;
//            $serverPortOffsetArray = ['SERVER_PORT_OFFSET' => $offset . '-' . $offset];
//        }
//    }
    $variables = array_merge($serverPortArray, $serverPortOffsetArray, $variables);
    return petroXfindFreePortsForVariables($ips, $variables);
}

function petroXfindPorts($ports, $ips)
{

    $_SERVER_IP = PetroXInstance::get()->server_ip;
    $_SERVER_PORT = PetroXInstance::get()->server_port;
    $variables = PetroXInstance::get()->variables;
    //check if we need server port offset
    //if so we add it here

//    if (PetroXInstance::get()->server_port_offset > 0) {
//        $offset = $_SERVER_PORT + PetroXInstance::get()->server_port_offset;
//        $variables = array_merge(['SERVER_PORT_OFFSET' => $offset . '-' . $offset], $variables);
//    }

//    //first we are checking for possible ips for the given IP.
//    $foundPorts = petroXfindFreePortsForAllVariablesOnIP($ips[$_SERVER_IP], $variables, $_SERVER_IP);
//    //if we can't find that we are trying to find ip with the same port that is given by pterodactyl panel.
//    if (!$foundPorts) {
//        $foundPorts = petroXSetServerPortVariables($variables, $_SERVER_PORT, $ips);
//    }


    //if not we are trying to find any ip in the server port range.
    $foundPorts = petroXSetServerPortVariables($variables, $ports['SERVER_PORT'], $ips, true);


    PetroXInstance::get()->addFileLog([
        'foundedPorts' => json_encode($foundPorts),
        'variables' => json_encode($variables),
        'ports' => json_encode($ports),
        'ips' => json_encode($ips),
        'server_port' => $_SERVER_PORT,
        'server_ip' => $_SERVER_IP,
        'server_ip_ports' => json_encode($ips[$_SERVER_IP] ?? [])
    ], 'Founding ports finish');
    return $foundPorts;
}

function petroXGetCustomFieldId($params)
{
    $customFieldExists = Capsule::table('tblcustomfields')
        ->where('relid', $params['packageid'])
        ->where('fieldname', 'UUID (Server ID)')
        ->where('type', 'product')
        ->first();
    if ($customFieldExists) {
        $customFieldId = $customFieldExists->id;
    }
    if (!$customFieldExists) {
        Capsule::table('tblcustomfields')->insert([
            'type' => 'product',
            'relid' => $params['packageid'],
            'fieldname' => 'UUID (Server ID)',
            'fieldtype' => 'text',
            'adminonly' => 'on'
        ]);
        $customFieldId = Capsule::getPdo()->lastInsertId();
    }
    return $customFieldId;
}

function petroXUpdateCustomField($params, $customFieldId, $serverId)
{
    Capsule::table('tblcustomfieldsvalues')
        ->updateOrInsert(
            ['fieldid' => $customFieldId, 'relid' => $params['serviceid']],
            ['value' => $serverId]
        );
}

function petroXReturnJson($data, $code = 200)
{
    header(sprintf('HTTP/%s %s', '1.1', $code), true, $code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function petroXreturnJsonMessage($message, $code = 200)
{
    global $_LANG;
    petroXReturnJson([
        'message' => $_LANG[$message] ?? $message
    ], $code);
}

function petroXSaveServerVariables($params, $serverData, $serverState)
{

    global $_LANG;

    if (!$params['allowSettingsEdit']) {
        petroXReturnJson([
            'message' => $_LANG['SERVER_SETTINGS_EDIT_NOT_ALLOWED']
        ], 400);
    }

    $serverId = $serverData['id'];
    $environment = $serverData['container']['environment'];
    $data = array_merge($environment, $_POST);

    $result = petroXApplicationApi($params, 'servers/' . $serverId . '/startup', [
        'startup' => $serverData['container']['environment']['STARTUP'],
        'egg' => $serverData['egg'],
        'image' => $serverData['container']['image'],
        'environment' => $data,
        'skip_scripts' => false,
    ], 'PATCH');
    if ($result['status_code'] == 200) {
        petroXReturnJson([
            'message' => $_LANG['changessavedsuccessfully']
        ], 200);
    }

    $errors = [
        'errors' => [],
        'message' => $_LANG['genericerror']['title']
    ];
    if (isset($result['errors'])) {
        foreach ($result['errors'] as $error) {
            $errors['errors'][] = [
                'input' => str_replace('environment.', '', $error['meta']['source_field']),
                'message' => $error['detail']
            ];
        }
    }
    petroXReturnJson($errors, 400);
}

function petroXSaveServerStartup($params, $serverData, $serverState)
{

}




function petroX_updateServerDomain($params)
{
    try {
        $serverIp = PetroXInstance::get()->server_ip;
        $serverPort = PetroXInstance::get()->server_port;
        if (PetroXInstance::get()->server_alias_ip !== false) {
            $serverIp = PetroXInstance::get()->server_alias_ip;
        }
        Capsule::table('tblhosting')
            ->where('id', $params['serviceid'])
            ->where('userid', $params['userid'])
            ->update(array('domain' => $serverIp . ":" . $serverPort));
    } catch (Exception $e) {
        return $e->getMessage() . "<br />" . $e->getTraceAsString();
    }
}

function petroX_getServerIPAndPort($allocations, $allocation)
{

    foreach ($allocations as $allocationData) {
        if ($allocationData['attributes']['id'] == $allocation) {
            PetroXInstance::get()->server_ip = $allocationData['attributes']['ip'];
            if (PetroXInstance::get()->use_alias_ip && $allocationData['attributes']['alias'] != '') {
                PetroXInstance::get()->server_alias_ip = $allocationData['attributes']['alias'];
            }
            PetroXInstance::get()->server_port = $allocationData['attributes']['port'];
            break;
        }
    }
}

function petrox_validateThreads($threads)
{
    //same validation from Pterodactyl panel
    //app\Models\Server.php
    $pattern = '/^[0-9-,]+$/';
    if (preg_match($pattern, $threads)) {
        return $threads;
    }
    return null;
}

/**
 * Find a suitable node and free allocation in the given location for a server transfer.
 * Respects port range requirements (ports_ranges config) when searching for allocations.
 *
 * @param array $params WHMCS server params
 * @param int $locationId Target location ID
 * @param array $portsArray Port range config (e.g. ['SERVER_PORT' => '25565-25669'])
 * @return array|null ['node_id', 'allocation_id', 'allocation', 'node'] or null if none found
 */
function petroXFindTransferTarget($params, $locationId, $portsArray = [])
{
    $nodesResult = petroXApplicationApi($params, 'nodes?include=allocations');
    if ($nodesResult['status_code'] !== 200) {
        throw new Exception('Failed to retrieve nodes for transfer, received error code: ' . $nodesResult['status_code'] . '. Enable module debug log for more info.');
    }

    foreach ($nodesResult['data'] as $nodeData) {
        $node = $nodeData['attributes'];

        // Only consider nodes in the target location
        if ($node['location_id'] != $locationId) continue;

        $nodeAllocations = $node['relationships']['allocations']['data'];
        $freeAllocations = [];

        foreach ($nodeAllocations as $allocation) {
            $attr = $allocation['attributes'];
            if (!$attr['assigned']) {
                $freeAllocations[] = $attr;
            }
        }

        if (empty($freeAllocations)) continue;

        // Sort free allocations by port ascending
        usort($freeAllocations, function ($a, $b) {
            return $a['port'] <=> $b['port'];
        });

        // If port ranges are configured, find a matching allocation
        if (!empty($portsArray) && isset($portsArray['SERVER_PORT'])) {
            $range = $portsArray['SERVER_PORT'];
            if (str_contains($range, '-')) {
                [$portStart, $portEnd] = explode('-', $range);
            } else {
                $portStart = $portEnd = $range;
            }

            foreach ($freeAllocations as $alloc) {
                if ($alloc['port'] >= (int)$portStart && $alloc['port'] <= (int)$portEnd) {
                    return [
                        'node_id' => $node['id'],
                        'allocation_id' => $alloc['id'],
                        'allocation' => $alloc,
                        'node' => $node,
                    ];
                }
            }
        } else {
            // No port range requirements — use first free allocation
            return [
                'node_id' => $node['id'],
                'allocation_id' => $freeAllocations[0]['id'],
                'allocation' => $freeAllocations[0],
                'node' => $node,
            ];
        }
    }

    return null;
}
