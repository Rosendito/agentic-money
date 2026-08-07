<?php

namespace App\Domain\Money\Models;

use Database\Factories\Domain\Money\InstrumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A unit in which a balance, price, or obligation can be expressed (docs/02-domain-glossary.md,
 * "Instrument"). Instrument codes are not restricted to three characters (MNY-001).
 *
 * This model must never depend on `App\Domain\Ledger` or `App\Domain\Obligations` (ARC-003).
 */
#[Fillable(['code', 'name'])]
class Instrument extends Model
{
    /** @use HasFactory<InstrumentFactory> */
    use HasFactory;

    protected static function newFactory(): InstrumentFactory
    {
        return InstrumentFactory::new();
    }
}
