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
        .da-note { font-size: .8rem; color: rgb(var(--gray-500)); margin-top: .75rem; }
        .da-waves { display: flex; flex-wrap: wrap; gap: .5rem 1rem; }
        .da-waves label { display: flex; align-items: center; gap: .4rem; font-size: .875rem; color: rgb(var(--gray-500)); }
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

        <x-filament::tabs label="Ansicht">
            <x-filament::tabs.item :active="$tab === 'vergleich'" icon="heroicon-m-chart-bar" wire:click="setTab('vergleich')">
                Vergleich
            </x-filament::tabs.item>
            <x-filament::tabs.item :active="$tab === 'foerderbereiche'" icon="heroicon-m-bars-3-center-left" wire:click="setTab('foerderbereiche')">
                Förderbereiche
            </x-filament::tabs.item>
            <x-filament::tabs.item :active="$tab === 'entwicklung'" icon="heroicon-m-arrow-trending-up" wire:click="setTab('entwicklung')">
                Entwicklung
            </x-filament::tabs.item>
            <x-filament::tabs.item :active="$tab === 'verteilung'" icon="heroicon-m-chart-bar-square" wire:click="setTab('verteilung')">
                Verteilung vs. Norm
            </x-filament::tabs.item>
            <x-filament::tabs.item :active="$tab === 'tempo'" icon="heroicon-m-bolt" wire:click="setTab('tempo')">
                Tempo &amp; Genauigkeit
            </x-filament::tabs.item>
            <x-filament::tabs.item :active="$tab === 'saetze'" icon="heroicon-m-list-bullet" wire:click="setTab('saetze')">
                Satzanalyse
            </x-filament::tabs.item>
        </x-filament::tabs>

        @if($tab === 'vergleich')
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
                @if($v['genderNote'])
                    <p class="da-note">{{ $v['genderNote'] }}</p>
                @endif
            </x-filament::section>
        @elseif($tab === 'foerderbereiche')
            <x-filament::section>
                <x-slot name="heading">Förderbereiche je Gruppe</x-slot>
                <x-slot name="description">
                    Anteil der Schüler:innen je Förderbereich (Grenzen aus den aktiven Förderbedarfsschwellen). Klick auf eine Gruppe öffnet die Schülerliste.
                </x-slot>
                <x-analysis.stacked-bands :groups="$dist['groups']" :total="$dist['total']" :bands="$dist['bands']" :drilldown="true" />
            </x-filament::section>
        @elseif($tab === 'entwicklung')
            @php $dev = $v['dev']; @endphp
            <x-filament::section>
                <x-slot name="heading">Entwicklung über die Erhebungen</x-slot>
                <x-slot name="description">
                    Median und mittlere 50 % je Erhebung; darunter die Veränderung je Schüler:in zwischen zwei Erhebungen.
                    @if($filter->secondaryGroupBy) Die zusätzliche Aufteilung wird hier nicht verwendet. @endif
                </x-slot>

                @if(! $dev['enough'])
                    <p class="da-empty">
                        Für eine Entwicklung werden Daten aus mindestens zwei Erhebungen benötigt
                        (gefunden: {{ count($dev['waves']) }}). Bitte im Filter mehrere Erhebungstypen bzw. Schuljahre zulassen.
                    </p>
                @else
                    <x-slot name="headerEnd">
                        <div class="da-waves">
                            <label>
                                <span>von</span>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model.live="devFrom">
                                        @foreach($dev['waves'] as $w)
                                            <option value="{{ $w['key'] }}" @selected($w['key'] === $dev['from'])>{{ $w['label'] }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </label>
                            <label>
                                <span>bis</span>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model.live="devTo">
                                        @foreach($dev['waves'] as $w)
                                            <option value="{{ $w['key'] }}" @selected($w['key'] === $dev['to'])>{{ $w['label'] }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </label>
                        </div>
                    </x-slot>

                    <x-analysis.development :dev="$dev" :threshold="$dist['threshold']" :threshold-label="$dist['threshold_label']" />
                    <x-analysis.delta-details :dev="$dev" :names="$v['canSeeNames']" style="margin-top:1rem;" />
                @endif
            </x-filament::section>
        @elseif($tab === 'verteilung')
            <x-filament::section>
                <x-slot name="heading">Verteilung im Vergleich zur Norm</x-slot>
                <x-slot name="description">
                    Wie viele Schüler:innen liegen in welchem LQ-Bereich – und wie viele wären laut Norm (Mittel 100, SD 15) zu erwarten?
                </x-slot>
                <x-analysis.histogram :hist="$v['hist']" :threshold="$dist['threshold']" :threshold-label="$dist['threshold_label']" />
            </x-filament::section>
        @elseif($tab === 'tempo')
            <x-filament::section>
                <x-slot name="heading">Tempo &amp; Genauigkeit</x-slot>
                <x-slot name="description">
                    Je Punkt ein:e Schüler:in: wie viele Sätze bearbeitet (Tempo) und wie viele davon falsch beurteilt (Fehlerquote).
                </x-slot>
                <x-slot name="headerEnd">
                    <x-filament::button size="sm" :color="$byGender ? 'primary' : 'gray'" :outlined="! $byGender"
                                        icon="heroicon-m-user-group" wire:click="$toggle('byGender')">
                        Nach Geschlecht
                    </x-filament::button>
                </x-slot>
                @if($v['sa']['n'] === 0)
                    <p class="da-empty">Keine Versuche mit bearbeiteten Sätzen.</p>
                @else
                    <x-analysis.scatter :sa="$v['sa']" :by-gender="$byGender" :names="$v['canSeeNames']" />
                @endif
            </x-filament::section>
        @elseif($tab === 'saetze')
            @php $ia = $v['ia']; @endphp
            <x-filament::section>
                <x-slot name="heading">Satzanalyse</x-slot>
                <x-slot name="description">
                    Welche Sätze wurden oft falsch beurteilt, und wie weit kamen die Schüler:innen in der Zeit?
                </x-slot>
                @if(count($ia['options']) > 1)
                    <x-slot name="headerEnd">
                        <label class="da-waves">
                            <span>Fragebogen</span>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model.live="questionnaireId">
                                    @foreach($ia['options'] as $id => $label)
                                        <option value="{{ $id }}" @selected($id === $ia['questionnaire_id'])>{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </label>
                    </x-slot>
                @endif
                @if($ia['items'] === [])
                    <p class="da-empty">Keine Antworten für eine Satzanalyse vorhanden.</p>
                @else
                    <x-analysis.items :ia="$ia" />
                @endif
            </x-filament::section>
        @endif
    @endif

</x-filament-panels::page>
