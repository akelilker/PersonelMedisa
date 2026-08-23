<?php

declare(strict_types=1);

/**
 * Child harness for OrgScope::assertPersonelAccess.
 * argv: [1]=base64 JSON {user, org, mode: allow|deny}
 * Prints ALLOW or FORBIDDEN JSON; exit 0 always after response (forbidden exits via JsonResponse).
 */

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\OrgScope;

$raw = isset($argv[1]) ? (string) $argv[1] : '';
$data = json_decode(base64_decode($raw), true);
if (!is_array($data)) {
    fwrite(STDERR, "BAD_PAYLOAD\n");
    exit(2);
}

$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/t';
$req = new Request();
OrgScope::assertPersonelAccess($data['user'], $req, $data['org']);
echo "ALLOW\n";
exit(0);
