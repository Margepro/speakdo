<?php
/* Copyright (C) 2026		MargePro
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */


/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function SpeakDoAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("speakdo@speakdo");

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/speakdo/admin/setup.php", 1));
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dolBuildUrl(dol_buildpath("/speakdo/admin/devices.php", 1));
	$head[$h][1] = $langs->trans("Devices");
	$head[$h][2] = 'devices';
	$h++;

	$head[$h][0] = dolBuildUrl(dol_buildpath("/speakdo/admin/billing.php", 1));
	$head[$h][1] = $langs->trans("Billing");
	$head[$h][2] = 'billing';
	$h++;
	/*
	$head[$h][0] = dolBuildUrl(dol_buildpath("/speakdo/admin/myobject_extrafields.php", 1));
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = (isset($extrafields->attributes['myobject']['label']) && is_countable($extrafields->attributes['myobject']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;

	$head[$h][0] = dolBuildUrl(dol_buildpath("/speakdo/admin/myobjectline_extrafields.php", 1));
	$head[$h][1] = $langs->trans("ExtraFieldsLines");
	$nbExtrafields = (isset($extrafields->attributes['myobjectline']['label']) && is_countable($extrafields->attributes['myobjectline']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafieldsline';
	$h++;
	*/

	$head[$h][0] = dolBuildUrl(dol_buildpath("/speakdo/admin/about.php", 1));
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@test:/test/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@test:/test/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'test@test');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'test@test', 'remove');

	return $head;
}

require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

if (!defined('SPEAKDO_MIDDLEWARE_BASE_URL')) {
    define('SPEAKDO_MIDDLEWARE_BASE_URL', 'https://api.speakdo.fr');
}
if (!defined('SPEAKDO_BUILT_IN_ADMIN_TOKEN')) {
    define('SPEAKDO_BUILT_IN_ADMIN_TOKEN', 'z1l1t-cIy2hbsMlJgbLaS3B2O0vqcb-aTh8PPZM9ZEueOEOs59aEa4A6GzjH_yu_');
}


function speakdo_get_middleware_secret()
{
    $stored = getDolGlobalString('SPEAKDO_HMAC_SECRET');
    if ($stored === '') {
        $stored = getDolGlobalString('SPEAKDO_MIDDLEWARE_SECRET'); // backward compat
    }
    return $stored !== '' ? dolDecrypt($stored) : '';
}

function speakdo_get_admin_token()
{
    return SPEAKDO_BUILT_IN_ADMIN_TOKEN;
}

function speakdo_is_tenant_enrolled()
{
    $tenantId = getDolGlobalString('SPEAKDO_TENANT_UUID');
    if ($tenantId === '') {
        $tenantId = getDolGlobalString('SPEAKDO_TENANT_ID');
    }
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId);
}

