<?php

declare(strict_types=1);

namespace App\Shared\Actions;

/**
 * Base class for single-responsibility action classes.
 *
 * Actions encapsulate a discrete business use case (e.g. CreateCourse). They are
 * resolved from the container so constructor-injected dependencies are supported.
 */
abstract class Action
{
    //
}
