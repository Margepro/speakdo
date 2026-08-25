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

	$head[$h][0] = dol_buildpath("/speakdo/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/speakdo/admin/devices.php", 1);
	$head[$h][1] = $langs->trans("Devices");
	$head[$h][2] = 'devices';
	$h++;

	$head[$h][0] = dol_buildpath("/speakdo/admin/billing.php", 1);
	$head[$h][1] = $langs->trans("Billing");
	$head[$h][2] = 'billing';
	$h++;
	/*
	$head[$h][0] = dol_buildpath("/speakdo/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = (isset($extrafields->attributes['myobject']['label']) && is_countable($extrafields->attributes['myobject']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;

	$head[$h][0] = dol_buildpath("/speakdo/admin/myobjectline_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFieldsLines");
	$nbExtrafields = (isset($extrafields->attributes['myobjectline']['label']) && is_countable($extrafields->attributes['myobjectline']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafieldsline';
	$h++;
	*/

	$head[$h][0] = dol_buildpath("/speakdo/admin/about.php", 1);
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

/**
 * @deprecated legacy only. Returns the module-wide shared admin token used solely by
 * speakdo_enroll_tenant_legacy(). This token is necessarily extractable from any publicly
 * distributed copy of this module — never use it as a model for new code. Kept only for
 * backward compatibility with middleware deployments that have not yet enabled bootstrap v2
 * (tenant_boostratp.md).
 */
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

function speakdo_tenant_bootstrap_mode()
{
    $mode = strtolower(trim(getDolGlobalString('SPEAKDO_TENANT_BOOTSTRAP_MODE', 'auto')));
    return in_array($mode, array('auto', 'v2', 'legacy'), true) ? $mode : 'auto';
}

/**
 * Thrown by speakdo_enroll_tenant_v2() specifically when the middleware deployment does not
 * implement bootstrap v2 at all — the ONLY condition under which speakdo_enroll_tenant() is
 * allowed to fall back to the legacy path in 'auto' mode (tenant_boostratp.md; never fall back
 * on a security or business error, only on an explicit version/capability mismatch). Detection
 * is deliberately conservative — see speakdo_assert_bootstrap_v2_supported().
 */
class SpeakDoTenantBootstrapUnsupportedException extends RuntimeException
{
}

/**
 * Distinguishes "this middleware deployment does not implement bootstrap v2 at all" from every
 * other kind of failure. Only a bare HTTP 404/501 whose body does not even parse as this
 * contract's JSON shape is treated as unsupported. A response carrying a recognizable
 * {"bootstrap_id":...} success shape, or a {"error":{"code":...}} envelope — even with an error
 * code this module version does not know about — is a real answer from a v2-aware middleware and
 * must NOT trigger a legacy fallback. This specific detection rule is this module's own
 * interpretation: tenant_boostratp.md does not name an explicit "version unsupported" signal.
 *
 * @param int        $httpCode
 * @param mixed      $decodedBody json_decode() result of the /bootstrap/start response
 * @throws SpeakDoTenantBootstrapUnsupportedException
 */
function speakdo_assert_bootstrap_v2_supported($httpCode, $decodedBody)
{
    $looksLikeContractResponse = is_array($decodedBody) && (
        array_key_exists('bootstrap_id', $decodedBody)
        || (isset($decodedBody['error']) && is_array($decodedBody['error']) && isset($decodedBody['error']['code']))
    );
    if (($httpCode === 404 || $httpCode === 501) && !$looksLikeContractResponse) {
        throw new SpeakDoTenantBootstrapUnsupportedException('SpeakDo middleware does not support tenant bootstrap v2 (HTTP '.$httpCode.')');
    }
}

/**
 * Enroll this installation's tenant with the SpeakDo middleware. Dispatches according to
 * SPEAKDO_TENANT_BOOTSTRAP_MODE (tenant_boostratp.md):
 *  - 'v2': bootstrap v2 only, never falls back to legacy.
 *  - 'legacy': the old shared-admin-token flow only.
 *  - 'auto' (default): tries v2 first, falls back to legacy ONLY when the middleware explicitly
 *    does not support v2 (SpeakDoTenantBootstrapUnsupportedException) — any security, challenge,
 *    business, or tenant-already-exists error from v2 propagates as-is, no fallback.
 * A no-op if the tenant is already enrolled (either path).
 */
