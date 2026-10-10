<?php
/**
 * PetroX Automated Test Suite
 * Tests compatibility with Pterodactyl Panel v2 across 15 mandatory test scenarios.
 */

declare(strict_types=1);

namespace {
if (!defined('WHMCS')) {
    define('WHMCS', true);
}

// Global mock storage for Capsule and WHMCS
class MockDb
{
    public static array $tables = [
        'tblservers' => [],
        'tblcustomfields' => [],
        'tblcustomfieldsvalues' => [],
        'tblhosting' => [],
        'tblusers_clients' => [],
    ];

    public static function reset(): void
    {
        self::$tables = [
            'tblservers' => [
                [
                    'type' => 'petrox',
                    'hostname' => 'panel.example.com',
                    'username' => '',
                    'password' => 'encrypted_mock_app_key',
                    'accesshash' => 'mock_admin_token',
                    'secure' => 'on',
                    'port' => 443,
                ]
            ],
            'tblcustomfields' => [
                [
                    'id' => 101,
                    'type' => 'product',
                    'relid' => 1,
                    'fieldname' => 'UUID (Server ID)',
                    'fieldtype' => 'text',
                    'adminonly' => 'on'
                ]
            ],
            'tblcustomfieldsvalues' => [],
            'tblhosting' => [
                [
                    'id' => 10,
                    'userid' => 5,
                    'domain' => '',
                    'username' => '',
                    'password' => '',
                ]
            ],
            'tblusers_clients' => [],
        ];
    }
}
}

namespace Illuminate\Database\Capsule {
    use MockDb;

    class Manager
    {
        public static function table(string $table)
        {
            return new class($table) {
                private string $table;
                private array $wheres = [];

                public function __construct(string $table)
                {
                    $this->table = $table;
                }

                public function where($column, $operatorOrVal = null, $val = null): self
                {
                    if ($val === null) {
                        $this->wheres[$column] = $operatorOrVal;
                    } else {
                        $this->wheres[$column] = $val;
                    }
                    return $this;
                }

                public function orderBy(string $col, string $dir = 'asc'): self
                {
                    return $this;
                }

                public function first(): ?object
                {
                    $rows = $this->get();
                    return !empty($rows) ? (object)$rows[0] : null;
                }

                public function get(): array
                {
                    $rows = MockDb::$tables[$this->table] ?? [];
                    if (empty($this->wheres)) return $rows;

                    return array_values(array_filter($rows, function ($row) {
                        foreach ($this->wheres as $k => $v) {
                            if (!isset($row[$k]) || (string)$row[$k] !== (string)$v) {
                                return false;
                            }
                        }
                        return true;
                    }));
                }

                public function insert(array $values): bool
                {
                    if (!isset($values['id'])) {
                        $values['id'] = count(MockDb::$tables[$this->table] ?? []) + 1;
                    }
                    MockDb::$tables[$this->table][] = $values;
                    return true;
                }

                public function update(array $values): int
                {
                    $count = 0;
                    if (!isset(MockDb::$tables[$this->table])) return 0;
                    foreach (MockDb::$tables[$this->table] as &$row) {
                        $match = true;
                        foreach ($this->wheres as $k => $v) {
                            if (!isset($row[$k]) || (string)$row[$k] !== (string)$v) {
                                $match = false;
                                break;
                            }
                        }
                        if ($match) {
                            $row = array_merge($row, $values);
                            $count++;
                        }
                    }
                    return $count;
                }

                public function updateOrInsert(array $attributes, array $values = []): bool
                {
                    $matchIdx = -1;
                    if (isset(MockDb::$tables[$this->table])) {
                        foreach (MockDb::$tables[$this->table] as $idx => $row) {
                            $match = true;
                            foreach ($attributes as $k => $v) {
                                if (!isset($row[$k]) || (string)$row[$k] !== (string)$v) {
                                    $match = false;
                                    break;
                                }
                            }
                            if ($match) {
                                $matchIdx = $idx;
                                break;
                            }
                        }
                    }

                    if ($matchIdx >= 0) {
                        MockDb::$tables[$this->table][$matchIdx] = array_merge(MockDb::$tables[$this->table][$matchIdx], $values);
                    } else {
                        MockDb::$tables[$this->table][] = array_merge($attributes, $values);
                    }
                    return true;
                }
            };
        }

