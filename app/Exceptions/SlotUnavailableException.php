<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown inside a booking transaction when a race-safe, locked capacity
 * re-check finds the slot filled up after the initial (unlocked) validation
 * passed. Catching this distinctly lets the controllers show the devotee a
 * precise "slot just taken" message and roll the transaction back cleanly,
 * instead of surfacing a generic booking failure.
 */
class SlotUnavailableException extends RuntimeException
{
}
