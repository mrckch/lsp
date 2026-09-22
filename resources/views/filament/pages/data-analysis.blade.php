<x-filament-panels::page>
    @php
        $v = $this->viewData();
        $dist = $v['dist'];
        $kpis = $v['kpis'];
        $filter = $v['filter'];
        $preset = match (true) {
            $filter->groupBy === 'learning_group' && $filter->secondaryGroupBy === 'gender' => 'class_gender',
            $filter->groupBy === 'learning_group' && $filter->secondaryGroupBy === null => 'classes',
            $filter->groupBy === 'gender' && $filter->secondaryGroupBy === null => 'gender',
            default => null,
        };
    @endphp

    <style>
        .da-presets { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .da-presets .hint { font-size: .875rem; color: rgb(var(--gray-500)); margin-right: .25rem; }
        .da-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr)); gap: .75rem; }
        .da-kpi { border-radius: .75rem; padding: .75rem 1rem; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.05); border: 1px solid rgba(var(--gray-950), .06); }
        .da-kpi .v { font-size: 1.5rem; font-weight: 600; color: rgb(var(--gray-950)); font-variant-numeric: tabular-nums; }
        .da-kpi .l { font-size: .8rem; color: rgb(var(--gray-500)); }
        .da-kpi.sev-foerderbedarf { border-left: 4px solid rgb(var(--danger-500)); }
        .da-kpi.sev-auffaellig { border-left: 4px solid rgb(var(--warning-500)); }
        .da-kpi.sev-hinweis { border-left: 4px solid rgb(var(--info-500)); }
        .dark .da-kpi { background: rgb(var(--gray-900)); border-color: rgba(255,255,255,.1); }
        .dark .da-kpi .v { color: #fff; }
        .da-empty { color: rgb(var(--gray-500)); padding: 1rem 0; }
    </style>

    <div class="da-presets">
        <span class="hint">Schnellwahl:</span>
        <x-filament::button size="sm" :color="$preset === 'classes' ? 'primary' : 'gray'" :outlined="$preset !== 'classes'"
                            icon="heroicon-m-rectangle-group" wire:click="applyPreset('classes')">
            Klassen vergleichen
        </x-filament::button>
        <x-filament::button size="sm" :color="$preset === 'gender' ? 'primary' : 'gray'" :outlined="$preset !== 'gender'"
                            icon="heroicon-m-user-group" wire:click="applyPreset('gender')">
            Mädchen vs. Jungen
        </x-filament::button>
        <x-filament::button size="sm" :color="$preset === 'class_gender' ? 'primary' : 'gray'" :outlined="$preset !== 'class_gender'"
                            icon="heroicon-m-squares-2x2" wire:click="applyPreset('class_gender')">
            Klassen × Geschlecht
        </x-filament::button>
    </div>

    {{ $this->form }}

    @if($v['noScope'])
        <x-filament::section>
            <p class="da-empty">Ihnen sind keine Lerngruppen zugeordnet. Bitte wenden Sie sich an die Administration.</p>
        </x-filament::section>
    @elseif($kpis['attempts'] === 0)
        <x-filament::section>
            <p class="da-empty">Keine gewerteten Versuche für die gewählten Filter.</p>
        </x-filament::section>
    @else
        <div class="da-kpis">
            <div class="da-kpi">
                <div class="v">{{ $kpis['students'] }}</div>
                <div class="l">Schüler:innen @if($kpis['attempts'] !== $kpis['students'])({{ $kpis['attempts'] }} Versuche)@endif</div>
            </div>
            <div class="da-kpi">
                <div class="v">{{ $kpis['median'] === null ? '–' : number_format($kpis['median'], 1, ',', '') }}</div>
                <div class="l">Median LQ</div>
            </div>
            @foreach($kpis['shares'] as $share)
                <div class="da-kpi sev-{{ $share['severity'] }}">
                    <div class="v">{{ $share['pct'] }} %</div>
                    <div class="l">{{ $share['label'] }} ({{ $share['count'] }} SuS)</div>
                </div>
            @endforeach
        </div>

        <x-filament::section>
            <x-slot name="heading">Vergleich</x-slot>
            <x-slot name="description">
                {{ count($dist['groups']) }} {{ count($dist['groups']) === 1 ? 'Gruppe' : 'Gruppen' }} ·
                Klick auf einen Gruppennamen öffnet die Schülerliste.
            </x-slot>
            <x-slot name="headerEnd">
                <div style="display:flex; flex-wrap:wrap; gap:.5rem; justify-content:flex-end;">
                    <x-filament::button size="sm" :color="$showBands ? 'primary' : 'gray'" :outlined="! $showBands"
                                        icon="heroicon-m-adjustments-horizontal" wire:click="$toggle('showBands')">
                        Förderbereiche
                    </x-filament::button>
                    <x-filament::button size="sm" :color="$byGender ? 'primary' : 'gray'" :outlined="! $byGender"
                                        icon="heroicon-m-user-group" wire:click="$toggle('byGender')">
                        Nach Geschlecht
                    </x-filament::button>
                </div>
            </x-slot>

            <x-analysis.boxplot
                :groups="$dist['groups']"
                :labels="true"
                :domain="$dist['domain']"
                :bands="$dist['bands']"
                :threshold="$dist['threshold']"
                :threshold-label="$dist['threshold_label']"
                :show-bands="$showBands"
                :by-gender="$byGender"
                :gender-info="$v['genderInfo']"
                :drilldown="true"
            />

            <div style="margin-top:1rem;">
                <x-analysis.stats-table :groups="$dist['groups']" :total="$dist['total']" :bands="$dist['bands']" :drilldown="true" />
            </div>
        </x-filament::section>
    @endif

</x-filament-panels::page>
