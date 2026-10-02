<?php

namespace App\Actions\Settings;

use App\Models\User;
use App\Support\AccentColor;
use Illuminate\Validation\ValidationException;

class UpdateAccentColor
{
    public function handle(User $user, string $value): void
    {
        $value = trim($value);

        if (! AccentColor::isPreset($value) && ! AccentColor::isValidHex($value)) {
            throw ValidationException::withMessages([
                'accent' => __('Choose a swatch or enter a valid hex color like #7c3aed.'),
            ]);
        }

        $user->update(['accent' => $value]);
    }
}