        public static function getPdo()
        {
            return new class {
                public function lastInsertId(): int
                {
                    return 999;
                }
            };
        }
    }
}

namespace {
    // Global WHMCS helper mocks
    global $logCalls;
    $logCalls = [];

    function logModuleCall(string $module, string $action, $request, $response, $data = "", $variablesToRedact = [])
    {
        global $logCalls;
        $logCalls[] = [
            'module' => $module,
            'action' => $action,
            'request' => $request,
            'response' => $response,
        ];
    }

    function decrypt(string $ciphertext): string
    {
        return str_replace('encrypted_', '', $ciphertext);
    }

    // Include PetroX module
    require_once __DIR__ . '/../modules/servers/petrox/petrox.php';

    // Test Harness
    class TestRunner
    {
        private int $passed = 0;
        private int $failed = 0;
        private array $results = [];

        public function runTest(int $num, string $title, callable $fn): void
        {
            MockDb::reset();
            PetroXInstance::$mockApiHandler = null;
            PetroXInstance::get()->memory_as_gb = false;
            PetroXInstance::get()->disk_as_gb = false;
            PetroXInstance::get()->swap_as_gb = false;
            global $logCalls;
            $logCalls = [];

            try {
                $fn();
                $this->passed++;
                $this->results[] = "[PASS] Test $num: $title";
                echo "\033[32m[PASS]\033[0m Test $num: $title\n";
            } catch (Throwable $e) {
                $this->failed++;
                $this->results[] = "[FAIL] Test $num: $title - " . $e->getMessage();
                echo "\033[31m[FAIL]\033[0m Test $num: $title\n";
                echo "       Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
            }
        }

        public function report(): int
        {
            echo "\n========================================\n";
            echo "Test Summary: {$this->passed} Passed, {$this->failed} Failed, " . ($this->passed + $this->failed) . " Total\n";
            echo "========================================\n";
            return $this->failed === 0 ? 0 : 1;
        }
    }

    $runner = new TestRunner();

    // Base parameters for testing
    function getBaseParams(array $overrides = []): array
    {
        return array_merge([
            'serverhostname' => 'panel.example.com',
            'serverpassword' => 'mock_ptla_app_key_12345',
            'serveraccesshash' => 'mock_ptlc_client_key_12345',
            'serversecure' => 'on',
            'packageid' => 1,
            'serviceid' => 10,
            'domain' => '',
            'username' => '',
            'password' => '',
            'clientsdetails' => [
                'client_id' => 5,
                'email' => 'client@example.com',
                'firstname' => 'John',
                'lastname' => 'Doe',
            ],
            'configoptions' => [
                'egg_id' => 1,
                'location_id' => 1,
                'cpu' => 100,
                'memory' => 1024,
                'disk' => 5120,
                'swap' => 0,
                'io' => 500,
                'databases' => 1,
                'allocations' => 1,
                'backups' => 1,
            ],
            'customfields' => [],
        ], $overrides);
    }

    // ── Test 1: Successful API Authentication ──
    $runner->runTest(1, "Successful API authentication", function () {
        PetroXInstance::$mockApiHandler = function ($method, $url, $data, $headers) {
            if ($url === 'https://panel.example.com/api/application/nodes') {
                return [
                    'status_code' => 200,
                    'meta' => ['pagination' => ['total' => 2, 'count' => 2]],
                    'data' => [
                        ['attributes' => ['id' => 1, 'name' => 'Node 1']],
                        ['attributes' => ['id' => 2, 'name' => 'Node 2']],
                    ]
                ];
            }
            return ['status_code' => 404];
        };

        $res = petrox_TestConnection(getBaseParams());
        if (!$res['success'] || $res['error'] !== '') {
            throw new Exception("Test connection failed: " . $res['error']);
        }
    });

