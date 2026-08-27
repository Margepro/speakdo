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
 * SpeakDo business profile assignment + tenant semantic policy (vocabulary) administration.
 * Profile assignment is pure Dolibarr-side storage (extrafield), unaffected by middleware
 * availability. The vocabulary/policy sections are a real, live interface onto the middleware's
 * GET/PUT /semantic-policy and GET /semantic-actions (api.md §3 quater) — nothing is cached or
 * stored locally as a substitute source of truth.
 *
 * Note on scope: the real lexicon schema (api.md) admits only {type, locale, expression,
 * canonical, preferred_action?} per entry — no per-entry enabled/disabled flag, and the
 * middleware rejects any unknown key with 422 invalid_semantic_policy. A prior draft of this
 * mission's UI mockup showed a "Désactiver" action; it is intentionally NOT implemented here
 * since the real contract has no field to represent it — only Ajouter/Modifier/Supprimer exist.
 *
 * Three sub-tabs (profile / users / vocabulary) keep each section's content — and the width of
 * its own tables/selects — isolated from the others, instead of stacking everything on one page.
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once dol_buildpath('/speakdo/lib/speakdo.lib.php', 0);

$langs->loadLangs(array('users', 'speakdo@speakdo'));
if (empty($user->admin) && !$user->hasRight('speakdo', 'admin', 'manage')) {
    accessforbidden();
}

$subtab = GETPOST('subtab', 'aZ09');
if (!in_array($subtab, array('profile', 'users', 'vocabulary'), true)) {
    $subtab = 'profile';
}

$action = GETPOST('action', 'aZ09');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = GETPOST('token', 'alphanohtml');
    if (empty($_SESSION['newtoken']) || !hash_equals((string) $_SESSION['newtoken'], (string) $postedToken)) {
        accessforbidden('Invalid CSRF token');
    }
    try {
        if ($action === 'set_default_profile' || $action === 'set_user_profile') {
            $value = trim(GETPOST($action === 'set_default_profile' ? 'default_profile' : 'speakdo_profile', 'alphanohtml'));
            if ($value !== '' && !preg_match(SPEAKDO_PROFILE_ID_REGEX, $value)) {
                throw new RuntimeException($langs->trans('SpeakDoProfileInvalidFormat'));
            }
            // Mission §8: once the catalog is reachable, no longer accept an arbitrary id blindly
            // — check membership. If the catalog itself is unreachable, fall back to format-only
            // validation (do not block profile assignment on a middleware outage).
            if ($value !== '') {
                try {
                    $knownIds = array_column(speakdo_profiles_get(), 'id');
                    if (!in_array($value, $knownIds, true)) {
                        throw new RuntimeException($langs->trans('SpeakDoProfileUnknown'));
                    }
                } catch (SpeakDoMiddlewareApiException $ignored) {
                    // Catalog unreachable: keep the format-only validation already done above.
                }
            }
            if ($action === 'set_default_profile') {
                dolibarr_set_const($db, 'SPEAKDO_DEFAULT_PROFILE', $value, 'chaine', 0, '', $conf->entity);
            } else {
                $targetUser = new User($db);
                if ($targetUser->fetch(GETPOSTINT('user_id')) <= 0) {
                    throw new RuntimeException('User not found');
                }
                speakdo_set_user_profile($db, $targetUser, $value);
            }
            setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
        } elseif ($action === 'lexicon_upsert' || $action === 'lexicon_delete') {
            $expression = trim(GETPOST('lex_expression', 'restricthtml'));
            $type = GETPOST('lex_type', 'aZ09') === 'intent_alias' ? 'intent_alias' : 'lexical_alias';
            $expectedRevision = GETPOSTINT('expected_revision');
            if ($expression === '') {
                throw new RuntimeException($langs->trans('SpeakDoVocabFieldsRequired'));
            }

            // Rebuild from a fresh read: the PUT's own expected_revision check (using the value
            // captured when this page was last rendered, not this fresh one) is what actually
            // protects against a concurrent change — see speakdo_semantic_policy_put() docblock.
            $current = speakdo_semantic_policy_get();
            $policy = is_array($current['policy'] ?? null) ? $current['policy'] : array('schema_version' => 1, 'actions' => array('exclude' => array(), 'priority' => array()), 'lexicon' => array(), 'ui' => array('shortcuts' => array()));
            $lexicon = is_array($policy['lexicon'] ?? null) ? array_values($policy['lexicon']) : array();

            if ($action === 'lexicon_delete') {
                $lexicon = speakdo_semantic_lexicon_remove($lexicon, $type, $expression);
                $reason = 'Suppression alias via module Dolibarr';
            } else {
                $knownActionIds = array_column(speakdo_semantic_actions_get(), 'id');
                $locale = substr((string) $langs->defaultlang, 0, 2) ?: 'fr';
                $lexAction = trim(GETPOST('lex_action', 'alphanohtml'));
                // Server-side mirror of the form's onchange sync (works even without JS): an
                // action was chosen, so treat this as an intent_alias regardless of what Type was
                // left at — otherwise a lexical_alias silently drops the action with no error,
                // which is confusing (the action looks "selected" but is never actually stored,
                // since the contract admits no preferred_action field for that type).
                if ($lexAction !== '') {
                    $type = 'intent_alias';
                }
                $newEntry = speakdo_semantic_lexicon_validate_entry(
                    $type,
                    $expression,
                    GETPOST('lex_canonical', 'restricthtml'),
                    $lexAction,
                    $knownActionIds,
                    $locale,
                    $langs
                );
                $upsertResult = speakdo_semantic_lexicon_upsert($lexicon, $newEntry);
                $lexicon = $upsertResult['lexicon'];
                $reason = $upsertResult['updated'] ? 'Modification alias via module Dolibarr' : 'Ajout alias via module Dolibarr';
            }

            $policy['lexicon'] = $lexicon;
            try {
                $putResult = speakdo_semantic_policy_put($expectedRevision, $policy, $reason);
                setEventMessages($langs->trans('SpeakDoVocabSaved', (string) ($putResult['revision'] ?? '?')), null, 'mesgs');
            } catch (SpeakDoMiddlewareApiException $e) {
                if ($e->errorCode === 'semantic_policy_revision_conflict') {
                    setEventMessages($langs->trans('SpeakDoSemanticErrRevisionConflict'), null, 'errors');
                } else {
                    setEventMessages(speakdo_semantic_error_message($langs, $e), null, 'errors');
                }
            }
        }
    } catch (Throwable $e) {
        setEventMessages($e->getMessage(), null, 'errors');
    }
}

