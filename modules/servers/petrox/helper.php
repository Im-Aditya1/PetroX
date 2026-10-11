<?php

use Illuminate\Database\Capsule\Manager as Capsule;

class PetroXInstance
{
    private static PetroXInstance|null $instance = null;
    public static $mockApiHandler = null;
    public $swap_as_gb = false;
    public $disk_as_gb = false;
    public $memory_as_gb = false;
    public $enable_client_area_password_changer = true;
    public $enable_whmcs_user_sync = false;

    public $allow_startup_edit = false;
    public $allow_variables_edit = false;

    public $game_panel = "pterodactyl";

    public $dynamic_variables = [];
    public $dynamic_environment_array = [];

    public $jsPath = '';
    public $cssPath = '';

    public array $panelCurlConfig = [
        'user' => 'PetroX',
        'accept' => 'application/json, application/vnd.pterodactyl.v1+json'
    ];

    public array $curlRequestSettings = [
        'pterodactyl' => [
            'user' => 'PetroX',
            'accept' => 'application/json, application/vnd.pterodactyl.v1+json'
        ],
        'wisp' => [
            'user' => 'WISP',
            'accept' => 'Application/vnd.wisp.v1+json',
        ]
    ];

    public $fetchingPagination = [];
    public bool $fetching = true;
    public int $fetchingNextPage = 1;
    public array $fetchedResults = [];
    public array $hooksData = [];
    public bool $use_alias_ip = false;
    public $service_id = 0;
    public $dedicated_ip = false;
    public string $server_ip = "0";
    public int $server_port = 0;
    public string|bool $server_alias_ip = false;
    public array $node = [];
    public array $node_allocations = [];
    public array $variables = [];
    private array|false $server = false;

