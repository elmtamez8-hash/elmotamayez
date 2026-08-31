<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Exceptions;

use DomainException;

/**
 * The feature switch for this workspace is off (spec 011 · spec 012 FR-001).
 *
 * ⚠️ A TYPE BECAUSE THE ANSWER IS `403`, NOT `422`. A refusal a student can act
 * on («اختر فكرة أخرى») and one they cannot («المدرّس لم يفعّل هذا بعد») are two
 * different screens, and a controller cannot tell them apart by reading a
 * sentence. Caught ABOVE the general `DomainException` arm, for the reason
 * `BroadcastProviderUnavailable` is caught above `RuntimeException`.
 *
 * ⚠️ AND THE SWITCH IS READ WITH AN EXPLICIT WORKSPACE ID, never from
 * `WorkspaceContext` — that is `null` for every student, and `(int) null === 0`
 * addresses the PLATFORM row, which ships off. Read that way the feature is
 * refused to everybody, for ever, and the flag screen shows it enabled.
 */
class FeatureDisabledException extends DomainException {}