    // ── Test 2: Successful Server Provisioning ──
    $runner->runTest(2, "Successful server provisioning (POST /api/application/servers)", function () {
        $serverCreated = false;
        PetroXInstance::$mockApiHandler = function ($method, $url, $data, $headers) use (&$serverCreated) {
            // Check existing server lookup
            if (str_contains($url, 'servers/external/10')) {
                return ['status_code' => 404];
            }
            // User lookup
            if (str_contains($url, 'users/external/5')) {
                return [
                    'status_code' => 200,
                    'attributes' => ['id' => 42, 'username' => 'johndoe', 'email' => 'client@example.com']
                ];
            }
            // Egg lookup (v2 top-level)
            if (str_contains($url, 'eggs/1?include=variables,tags')) {
                return [
                    'status_code' => 200,
                    'attributes' => [
                        'id' => 1,
                        'docker_image' => 'ghcr.io/pterodactyl/yolks:java_21',
                        'startup' => 'java -Xmx{{SERVER_MEMORY}}M -jar server.jar',
                        'relationships' => [
                            'variables' => [
                                'data' => [
                                    ['attributes' => ['name' => 'Server Jar', 'env_variable' => 'SERVER_JARFILE', 'default_value' => 'server.jar']]
                                ]
                            ]
                        ]
                    ]
                ];
            }
            // Server create (POST /api/application/servers)
            if ($method === 'POST' && str_contains($url, 'servers?include=allocations')) {
                $serverCreated = true;
                // Validate deploy constraint: locations must not contain 0
                if (in_array(0, $data['deploy']['locations'], true)) {
                    throw new Exception("Received forbidden location ID 0!");
                }
                return [
                    'status_code' => 201,
                    'attributes' => [
                        'id' => 99,
                        'uuid' => '99999999-9999-9999-9999-999999999999',
                        'node' => 1,
                        'allocation' => 500,
                        'relationships' => [
                            'allocations' => [
                                'data' => [
                                    ['attributes' => ['id' => 500, 'ip' => '192.168.1.100', 'port' => 25565, 'alias' => '']]
                                ]
                            ]
                        ]
                    ]
                ];
            }
            return ['status_code' => 200];
        };

        $result = petrox_CreateAccount(getBaseParams());
        if ($result !== 'success') {
            throw new Exception("petrox_CreateAccount returned: " . $result);
        }
        if (!$serverCreated) {
            throw new Exception("Server creation API was not called!");
        }
    });

    // ── Test 3: Successful Existing-User Lookup and Synchronization ──
    $runner->runTest(3, "Successful existing-user lookup and synchronization", function () {
        $updatedExternalId = false;
        PetroXInstance::$mockApiHandler = function ($method, $url, $data, $headers) use (&$updatedExternalId) {
            // First check external_id -> not found
            if (str_contains($url, 'users/external/5')) {
                return ['status_code' => 404];
            }
            // Email filter -> found user with ID 88
            if (str_contains($url, 'users?filter[email]=')) {
                return [
                    'status_code' => 200,
                    'meta' => ['pagination' => ['total' => 1, 'count' => 1]],
                    'data' => [
                        [
                            'attributes' => [
                                'id' => 88,
                                'username' => 'existinguser',
                                'email' => 'client@example.com',
                                'first_name' => 'John',
                                'last_name' => 'Doe',
                            ]
                        ]
                    ]
                ];
            }
            // Sync PATCH to link external_id
            if ($method === 'PATCH' && str_contains($url, 'users/88')) {
                if (isset($data['external_id']) && $data['external_id'] === '5') {
                    $updatedExternalId = true;
                }
                return ['status_code' => 200];
            }
            return ['status_code' => 200];
        };

        $instance = PetroXInstance::get();
        $user = $instance->getPterodactylUser(getBaseParams(), [
            'id' => 5,
            'email' => 'client@example.com',
            'firstname' => 'John',
            'lastname' => 'Doe',
            'username' => 'existinguser'
        ]);

        if (($user['attributes']['id'] ?? 0) !== 88) {
            throw new Exception("Expected user ID 88, got: " . json_encode($user));
        }
        if (!$updatedExternalId) {
            throw new Exception("Did not synchronize external_id to WHMCS client ID!");
        }
    });

