<x-filament-panels::page>
    <p>Automatic mode discovers approved sources, retrieves permitted primary evidence, generates English and seven translations, and publishes after automated checks. Human review remains available as an optional mode. Credentials stay in server configuration.</p>
    <p><a href="{{ \App\Filament\Resources\NewsSources\NewsSourceResource::getUrl() }}">Enabled sources — manage discovery, primary material permission and AI rights</a></p>
    <dl class="grid gap-4 md:grid-cols-3">
        @foreach($this->statistics() as $label => $value)
            <div><dt class="font-semibold">{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl>
    <form wire:submit="save">
        {{ $this->form }}
        @if(auth()->user()->is_admin)
            <div class="mt-6 flex gap-4">
                <x-filament::button type="submit">Save discovery settings</x-filament::button>
                <x-filament::button type="button" color="gray" wire:click="discover" wire:loading.attr="disabled">Queue due discovery</x-filament::button>
            </div>
        @endif
    </form>
    <h2 class="text-lg font-semibold">AI generation controls</h2>
    <p>Server enable flags and ceilings also apply. Pilot: one article per day, seven translations, USD 1/day, USD 10/month and one concurrent AI request. Unknown provider costs remain reserved until an administrator checks provider billing records.</p>
    <form wire:submit="saveAi">
        {{ $this->aiForm }}
        @if(auth()->user()->is_admin)
            <div class="mt-6 flex gap-4">
                <x-filament::button type="submit">Save AI controls</x-filament::button>
                <x-filament::button type="button" color="danger" wire:click="stopAi" wire:loading.attr="disabled">Stop all new AI requests</x-filament::button>
            </div>
        @endif
    </form>
    <dl class="grid gap-4 md:grid-cols-3">
        @foreach($this->aiStatistics() as $label => $value)
            <div><dt class="font-semibold">{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl>
    <h2 class="text-lg font-semibold">Recent generation runs</h2>
    <h2 class="text-lg font-semibold">Failed article monitoring</h2>
    <p>Held candidates and articles show the reason in their administration tables. Safe rate-limit retries are bounded; uncertain costs and failed evidence remain held. Editing or unpublishing an automatic article pauses its automation.</p>
    <p><a href="{{ \App\Filament\Resources\AiGenerationRuns\AiGenerationRunResource::getUrl() }}">Inspect sources, verification reports, proposals, usage and recovery actions</a></p>
    <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>Run</th><th>Task</th><th>Language</th><th>Status</th><th>Mode</th><th>Error</th></tr></thead><tbody>
        @forelse($this->generationRuns() as $run)
            <tr><td>{{ $run->id }}</td><td>{{ $run->task }}</td><td>{{ $run->locale }}</td><td>{{ $run->status->value }}</td><td>{{ $run->is_simulated ? 'SIMULATED' : $run->provider }}</td><td>{{ $run->error_code ?? '—' }}</td></tr>
        @empty
            <tr><td colspan="6">No generation runs. AI starts stopped; no spending or token usage is assumed.</td></tr>
        @endforelse
    </tbody></table></div>
    <h2 class="text-lg font-semibold">Recent ingestion runs</h2>
    <div class="overflow-x-auto">
        <table class="w-full text-left"><thead><tr><th>Run</th><th>Source</th><th>Status</th><th>Candidates</th><th>Error code</th></tr></thead><tbody>
            @forelse($this->recentRuns() as $run)
                <tr><td>{{ $run->id }}</td><td>{{ $run->source?->name }}</td><td>{{ $run->status->value }}</td><td>{{ $run->created_count }}</td><td>{{ $run->error_code ?? '—' }}</td></tr>
            @empty
                <tr><td colspan="5">No ingestion runs. Sources and automatic discovery start disabled.</td></tr>
            @endforelse
        </tbody></table>
    </div>
</x-filament-panels::page>