function speakdo_enroll_tenant($db, $entity)
{
    if (speakdo_is_tenant_enrolled()) {
        return getDolGlobalString('SPEAKDO_TENANT_UUID') ?: getDolGlobalString('SPEAKDO_TENANT_ID');
    }

    $mode = speakdo_tenant_bootstrap_mode();
    if ($mode === 'legacy') {
        return speakdo_enroll_tenant_legacy($db, $entity);
    }

    try {
        return speakdo_enroll_tenant_v2($db, $entity);
    } catch (SpeakDoTenantBootstrapUnsupportedException $e) {
        if ($mode === 'v2') {
            throw $e; // v2 mode: never fall back, surface the incompatibility as-is.
        }
        dol_syslog('SpeakDo: bootstrap v2 unsupported by middleware, falling back to legacy: '.$e->getMessage(), LOG_WARNING);
        return speakdo_enroll_tenant_legacy($db, $entity);
    }
}

/**
 * Tenant bootstrap v2 (tenant_boostratp.md): no shared global secret. Proves control of this
 * Dolibarr instance via a challenge that the middleware itself fetches back from
 * GET /api/index.php/speakdo/bootstrap-proofs/{bootstrap_id} (Speakdo::bootstrapProof()) before
 * creating the tenant. Stores exactly the same SPEAKDO_TENANT_UUID / SPEAKDO_HMAC_SECRET as the
 * legacy path on success (tenant_boostratp.md §7 — fields aligned with the legacy response).
 *
 * @param DoliDB $db
 * @param int    $entity
 * @return string Tenant UUID
 * @throws SpeakDoTenantBootstrapUnsupportedException if this middleware does not implement v2
 * @throws RuntimeException on any other failure (security, business, transport)
 */
