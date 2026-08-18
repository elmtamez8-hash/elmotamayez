<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Which provider holds THIS session's room — read from the session, not the config.
 *
 * ⚠️ LITERALLY THE DEFECT 019 FIXED FOR `media_assets.provider`, ONE MODULE OVER.
 * `class_sessions.broadcast_provider` has been written by `OpenBroadcastRoom`
 * since 017 and read by NOTHING: every consumer took the single binding from
 * `sessions.provider`. Harmless with one provider, and a silent loss the day
 * there are two.
 *
 * The live cost is not hypothetical. Flip the platform to a provider that does not
 * record while yesterday's recordings are still in flight, and
 * `IngestSessionRecordingJob` asks the
 * configured provider whether it records, is told no, and RETURNS having written
 * nothing. The egress file sits in our own bucket and nobody will ever ask for it;
 * `PackageCompletion` reads the unchanged status and releases the teacher's fee
 * with `recording_fault: false`; and the seat holders are never told, because the
 * notification lives below that early return. Paid for, lost, and silent.
 *
 * ⚠️ BESIDE THE BINDING, NEVER INSTEAD OF IT. `OpenBroadcastRoom` runs before any
 * room exists, so there is no column to read and the configured provider is the
 * only correct answer there — the media module keeps the same split for the same
 * reason. Everything AFTER the room is opened reads the column.
 *
 * ⚠️ AND THE NAMES LIVE IN `LiveSessionsServiceProvider`. The map is injected so
 * one file names the providers; a resolver holding its own copy is a second place
 * to update, which is how a provider becomes resolvable in one direction only.
 */
final class BroadcastProviderResolver
{
    /**
     * @param  array<string, callable(): BroadcastProviderInterface>  $map
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $map,
    ) {}

    /** The provider a session with no room yet must be opened with. */
    public function configured(): BroadcastProviderInterface
    {
        return $this->container->make(BroadcastProviderInterface::class);
    }

    /**
     * The provider this session's room actually belongs to.
     *
     * Falls back to the configured one only while the column is still null — a
     * session whose room was never opened. A NAMED provider this deployment does
     * not have throws instead: the alternative is quietly asking the wrong
     * provider about a room it never created and being told, correctly, that
     * there is nothing there.
     *
     * @throws RuntimeException on an unknown name.
     */
    public function for(ClassSession $session): BroadcastProviderInterface
    {
        $name = $session->broadcast_provider;

        if ($name === null || $name === '') {
            return $this->configured();
        }

        $configured = $this->configured();

        /*
         * ⚠️ THE BOUND INSTANCE ANSWERS FOR ITS OWN NAME, AND THE MAP IS THE
         * SECOND QUESTION.
         *
         * The overwhelmingly common case is a session stamped with the provider
         * that is still configured — and going to the map there would build a
         * SECOND adapter beside the one the container holds, discarding anything
         * bound into it. A test that swaps a double in would find its double
         * ignored, which is a resolver that cannot be tested through.
         */
        if ($name === $configured->identifier()) {
            return $configured;
        }

        $factory = $this->map[$name] ?? null;

        if ($factory === null) {
            throw new RuntimeException(
                "مزوّد البثّ «{$name}» غير مُهيَّأ في هذا النظام (الحصة {$session->uuid})."
            );
        }

        return $factory();
    }

    /**
     * The provider names that record, for a sweep that cannot ask row by row.
     *
     * `RetryPendingRecordingsJob` re-dispatches for sessions whose recording never
     * settled, and a session on a provider that does not record has nothing to
     * settle. Asking per row would be a provider lookup per row inside a query; the
     * names are asked once and the filter is a column comparison.
     *
     * @return list<string>
     */
    public function recordingProviderNames(): array
    {
        $names = [];

        foreach ($this->map as $name => $factory) {
            if ($factory()->capabilities()->recording) {
                $names[] = $name;
            }
        }

        // And whatever is actually bound, which the map cannot know about — the
        // same reason `for()` asks the configured instance before the map.
        $configured = $this->configured();

        if ($configured->capabilities()->recording && ! in_array($configured->identifier(), $names, true)) {
            $names[] = $configured->identifier();
        }

        return $names;
    }
}