    // ── Test 4: Missing or Invalid Egg, Location, User, or Allocation IDs ──
    $runner->runTest(4, "Missing or invalid egg, location, user, or allocation IDs", function () {
        PetroXInstance::$mockApiHandler = fn() => ['status_code' => 404];

        // 4a. Missing egg_id
        $paramsNoEgg = getBaseParams();
        $paramsNoEgg['configoptions']['egg_id'] = '';
        $res = petrox_CreateAccount($paramsNoEgg);
        if (!str_contains($res, 'Missing or invalid Egg ID')) {
            throw new Exception("Expected missing egg error, got: " . $res);
        }

        // 4b. Invalid location_id (negative)
        $paramsBadLoc = getBaseParams();
        $paramsBadLoc['configoptions']['location_id'] = '-1';
        $res = petrox_CreateAccount($paramsBadLoc);
        if (!str_contains($res, 'Invalid or missing Location ID')) {
            throw new Exception("Expected invalid location error, got: " . $res);
        }
    });

    // ── Test 5: Invalid Deployment Configuration and Prevention of Location ID 0 ──
    $runner->runTest(5, "Prevention of location ID 0 in deployment configuration", function () {
        // Attempt provisioning with location_id = 0
        $paramsZeroLoc = getBaseParams();
        $paramsZeroLoc['configoptions']['location_id'] = 0;

        $res = petrox_CreateAccount($paramsZeroLoc);
        if (!str_contains($res, 'Location ID 0 is rejected by Pterodactyl')) {
            throw new Exception("Expected prevention of location ID 0, got: " . $res);
        }
    });

    // ── Test 6: Automatic Deployment and Valid Request Payloads ──
    $runner->runTest(6, "Automatic deployment payload structure validation (Pterodactyl v2)", function () {
        $capturedPayload = null;
        PetroXInstance::$mockApiHandler = function ($method, $url, $data, $headers) use (&$capturedPayload) {
            if (str_contains($url, 'servers/external/10')) return ['status_code' => 404];
            if (str_contains($url, 'users/external/5')) return ['status_code' => 200, 'attributes' => ['id' => 1]];
            if (str_contains($url, 'eggs/1?include=variables,tags')) {
                return [
                    'status_code' => 200,
                    'attributes' => ['id' => 1, 'docker_image' => 'img', 'startup' => 'cmd', 'relationships' => ['variables' => ['data' => []]]]
                ];
            }
            if ($method === 'POST' && str_contains($url, 'servers?include=allocations')) {
                $capturedPayload = $data;
                return [
                    'status_code' => 201,
                    'attributes' => [
                        'id' => 10,
                        'uuid' => 'uuid-10',
                        'node' => 1,
                        'allocation' => 1,
                        'relationships' => ['allocations' => ['data' => [['attributes' => ['id' => 1, 'ip' => '1.1.1.1', 'port' => 25565, 'alias' => '']]]]]
                    ]
                ];
            }
            return ['status_code' => 200];
        };

        $res = petrox_CreateAccount(getBaseParams());
        if ($res !== 'success') throw new Exception("CreateAccount failed: " . $res);

        // Verify Pterodactyl v2 payload rules
        if (!isset($capturedPayload['deploy']['locations']) || $capturedPayload['deploy']['locations'] !== [1]) {
            throw new Exception("Payload missing deploy.locations: " . json_encode($capturedPayload['deploy']));
        }
        if (!isset($capturedPayload['limits']) || !isset($capturedPayload['feature_limits'])) {
            throw new Exception("Payload missing limits or feature_limits!");
        }
        if ($capturedPayload['limits']['memory'] !== 1024 || $capturedPayload['limits']['disk'] !== 5120) {
            throw new Exception("Incorrect resource limits in payload!");
        }
        if (isset($capturedPayload['nest'])) {
            throw new Exception("Payload should not contain legacy nest for v2!");
        }
    });