function speakdo_enroll_tenant_v2($db, $entity)
{
    $middlewareUrl = SPEAKDO_MIDDLEWARE_BASE_URL;
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL PHP extension is required for SpeakDo enrollment');
    }
    $installationId = speakdo_ensure_installation_uuid($db, $entity);

    // --- 1. start: no secret to present — that's precisely what this flow establishes ---
    $startBody = json_encode(array(
        'installation_id'   => $installationId,
        'dolibarr_base_url' => DOL_MAIN_URL_ROOT,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($middlewareUrl.'/api/v1/tenants/bootstrap/start');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $startBody,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Accept: application/json'),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    $startResp = curl_exec($ch);
    $startHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $startErr  = curl_error($ch);
    curl_close($ch);

    if ($startErr !== '') {
        throw new RuntimeException('SpeakDo bootstrap v2 start failed: '.$startErr);
    }
    $startData = json_decode((string) $startResp, true);
    speakdo_assert_bootstrap_v2_supported($startHttp, $startData);

    if ($startHttp !== 201 || !is_array($startData) || empty($startData['bootstrap_id']) || empty($startData['challenge'])) {
        $code = (is_array($startData['error'] ?? null)) ? ($startData['error']['code'] ?? null) : null;
        $msg  = (is_array($startData['error'] ?? null)) ? ($startData['error']['message'] ?? null) : null;
        dol_syslog('SpeakDo bootstrap v2 start failed (HTTP '.$startHttp.'): '.substr((string) $startResp, 0, 400), LOG_ERR);
        throw new RuntimeException('SpeakDo bootstrap v2 start failed'.($code ? ' ('.$code.')' : '').($msg ? ': '.$msg : ' (HTTP '.$startHttp.')'));
    }

    $bootstrapId      = (string) $startData['bootstrap_id'];
    $challenge        = (string) $startData['challenge'];
    $expiresInSeconds = isset($startData['expires_in_seconds']) ? (int) $startData['expires_in_seconds'] : 300;

    // --- 2. store locally so our own proof endpoint can answer the middleware's upcoming GET ---
    $now = dol_now();
    $expiresAt = $now + max(30, min(3600, $expiresInSeconds));
    $sql = "INSERT INTO ".MAIN_DB_PREFIX."speakdo_tenant_bootstrap(entity, bootstrap_id, challenge, installation_id, expires_at, datec) VALUES (";
    $sql .= ((int) $entity).", '".$db->escape($bootstrapId)."', '".$db->escape($challenge)."', '".$db->escape($installationId)."', '".$db->idate($expiresAt)."', '".$db->idate($now)."')";
    if (!$db->query($sql)) {
        throw new RuntimeException('SpeakDo bootstrap v2: unable to store challenge locally: '.$db->lasterror());
    }

    // --- 3. finalize: this call is what triggers the middleware's own outbound GET to our proof
    // endpoint, from a different request entirely — no deadlock, just a synchronous wait here.
    try {
        $ch = curl_init($middlewareUrl.'/api/v1/tenants/bootstrap/'.rawurlencode($bootstrapId).'/finalize');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_HTTPHEADER     => array('Accept: application/json'),
            CURLOPT_TIMEOUT        => 30, // must allow time for the middleware's own outbound verification call
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ));
        $finResp = curl_exec($ch);
        $finHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finErr  = curl_error($ch);
        curl_close($ch);

        if ($finErr !== '') {
            throw new RuntimeException('SpeakDo bootstrap v2 finalize failed: '.$finErr);
        }
        $finData = json_decode((string) $finResp, true);

        if ($finHttp !== 201 || !is_array($finData) || empty($finData['tenant_id']) || empty($finData['dolibarr_hmac_secret'])) {
            $code = (is_array($finData['error'] ?? null)) ? ($finData['error']['code'] ?? null) : null;
            $msg  = (is_array($finData['error'] ?? null)) ? ($finData['error']['message'] ?? null) : null;
            dol_syslog('SpeakDo bootstrap v2 finalize failed (HTTP '.$finHttp.'): '.substr((string) $finResp, 0, 400), LOG_ERR);
            throw new RuntimeException('SpeakDo bootstrap v2 finalize failed'.($code ? ' ('.$code.')' : '').($msg ? ': '.$msg : ' (HTTP '.$finHttp.')'));
        }

        $tenantUuid = (string) $finData['tenant_id'];
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantUuid)) {
            throw new RuntimeException('SpeakDo bootstrap v2: invalid tenant_id in response');
        }

        require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
        dolibarr_set_const($db, 'SPEAKDO_TENANT_UUID', $tenantUuid, 'chaine', 0, '', $entity);
        dolibarr_set_const($db, 'SPEAKDO_HMAC_SECRET', dolEncrypt((string) $finData['dolibarr_hmac_secret']), 'chaine', 0, '', $entity);
        dolibarr_del_const($db, 'SPEAKDO_TENANT_ID', $entity);

        return $tenantUuid;
    } finally {
        // The bootstrap row's only purpose was to answer the middleware's proof GET during this
        // finalize call; the middleware enforces one-shot consumption on its own side
        // (tenant_boostratp.md §6), so deleting our local copy afterwards — success or failure —
        // is hygiene, not a security boundary.
        $db->query("DELETE FROM ".MAIN_DB_PREFIX."speakdo_tenant_bootstrap WHERE bootstrap_id = '".$db->escape($bootstrapId)."'");
    }
}

/**
 * @deprecated legacy only — see speakdo_get_admin_token(). Original tenant enrollment: a single
 * shared admin token baked into every copy of this module, authenticating POST /internal/v1/tenants.
 * Kept only so installations whose middleware deployment has not enabled bootstrap v2 keep working
 * unchanged; new code must never call this directly — go through speakdo_enroll_tenant().
 */
function speakdo_enroll_tenant_legacy($db, $entity)
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
        // The admin token below is a bearer credential for a real public HTTPS endpoint — nothing
        // justifies skipping certificate validation on this call, unlike the self-referential
        // internal call in Speakdo::proxy() (Dolibarr calling its own local API).
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
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

/**
 * Generate and persist this installation's non-secret identifier, once. Required groundwork for
 * a future tenant bootstrap v2 (see tenant_boostratp.md): the middleware-side v2 route does not
 * exist yet, but every installation must already have a stable installation_id before it can be
 * used, and it must never be regenerated once set (it identifies this specific Dolibarr instance
 * across bootstrap attempts, not a credential).
 *
 * @param DoliDB $db
 * @param int    $entity
 * @return string The installation UUID (existing or newly created)
 */
