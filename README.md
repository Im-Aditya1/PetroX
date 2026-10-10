# PetroX

PetroX is a trailblazer in server deployment and management, offering advanced solutions that make complex server processes simpler and more efficient. Our technology empowers businesses to focus on growth by efficiently handling server allocation and deployment. Designed to meet a wide range of business needs, our scalable and reliable solutions are at the forefront of server management technology.

This module is an enhanced version of the original [Pterodactyl WHMCS module](https://github.com/pterodactyl/whmcs). It builds on the solid foundation of Pterodactyl, known for its robust and user-friendly server management capabilities, by adding additional features and improvements to further refine server management tasks. Among these enhancements is full compatibility with **Pterodactyl Panel v2** and **v1** (dual-support), multi-port allocations, port range discovery, and robust error handling.

## Technical Requirements

- **PHP Version**: PHP 8.0, 8.1, 8.2, 8.3 or higher.
- **PHP Extensions**: `curl`, `json`, `openssl`, `pdo_mysql`.
- **WHMCS Compatibility**: WHMCS 8.0 or higher.
- **Pterodactyl Panel**: Compatible with Pterodactyl Panel v1.x and Pterodactyl Panel v2.x.
- **TLS Requirements**: TLS 1.2 or TLS 1.3 with valid SSL certificates.

## Pterodactyl v2 Compatibility & Key Changes

PetroX includes full architectural support for Pterodactyl Panel v2 while maintaining seamless backward compatibility with v1.x installations:

1. **Top-Level Eggs & Tags Support**: In Pterodactyl v2, nests are removed in favor of top-level `/api/application/eggs` and tags (`/api/application/tags`). PetroX automatically queries v2 endpoints and seamlessly falls back to legacy nested routes (`/api/application/nests/{id}/eggs/{id}`) on v1 panels.
2. **Strict Location Validation**: Pterodactyl v2 strictly validates `deploy.locations.* => min:1`. Sending `0` or omitting location IDs triggers HTTP 422 errors. PetroX validates location IDs client-side and server-side, preventing invalid payloads.
3. **Dual Accept Headers**: Sends `Accept: application/json, application/vnd.pterodactyl.v1+json` to satisfy both v2 and v1 panel requirements.
4. **Idempotent Server Creation**: Prevents duplicate server provisioning on WHMCS service retries or timeouts by looking up existing servers by WHMCS service ID (`external_id`) or custom field UUID before initiating deployment.
5. **Secure Redaction & Detailed Logging**: All sensitive data (Bearer tokens, API keys, passwords) are automatically redacted before logging to WHMCS Module Log.
6. **Automatic API Pagination**: Seamlessly traverses paginated endpoints (locations, eggs, nodes, allocations) up to the panel's maximum page limit.

## Enhanced Features

- **Automated Port Allocation**: Simplifies server setup by automatically assigning ports, minimizing manual configuration.
- **Multiple Port Allocation**: Facilitates the allocation of multiple ports for complex networking requirements, a significant feature for games and applications with specific port needs.
- **Port Offset Support**: Offers enhanced port configuration options, ideal for applications requiring a query port that is offset from the main server port.
- **Intelligent User Management**: Automatically synchronizes WHMCS clients with Pterodactyl users using email and `external_id`.
- **Custom Server Environment Setup**: Provides the ability to tailor server environments to specific needs via configurable options.
- **Dynamic Server Configuration**: Dynamically adjusts server settings to optimize performance and resource management.
- **Advanced Server Deployment**: Automatic deployment to healthy nodes with available ports in the specified location.
- **Default Variables Support**: Enables pre-setting common variables for faster and more consistent server setups.
- **Game Server Status**: Displays real-time game server status directly within the WHMCS client and admin panels.
- **WHMCS Pterodactyl Sync**: Keeps client emails and server state synchronized between WHMCS and Pterodactyl.

---

# Module Installation & Upgrade Guide

## Step 1: Download and Unzip
Download the module release and extract it. You will find the `petrox` folder located under `modules/servers/`.

## Step 2: Upload to WHMCS
Upload the `petrox` folder to your WHMCS root at:
```text
/path/to/whmcs/modules/servers/petrox
```

### Upgrading from a Previous Version
PetroX is a direct drop-in replacement. Simply overwrite the existing files in `modules/servers/petrox/`. Your existing `config.json` and WHMCS product configurations are completely preserved.

## Step 3: Configure config.json
Adjust `modules/servers/petrox/config.json` as needed for your environment:
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

## Step 4: Required Pterodactyl API Key & Permissions
Create an **Application API Key** in your Pterodactyl Panel (**Admin Panel -> Application API**):
- **Users**: Read & Write (for creating and synchronizing client accounts)
- **Servers**: Read & Write (for provisioning, suspending, reinstalling, and terminating)
- **Locations**: Read (for loading deployment locations)
- **Nodes**: Read (for finding available allocations)
- **Allocations**: Read & Write (for port assignments)
- **Nests / Eggs**: Read (for egg variables, startup commands, and tags)
- **Database Hosts**: Read & Write (optional, if using WHMCS database management)

If you enable client server reboots or admin server transfers from WHMCS:
- Create an **Account API Key** from an Admin account in Pterodactyl (**Account Settings -> API Credentials**) and save it in the **Access Hash** field.

## Step 5: Server Configuration in WHMCS
1. Navigate to **WHMCS Admin** -> **Configuration** (or **Setup**) -> **Products/Services** -> **Servers**.
2. Click **Add New Server**.
3. **Name**: Pterodactyl Panel (or your choice).
4. **Hostname**: Your panel URL (e.g. `panel.example.com` or `https://panel.example.com`).
5. **Server Details Module**: Select **PetroX**.
6. **Username**: Leave blank.
7. **Password**: Paste your Pterodactyl **Application API Key** (starts with `ptla_`).
8. **Access Hash**: (Optional) Paste your Pterodactyl **Admin Account API Key** (starts with `ptlc_`) if using client power actions or transfers.
9. **Secure**: Check the box if your panel uses HTTPS / SSL (recommended).
10. Click **Save Changes**, then click **Test Connection** to verify API connectivity.

## Step 6: Product / Service Configuration
1. Go to **Setup** -> **Products/Services** -> **Products/Services**.
2. Edit or create your Game Server product.
3. Under **Module Settings**, select **PetroX**.
4. Configure your parameters:
   - **Location ID**: Select a valid positive location ID (e.g. `1`). Note: Location ID `0` is invalid.
   - **Egg ID**: Select your egg from the dropdown.
   - **Nest ID**: Required for Pterodactyl v1. Optional in Pterodactyl v2 (eggs are grouped by tags).
   - **Memory, Disk, Swap, CPU**: Resource limits for the container.
   - **Ports Ranges**: Configure primary and additional port ranges in JSON format (see FAQ).
5. Save your product.

---

# Troubleshooting & Module Logs

## Viewing WHMCS Module Logs
To diagnose issues with server creation, suspension, or API communication:
1. In WHMCS Admin, navigate to **Utilities** -> **Logs** -> **Module Log** (or **System Logs** -> **Module Log**).
2. Ensure **Module Logging** is set to **Enabled**.
3. Look for calls starting with `PetroX-WHMCS`.
4. The log displays the HTTP method, endpoint, request payload (with tokens redacted), and full panel response.

## Common HTTP Status Codes

| Status Code | Meaning | Resolution |
| :--- | :--- | :--- |
| **401 Unauthorized** | Invalid API credentials | Verify that the Application API key in the Server **Password** field starts with `ptla_` and has not expired or been deleted. |
| **403 Forbidden** | Insufficient permissions | Ensure your Application API key has Read & Write permissions for Users, Servers, Nodes, and Eggs. |
| **404 Not Found** | Resource does not exist | Check that the configured Egg ID, Location ID, or Server UUID exists on the panel. |
| **409 Conflict** | Resource state conflict | Usually occurs if a server is already being installed, transferred, or deleted. |
| **422 Unprocessable Entity** | Validation failure | Read the error message in WHMCS. Common causes: Location ID `0`, memory/disk limits below egg minimums, invalid Docker image, or invalid CPU pinning (`threads`). |
| **429 Too Many Requests** | Rate limit exceeded | The panel is throttling API requests. Wait a few moments or adjust Nginx/Pterodactyl rate limits. |
| **500 / 502 / 504** | Panel server error | Inspect Pterodactyl logs at `storage/logs/laravel-*.log` on your panel host. |
| **cURL Error 28 / Timeout** | Connection timed out | Verify firewall allows outbound HTTPS (port 443) from WHMCS to Pterodactyl and verify DNS resolution. |

---

# FAQ

## Multiple Port Allocation Configuration
Our module supports games or applications that require multiple ports. Configure `ports_ranges` in JSON format under Module Settings or Configurable Options:
```json
{
  "SERVER_PORT": "25565-25665",
  "QUERY_PORT": "25566-25666",
  "RCON_PORT": "25575-25675"
}
```
The module will find free allocations on the target node belonging to the same IP address and automatically assign them to the server container and environment variables.

## Overwriting Values Through Configurable Options
Values can be overwritten dynamically per order using either Configurable Options or Custom Fields. The option name must match the parameter name:
- `server_name`
- `memory`
- `swap`
- `io`
- `cpu`
- `disk`
- `location_id`
- `egg_id`
- `nest_id` (v1)
- `dedicated_ip`
- `ports_ranges`
- `image`
- `startup`
- `databases`
- `allocations`
- `backups`
- `oom_disabled`
- `username`

**Tip:** You can use the WHMCS pipe syntax for friendly display names:
```text
1|North America (US East)
2|Europe (Frankfurt)
```

## "Couldn't find any nodes satisfying the request"
This occurs when:
1. No nodes exist in the specified `location_id`.
2. The nodes in that location do not have unallocated IP ports matching the required port range.
3. The nodes have reached their configured memory or disk over-allocation limits.
