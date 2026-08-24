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
$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/usergroups.lib.php';
require_once dol_buildpath('/speakdo/lib/speakdo.lib.php', 0);

$langs->loadLangs(array('users', 'speakdo@speakdo'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
if ($id <= 0) {
    $id = $user->id;
}

$object = new User($db);
if ($object->fetch($id) <= 0) {
    accessforbidden();
}

$canManage = !empty($user->admin) || $user->hasRight('speakdo', 'admin', 'manage') || ($user->id === $object->id && $user->hasRight('speakdo', 'user', 'manage'));
if (!$canManage) {
    accessforbidden();
}

$qr = null;
$mcpResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = GETPOST('token', 'alphanohtml');
    if (empty($_SESSION['newtoken']) || !hash_equals((string) $_SESSION['newtoken'], (string) $postedToken)) {
        accessforbidden('Invalid CSRF token');
    }

    try {
        if ($action === 'generate') {
            // PWA-only from here on: MCP access is now provisioned directly (action=mcp_create
            // below), not through a QR/enrollment token. channel is never taken from the request.
            if (!speakdo_api_is_enabled()) {
                throw new RuntimeException($langs->trans('SpeakDoApiDisabled'));
            }
            if ((int) $object->status !== User::STATUS_ENABLED) {
                throw new RuntimeException($langs->trans('SpeakDoUserDisabled'));
            }
            speakdo_ensure_user_api_key($db, $object);
            $qr = speakdo_create_enrollment($db, $conf->entity, $object->id, $user->id, getDolGlobalInt('SPEAKDO_QR_TTL', 600));
            $qr['url'] = speakdo_enrollment_url($qr['token'], $qr['channel']);
            $qr['image'] = speakdo_qr_data_uri($qr['url']);
            $_SESSION['speakdo_qr'][$object->id] = array('rowid' => $qr['rowid'], 'token' => $qr['token']);
        } elseif ($action === 'hideqr') {
            unset($_SESSION['speakdo_qr'][$object->id]);
        } elseif ($action === 'set_mcp') {
            speakdo_set_user_mcp_enabled($db, $object, GETPOSTINT('mcp_enabled') === 1);
            setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
        } elseif ($action === 'revoke') {
            speakdo_revoke_device($db, $conf->entity, GETPOSTINT('device_id'), $user->id, $object->id);
            setEventMessages($langs->trans('SpeakDoDeviceRevoked'), null, 'mesgs');
        } elseif ($action === 'delete') {
            speakdo_delete_device($db, $conf->entity, GETPOSTINT('device_id'), $object->id);
            setEventMessages($langs->trans('SpeakDoDeviceDeleted'), null, 'mesgs');
        } elseif ($action === 'revokeall') {
            speakdo_revoke_all_devices($db, $conf->entity, $object->id, $user->id);
            setEventMessages($langs->trans('SpeakDoAllDevicesRevoked'), null, 'mesgs');
        } elseif ($action === 'mcp_create') {
            if (!speakdo_user_mcp_enabled($object)) {
                throw new RuntimeException($langs->trans('SpeakDoMcpNotEnabled'));
            }
            if ((int) $object->status !== User::STATUS_ENABLED) {
                throw new RuntimeException($langs->trans('SpeakDoUserDisabled'));
            }
            $clientName = trim(GETPOST('mcp_client_name', 'alphanohtml'));
            $authType = GETPOST('mcp_auth_type', 'aZ09') === 'oauth' ? 'oauth' : 'bearer';
            $clientType = trim(GETPOST('mcp_client_type', 'alphanohtml'));
            if ($clientName === '' || $clientType === '') {
                throw new RuntimeException($langs->trans('SpeakDoMcpFieldsRequired'));
            }
            speakdo_ensure_user_api_key($db, $object);

            // Idempotency-Key lifecycle (contract §2/§9): reuse the same key only when this is the
            // exact same intent (same user + same three fields) as the last attempt for this user
            // — that covers a same-form retry after a transport-level failure. Any other case
            // (different content, or no prior attempt) gets a brand new key.
            $fingerprint = hash('sha256', json_encode(array($object->id, $clientName, $clientType, $authType)));
            $sessionIdemKey = 'speakdo_mcp_idem_'.$object->id;
            if (!empty($_SESSION[$sessionIdemKey]) && $_SESSION[$sessionIdemKey]['fingerprint'] === $fingerprint) {
                $idempotencyKey = $_SESSION[$sessionIdemKey]['key'];
            } else {
                $idempotencyKey = 'dol-'.((int) $conf->entity).'-'.bin2hex(random_bytes(12));
                $_SESSION[$sessionIdemKey] = array('key' => $idempotencyKey, 'fingerprint' => $fingerprint);
            }

            try {
                $mcpResult = speakdo_mcp_create_access($object->id, $clientName, $clientType, $authType, $object->api_key, $idempotencyKey);
                // Success: this intent is fully consumed — any later identical-looking request must get a fresh key.
                unset($_SESSION[$sessionIdemKey]);
            } catch (SpeakDoMiddlewareApiException $e) {
                if ($e->errorCode !== null) {
                    // The middleware answered with a business error: per contract §2/§9 it has
                    // already consumed this Idempotency-Key for 24h. Clear it so the next real
                    // attempt (once the underlying issue is fixed) gets a fresh key instead of
                    // silently replaying this same failure via idempotency_failed_final.
                    unset($_SESSION[$sessionIdemKey]);
                }
                // else: transport-level failure (no error.code, e.g. connection drop) — keep the
                // session entry so a same-form retry reuses the identical key, which the contract
                // guarantees is safe (§2 "un simple retry ... est sûr").
                throw new RuntimeException(speakdo_mcp_error_message($langs, $e));
            }
        } elseif ($action === 'mcp_revoke') {
            try {
                speakdo_mcp_revoke_access(GETPOST('terminal_id', 'alphanohtml'));
                setEventMessages($langs->trans('SpeakDoDeviceRevoked'), null, 'mesgs');
            } catch (SpeakDoMiddlewareApiException $e) {
                throw new RuntimeException(speakdo_mcp_error_message($langs, $e));
            }
        }
    } catch (Throwable $e) {
        setEventMessages($e->getMessage(), null, 'errors');
    }
}

if ($qr === null && !empty($_SESSION['speakdo_qr'][$object->id])) {
    $pending = $_SESSION['speakdo_qr'][$object->id];
    try {
        $enrollment = speakdo_get_pending_enrollment($db, $conf->entity, (int) $pending['rowid']);
    } catch (Throwable $e) {
        $enrollment = null;
    }
    if ($enrollment && (int) $enrollment->fk_user === (int) $object->id) {
        $qr = array('token' => $pending['token'], 'channel' => $enrollment->channel, 'expires_at' => $db->jdate($enrollment->expires_at));
        $qr['url'] = speakdo_enrollment_url($qr['token'], $qr['channel']);
        $qr['image'] = speakdo_qr_data_uri($qr['url']);
    } else {
        unset($_SESSION['speakdo_qr'][$object->id]);
    }
}

$mcpEnabled = speakdo_user_mcp_enabled($object);

$devices = array();
try {
    $devices = speakdo_list_devices($db, $conf->entity, $object->id);
} catch (Throwable $e) {
    setEventMessages($e->getMessage(), null, 'errors');
}

// Live listing from the middleware — MCP accesses are not stored locally (contract §3), this page
// is the sole place they are read from. A middleware outage must not break the rest of the page.
$mcpAccesses = array();
$mcpListError = null;
try {
    $mcpAccesses = speakdo_mcp_list_accesses($object->id);
} catch (SpeakDoMiddlewareApiException $e) {
    $mcpListError = speakdo_mcp_error_message($langs, $e);
} catch (Throwable $e) {
    $mcpListError = $langs->trans('SpeakDoMcpErrGeneric');
}

$csrfToken = newToken();

llxHeader('', 'SpeakDo');
$head = user_prepare_head($object);
print dol_get_fiche_head($head, 'speakdo', $langs->trans('User'), -1, 'user');

print '<div class="fichecenter">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('User').'</td><td>'.dol_escape_htmltag($object->getFullName($langs).' ('.$object->login.')').'</td></tr>';
print '<tr><td>'.$langs->trans('SpeakDoRestApi').'</td><td>'.(speakdo_api_is_enabled() ? img_picto('', 'status4').' '.$langs->trans('Enabled') : img_picto('', 'status8').' '.$langs->trans('Disabled')).'</td></tr>';
print '<tr><td>'.$langs->trans('SpeakDoUserApiKey').'</td><td>'.(!empty($object->api_key) ? img_picto('', 'status4').' '.$langs->trans('Configured') : img_picto('', 'status1').' '.$langs->trans('NotConfigured')).'</td></tr>';
print '<tr><td>'.$langs->trans('SpeakDoMcpAccess').'</td><td>';
print '<form method="post" style="display:inline">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
print '<input type="hidden" name="action" value="set_mcp">';
print '<label><input type="checkbox" name="mcp_enabled" value="1"'.($mcpEnabled ? ' checked' : '').' onchange="this.form.submit()"> '.$langs->trans('SpeakDoMcpAllowCheckbox').'</label>';
print '</form>';
print '</td></tr>';
print '</table>';

/*
 * Application SpeakDo (PWA) — unchanged QR/ECDSA enrollment flow.
 */
print '<div class="tabsAction">';
print '<form method="post" style="display:inline-block">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
print '<input type="hidden" name="action" value="generate">';
print '<button class="butAction" type="submit">'.$langs->trans('SpeakDoGenerateQr').'</button>';
print '</form> ';
if (!empty($devices)) {
    print '<form method="post" style="display:inline-block" onsubmit="return confirm(\''.dol_escape_js($langs->trans('SpeakDoConfirmRevokeAll')).'\');">';
    print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
    print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
    print '<input type="hidden" name="action" value="revokeall">';
    print '<button class="butActionDelete" type="submit">'.$langs->trans('SpeakDoRevokeAll').'</button>';
    print '</form>';
}
print '</div>';

if ($qr) {
    print '<div class="info" style="margin:1em 0;padding:1em;text-align:center">';
    print '<h3>'.$langs->trans('SpeakDoScanQr').'</h3>';
    if ($qr['image']) {
        print '<img alt="QR SpeakDo" src="'.dol_escape_htmltag($qr['image']).'" style="max-width:320px;width:100%;height:auto">';
    } else {
        print '<div class="warning">'.$langs->trans('SpeakDoQrLibraryMissing').'</div>';
    }
    print '<p><code style="word-break:break-all">'.dol_escape_htmltag($qr['url']).'</code></p>';
    print '<p>'.$langs->trans('SpeakDoQrExpires', dol_print_date($qr['expires_at'], 'dayhour')).'</p>';
    print '<form method="post" style="display:inline-block">';
    print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
    print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
    print '<input type="hidden" name="action" value="hideqr">';
    print '<button class="button small" type="submit">'.$langs->trans('SpeakDoHideQr').'</button>';
    print '</form>';
    print '</div>';
}

print load_fiche_titre($langs->trans('SpeakDoPwaDevicesTitle'), '', 'mobile-alt');
print '<div class="div-table-responsive">';
print '<table class="liste centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('Label').'</th><th>'.$langs->trans('Platform').'</th><th>'.$langs->trans('Version').'</th><th>'.$langs->trans('DateCreation').'</th><th>'.$langs->trans('LastActivity').'</th><th>'.$langs->trans('Status').'</th><th class="right">'.$langs->trans('Actions').'</th></tr>';
if (!$devices) {
    print '<tr><td colspan="7" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
}
foreach ($devices as $device) {
    print '<tr class="oddeven">';
    print '<td>'.dol_escape_htmltag($device->label).'<br><span class="opacitymedium">'.dol_escape_htmltag($device->public_id).'</span></td>';
    print '<td>'.dol_escape_htmltag((string) $device->platform).'</td>';
    print '<td>'.dol_escape_htmltag((string) $device->pwa_version).'</td>';
    print '<td>'.dol_print_date($db->jdate($device->datec), 'dayhour').'</td>';
    print '<td>'.($device->last_seen_at ? dol_print_date($db->jdate($device->last_seen_at), 'dayhour') : '-').'</td>';
    print '<td>'.dol_escape_htmltag($device->status).'</td>';
    print '<td class="right">';
    if ($device->status !== 'REVOKED') {
        print '<form method="post" style="display:inline-block">';
        print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'"><input type="hidden" name="id" value="'.((int) $object->id).'"><input type="hidden" name="action" value="revoke"><input type="hidden" name="device_id" value="'.((int) $device->rowid).'">';
        print '<button class="button small" type="submit">'.$langs->trans('Revoke').'</button></form>';
    } else {
        print '<form method="post" style="display:inline-block">';
        print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'"><input type="hidden" name="id" value="'.((int) $object->id).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="device_id" value="'.((int) $device->rowid).'">';
        print '<button class="button small" type="submit">'.$langs->trans('Delete').'</button></form>';
    }
    print '</td></tr>';
}
print '</table></div>';

/*
 * Accès MCP — direct provisioning via the middleware contract, no QR/enrollment token involved.
 */
print load_fiche_titre($langs->trans('SpeakDoMcpAccessesTitle'), '', 'mobile-alt');

if ($mcpListError) {
    print '<div class="warning">'.dol_escape_htmltag($mcpListError).'</div>';
}

if (!empty($mcpResult)) {
    $mcpUrl = (string) ($mcpResult['mcp_url'] ?? '');
    $creds = is_array($mcpResult['credentials'] ?? null) ? $mcpResult['credentials'] : array();
    $isOauth = (($mcpResult['auth_type'] ?? '') === 'oauth');
    print '<div class="info" style="margin:1em 0;padding:1em">';
    print '<h3>'.$langs->trans('SpeakDoMcpAccessCreated').'</h3>';
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('SpeakDoMcpUrl').'</td><td>';
    print '<input type="text" readonly class="minwidth300" id="speakdo_mcp_url_new" value="'.dol_escape_htmltag($mcpUrl).'"> ';
    print '<button type="button" class="button small" onclick="navigator.clipboard.writeText(document.getElementById(\'speakdo_mcp_url_new\').value)">'.$langs->trans('SpeakDoCopy').'</button>';
    print '</td></tr>';
    print '<tr><td>'.$langs->trans('SpeakDoMcpAuthType').'</td><td>'.($isOauth ? 'OAuth' : 'Bearer').'</td></tr>';
    if ($isOauth) {
        print '<tr><td>'.$langs->trans('SpeakDoMcpOauthClientId').'</td><td>';
        print '<input type="text" readonly class="minwidth200" id="speakdo_mcp_oauth_id_new" value="'.dol_escape_htmltag((string) ($creds['oauth_client_id'] ?? '')).'"> ';
        print '<button type="button" class="button small" onclick="navigator.clipboard.writeText(document.getElementById(\'speakdo_mcp_oauth_id_new\').value)">'.$langs->trans('SpeakDoCopy').'</button>';
        print '</td></tr>';
        if (!empty($creds['pairing_code'])) {
            print '<tr><td>'.$langs->trans('SpeakDoMcpPairingCode').'</td><td>';
            print '<input type="text" readonly class="minwidth400" id="speakdo_mcp_pairing_new" value="'.dol_escape_htmltag((string) $creds['pairing_code']).'"> ';
            print '<button type="button" class="button small" onclick="navigator.clipboard.writeText(document.getElementById(\'speakdo_mcp_pairing_new\').value)">'.$langs->trans('SpeakDoCopy').'</button>';
            print '</td></tr>';
        }
    } else {
        if (!empty($creds['bearer_token'])) {
            print '<tr><td>'.$langs->trans('SpeakDoMcpBearerKey').'</td><td>';
            print '<input type="text" readonly class="minwidth400" id="speakdo_mcp_bearer_new" value="'.dol_escape_htmltag((string) $creds['bearer_token']).'"> ';
            print '<button type="button" class="button small" onclick="navigator.clipboard.writeText(document.getElementById(\'speakdo_mcp_bearer_new\').value)">'.$langs->trans('SpeakDoCopy').'</button>';
            print '</td></tr>';
        }
    }
    print '</table>';
    if (!empty($mcpResult['warning'])) {
        print '<p class="warning">'.dol_escape_htmltag((string) $mcpResult['warning']).'</p>';
    }
    print '</div>';
}

if ($mcpEnabled) {
    $catalog = speakdo_mcp_client_catalog();
    print '<div class="fichecenter"><div class="fichehalfleft">';
    print '<div class="info" style="padding:1em">';
    print '<h4>'.$langs->trans('SpeakDoMcpAddOauth').'</h4>';
    print '<form method="post">';
    print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
    print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
    print '<input type="hidden" name="action" value="mcp_create">';
    print '<input type="hidden" name="mcp_auth_type" value="oauth">';
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('SpeakDoMcpClientName').'</td><td><input class="minwidth200" name="mcp_client_name" maxlength="190" required></td></tr>';
    print '<tr><td>'.$langs->trans('SpeakDoMcpClientType').'</td><td><select name="mcp_client_type" class="minwidth150">';
    foreach ($catalog['oauth'] as $key => $label) {
        print '<option value="'.dol_escape_htmltag($key).'">'.dol_escape_htmltag($label).'</option>';
    }
    print '</select></td></tr>';
    print '</table>';
    print '<div class="center" style="margin-top:0.5em"><button type="submit" class="butAction" onclick="this.disabled=true;this.form.submit();">'.$langs->trans('SpeakDoMcpAddButton').'</button></div>';
    print '</form>';
    print '</div>';
    print '</div><div class="fichehalfright">';
    print '<div class="info" style="padding:1em">';
    print '<h4>'.$langs->trans('SpeakDoMcpAddBearer').'</h4>';
    print '<form method="post">';
    print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
    print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
    print '<input type="hidden" name="action" value="mcp_create">';
    print '<input type="hidden" name="mcp_auth_type" value="bearer">';
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('SpeakDoMcpClientName').'</td><td><input class="minwidth200" name="mcp_client_name" maxlength="190" required></td></tr>';
    print '<tr><td>'.$langs->trans('SpeakDoMcpClientType').'</td><td><input class="minwidth150" name="mcp_client_type" list="speakdo_mcp_bearer_suggestions" maxlength="60" required>';
    print '<datalist id="speakdo_mcp_bearer_suggestions">';
    foreach ($catalog['bearer'] as $key => $label) {
        print '<option value="'.dol_escape_htmltag($key).'">'.dol_escape_htmltag($label).'</option>';
    }
    print '</datalist>';
    print '</td></tr>';
    print '</table>';
    print '<div class="center" style="margin-top:0.5em"><button type="submit" class="butAction" onclick="this.disabled=true;this.form.submit();">'.$langs->trans('SpeakDoMcpAddButton').'</button></div>';
    print '</form>';
    print '</div>';
    print '</div></div><div class="clearboth"></div>';
} else {
    print '<div class="opacitymedium" style="margin:0.5em 0">'.$langs->trans('SpeakDoMcpAddDisabledHint').'</div>';
}

print '<div class="div-table-responsive">';
print '<table class="liste centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('SpeakDoMcpClientName').'</th><th>'.$langs->trans('SpeakDoMcpClientType').'</th><th>'.$langs->trans('SpeakDoMcpAuthType').'</th><th>'.$langs->trans('Status').'</th><th>'.$langs->trans('SpeakDoMcpLastValidation').'</th><th class="right">'.$langs->trans('Actions').'</th></tr>';
if (!$mcpAccesses && !$mcpListError) {
    print '<tr><td colspan="6" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
}
foreach ($mcpAccesses as $access) {
    $authType = (string) ($access['auth_type'] ?? '');
    $status = (string) ($access['status'] ?? '');
    list($statusLabel, $statusPicto) = speakdo_mcp_status_label($langs, $status);
    $lastActivityTs = speakdo_mcp_parse_mariadb_datetime($access['last_activity_at'] ?? null);
    $terminalId = (string) ($access['terminal_id'] ?? '');
    print '<tr class="oddeven">';
    print '<td>'.dol_escape_htmltag((string) ($access['client_name'] ?? '')).'</td>';
    print '<td>'.dol_escape_htmltag((string) ($access['client_type'] ?? '')).'</td>';
    print '<td>'.($authType === 'oauth' ? 'OAuth' : 'Bearer').'</td>';
    print '<td>'.img_picto('', $statusPicto).' '.dol_escape_htmltag($statusLabel).'</td>';
    print '<td>'.($lastActivityTs ? dol_print_date($lastActivityTs, 'dayhour') : '-').'</td>';
    print '<td class="right">';
    if ($status !== 'revoked') {
        print '<form method="post" style="display:inline-block" onsubmit="return confirm(\''.dol_escape_js($langs->trans('SpeakDoMcpConfirmRevoke')).'\');">';
        print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
        print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
        print '<input type="hidden" name="action" value="mcp_revoke">';
        print '<input type="hidden" name="terminal_id" value="'.dol_escape_htmltag($terminalId).'">';
        print '<button class="button small" type="submit">'.$langs->trans('Revoke').'</button></form>';
    }
    print '</td></tr>';
}
print '</table></div>';
print '<p class="opacitymedium">'.$langs->trans('SpeakDoMcpLastValidationHint').'</p>';

print '</div>';
print dol_get_fiche_end();
llxFooter();
$db->close();