    public function __construct()
    {
        global $CONFIG;
        $configFile = dirname(__FILE__) . '/config.json';
        if (file_exists($configFile)) {
            $data = file_get_contents($configFile);
            $data = json_decode($data, true);
            if ($data && is_array($data)) {
                foreach ($data as $key => $value) {
                    $this->$key = $value;
                }
            }
        }
        if (isset($this->curlRequestSettings[$this->game_panel])) {
            $this->panelCurlConfig = $this->curlRequestSettings[$this->game_panel];
        }

        $whmcsBaseUrl = isset($CONFIG['SystemURL']) ? rtrim($CONFIG['SystemURL'], '/') : '';
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
            if ($server) {
                $this->server = [
                    'serverhostname' => $server->hostname,
                    'serverusername' => $server->username,
                    'serverpassword' => decrypt($server->password),
                    'serveraccesshash' => $server->accesshash,
                    'serversecure' => $server->secure,
                    'serverport' => $server->port,
                ];
            }
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
        if ($updateResult['status_code'] !== 200) {
            throw new Exception('Failed to change password: ' . petroXFormatError($updateResult));
        }
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
            throw new Exception('Failed to update user details: ' . petroXFormatError($updateResult));
        }
    }

    public function getPterodactylUser($params, array $client = [], $create = true)
    {
        $clientId = (string)($client['id'] ?? '');
        $userResult = petroXApplicationApi($params, 'users/external/' . urlencode($clientId));

        if ($userResult['status_code'] === 404) {
            if (!isset($client['email']) || $client['email'] === '') return $userResult;

            if ($this->game_panel === 'wisp') {
                $userResult = petroXApplicationApi($params, 'users?search=' . urlencode($client['email']));
            } else {
                $userResult = petroXApplicationApi($params, 'users?filter[email]=' . urlencode($client['email']));
            }

            $total = $userResult['meta']['pagination']['total'] ?? (isset($userResult['data']) ? count($userResult['data']) : 0);

            if ($create === true && $total === 0) {
                $userResult = $this->createPteroUser($client, $params);
            } else {
                if ($total === 0) {
                    $userResult['status_code'] = 404;
                }
                if ($userResult['status_code'] !== 404 && !empty($userResult['data'])) {
                    $matched = false;
                    foreach ($userResult['data'] as $value) {
                        if (strtolower($value['attributes']['email'] ?? '') === strtolower($client['email'])) {
                            $userResult = $value;
                            $userResult['status_code'] = 200;
                            // Sync external_id to match WHMCS client ID
                            $arr = ['external_id' => $clientId];
                            if (isset($client['password']) && $client['password'] !== '') {
                                $arr['password'] = $client['password'];
                            }
                            $this->updatePterodactylUserData($userResult, $params, $arr);
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched && $create === true) {
                        $userResult = $this->createPteroUser($client, $params);
                    } elseif (!$matched) {
                        $userResult = ['status_code' => 404];
                    }
                } elseif ($create === true) {
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
        }
        $sanitizedData = petroXSanitizeForLog($data);
        $content = PHP_EOL;
        $content .= PHP_EOL;
        $content .= 'START: ' . $title . PHP_EOL;
        $content .= 'Time: ' . date('Y-m-d H:i:s');
        $content .= PHP_EOL;
        $content .= print_r($sanitizedData, true);
        $content .= PHP_EOL;
        $content .= 'END: ' . $title;
        @file_put_contents($file, $content, FILE_APPEND);
    }

    public function getDynamicEnvironmentArray(): array
    {
        return $this->dynamic_environment_array;
    }

    private function createPteroUser(array $client, $params): mixed
    {
        $arr = [
            'username' => $client['username'],
            'email' => $client['email'],
            'first_name' => $client['firstname'],
            'last_name' => $client['lastname'],
            'external_id' => (string)$client['id'],
        ];
        if (isset($client['password']) && $client['password'] !== '') {
            $arr['password'] = $client['password'];
        }
        $res = petroXApplicationApi($params, 'users', $arr, 'POST');
        if ($res['status_code'] !== 200 && $res['status_code'] !== 201) {
            throw new Exception('Failed to create Pterodactyl user: ' . petroXFormatError($res));
        }
        return $res;
    }
}

/**
 * Recursively sanitize sensitive keys and tokens from data structures prior to logging.
 */
function petroXSanitizeForLog($data)
{
    if (is_string($data)) {
        return preg_replace('/Bearer\s+[A-Za-z0-9_\-\.]+/i', 'Bearer [REDACTED]', $data);
    }
    if (!is_array($data)) {
        return $data;
    }

    $sensitiveKeys = [
        'password', 'serverpassword', 'serveraccesshash', 'accesshash',
        'token', 'secret', 'api_key', 'apikey', 'auth'
    ];

    $sanitized = [];
    foreach ($data as $key => $value) {
        $keyLower = strtolower((string)$key);
        $isSensitive = in_array($keyLower, $sensitiveKeys, true) ||
            str_contains($keyLower, 'password') ||
            str_contains($keyLower, 'secret') ||
            str_contains($keyLower, 'token');

        if ($isSensitive) {
            $sanitized[$key] = '[REDACTED]';
        } elseif (is_array($value)) {
            $sanitized[$key] = petroXSanitizeForLog($value);
        } elseif (is_string($value)) {
            $sanitized[$key] = petroXSanitizeForLog($value);
        } else {
            $sanitized[$key] = $value;
        }
    }
    return $sanitized;
}

/**
 * Format API responses into human-readable, actionable error messages.
 */
function petroXFormatError(mixed $result, string $defaultMsg = 'An error occurred while communicating with the panel.'): string
{
    if (!is_array($result)) {
        return $defaultMsg;
    }

    $statusCode = $result['status_code'] ?? 0;
    $details = [];

    // Extract Pterodactyl validation errors
    if (!empty($result['errors']) && is_array($result['errors'])) {
        foreach ($result['errors'] as $err) {
            if (!is_array($err)) continue;
            $msg = $err['detail'] ?? ($err['title'] ?? '');
            $field = $err['source']['field'] ?? ($err['meta']['source_field'] ?? '');
            if ($field !== '') {
                $fieldClean = str_replace(['environment.', 'limits.', 'feature_limits.'], '', $field);
                $details[] = "[$fieldClean] $msg";
            } elseif ($msg !== '') {
                $details[] = $msg;
            }
        }
    }

    if (!empty($details)) {
        return implode('; ', $details);
    }

    if ($statusCode === 0 && !empty($result['curl_error'])) {
        return 'Connection failure: ' . $result['curl_error'];
    }

    return match ($statusCode) {
        400 => 'Bad Request (HTTP 400): The panel rejected the request or no viable node/allocation could be found.',
        401 => 'Unauthorized (HTTP 401): Invalid or missing Application API key. Please verify your WHMCS server password setting.',
        403 => 'Forbidden (HTTP 403): The API key does not have sufficient permissions for this operation.',
        404 => 'Not Found (HTTP 404): The requested resource does not exist on the panel.',
        409 => 'Conflict (HTTP 409): The requested operation conflicts with the server\'s current state.',
        422 => 'Validation Error (HTTP 422): One or more fields failed panel validation.',
        429 => 'Rate Limited (HTTP 429): Too many requests sent to the Pterodactyl panel. Please retry later.',
        500 => 'Internal Server Error (HTTP 500): The Pterodactyl panel encountered an internal error. Check panel logs.',
        502, 503, 504 => "Gateway Error (HTTP $statusCode): The Pterodactyl panel or Wings daemon is unreachable or timed out.",
        default => $defaultMsg !== '' ? $defaultMsg : "Unexpected API response (HTTP $statusCode).",
    };
}

function petroXLog(string $action, string $string, array $array)
{
    if (function_exists('logModuleCall')) {
        logModuleCall("PetroX-WHMCS", $action, $string, petroXSanitizeForLog($array));
    }
}

function petroXGetHostname(array $params)
{
    $hostname = trim($params['serverhostname'] ?? '');

    if ($hostname === '') {
        throw new Exception('Could not find the panel\'s hostname - did you configure server group for the product?');
    }

    foreach ([
        'DOT' => '.',
        'DASH' => '-',
    ] as $from => $to) {
        $hostname = str_replace($from, $to, $hostname);
    }

    // Strip existing scheme prefix to prevent duplicate protocols (e.g. https://https://...)
    $isHttps = !empty($params['serversecure']) || str_starts_with(strtolower($params['serverhostname'] ?? ''), 'https://');
    $hostname = preg_replace('#^https?://#i', '', $hostname);
    $hostname = ($isHttps ? 'https://' : 'http://') . $hostname;
    return rtrim($hostname, '/');
}

function petroXApiHandler(mixed $method, array $data, mixed $curl, array $headers, mixed $dontLog, string $url): mixed
{
    if (PetroXInstance::$mockApiHandler !== null) {
        $mock = PetroXInstance::$mockApiHandler;
        $mockResponse = $mock($method, $url, $data, $headers);
        if (!is_array($mockResponse)) {
            $mockResponse = [];
        }
        if (!$dontLog && function_exists('logModuleCall')) {
            $sanitizedData = petroXSanitizeForLog($data);
            $sanitizedResponse = petroXSanitizeForLog($mockResponse);
            logModuleCall(
                "PetroX-WHMCS",
                $method . " - " . $url,
                !empty($sanitizedData) ? json_encode($sanitizedData) : "",
                print_r($sanitizedResponse, true)
            );
        }
        return $mockResponse;
    }

    if (!$curl) {
        return ['status_code' => 0, 'curl_error' => 'Failed to initialize cURL handle.'];
    }

    if ($method === 'POST' || $method === 'PATCH' || $method === 'PUT') {
        $jsonData = json_encode($data);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);
        $headers[] = "Content-Type: application/json";
        $headers[] = "Content-Length: " . strlen($jsonData);
    }

    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $responseData = json_decode($response, true);
    if (!is_array($responseData)) {
        $responseData = [];
        if ($response !== false && $response !== '') {
            $responseData['raw_body'] = $response;
        }
    }
    $responseData['status_code'] = $statusCode;
    if ($statusCode === 0) {
        $responseData['curl_error'] = $curlError;
    }

    if (!$dontLog && function_exists('logModuleCall')) {
        $sanitizedData = petroXSanitizeForLog($data);
        $sanitizedResponse = petroXSanitizeForLog($responseData);
        logModuleCall(
            "PetroX-WHMCS",
            $method . " - " . $url,
            !empty($sanitizedData) ? json_encode($sanitizedData) : "",
            print_r($sanitizedResponse, true)
        );
    }

    return $responseData;
}

function getCurl_init(string $url, mixed $method)
{
    if (!function_exists('curl_init')) {
        return false;
    }
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_USERAGENT, PetroXInstance::get()->panelCurlConfig['user'] . "-WHMCS");
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_POSTREDIR, CURL_REDIR_POST_301);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
    $host = parse_url($url, PHP_URL_HOST) ?? '';
    $isRawIp = (ip2long($host) !== false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $isRawIp ? 0 : 1);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $isRawIp ? 0 : 2);
    return $curl;
}

