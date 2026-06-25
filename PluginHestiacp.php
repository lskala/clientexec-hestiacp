<?php

/**
 * HestiaCP Server Plugin for ClientExec
 *
 * @package  Plugins
 * @version  1.2.0
 */

require_once 'modules/admin/models/ServerPlugin.php';

class PluginHestiacp extends ServerPlugin
{
    public $features = [
        'packageName'     => true,
        'testConnection'  => true,
        'showNameservers' => true,
        'directlink'      => true,
        'upgrades'        => true,
    ];

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    public function getVariables()
    {
        return [
            'Name' => [
                'type'        => 'hidden',
                'description' => 'Used by CE to show plugin',
                'value'       => 'HestiaCP',
            ],
            'Description' => [
                'type'        => 'hidden',
                'description' => 'Description viewable by admin in server settings',
                'value'       => 'HestiaCP Web Hosting Control Panel',
            ],
            'Server Hostname' => [
                'type'        => 'text',
                'description' => 'Hostname or IP of the HestiaCP server (no https://)',
                'value'       => '',
            ],
            'Server Port' => [
                'type'        => 'text',
                'description' => 'HestiaCP port (default: 8083)',
                'value'       => '8083',
            ],
            'Access Key ID' => [
                'type'        => 'text',
                'description' => 'HestiaCP Access Key ID. Generate with: v-add-access-key admin \'v-add-user,v-add-domain,v-delete-user,v-suspend-user,v-unsuspend-user,v-change-user-password,v-change-user-package,v-change-user-shell,v-list-users\' clientexec json',
                'value'       => '',
                'encryptable' => true,
            ],
            'Secret Key' => [
                'type'        => 'password',
                'description' => 'HestiaCP Secret Key (40 chars)',
                'value'       => '',
                'encryptable' => true,
            ],
            'Enable SSH on Create' => [
                'type'        => 'yesno',
                'description' => 'Enable SSH (bash shell) for newly created accounts',
                'value'       => '0',
            ],
            'Failure Email' => [
                'type'        => 'text',
                'description' => 'Email address for provisioning failure notifications (optional)',
                'value'       => '',
            ],
            'Actions' => [
                'type'        => 'hidden',
                'description' => 'Current actions that are active for this plugin per server',
                'value'       => 'Create,Delete,Suspend,UnSuspend',
            ],

        ];
    }

    // =========================================================================
    // API HELPER
    // =========================================================================

    /**
     * Execute a HestiaCP API command via cURL.
     *
     * POST fields:
     *   hash       = "ACCESS_KEY_ID:SECRET_KEY"
     *   cmd        = command name
     *   returncode = 'yes' → integer return code | 'no' → full output
     *   arg1..argN = positional arguments
     *
     * JSON output: append 'json' as last argN + set returncode=no
     */
    private function apiCall(array $serverVars, string $command, array $args = [], bool $returnJson = false)
    {
        $host = trim($serverVars['plugin_hestiacp_Server_Hostname'] ?? '');
        $port = trim($serverVars['plugin_hestiacp_Server_Port'] ?? '8083');

        if (empty($host)) {
            throw new CE_Exception('HestiaCP: Server Hostname is not configured.');
        }

        $accessKey = trim($serverVars['plugin_hestiacp_Access_Key_ID'] ?? '');
        $secretKey = trim($serverVars['plugin_hestiacp_Secret_Key'] ?? '');

        if (empty($accessKey) || empty($secretKey)) {
            throw new CE_Exception('HestiaCP: Access Key ID and Secret Key must be configured.');
        }

        if ($returnJson) {
            $args[] = 'json';
        }

        $post = [
            'hash'       => $accessKey . ':' . $secretKey,
            'cmd'        => $command,
            'returncode' => $returnJson ? 'no' : 'yes',
        ];

        foreach ($args as $i => $value) {
            $post['arg' . ($i + 1)] = (string)$value;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://{$host}:{$port}/api/",
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($post),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($curlError)) {
            throw new CE_Exception("HestiaCP: cURL error – {$curlError}");
        }

        if ($httpCode === 403) {
            throw new CE_Exception('HestiaCP: Authentication failed. Check credentials and that this server IP is allowed.');
        }

        if ($httpCode !== 200) {
            throw new CE_Exception("HestiaCP: API returned HTTP {$httpCode}. Response: " . substr($response, 0, 300));
        }

        if ($returnJson) {
            $decoded = json_decode(trim($response), true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new CE_Exception('HestiaCP: Expected JSON but got: ' . substr($response, 0, 300));
            }
            return $decoded;
        }

        return (int)trim($response);
    }