$csrfToken = newToken();

llxHeader('', $langs->trans('SpeakDoPersonalization'));
print load_fiche_titre($langs->trans('SpeakDoPersonalization'), '', 'mobile-alt');

$head = SpeakDoAdminPrepareHead();
print dol_get_fiche_head($head, 'personalization', $langs->trans('SpeakDoSetup'), -1, 'test@test');

print '<p class="opacitymedium">'.$langs->trans('SpeakDoPersonalizationIntro').'</p>';

$subhead = array(
    array(dol_buildpath('/speakdo/admin/personalization.php', 1).'?subtab=profile', $langs->trans('SpeakDoProfilesSection'), 'profile'),
    array(dol_buildpath('/speakdo/admin/personalization.php', 1).'?subtab=users', $langs->trans('SpeakDoUserProfilesSection'), 'users'),
    array(dol_buildpath('/speakdo/admin/personalization.php', 1).'?subtab=vocabulary', $langs->trans('SpeakDoVocabularySection'), 'vocabulary'),
);
print dol_get_fiche_head($subhead, $subtab, '', -1, '');

/*
 * Sub-tab 1: Profils — live catalog from GET /profiles + tenant default.
 */
if ($subtab === 'profile') {
    $profiles = null;
    try {
        $profiles = speakdo_profiles_get();
    } catch (Throwable $e) {
        $profiles = null;
    }

    if ($profiles === null) {
        print '<div class="warning">'.$langs->trans('SpeakDoProfileCatalogUnavailableHint').'</div>';
    } elseif (!$profiles) {
        print '<p class="opacitymedium">'.$langs->trans('None').'</p>';
    } else {
        print '<div class="div-table-responsive"><table class="liste centpercent">';
        print '<tr class="liste_titre"><th>'.$langs->trans('SpeakDoProfileLabel').'</th><th>'.$langs->trans('SpeakDoProfileId').'</th><th>'.$langs->trans('SpeakDoProfileRequires').'</th></tr>';
        foreach ($profiles as $p) {
            $requiredAllOf = is_array($p['requires']['required']['all_of'] ?? null) ? $p['requires']['required']['all_of'] : array();
            $requiredAnyOf = is_array($p['requires']['required']['any_of'] ?? null) ? $p['requires']['required']['any_of'] : array();
            $reqParts = array();
            if ($requiredAllOf) $reqParts[] = implode(' + ', array_map('dol_escape_htmltag', $requiredAllOf));
            if ($requiredAnyOf) $reqParts[] = $langs->trans('SpeakDoProfileAnyOf').' '.implode('/', array_map('dol_escape_htmltag', $requiredAnyOf));
            print '<tr class="oddeven">';
            print '<td>'.dol_escape_htmltag((string) ($p['label'] ?? '')).'</td>';
            print '<td><code>'.dol_escape_htmltag((string) ($p['id'] ?? '')).'</code></td>';
            print '<td class="opacitymedium">'.($reqParts ? implode(' — ', $reqParts) : $langs->trans('SpeakDoProfileNoRequirement')).'</td>';
            print '</tr>';
        }
        print '</table></div>';
    }

    $currentDefaultProfile = getDolGlobalString('SPEAKDO_DEFAULT_PROFILE');
    print '<form method="post" style="margin-top:1em">';
    print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
    print '<input type="hidden" name="action" value="set_default_profile">';
    print '<input type="hidden" name="subtab" value="profile">';
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('SpeakDoDefaultProfile').'</td><td>';
    if ($profiles !== null) {
        print '<select name="default_profile" class="minwidth300 maxwidth100onsmartphone">';
        print speakdo_profile_select_options($profiles, $currentDefaultProfile, $langs->trans('SpeakDoProfileMiddlewareDefault'), $langs);
        print '</select> ';
    } else {
        print '<input type="text" name="default_profile" class="minwidth200" maxlength="190" placeholder="generic" value="'.dol_escape_htmltag($currentDefaultProfile).'"> ';
    }
    print '<button class="button small" type="submit">'.$langs->trans('Save').'</button>';
    print '</td></tr>';
    print '</table>';
    print '</form>';
    print '<p class="opacitymedium">'.$langs->trans('SpeakDoDefaultProfileHint').'</p>';
}

