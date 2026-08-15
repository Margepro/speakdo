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

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once dol_buildpath('/speakdo/lib/speakdo.lib.php', 0);

/**
 * SpeakDo enrollment and device API.
 *
 * @access protected
 * @class DolibarrApiAccess {@requires user,external}
 */
class Speakdo extends DolibarrApi
{
    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * Public health check. No secret is returned.
     *
     * @url GET /health
     * @access public
     */
    public function health()
    {
        return array(
            'ok' => true,
            'module' => 'speakdo',
            'module_version' => '0.1.0',
            'protocol_version' => getDolGlobalString('SPEAKDO_PROTOCOL_VERSION', '1'),
            'tenant_id' => getDolGlobalString('SPEAKDO_TENANT_UUID') ?: getDolGlobalString('SPEAKDO_TENANT_ID'),
            'api_enabled' => isModEnabled('api'),
            'server_time' => dol_print_date(dol_now(), 'standard', 'gmt'),
        );
    }

    /**
     * Consume an enrollment token and create a device.
     * The middleware must sign the request with the tenant secret.
     *
     * Canonical HMAC message:
     * tenant + "\\n" + timestamp + "\\n" + nonce + "\\n" + method + "\\n" + path + "\\n" + sha256(raw JSON body)
     *
     * Required headers:
     * X-SpeakDo-Tenant, X-SpeakDo-Timestamp, X-SpeakDo-Nonce, X-SpeakDo-Signature
     *
     * @url POST /enrollments/{token}/claim
     * @access public
     *
     * @param string $token Enrollment token from QR code
     * @param string $label Device label
     * @param string $platform android, ios or browser
     * @param string $pwa_version PWA version
     * @param string $public_key Device public key
     */
    public function claim($token, $label = '', $platform = '', $pwa_version = '', $public_key = '')
    {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) $rawBody = '';
        return $this->doClaimEnrollment($token, $rawBody, $label, $platform, $pwa_version, $public_key);
    }

    /**
     * Get current status of a device using the owner's DOLAPIKEY.
     *
     * @url GET /devices/{deviceId}
     */
    public function device($deviceId)
    {
        global $conf;
        $apiUser = DolibarrApiAccess::$user;
        if (!$apiUser) throw new RestException(401, 'Authentication required');

        $sql = "SELECT public_id, fk_user, label, platform, pwa_version, status, datec, last_seen_at, revoked_at FROM ".MAIN_DB_PREFIX."speakdo_device";
        $sql .= " WHERE entity = ".((int) $conf->entity)." AND public_id = '".$this->db->escape($deviceId)."'";
        $resql = $this->db->query($sql);
        if (!$resql || !($device = $this->db->fetch_object($resql))) throw new RestException(404, 'Device not found');
        if ((int) $device->fk_user !== (int) $apiUser->id && empty($apiUser->admin)) throw new RestException(403, 'Not allowed');

        return array(
            'device_id' => $device->public_id,
            'user_id' => (int) $device->fk_user,
            'label' => $device->label,
            'platform' => $device->platform,
            'pwa_version' => $device->pwa_version,
            'status' => $device->status,
            'created_at' => $device->datec,
            'last_seen_at' => $device->last_seen_at,
            'revoked_at' => $device->revoked_at,
        );
    }

    /**
     * Refresh last activity of a device using the owner's DOLAPIKEY.
     *
     * @url POST /devices/{deviceId}/touch
     */
    public function touch($deviceId, $pwa_version = '')
    {
        global $conf;
        $apiUser = DolibarrApiAccess::$user;
        if (!$apiUser) throw new RestException(401, 'Authentication required');

        $sql = "SELECT fk_user, status FROM ".MAIN_DB_PREFIX."speakdo_device WHERE entity = ".((int) $conf->entity)." AND public_id = '".$this->db->escape($deviceId)."'";
        $resql = $this->db->query($sql);
        if (!$resql || !($device = $this->db->fetch_object($resql))) throw new RestException(404, 'Device not found');
        if ((int) $device->fk_user !== (int) $apiUser->id && empty($apiUser->admin)) throw new RestException(403, 'Not allowed');
        if ($device->status !== 'ACTIVE') throw new RestException(403, 'Device revoked');

        $version = dol_trunc(preg_replace('/[^a-zA-Z0-9._+-]/', '', (string) $pwa_version), 32, 'right', 'UTF-8', 1);
        $sql = "UPDATE ".MAIN_DB_PREFIX."speakdo_device SET last_seen_at = '".$this->db->idate(dol_now())."'";
        if ($version !== '') $sql .= ", pwa_version = '".$this->db->escape($version)."'";
        $sql .= " WHERE entity = ".((int) $conf->entity)." AND public_id = '".$this->db->escape($deviceId)."'";
        if (!$this->db->query($sql)) throw new RestException(500, $this->db->lasterror());
        return array('ok' => true, 'device_id' => $deviceId, 'status' => 'ACTIVE');
    }

    /**
     * Versioned health check.
     *
     * @url GET /v1/health
     * @access public
     */
    public function v1Health()
    {
        return $this->health();
    }

    /**
     * Verify (consume) an enrollment token. Called by the middleware.
     *
     * @url POST /v1/enrollments/{token}/verify
     * @access public
     *
     * @param string $token       Enrollment token from QR code
     * @param string $label       Device label
     * @param string $platform    android, ios or browser
     * @param string $pwa_version PWA version
     * @param string $public_key  Device public key
     */
    public function verifyEnrollment($token, $label = '', $platform = '', $pwa_version = '', $public_key = '')
    {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) $rawBody = '';
        return $this->doClaimEnrollment($token, $rawBody, $label, $platform, $pwa_version, $public_key);
    }

    /**
     * Get terminal (device) status. Requires middleware HMAC signature or Dolibarr admin API key.
     *
     * @url GET /v1/terminals/{terminal_id}/status
     * @access public
     *
     * @param string $terminal_id Terminal public UUID
     */
    public function terminalStatus($terminal_id)
    {
        global $conf;
        $this->assertMiddlewareOrAdmin('');
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $terminal_id);
        $sql = "SELECT d.public_id, d.fk_user, d.label, d.platform, d.status, d.last_seen_at,";
        $sql .= " u.login, u.firstname, u.lastname";
        $sql .= " FROM ".MAIN_DB_PREFIX."speakdo_device d";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = d.fk_user";
        $sql .= " WHERE d.entity = ".((int) $conf->entity)." AND d.public_id = '".$this->db->escape($safeId)."'";
        $resql = $this->db->query($sql);
        if (!$resql || !($device = $this->db->fetch_object($resql))) {
            throw new RestException(404, 'Terminal not found');
        }
        return array(
            'ok'          => true,
            'terminal_id' => $device->public_id,
            'status'      => strtolower((string) $device->status),
            'label'       => $device->label,
            'platform'    => $device->platform,
            'user'        => array(
                'id'    => (int) $device->fk_user,
                'login' => $device->login,
                'name'  => trim($device->firstname.' '.$device->lastname),
            ),
            'last_seen_at' => $device->last_seen_at,
        );
    }

    /**
     * Get user capabilities for the terminal app. Requires middleware HMAC signature or Dolibarr admin API key.
     *
     * @url GET /v1/users/{user_id}/capabilities
     * @access public
     *
     * @param int $user_id Dolibarr user ID
     */
    public function userCapabilities($user_id)
    {
        global $conf;
        $this->assertMiddlewareOrAdmin('');
        $targetUser = new User($this->db);
        if ($targetUser->fetch((int) $user_id) <= 0 || (int) $targetUser->status !== User::STATUS_ENABLED) {
            throw new RestException(404, 'User not found or disabled');
        }
        $targetUser->loadRights();
        return array(
            'ok'                 => true,
            'active'             => true,
            'user_id'            => (int) $targetUser->id,
            'login'              => $targetUser->login,
            'name'               => trim($targetUser->firstname.' '.$targetUser->lastname),
            'is_admin'           => (bool) $targetUser->admin,
            'api_key_configured' => !empty($targetUser->api_key),
            'capabilities'       => $this->speakdoCapabilitiesForUser($targetUser),
            'permissions_version' => 1,
        );
    }

    /**
     * Execute a Dolibarr action requested by the middleware. Requires HMAC signature.
     *
     * @url POST /v1/proxy
     * @access public
     */
    public function proxy()
    {
        global $conf;
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) $rawBody = '';
        $this->assertMiddlewareSignature($rawBody);

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) throw new RestException(400, 'Invalid JSON body');

        $method      = strtoupper(trim((string) ($payload['method'] ?? 'GET')));
        $endpoint    = trim((string) ($payload['endpoint'] ?? ''));
        $queryParams = is_array($payload['query_params'] ?? null) ? $payload['query_params'] : [];
        $body        = $payload['body'] ?? null;
        $responseWrap = trim((string) ($payload['response_wrap'] ?? ''));

        // Security: only allow known safe methods
        if (!in_array($method, array('GET', 'POST', 'PUT', 'DELETE'), true)) {
            throw new RestException(400, 'Unsupported proxy method: '.$method);
        }
        // Security: restrict to Dolibarr native API paths only, no SpeakDo loop
        if (!preg_match('#^/api/index\.php/(?!speakdo)[a-zA-Z0-9/_\-]+$#', $endpoint)) {
            throw new RestException(400, 'Invalid or forbidden proxy endpoint: '.$endpoint);
        }

        // Build URL
        $apiUser = DolibarrApiAccess::$user ?? null;
        $apiKey  = ($apiUser && !empty($apiUser->api_key)) ? $apiUser->api_key : '';
        if ($apiKey === '') throw new RestException(503, 'No API key available for proxy user');

        $url = DOL_MAIN_URL_ROOT.$endpoint;
        if (!empty($queryParams)) {
            // sanitize: cast all values to string
            $safeParams = array();
            foreach ($queryParams as $k => $v) {
                $safeParams[(string) $k] = (string) $v;
            }
            $url .= '?'.http_build_query($safeParams);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'DOLAPIKEY: '.$apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // internal call
        if (in_array($method, array('POST', 'PUT'), true) && $body !== null) {
            $encoded = is_string($body) ? $body : json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        }
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new RestException(502, 'Proxy curl error: '.$curlError);
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            throw new RestException($httpCode >= 500 ? 502 : $httpCode,
                'Dolibarr native API error',
                $decoded ?? array('raw' => $response)
            );
        }

        // Wrap tableau en {results:[...], count:N} si demandé (pour les actions de recherche)
        if ($responseWrap === 'results' && is_array($decoded) && array_is_list($decoded)) {
            return array('results' => $decoded, 'count' => count($decoded));
        }

        return $decoded ?? array('raw' => $response);
    }

    /**
     * @url POST /v1/actions/{action_id}
     * @access public
     *
     * @param string $action_id Action identifier
     */
    public function executeAction($action_id)
    {
        global $conf;
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) $rawBody = '';
        $this->assertMiddlewareSignature($rawBody);
        // Allow dots in action IDs (e.g. "thirdparty.search", "task.create")
        $action_id = preg_replace('/[^a-zA-Z0-9_.\-]/', '', (string) $action_id);
        $body   = json_decode($rawBody, true) ?: array();
        // Middleware wraps action arguments under 'arguments' key
        $params = is_array($body['arguments'] ?? null) ? $body['arguments'] : $body;
        // Per docs/DOLIBARR_MODULE_CONTRACT.md, user_id travels at the body root
        // (alongside terminal_id, idempotency_key), not inside 'arguments'.
        $userId = (int) ($body['user_id'] ?? 0);

        switch ($action_id) {
            case 'ping':
                return array('ok' => true, 'action_id' => 'ping', 'result' => array(
                    'pong'        => true,
                    'server_time' => dol_print_date(dol_now(), 'standard', 'gmt'),
                ));

            case 'intervention.note.create':
                require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
                $interventionId = (int) ($params['intervention_id'] ?? 0);
                $note = trim((string) ($params['note'] ?? ''));
                if ($interventionId <= 0 || $note === '') throw new RestException(422, 'intervention_id and note are required');
                $fi = new Fichinter($this->db);
                if ($fi->fetch($interventionId) <= 0 || (int) $fi->entity !== (int) $conf->entity) {
                    throw new RestException(404, 'Intervention not found');
                }
                $statusMap = array('draft' => 0, 'done' => 3, 'blocked' => 2);
                $newStatus = isset($params['status']) && isset($statusMap[$params['status']]) ? $statusMap[$params['status']] : null;
                $fi->description = ($fi->description ? $fi->description."\n\n" : '').$note;
                if ($newStatus !== null) $fi->statut = $newStatus;
                if ($fi->update(DolibarrApiAccess::$user ?: new User($this->db)) < 0) {
                    throw new RestException(500, $fi->error ?: 'Update failed');
                }
                return array('ok' => true, 'action_id' => $action_id, 'result' => array(
                    'intervention_id' => $interventionId,
                    'note_appended'   => true,
                    'new_status'      => $newStatus !== null ? $params['status'] : null,
                ));

            case 'intervention.invoice.create':
                return $this->doCreateInvoiceFromIntervention($action_id, $params, $userId);

            case 'document.send':
                return $this->doSendDocumentByEmail($action_id, $params, $userId);

            case 'object.list_linked':
                return $this->doListLinkedObjects($action_id, $params, $userId);

            default:
                throw new RestException(501, 'Action not implemented: '.$action_id);
        }
    }

    /**
     * Create a draft invoice from a validated intervention (same mechanism used by
     * Dolibarr's own "Create bill" button on the intervention card: origin/origin_id
     * link to fichinter, then classify the intervention as billed).
     *
     * Arguments (arguments):
     *  - intervention_id (int, required)
     *  - lines            (array, optional) [{desc, qty, unit_price, vat_rate}, ...]
     *                      When omitted, one line per intervention line is built from its
     *                      duration (converted to hours) and description.
     * Plus $userId (int, required), the acting Dolibarr user sent at the body root
     * (author + permission check).
     */
    private function doCreateInvoiceFromIntervention($action_id, array $params, $userId)
    {
        global $conf;

        require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
        require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

        $interventionId = (int) ($params['intervention_id'] ?? 0);
        $userId = (int) $userId;
        if ($interventionId <= 0 || $userId <= 0) {
            throw new RestException(422, 'intervention_id and user_id are required');
        }

        $actingUser = new User($this->db);
        if ($actingUser->fetch($userId) <= 0 || (int) $actingUser->status !== User::STATUS_ENABLED) {
            throw new RestException(404, 'User not found or disabled');
        }
        $actingUser->loadRights();
        if (!isModEnabled('facture') || !$actingUser->hasRight('facture', 'creer') || !$actingUser->hasRight('ficheinter', 'lire')) {
            throw new RestException(403, 'User is not allowed to create invoices from interventions');
        }

        $fi = new Fichinter($this->db);
        if ($fi->fetch($interventionId) <= 0 || (int) $fi->entity !== (int) $conf->entity) {
            throw new RestException(404, 'Intervention not found');
        }
        if ((int) $fi->status === Fichinter::STATUS_DRAFT) {
            throw new RestException(409, 'Intervention must be validated before it can be invoiced');
        }
        if ((int) $fi->status >= Fichinter::STATUS_BILLED) {
            throw new RestException(409, 'Intervention is already classified as billed');
        }
        $fi->fetch_lines();

        // Caller-supplied lines take priority (real prices dictated by the user);
        // otherwise fall back to one line per intervention line (duration -> hours).
        $inputLines = array();
        if (isset($params['lines']) && is_array($params['lines'])) {
            foreach ($params['lines'] as $line) {
                $desc = trim((string) ($line['desc'] ?? ''));
                if ($desc === '') continue;
                $inputLines[] = array(
                    'desc'       => $desc,
                    'qty'        => (float) ($line['qty'] ?? 1),
                    'unit_price' => (float) ($line['unit_price'] ?? 0),
                    'vat_rate'   => (float) ($line['vat_rate'] ?? 0),
                );
            }
        } elseif (!empty($fi->lines)) {
            foreach ($fi->lines as $line) {
                $desc = trim((string) $line->desc);
                if ($desc === '') $desc = $fi->ref;
                $inputLines[] = array(
                    'desc'       => $desc,
                    'qty'        => $line->duration > 0 ? round($line->duration / 3600, 2) : 1,
                    'unit_price' => (float) $line->subprice,
                    'vat_rate'   => (float) $line->tva_tx,
                );
            }
        }
        if (empty($inputLines)) {
            throw new RestException(409, 'No lines to invoice: intervention has no lines and none were provided');
        }

        $facture = new Facture($this->db);
        $facture->socid = $fi->socid;
        $facture->type = Facture::TYPE_STANDARD;
        $facture->date = dol_now();
        $facture->fk_project = $fi->fk_project;
        $facture->origin = 'fichinter';
        $facture->origin_id = $fi->id;
        $facture->linked_objects[$facture->origin] = $facture->origin_id;

        $soc = new Societe($this->db);
        if ($soc->fetch($fi->socid) > 0) {
            $facture->cond_reglement_id = $soc->cond_reglement_id;
            $facture->mode_reglement_id = $soc->mode_reglement_id;
        }

        $newInvoiceId = $facture->create($actingUser);
        if ($newInvoiceId <= 0) {
            throw new RestException(500, $facture->error ?: 'Invoice creation failed');
        }

        foreach ($inputLines as $line) {
            $result = $facture->addline($line['desc'], $line['unit_price'], $line['qty'], $line['vat_rate']);
            if ($result <= 0) {
                throw new RestException(500, $facture->error ?: 'Failed to add invoice line');
            }
        }

        $fi->setStatut(Fichinter::STATUS_BILLED);

        return array('ok' => true, 'action_id' => $action_id, 'result' => array(
            'intervention_id' => $interventionId,
            'invoice_id'      => $newInvoiceId,
            'invoice_ref'     => $facture->ref,
            'status'          => 'draft',
        ));
    }

    /**
     * Email a generated document (invoice, intervention report, proposal, order) to its
     * third party's contact email, reusing Dolibarr's own document access control
     * (dol_check_secure_access_document) so no path outside the object's own document
     * directory can ever be reached.
     *
     * Arguments (arguments):
     *  - element    (string, required) One of: facture, fichinter, propal, commande
     *  - element_id (int, required)
     *  - to         (string, optional) Override recipient email; defaults to the third party's email
     *  - subject    (string, optional)
     *  - message    (string, optional)
     *  - filename   (string, optional) File name within the object's document directory; defaults to {ref}.pdf
     * Plus $userId (int, required), the acting Dolibarr user sent at the body root
     * (permission check).
     */
    private function doSendDocumentByEmail($action_id, array $params, $userId)
    {
        global $conf, $langs;

        $allowedElements = array(
            'facture'   => array('compta/facture/class/facture.class.php', 'Facture',   'facture'),
            'fichinter' => array('fichinter/class/fichinter.class.php',     'Fichinter', 'ficheinter'),
            'propal'    => array('comm/propal/class/propal.class.php',      'Propal',    'propal'),
            'commande'  => array('commande/class/commande.class.php',       'Commande',  'commande'),
        );

        $element = trim((string) ($params['element'] ?? ''));
        $elementId = (int) ($params['element_id'] ?? 0);
        $userId = (int) $userId;
        if (!isset($allowedElements[$element])) {
            throw new RestException(422, 'Unsupported element type: '.$element);
        }
        if ($elementId <= 0 || $userId <= 0) {
            throw new RestException(422, 'element_id and user_id are required');
        }

        list($classFile, $className, $rightModule) = $allowedElements[$element];

        $actingUser = new User($this->db);
        if ($actingUser->fetch($userId) <= 0 || (int) $actingUser->status !== User::STATUS_ENABLED) {
            throw new RestException(404, 'User not found or disabled');
        }
        $actingUser->loadRights();
        if (!isModEnabled($rightModule) || !$actingUser->hasRight($rightModule, 'lire')) {
            throw new RestException(403, 'User is not allowed to read this document');
        }

        require_once DOL_DOCUMENT_ROOT.'/'.$classFile;
        $object = new $className($this->db);
        if ($object->fetch($elementId) <= 0 || (int) $object->entity !== (int) $conf->entity) {
            throw new RestException(404, ucfirst($element).' not found');
        }
        $object->fetch_thirdparty();

        $recipient = trim((string) ($params['to'] ?? ''));
        if ($recipient === '') {
            $recipient = (string) ($object->thirdparty->email ?? '');
        }
        if (!isValidEmail($recipient)) {
            throw new RestException(422, 'No valid recipient email available');
        }

        $filename = trim((string) ($params['filename'] ?? ''));
        $safeFilename = $filename !== '' ? basename($filename) : $object->ref.'.pdf';
        $safeFilename = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $safeFilename);

        require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
        $check = dol_check_secure_access_document($element, $object->ref.'/'.$safeFilename, $conf->entity, $actingUser, '', 'read');
        if (empty($check['accessallowed'])) {
            throw new RestException(403, 'Access to this document is not allowed');
        }
        $fullpath = $check['original_file'];
        if (!is_file($fullpath)) {
            throw new RestException(404, 'Document file not found: '.$safeFilename);
        }

        $subject = trim((string) ($params['subject'] ?? ''));
        $message = trim((string) ($params['message'] ?? ''));
        if ($subject === '') $subject = $langs->trans('SpeakDoDocumentEmailSubject', $object->ref);
        if ($message === '') $message = $langs->trans('SpeakDoDocumentEmailBody', $object->ref);

        $from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
        if ($from === '') {
            throw new RestException(503, 'Outgoing email is not configured (MAIN_MAIL_EMAIL_FROM)');
        }

        require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
        $mime = dol_mimetype($fullpath);
        $mailFile = new CMailFile($subject, $recipient, $from, $message, array($fullpath), array($mime), array($safeFilename));
        if (!empty($mailFile->error)) {
            throw new RestException(500, 'Unable to build email: '.$mailFile->error);
        }
        if (!$mailFile->sendfile()) {
            throw new RestException(502, 'Unable to send email: '.$mailFile->error);
        }

        return array('ok' => true, 'action_id' => $action_id, 'result' => array(
            'element'    => $element,
            'element_id' => $elementId,
            'to'         => $recipient,
            'filename'   => $safeFilename,
            'sent'       => true,
        ));
    }

    /**
     * SpeakDo vocabulary <-> Dolibarr native element type, matching the table already
     * established for object.get/object.list, plus what is needed to fetch/describe
     * an object of that type: [native_type, class_file, class_name, right_module, right_level(s)].
     * right_level(s) is either a 2-element array [module, level] or a 3-element array
     * [module, sublevel, level] to match User::hasRight()'s variable arity.
     *
     * @return array<string, array>
     */
    private function speakdoLinkedObjectTypeMap()
    {
        return array(
            'thirdparty'   => array('societe',    'societe/class/societe.class.php',        'Societe',    'societe',  array('societe', 'lire')),
            'invoice'      => array('facture',    'compta/facture/class/facture.class.php', 'Facture',    'facture',  array('facture', 'lire')),
            'order'        => array('commande',   'commande/class/commande.class.php',      'Commande',   'commande', array('commande', 'lire')),
            'proposal'     => array('propal',     'comm/propal/class/propal.class.php',     'Propal',     'propal',   array('propal', 'lire')),
            'project'      => array('project',    'projet/class/project.class.php',         'Project',    'projet',   array('projet', 'lire')),
            'intervention' => array('fichinter',  'fichinter/class/fichinter.class.php',    'Fichinter',  'ficheinter', array('ficheinter', 'lire')),
            'shipment'     => array('expedition', 'expedition/class/expedition.class.php',  'Expedition', 'expedition', array('expedition', 'lire')),
            'product'      => array('product',    'product/class/product.class.php',        'Product',    'product',  array('produit', 'lire')),
            'contact'      => array('contact',    'contact/class/contact.class.php',        'Contact',    'societe',  array('societe', 'contact', 'lire')),
            'ticket'       => array('ticket',     'ticket/class/ticket.class.php',           'Ticket',     'ticket',   array('ticket', 'read')),
        );
    }

    private function speakdoUserCanReadType($user, array $typeInfo)
    {
        list(, , , $rightModule, $rightArgs) = $typeInfo;
        if (!isModEnabled($rightModule)) {
            return false;
        }
        $allowed = $user->hasRight(...$rightArgs);
        // Product rights are split product/service; accept either.
        if (!$allowed && $rightModule === 'product') {
            $allowed = $user->hasRight('service', 'lire');
        }
        return (bool) $allowed;
    }

    /**
     * List objects natively linked (llx_element_element) to a given object, translating
     * Dolibarr's internal element types to SpeakDo vocabulary. Read-only.
     *
     * Arguments (arguments):
     *  - object_type  (string, required) SpeakDo vocabulary key of the source object
     *  - object_id    (int, optional if ref is given)
     *  - ref          (string, optional if object_id is given)
     *  - linked_type  (string, optional) Restrict results to this SpeakDo type
     *  - limit        (int, optional, default 50, max 200)
     * Plus $userId (int, required), sent at the body root.
     */
    private function doListLinkedObjects($action_id, array $params, $userId)
    {
        global $conf;

        $typeMap = $this->speakdoLinkedObjectTypeMap();

        $sourceType = trim((string) ($params['object_type'] ?? ''));
        $sourceId = (int) ($params['object_id'] ?? 0);
        $sourceRef = trim((string) ($params['ref'] ?? ''));
        $linkedTypeFilter = trim((string) ($params['linked_type'] ?? ''));
        $limit = (int) ($params['limit'] ?? 50);
        if ($limit <= 0) $limit = 50;
        if ($limit > 200) $limit = 200;
        $userId = (int) $userId;

        if (!isset($typeMap[$sourceType]) || ($sourceId <= 0 && $sourceRef === '') || $userId <= 0) {
            throw new RestException(422, 'object_type, (object_id or ref) and user_id are required');
        }
        if ($linkedTypeFilter !== '' && !isset($typeMap[$linkedTypeFilter])) {
            throw new RestException(422, 'Unsupported linked_type: '.$linkedTypeFilter);
        }

        $actingUser = new User($this->db);
        if ($actingUser->fetch($userId) <= 0 || (int) $actingUser->status !== User::STATUS_ENABLED) {
            throw new RestException(404, 'User not found or disabled');
        }
        $actingUser->loadRights();

        $sourceInfo = $typeMap[$sourceType];
        if (!$this->speakdoUserCanReadType($actingUser, $sourceInfo)) {
            throw new RestException(403, 'User is not allowed to read this object type');
        }

        $sourceObject = $this->speakdoFetchTypedObject($sourceType, $sourceInfo, $sourceId, $sourceRef);
        if (!$sourceObject || (int) $sourceObject->entity !== (int) $conf->entity) {
            throw new RestException(404, ucfirst($sourceType).' not found');
        }

        $nativeSourceType = $sourceInfo[0];
        $sql = "SELECT fk_source, sourcetype, fk_target, targettype FROM ".MAIN_DB_PREFIX."element_element";
        $sql .= " WHERE (fk_source = ".((int) $sourceObject->id)." AND sourcetype = '".$this->db->escape($nativeSourceType)."')";
        $sql .= " OR (fk_target = ".((int) $sourceObject->id)." AND targettype = '".$this->db->escape($nativeSourceType)."')";
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RestException(500, $this->db->lasterror());
        }

        // Reverse map: native Dolibarr element type -> SpeakDo type
        $reverseMap = array();
        foreach ($typeMap as $speakdoType => $info) {
            $reverseMap[$info[0]] = $speakdoType;
        }

        // Collect distinct (nativeType, id) pairs on the "other side" of each link
        $candidates = array();
        while ($row = $this->db->fetch_object($resql)) {
            if ((int) $row->fk_source === (int) $sourceObject->id && $row->sourcetype === $nativeSourceType) {
                $otherType = $row->targettype;
                $otherId = (int) $row->fk_target;
            } else {
                $otherType = $row->sourcetype;
                $otherId = (int) $row->fk_source;
            }
            if (!isset($reverseMap[$otherType])) continue; // Not a type SpeakDo knows about
            $speakdoType = $reverseMap[$otherType];
            if ($linkedTypeFilter !== '' && $speakdoType !== $linkedTypeFilter) continue;
            $candidates[$speakdoType.':'.$otherId] = array($speakdoType, $otherId);
        }

        $results = array();
        foreach ($candidates as $candidate) {
            if (count($results) >= $limit) break;
            list($speakdoType, $id) = $candidate;
            $info = $typeMap[$speakdoType];
            if (!$this->speakdoUserCanReadType($actingUser, $info)) continue;
            $obj = $this->speakdoFetchTypedObject($speakdoType, $info, $id, '');
            if (!$obj || (int) $obj->entity !== (int) $conf->entity) continue;
            $results[] = $this->speakdoDescribeObject($speakdoType, $obj);
        }

        // The middleware already wraps every action response in {ok, action_id, action_version, result} —
        // return the raw payload only, unlike other actions in this file that pre-date that contract.
        return array(
            'results' => $results,
            'count'   => count($results),
        );
    }

    /**
     * Fetch an object of a given SpeakDo type by id or ref. Contact::fetch() has a
     * different signature (2nd argument is a User, not a ref) so it is special-cased
     * to id-only lookup.
     *
     * @return CommonObject|null
     */
    private function speakdoFetchTypedObject($speakdoType, array $typeInfo, $id, $ref)
    {
        list(, $classFile, $className) = $typeInfo;
        require_once DOL_DOCUMENT_ROOT.'/'.$classFile;
        $obj = new $className($this->db);
        if ($speakdoType === 'contact') {
            $result = $id > 0 ? $obj->fetch((int) $id) : -1;
        } else {
            $result = $obj->fetch((int) $id, (string) $ref);
        }
        return $result > 0 ? $obj : null;
    }

    /**
     * Build the {candidate_ref, display_label, object_type, ref, status, date} shape
     * shared with the existing search-result actions.
     */
    private function speakdoDescribeObject($speakdoType, $obj)
    {
        global $langs;

        if ($speakdoType === 'thirdparty') {
            $ref = (string) ($obj->name ?? $obj->nom ?? '');
        } elseif ($speakdoType === 'contact') {
            $ref = method_exists($obj, 'getFullName') ? $obj->getFullName($langs) : trim(($obj->firstname ?? '').' '.($obj->lastname ?? ''));
        } else {
            $ref = (string) ($obj->ref ?? '');
        }
        if ($ref === '') $ref = '#'.$obj->id;

        $status = '';
        if (method_exists($obj, 'getLibStatut')) {
            $status = (string) $obj->getLibStatut(0);
        } elseif (isset($obj->statut)) {
            $status = (string) $obj->statut;
        } elseif (isset($obj->status)) {
            $status = (string) $obj->status;
        }

        $date = null;
        foreach (array('date', 'date_commande', 'date_creation', 'datec') as $prop) {
            if (property_exists($obj, $prop) && !empty($obj->$prop)) {
                $date = is_numeric($obj->$prop) ? (int) $obj->$prop : strtotime((string) $obj->$prop);
                if ($date) break;
            }
        }

        return array(
            'candidate_ref'  => $speakdoType.':'.$obj->id,
            'display_label'  => $ref,
            'object_type'    => $speakdoType,
            'ref'            => $ref,
            'status'         => $status,
            'date'           => $date ? dol_print_date($date, 'dayhour') : null,
        );
    }

    /**
     * Return the list of SpeakDo capability strings for a user.
     * Maps SpeakDo action capability names to Dolibarr module rights.
     * This list must match the "permissions.capabilities" values in the action manifests.
     *
     * @param User $user Dolibarr user (rights must already be loaded)
     * @return list<string>
     */
    private function speakdoCapabilitiesForUser($user)
    {
        // Format: 'speakdo_capability' => ['module', 'right_level1', 'right_level2']
        // right_level2 = '' means only one level (e.g. hasRight('societe','lire'))
        $map = array(
            // Tiers (societe)
            'thirdparty.read'          => array('societe',    'lire',      ''),
            'thirdparty.write'         => array('societe',    'creer',     ''),
            // Interventions (ficheinter)
            'intervention.read'        => array('ficheinter', 'lire',      ''),
            'intervention.write'       => array('ficheinter', 'creer',     ''),
            'intervention.note.create' => array('ficheinter', 'creer',     ''),
            // Factures (facture)
            'invoice.read'             => array('facture',    'lire',      ''),
            'invoice.write'            => array('facture',    'creer',     ''),
            'intervention.invoice.create' => array('facture', 'creer',     ''),
            // Tâches projet (projet)
            'task.create'              => array('projet',     'task',      'creer'),
            'task.read'                => array('projet',     'lire',      ''),
            // Envoi de documents par email
            'document.send'            => array('societe',    'lire',      ''),
            // Objets liés (element_element)
            'object.list_linked'       => array('societe',    'lire',      ''),
        );
        $caps = array();
        foreach ($map as $capability => $right) {
            if (!isModEnabled($right[0])) continue;
            if ($right[2] !== '') {
                if ($user->hasRight($right[0], $right[1], $right[2])) $caps[] = $capability;
            } else {
                if ($user->hasRight($right[0], $right[1])) $caps[] = $capability;
            }
        }
        return $caps;
    }

    /**
     * Accept either a valid Dolibarr admin API key (for manual testing) or a valid HMAC signature (for the middleware).
     * If the standard Dolibarr authenticated user is an admin, skip HMAC entirely.
     */
    private function assertMiddlewareOrAdmin($rawBody)
    {
        $apiUser = DolibarrApiAccess::$user ?? null;
        if ($apiUser && !empty($apiUser->id) && !empty($apiUser->admin)) {
            return; // admin with valid DOLAPIKEY — allow
        }
        $this->assertMiddlewareSignature($rawBody);
    }

    private function assertMiddlewareSignature($rawBody)
    {
        global $conf;
        $headers = $this->requestHeaders();
        $tenant    = isset($headers['x-speakdo-tenant'])    ? (string) $headers['x-speakdo-tenant']    : '';
        $timestamp = isset($headers['x-speakdo-timestamp']) ? (string) $headers['x-speakdo-timestamp'] : '';
        $nonce     = isset($headers['x-speakdo-nonce'])     ? (string) $headers['x-speakdo-nonce']     : '';
        // Signature is base64(hmac_sha256_raw) — case-sensitive, keep original
        $signature = isset($headers['x-speakdo-signature']) ? (string) $headers['x-speakdo-signature'] : '';

        $storedTenant = getDolGlobalString('SPEAKDO_TENANT_UUID') ?: getDolGlobalString('SPEAKDO_TENANT_ID');
        if (!hash_equals($storedTenant, $tenant)) throw new RestException(401, 'Invalid tenant');
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) throw new RestException(401, 'Expired request timestamp');
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)) throw new RestException(401, 'Invalid nonce');
        // Base64 signature: 44 chars (standard) or up to ~88 (long keys); reject clearly wrong values
        if (!preg_match('/^[A-Za-z0-9+\/=]{20,128}$/', $signature)) throw new RestException(401, 'Invalid signature format');

        $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '');
        $path = parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
        if (!in_array($method, array('GET', 'POST')) || !is_string($path) || $path === '') throw new RestException(400, 'Invalid request target');

        $secret = speakdo_get_middleware_secret();
        if ($secret === '') throw new RestException(503, 'Middleware secret is not configured');
        // Canonical format matches HmacSigner::canonicalRequest in the middleware:
        // METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256(body)
        $canonical = $method."\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $rawBody);
        $expected = base64_encode(hash_hmac('sha256', $canonical, $secret, true));
        if (!hash_equals($expected, $signature)) throw new RestException(401, 'Invalid signature');

        $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."speakdo_nonce WHERE expires_at < '".$this->db->idate(dol_now())."'");
        $nonceHash = hash('sha256', $nonce);
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."speakdo_nonce(entity, nonce_hash, datec, expires_at) VALUES (".((int) $conf->entity).", '".$this->db->escape($nonceHash)."', '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now() + 600)."')";
        if (!$this->db->query($sql)) {
            if ($this->db->errno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') throw new RestException(409, 'Nonce already used');
            throw new RestException(500, 'Unable to persist nonce: '.$this->db->lasterror());
        }
    }

    private function doClaimEnrollment($token, $rawBody, $label = '', $platform = '', $pwa_version = '', $public_key = '')
    {
        global $conf;
        if (!preg_match('/^[A-Za-z0-9_-]{32,512}$/', $token)) {
            throw new RestException(400, 'Invalid enrollment token');
        }
        $this->assertMiddlewareSignature($rawBody);

        // Parse body — middleware sends terminal fields nested under 'terminal'
        $bodyData = json_decode($rawBody, true) ?: array();
        $terminalData = is_array($bodyData['terminal'] ?? null) ? $bodyData['terminal'] : array();

        $label       = dol_trunc(trim(strip_tags((string) ($terminalData['label'] ?? $label))), 190, 'right', 'UTF-8', 1);
        if ($label === '') $label = 'Téléphone SpeakDo';
        $platform    = dol_trunc(preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($terminalData['platform'] ?? $platform)), 32, 'right', 'UTF-8', 1);
        $pwa_version = dol_trunc(preg_replace('/[^a-zA-Z0-9._+-]/', '', (string) ($terminalData['pwa_version'] ?? $pwa_version)), 32, 'right', 'UTF-8', 1);
        $public_key  = trim((string) ($terminalData['public_key_pem'] ?? $terminalData['public_key'] ?? $public_key));
        // terminal_id proposed by the middleware (UUID); fallback to our own generated UUID
        $proposedTerminalId = trim((string) ($terminalData['terminal_id'] ?? ''));
        if (strlen($public_key) > 16384) {
            throw new RestException(400, 'Public key too large');
        }

        $tokenHash = hash('sha256', $token);
        $this->db->begin();
        try {
            $sql = "SELECT rowid, fk_user, status, expires_at FROM ".MAIN_DB_PREFIX."speakdo_enrollment";
            $sql .= " WHERE entity = ".((int) $conf->entity)." AND token_hash = '".$this->db->escape($tokenHash)."' FOR UPDATE";
            $resql = $this->db->query($sql);
            if (!$resql || !($enrollment = $this->db->fetch_object($resql))) {
                throw new RestException(404, 'Enrollment not found');
            }
            if ($enrollment->status !== 'PENDING') {
                throw new RestException(409, 'Enrollment already consumed or cancelled');
            }
            if ($this->db->jdate($enrollment->expires_at) < dol_now()) {
                throw new RestException(410, 'Enrollment expired');
            }
            $target = new User($this->db);
            if ($target->fetch((int) $enrollment->fk_user) <= 0 || (int) $target->status !== User::STATUS_ENABLED) {
                throw new RestException(403, 'Dolibarr user is disabled or unavailable');
            }
            // Use the terminal_id proposed by the middleware, or generate our own
            $publicId = (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $proposedTerminalId) ? $proposedTerminalId : speakdo_uuid_v4());
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."speakdo_device(entity, public_id, fk_user, label, platform, pwa_version, public_key, status, datec, last_seen_at) VALUES (";
            $sql .= ((int) $conf->entity).", '".$this->db->escape($publicId)."', ".((int) $target->id).", '".$this->db->escape($label)."', ";
            $sql .= ($platform !== '' ? "'".$this->db->escape($platform)."'" : 'NULL').", ";
            $sql .= ($pwa_version !== '' ? "'".$this->db->escape($pwa_version)."'" : 'NULL').", ";
            $sql .= ($public_key !== '' ? "'".$this->db->escape($public_key)."'" : 'NULL').", 'ACTIVE', '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."')";
            if (!$this->db->query($sql)) {
                throw new RuntimeException($this->db->lasterror());
            }
            $sql = "UPDATE ".MAIN_DB_PREFIX."speakdo_enrollment SET status = 'CONSUMED', consumed_at = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $enrollment->rowid)." AND status = 'PENDING'";
            if (($updateRes = $this->db->query($sql)) === false || $this->db->affected_rows($updateRes) !== 1) {
                throw new RestException(409, 'Enrollment was consumed concurrently');
            }
            $this->db->commit();
            $displayName = trim($target->firstname.' '.$target->lastname);
            if ($displayName === '') $displayName = $target->login;
            // Compute user capabilities using the SpeakDo capability map
            $target->loadRights();
            $capList = $this->speakdoCapabilitiesForUser($target);
            // Générer une clé API si l'utilisateur n'en a pas encore
            if (empty($target->api_key)) {
                $target->api_key = getRandomPassword(false);
                $target->update($target, true, 1, 0, '', 'noemail');
            }
            return array(
                'ok'              => true,
                'valid'           => true,
                'terminal_id'     => $publicId,
                'terminal_status' => 'active',
                'user'            => array(
                    'id'           => (int) $target->id,
                    'login'        => $target->login,
                    'name'         => $displayName,
                    'display_name' => $displayName,
                    'active'       => true,
                ),
                'dolibarr_apikey'    => $target->api_key ?: null,
                'capabilities'       => $capList,
                'permissions_version' => 1,
            );
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof RestException) throw $e;
            throw new RestException(500, $e->getMessage());
        }
    }

    private function requestHeaders()
    {
        $headers = array();
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $key => $value) $headers[strtolower($key)] = trim((string) $value);
        }
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                if (!isset($headers[$name])) $headers[$name] = trim((string) $value);
            }
        }
        return $headers;
    }
}
