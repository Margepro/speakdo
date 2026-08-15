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
    if ($action === 'billing_link') {
        try {
            $url = speakdo_billing_get_link($user->id);
            header('Location: '.$url);
            exit;
        } catch (Throwable $e) {
            setEventMessages($e->getMessage(), null, 'errors');
        }
    }
}

$isEnrolled = speakdo_is_tenant_enrolled();
$status = null;
if ($isEnrolled) {
    try {
        $status = speakdo_billing_get_status();
    } catch (Throwable $e) {
        setEventMessages($e->getMessage(), null, 'warnings');
    }
}

// plan_code / tenant_state are internal middleware codes — map the known ones to a
// readable label and fall back to the raw code for anything not (yet) mapped here.
$planLabels = array(
    'starter' => $langs->trans('SpeakDoPlanTrial'),
    'trial'   => $langs->trans('SpeakDoPlanTrial'),
    'solo'    => $langs->trans('SpeakDoPlanSolo'),
    'equipe'  => $langs->trans('SpeakDoPlanEquipe'),
    'team'    => $langs->trans('SpeakDoPlanEquipe'),
    'terrain' => $langs->trans('SpeakDoPlanTerrain'),
);
$stateLabels = array(
    'trialing'       => array($langs->trans('SpeakDoStateTrialing'), 'status1'),
    'active'         => array($langs->trans('SpeakDoStateActive'), 'status4'),
    'payment_grace'  => array($langs->trans('SpeakDoStatePaymentGrace'), 'status8'),
    'past_due'       => array($langs->trans('SpeakDoStatePaymentGrace'), 'status8'),
    'canceled'       => array($langs->trans('SpeakDoStateCanceled'), 'status6'),
);

$csrfToken = newToken();

llxHeader('', $langs->trans('SpeakDoBilling'));
print load_fiche_titre($langs->trans('SpeakDoBilling'), '', 'mobile-alt');

// Configuration header
$head = SpeakDoAdminPrepareHead();
print dol_get_fiche_head($head, 'billing', $langs->trans('SpeakDoSetup'), -1, "test@test");

//print '<div class="tabsAction"><a class="butAction" href="'.dol_buildpath('/speakdo/admin/setup.php', 1).'">'.$langs->trans('Setup').'</a></div>';



if (!$isEnrolled) {
    print '<div class="warning">'.$langs->trans('SpeakDoBillingNotEnrolled').'</div>';
} elseif (!$status) {
    print '<div class="warning">'.$langs->trans('SpeakDoBillingStatusUnavailable').'</div>';
} else {
    $quota = is_array($status['period_quota'] ?? null) ? $status['period_quota'] : array();
    $used = (int) ($quota['used'] ?? 0);
    $included = (int) ($quota['included'] ?? 0);

    $planCode = (string) ($status['plan_code'] ?? '');
    $planLabel = $planLabels[$planCode] ?? ($planCode !== '' ? $planCode : '-');

    $stateCode = (string) ($status['tenant_state'] ?? '');
    $stateInfo = $stateLabels[$stateCode] ?? array($stateCode !== '' ? $stateCode : '-', 'status0');

    $periodEndTs = !empty($status['period_end']) ? strtotime((string) $status['period_end']) : false;

    print '<div class="fichecenter"><div class="fichehalfleft">';
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('SpeakDoBillingPlan').'</td><td>'.dol_escape_htmltag($planLabel).'</td></tr>';
    print '<tr><td>'.$langs->trans('SpeakDoBillingTenantState').'</td><td>'.img_picto('', $stateInfo[1]).' '.dol_escape_htmltag($stateInfo[0]).'</td></tr>';
    print '<tr><td>'.$langs->trans('SpeakDoBillingPeriodEnd').'</td><td>'.($periodEndTs !== false ? dol_print_date($periodEndTs, 'day') : '-').'</td></tr>';
    print '</table>';
    print '</div><div class="fichehalfright">';
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('SpeakDoBillingQuota').'</td><td>';
    print $used.' / '.$included;
    if ($included > 0) {
        $pct = max(0, min(100, (int) round(($used / $included) * 100)));
        $barColor = $pct >= 100 ? '#c9302c' : ($pct >= 80 ? '#e0a800' : '#5cb85c');
        print '<div style="background:#e5e5e5;border-radius:4px;overflow:hidden;height:6px;margin-top:4px;max-width:220px">';
        print '<div style="width:'.$pct.'%;background:'.$barColor.';height:6px"></div>';
        print '</div>';
    }
    print '</td></tr>';
    print '<tr><td>'.$langs->trans('SpeakDoBillingCreditsBalance').'</td><td>'.((int) ($status['credits_balance'] ?? 0)).'</td></tr>';
    print '</table>';
    print '</div></div><div class="clearboth"></div>';

    print '<div class="tabsAction">';
    print '<form method="post" target="_blank" style="display:inline-block">';
    print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
    print '<input type="hidden" name="action" value="billing_link">';
    print '<button class="butAction" type="submit">'.$langs->trans('SpeakDoBillingRecharge').'</button>';
    print '</form>';
    print '</div>';
}

llxFooter();
$db->close();