function speakdo_enroll_tenant($db, $entity)
{
    if (speakdo_is_tenant_enrolled()) {
        return getDolGlobalString('SPEAKDO_TENANT_UUID') ?: getDolGlobalString('SPEAKDO_TENANT_ID');
    }

    $middlewareUrl = SPEAKDO_MIDDLEWARE_BASE_URL;
    $adminToken = speakdo_get_admin_token();
    if ($adminToken === '') {
        throw new RuntimeException('SPEAKDO_ADMIN_TOKEN not configured');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL PHP extension is required for SpeakDo enrollment');
    }

    $companyName = getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'instance');
    $slug = getDolGlobalString('SPEAKDO_SLUG');
    if ($slug === '') {
        $slug = 'dolibarr-'.trim(preg_replace('/[^a-z0-9]+/u', '-', mb_strtolower($companyName, 'UTF-8')), '-');
        $slug = $slug ?: 'dolibarr-instance';
    }
    $slug = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug)), '-') ?: 'dolibarr-instance';
    $displayName = getDolGlobalString('SPEAKDO_DISPLAY_NAME');
    if ($displayName === '') {
        $displayName = 'Dolibarr '.$companyName;
    }
    $body = json_encode(array(
        'slug'              => $slug,
        'display_name'      => $displayName,
        'dolibarr_base_url' => DOL_MAIN_URL_ROOT,
        'billing_status'    => 'trialing',
        'eligible_until'    => date('Y-m-d H:i:s', strtotime('+1 year')),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($middlewareUrl.'/internal/v1/tenants');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => array(
            'X-SpeakDo-Admin-Token: '.$adminToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ));
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        throw new RuntimeException('SpeakDo enrollment cURL error: '.$curlError);
    }
    if ($httpCode !== 200 && $httpCode !== 201) {
        dol_syslog('SpeakDo enrollment failed (HTTP '.$httpCode.') POST '.$middlewareUrl.'/internal/v1/tenants body='.$body.' response='.$response, LOG_ERR);
        $preview = substr((string) $response, 0, 400);
        throw new RuntimeException('SpeakDo enrollment failed (HTTP '.$httpCode.'): '.($preview !== '' ? $preview : '(empty response body, see dolibarr.log)'));
    }

    $data = json_decode($response, true);
    if (empty($data['ok']) || empty($data['tenant']['id'])) {
        $preview = substr((string) $response, 0, 400);
        throw new RuntimeException('SpeakDo enrollment: unexpected response format (HTTP '.$httpCode.'): '.$preview);
    }

    $tenantUuid = (string) $data['tenant']['id'];
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantUuid)) {
        throw new RuntimeException('SpeakDo enrollment: invalid tenant_id in response');
    }

    require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
    dolibarr_set_const($db, 'SPEAKDO_TENANT_UUID', $tenantUuid, 'chaine', 0, '', $entity);
    if (!empty($data['dolibarr_hmac_secret'])) {
        dolibarr_set_const($db, 'SPEAKDO_HMAC_SECRET', dolEncrypt((string) $data['dolibarr_hmac_secret']), 'chaine', 0, '', $entity);
    } else {
        // Secret not returned (already-enrolled tenant or middleware policy)
        // Log a warning so it's visible in syslog
        syslog(LOG_WARNING, 'SpeakDo enrollment: dolibarr_hmac_secret absent from response for tenant '.$tenantUuid.'. SPEAKDO_HMAC_SECRET NOT updated.');
    }
    // Remove legacy non-UUID tenant_id if it exists
    dolibarr_del_const($db, 'SPEAKDO_TENANT_ID', $entity);

    return $tenantUuid;
}

function speakdo_uuid_v4()
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function speakdo_ensure_user_api_key($db, User $targetUser)
{
    if (!empty($targetUser->api_key)) {
        return $targetUser->api_key;
    }

    $apiKey = bin2hex(random_bytes(32));
    $encrypted = dolEncrypt($apiKey);
    $sql = "UPDATE ".MAIN_DB_PREFIX."user SET api_key = '".$db->escape($encrypted)."' WHERE rowid = ".((int) $targetUser->id);
    if (!$db->query($sql)) {
        throw new RuntimeException($db->lasterror());
    }
    $targetUser->api_key = $apiKey;
    return $apiKey;
}

function speakdo_create_enrollment($db, $entity, $targetUserId, $authorUserId, $ttlSeconds, $channel = 'pwa')
{
    $channel = in_array($channel, array('pwa', 'mcp'), true) ? $channel : 'pwa';
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $tokenHash = hash('sha256', $token);
    $now = dol_now();
    $expires = $now + max(60, min(3600, (int) $ttlSeconds));
    $ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '';

    $sql = "INSERT INTO ".MAIN_DB_PREFIX."speakdo_enrollment(entity, token_hash, fk_user, fk_user_author, channel, status, datec, expires_at, ip_created) VALUES (";
    $sql .= ((int) $entity).", '".$db->escape($tokenHash)."', ".((int) $targetUserId).", ".((int) $authorUserId).", '".$db->escape($channel)."', 'PENDING', '".$db->idate($now)."', '".$db->idate($expires)."', ";
    $sql .= ($ip !== '' ? "'".$db->escape($ip)."'" : 'NULL').")";
    if (!$db->query($sql)) {
        throw new RuntimeException($db->lasterror());
    }

    return array('rowid' => $db->last_insert_id(MAIN_DB_PREFIX.'speakdo_enrollment'), 'token' => $token, 'channel' => $channel, 'expires_at' => $expires);
}

function speakdo_get_pending_enrollment($db, $entity, $rowid)
{
    $sql = "SELECT rowid, fk_user, channel, status, expires_at FROM ".MAIN_DB_PREFIX."speakdo_enrollment";
    $sql .= " WHERE rowid = ".((int) $rowid)." AND entity = ".((int) $entity);
    $resql = $db->query($sql);
    if (!$resql) {
        throw new RuntimeException($db->lasterror());
    }
    $enrollment = $db->fetch_object($resql);
    if (!$enrollment || $enrollment->status !== 'PENDING' || $db->jdate($enrollment->expires_at) < dol_now()) {
        return null;
    }
    return $enrollment;
}

