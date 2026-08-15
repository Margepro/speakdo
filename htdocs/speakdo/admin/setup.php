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
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once dol_buildpath('/speakdo/lib/speakdo.lib.php', 0);

$langs->loadLangs(array('admin', 'speakdo@speakdo'));
if (empty($user->admin)) {
    accessforbidden();
}
dol_include_once('/speakdo/core/modules/modSpeakdo.class.php');
$tmpmodule = new modSpeakDo($db);


$action = GETPOST('action', 'aZ09');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = GETPOST('token', 'alphanohtml');
    if (empty($_SESSION['newtoken']) || !hash_equals((string) $_SESSION['newtoken'], (string) $postedToken)) {
        accessforbidden('Invalid CSRF token');
    }
    if ($action === 'save') {
        $url = trim(GETPOST('enroll_url', 'restricthtml'));
        if (!preg_match('#^https://#i', $url) && !getDolGlobalInt('SPEAKDO_ALLOW_HTTP_DEV')) {
            setEventMessages($langs->trans('SpeakDoHttpsRequired'), null, 'errors');
        } else {
            dolibarr_set_const($db, 'SPEAKDO_APP_ENROLL_URL', rtrim($url, '/'), 'chaine', 0, '', $conf->entity);
            dolibarr_set_const($db, 'SPEAKDO_QR_TTL', (string) max(60, min(3600, GETPOSTINT('qr_ttl'))), 'chaine', 0, '', $conf->entity);
            $slugInput = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower(GETPOST('speakdo_slug', 'nohtml'))), '-');
            if ($slugInput !== '') {
                dolibarr_set_const($db, 'SPEAKDO_SLUG', $slugInput, 'chaine', 0, '', $conf->entity);
            }
            $displayNameInput = trim(GETPOST('speakdo_display_name', 'nohtml'));
            if ($displayNameInput !== '') {
                dolibarr_set_const($db, 'SPEAKDO_DISPLAY_NAME', $displayNameInput, 'chaine', 0, '', $conf->entity);
            }
            setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
        }
    } elseif ($action === 'enroll') {
        try {
            speakdo_enroll_tenant($db, $conf->entity);
            setEventMessages($langs->trans('SpeakDoEnrolled'), null, 'mesgs');
        } catch (Throwable $e) {
            setEventMessages($e->getMessage(), null, 'errors');
        }
    } elseif ($action === 'reenroll') {
        try {
            dolibarr_del_const($db, 'SPEAKDO_TENANT_UUID', $conf->entity);
            dolibarr_del_const($db, 'SPEAKDO_HMAC_SECRET', $conf->entity);
            speakdo_enroll_tenant($db, $conf->entity);
            setEventMessages($langs->trans('SpeakDoEnrolled'), null, 'mesgs');
        } catch (Throwable $e) {
            setEventMessages($e->getMessage(), null, 'errors');
        }
    } elseif ($action === 'rotate') {
        dolibarr_set_const($db, 'SPEAKDO_HMAC_SECRET', dolEncrypt(bin2hex(random_bytes(32))), 'chaine', 0, '', $conf->entity);
        setEventMessages($langs->trans('SpeakDoSecretRotated'), null, 'warnings');
    }
}

// Auto-enroll when admin token is set but tenant is not yet enrolled
if (!in_array($action, array('enroll', 'reenroll')) && !speakdo_is_tenant_enrolled()) {
    try {
        speakdo_enroll_tenant($db, $conf->entity);
        setEventMessages($langs->trans('SpeakDoEnrolled'), null, 'mesgs');
    } catch (Throwable $e) {
        setEventMessages($langs->trans('SpeakDoEnrollmentFailed').': '.$e->getMessage(), null, 'errors');
    }
}

$csrfToken = newToken();
// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : dol_buildpath('/speakdo/admin/setup.php',2).'?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';



llxHeader('', $langs->trans('SpeakDoSetup'), $linkback);
print load_fiche_titre($langs->trans('SpeakDoSetup'), '', 'mobile-alt');


// Configuration header
$head = SpeakDoAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('SpeakDoSetup'), -1, "test@test");

