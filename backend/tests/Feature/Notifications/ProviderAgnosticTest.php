<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * SC-002 — the addendum's hardest constraint, as a build gate.
 *
 * "No business logic may depend on a specific notification provider" is an
 * architectural rule, and an architectural rule with no gate decays at the first
 * deadline. This is a text scan rather than an AST walk because what is forbidden
 * IS textual: naming a provider. Whoever writes `->notify(` or `'whatsapp'` in an
 * Action finds out here rather than in review.
 */
function actionFiles(): Finder
{
    return Finder::create()
        ->files()
        ->in(app_path('Modules'))
        ->path('/Actions/')
        ->name('*.php');
}

/**
 * Source with every comment removed.
 *
 * Named distinctly rather than reusing a sibling's copy on purpose: these live
 * in Pest files with no namespace, `--parallel` loads several of them into one
 * worker, and two identical global function names is a fatal redeclare — the
 * failure mode the suite already paid for with a duplicated constant.
 */
function agnosticSourceWithoutComments(string $source): string
{
    $kept = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $kept[] = is_array($token) ? $token[1] : $token;
    }

    return implode('', $kept);
}

it('never names a channel or provider inside business logic', function (): void {
    $forbidden = [
        'whatsapp', 'telegram', 'twilio', 'vonage', 'firebase',
        // Spec 020 — the first real provider this module ever had. A guard that
        // does not know the new name does not guard it, and the vendor whose
        // name is actually in the tree is the one worth naming.
        '360dialog', 'd360',
        'InAppChannel', 'MailMessage', '->notify(', 'Notification::route',
    ];

    $offenders = [];

    foreach (actionFiles() as $file) {
        /*
        | ⚠️ COMMENTS STRIPPED FIRST, AND THIS FILE WAS THE LAST GUARD IN THE TREE
        | WITHOUT IT. A rule written down beside the code it governs was read as a
        | breach of itself: an Action explaining WHY it does not call Laravel's
        | own Notifiable method turned this build red for containing the very
        | string it was warning against. `TrustScoreJobIsolationTest` and
        | `ContextIsolationTest` each learned this and each wrote their own
        | stripper; a red build over an explanation teaches people to delete the
        | explanation.
        */
        $contents = agnosticSourceWithoutComments($file->getContents());

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = $file->getRelativePathname().' → '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});

// The channel-picking words are allowed in exactly one place: the module that
// implements channels. Anywhere else they mean business logic has started
// deciding how a message travels.
it('confines channel implementations to the Channels directory', function (): void {
    $files = Finder::create()
        ->files()
        ->in(app_path('Modules'))
        ->name('*.php')
        ->notPath('Notifications/Channels')
        ->notPath('Notifications/Support')
        ->notPath('Notifications/Contracts')
        ->notPath('Notifications/Http')
        ->notPath('Notifications/Exceptions')
        ->notPath('Notifications/Jobs')
        ->notPath('Notifications/Database');

    $offenders = [];

    foreach ($files as $file) {
        if (str_contains($file->getContents(), 'implements NotificationChannelInterface')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

// Laravel's own notification path stays available for exactly two things, both
// authentication primitives (research R14). A reset link delivered in-app is
// unreachable by definition: the person asking for it cannot sign in to read it.
it('keeps Notifiable on User for password reset and email verification', function (): void {
    $user = file_get_contents(app_path('Models/User.php'));

    expect($user)->toContain('Notifiable')
        ->and($user)->toContain('MustVerifyEmail');
});
