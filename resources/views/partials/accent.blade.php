@php
    $accentValue = auth()->user()?->accent;
    $accent = \App\Support\AccentColor::resolve($accentValue);
    $accentDark = \App\Support\AccentColor::resolveForDark($accentValue);
@endphp

@unless ($accent['preset'])
    {{-- Custom accent: no data-accent rule exists in app.css for it, so emit both themes'
        values directly. :root.dark here behaves exactly like the preset overrides in
        app.css (same selector shape, same cascade), just scoped to this one <style> tag
        instead of living in the stylesheet. Same id the picker's @script updates live. --}}
    <style id="accent-custom-override">
        :root {
            --color-accent: {{ $accent['accent'] }};
            --color-accent-content: {{ $accent['accent'] }};
            --color-accent-foreground: {{ $accent['foreground'] }};
        }

        :root.dark {
            --color-accent: {{ $accentDark['accent'] }};
            --color-accent-content: {{ $accentDark['accent'] }};
            --color-accent-foreground: {{ $accentDark['foreground'] }};
        }
    </style>
@endunless
