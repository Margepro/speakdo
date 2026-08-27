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

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modSpeakdo extends DolibarrModules
{
    public function __construct($db)
    {
        global $conf;

        $this->db = $db;
        $this->numero = 117500;
        $this->rights_class = 'speakdo';
        $this->family = 'interface';
        $this->module_position = '95';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'SpeakDo device enrollment and per-user Dolibarr API access';
        // Author
		$this->editor_name = 'MargePro';
		$this->editor_url = 'https://speakdo.fr';
        
        $this->version = '1.0.4';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'mobile-alt';
        $this->module_parts = array('api' => 1);
        $this->dirs = array('/speakdo/temp');
        $this->config_page_url = array('setup.php@speakdo');
        $this->depends = array('modApi');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(18, 0);
        $this->langfiles = array('speakdo@speakdo');

        $this->const = array(
            1 => array('SPEAKDO_APP_ENROLL_URL', 'chaine', 'https://app.speakdo.fr/enroll', 'Base URL used in enrollment QR codes', 1, 'current', 1),
            2 => array('SPEAKDO_QR_TTL', 'chaine', '600', 'Enrollment QR lifetime in seconds', 0, 'current', 1),
            3 => array('SPEAKDO_PROTOCOL_VERSION', 'chaine', '1', 'SpeakDo protocol version', 0, 'current', 1),
            4 => array('SPEAKDO_MIDDLEWARE_API_URL', 'chaine', 'https://api.speakdo.fr', 'SpeakDo middleware API base URL', 0, 'current', 1),
            5 => array('SPEAKDO_SLUG', 'chaine', '', 'SpeakDo tenant slug (auto-generated from company name if blank)', 0, 'current', 1),
            6 => array('SPEAKDO_DISPLAY_NAME', 'chaine', '', 'SpeakDo tenant display name (auto-generated from company name if blank)', 0, 'current', 1),
            7 => array('SPEAKDO_TENANT_BOOTSTRAP_MODE', 'chaine', 'auto', 'Tenant bootstrap protocol: auto (v2, fallback to legacy only if v2 is unsupported), v2 (never fallback) or legacy (old admin-token flow only)', 0, 'current', 1),
            8 => array('SPEAKDO_DEFAULT_PROFILE', 'chaine', '', 'Tenant default SpeakDo profile identifier for users with no explicit assignment (blank = middleware default)', 0, 'current', 1),
        );

        $this->tabs = array(
            'user:+speakdo:SpeakDo:speakdo@speakdo:($user->admin || $user->hasRight("speakdo", "admin", "manage") || $user->hasRight("speakdo", "user", "manage")):/speakdo/user.php?id=__ID__'
        );

        $this->dictionaries = array();
        $this->boxes = array();

        $this->rights = array();
        $this->rights_admin_allowed = 1;
        $r = 0;
        $this->rights[$r][0] = 10422101;
        $this->rights[$r][1] = 'Manage own SpeakDo devices';
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = 'user';
        $this->rights[$r][5] = 'manage';
        $r++;
        $this->rights[$r][0] = 10422102;
        $this->rights[$r][1] = 'Administer all SpeakDo devices';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'admin';
        $this->rights[$r][5] = 'manage';

        $this->menu = array();
        $r = 0;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=tools',
            'type' => 'left',
            'titre' => 'SpeakDoDevices',
            'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth"'),
            'mainmenu' => 'tools',
            'leftmenu' => 'speakdo_devices',
            'url' => '/speakdo/admin/devices.php',
            'langs' => 'speakdo@speakdo',
            'position' => 200,
            'enabled' => 'isModEnabled("speakdo")',
            'perms' => '$user->admin || $user->hasRight("speakdo", "admin", "manage")',
            'target' => '',
            'user' => 0,
        );
    }

    public function init($options = '')
    {
        global $conf;

        $result = $this->_load_tables('/speakdo/sql/');
        if ($result < 0) {
            return -1;
        }

        $sql = array();
        $result = $this->_init($sql, $options);
        if ($result <= 0) {
            return $result;
        }

        require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($this->db);
        // Per-user toggle: is this Dolibarr user allowed to enroll/use a SpeakDo MCP client?
        // Disabled by default for existing and new users (default_value '0'). Not a business
        // right: it only gates the MCP channel of the existing SpeakDo enrollment/device
        // mechanism, rendered on our own SpeakDo user tab, so it stays hidden from the
        // generic extrafields forms (list = '-1').
        $extrafields->addExtraField(
            'speakdo_mcp_enabled',
            'SpeakDoMcpEnabledExtrafield',
            'boolean',
            100,
            '1',
            'user',
            0,
            0,
            '0',
            '',
            0,
            '',
            '-1',
            '',
            '',
            '',
            'speakdo@speakdo'
        );
        // Per-user SpeakDo business profile identifier (e.g. 'project_manager'). A preference,
        // never a right — see speakdo_set_user_profile(). Free varchar, not a 'select', because
        // this module has no catalog of real profile ids to build a select from yet (no
        // GET /api/v1/profiles-equivalent middleware contract exists here); an empty value means
        // "inherit the tenant default" (SPEAKDO_DEFAULT_PROFILE), itself falling back to the
        // middleware's own 'generic'. Hidden from generic extrafields forms (list = '-1'): the
        // SpeakDo user tab and admin/personalization.php render it themselves.
        $extrafields->addExtraField(
            'speakdo_profile',
            'SpeakDoProfileExtrafield',
            'varchar',
            101,
            '190',
            'user',
            0,
            0,
            '',
            '',
            0,
            '',
            '-1',
            '',
            '',
            '',
            'speakdo@speakdo'
        );

        require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
        require_once dol_buildpath('/speakdo/lib/speakdo.lib.php', 0);

        // Non-secret, never regenerated once set — required by tenant bootstrap v2
        // (tenant_boostratp.md §1) as soon as SPEAKDO_TENANT_BOOTSTRAP_MODE allows it below.
        speakdo_ensure_installation_uuid($this->db, $conf->entity);

        if (!speakdo_is_tenant_enrolled()) {
            try {
                speakdo_enroll_tenant($this->db, $conf->entity);
            } catch (Throwable $e) {
                dol_syslog('SpeakDo: auto-enrollment failed during init: '.$e->getMessage(), LOG_WARNING);
            }
        }

        return 1;
    }

    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }
}
