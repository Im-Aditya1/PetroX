# PetroX - Advanced Pterodactyl Provisioning Module for WHMCS

[![PHP Version](https://img.shields.io/badge/PHP-8.0%20--%208.3%2B-blue.svg)](https://php.net/)
[![WHMCS Compatibility](https://img.shields.io/badge/WHMCS-8.0%2B-green.svg)](https://whmcs.com/)
[![Pterodactyl](https://img.shields.io/badge/Pterodactyl-v1.x%20%26%20v2.x-orange.svg)](https://pterodactyl.io/)
[![Tests](https://img.shields.io/badge/Test%20Suite-21%20Passing-brightgreen.svg)](tests/test_runner.php)

**PetroX** is a premier, enterprise-grade server provisioning and management module for WHMCS, engineered specifically for game hosting providers, cloud infrastructure services, and enterprise game server management.

Built as an enhanced and optimized evolution of the official Pterodactyl WHMCS module, PetroX provides seamless **dual compatibility with both Pterodactyl Panel v2.x and v1.x**, advanced automated multi-port discovery, intelligent client synchronization, port-offset calculations, and hardened diagnostic logging.

---

## Table of Contents

- [Key Highlights](#key-highlights)
- [Technical Requirements](#technical-requirements)
- [Pterodactyl v2 Compatibility & Architecture](#pterodactyl-v2-compatibility--architecture)
- [Feature Matrix](#feature-matrix)
- [Installation Guide](#installation-guide)
- [Upgrade Guide](#upgrade-guide)
- [WHMCS Configuration](#whmcs-configuration)
  - [1. Server Configuration](#1-server-configuration)
  - [2. Product & Service Setup](#2-product--service-setup)
  - [3. Configurable Options & Overrides](#3-configurable-options--overrides)
- [Advanced Port Allocation & Discovery](#advanced-port-allocation--discovery)
  - [Multiple Ports Allocation (`ports_ranges`)](#multiple-ports-allocation-ports_ranges)
  - [Port Offset Support (`server_port_offset`)](#port-offset-support-server_port_offset)
- [Module Configuration (`config.json`)](#module-configuration-configjson)
- [Troubleshooting & Diagnostics](#troubleshooting--diagnostics)
  - [WHMCS Module Log](#whmcs-module-log)
  - [HTTP Error Reference](#http-error-reference)
- [Running Automated Tests](#running-automated-tests)
- [Frequently Asked Questions (FAQ)](#frequently-asked-questions-faq)
- [License & Support](#license--support)

---

## Key Highlights

- **Dual Pterodactyl v1 & v2 Engine**: Universally compatible across Pterodactyl Panel v1.x and v2.x without requiring any manual toggles or code adjustments.
- **Top-Level Egg & Tag Architecture**: Fully supports Pterodactyl 2.0 top-level eggs (`/api/application/eggs`) and tags with automatic fallback to legacy v1 nested endpoints.
- **Automated Multi-Port Allocation**: Allocates multiple adjacent or non-adjacent ports on the same host IP address for games requiring query, RCON, or companion ports (e.g., Rust, ARK, Minecraft, DayZ, FiveM).
- **Port Offset Automation**: Automatically calculates query ports using mathematical offsets (e.g. `SERVER_PORT + 1`), ideal for games like ARK: Survival Evolved.
- **Strict Location ID 0 Prevention**: Automatically validates deployment payloads to prevent HTTP 422 errors caused by invalid location IDs.
- **Idempotent Provisioning**: Detects existing servers by WHMCS service ID (`external_id`) or custom field UUID, avoiding duplicate deployments during WHMCS service retries or timeouts.
- **Automated API Pagination**: Traverses all pages seamlessly when loading locations, eggs, nodes, and allocations.
- **Hardened Credential Sanitization**: Automatically strips Bearer tokens, passwords, and sensitive keys from WHMCS module logs before writing to the database.

---

## Technical Requirements

| Component | Minimum Requirement | Recommended |
| :--- | :--- | :--- |
| **PHP** | 8.0 | 8.1, 8.2, or 8.3 |
| **PHP Extensions** | `curl`, `json`, `openssl`, `pdo_mysql` | `curl`, `json`, `openssl`, `pdo_mysql` |
| **WHMCS** | 8.0 | 8.8+ |
| **Pterodactyl Panel** | v1.7.0+ or v2.0-develop+ | Latest Stable Release |
| **TLS / SSL** | TLS 1.2 | TLS 1.3 |

---

## Pterodactyl v2 Compatibility & Architecture

Pterodactyl Panel v2 introduces significant architectural changes to its Application API. PetroX seamlessly adapts to these updates while preserving backward compatibility:

1. **Top-Level Eggs & Tags**: In v2, nests have been removed in favor of tags. PetroX queries `/api/application/eggs?include=variables,tags` and gracefully falls back to `/api/application/nests/{nestId}/eggs/{eggId}` on v1 panels.
2. **Location Constraint Enforcement**: Pterodactyl v2 strictly validates `deploy.locations.* => ['required', 'integer', 'min:1']`. PetroX validates location inputs client-side and server-side, preventing invalid payloads like `deploy.locations: [0]`.
3. **Dual Accept Headers**: Sends `Accept: application/json, application/vnd.pterodactyl.v1+json` to satisfy both v2 and v1 panel requirements.
4. **Dynamic Docker Images**: Supports both string `docker_image` (v1) and associative array `docker_images` (v2) from egg definitions.
5. **Admin API Fallback for Transfers**: Attempts server transfer on `/api/application/servers/{id}/transfer` (v1) and falls back to `/api/admin/servers/{id}/transfer` (v2) if admin credentials are provided.

---

## Feature Matrix

| Feature | PetroX | Official Pterodactyl Module |
| :--- | :---: | :---: |
| **Pterodactyl v2 API Support** | Yes (Dual v1/v2) | No (v1 only) |
| **Multi-Port Allocation** | Yes (Automated discovery) | No (Single port only) |
| **Port Offset Support** | Yes (`SERVER_PORT + offset`) | No |
| **Idempotent Retry Handling** | Yes (Duplicate prevention) | No |
| **API Pagination Traversal** | Yes (Automatic multi-page) | Partial |
| **Credential Redaction in Logs** | Yes (Bearer/password stripping) | No |
| **Client-Side Live Status** | Yes | Basic |
| **Client Area Password Sync** | Yes (Configurable) | Basic |
| **Configurable Option Overrides**| Full (All parameters) | Limited |

---

## Installation Guide

### Step 1: Download and Extract
Download or clone the PetroX repository. You will find the module directory under `modules/servers/petrox/`.

### Step 2: Upload to WHMCS
Upload the `petrox` folder to your WHMCS server module directory:
```text
<whmcs-root>/modules/servers/petrox/
```

Ensure the web server (e.g. `www-data`, `nginx`, or `apache`) has read access to the files and write access to the `modules/servers/petrox/log/` directory if file logging is enabled.

### Step 3: Configure Module Options
Review and edit `modules/servers/petrox/config.json` to configure global settings for your hosting environment (see [Module Configuration](#module-configuration-configjson)).

---

## Upgrade Guide

Upgrading an existing PetroX installation is 100% backward-compatible:

1. Backup your existing `modules/servers/petrox/config.json` file.
2. Upload and overwrite all files in `modules/servers/petrox/`.
3. Restore your `config.json` settings if overwritten.
4. No database schema migrations or product re-configurations are required. Existing client services, stored server UUIDs, and custom fields will continue operating uninterrupted.

---

## WHMCS Configuration

### 1. Server Configuration

1. In the WHMCS Admin area, navigate to **Configuration** (or **Setup**) -> **System Settings** -> **Servers**.
2. Click **Add New Server**.
3. Fill in the server details:
   - **Name**: A friendly name (e.g., `Production Pterodactyl`).
   - **Hostname**: Your panel URL (e.g., `panel.example.com` or `https://panel.example.com`).
   - **IP Address**: (Optional) IP address of your panel host.
   - **Module**: Select **PetroX** from the dropdown.
   - **Username**: Leave **blank**.
   - **Password**: Enter your Pterodactyl **Application API Key** (must start with `ptla_`).
   - **Access Hash**: (Optional) Enter a Pterodactyl **Admin Account API Key** (starts with `ptlc_`) if enabling client power actions or location transfers.
   - **Secure**: Check the box if your panel uses HTTPS / SSL (strongly recommended).
4. Click **Save Changes**, then click **Test Connection** to verify API connectivity.

#### Required Application API Key Permissions (Pterodactyl Panel)
Navigate to **Pterodactyl Admin Panel** -> **Application API** and create a key with the following minimum permissions:
- **Users**: Read & Write
- **Servers**: Read & Write
- **Locations**: Read
- **Nodes**: Read
- **Allocations**: Read & Write
- **Eggs**: Read (and **Tags**: Read for v2)
- **Database Hosts**: Read & Write (optional, if using database feature limits)

---

### 2. Product & Service Setup

1. In WHMCS, go to **Setup** -> **Products/Services** -> **Products/Services**.
2. Create or edit a Product (Type: **Other** or **Server/VPS**).
3. Under the **Module Settings** tab, select **PetroX** as the module.
4. Configure the product settings:
   - **Server Group**: Select the server group containing your PetroX server.
   - **Location ID**: Select a valid positive location ID (e.g., `1`). *Location ID 0 is strictly rejected.*
   - **Egg ID**: Select your egg from the dynamic dropdown.
   - **Nest ID**: Required for Pterodactyl v1. Optional in Pterodactyl v2 (eggs are grouped by tags).
   - **Memory (MB/GB)**: Total container RAM allocation.
   - **Disk Space (MB/GB)**: Total container disk storage.
   - **Swap (MB/GB)**: Swap memory allocation (`0` to disable).
   - **CPU Limit (%)**: CPU limit percentage (`100` = 1 core, `200` = 2 cores, `0` = unlimited).
   - **CPU Pinning (`threads`)**: Specific CPU cores (e.g. `0`, `0-1`, `0,2`) or blank for all cores.
   - **Ports Ranges**: JSON port range configuration (see below).
   - **Feature Limits**: Databases, Backups, and Allocations allowances.
5. Click **Save Changes**.

---

### 3. Configurable Options & Overrides

PetroX allows you to dynamically override any product parameter via WHMCS **Configurable Options** or **Custom Fields**.

The configurable option name must match the parameter name exactly:

| Option Key | Type | Description | Example / Notes |
| :--- | :--- | :--- | :--- |
| `memory` | Quantity / Dropdown | RAM allocation | `1024`, `2048`, `4096` |
| `disk` | Quantity / Dropdown | Disk space allocation | `5120`, `10240`, `20480` |
| `cpu` | Quantity / Dropdown | CPU allocation % | `100`, `200`, `400` |
| `swap` | Quantity / Dropdown | Swap memory | `0`, `512`, `1024` |
| `io` | Quantity / Dropdown | Block I/O weight | Range: `10` to `1000` (default: `500`) |
| `location_id` | Dropdown | Target deployment location | `1\|US East`, `2\|EU West` |
| `egg_id` | Dropdown | Specific Egg ID | `1\|Paper`, `2\|Forge` |
| `dedicated_ip` | Yes/No | Assign dedicated IP | `1` or `0` |
| `dedicated_port`| Yes/No | Reserve dedicated port | `1` or `0` |
| `ports_ranges` | Text | Port range JSON | See Port Allocation section |
| `databases` | Quantity | Max databases | `1`, `2`, `5` |
| `backups` | Quantity | Max backups | `1`, `3`, `7` |
| `allocations` | Quantity | Additional port allocations | `0`, `1`, `3` |
| `server_name` | Text | Name shown in panel | `Client Server` |
| `image` | Text | Custom Docker image | `ghcr.io/pterodactyl/yolks:java_17` |

> [!TIP]
> **WHMCS Display Names (Pipe Syntax)**: You can use WHMCS pipe syntax to present friendly names to customers while passing the raw value to PetroX:
> ```text
> 1|North America (New York)
> 2|Europe (Frankfurt)
> 3|Asia Pacific (Singapore)
> ```

---

## Advanced Port Allocation & Discovery

### Multiple Ports Allocation (`ports_ranges`)

Modern game servers frequently require multiple ports allocated to the same container on the same host IP address (e.g. Game Port, Query Port, RCON Port, Voice/Web Port).

Configure `ports_ranges` in JSON format under Module Settings or as a Configurable Option:

```json
{
  "SERVER_PORT": "25565-25665",
  "QUERY_PORT": "25566-25666",
  "RCON_PORT": "25575-25675"
}
```

#### How the Discovery Engine Works:
1. Pterodactyl provisions the server and assigns an initial primary allocation satisfying the `SERVER_PORT` range.
2. PetroX inspects all free allocations on the target node belonging to the **same IP address**.
3. It identifies unassigned allocations matching the required ranges for `QUERY_PORT`, `RCON_PORT`, etc.
4. PetroX automatically issues a `PATCH /api/application/servers/{id}/build` call adding the extra port IDs.
5. PetroX updates the container startup environment variables via `PATCH /api/application/servers/{id}/startup` binding each port to its respective variable.

### Port Offset Support (`server_port_offset`)

For games where secondary ports must be an exact arithmetic offset from the primary server port (e.g., ARK: Survival Evolved requiring Query Port = `SERVER_PORT + 1`):

- Set **Server Port Offset** (`server_port_offset`) to `1` (or your required integer offset).
- PetroX will automatically compute the offset and assign the adjacent port.

---

## Module Configuration (`config.json`)

The global behavior of PetroX is controlled via `modules/servers/petrox/config.json`:

```json
{
  "game_panel": "pterodactyl",
  "swap_as_gb": true,
  "disk_as_gb": true,
  "memory_as_gb": true,
  "enable_client_area_password_changer": true,
  "enable_whmcs_user_sync": true,
  "use_alias_ip": true,
  "allow_startup_edit": true,
  "allow_variables_edit": true
}
```

| Configuration Key | Type | Default | Description |
| :--- | :---: | :---: | :--- |
| `game_panel` | string | `"pterodactyl"` | Target panel architecture (`"pterodactyl"` or `"wisp"`). |
| `memory_as_gb` | boolean | `true` | If `true`, memory values in WHMCS are treated as GB and multiplied by 1024 before sending to Pterodactyl. Set to `false` if your WHMCS products specify RAM in MB. |
| `disk_as_gb` | boolean | `true` | If `true`, disk values are treated as GB and converted to MB. |
| `swap_as_gb` | boolean | `true` | If `true`, swap values are treated as GB and converted to MB. |
| `enable_client_area_password_changer` | boolean | `true` | Enables panel password reset directly from the WHMCS client service page. |
| `enable_whmcs_user_sync` | boolean | `true` | Synchronizes client email and name updates from WHMCS to Pterodactyl. |
| `use_alias_ip` | boolean | `true` | Uses allocation IP aliases (e.g. domain/FQDN) instead of raw IP when updating WHMCS domain records. |
| `allow_startup_edit` | boolean | `true` | Allows clients to view/edit startup parameters if permitted by the product. |
| `allow_variables_edit` | boolean | `true` | Allows clients to view/edit egg environment variables from the client area. |

---

## Troubleshooting & Diagnostics

### WHMCS Module Log

To inspect exact API requests and responses:
1. In WHMCS Admin, navigate to **Utilities** -> **Logs** -> **Module Log** (or **System Logs** -> **Module Log**).
2. Check the box to **Enable Debug Logging**.
3. Trigger the action in WHMCS (e.g. Create, Suspend, Terminate).
4. Inspect entries where Module is `PetroX-WHMCS`.

> [!NOTE]
> All sensitive authorization headers, Bearer tokens, and client passwords are automatically sanitized by PetroX before being logged.

### HTTP Error Reference

| Status Code | Meaning | Common Cause & Resolution |
| :--- | :--- | :--- |
| **401 Unauthorized** | Invalid API Token | The Application API key in the Server **Password** field is invalid, revoked, or lacks admin privileges. Verify it begins with `ptla_`. |
| **403 Forbidden** | Insufficient Permissions | The Application API key is missing required permissions (e.g. Server Create, Users, Allocations). |
| **404 Not Found** | Resource Missing | The configured Egg ID, Location ID, or Node ID does not exist on the panel. |
| **409 Conflict** | State Conflict | The server is currently performing another task (installing, transferring, or deleting). For terminations, PetroX automatically retries with force-delete. |
| **422 Unprocessable Entity** | Validation Error | The panel rejected input values. Inspect the detailed error message in WHMCS. Common causes: Location ID `0`, memory below egg minimum, or invalid CPU pinning (`threads`). |
| **429 Too Many Requests** | Rate Limited | Pterodactyl API rate limit reached. PetroX implements exponential backoff. Increase rate limits in Nginx/Laravel if persistent. |
| **500 Internal Error** | Panel Exception | Check Pterodactyl server logs on the panel host: `storage/logs/laravel-*.log`. |
| **cURL 28 / Timeout** | Network Timeout | WHMCS cannot reach Pterodactyl over port 443. Check firewall rules, DNS resolution, and SSL certificates. |

---

## Running Automated Tests

PetroX includes a zero-dependency automated test suite verifying all 15 core operational scenarios:

```bash
php tests/test_runner.php
```

### Verified Test Scenarios

```text
[PASS] Test 1:  Successful API authentication
[PASS] Test 2:  Successful server provisioning (POST /api/application/servers)
[PASS] Test 3:  Successful existing-user lookup and synchronization
[PASS] Test 4:  Missing or invalid egg, location, user, or allocation IDs
[PASS] Test 5:  Prevention of location ID 0 in deployment configuration
[PASS] Test 6:  Automatic deployment payload structure validation (Pterodactyl v2)
[PASS] Test 7:  Multiple port allocation and port range processing
[PASS] Test 8:  Configurable resource limits and environment variable overrides
[PASS] Test 9:  API validation error extraction (HTTP 422)
[PASS] Test 10: Unauthorized (HTTP 401) and Forbidden (HTTP 403) handling
[PASS] Test 11: API pagination traversal across multiple pages
[PASS] Test 12: Server lifecycle operations (suspend, unsuspend, reinstall, terminate)
[PASS] Test 13: Idempotent service retry without creating duplicate servers
[PASS] Test 14: Network timeouts, malformed JSON, and server-side 5xx errors
[PASS] Test 15: Backward compatibility with existing configuration & v1/v2 dual support
[PASS] Test 16: Password change via petrox_ChangePassword (PATCH /api/application/users/{id})
[PASS] Test 17: Server reinstallation and admin custom buttons (POST /api/application/servers/{id}/reinstall)
[PASS] Test 18: Multi-port allocation build patch deduplication and primary protection
[PASS] Test 19: Port range calculations, thread core 0, and multi-homed IP affinity
[PASS] Test 20: Universal Fractal relationships extraction (root vs attributes)
[PASS] Test 21: WHMCS Hook safety on 404 client edit and missing server configuration

========================================
Test Summary: 21 Passed, 0 Failed, 21 Total (100% Pass Rate)
========================================
```

---

## Frequently Asked Questions (FAQ)

#### Q: Does PetroX support Pterodactyl Panel v1.x after this upgrade?
**A:** Yes! PetroX provides full dual compatibility. If running against a v1.x panel, it automatically detects and queries legacy nested endpoints (`/api/application/nests/{nestId}/eggs/{eggId}`).

#### Q: Why did Pterodactyl reject my deployment with "deploy.locations: [0]"?
**A:** In Pterodactyl v2, location IDs must strictly be positive integers (`min: 1`). Sending `0` triggers HTTP 422. PetroX now validates location IDs client-side and server-side, preventing invalid payloads.

#### Q: How do I change RAM from GB to MB?
**A:** In `modules/servers/petrox/config.json`, change `"memory_as_gb": true` to `"memory_as_gb": false`.

#### Q: Where is the server UUID stored in WHMCS?
**A:** PetroX automatically creates and maintains a custom field named `UUID (Server ID)` attached to the product. The server's IP and primary port are also saved to the service `domain` field (`IP:PORT`).

#### Q: What happens if WHMCS retries a provisioning job after a timeout?
**A:** PetroX implements idempotent provisioning. Before sending a create request, it checks if a server matching the WHMCS service ID (`external_id`) or custom field UUID already exists. If found, it links the server and returns `success` without creating duplicates.

---

## License & Support

This project is open-source and released under the MIT License. Contributions and pull requests are welcome on GitHub.
