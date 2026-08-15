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
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once dol_buildpath('/speakdo/lib/speakdo.lib.php', 0);

$langs->loadLangs(array('users', 'speakdo@speakdo'));
if (empty($user->admin) && !$user->hasRight('speakdo', 'admin', 'manage')) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = GETPOST('token', 'alphanohtml');
    if (empty($_SESSION['newtoken']) || !hash_equals((string) $_SESSION['newtoken'], (string) $postedToken)) {
        accessforbidden('Invalid CSRF token');
    }
    if ($action === 'revoke') {
        speakdo_revoke_device($db, $conf->entity, GETPOSTINT('device_id'), $user->id);
        setEventMessages($langs->trans('SpeakDoDeviceRevoked'), null, 'mesgs');
    } elseif ($action === 'delete') {
        speakdo_delete_device($db, $conf->entity, GETPOSTINT('device_id'));
        setEventMessages($langs->trans('SpeakDoDeviceDeleted'), null, 'mesgs');
    }
}

$devices = speakdo_list_devices($db, $conf->entity);
$csrfToken = newToken();

llxHeader('', $langs->trans('SpeakDoDevices'));
print load_fiche_titre($langs->trans('SpeakDoDevices'), '', 'mobile-alt');

// Configuration header
$head = SpeakDoAdminPrepareHead();
print dol_get_fiche_head($head, 'devices', $langs->trans('SpeakDoSetup'), -1, "test@test");

print '<div class="tabsAction"><a class="butAction" href="'.dol_buildpath('/speakdo/admin/setup.php', 1).'">'.$langs->trans('Setup').'</a></div>';
print '<div class="div-table-responsive"><table class="liste centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('User').'</th><th>'.$langs->trans('Label').'</th><th>'.$langs->trans('Platform').'</th><th>'.$langs->trans('Version').'</th><th>'.$langs->trans('DateCreation').'</th><th>'.$langs->trans('LastActivity').'</th><th>'.$langs->trans('Status').'</th><th class="right">'.$langs->trans('Actions').'</th></tr>';
if (!$devices) {
    print '<tr><td colspan="8" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
}
foreach ($devices as $device) {
    $displayName = trim($device->firstname.' '.$device->lastname);
    if ($displayName === '') $displayName = $device->login;
    print '<tr class="oddeven">';
    print '<td><a href="'.DOL_URL_ROOT.'/user/card.php?id='.((int) $device->fk_user).'">'.dol_escape_htmltag($displayName).'</a><br><span class="opacitymedium">'.dol_escape_htmltag($device->login).'</span></td>';
    print '<td>'.dol_escape_htmltag($device->label).'<br><span class="opacitymedium">'.dol_escape_htmltag($device->public_id).'</span></td>';
    print '<td>'.dol_escape_htmltag((string) $device->platform).'</td>';
    print '<td>'.dol_escape_htmltag((string) $device->pwa_version).'</td>';
    print '<td>'.dol_print_date($db->jdate($device->datec), 'dayhour').'</td>';
    print '<td>'.($device->last_seen_at ? dol_print_date($db->jdate($device->last_seen_at), 'dayhour') : '-').'</td>';
    print '<td>'.dol_escape_htmltag($device->status).'</td>';
    print '<td class="right"><form method="post" style="display:inline-block">';
    print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'"><input type="hidden" name="device_id" value="'.((int) $device->rowid).'">';
    if ($device->status !== 'REVOKED') {
        print '<input type="hidden" name="action" value="revoke"><button class="button small" type="submit">'.$langs->trans('Revoke').'</button>';
    } else {
        print '<input type="hidden" name="action" value="delete"><button class="button small" type="submit">'.$langs->trans('Delete').'</button>';
    }
    print '</form></td></tr>';
}
print '</table></div>';
llxFooter();
$db->close();