print '<div class="fichecenter"><div class="fichehalfleft">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('SpeakDoModuleVersion').'</td><td>'.$tmpmodule->version.'</td></tr>';
print '<tr><td>'.$langs->trans('SpeakDoProtocolVersion').'</td><td>'.dol_escape_htmltag(getDolGlobalString('SPEAKDO_PROTOCOL_VERSION', '1')).'</td></tr>';
print '<tr><td>'.$langs->trans('SpeakDoRestApi').'</td><td>'.(speakdo_api_is_enabled() ? img_picto('', 'status4').' '.$langs->trans('Enabled') : img_picto('', 'status8').' '.$langs->trans('SpeakDoApiDisabled')).'</td></tr>';
print '<tr><td>'.$langs->trans('SpeakDoHealthEndpoint').'</td><td><code>'.dol_escape_htmltag(DOL_URL_ROOT.'/api/index.php/speakdo/health').'</code></td></tr>';
print '</table></div><div class="fichehalfright">';
print '<table class="border centpercent">';
$isEnrolled = speakdo_is_tenant_enrolled();
print '<tr><td class="titlefield">'.$langs->trans('SpeakDoTenantEnrollment').'</td><td>'.($isEnrolled ? img_picto('', 'status4').' '.$langs->trans('SpeakDoEnrollmentOk') : img_picto('', 'status8').' '.$langs->trans('SpeakDoNotEnrolled')).'</td></tr>';
print '<tr><td>'.$langs->trans('SpeakDoMiddlewareApiUrl').'</td><td><code>'.dol_escape_htmltag(SPEAKDO_MIDDLEWARE_BASE_URL).'</code></td></tr>';
print '<tr><td>Tenant UUID</td><td><code>'.dol_escape_htmltag(getDolGlobalString('SPEAKDO_TENANT_UUID', '-')).'</code></td></tr>';
print '<tr><td>'.$langs->trans('SpeakDoMiddlewareSecret').'</td><td><input type="password" readonly value="'.dol_escape_htmltag(speakdo_get_middleware_secret()).'" style="width:100%"> <a href="#" onclick="this.previousElementSibling.type=(this.previousElementSibling.type===\'password\'?\'text\':\'password\');return false;">'.$langs->trans('Show').'</a></td></tr>';
print '</table></div></div><div class="clearboth"></div>';

print '<form method="post">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'"><input type="hidden" name="action" value="save">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('SpeakDoEnrollmentBaseUrl').'</td><td><input class="minwidth500" name="enroll_url" value="'.dol_escape_htmltag(getDolGlobalString('SPEAKDO_APP_ENROLL_URL')).'"></td></tr>';
print '<tr><td>'.$langs->trans('SpeakDoQrTtl').'</td><td><input type="number" min="60" max="3600" name="qr_ttl" value="'.getDolGlobalInt('SPEAKDO_QR_TTL', 600).'"> '.$langs->trans('Seconds').'</td></tr>';
print '<tr><td>'.$langs->trans('SpeakDoSlug').'</td><td><input class="minwidth500" name="speakdo_slug" value="'.dol_escape_htmltag(getDolGlobalString('SPEAKDO_SLUG')).'"></td></tr>';
print '<tr><td>'.$langs->trans('SpeakDoDisplayName').'</td><td><input class="minwidth500" name="speakdo_display_name" value="'.dol_escape_htmltag(getDolGlobalString('SPEAKDO_DISPLAY_NAME')).'"></td></tr>';
print '</table><div class="center"><button class="button button-save" type="submit">'.$langs->trans('Save').'</button></div></form>';

print '<div class="tabsAction">';
// print '<a class="butAction" href="'.dol_buildpath('/speakdo/admin/devices.php', 1).'">'.$langs->trans('SpeakDoOpenDeviceAdmin').'</a> ';
// print '<a class="butAction" href="'.dol_buildpath('/speakdo/admin/billing.php', 1).'">'.$langs->trans('SpeakDoBilling').'</a> ';
print '<a class="butAction" target="_blank" rel="noopener" href="'.DOL_URL_ROOT.'/api/index.php/explorer">'.$langs->trans('ApiExplorer').'</a> ';
print '<form method="post" style="display:inline-block" onsubmit="return confirm(\''.dol_escape_js($langs->trans('SpeakDoConfirmEnroll')).'\');"><input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'"><input type="hidden" name="action" value="enroll"><button type="submit" class="butAction">'.$langs->trans('SpeakDoEnrollNow').'</button></form> ';
// print '<form method="post" style="display:inline-block" onsubmit="return confirm(\''.dol_escape_js($langs->trans('SpeakDoConfirmReEnroll')).'\');"><input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'"><input type="hidden" name="action" value="reenroll"><button type="submit" class="butActionDelete">'.$langs->trans('SpeakDoReEnrollNow').'</button></form> ';
// print '<form method="post" style="display:inline-block" onsubmit="return confirm(\''.dol_escape_js($langs->trans('SpeakDoConfirmSecretRotation')).'\');"><input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'"><input type="hidden" name="action" value="rotate"><button type="submit" class="butActionDelete">'.$langs->trans('SpeakDoRotateSecret').'</button></form>';
print '</div>';

llxFooter();
$db->close();