/*
 * Sub-tab 2: Utilisateurs -> Profil. Only users who have activated at least one SpeakDo client
 * (a local PWA device row, any status, or a live MCP access) — not every active Dolibarr user.
 */
if ($subtab === 'users') {
    $profiles = null;
    try {
        $profiles = speakdo_profiles_get();
    } catch (Throwable $e) {
        $profiles = null;
    }

    $pwaUserIds = array();
    $sqlPwa = "SELECT DISTINCT fk_user FROM ".MAIN_DB_PREFIX."speakdo_device WHERE entity = ".((int) $conf->entity);
    $resqlPwa = $db->query($sqlPwa);
    if ($resqlPwa) {
        while ($r = $db->fetch_object($resqlPwa)) {
            $pwaUserIds[] = (int) $r->fk_user;
        }
    }

    $mcpUserIds = array();
    $mcpUserListWarning = null;
    try {
        foreach (speakdo_mcp_list_accesses(null) as $acc) {
            if (!empty($acc['erp_user_id'])) $mcpUserIds[] = (int) $acc['erp_user_id'];
        }
    } catch (SpeakDoMiddlewareApiException $e) {
        $mcpUserListWarning = speakdo_mcp_error_message($langs, $e);
    } catch (Throwable $e) {
        $mcpUserListWarning = $langs->trans('SpeakDoMcpErrGeneric');
    }

    $deviceUserIds = array_values(array_unique(array_merge($pwaUserIds, $mcpUserIds)));

    if ($mcpUserListWarning !== null) {
        print '<div class="warning">'.dol_escape_htmltag($langs->trans('SpeakDoUserListMcpWarning')).' '.dol_escape_htmltag($mcpUserListWarning).'</div>';
    }

    $sqlUsers = "SELECT u.rowid, u.login, u.firstname, u.lastname, uef.speakdo_profile";
    $sqlUsers .= " FROM ".MAIN_DB_PREFIX."user u";
    $sqlUsers .= " LEFT JOIN ".MAIN_DB_PREFIX."user_extrafields uef ON uef.fk_object = u.rowid";
    $sqlUsers .= " WHERE u.statut = 1";
    $sqlUsers .= $deviceUserIds ? " AND u.rowid IN (".implode(',', array_map('intval', $deviceUserIds)).")" : " AND 1 = 0";
    $sqlUsers .= " ORDER BY u.lastname, u.firstname";
    $resqlUsers = $db->query($sqlUsers);

    print '<div class="div-table-responsive"><table class="liste centpercent">';
    print '<tr class="liste_titre"><th>'.$langs->trans('User').'</th><th colspan="2">'.$langs->trans('SpeakDoProfile').'</th></tr>';
    if ($resqlUsers && $db->num_rows($resqlUsers) > 0) {
        while ($rowUser = $db->fetch_object($resqlUsers)) {
            $displayName = trim($rowUser->firstname.' '.$rowUser->lastname);
            if ($displayName === '') $displayName = $rowUser->login;
            $rowProfile = (string) $rowUser->speakdo_profile;
            print '<tr class="oddeven">';
            print '<td><a href="'.DOL_URL_ROOT.'/user/card.php?id='.((int) $rowUser->rowid).'">'.dol_escape_htmltag($displayName).'</a><br><span class="opacitymedium">'.dol_escape_htmltag($rowUser->login).'</span></td>';
            print '<td colspan="2"><form method="post" style="display:inline">';
            print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
            print '<input type="hidden" name="action" value="set_user_profile">';
            print '<input type="hidden" name="subtab" value="users">';
            print '<input type="hidden" name="user_id" value="'.((int) $rowUser->rowid).'">';
            if ($profiles !== null) {
                print '<select name="speakdo_profile" class="minwidth300 maxwidth100onsmartphone">';
                print speakdo_profile_select_options($profiles, $rowProfile, $langs->trans('SpeakDoProfileTenantDefault'), $langs);
                print '</select> ';
            } else {
                print '<input type="text" name="speakdo_profile" class="minwidth200" maxlength="190" placeholder="'.dol_escape_htmltag($langs->trans('SpeakDoProfileDefaultPlaceholder')).'" value="'.dol_escape_htmltag($rowProfile).'"> ';
            }
            print '<button class="button small" type="submit">'.$langs->trans('Save').'</button>';
            print '</form></td>';
            print '</tr>';
        }
    } else {
        print '<tr><td colspan="2" class="opacitymedium">'.$langs->trans('SpeakDoNoActivatedUsers').'</td></tr>';
    }
    print '</table></div>';
}

