# HestiaCP Server Plugin for ClientExec

A free, open-source server plugin that integrates **[HestiaCP](https://hestiacp.com)** with **[ClientExec](https://www.clientexec.com)** for fully automated shared hosting account provisioning.

---

## Features

| Feature | Status |
|---|---|
| Automated account creation | ✅ |
| Domain added on account creation | ✅ |
| Account suspension / unsuspension | ✅ |
| Account deletion | ✅ |
| Password change | ✅ |
| Package (plan) change / upgrades | ✅ |
| SSH access toggle on create | ✅ |
| Login to HestiaCP button | ✅ |
| Live suspend/unsuspend state (queries HestiaCP API) | ✅ |
| Test server connection | ✅ |
| Access Key / Secret Key authentication | ✅ |
| Plugin logo in ClientExec UI | ✅ |

---

## Requirements

- ClientExec 6.x or later
- HestiaCP 1.5 or later
- PHP 7.4+ with cURL extension
- HTTPS access from your ClientExec server to the HestiaCP server (port 8083 by default)

---

## Installation

### 1 — Copy the plugin files

Upload the `hestiacp` directory into your ClientExec plugins folder:

```
[clientexec-root]/plugins/server/hestiacp/
    PluginHestiacp.php
    logo.png
    hestiacp.png
```

### 2 — Enable the HestiaCP API

SSH into your HestiaCP server and run:

```bash
v-change-sys-config-value API_SYSTEM api
```

Optionally restrict API access to your ClientExec server IP:

```bash
v-change-sys-config-value API_ALLOWED_IP YOUR.CLIENTEXEC.SERVER.IP
```

### 3 — Create an API Access Key

Generate a scoped access key with only the permissions the plugin needs:

```bash
v-add-access-key admin 'v-add-user,v-add-domain,v-delete-user,v-suspend-user,v-unsuspend-user,v-change-user-password,v-change-user-package,v-change-user-shell,v-list-users,v-list-user' clientexec json
```

The command outputs an **Access Key ID** (20 chars) and a **Secret Key** (40 chars). Save both.

### 4 — Add a Server in ClientExec

1. Go to **Settings → Servers → Add Server**
2. Select **HestiaCP** as the server type
3. Fill in:
   - **Server Hostname** — your HestiaCP server hostname or IP (no `https://`)
   - **Server Port** — `8083` (default)
   - **Access Key ID** — from Step 3
   - **Secret Key** — from Step 3
   - **Enable SSH on Create** — Yes / No
   - **Failure Email** — optional notification address
4. Click **Test Connection** to verify
5. Save

### 5 — Configure a Product

1. Go to **Products → Add / Edit Product**
2. Under the **Advanced** tab, select your HestiaCP server
3. Set **Package Name On Server** to the exact HestiaCP package name (e.g. `default`)
4. Save

> **Important:** Every product that uses this plugin must have **Package Name On Server** set. Provisioning will fail with a clear error message if it is missing.

---

## API Permissions

The plugin uses these HestiaCP API commands. The access key created in Step 3 grants exactly these — no more:

| Command | Purpose |
|---|---|
| `v-add-user` | Create hosting account |
| `v-add-domain` | Add domain to account |
| `v-delete-user` | Delete hosting account |
| `v-suspend-user` | Suspend hosting account |
| `v-unsuspend-user` | Unsuspend hosting account |
| `v-change-user-password` | Change account password |
| `v-change-user-package` | Change hosting package / plan |
| `v-change-user-shell` | Enable SSH access |
| `v-list-users` | Test server connection |
| `v-list-user` | Check live account status |

---

## Username Rules

HestiaCP usernames must be lowercase alphanumeric (plus `_` and `-`), max 16 characters, and cannot start with a digit. The plugin sanitises ClientExec usernames automatically to comply.

---

## Troubleshooting

**Test Connection fails with HTTP 401**
- Verify the Access Key ID and Secret Key are correct
- Check that the API is enabled: `v-list-sys-config | grep API`
- If you restricted `API_ALLOWED_IP`, confirm your ClientExec server IP is listed

**Account creation fails with "No package name configured"**
- Edit the product in ClientExec → Advanced tab → set **Package Name On Server**
- The value must exactly match a package name in HestiaCP: `v-list-user-packages admin`

**Suspend/Unsuspend not reflecting correctly**
- The plugin queries HestiaCP live for account state — ensure `v-list-user` is included in your access key permissions

---

## Changelog

| Version | Notes |
|---|---|
| 1.0.0 | Initial release — Create, Delete, Suspend, Unsuspend, ChangePassword, ChangePackage, TestConnection, Login button |

---

## License

[GPL-2.0](LICENSE)

---

## Credits

Developed by [Kalawebs](https://kalawebs.com)