function speakdo_ensure_installation_uuid($db, $entity)
{
    $existing = getDolGlobalString('SPEAKDO_INSTALLATION_UUID');
    if ($existing !== '') {
        return $existing;
    }
    require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
    $uuid = speakdo_uuid_v4();
    dolibarr_set_const($db, 'SPEAKDO_INSTALLATION_UUID', $uuid, 'chaine', 0, '', $entity);
    return $uuid;
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
 * Thrown when the SpeakDo middleware answers a signed request with a non-2xx status. When the
 * response carries the standard contract envelope {"ok":false,"error":{code,message,details,
 * request_id}} (mcp-provisioning-contract-dolibarr-module.md §5), $errorCode and $details are
 * populated from it. Calling code must branch on $errorCode — the only stable identifier per the
 * contract — and never on getMessage(), which carries the middleware's localized, free-form text.
 * $errorCode is null for transport-level failures (no parseable error envelope).
 */
class SpeakDoMiddlewareApiException extends RuntimeException
{
    /** @var int */
    public $httpCode;
    /** @var string|null */
    public $errorCode;
    /** @var array */
    public $details;

    public function __construct($httpCode, $errorCode, $message, array $details = array())
    {
        parent::__construct($message);
        $this->httpCode = (int) $httpCode;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }
}

/**
 * Sign and send a request to the SpeakDo middleware, reusing the single tenant HMAC mechanism
 * shared by every module -> middleware call (billing, MCP provisioning, GET /profiles, ...).
 * Canonical string and header names are fixed by mcp-provisioning-contract-dolibarr-module.md §1,
 * verified bit-for-bit against that document's example vectors:
 * canonical = METHOD."\n".PATH."\n".TIMESTAMP."\n".NONCE."\n".SHA256_HEX(BODY)
 * signature = base64(hmac_sha256(canonical, tenant secret))
 * PATH never includes the query string (contract §3 example 3), even when $queryParams is used to
 * build the request URL — only the request URL gets the query string appended, never the signature.
 *
 * Backward compatible with the pre-existing billing calls: they pass only ($method, $path,
 * $userRef), so $body/$extraHeaders/$queryParams default to their previous implicit values
 * (empty body, no extra headers, no query string) and produce byte-identical requests to before.
 *
 * @param string      $method       HTTP method (GET, POST, ...)
 * @param string      $path         Path only (no domain, no query string), e.g. '/api/v1/mcp/accesses'
 * @param string|null $userRef      Dolibarr user id/ref sent as X-SpeakDo-User-Ref, for logging only
 * @param string      $body         Raw request body to sign and send ('' for GET / no-body calls)
 * @param array       $extraHeaders Extra headers as 'Name' => 'value' (e.g. Idempotency-Key)
 * @param array       $queryParams  Query params appended to the request URL only (never signed)
 * @return array Decoded JSON response body (2xx only)
 * @throws SpeakDoMiddlewareApiException on any non-2xx response
 * @throws RuntimeException on transport-level failure (tenant not enrolled, no secret, no cURL, unparsable success response)
 */
function speakdo_middleware_signed_request($method, $path, $userRef = null, $body = '', array $extraHeaders = array(), array $queryParams = array())
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
    $rawBody = (string) $body;
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
    if ($rawBody !== '') {
        $headers[] = 'Content-Type: application/json';
    }
    foreach ($extraHeaders as $name => $value) {
        $headers[] = $name.': '.$value;
    }

    $url = SPEAKDO_MIDDLEWARE_BASE_URL.$path;
    if (!empty($queryParams)) {
        $url .= '?'.http_build_query($queryParams);
    }

    $ch = curl_init($url);
    $curlOpts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
    );
    if ($rawBody !== '') {
        $curlOpts[CURLOPT_POSTFIELDS] = $rawBody;
    }
    curl_setopt_array($ch, $curlOpts);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        throw new RuntimeException('SpeakDo middleware request failed: '.$curlError);
    }

    $data = json_decode((string) $response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        dol_syslog('SpeakDo middleware request failed (HTTP '.$httpCode.') '.$method.' '.$path.' response='.$response, LOG_ERR);
        if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
            $errCode = isset($data['error']['code']) ? (string) $data['error']['code'] : null;
            $errMsg = isset($data['error']['message']) ? (string) $data['error']['message'] : ('HTTP '.$httpCode);
            $errDetails = isset($data['error']['details']) && is_array($data['error']['details']) ? $data['error']['details'] : array();
            throw new SpeakDoMiddlewareApiException($httpCode, $errCode, $errMsg, $errDetails);
        }
        $preview = substr((string) $response, 0, 400);
        throw new SpeakDoMiddlewareApiException($httpCode, null, 'SpeakDo middleware request failed (HTTP '.$httpCode.'): '.($preview !== '' ? $preview : '(empty response body, see dolibarr.log)'));
    }

    if (!is_array($data)) {
        throw new RuntimeException('SpeakDo middleware: invalid response format');
    }
    return $data;
}

