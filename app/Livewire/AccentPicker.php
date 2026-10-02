<?php

namespace App\Livewire;

use App\Actions\Settings\UpdateAccentColor;
use App\Support\AccentColor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Reusable accent-color picker. Used on Settings > Appearance and the setup wizard.
 *
 * @property-read array<string, array{accent: string, foreground: string, label: string}> $presets
 */
class AccentPicker extends Component
{
    public string $accent = AccentColor::DEFAULT;

    public string $customHex = '';

    public bool $useCustom = false;

    public function mount(): void
    {
        $stored = auth()->user()->accent;

        if (AccentColor::isPreset($stored)) {
            $this->accent = $stored;

            return;
        }

        if (AccentColor::isValidHex($stored)) {
            $this->accent = $stored;
            $this->customHex = $stored;
            $this->useCustom = true;
        }
    }

    #[Computed]
    public function presets(): array
    {
        return AccentColor::PRESETS;
    }

    #[Computed]
    public function resolved(): array
    {
        return AccentColor::resolve($this->accent);
    }

    #[Computed]
    public function resolvedDark(): array
    {
        return AccentColor::resolveForDark($this->accent);
    }

    #[Computed]
    public function customHexIsLowContrast(): bool
    {
        return AccentColor::isValidHex($this->customHex) && ! AccentColor::meetsContrast($this->customHex);
    }

    public function selectPreset(string $preset): void
    {
        if (! AccentColor::isPreset($preset)) {
            return;
        }

        $this->useCustom = false;
        $this->accent = $preset;

        $this->save();
    }

    public function applyCustom(): void
    {
        $this->useCustom = true;

        if (! AccentColor::isValidHex($this->customHex)) {
            return;
        }

        $this->accent = $this->customHex;

        $this->save();
    }

    private function save(): void
    {
        app(UpdateAccentColor::class)->handle(auth()->user(), $this->accent);

        unset($this->resolved, $this->resolvedDark);

        $this->dispatch(
            'accent-applied',
            preset: $this->resolved['preset'],
            light: ['accent' => $this->resolved['accent'], 'foreground' => $this->resolved['foreground']],
            dark: ['accent' => $this->resolvedDark['accent'], 'foreground' => $this->resolvedDark['foreground']],
        );
    }

    public function render(): View
    {
        return view('livewire.accent-picker');
    }
}