function petroXApplicationApi(array $params, $endpoint, array $data = [], $method = "GET", $dontLog = false)
{
    $url = petroXGetHostname($params) . '/api/application/' . $endpoint;

    $headers = [
        "Authorization: Bearer " . ($params['serverpassword'] ?? ''),
        "Accept: " . PetroXInstance::get()->panelCurlConfig['accept'],
    ];

    if (PetroXInstance::$mockApiHandler !== null) {
        return petroXApiHandler($method, $data, null, $headers, $dontLog, $url);
    }

    $curl = getCurl_init($url, $method);

    return petroXApiHandler($method, $data, $curl, $headers, $dontLog, $url);
}

function petroXClientApi(array $params, $endPoint, array $data = [], $method = "GET", $dontLog = false)
{
    $url = petroXGetHostname($params) . '/api/client/' . $endPoint;

    $headers = [
        "Authorization: Bearer " . ($params['serveraccesshash'] ?? ''),
        "Accept: " . PetroXInstance::get()->panelCurlConfig['accept'],
    ];

    if (PetroXInstance::$mockApiHandler !== null) {
        return petroXApiHandler($method, $data, null, $headers, $dontLog, $url);
    }

    $curl = getCurl_init($url, $method);

    return petroXApiHandler($method, $data, $curl, $headers, $dontLog, $url);
}

