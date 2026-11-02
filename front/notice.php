<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 *
 * Public notices for the logged-in user (JSON). Used by public/js/notice.js on the self-service portal: published
 * surges of the user's entities, open or resolved within the last few hours. Only the notice text is exposed — no
 * ticket, requester or internal detail.
 */

use GlpiPlugin\Surge\Surge;

include('../../../inc/includes.php');

if (Session::getLoginUserID() === false) { // GLPI already requires a session for plugin pages; belt and braces
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
echo json_encode(['notices' => Surge::notices()], JSON_UNESCAPED_UNICODE);
exit;