function speakdo_enrollment_url($token, $channel = 'pwa', $tenantId = null)
{
    $base = rtrim(getDolGlobalString('SPEAKDO_APP_ENROLL_URL', 'https://app.speakdo.example/enroll'), '/');
    if ($tenantId === null) {
        $tenantId = getDolGlobalString('SPEAKDO_TENANT_UUID') ?: getDolGlobalString('SPEAKDO_TENANT_ID');
    }
    // channel is informational only here (it helps the PWA/middleware pick a UI/flow before
    // calling claim); the authoritative value is always llx_speakdo_enrollment.channel, read
    // server-side when the token is consumed — see Speakdo::doClaimEnrollment().
    return $base.'?'.http_build_query(array(
        'tenant_id' => $tenantId,
        'token'     => $token,
        'channel'   => $channel,
    ));
}

function speakdo_qr_data_uri($payload)
{
    $tcpdfBarcode = DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
    if (!is_readable($tcpdfBarcode)) {
        return '';
    }
    require_once $tcpdfBarcode;
    $barcode = new TCPDF2DBarcode($payload, 'QRCODE,H');
    $png = $barcode->getBarcodePngData(5, 5, array(0, 0, 0));
    return 'data:image/png;base64,'.base64_encode($png);
}

function speakdo_list_devices($db, $entity, $userId = 0)
{
    $devices = array();
    $sql = "SELECT d.rowid, d.public_id, d.fk_user, d.label, d.platform, d.pwa_version, d.channel, d.status, d.datec, d.last_seen_at, d.revoked_at, u.login, u.firstname, u.lastname";
    $sql .= " FROM ".MAIN_DB_PREFIX."speakdo_device d";
    $sql .= " INNER JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = d.fk_user";
    $sql .= " WHERE d.entity = ".((int) $entity);
    if ($userId > 0) {
        $sql .= " AND d.fk_user = ".((int) $userId);
    }
    $sql .= " ORDER BY d.datec DESC";
    $resql = $db->query($sql);
    if (!$resql) {
        throw new RuntimeException($db->lasterror());
    }
    while ($obj = $db->fetch_object($resql)) {
        $devices[] = $obj;
    }
    return $devices;
}

function speakdo_revoke_device($db, $entity, $deviceRowId, $actorUserId, $ownerUserId = 0)
{
    $sql = "UPDATE ".MAIN_DB_PREFIX."speakdo_device SET status = 'REVOKED', revoked_at = '".$db->idate(dol_now())."', fk_user_revoke = ".((int) $actorUserId);
    $sql .= " WHERE rowid = ".((int) $deviceRowId)." AND entity = ".((int) $entity)." AND status <> 'REVOKED'";
    if ($ownerUserId > 0) {
        $sql .= " AND fk_user = ".((int) $ownerUserId);
    }
    $resql = $db->query($sql);
    return $resql ? $db->affected_rows($resql) : -1;
}

function speakdo_revoke_all_devices($db, $entity, $ownerUserId, $actorUserId)
{
    $sql = "UPDATE ".MAIN_DB_PREFIX."speakdo_device SET status = 'REVOKED', revoked_at = '".$db->idate(dol_now())."', fk_user_revoke = ".((int) $actorUserId);
    $sql .= " WHERE entity = ".((int) $entity)." AND fk_user = ".((int) $ownerUserId)." AND status <> 'REVOKED'";
    if (!$db->query($sql)) {
        return -1;
    }
    return 1;
}

function speakdo_delete_device($db, $entity, $deviceRowId, $ownerUserId = 0)
{
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."speakdo_device WHERE rowid = ".((int) $deviceRowId)." AND entity = ".((int) $entity)." AND status = 'REVOKED'";
    if ($ownerUserId > 0) {
        $sql .= " AND fk_user = ".((int) $ownerUserId);
    }
    return $db->query($sql) ? 1 : -1;
}

function speakdo_api_is_enabled()
{
    return isModEnabled('api') && getDolGlobalInt('MAIN_MODULE_API') === 1;
}

/**
 * Whether this Dolibarr user is allowed to enroll/use a SpeakDo MCP client.
 * This is a channel activation flag, not a business right: Dolibarr's own
 * permissions remain the sole authority for what the user can do once
 * identified. Stored as a 'user' extrafield declared in modSpeakdo::init().
 *
 * @param User $targetUser Fetched user object
 * @return bool
 */
function speakdo_user_mcp_enabled(User $targetUser)
{
    $targetUser->fetch_optionals();
    return !empty($targetUser->array_options['options_speakdo_mcp_enabled']);
}

