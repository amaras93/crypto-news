<x-filament-panels::page>
    <p>Defaults apply only to new drafts. Editors always author their own drafts. Publication requires administrator authorization; all publication times are UTC.</p>
    <form wire:submit="save">
        {{ $this->form }}
        <div class="mt-6"><x-filament::button type="submit">Save editorial defaults</x-filament::button></div>
    </form>
</x-filament-panels::page>
