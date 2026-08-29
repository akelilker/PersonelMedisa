<?php

declare(strict_types=1);

/**
 * Initial password derivation runner.
 *
 * Locks PersonelMedisa's Medisa\Api\Auth\InitialPassword to the rule already in
 * production in the Tasit Yonetim Sistemi
 * (`medisaBuildLegacyDefaultCredentials`). The Tasit repo is not a dependency of
 * this repo or of CI, so the parity vectors below are the ones Tasit itself
 * asserts in `scripts/verify-medisa-default-credentials.php`, reproduced here as
 * fixtures.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/api/src/Auth/InitialPassword.php';

use Medisa\Api\Auth\InitialPassword;

function ipAssert($cond, $msg)
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "OK: {$msg}\n";
}

// --- Tasit parity vectors (asserted verbatim in the Tasit verify script) ---
ipAssert(InitialPassword::deriveOrNull('Serhan KÖSE') === 'Kose123', 'Tasit vector Serhan KOSE => Kose123');
ipAssert(InitialPassword::deriveOrNull('Sevkiyat') === 'Sevkiyat123', 'Tasit vector single-word Sevkiyat => Sevkiyat123');
ipAssert(
    InitialPassword::transliterate('ÇĞİÖŞÜçğıöşü') === 'CGIOSUcgiosu',
    'Tasit transliteration table byte-for-byte'
);

// --- Turkish letters in the surname ---
ipAssert(InitialPassword::deriveOrNull('Zeynep ÇİĞDEM') === 'Cigdem123', 'Turkish surname CIGDEM => Cigdem123');
ipAssert(InitialPassword::deriveOrNull('Ilker Şahin') === 'Sahin123', 'Turkish surname Sahin => Sahin123');
ipAssert(InitialPassword::deriveOrNull('Ömer Ünlü') === 'Unlu123', 'Turkish surname Unlu => Unlu123');
ipAssert(InitialPassword::deriveOrNull('İlker Akel') === 'Akel123', 'Turkish first letter I does not affect surname');

// --- Case is normalized, not trusted from input ---
ipAssert(InitialPassword::deriveOrNull('ilker akel') === 'Akel123', 'lowercase input normalizes');
ipAssert(InitialPassword::deriveOrNull('ILKER AKEL') === 'Akel123', 'uppercase input normalizes');
ipAssert(InitialPassword::deriveOrNull('  Ilker   Akel  ') === 'Akel123', 'extra whitespace collapses');

// --- Multi-part names: last token is the surname, first token must exist ---
ipAssert(
    InitialPassword::deriveOrNull('Ayse Nur Öztürk Kaya') === 'Kaya123',
    'multi-part name uses the last token as surname'
);
ipAssert(
    InitialPassword::deriveOrNull('Mehmet Ali Şen') === 'Sen123',
    'three-part name uses the last token as surname'
);
ipAssert(
    InitialPassword::deriveOrNull("Hasan O'Brien-Yilmaz") === 'Obrienyilmaz123',
    'non-alphanumeric characters are stripped from the surname token'
);

// --- Fail closed: nothing usable to derive from ---
ipAssert(InitialPassword::deriveOrNull('') === null, 'empty name fails closed');
ipAssert(InitialPassword::deriveOrNull('   ') === null, 'whitespace-only name fails closed');
ipAssert(InitialPassword::deriveOrNull('...') === null, 'punctuation-only name fails closed');
ipAssert(InitialPassword::deriveOrNull('Ilker ...') === null, 'punctuation-only surname fails closed');
ipAssert(InitialPassword::deriveOrNull('... Akel') === null, 'punctuation-only first name fails closed');
// A one-letter surname cannot satisfy the Tasit shape check (needs a lowercase char).
ipAssert(InitialPassword::deriveOrNull('Ilker A') === null, 'single-letter surname fails the derived shape');

// --- Deterministic: the same name always yields the same initial password ---
ipAssert(
    InitialPassword::deriveOrNull('Zeynep ÇİĞDEM') === InitialPassword::deriveOrNull('zeynep çiğdem'),
    'derivation is deterministic across case variants'
);

// --- The derived value is only ever handed out as a bcrypt hash ---
$derived = InitialPassword::deriveOrNull('Serhan KÖSE');
$hash = password_hash($derived, PASSWORD_BCRYPT);
ipAssert(password_verify($derived, $hash) === true, 'derived initial password verifies against its bcrypt hash');
ipAssert(strpos($hash, $derived) === false, 'bcrypt hash does not contain the plaintext');

// --- Shape contract mirrors Tasit, and is not the user-chosen password policy ---
ipAssert(InitialPassword::satisfiesDerivedShape('Kose123') === true, 'Tasit shape accepts a 7-char derived value');
ipAssert(InitialPassword::satisfiesDerivedShape('A123') === false, 'Tasit shape rejects under minimum length');
ipAssert(InitialPassword::satisfiesDerivedShape('kose123') === false, 'Tasit shape requires an uppercase letter');
ipAssert(InitialPassword::MIN_LENGTH === 6, 'derived-shape minimum length is 6 as in Tasit');

// --- demo123 can never be produced for a real surname ---
$demoish = InitialPassword::deriveOrNull('Test Demo');
ipAssert($demoish === 'Demo123', 'surname Demo derives Demo123');
ipAssert($demoish !== 'demo123', 'derivation never produces the forbidden demo123 literal');

echo "INITIAL_PASSWORD_TASIT_PARITY=PASS\n";