/**
 * Client types offered in the "add MCP access" UI, grouped by auth_type. Display/UX catalog only —
 * the middleware's oauth_clients table remains the sole authority on which OAuth client_type
 * values actually work (mcp-provisioning-contract-dolibarr-module.md §7); an unlisted or
 * unrecognized one simply surfaces 'oauth_client_not_registered', handled like any contract error.
 *
 * @return array{oauth: array<string,string>, bearer: array<string,string>}
 */
function speakdo_mcp_client_catalog()
{
    return array(
        'oauth' => array(
            'claude'  => 'Claude',
            'chatgpt' => 'ChatGPT',
        ),
        'bearer' => array(
            'yeastar' => 'Yeastar',
            'generic' => 'Service externe / automatisation',
        ),
    );
}

/**
 * Create an MCP access (bearer or oauth) for a Dolibarr user via the middleware's direct
 * provisioning contract (mcp-provisioning-contract-dolibarr-module.md §2). The caller is
 * responsible for verifying mcp_enabled and user status beforehand — per contract §9 the
 * middleware does not re-verify erp_user_id against Dolibarr on this route, it trusts the tenant
 * HMAC signature as proof the module already checked — and for the Idempotency-Key lifecycle
 * (§9: stable for a same-intent retry, regenerated after a corrected business error).
 *
 * @param int         $erpUserId      Dolibarr user id
 * @param string      $clientName     1-190 chars, becomes the displayed access name (terminals.display_label)
 * @param string      $clientType     1-60 chars; for auth_type=oauth must equal a registered oauth_clients.client_id
 * @param string      $authType       'bearer' or 'oauth'
 * @param string      $dolibarrApiKey Plaintext Dolibarr API key of $erpUserId
 * @param string      $idempotencyKey 16-190 chars, [A-Za-z0-9._:-]
 * @param string|null $terminalStatus 'active' or 'pending_approval', or null for the middleware default ('active')
 * @return array Decoded response — contract §2 (contains one-shot credentials, capture immediately)
 */
