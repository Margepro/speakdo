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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = GETPOST('token', 'alphanohtml');
    if (empty($_SESSION['newtoken']) || !hash_equals((string) $_SESSION['newtoken'], (string) $postedToken)) {
        accessforbidden('Invalid CSRF token');
    }

    try {
        if ($action === 'generate') {
            $channel = GETPOST('channel', 'aZ09') === 'mcp' ? 'mcp' : 'pwa';
            if (!speakdo_api_is_enabled()) {
                throw new RuntimeException($langs->trans('SpeakDoApiDisabled'));
            }
            if ((int) $object->status !== User::STATUS_ENABLED) {
                throw new RuntimeException($langs->trans('SpeakDoUserDisabled'));
            }
            if ($channel === 'mcp' && !speakdo_user_mcp_enabled($object)) {
                throw new RuntimeException($langs->trans('SpeakDoMcpNotEnabled'));
            }
            speakdo_ensure_user_api_key($db, $object);
            $qr = speakdo_create_enrollment($db, $conf->entity, $object->id, $user->id, getDolGlobalInt('SPEAKDO_QR_TTL', 600), $channel);
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
        // channel is always taken from the stored enrollment row (authoritative), never from
        // what was cached in session — the session only caches the token for redisplay.
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

print '<div class="tabsAction">';
print '<form method="post" style="display:inline-block">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
print '<input type="hidden" name="action" value="generate">';
print '<input type="hidden" name="channel" value="pwa">';
print '<button class="butAction" type="submit">'.$langs->trans('SpeakDoGenerateQr').'</button>';
print '</form> ';
if ($mcpEnabled) {
    print '<form method="post" style="display:inline-block">';
    print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
    print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
    print '<input type="hidden" name="action" value="generate">';
    print '<input type="hidden" name="channel" value="mcp">';
    print '<button class="butAction" type="submit">'.$langs->trans('SpeakDoGenerateMcp').'</button>';
    print '</form> ';
}
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
    print '<p><strong>'.$langs->trans('SpeakDoChannel').':</strong> '.dol_escape_htmltag(strtoupper((string) $qr['channel'])).'</p>';
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

print load_fiche_titre($langs->trans('SpeakDoDevices'), '', 'mobile-alt');
print '<div class="div-table-responsive">';
print '<table class="liste centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('Label').'</th><th>'.$langs->trans('SpeakDoChannel').'</th><th>'.$langs->trans('Platform').'</th><th>'.$langs->trans('Version').'</th><th>'.$langs->trans('DateCreation').'</th><th>'.$langs->trans('LastActivity').'</th><th>'.$langs->trans('Status').'</th><th class="right">'.$langs->trans('Actions').'</th></tr>';
if (!$devices) {
    print '<tr><td colspan="8" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
}
foreach ($devices as $device) {
    print '<tr class="oddeven">';
    print '<td>'.dol_escape_htmltag($device->label).'<br><span class="opacitymedium">'.dol_escape_htmltag($device->public_id).'</span></td>';
    print '<td>'.dol_escape_htmltag(strtoupper((string) $device->channel)).'</td>';
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
print '</table></div></div>';
print dol_get_fiche_end();
llxFooter();
$db->close();