/*
 * Sub-tab 3: Vocabulaire — live interface onto GET/PUT /semantic-policy.
 */
if ($subtab === 'vocabulary') {
    $semanticPolicy = null;
    $semanticPolicyError = null;
    try {
        $semanticPolicy = speakdo_semantic_policy_get();
    } catch (SpeakDoMiddlewareApiException $e) {
        $semanticPolicyError = speakdo_semantic_error_message($langs, $e);
    } catch (Throwable $e) {
        $semanticPolicyError = $langs->trans('SpeakDoMcpErrGeneric');
    }

    if ($semanticPolicyError !== null) {
        print '<div class="warning">'.dol_escape_htmltag($semanticPolicyError).'</div>';
    } else {
        $semanticActions = array();
        try {
            $semanticActions = speakdo_semantic_actions_get();
        } catch (Throwable $e) {
            $semanticActions = array();
        }

        $revision = (int) ($semanticPolicy['revision'] ?? 0);
        $policy = is_array($semanticPolicy['policy'] ?? null) ? $semanticPolicy['policy'] : array();
        $lexicon = is_array($policy['lexicon'] ?? null) ? $policy['lexicon'] : array();

        print '<p><strong>'.$langs->trans('SpeakDoActiveRevision').'</strong> '.$revision.'</p>';

        print '<div class="div-table-responsive"><table class="liste centpercent">';
        print '<tr class="liste_titre"><th>'.$langs->trans('SpeakDoVocabExpression').'</th><th>'.$langs->trans('SpeakDoVocabCanonical').'</th><th>'.$langs->trans('SpeakDoVocabType').'</th><th>'.$langs->trans('SpeakDoVocabAction').'</th><th class="right">'.$langs->trans('Actions').'</th></tr>';
        if (!$lexicon) {
            print '<tr><td colspan="5" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
        }
        foreach ($lexicon as $entry) {
            $entryType = (string) ($entry['type'] ?? '');
            $entryExpr = (string) ($entry['expression'] ?? '');
            $entryCanonical = (string) ($entry['canonical'] ?? '');
            $entryAction = (string) ($entry['preferred_action'] ?? '');
            print '<tr class="oddeven">';
            print '<td>'.dol_escape_htmltag($entryExpr).'</td>';
            print '<td>'.dol_escape_htmltag($entryCanonical).'</td>';
            print '<td>'.($entryType === 'intent_alias' ? $langs->trans('SpeakDoVocabTypeIntent') : $langs->trans('SpeakDoVocabTypeLexical')).'</td>';
            print '<td>'.($entryAction !== '' ? '<code>'.dol_escape_htmltag($entryAction).'</code>' : '—').'</td>';
            print '<td class="right">';
            print '<form method="post" style="display:inline-block" onsubmit="return confirm(\''.dol_escape_js($langs->trans('SpeakDoVocabConfirmDelete')).'\');">';
            print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
            print '<input type="hidden" name="action" value="lexicon_delete">';
            print '<input type="hidden" name="subtab" value="vocabulary">';
            print '<input type="hidden" name="expected_revision" value="'.$revision.'">';
            print '<input type="hidden" name="lex_type" value="'.dol_escape_htmltag($entryType).'">';
            print '<input type="hidden" name="lex_expression" value="'.dol_escape_htmltag($entryExpr).'">';
            print '<button class="button small" type="submit">'.$langs->trans('Delete').'</button></form>';
            print '</td></tr>';
        }
        print '</table></div>';

        print '<div class="info" style="padding:1em;margin-top:1em;max-width:100%;box-sizing:border-box;overflow:hidden">';
        print '<h4>'.$langs->trans('SpeakDoVocabAddOrEdit').'</h4>';
        print '<p class="opacitymedium">'.$langs->trans('SpeakDoVocabAddOrEditHint').'</p>';
        print '<form method="post">';
        print '<input type="hidden" name="token" value="'.dol_escape_htmltag($csrfToken).'">';
        print '<input type="hidden" name="action" value="lexicon_upsert">';
        print '<input type="hidden" name="subtab" value="vocabulary">';
        print '<input type="hidden" name="expected_revision" value="'.$revision.'">';
        // div-table-responsive + table-layout:fixed: the action <select> below can contain long
        // option text (action descriptions); without a fixed layout, a plain "auto" table lets a
        // wide option force the whole table — and with it the page — into horizontal scroll. Fixed
        // layout keeps columns to their declared width regardless of option content; the
        // responsive wrapper is a second line of defense (scrolls only this box, never the page).
        print '<div class="div-table-responsive">';
        print '<table class="border centpercent" style="table-layout:fixed">';
        print '<tr><td class="titlefield">'.$langs->trans('SpeakDoVocabExpression').'</td><td><input class="minwidth200 maxwidth100onsmartphone" style="max-width:100%" name="lex_expression" maxlength="190" required></td></tr>';
        print '<tr><td>'.$langs->trans('SpeakDoVocabCanonical').'</td><td><input class="minwidth300 maxwidth100onsmartphone" style="max-width:100%" name="lex_canonical" maxlength="190" required></td></tr>';
        // preferred_action is only ever stored server-side when lex_type=intent_alias (contract:
        // a lexical_alias entry admits no such field) — these two onchange handlers keep the two
        // selects in sync so picking an action doesn't get silently dropped just because Type was
        // left on its default "Alias lexical", and switching back to "Alias lexical" clears a
        // stale action choice instead of leaving it selected but unused.
        print '<tr><td>'.$langs->trans('SpeakDoVocabType').'</td><td><select name="lex_type" id="speakdo_lex_type" style="max-width:100%" onchange="if(this.value!==\'intent_alias\'){document.getElementById(\'speakdo_lex_action\').value=\'\';}">';
        print '<option value="lexical_alias">'.$langs->trans('SpeakDoVocabTypeLexical').'</option>';
        print '<option value="intent_alias">'.$langs->trans('SpeakDoVocabTypeIntent').'</option>';
        print '</select></td></tr>';
        print '<tr><td>'.$langs->trans('SpeakDoVocabAction').'</td><td><select name="lex_action" id="speakdo_lex_action" style="max-width:100%;width:100%" onchange="if(this.value!==\'\'){document.getElementById(\'speakdo_lex_type\').value=\'intent_alias\';}"><option value="">—</option>';
        foreach ($semanticActions as $a) {
            if (empty($a['id'])) continue;
            $desc = (string) ($a['description'] ?? '');
            $shortDesc = dol_trunc($desc, 40, 'right', 'UTF-8', 1);
            print '<option value="'.dol_escape_htmltag($a['id']).'" title="'.dol_escape_htmltag($desc).'">'.dol_escape_htmltag($a['id']).' — '.dol_escape_htmltag($shortDesc).'</option>';
        }
        print '</select><br><span class="opacitymedium">'.$langs->trans('SpeakDoVocabActionHint').'</span></td></tr>';
        print '</table>';
        print '</div>';
        print '<div class="center" style="margin-top:0.5em"><button type="submit" class="butAction">'.$langs->trans('SpeakDoVocabSaveButton').'</button></div>';
        print '</form>';
        print '</div>';
    }
}

llxFooter();
$db->close();