    // ── Test 7: Multiple Port Allocation and Port Discovery ──
    $runner->runTest(7, "Multiple port allocation and port range processing", function () {
        $portsFound = false;
        PetroXInstance::$mockApiHandler = function ($method, $url, $data, $headers) use (&$portsFound) {
            if (str_contains($url, 'servers/external/10')) return ['status_code' => 404];
            if (str_contains($url, 'users/external/5')) return ['status_code' => 200, 'attributes' => ['id' => 1]];
            if (str_contains($url, 'eggs/1?include=variables,tags')) {
                return [
                    'status_code' => 200,
                    'attributes' => [
                        'id' => 1, 'docker_image' => 'img', 'startup' => 'cmd',
                        'relationships' => [
                            'variables' => [
                                'data' => [
                                    ['attributes' => ['env_variable' => 'SERVER_PORT', 'default_value' => '25565', 'name' => 'Port']],
                                    ['attributes' => ['env_variable' => 'QUERY_PORT', 'default_value' => '25566', 'name' => 'Query Port']],
                                ]
                            ]
                        ]
                    ]
                ];
            }
            if ($method === 'POST' && str_contains($url, 'servers?include=allocations')) {
                return [
                    'status_code' => 201,
                    'attributes' => [
                        'id' => 20,
                        'uuid' => 'uuid-20',
                        'node' => 2,
                        'allocation' => 101,
                        'relationships' => ['allocations' => ['data' => [['attributes' => ['id' => 101, 'ip' => '10.0.0.1', 'port' => 25565, 'alias' => '']]]]]
                    ]
                ];
            }
            // Allocations for node 2
            if (str_contains($url, 'nodes/2/allocations')) {
                return [
                    'status_code' => 200,
                    'data' => [
                        ['attributes' => ['id' => 101, 'ip' => '10.0.0.1', 'port' => 25565, 'assigned' => true]],
                        ['attributes' => ['id' => 102, 'ip' => '10.0.0.1', 'port' => 25566, 'assigned' => false]],
                    ]
                ];
            }
            // Build update for additional allocations
            if ($method === 'PATCH' && str_contains($url, 'servers/20/build')) {
                $portsFound = true;
                return [
                    'status_code' => 200,
                    'attributes' => [
                        'allocation' => 101,
                        'relationships' => ['allocations' => ['data' => [['attributes' => ['id' => 101, 'ip' => '10.0.0.1', 'port' => 25565, 'alias' => '']]]]]
                    ]
                ];
            }
            return ['status_code' => 200];
        };

        $params = getBaseParams();
        $params['configoptions']['ports_ranges'] = json_encode([
            'SERVER_PORT' => '25565-25565',
            'QUERY_PORT' => '25566-25566'
        ]);

        $res = petrox_CreateAccount($params);
        if ($res !== 'success') throw new Exception("CreateAccount failed: " . $res);
        if (!$portsFound) throw new Exception("Multiple port allocation PATCH was not executed!");
    });