function speakdo_mcp_create_access($erpUserId, $clientName, $clientType, $authType, $dolibarrApiKey, $idempotencyKey, $terminalStatus = null)
{
    $payload = array(
        'erp_user_id'     => (int) $erpUserId,
        'client_name'     => $clientName,
        'client_type'     => $clientType,
        'auth_type'       => $authType,
        'mcp_enabled'     => true,
        'dolibarr_apikey' => $dolibarrApiKey,
    );
    if ($terminalStatus !== null) {
        $payload['terminal_status'] = $terminalStatus;
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return speakdo_middleware_signed_request('POST', '/api/v1/mcp/accesses', null, $body, array('Idempotency-Key' => $idempotencyKey));
}

/**
 * List MCP accesses for the tenant, optionally filtered by Dolibarr user id (contract §3 — a
 * convenience filter only, silently ignored if not a pure-digit value, never an authorization
 * boundary). channel=mcp is implicit server-side; PWA terminals never appear here.
 *
 * @param int|null $erpUserId
 * @return array List of access rows as returned by the middleware (contract §3)
 */
function speakdo_mcp_list_accesses($erpUserId = null)
{
    $queryParams = array();
    if ($erpUserId !== null && ctype_digit((string) $erpUserId)) {
        $queryParams['erp_user_id'] = (string) $erpUserId;
    }
    $data = speakdo_middleware_signed_request('GET', '/api/v1/mcp/accesses', null, '', array(), $queryParams);
    return is_array($data['accesses'] ?? null) ? $data['accesses'] : array();
}

/**
 * Revoke a single MCP access by terminal id. Idempotent server-side (contract §4): calling this
 * again on an already-revoked access still returns 200, never an error — safe to call without a
 * pre-check.
 *
 * @param string $terminalId
 * @return array {access_id, terminal_id, status:"revoked"}
 */
function speakdo_mcp_revoke_access($terminalId)
{
    $safeId = preg_replace('/[^0-9a-fA-F-]/', '', (string) $terminalId);
    return speakdo_middleware_signed_request('POST', '/api/v1/mcp/accesses/'.$safeId.'/revoke', null, '');
}

/**
 * Parse the MariaDB DATETIME(6) string returned as last_activity_at (contract §3): format
 * 'YYYY-MM-DD HH:MM:SS.ffffff', UTC, NOT ISO-8601 (space separator, no 'T', no timezone suffix).
 *
 * @param string|null $value
 * @return int|null Unix timestamp, or null if absent/unparsable
 */
function speakdo_mcp_parse_mariadb_datetime($value)
{
    if (empty($value)) {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s.u', (string) $value, new DateTimeZone('UTC'));
    if (!$dt) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', (string) $value, new DateTimeZone('UTC'));
    }
    return $dt ? $dt->getTimestamp() : null;
}

/**
 * UI-only status normalization for an MCP access row. Never alters the contract value itself —
 * only picks a label/picto for display. For auth_type=bearer the contract's `status` field is the
 * credential status (issued|active|revoked); for auth_type=oauth it is the terminal status
 * (pending_approval|active|revoked) — 'issued' and 'active' are both shown as "active" here since
 * both mean the access is currently usable (contract §3/§8).
 *
 * @param Translate $langs
 * @param string    $status
 * @return array{0:string,1:string} [label, picto status class]
 */
function speakdo_mcp_status_label($langs, $status)
{
    switch ($status) {
        case 'active':
        case 'issued':
            return array($langs->trans('SpeakDoMcpStatusActive'), 'status4');
        case 'pending_approval':
            return array($langs->trans('SpeakDoMcpStatusPending'), 'status1');
        case 'revoked':
            return array($langs->trans('SpeakDoMcpStatusRevoked'), 'status6');
        default:
            return array((string) $status, 'status0');
    }
}

/**
 * Map a SpeakDoMiddlewareApiException to a clean, translated message for the Dolibarr admin —
 * branches on the stable error.code (contract §5), never on the middleware's free-text message,
 * and never surfaces raw internals/stack traces.
 *
 * @param Translate                     $langs
 * @param SpeakDoMiddlewareApiException $e
 * @return string
 */
function speakdo_mcp_error_message($langs, SpeakDoMiddlewareApiException $e)
{
    $map = array(
        'mcp_not_enabled'                 => 'SpeakDoMcpNotEnabled',
        'oauth_client_not_registered'     => 'SpeakDoMcpErrOauthUnknown',
        'mcp_oauth_disabled'              => 'SpeakDoMcpErrOauthDisabled',
        'missing_erp_credential'          => 'SpeakDoMcpErrMissingCredential',
        'invalid_mcp_client'              => 'SpeakDoMcpErrInvalidClient',
        'invalid_mcp_auth_type'           => 'SpeakDoMcpErrInvalidAuthType',
        'invalid_terminal_status'         => 'SpeakDoMcpErrInvalidClient',
        'invalid_erp_user_id'             => 'SpeakDoMcpErrInvalidUser',
        'idempotency_key_required'        => 'SpeakDoMcpErrGeneric',
        'invalid_idempotency_key'         => 'SpeakDoMcpErrGeneric',
        'idempotency_conflict'            => 'SpeakDoMcpErrIdempotency',
        'idempotency_failed_final'        => 'SpeakDoMcpErrIdempotency',
        'idempotency_in_progress'         => 'SpeakDoMcpErrIdempotencyInProgress',
        'mcp_access_not_found'            => 'SpeakDoMcpErrAccessNotFound',
        'invalid_json'                    => 'SpeakDoMcpErrGeneric',
        'tenant_signature_missing'        => 'SpeakDoMcpErrTenant',
        'invalid_tenant_timestamp'        => 'SpeakDoMcpErrTenant',
        'tenant_timestamp_expired'        => 'SpeakDoMcpErrTenant',
        'invalid_tenant_nonce'            => 'SpeakDoMcpErrTenant',
        'invalid_tenant_signature'        => 'SpeakDoMcpErrTenant',
        'tenant_replay_detected'          => 'SpeakDoMcpErrTenant',
        'oauth_not_configured'            => 'SpeakDoMcpErrMiddlewareConfig',
        'mcp_provisioning_not_configured' => 'SpeakDoMcpErrMiddlewareConfig',
    );
    if ($e->errorCode !== null && isset($map[$e->errorCode])) {
        return $langs->trans($map[$e->errorCode]);
    }
    return $langs->trans('SpeakDoMcpErrGeneric');
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