    /**
     * Sanitise a string into a valid HestiaCP username.
     */
    private function sanitiseUsername(string $raw): string
    {
        $username = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $raw));
        $username = ltrim($username, '0123456789');
        $username = substr($username, 0, 16);
        return $username ?: 'user' . rand(1000, 9999);
    }

    /**
     * Get the HestiaCP package name from the CE product's "Package Name On Server" field.
     */
    private function resolvePackageName(array $args): string
    {
        $pkgName = trim($args['package']['name_on_server'] ?? '');
        if (empty($pkgName)) {
            throw new CE_Exception(
                'HestiaCP: No package name configured. Edit the product → Advanced tab → ' .
                'set "Package Name On Server" to the exact HestiaCP package name (e.g. "default").'
            );
        }
        return $pkgName;
    }

    private function logError(string $message, array $serverVars)
    {
        CE_Lib::log(4, 'HestiaCP Error: ' . $message);
        $failureEmail = trim($serverVars['plugin_hestiacp_Failure_Email'] ?? '');
        if (!empty($failureEmail)) {
            try {
                $mail = new NE_MailGateway();
                $mail->mailMessageHTML('HestiaCP Plugin Error', $message, $failureEmail);
            } catch (Exception $e) {
                CE_Lib::log(4, 'HestiaCP: Could not send failure email: ' . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // INTERNAL PROVISIONING METHODS (called by do* wrappers below)
    // =========================================================================

    public function create($args)
    {
        $serverVars = $args['server']['variables'];
        $username   = $this->sanitiseUsername($args['package']['username'] ?? '');
        $password   = trim($args['package']['password'] ?? '') ?: CE_Lib::generatePassword();
        $email      = trim($args['customer']['email'] ?? 'noreply@example.com');
        $package    = $this->resolvePackageName($args);
        $domain     = trim($args['package']['domain_name'] ?? '');
        $fullName   = trim(($args['customer']['first_name'] ?? '') . ' ' . ($args['customer']['last_name'] ?? '')) ?: 'Hosting User';

        CE_Lib::log(4, "HestiaCP create: user={$username} package={$package} domain={$domain}");

        $rc = $this->apiCall($serverVars, 'v-add-user', [
            $username, $password, $email, $package, $fullName,
        ]);

        if ($rc !== 0) {
            $msg = "v-add-user '{$username}' failed (rc={$rc})";
            $this->logError($msg, $serverVars);
            throw new CE_Exception("HestiaCP: {$msg}");
        }

        if (!empty($domain)) {
            $drc = $this->apiCall($serverVars, 'v-add-domain', [$username, $domain]);
            if ($drc !== 0) {
                CE_Lib::log(4, "HestiaCP: v-add-domain '{$domain}' for '{$username}' failed (rc={$drc}) – non-fatal");
            } else {
                CE_Lib::log(4, "HestiaCP: domain '{$domain}' added to '{$username}'");
            }
        }

        $enableSSH = strtolower(trim($serverVars['plugin_hestiacp_Enable_SSH_on_Create'] ?? '0'));
        if ($enableSSH === '1' || $enableSSH === 'yes') {
            try {
                $this->apiCall($serverVars, 'v-change-user-shell', [$username, 'bash']);
            } catch (Exception $e) {
                CE_Lib::log(4, "HestiaCP: could not enable SSH for '{$username}': " . $e->getMessage());
            }
        }
    }

    public function delete($args)
    {
        $serverVars = $args['server']['variables'];
        $username   = $this->sanitiseUsername($args['package']['username'] ?? '');

        CE_Lib::log(4, "HestiaCP delete: user={$username}");

        $rc = $this->apiCall($serverVars, 'v-delete-user', [$username]);

        if ($rc !== 0 && $rc !== 3) {
            $msg = "v-delete-user '{$username}' failed (rc={$rc})";
            $this->logError($msg, $serverVars);
            throw new CE_Exception("HestiaCP: {$msg}");
        }
    }

    public function suspend($args)
    {
        $serverVars = $args['server']['variables'];
        $username   = $this->sanitiseUsername($args['package']['username'] ?? '');

        CE_Lib::log(4, "HestiaCP suspend: user={$username}");

        $rc = $this->apiCall($serverVars, 'v-suspend-user', [$username]);

        if ($rc !== 0) {
            $msg = "v-suspend-user '{$username}' failed (rc={$rc})";
            $this->logError($msg, $serverVars);
            throw new CE_Exception("HestiaCP: {$msg}");
        }
    }

    public function unsuspend($args)
    {
        $serverVars = $args['server']['variables'];
        $username   = $this->sanitiseUsername($args['package']['username'] ?? '');

        CE_Lib::log(4, "HestiaCP unsuspend: user={$username}");

        $rc = $this->apiCall($serverVars, 'v-unsuspend-user', [$username]);

        if ($rc !== 0) {
            $msg = "v-unsuspend-user '{$username}' failed (rc={$rc})";
            $this->logError($msg, $serverVars);
            throw new CE_Exception("HestiaCP: {$msg}");
        }
    }

    public function changePassword($args)
    {
        $serverVars  = $args['server']['variables'];
        $username    = $this->sanitiseUsername($args['package']['username'] ?? '');
        $newPassword = trim($args['package']['password'] ?? '');

        if (empty($newPassword)) {
            throw new CE_Exception('HestiaCP: New password cannot be empty.');
        }

        CE_Lib::log(4, "HestiaCP changePassword: user={$username}");

        $rc = $this->apiCall($serverVars, 'v-change-user-password', [$username, $newPassword]);

        if ($rc !== 0) {
            $msg = "v-change-user-password '{$username}' failed (rc={$rc})";
            $this->logError($msg, $serverVars);
            throw new CE_Exception("HestiaCP: {$msg}");
        }
    }

    public function changePackage($args)
    {
        $serverVars = $args['server']['variables'];
        $username   = $this->sanitiseUsername($args['package']['username'] ?? '');
        $newPackage = $this->resolvePackageName($args);

        CE_Lib::log(4, "HestiaCP changePackage: user={$username} package={$newPackage}");

        $rc = $this->apiCall($serverVars, 'v-change-user-package', [$username, $newPackage]);

        if ($rc !== 0) {
            $msg = "v-change-user-package '{$username}' → '{$newPackage}' failed (rc={$rc})";
            $this->logError($msg, $serverVars);
            throw new CE_Exception("HestiaCP: {$msg}");
        }
    }

    // =========================================================================
    // do* WRAPPERS — exactly as per DirectAdmin/CyberPanel pattern
    // =========================================================================

    public function doCreate($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->create($this->buildParams($userPackage));
        return $userPackage->getCustomField('Domain Name') . ' has been created.';
    }

    public function doDelete($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->delete($this->buildParams($userPackage));
        return $userPackage->getCustomField('Domain Name') . ' has been deleted.';
    }

    public function doSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->suspend($this->buildParams($userPackage));
        return $userPackage->getCustomField('Domain Name') . ' has been suspended.';
    }

    public function doUnSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->unsuspend($this->buildParams($userPackage));
        return $userPackage->getCustomField('Domain Name') . ' has been unsuspended.';
    }

    public function doChangePassword($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->changePassword($this->buildParams($userPackage));
        return $userPackage->getCustomField('Domain Name') . ' password has been changed.';
    }

    public function doChangePackage($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $this->changePackage($this->buildParams($userPackage));
        return $userPackage->getCustomField('Domain Name') . ' package has been changed.';
    }

    // =========================================================================
    // AVAILABLE ACTIONS — queries HestiaCP live for accurate suspended state
    // =========================================================================

    public function getAvailableActions($userPackage)
    {
        $args     = $this->buildParams($userPackage);
        $username = $this->sanitiseUsername($args['package']['username'] ?? '');

        // No username = account not yet provisioned
        if (empty($username)) {
            return ['Create'];
        }

        // Query HestiaCP for the actual account state
        try {
            $serverVars = $args['server']['variables'];
            $result     = $this->apiCall($serverVars, 'v-list-user', [$username], true);

            // v-list-user returns array keyed by username
            $userInfo = $result[$username] ?? null;

            if (empty($userInfo)) {
                // User not found on server
                return ['Create'];
            }

            if (($userInfo['SUSPENDED'] ?? '') === 'yes') {
                return ['UnSuspend', 'Delete'];
            }

            return ['Suspend', 'Delete'];

        } catch (Exception $e) {
            // If API call fails fall back to CE's stored status via parent
            CE_Lib::log(4, 'HestiaCP getAvailableActions fallback: ' . $e->getMessage());
            return parent::getAvailableActions($userPackage);
        }
    }

    // =========================================================================
    // TEST CONNECTION
    // =========================================================================

    public function testConnection($args)
    {
        $serverVars = $args['server']['variables'];
        $result     = $this->apiCall($serverVars, 'v-list-users', [], true);

        if (!is_array($result)) {
            throw new CE_Exception('HestiaCP: Connection test failed – unexpected response.');
        }

        CE_Lib::log(4, 'HestiaCP: Connection test successful.');
    }

    // =========================================================================
    // DIRECT LOGIN LINK
    // =========================================================================

    public function getDirectLink($userPackage, $getRealLink = true, $fromAdmin = false, $isReseller = false)
    {
        $linkText = $this->user->lang('Login to HestiaCP');

        // When called from admin gear menu, CE needs cmd+label to build the menu item
        if ($fromAdmin) {
            return [
                'cmd'   => 'panellogin',
                'label' => $linkText,
            ];
        }

        $args = $this->buildParams($userPackage);
        $vars = $args['server']['variables'];
        $host = trim($vars['plugin_hestiacp_Server_Hostname'] ?? '');
        $port = trim($vars['plugin_hestiacp_Server_Port'] ?? '8083');

        if (!$getRealLink) {
            return [
                'fa'   => 'fa fa-user fa-fw',
                'link' => 'index.php?fuse=clients&controller=products&action=openpackagedirectlink'
                    . '&packageId=' . $userPackage->getId()
                    . '&sessionHash=' . CE_Lib::getSessionHash(),
                'text' => $linkText,
                'form' => '',
            ];
        }

        return [
            'fa'   => 'fa fa-user fa-fw',
            'link' => "https://{$host}:{$port}/login/",
            'text' => $linkText,
            'form' => '',
        ];
    }

    public function dopanellogin($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $response    = $this->getDirectLink($userPackage);
        return $response['link'];
    }
}