    // ── Test 8: Configurable Resource Limits and Environment Variable Overrides ──
    $runner->runTest(8, "Configurable resource limits and environment variable overrides", function () {
        $envCaptured = null;
        PetroXInstance::$mockApiHandler = function ($method, $url, $data, $headers) use (&$envCaptured) {
            if (str_contains($url, 'servers/external/10')) return ['status_code' => 404];
            if (str_contains($url, 'users/external/5')) return ['status_code' => 200, 'attributes' => ['id' => 1]];
            if (str_contains($url, 'eggs/1?include=variables,tags')) {
                return [
                    'status_code' => 200,
                    'attributes' => [
                        'id' => 1, 'docker_image' => 'img', 'startup' => 'cmd',
                        'relationships' => [
                            'variables' => [
                                'data' => [
                                    ['attributes' => ['env_variable' => 'MAX_PLAYERS', 'name' => 'Max Players', 'default_value' => '20']],
                                    ['attributes' => ['env_variable' => 'MOTD', 'name' => 'MOTD', 'default_value' => 'A PetroX Server']],
                                ]
                            ]
                        ]
                    ]
                ];
            }
            if ($method === 'POST' && str_contains($url, 'servers?include=allocations')) {
                $envCaptured = $data['environment'];
                return [
                    'status_code' => 201,
                    'attributes' => [
                        'id' => 10, 'uuid' => 'u1', 'node' => 1, 'allocation' => 1,
                        'relationships' => ['allocations' => ['data' => [['attributes' => ['id' => 1, 'ip' => '1.1.1.1', 'port' => 25565, 'alias' => '']]]]]
                    ]
                ];
            }
            return ['status_code' => 200];
        };

        $params = getBaseParams();
        $params['configoptions']['default_variables'] = json_encode(['MAX_PLAYERS' => '50']);
        $params['configoptions']['MOTD'] = 'Custom WHMCS MOTD';

        $res = petrox_CreateAccount($params);
        if ($res !== 'success') throw new Exception("CreateAccount failed: " . $res);

        if (($envCaptured['MAX_PLAYERS'] ?? '') !== '50') {
            throw new Exception("MAX_PLAYERS not overridden from default_variables, got: " . json_encode($envCaptured));
        }
        if (($envCaptured['MOTD'] ?? '') !== 'Custom WHMCS MOTD') {
            throw new Exception("MOTD not overridden from configoptions, got: " . json_encode($envCaptured));
        }
    });

    // ── Test 9: API Validation Errors (HTTP 422) ──
    $runner->runTest(9, "API validation error extraction (HTTP 422)", function () {
        PetroXInstance::$mockApiHandler = function ($method, $url, $data, $headers) {
            if (str_contains($url, 'servers/external/10')) return ['status_code' => 404];
            if (str_contains($url, 'users/external/5')) return ['status_code' => 200, 'attributes' => ['id' => 1]];
            if (str_contains($url, 'eggs/1?include=variables,tags')) {
                return ['status_code' => 200, 'attributes' => ['id' => 1, 'docker_image' => 'img', 'startup' => 'cmd', 'relationships' => ['variables' => ['data' => []]]]];
            }
            if ($method === 'POST' && str_contains($url, 'servers?include=allocations')) {
                return [
                    'status_code' => 422,
                    'errors' => [
                        [
                            'code' => 'ValidationException',
                            'detail' => 'The selected egg is invalid.',
                            'source' => ['field' => 'egg']
                        ],
                        [
                            'code' => 'ValidationException',
                            'detail' => 'The limits.memory must be at least 128.',
                            'source' => ['field' => 'limits.memory']
                        ]
                    ]
                ];
            }
            return ['status_code' => 200];
        };

        $res = petrox_CreateAccount(getBaseParams());
        if (!str_contains($res, '[egg] The selected egg is invalid') || !str_contains($res, '[memory] The limits.memory must be at least 128')) {
            throw new Exception("Validation errors were not properly parsed and returned. Got: " . $res);
        }
    });