/**
 * Handle paginated Application API requests across all pages up to $maxPages.
 */
function petroXApplicationApiPaginated(array $params, string $endpoint, array $data = [], string $method = "GET", int $maxPages = 20): array
{
    $allData = [];
    $page = 1;
    $separator = str_contains($endpoint, '?') ? '&' : '?';

    do {
        $paginatedEndpoint = $endpoint . $separator . 'page=' . $page . '&per_page=100';
        $res = petroXApplicationApi($params, $paginatedEndpoint, $data, $method, true);
        if ($res['status_code'] !== 200) {
            if ($page === 1) return $res;
            break;
        }
        if (isset($res['data']) && is_array($res['data'])) {
            foreach ($res['data'] as $item) {
                $allData[] = $item;
            }
        } else {
            break;
        }

        $currentPage = $res['meta']['pagination']['current_page'] ?? $page;
        $totalPages = $res['meta']['pagination']['total_pages'] ?? 1;
        $page++;
    } while ($currentPage < $totalPages && $page <= $maxPages);

    return [
        'status_code' => 200,
        'data' => $allData,
    ];
}

/**
 * Retrieve egg data with variables, supporting Pterodactyl v2 directly with v1 nest fallback.
 */
function petroXGetEggData(array $params, $eggId, $nestId = null)
{
    // Try Pterodactyl v2 endpoint first (eggs are top-level resources in v2)
    $eggData = petroXApplicationApi($params, 'eggs/' . (int)$eggId . '?include=variables,tags');
    if ($eggData['status_code'] === 200) {
        return $eggData;
    }

    // Fall back to Pterodactyl v1 nested endpoint if nest_id is provided
    if ($nestId && (int)$nestId > 0) {
        $v1Data = petroXApplicationApi($params, 'nests/' . (int)$nestId . '/eggs/' . (int)$eggId . '?include=variables');
        if ($v1Data['status_code'] === 200) {
            return $v1Data;
        }
    }

    return $eggData;
}