/**
 * Enable or disable the MCP channel for this Dolibarr user.
 *
 * @param DoliDB $db
 * @param User   $targetUser Fetched user object
 * @param bool   $enabled
 * @return bool The new state
 */
function speakdo_set_user_mcp_enabled($db, User $targetUser, $enabled)
{
    $targetUser->fetch_optionals();
    $targetUser->array_options['options_speakdo_mcp_enabled'] = $enabled ? 1 : 0;
    $result = $targetUser->updateExtraField('speakdo_mcp_enabled');
    if ($result < 0) {
        throw new RuntimeException($targetUser->error ?: 'Unable to update SpeakDo MCP flag');
    }
    return (bool) $enabled;
}

/**
 * Sign and send a request to the SpeakDo middleware billing endpoints.
 * These calls always carry an empty body — the canonical string and headers
 * match exactly what DolibarrClient verifies on the middleware side:
 * canonical = METHOD."\n".PATH."\n".TIMESTAMP."\n".NONCE."\n".SHA256('')
 * signature = base64(hmac_sha256(canonical, tenant secret))
 *
 * @param string      $method  HTTP method (GET, POST, ...)
 * @param string      $path    Path only (no domain, no query string), e.g. '/billing/status'
 * @param string|null $userRef Dolibarr user id/ref sent as X-SpeakDo-User-Ref, for logging only
 * @return array Decoded JSON response
 */
function speakdo_middleware_signed_request($method, $path, $userRef = null)
{
    $tenantId = getDolGlobalString('SPEAKDO_TENANT_UUID') ?: getDolGlobalString('SPEAKDO_TENANT_ID');
    if ($tenantId === '') {
        throw new RuntimeException('SpeakDo tenant is not enrolled');
    }
    $secret = speakdo_get_middleware_secret();
    if ($secret === '') {
        throw new RuntimeException('SpeakDo middleware secret is not configured');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL PHP extension is required for SpeakDo middleware calls');
    }

    $method = strtoupper($method);
    $rawBody = ''; // billing endpoints always sign an empty body
    $timestamp = (string) time();
    $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $canonical = $method."\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $rawBody);
    $signature = base64_encode(hash_hmac('sha256', $canonical, $secret, true));

    $headers = array(
        'X-SpeakDo-Tenant: '.$tenantId,
        'X-SpeakDo-Timestamp: '.$timestamp,
        'X-SpeakDo-Nonce: '.$nonce,
        'X-SpeakDo-Signature: '.$signature,
        'Accept: application/json',
    );
    if ($userRef !== null && $userRef !== '') {
        $headers[] = 'X-SpeakDo-User-Ref: '.$userRef;
    }

    $ch = curl_init(SPEAKDO_MIDDLEWARE_BASE_URL.$path);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
    ));
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        throw new RuntimeException('SpeakDo middleware request failed: '.$curlError);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        dol_syslog('SpeakDo middleware request failed (HTTP '.$httpCode.') '.$method.' '.$path.' response='.$response, LOG_ERR);
        $preview = substr((string) $response, 0, 400);
        throw new RuntimeException('SpeakDo middleware request failed (HTTP '.$httpCode.'): '.($preview !== '' ? $preview : '(empty response body, see dolibarr.log)'));
    }

    $data = json_decode((string) $response, true);
    if (!is_array($data)) {
        throw new RuntimeException('SpeakDo middleware: invalid response format');
    }
    return $data;
}

function speakdo_billing_get_status($userRef = null)
{
    return speakdo_middleware_signed_request('GET', '/billing/status', $userRef);
}

/**
 * Ask the middleware for a short-lived, single-use signed payment link and
 * return the ready-to-open URL (spec §3.2 / §5). initiating_channel is
 * recorded server-side as "dolibarr_module" for this route — nothing to send.
 *
 * @param string|null $userRef Dolibarr user id requesting the link, for logging only
 */
function speakdo_billing_get_link($userRef = null)
{
    $data = speakdo_middleware_signed_request('POST', '/billing/link', $userRef);

    if (!empty($data['url'])) {
        return (string) $data['url'];
    }
    if (!empty($data['token'])) {
        $base = rtrim(getDolGlobalString('SPEAKDO_PAY_BASE_URL', 'https://pay.speakdo.fr'), '/');
        return $base.'/'.rawurlencode((string) $data['token']);
    }
    throw new RuntimeException('SpeakDo billing: link response missing url/token');
}