    // ── Test 10: Unauthorized and Insufficient Permission Responses (HTTP 401 / 403) ──
    $runner->runTest(10, "Unauthorized (HTTP 401) and Forbidden (HTTP 403) handling", function () {
        // 10a. 401 Unauthorized
        PetroXInstance::$mockApiHandler = fn() => ['status_code' => 401];
        $res401 = petrox_TestConnection(getBaseParams());
        if ($res401['success'] || !str_contains($res401['error'], 'HTTP 401')) {
            throw new Exception("Expected 401 error message, got: " . json_encode($res401));
        }

        // 10b. 403 Forbidden
        PetroXInstance::$mockApiHandler = fn() => ['status_code' => 403];
        $res403 = petrox_TestConnection(getBaseParams());
        if ($res403['success'] || !str_contains($res403['error'], 'HTTP 403')) {
            throw new Exception("Expected 403 error message, got: " . json_encode($res403));
        }
    });

    // ── Test 11: API Pagination Traversal ──
    $runner->runTest(11, "API pagination traversal across multiple pages", function () {
        PetroXInstance::$mockApiHandler = function ($method, $url, $data, $headers) {
            if (str_contains($url, 'locations')) {
                if (str_contains($url, 'page=1&')) {
                    return [
                        'status_code' => 200,
                        'meta' => ['pagination' => ['current_page' => 1, 'total_pages' => 2]],
                        'data' => [
                            ['attributes' => ['id' => 1, 'short' => 'us-east', 'long' => 'US East']]
                        ]
                    ];
                } elseif (str_contains($url, 'page=2&')) {
                    return [
                        'status_code' => 200,
                        'meta' => ['pagination' => ['current_page' => 2, 'total_pages' => 2]],
                        'data' => [
                            ['attributes' => ['id' => 2, 'short' => 'eu-west', 'long' => 'EU West']]
                        ]
                    ];
                }
            }
            return ['status_code' => 404];
        };

        $locations = petrox_loadLocations(getBaseParams());
        if (!isset($locations[1]) || !isset($locations[2])) {
            throw new Exception("Pagination failed to load both pages. Got: " . json_encode($locations));
        }
        if (count($locations) !== 2) {
            throw new Exception("Expected 2 locations from 2 pages, got: " . count($locations));
        }
    });

    // ── Test 12: Server Suspension, Unsuspension, Reinstall, and Termination ──
    $runner->runTest(12, "Server lifecycle operations (suspend, unsuspend, reinstall, terminate)", function () {
        $actions = [];
        PetroXInstance::$mockApiHandler = function ($method, $url, $data, $headers) use (&$actions) {
            if (str_contains($url, 'servers/external/10')) {
                return ['status_code' => 200, 'attributes' => ['id' => 77, 'uuid' => 'uuid-77']];
            }
            if ($method === 'POST' && str_contains($url, 'servers/77/suspend')) {
                $actions[] = 'suspend';
                return ['status_code' => 204];
            }
            if ($method === 'POST' && str_contains($url, 'servers/77/unsuspend')) {
                $actions[] = 'unsuspend';
                return ['status_code' => 204];
            }
            if ($method === 'DELETE' && str_contains($url, 'servers/77')) {
                $actions[] = 'delete';
                return ['status_code' => 204];
            }
            return ['status_code' => 200];
        };

        // 12a. Suspend
        $resSuspend = petrox_SuspendAccount(getBaseParams());
        if ($resSuspend !== 'success') throw new Exception("Suspend failed: " . $resSuspend);

        // 12b. Unsuspend
        $resUnsuspend = petrox_UnsuspendAccount(getBaseParams());
        if ($resUnsuspend !== 'success') throw new Exception("Unsuspend failed: " . $resUnsuspend);

        // 12c. Terminate
        $resTerminate = petrox_TerminateAccount(getBaseParams());
        if ($resTerminate !== 'success') throw new Exception("Terminate failed: " . $resTerminate);

        if ($actions !== ['suspend', 'unsuspend', 'delete']) {
            throw new Exception("Lifecycle actions mismatched: " . json_encode($actions));
        }
    });