function petroXGetMemorySwapAndDisk($params)
{
    $memory = petroXGetOption($params, 'memory');
    if (!is_numeric($memory) && ($memory != '0' && $memory != '')) {
        throw new Exception('Invalid memory value provided. Please enter a valid integer. For example: 1, 2, 3, etc. Don\'t forget to check your Configuration Options.');
    }
    if (PetroXInstance::get()->memory_as_gb) {
        $memory = petroXConvertToMB($memory);
    }

    $swap = petroXGetOption($params, 'swap');
    if (!is_numeric($swap) && ($swap != '0' && $swap != '')) {
        throw new Exception('Invalid swap value provided. Please enter a valid integer. For example: 0, 512, etc.');
    }
    $swap = (int)$swap;
    if (PetroXInstance::get()->swap_as_gb && $swap > 0) {
        $swap = petroXConvertToMB($swap);
    }

    $disk = petroXGetOption($params, 'disk');
    if (!is_numeric($disk) && ($disk != '0' && $disk != '')) {
        throw new Exception('Invalid disk value provided. Please enter a valid integer. For example: 10, 20, 1024, etc.');
    }
    $disk = (int)$disk;
    if (PetroXInstance::get()->disk_as_gb) {
        $disk = petroXConvertToMB($disk);
    }

    return [(int)$memory, (int)$swap, (int)$disk];
}

function petroXGetServer(array $params, $raw = false, $include = false)
{
    $queryString = $include ? ('?include=' . $include) : '';
    $serviceId = (string)$params['serviceid'];

    // 1. First attempt: lookup by external_id (WHMCS service ID)
    $serverResult = petroXApplicationApi($params, 'servers/external/' . urlencode($serviceId) . $queryString, [], 'GET', true);
    if ($serverResult['status_code'] === 200 && isset($serverResult['attributes'])) {
        return $raw ? $serverResult['attributes'] : $serverResult['attributes']['id'];
    }

    // 2. Second attempt: lookup by stored UUID from custom fields if available
    $uuid = $params['customfields']['UUID (Server ID)'] ?? null;
    if ($uuid) {
        $filterResult = petroXApplicationApi($params, 'servers?filter[uuid]=' . urlencode($uuid) . ($include ? ('&include=' . $include) : ''), [], 'GET', true);
        if ($filterResult['status_code'] === 200 && !empty($filterResult['data'][0]['attributes'])) {
            return $raw ? $filterResult['data'][0]['attributes'] : $filterResult['data'][0]['attributes']['id'];
        }
    }

    if ($serverResult['status_code'] === 500) {
        throw new Exception('Failed to get server, panel errored: ' . petroXFormatError($serverResult));
    }
    return false;
}

function petroXGetServerVariables(array $params, $serverId)
{
    $data = petroXClientApi($params, 'servers/' . $serverId . '/startup', [], 'GET', true);
    if ($data['status_code'] === 200) {
        return [$data['data'] ?? [], $data['meta'] ?? []];
    } else if ($data['status_code'] === 500) {
        throw new Exception('Failed to get server startup variables, panel errored: ' . petroXFormatError($data));
    }
    return [[], []];
}