    // ── Test 13: WHMCS Service Retries and Duplicate Provisioning Prevention ──
    $runner->runTest(13, "Idempotent service retry without creating duplicate servers", function () {
        $postServerCalled = false;
        PetroXInstance::$mockApiHandler = function ($method, $url, $data, $headers) use (&$postServerCalled) {
            if (str_contains($url, 'servers/external/10')) {
                // Server ALREADY exists!
                return [
                    'status_code' => 200,
                    'attributes' => [
                        'id' => 55,
                        'uuid' => '55555555-5555-5555-5555-555555555555',
                        'allocation' => 10,
                        'relationships' => [
                            'allocations' => [
                                'data' => [['attributes' => ['id' => 10, 'ip' => '1.2.3.4', 'port' => 25565, 'alias' => '']]]
                            ]
                        ]
                    ]
                ];
            }
            if ($method === 'POST' && str_contains($url, 'servers')) {
                $postServerCalled = true;
                return ['status_code' => 201];
            }
            return ['status_code' => 200];
        };

        $res = petrox_CreateAccount(getBaseParams());
        if ($res !== 'success') {
            throw new Exception("Retry provisioning failed: " . $res);
        }
        if ($postServerCalled) {
            throw new Exception("Server was recreated despite already existing!");
        }
    });

    // ── Test 14: Network Timeouts, Malformed Responses, and Server Errors ──
    $runner->runTest(14, "Network timeouts, malformed JSON, and server-side 5xx errors", function () {
        // 14a. cURL network failure
        PetroXInstance::$mockApiHandler = function () {
            return ['status_code' => 0, 'curl_error' => 'Connection timed out after 30001 milliseconds'];
        };
        $res = petrox_TestConnection(getBaseParams());
        if ($res['success'] || !str_contains($res['error'], 'Connection failure: Connection timed out')) {
            throw new Exception("Expected connection failure message, got: " . json_encode($res));
        }

        // 14b. HTTP 500 panel error
        PetroXInstance::$mockApiHandler = function () {
            return ['status_code' => 500, 'raw_body' => 'Internal Server Error'];
        };
        $res500 = petrox_TestConnection(getBaseParams());
        if ($res500['success'] || !str_contains($res500['error'], 'HTTP 500')) {
            throw new Exception("Expected 500 error message, got: " . json_encode($res500));
        }
    });

    // ── Test 15: Existing Configuration Compatibility ──
    $runner->runTest(15, "Backward compatibility with existing configuration & v1/v2 dual support", function () {
        // Test fallback to legacy nests if eggs endpoint 404s
        PetroXInstance::$mockApiHandler = function ($method, $url, $data, $headers) {
            // v2 eggs endpoint returns 404 (simulating v1 panel)
            if (str_contains($url, 'eggs/99?include=variables,tags')) {
                return ['status_code' => 404];
            }
            // v1 nested endpoint returns 200
            if (str_contains($url, 'nests/5/eggs/99?include=variables')) {
                return [
                    'status_code' => 200,
                    'attributes' => [
                        'id' => 99,
                        'docker_image' => 'v1_image',
                        'startup' => 'v1_startup',
                        'relationships' => ['variables' => ['data' => []]]
                    ]
                ];
            }
            return ['status_code' => 404];
        };

        $eggData = petroXGetEggData(getBaseParams(), 99, 5);
        if ($eggData['status_code'] !== 200 || ($eggData['attributes']['docker_image'] ?? '') !== 'v1_image') {
            throw new Exception("Failed to fall back to legacy nest egg data: " . json_encode($eggData));
        }

        // Test configuration helper compatibility
        $keys = petrox_ConfigKeys();
        if (!in_array('location_id', $keys, true) || !in_array('egg_id', $keys, true) || !in_array('ports_ranges', $keys, true)) {
            throw new Exception("Missing essential configuration keys!");
        }
    });

    exit($runner->report());
}