function petroXGetClientServer($params, $serverId)
{
    $serverResult = petroXClientApi($params, 'servers/' . $serverId . '?include=subusers', [], 'GET', true);

    if ($serverResult['status_code'] === 200) {
        return $serverResult['attributes'] ?? [];
    } else if ($serverResult['status_code'] === 500) {
        throw new Exception('Failed to get client server, panel errored: ' . petroXFormatError($serverResult));
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
    return (int)($input * 1024);
}

function petroXGetNodeAllocations($params, $serverNode)
{
    $node = [];
    $allocations = [];

    // Direct node allocations endpoint (paginated)
    $allocationsResult = petroXApplicationApiPaginated($params, 'nodes/' . (int)$serverNode . '/allocations');
    if ($allocationsResult['status_code'] === 200 && !empty($allocationsResult['data'])) {
        $allocations = $allocationsResult['data'];
    } else {
        // Fallback: nodes?include=allocations
        $nodes = petroXApplicationApi($params, 'nodes?include=allocations');
        if ($nodes && isset($nodes['data'])) {
            foreach ($nodes['data'] as $nodeData) {
                $nodeCandidate = $nodeData['attributes'];
                if ($nodeCandidate['id'] == $serverNode) {
                    $node = $nodeCandidate;
                    $allocations = $nodeCandidate['relationships']['allocations']['data'] ?? ($nodeCandidate['attributes']['relationships']['allocations']['data'] ?? ($nodeData['relationships']['allocations']['data'] ?? []));
                    break;
                }
            }
        }
    }

    if (!$allocations) {
        throw new Exception('Cannot find any allocations for node with ID ' . $serverNode . '. Please ensure you have created ports for that node.');
    }

    PetroXInstance::get()->node_allocations = $allocations;
    PetroXInstance::get()->node = $node;
    return $allocations;
}

function petroX_calculatePortRange($serverPortRange, $offset)
{
    $parts = explode('-', (string)$serverPortRange);
    $start = (int)$parts[0];
    $end = isset($parts[1]) ? (int)$parts[1] : $start;
    $newStart = $start + (int)$offset;
    $newEnd = $end + (int)$offset;
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
            if (!empty($attr['assigned']) && $serverIp !== $attr['ip']) {
                $ipsToRemove[] = $attr['ip'];
            }
        }

        $allocations = array_filter(PetroXInstance::get()->node_allocations, function ($allocation) use ($ipsToRemove) {
            return !in_array($allocation['attributes']['ip'], $ipsToRemove, true);
        });

        PetroXInstance::get()->node_allocations = array_values($allocations);
    }

    foreach (PetroXInstance::get()->node_allocations as $allocation) {
        $attr = $allocation['attributes'];
        $ip = $attr['ip'];
        $ips[$ip][] = [
            'port' => (int)$attr['port'],
            'assigned' => !empty($attr['assigned']) ? 1 : 0,
            'id' => (int)$attr['id']
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
    $varData = $eggData['relationships']['variables']['data'] ?? ($eggData['attributes']['relationships']['variables']['data'] ?? []);
    foreach ($varData as $val) {
        $attr = $val['attributes'];
        $var = $attr['env_variable'];
        if (isset($ports[$var])) {
            $variables[$var] = $ports[$var];
        }
    }

    if (isset($ports['EXTRA_ALLOCATION'])) {
        $extra = $ports['EXTRA_ALLOCATION'];
        $parts = explode(":", $extra);

        if (count($parts) > 1) {
            $times = (int)end($parts);
            $lastColonPos = strrpos($extra, ':');
            $modifiedString = $lastColonPos !== false ? substr($extra, 0, $lastColonPos) : $extra;
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
        $allocatedPorts = [];
        $freePorts = [];
        $foundAll = true;

        foreach ($variables as $var => $range) {
            $rangeParts = explode('-', $range);
            $start = (int)$rangeParts[0];
            $end = isset($rangeParts[1]) ? (int)$rangeParts[1] : $start;
            $foundPort = false;

            foreach ($ports as $port) {
                if (!empty($port['assigned'])) {
                    // An assigned port is allowed if it is this server's own primary allocation matching SERVER_PORT
                    $isOwnServerPrimary = ($var === 'SERVER_PORT'
                        && (int)$port['port'] === PetroXInstance::get()->server_port
                        && $ip === PetroXInstance::get()->server_ip);
                    if (!$isOwnServerPrimary) {
                        continue;
                    }
                }

                if (!isset($allocatedPorts[$port['id']]) && $port['port'] >= $start && $port['port'] <= $end) {
                    $port['ip'] = $ip;
                    $freePorts[$var] = $port;
                    $allocatedPorts[$port['id']] = $port['port'];
                    $foundPort = true;
                    break;
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
    $allocatedPorts = [];

    foreach ($variables as $var => $range) {
        $rangeParts = explode('-', $range);
        $start = (int)$rangeParts[0];
        $end = isset($rangeParts[1]) ? (int)$rangeParts[1] : $start;
        $foundPort = false;

        foreach ($ports as $port) {
            if (!empty($port['assigned'])) {
                $isOwnServerPrimary = ($var === 'SERVER_PORT'
                    && (int)$port['port'] === PetroXInstance::get()->server_port
                    && $_SERVER_IP === PetroXInstance::get()->server_ip);
                if (!$isOwnServerPrimary) {
                    continue;
                }
            }
            if (!isset($allocatedPorts[$port['id']]) && $port['port'] >= $start && $port['port'] <= $end) {
                $port['ip'] = $_SERVER_IP;
                $freePorts[$var] = $port;
                $allocatedPorts[$port['id']] = $port['port'];
                $foundPort = true;
                break;
            }
        }

        if (!$foundPort) {
            $foundAll = false;
            break;
        }
    }

    return $foundAll ? $freePorts : [];
}

function petroXSetServerPortVariables(&$variables, $serverPort, $ips, $isRange = false)
{
    $serverPortValue = $isRange ? $serverPort : $serverPort . '-' . $serverPort;
    $serverPortArray = ['SERVER_PORT' => $serverPortValue];
    $variables = array_merge($serverPortArray, $variables);
    return petroXfindFreePortsForVariables($ips, $variables);
}

function petroXfindPorts($ports, $ips)
{
    $_SERVER_IP = PetroXInstance::get()->server_ip;
    $_SERVER_PORT = PetroXInstance::get()->server_port;
    $variables = PetroXInstance::get()->variables;

    // Prioritize server IP in multi-homed nodes to maintain IP affinity
    if ($_SERVER_IP && isset($ips[$_SERVER_IP])) {
        $orderedIps = [$_SERVER_IP => $ips[$_SERVER_IP]];
        foreach ($ips as $ip => $portsList) {
            if ($ip !== $_SERVER_IP) {
                $orderedIps[$ip] = $portsList;
            }
        }
        $ips = $orderedIps;
    }

    $serverPort = $ports['SERVER_PORT'] ?? ($_SERVER_PORT . '-' . $_SERVER_PORT);
    $foundPorts = petroXSetServerPortVariables($variables, $serverPort, $ips, true);

    PetroXInstance::get()->addFileLog([
        'foundedPorts' => json_encode($foundPorts),
        'variables' => json_encode($variables),
        'ports' => json_encode($ports),
        'ips' => json_encode($ips),
        'server_port' => $_SERVER_PORT,
        'server_ip' => $_SERVER_IP,
        'server_ip_ports' => json_encode($ips[$_SERVER_IP] ?? [])
    ], 'Port discovery finished');
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
    } else {
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

    if (empty($params['allowSettingsEdit'])) {
        petroXReturnJson([
            'message' => $_LANG['SERVER_SETTINGS_EDIT_NOT_ALLOWED'] ?? 'Editing server settings is not allowed.'
        ], 400);
    }

    $serverId = $serverData['id'];
    $environment = $serverData['container']['environment'] ?? [];
    $data = array_merge($environment, $_POST);

    $startup = $serverData['container']['startup_command'] ?? ($serverData['container']['environment']['STARTUP'] ?? '');
    $image = $serverData['container']['image'] ?? '';

    $result = petroXApplicationApi($params, 'servers/' . $serverId . '/startup', [
        'startup' => $startup,
        'egg' => $serverData['egg'],
        'image' => $image,
        'environment' => $data,
        'skip_scripts' => false,
    ], 'PATCH');

    if ($result['status_code'] === 200) {
        petroXReturnJson([
            'message' => $_LANG['changessavedsuccessfully'] ?? 'Changes saved successfully.'
        ], 200);
    }

    $errors = [
        'errors' => [],
        'message' => $_LANG['genericerror']['title'] ?? petroXFormatError($result, 'Failed to save server settings.')
    ];
    if (isset($result['errors'])) {
        foreach ($result['errors'] as $error) {
            $sourceField = $error['meta']['source_field'] ?? ($error['source']['field'] ?? '');
            $errors['errors'][] = [
                'input' => str_replace('environment.', '', $sourceField),
                'message' => $error['detail'] ?? 'Invalid value.'
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
        $userId = $params['userid'] ?? ($params['clientsdetails']['userid'] ?? ($params['clientsdetails']['id'] ?? ($params['clientsdetails']['client_id'] ?? null)));
        $query = Capsule::table('tblhosting')
            ->where('id', $params['serviceid']);
        if ($userId !== null) {
            $query->where('userid', $userId);
        }
        $query->update(['domain' => $serverIp . ":" . $serverPort]);
    } catch (Exception $e) {
        return $e->getMessage() . "<br />" . $e->getTraceAsString();
    }
}

function petroX_getServerIPAndPort($allocations, $allocation)
{
    if (!is_array($allocations)) return;
    foreach ($allocations as $allocationData) {
        $attr = $allocationData['attributes'] ?? [];
        if (($attr['id'] ?? null) == $allocation) {
            PetroXInstance::get()->server_ip = $attr['ip'];
            if (PetroXInstance::get()->use_alias_ip && !empty($attr['alias'])) {
                PetroXInstance::get()->server_alias_ip = $attr['alias'];
            }
            PetroXInstance::get()->server_port = (int)$attr['port'];
            break;
        }
    }
}

function petrox_validateThreads($threads)
{
    if (is_int($threads)) {
        return (string)$threads;
    }
    $pattern = '/^[0-9-,]+$/';
    if (is_string($threads) && preg_match($pattern, trim($threads))) {
        return trim($threads);
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
    $nodesResult = petroXApplicationApiPaginated($params, 'nodes?include=allocations');
    if ($nodesResult['status_code'] !== 200) {
        throw new Exception('Failed to retrieve nodes for transfer: ' . petroXFormatError($nodesResult));
    }

    foreach ($nodesResult['data'] as $nodeData) {
        $node = $nodeData['attributes'];

        if (($node['location_id'] ?? null) != $locationId) continue;

        $nodeAllocations = $nodeData['relationships']['allocations']['data'] ?? ($node['relationships']['allocations']['data'] ?? ($node['attributes']['relationships']['allocations']['data'] ?? []));
        // If allocations relationship wasn't included, query allocations directly
        if (empty($nodeAllocations)) {
            $allocQuery = petroXApplicationApiPaginated($params, 'nodes/' . $node['id'] . '/allocations');
            if ($allocQuery['status_code'] === 200) {
                $nodeAllocations = $allocQuery['data'];
            }
        }

        $freeAllocations = [];
        foreach ($nodeAllocations as $allocation) {
            $attr = $allocation['attributes'];
            if (empty($attr['assigned'])) {
                $freeAllocations[] = $attr;
            }
        }

        if (empty($freeAllocations)) continue;

        usort($freeAllocations, function ($a, $b) {
            return $a['port'] <=> $b['port'];
        });

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
